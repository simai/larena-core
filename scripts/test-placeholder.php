<?php

declare(strict_types=1);

$context = json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR);

if (($context['coding_started'] ?? false) === true && !is_dir('tests')) {
    fwrite(STDERR, "Coding has started but tests/ is missing.\n");
    exit(1);
}

echo "Enforcement baseline test command passed; no package implementation code has started.\n";
