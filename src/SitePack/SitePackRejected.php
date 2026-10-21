<?php

declare(strict_types=1);

namespace Larena\Core\SitePack;

use RuntimeException;
use Throwable;

/**
 * A SitePack archive, envelope or artifact was refused. The reason code is part of the frozen contract; the message is
 * not. Reason codes:
 *
 * - archive: `sitepack_archive_unavailable`, `sitepack_archive_invalid`, `sitepack_archive_too_large`,
 *   `sitepack_archive_entry_count_invalid`, `sitepack_archive_entry_invalid`, `sitepack_archive_entry_encrypted`,
 *   `sitepack_archive_entry_symlink`, `sitepack_archive_entry_duplicate`, `sitepack_archive_entry_unknown`,
 *   `sitepack_archive_entry_path_invalid`, `sitepack_archive_entry_compression_unsupported`,
 *   `sitepack_archive_expansion_limit_exceeded`, `sitepack_archive_expansion_ratio_exceeded`;
 * - envelope: `sitepack_manifest_invalid`, `sitepack_manifest_incompatible`, `sitepack_catalog_invalid`,
 *   `sitepack_package_identity_mismatch`, `sitepack_document_too_large`;
 * - artifacts: `sitepack_artifact_missing`, `sitepack_artifact_unknown`, `sitepack_artifact_duplicate`,
 *   `sitepack_artifact_size_mismatch`, `sitepack_artifact_digest_mismatch`, `sitepack_artifact_line_count_mismatch`,
 *   `sitepack_artifact_not_lines`, `sitepack_artifact_unreadable`;
 * - lines: `sitepack_line_too_long`, `sitepack_line_unterminated`, `sitepack_line_invalid`,
 *   `sitepack_line_depth_exceeded`;
 * - writer: `sitepack_writer_invalid`, `sitepack_writer_finished`, `sitepack_writer_io_failed`.
 */
final class SitePackRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message === '' ? 'SitePack refused: '.$reasonCode : $message, 0, $previous);
    }
}
