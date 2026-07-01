<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Starter\StarterEvidencePath;

$basePath = sys_get_temp_dir() . '/larena-core-evidence-path-test-' . bin2hex(random_bytes(4));
$default = StarterEvidencePath::path(['base_path' => $basePath], 'starter-cli/doctor-output.json');

if ($default !== $basePath . '/source/output/larena/starter-cli/doctor-output.json') {
    fwrite(STDERR, 'Default evidence path must use ignored app source/output.' . PHP_EOL);
    exit(1);
}

putenv('LARENA_EVIDENCE_DIR=' . $basePath . '/custom-evidence');
$custom = StarterEvidencePath::path(['base_path' => $basePath], '/starter-cli/doctor-output.json');
putenv('LARENA_EVIDENCE_DIR');

if ($custom !== $basePath . '/custom-evidence/starter-cli/doctor-output.json') {
    fwrite(STDERR, 'Custom evidence path must honor LARENA_EVIDENCE_DIR.' . PHP_EOL);
    exit(1);
}

echo 'StarterEvidencePathTest passed.' . PHP_EOL;
