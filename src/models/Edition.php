<?php

namespace justinholtweb\legs\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application and read in one place as the answer to "what exactly does Pro
 * buy".
 *
 * Two things enforce it, and they do different jobs:
 *
 * - The CP and the importers **refuse** what Lite cannot do, so an author is told rather than
 *   quietly given something else.
 * - {@see self::applyTo()} **downgrades** render options on the way to the renderer, so a site
 *   whose licence lapsed keeps serving correct tables with the Lite feature set instead of
 *   breaking. Stored options are untouched, so renewing restores exactly what was there.
 */
class Edition
{
    /** Library tables Lite may keep. Inline field tables are not counted — they are not library entries. */
    public const LITE_MAX_TABLES = 3;

    public static function maxTables(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_TABLES;
    }

    public static function allowsSearch(bool $isPro): bool
    {
        return $isPro;
    }

    public static function allowsPagination(bool $isPro): bool
    {
        return $isPro;
    }

    public static function allowsStacking(bool $isPro): bool
    {
        return $isPro;
    }

    public static function allowsMerges(bool $isPro): bool
    {
        return $isPro;
    }

    public static function allowsFormulas(bool $isPro): bool
    {
        return $isPro;
    }

    /** Element-query backed tables, and the scheduled refresh that keeps them current. */
    public static function allowsQueryTables(bool $isPro): bool
    {
        return $isPro;
    }

    public static function allowsXlsx(bool $isPro): bool
    {
        return $isPro;
    }

    /**
     * Returns a copy of the options with anything this edition cannot serve turned off.
     *
     * A dropped option is turned *off*, never reinterpreted — a stacked table served as scrolling
     * is a table; a stacked table served as something else is a bug report.
     */
    public static function applyTo(RenderOptions $options, bool $isPro): RenderOptions
    {
        if ($isPro) {
            return $options;
        }

        $downgraded = RenderOptions::fromArray($options->toArray());
        $downgraded->searchable = false;
        $downgraded->paginate = false;
        $downgraded->formulas = false;

        if ($downgraded->responsive === RenderOptions::RESPONSIVE_STACK) {
            $downgraded->responsive = RenderOptions::RESPONSIVE_SCROLL;
        }

        return $downgraded;
    }
}
