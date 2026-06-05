<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

final class StarterEvidencePath
{
    /**
     * @param array<string, mixed> $applicationContext
     */
    public static function path(array $applicationContext, string $relativePath): string
    {
        $root = getenv('LARENA_EVIDENCE_DIR');

        if (!is_string($root) || trim($root) === '') {
            $root = rtrim((string) $applicationContext['base_path'], '/') . '/source/output/larena';
        }

        return rtrim($root, '/') . '/' . ltrim($relativePath, '/');
    }
}
