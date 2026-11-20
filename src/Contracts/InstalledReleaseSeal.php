<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * The installer's last step for a release installed from a signed archive (owner decision 2026-11-20, integrity part
 * A1–A4): the files of the fresh release are checked against the release's signed file list, and only then the
 * signed description, the list and the archive are kept with the release (`<release>/.larena-signed/`), so the
 * site's own checks and «Вернуть как было» have something trusted to compare with from the first request.
 *
 * Core owns the step and its command (`larena:install-seal`); the update client, which owns release signatures,
 * provides the check. Without it the step says so and seals nothing ({@see \Larena\Core\Install\NullInstalledReleaseSeal}).
 */
interface InstalledReleaseSeal
{
    /**
     * @param string $releaseRoot the installed release (`<site>/releases/<release>`)
     * @param string $signedFrom  the folder holding the release's `release.json`, `release.json.sig`, `files.json`
     *                            and archive, as downloaded with it
     *
     * @return array{sealed: bool, reason: ?string, version: ?string, files: int}
     *         `reason` is a stable code (no secret, no path) when nothing was sealed
     */
    public function seal(string $releaseRoot, string $signedFrom): array;
}
