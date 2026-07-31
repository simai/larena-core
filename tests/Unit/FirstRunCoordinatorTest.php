<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\FirstRun\FirstRunAvailability;
use Larena\Core\FirstRun\FirstRunContext;
use Larena\Core\FirstRun\FirstRunCoordinator;
use Larena\Core\FirstRun\FirstRunPayload;
use Larena\Core\FirstRun\FirstRunValidationFailed;

/** @phpstan-impure */
function first_run_availability(FirstRunCoordinator $coordinator): string
{
    return $coordinator->availability()->state;
}

/** @phpstan-impure */
function first_run_table_count($connection, string $table): int
{
    return $connection->table($table)->count();
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$connection = $capsule->getConnection();
$connection->getSchemaBuilder()->create('larena_install_state', static function (Blueprint $table): void {
    $table->id();
    $table->string('state_key')->unique();
    $table->string('state_status');
    $table->string('launch_record_id')->nullable();
    $table->string('evidence_path')->nullable();
    $table->json('payload')->nullable();
    $table->timestamps();
});
$connection->getSchemaBuilder()->create('first_run_test_steps', static function (Blueprint $table): void {
    $table->string('step')->primary();
});

$payload = new FirstRunPayload('Administrator', 'admin@example.test', 'Strong-password!', 'Starter site', 'en', 'UTC');
$make = static function (string $id, int $priority, string $state = FirstRunContributor::STATE_EMPTY, bool $fail = false) use ($connection): FirstRunContributor {
    return new class($id, $priority, $state, $fail, $connection) implements FirstRunContributor {
        public function __construct(
            private string $step,
            private int $order,
            private string $currentState,
            private bool $fail,
            private $connection,
        ) {
        }
        public function id(): string { return $this->step; }
        public function priority(): int { return $this->order; }
        public function validate(FirstRunPayload $payload): array { return $payload->siteName === '' ? ['site_name' => 'required'] : []; }
        public function state(): string { return $this->currentState; }
        public function apply(FirstRunPayload $payload, FirstRunContext $context): FirstRunContext
        {
            $this->connection->table('first_run_test_steps')->insert(['step' => $this->step]);
            if ($this->fail) {
                throw new RuntimeException('injected');
            }
            return $context->with($this->step . '.done', 1);
        }
    };
};

$coordinator = new FirstRunCoordinator($connection, [$make('content', 300), $make('auth', 100), $make('setting', 200)]);
assert(first_run_availability($coordinator) === FirstRunAvailability::AVAILABLE);
$result = $coordinator->bootstrap($payload);
assert($result->integer('auth.done') === 1);
assert($connection->table('first_run_test_steps')->orderByRaw('rowid')->pluck('step')->all() === ['auth', 'setting', 'content']);
assert(first_run_availability($coordinator) === FirstRunAvailability::COMPLETED);

$connection->table('larena_install_state')->delete();
$connection->table('first_run_test_steps')->delete();
$failing = new FirstRunCoordinator($connection, [$make('auth', 100), $make('setting', 200, fail: true), $make('content', 300)]);
try {
    $failing->bootstrap($payload);
    throw new LogicException('Injected failure must escape.');
} catch (RuntimeException $exception) {
    assert($exception->getMessage() === 'injected');
}
assert(first_run_table_count($connection, 'first_run_test_steps') === 0);
assert(first_run_table_count($connection, 'larena_install_state') === 0);

try {
    $coordinator = new FirstRunCoordinator($connection, [$make('auth', 100), $make('setting', 200)]);
    throw new RuntimeException('Missing contributor must fail closed.');
} catch (LogicException) {
}

$invalid = new FirstRunCoordinator($connection, [$make('auth', 100), $make('setting', 200), $make('content', 300)]);
try {
    $invalid->bootstrap(new FirstRunPayload('Admin', 'admin@example.test', 'Strong-password!', '', 'en', 'UTC'));
    throw new RuntimeException('Validation must fail.');
} catch (FirstRunValidationFailed $exception) {
    assert(isset($exception->errors['site_name']));
}
assert(first_run_table_count($connection, 'first_run_test_steps') === 0);

echo "First-run coordinator contract passed.\n";
