<?php

namespace justinholtweb\legs\gql;

use craft\helpers\Gql as GqlHelper;
use justinholtweb\legs\elements\Table;

/**
 * Which tables the active GraphQL schema may read.
 *
 * Two kinds of grant, both under `legsTables`: `legsTables.all:read` for the whole library —
 * including tables created after the schema was saved — and `legsTables.<uid>:read` for one
 * table. Everything GraphQL hands out goes through here: the root queries, the generated type,
 * and Legs fields in reference mode, so a field cannot be a way round a schema that was never
 * given the table.
 */
class Scope
{
    public const COMPONENT = 'legsTables';
    public const ALL = 'all';

    /** Whether the schema can read any table at all — no grant, no queries and no type. */
    public static function canReadAny(): bool
    {
        return self::allowedUids() !== [];
    }

    public static function canReadAll(): bool
    {
        return in_array(self::ALL, self::allowedUids(), true);
    }

    /**
     * Whether a table may be handed to this schema: granted, and live on the site it was loaded
     * for. Tables have no status switch in the CP, but the element still has its enabled flags,
     * and a table switched off by a migration or a resave is not one an author published.
     */
    public static function canRead(Table $table): bool
    {
        if ($table->trashed || !$table->enabled || !$table->getEnabledForSite()) {
            return false;
        }

        return self::canReadAll() || in_array($table->uid, self::allowedUids(), true);
    }

    /**
     * Table UIDs granted one by one, or null when the schema has the whole library.
     *
     * @return list<string>|null
     */
    public static function grantedUids(): ?array
    {
        if (self::canReadAll()) {
            return null;
        }

        return array_values(array_filter(self::allowedUids(), static fn(string $uid) => $uid !== self::ALL));
    }

    /** @return list<string> */
    private static function allowedUids(): array
    {
        $uids = GqlHelper::extractAllowedEntitiesFromSchema('read')[self::COMPONENT] ?? [];

        return is_array($uids) ? array_values(array_map('strval', $uids)) : [];
    }
}
