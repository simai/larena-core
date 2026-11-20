<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Container\Container;
use Larena\Core\Console\Commands\InstallSealCommand;
use Larena\Core\Contracts\InstalledReleaseSeal;
use Larena\Core\Install\NullInstalledReleaseSeal;

// The installer's seal of a release from a signed archive (owner decision 2026-11-20): core owns the step and its
// command; without a package that checks signatures nothing is sealed and the answer says so.
$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$result = (new NullInstalledReleaseSeal())->seal('/srv/site/releases/1.3.0', '/srv/site/download');
if ($result !== ['sealed' => false, 'reason' => 'release_seal_unavailable', 'version' => null, 'files' => 0]) {
    $fail('Without a signature check nothing is sealed, with a stable reason.');
}

$command = new ReflectionClass(InstallSealCommand::class);
$signature = (string) $command->getProperty('signature')->getDefaultValue();
if (!str_contains($signature, 'larena:install-seal') || !str_contains($signature, '{--from=')) {
    $fail('The seal step is the command larena:install-seal --from=<folder>.');
}

// The registrar offers the null seal only where no package bound a real one.
if (class_exists(Container::class)) {
    $container = new Container();
    $container->bind(InstalledReleaseSeal::class, static fn (): InstalledReleaseSeal => new class implements InstalledReleaseSeal {
        public function seal(string $releaseRoot, string $signedFrom): array
        {
            return ['sealed' => true, 'reason' => null, 'version' => '1.3.0', 'files' => 1];
        }
    });
    $container->bindIf(InstalledReleaseSeal::class, NullInstalledReleaseSeal::class);
    if ($container->make(InstalledReleaseSeal::class)->seal('/a', '/b')['sealed'] !== true) {
        $fail('A package seal bound before core keeps its place.');
    }
}

echo "Installed release seal contract passed.\n";
