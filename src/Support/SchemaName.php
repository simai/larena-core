<?php

declare(strict_types=1);

namespace Larena\Core\Support;

/**
 * Names carry no version (owner decisions 2026-10-11 and 2026-10-18). A stored or exchanged format is named once and
 * carries its version as a data field: `{"schema": "<name>", "schema_version": N}`. Data written before the move names
 * the version in the label (`<name>.vN`, with no schema_version or the same one); readers accept both forms until the
 * stored data is migrated. This is the one check every reader uses.
 */
final class SchemaName
{
    /** @param array<array-key, mixed> $data */
    public static function is(array $data, string $name, int $version): bool
    {
        $schema = $data['schema'] ?? null;
        $declared = $data['schema_version'] ?? null;

        return ($schema === $name && $declared === $version)
            || ($schema === $name.'.v'.$version && ($declared === null || $declared === $version));
    }

    /**
     * The fields a writer puts first in a document of this format.
     *
     * @return array{schema: string, schema_version: int}
     */
    public static function stamp(string $name, int $version): array
    {
        return ['schema' => $name, 'schema_version' => $version];
    }
}
