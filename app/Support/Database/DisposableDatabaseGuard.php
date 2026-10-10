<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Connection;
use LogicException;

/**
 * Fails closed before a destructive database operation (wipe, fresh/refresh/reset migrations,
 * test truncation) unless the target is SQLite or a MySQL/MariaDB schema that is listed exactly
 * in IERP_DISPOSABLE_DATABASES and is the schema the connection actually has selected.
 */
final readonly class DisposableDatabaseGuard
{
    /** @var list<string> */
    public const array DESTRUCTIVE_COMMANDS = ['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset'];

    /**
     * @param  list<string>  $disposableDatabases
     */
    public function __construct(
        #[Config('database.disposable_databases', [])]
        private array $disposableDatabases,
    ) {}

    public function assertDisposable(Connection $connection): void
    {
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new LogicException(sprintf('Refusing a destructive operation on an unsupported [%s] connection.', $driver));
        }

        $databaseName = $connection->getDatabaseName();

        if (! in_array($databaseName, $this->disposableDatabases, true)) {
            throw new LogicException(sprintf(
                'Refusing a destructive operation on database [%s]: it is not listed in IERP_DISPOSABLE_DATABASES.',
                $databaseName,
            ));
        }

        $selectedDatabase = $connection->selectOne('SELECT DATABASE() AS selected_database');

        if (! is_object($selectedDatabase) || ($selectedDatabase->selected_database ?? null) !== $databaseName) {
            throw new LogicException(sprintf(
                'Refusing a destructive operation on database [%s]: the connection does not have it selected.',
                $databaseName,
            ));
        }
    }
}
