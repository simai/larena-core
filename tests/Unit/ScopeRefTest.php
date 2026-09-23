<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Enums\ScopeKind;
use Larena\Core\Scope\ScopeRef;

$site = ScopeRef::of(ScopeKind::Site, 'main');
assert($site->toString() === 'site:main');
assert($site->equals(ScopeRef::parse('site:main')));
assert(ScopeRef::parse('organization:acme')->kind === ScopeKind::Organization);

$rejected = 0;
foreach (['', 'site', 'unknown:main', 'site:', 'site:Main', 'site:-bad', 'site:' . str_repeat('a', 64)] as $candidate) {
    try {
        ScopeRef::parse($candidate);
    } catch (InvalidArgumentException) {
        ++$rejected;
    }
}
assert($rejected === 7, 'every malformed scope reference must be rejected');

// 63 identifier characters is the frozen maximum and stays valid.
assert(ScopeRef::parse('site:' . str_repeat('a', 63))->identifier === str_repeat('a', 63));
assert(ScopeRef::tryParse('site:Main') === null);
assert(ScopeRef::tryParse('site:main') !== null);

echo "Scope reference grammar passed.\n";
