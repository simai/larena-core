<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Support\SchemaName;

$name = 'larena.example_format';
$checks = [
    [SchemaName::stamp($name, 2), true],
    [['schema' => $name.'.v2'], true],
    [['schema' => $name.'.v2', 'schema_version' => 2], true],
    [['schema' => $name.'.v2', 'schema_version' => 3], false],
    [['schema' => $name.'.v1'], false],
    [['schema' => $name], false],
    [['schema' => $name, 'schema_version' => '2'], false],
    [['schema' => $name, 'schema_version' => 1], false],
    [['schema' => 'larena.other_format', 'schema_version' => 2], false],
    [[], false],
];
foreach ($checks as $index => [$data, $expected]) {
    if (SchemaName::is($data, $name, 2) !== $expected) {
        fwrite(STDERR, "schema name check {$index} failed\n");
        exit(1);
    }
}
if (SchemaName::stamp($name, 2) !== ['schema' => $name, 'schema_version' => 2]) {
    fwrite(STDERR, "schema stamp failed\n");
    exit(1);
}

echo "Schema names without versions passed.\n";
