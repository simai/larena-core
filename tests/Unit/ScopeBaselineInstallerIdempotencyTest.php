<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../support/scope-plane-schema.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Core\Enums\PlaneKind;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Starter\ScopeBaselineInstaller;

$connection = larena_core_scope_plane_connection();
$scopes = new DatabaseScopeRegistry($connection);
$planes = new DatabasePlaneRegistry($connection, $scopes);
$installer = new ScopeBaselineInstaller($connection, $scopes, $planes);

assert(!$installer->isApplied());

$first = $installer->apply('corr-install');
assert($first['status'] === 'applied');
assert($first['site_scope_ref'] === 'site:main');
assert($first['groups_plane_id'] === 'site:main/groups');
assert($first['administrators_node_id'] === 'site:main/groups#administrators');
assert(count($first['created']) === 3);
assert($installer->isApplied());

// The baseline plane is flat: Groups holds root nodes only.
assert($planes->readPlane($first['groups_plane_id'])?->kind === PlaneKind::Flat);
assert($planes->readNode($first['administrators_node_id'])?->depth() === 0);

// Re-running on a populated installation is a no-op.
$second = $installer->apply('corr-install-2');
assert($second['status'] === 'already_applied');
assert($second['created'] === []);
assert($scopes->list()[0]->name === 'Main site');
assert(count($planes->listPlanes($installer->defaultSiteRef())) === 1);
assert(count($planes->listNodes($first['groups_plane_id'])) === 1);

// Without the schema the installer reports instead of throwing, so an install
// path that runs before migrations stays diagnosable.
$bare = new Capsule();
$bare->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$bareConnection = $bare->getConnection();
$bareScopes = new DatabaseScopeRegistry($bareConnection);
$bareInstaller = new ScopeBaselineInstaller($bareConnection, $bareScopes, new DatabasePlaneRegistry($bareConnection, $bareScopes));
assert(!$bareInstaller->isApplied());
assert($bareInstaller->apply()['status'] === 'schema_missing');

echo "Scope baseline installer is idempotent.\n";
