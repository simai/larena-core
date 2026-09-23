<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Registry\CoreOperationProvider;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Registry\OperationCoverageReport;

$packageRoot = dirname(__DIR__, 2);
$registry = DeclaredOperationRegistry::fromProviders([new CoreOperationProvider()]);
$report = new OperationCoverageReport($registry);

// Core alone: every access operation code core declares is covered by a
// registered operation, and nothing is left over.
$coreOnly = $report->build(['larena/core' => $packageRoot], 'test-revision');
assert($coreOnly['schema'] === 'larena.operation_coverage_report.v1');
assert($coreOnly['generated_for_revision'] === 'test-revision');
assert($coreOnly['status'] === 'passed', 'core must cover its own declarations: ' . json_encode($coreOnly['declared_not_registered']));
assert($coreOnly['declared_not_registered'] === []);
assert($coreOnly['registered_without_declared_access_code'] === []);
assert($coreOnly['descriptor_file_violations'] === []);
assert($coreOnly['descriptor_file_notices'] === []);
assert($coreOnly['gap_counts']['scheduled_gap'] === 0 && $coreOnly['gap_counts']['unscheduled_gap'] === 0);
assert(count($coreOnly['registered_operations']) === 22);
assert(in_array('core.transport.invoke', $coreOnly['declared_access_operation_codes'], true));

$coveredCodes = array_column($coreOnly['covered'], 'access_operation_code');
assert(in_array('core.scope.manage', $coveredCodes, true));
assert(in_array('core.operation_registry.read', $coveredCodes, true));

// A package scheduled for a later batch is a recorded gap, not a failure: the
// report stays usable for as long as the plan says the gap will exist.
$scheduled = sys_get_temp_dir() . '/larena-coverage-scheduled-' . getmypid();
@mkdir($scheduled, 0777, true);
file_put_contents($scheduled . '/access.yaml', <<<YAML
schema: larena.access.descriptor.v1
package: larena/storage
operations:
  - code: storage.record.manage
    risk: critical
    audit: always
YAML);

$withScheduled = $report->build(['larena/core' => $packageRoot, 'larena/storage' => $scheduled]);
assert($withScheduled['status'] === 'passed', 'a scheduled gap must not fail the report');
assert(count($withScheduled['declared_not_registered']) === 1);
assert($withScheduled['declared_not_registered'][0]['state'] === 'scheduled_gap');
assert($withScheduled['declared_not_registered'][0]['scheduled_in'] === 'batch-4-storage-roles');

// A package nobody scheduled is a gap the report names but does not fail on: a
// gate that is red for as long as the plan says a gap exists teaches everyone to
// ignore it. The state field is what makes the unplanned gap visible.
$unscheduled = sys_get_temp_dir() . '/larena-coverage-unscheduled-' . getmypid();
@mkdir($unscheduled, 0777, true);
file_put_contents($unscheduled . '/access.yaml', <<<YAML
schema: larena.access.descriptor.v1
package: larena/surprise
operations:
  - code: surprise.thing.manage
    risk: critical
    audit: always
YAML);

$withUnscheduled = $report->build(['larena/core' => $packageRoot, 'larena/surprise' => $unscheduled]);
assert($withUnscheduled['status'] === 'passed', 'a gap must not fail the gate');
$states = array_column($withUnscheduled['declared_not_registered'], 'state');
assert(in_array('unscheduled_gap', $states, true), 'an unplanned gap must be named');
assert($withUnscheduled['gap_counts']['unscheduled_gap'] === 1);
assert($withUnscheduled['gap_counts']['scheduled_gap'] === 0);
assert($withScheduled['gap_counts']['scheduled_gap'] === 1);

// A malformed descriptor file fails the report. This is the Batch 2 defect: it
// passed every gate in three repositories, and now it cannot.
$defective = sys_get_temp_dir() . '/larena-coverage-defect-' . getmypid();
@mkdir($defective, 0777, true);
file_put_contents($defective . '/access.yaml', <<<YAML
schema: larena.access.descriptor.v1
package: larena/access
operations:
  - code: access.role.read
    risk: high
    audit: denial
presets:
  - administrator
  - code: access.node_grant.manage
    risk: critical
    audit: always
YAML);

$withDefect = $report->build(['larena/core' => $packageRoot, 'larena/access' => $defective]);
assert($withDefect['status'] === 'failed', 'a malformed descriptor file must fail the report');
assert($withDefect['descriptor_file_violations'] !== []);
assert($withDefect['descriptor_file_violations'][0]['package'] === 'larena/access');
assert($withDefect['descriptor_file_violations'][0]['reason_code'] === 'unexpected_map_in_list');

// An operation registered against a code no package declares also fails: the
// check runs in both directions.
$orphanRegistry = new DeclaredOperationRegistry();
$orphan = larena_test_declaration(['name' => 'test.thing.change', 'accessScope' => 'test.nobody.declares']);
$orphanRegistry->register($orphan, larena_test_descriptor_for($orphan), 'core.handler.test');
$orphanReport = (new OperationCoverageReport($orphanRegistry))->build(['larena/core' => $packageRoot]);
assert($orphanReport['status'] === 'failed');
assert($orphanReport['registered_without_declared_access_code'][0]['access_scope'] === 'test.nobody.declares');

// An unversioned descriptor is a notice, not a failure: it names drift this
// package cannot fix.
$unversioned = sys_get_temp_dir() . '/larena-coverage-unversioned-' . getmypid();
@mkdir($unversioned, 0777, true);
file_put_contents($unversioned . '/access.yaml', <<<YAML
operations:
  - {code: legacy.thing.read, scope: legacy.thing:all}
YAML);

$withNotice = (new OperationCoverageReport($registry))->build([
    'larena/core' => $packageRoot,
    'larena/legacy' => $unversioned,
]);
assert($withNotice['status'] === 'passed', 'an unversioned descriptor must not fail the gate');
assert($withNotice['descriptor_file_notices'][0]['reason_code'] === 'schema_absent');
assert($withNotice['descriptor_file_violations'] === []);
@unlink($unversioned . '/access.yaml');
@rmdir($unversioned);

foreach ([$scheduled, $unscheduled, $defective] as $path) {
    @unlink($path . '/access.yaml');
    @rmdir($path);
}

echo "Operation coverage report passed.\n";
