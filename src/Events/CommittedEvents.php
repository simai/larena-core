<?php

declare(strict_types=1);

namespace Larena\Core\Events;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * Dispatches a core event once the change behind it is committed on the caller's connection. Outside a transaction
 * it goes at once; inside one it waits for the outermost commit and is dropped on a rollback. Without the
 * application's event dispatcher (a bare script) nothing is sent. A listener's failure never undoes or fails the
 * change: it is reported, and the change stands.
 *
 * @internal
 */
final class CommittedEvents
{
    public static function dispatch(ConnectionInterface $connection, object $event): void
    {
        $send = static function () use ($event): void {
            $events = self::dispatcher();
            if ($events === null) {
                return;
            }
            try {
                $events->dispatch($event);
            } catch (Throwable $exception) {
                self::report($exception);
            }
        };

        if ($connection->transactionLevel() === 0) {
            $send();

            return;
        }
        if (!$connection instanceof Connection) {
            return;
        }
        try {
            // Laravel keeps it for the outermost commit and forgets it on a rollback.
            $connection->afterCommit($send);
        } catch (RuntimeException) {
            // No transaction manager on this connection (not an application's): nothing can be promised after
            // commit, so nothing is sent; a consumer's periodic check still sees the change.
        }
    }

    private static function dispatcher(): ?Dispatcher
    {
        if (!class_exists(Container::class)) {
            return null;
        }
        $container = Container::getInstance();
        if (!$container->bound('events')) {
            return null;
        }
        $events = $container->make('events');

        return $events instanceof Dispatcher ? $events : null;
    }

    private static function report(Throwable $exception): void
    {
        if (function_exists('report')) {
            report($exception);
        }
    }
}
