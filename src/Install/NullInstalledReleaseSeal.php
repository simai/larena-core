<?php

declare(strict_types=1);

namespace Larena\Core\Install;

use Larena\Core\Contracts\InstalledReleaseSeal;

/** No package checks release signatures here: nothing is sealed, and the step says so. */
final readonly class NullInstalledReleaseSeal implements InstalledReleaseSeal
{
    public function seal(string $releaseRoot, string $signedFrom): array
    {
        return ['sealed' => false, 'reason' => 'release_seal_unavailable', 'version' => null, 'files' => 0];
    }
}
