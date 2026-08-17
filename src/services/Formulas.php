<?php

namespace justinholtweb\legs\services;

use craft\base\Component;
use justinholtweb\legs\models\TableData;

/**
 * Spreadsheet formulas: `=SUM(B2:B9)`, `=A1*1.2`, `=ROUND(AVERAGE(C2:C10), 2)`.
 *
 * A small recursive-descent evaluator rather than anything that can reach PHP: cells are author
 * input, and "let authors type an expression" must never become "let authors type code".
 * The grammar is arithmetic, cell references, ranges, and a fixed function list — nothing else
 * parses, and anything that does not parse is left on the page as the text the author typed.
 *
 * Formulas read the *evaluated* grid, so a formula may reference a cell that is itself a
 * formula. Cycles are detected rather than followed, and report `#CIRCULAR!` in the cell that
 * closed the loop.
 */
class Formulas extends Component
{
    private const FUNCTIONS = [
        'SUM', 'AVERAGE', 'AVG', 'MIN', 'MAX', 'COUNT', 'COUNTA', 'ROUND', 'ABS', 'PRODUCT', 'MEDIAN',
    ];

    private TableData $data;

    /** Cells currently being evaluated, keyed "row:col" — the cycle detector. */
    private array $evaluating = [];

    private array $resolved = [];

    /**
     * Whether the cell currently being evaluated depended on a cycle.
     *
     * Needed because the cell that *closes* a loop is not the cell an author is looking at: A2
     * referring to B2 referring back to A2 makes the inner A2 report `#CIRCULAR!`, and without
     * this that marker is read as the number zero by whatever asked for it. The flag rides back
     * up the chain so both cells say what really happened.
     */
    private bool $cycleDetected = false;

    /**
     * Returns a copy of the grid with every formula cell replaced by its result.
     *
     * A copy, not an edit in place: the author's formula has to survive so the editor can show
     * it again, and only the render sees numbers.
     */
    public function apply(TableData $data): TableData
    {
        $this->data = $data;
        $this->evaluating = [];
        $this->resolved = [];

        $output = TableData::fromArray($data->toArray());

        foreach ($data->cells as $r => $row) {
            foreach ($row as $c => $cell) {
                if ($this->isFormula($cell)) {
                    $this->cycleDetected = false;
                    $output->cells[$r][$c] = $this->cellValue($r, $c);
                }
            }
        }

        return $output;
    }

    public function isFormula(string $value): bool
    {
        return str_starts_with(ltrim($value), '=');
    }

    private function cellValue(int $row, int $col): string
    {
        $key = "$row:$col";

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if (isset($this->evaluating[$key])) {
            $this->cycleDetected = true;

            return '#CIRCULAR!';
        }

        $raw = $this->data->cell($row, $col);

        if (!$this->isFormula($raw)) {
            return $raw;
        }

        $this->evaluating[$key] = true;

        try {
            $result = $this->evaluate(substr(ltrim($raw), 1));
            $value = match (true) {
                $this->cycleDetected => '#CIRCULAR!',
                $result === null => '#ERROR!',
                default => $this->formatNumber($result),
            };
        } finally {
            unset($this->evaluating[$key]);
        }

        return $this->resolved[$key] = $value;
    }

    /** @return float|null Null when the expression does not parse — the cell then shows its source. */
    private function evaluate(string $expression): ?float
    {
        $tokens = $this->tokenize($expression);

        if ($tokens === null) {
            return null;
        }

        $position = 0;
        $value = $this->parseExpression($tokens, $position);

        // Trailing junk means the expression did not really parse, whatever the prefix said.
        return $position === count($tokens) ? $value : null;
    }

    /** @return list<array{0:string,1:string}>|null */
    private function tokenize(string $expression): ?array
    {
        $tokens = [];
        $length = strlen($expression);
        $i = 0;

        while ($i < $length) {
            $char = $expression[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            if (str_contains('+-*/^(),:', $char)) {
                $tokens[] = ['op', $char];
                $i++;
                continue;
            }

            if (ctype_digit($char) || $char === '.') {
                $number = '';
                while ($i < $length && (ctype_digit($expression[$i]) || $expression[$i] === '.')) {
                    $number .= $expression[$i++];
                }
                $tokens[] = ['number', $number];
                continue;
            }

            if (ctype_alpha($char) || $char === '$') {
                $word = '';
                while ($i < $length && (ctype_alnum($expression[$i]) || $expression[$i] === '$')) {
                    $word .= $expression[$i++];
                }
                $tokens[] = ['word', $word];
                continue;
            }

            return null;
        }

        return $tokens;
    }

    private function parseExpression(array $tokens, int &$position): ?float
    {
        $value = $this->parseTerm($tokens, $position);

        while ($value !== null && isset($tokens[$position]) && in_array($tokens[$position][1], ['+', '-'], true) && $tokens[$position][0] === 'op') {
            $operator = $tokens[$position++][1];
            $right = $this->parseTerm($tokens, $position);

            if ($right === null) {
                return null;
            }

            $value = $operator === '+' ? $value + $right : $value - $right;
        }

        return $value;
    }

    private function parseTerm(array $tokens, int &$position): ?float
    {
        $value = $this->parseFactor($tokens, $position);

        while ($value !== null && isset($tokens[$position]) && in_array($tokens[$position][1], ['*', '/'], true) && $tokens[$position][0] === 'op') {
            $operator = $tokens[$position++][1];
            $right = $this->parseFactor($tokens, $position);

            if ($right === null) {
                return null;
            }

            if ($operator === '/') {
                if ($right == 0.0) {
                    return null;
                }
                $value /= $right;
            } else {
                $value *= $right;
            }
        }

        return $value;
    }

    private function parseFactor(array $tokens, int &$position): ?float
    {
        $value = $this->parseUnary($tokens, $position);

        if ($value !== null && isset($tokens[$position]) && $tokens[$position] === ['op', '^']) {
            $position++;
            $exponent = $this->parseFactor($tokens, $position);

            if ($exponent === null) {
                return null;
            }

            return $value ** $exponent;
        }

        return $value;
    }

    private function parseUnary(array $tokens, int &$position): ?float
    {
        if (isset($tokens[$position]) && $tokens[$position][0] === 'op' && in_array($tokens[$position][1], ['+', '-'], true)) {
            $operator = $tokens[$position++][1];
            $value = $this->parseUnary($tokens, $position);

            if ($value === null) {
                return null;
            }

            return $operator === '-' ? -$value : $value;
        }

        return $this->parsePrimary($tokens, $position);
    }

    private function parsePrimary(array $tokens, int &$position): ?float
    {
        $token = $tokens[$position] ?? null;

        if ($token === null) {
            return null;
        }

        if ($token[0] === 'number') {
            $position++;
            return (float)$token[1];
        }

        if ($token === ['op', '(']) {
            $position++;
            $value = $this->parseExpression($tokens, $position);

            if ($value === null || ($tokens[$position] ?? null) !== ['op', ')']) {
                return null;
            }

            $position++;
            return $value;
        }

        if ($token[0] === 'word') {
            $word = strtoupper(str_replace('$', '', $token[1]));

            // A function call.
            if (($tokens[$position + 1] ?? null) === ['op', '('] && in_array($word, self::FUNCTIONS, true)) {
                $position += 2;
                $arguments = $this->parseArguments($tokens, $position);

                if ($arguments === null) {
                    return null;
                }

                return $this->callFunction($word, $arguments);
            }

            // A cell reference.
            $cell = $this->parseReference($word);

            if ($cell === null) {
                return null;
            }

            $position++;
            [$row, $col] = $cell;
            $value = $this->cellValue($row, $col);

            return $this->numberFrom($value);
        }

        return null;
    }

    /** @return list<float>|null Flattened: a range contributes each of its cells. */
    private function parseArguments(array $tokens, int &$position): ?array
    {
        $values = [];

        if (($tokens[$position] ?? null) === ['op', ')']) {
            $position++;
            return $values;
        }

        while (true) {
            $range = $this->tryParseRange($tokens, $position);

            if ($range !== null) {
                array_push($values, ...$range);
            } else {
                $value = $this->parseExpression($tokens, $position);

                if ($value === null) {
                    return null;
                }

                $values[] = $value;
            }

            $next = $tokens[$position] ?? null;

            if ($next === ['op', ',']) {
                $position++;
                continue;
            }

            if ($next === ['op', ')']) {
                $position++;
                return $values;
            }

            return null;
        }
    }

    /** @return list<float>|null */
    private function tryParseRange(array $tokens, int &$position): ?array
    {
        $first = $tokens[$position] ?? null;
        $colon = $tokens[$position + 1] ?? null;
        $second = $tokens[$position + 2] ?? null;

        if ($first === null || $first[0] !== 'word' || $colon !== ['op', ':'] || $second === null || $second[0] !== 'word') {
            return null;
        }

        $from = $this->parseReference(strtoupper(str_replace('$', '', $first[1])));
        $to = $this->parseReference(strtoupper(str_replace('$', '', $second[1])));

        if ($from === null || $to === null) {
            return null;
        }

        $position += 3;
        $values = [];

        foreach (range(min($from[0], $to[0]), max($from[0], $to[0])) as $row) {
            foreach (range(min($from[1], $to[1]), max($from[1], $to[1])) as $col) {
                $values[] = $this->numberFrom($this->cellValue($row, $col));
            }
        }

        return $values;
    }

    /**
     * "B7" → [6, 1]. Spreadsheet references are 1-based and column-first; the grid is 0-based
     * and row-first.
     *
     * @return array{0:int,1:int}|null
     */
    private function parseReference(string $reference): ?array
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/', $reference, $matches)) {
            return null;
        }

        $col = 0;
        foreach (str_split($matches[1]) as $letter) {
            $col = $col * 26 + (ord($letter) - 64);
        }

        return [(int)$matches[2] - 1, $col - 1];
    }

    /** @param list<float> $values */
    private function callFunction(string $name, array $values): ?float
    {
        $numbers = array_values(array_filter($values, static fn($value) => $value !== null));

        return match ($name) {
            'SUM' => array_sum($numbers),
            'PRODUCT' => array_product($numbers),
            'AVERAGE', 'AVG' => $numbers ? array_sum($numbers) / count($numbers) : null,
            'MIN' => $numbers ? min($numbers) : null,
            'MAX' => $numbers ? max($numbers) : null,
            // Every argument has already been coerced to a number by the time it gets here, so
            // COUNT and COUNTA cannot differ — they are both "how many cells did you hand me".
            'COUNT', 'COUNTA' => (float)count($numbers),
            'MEDIAN' => $this->median($numbers),
            'ROUND' => count($numbers) >= 1 ? round($numbers[0], (int)($numbers[1] ?? 0)) : null,
            'ABS' => count($numbers) >= 1 ? abs($numbers[0]) : null,
            default => null,
        };
    }

    /** @param list<float> $numbers */
    private function median(array $numbers): ?float
    {
        if (!$numbers) {
            return null;
        }

        sort($numbers);
        $middle = intdiv(count($numbers), 2);

        return count($numbers) % 2 ? $numbers[$middle] : ($numbers[$middle - 1] + $numbers[$middle]) / 2;
    }

    /** Reads the number out of a cell, so `=SUM(B2:B4)` works over "$1,200.00". */
    private function numberFrom(string $value): float
    {
        $value = trim(strip_tags($value));
        $cleaned = preg_replace('/[^\d.\-]/u', '', str_replace(',', '', $value));

        return is_numeric($cleaned) ? (float)$cleaned : 0.0;
    }

    /**
     * Formats a result without inventing precision: whole numbers stay whole, and floating point
     * noise (0.1 + 0.2) is rounded away rather than published.
     */
    private function formatNumber(float $value): string
    {
        $rounded = round($value, 10);

        if (abs($rounded - round($rounded)) < 1e-9) {
            return (string)(int)round($rounded);
        }

        return rtrim(rtrim(number_format($rounded, 10, '.', ''), '0'), '.');
    }
}
