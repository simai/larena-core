<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * Writes a scenario's starter settings into the site's settings. Only settings the site does not have yet are written:
 * a value the person (or an earlier scenario) set is kept, so applying a scenario again, or adding a second one, never
 * overwrites or removes anything.
 */
interface SiteScenarioSettings
{
    /** Whether the site has a settings store to write to. */
    public function available(): bool;

    /**
     * @param array<string, string|int|float|bool> $values
     * @return list<string> the keys written (the ones the site did not have)
     */
    public function putMissing(array $values, string $actor): array;
}
