<?php

namespace justinholtweb\legs\gql\resolvers;

use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use craft\gql\base\ElementResolver;
use justinholtweb\legs\elements\db\TableQuery;
use justinholtweb\legs\elements\Table;
use justinholtweb\legs\gql\Scope;
use yii\base\UnknownMethodException;

/**
 * Resolves `legsTables`, `legsTable` and `legsTableCount`, scoped to what the schema may read.
 *
 * Whatever the arguments say, three things are fixed: only granted tables, only live ones (the
 * element and its site row enabled, never the trash), and nothing that is not a table's own
 * content — the element-query criteria behind a query-backed table never leave the server.
 */
class TableResolver extends ElementResolver
{
    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        $query = $source === null ? Table::find() : $source->$fieldName;

        if (!$query instanceof ElementQuery) {
            return $query;
        }

        foreach ($arguments as $key => $value) {
            try {
                $query->$key($value);
            } catch (UnknownMethodException $e) {
                if ($value !== null) {
                    throw $e;
                }
            }
        }

        if (!Scope::canReadAny()) {
            return ElementCollection::empty();
        }

        /** @var TableQuery $query */
        $uids = Scope::grantedUids();

        if ($uids !== null) {
            if ($uids === []) {
                return ElementCollection::empty();
            }

            $query->andWhere(['elements.uid' => $uids]);
        }

        // Set after the arguments, so a schema that may see inactive elements still cannot ask
        // for a disabled or trashed table: `status` means nothing to an element without
        // statuses, and `trashed` would otherwise open the bin.
        $query->trashed(false);
        $query->andWhere([
            'elements.enabled' => true,
            'elements_sites.enabled' => true,
        ]);

        return $query;
    }
}
