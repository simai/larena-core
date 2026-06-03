<?php

declare(strict_types=1);

$context = json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR);
$evidencePath = rtrim((string) $context['evidence_path'], '/') . '/';
$proposalPath = (string) $context['graph_sync_proposal_path'];

$errors = [];

if (!is_dir($evidencePath)) {
    $errors[] = "Missing evidence directory: {$evidencePath}";
}

if (!is_file($proposalPath)) {
    $errors[] = "Missing graph sync proposal: {$proposalPath}";
} else {
    $proposal = json_decode((string) file_get_contents($proposalPath), true, 512, JSON_THROW_ON_ERROR);
    if (($proposal['canonical_update_allowed'] ?? null) !== false) {
        $errors[] = 'graph-sync-proposal must keep canonical_update_allowed=false';
    }
}

if (($context['coding_started'] ?? false) === true) {
    foreach (['implementation-summary.md', 'tests.md', 'smoke.md', 'file-map.json', 'deviations.json'] as $required) {
        if (!is_file($evidencePath . $required)) {
            $errors[] = "Coding evidence is missing: {$evidencePath}{$required}";
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}

echo "Evidence contract is valid for the current repository state.\n";
