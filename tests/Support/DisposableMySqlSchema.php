<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Database\DisposableDatabaseGuard;
use Illuminate\Database\Connection;
use LogicException;

/**
 * Fail-closed guard and cleanup for the dedicated MySQL schemas used by process-concurrency tests.
 *
 * An unscoped table listing run with a broad account once truncated unrelated local schemas, so a
 * schema may only be mutated when it is named ierp_<label>_testing, listed in
 * IERP_DISPOSABLE_DATABASES, selected by the connection, and reachable by an account whose only
 * grants besides USAGE ON *.* target exactly that schema (with escaped `\_`, not a wildcard).
 */
final class DisposableMySqlSchema
{
    public static function isDisposableName(string $databaseName): bool
    {
        return preg_match('/^ierp_[a-z0-9]+(?:_[a-z0-9]+)*_testing\z/', $databaseName) === 1;
    }

    public static function isGrantScoped(string $grant, string $databaseName): bool
    {
        if (preg_match('/^GRANT USAGE ON \*\.\* TO /', $grant) === 1) {
            return true;
        }

        if (preg_match('/^GRANT (?!PROXY )[A-Z ,]+ ON `((?:[^`]|``)+)`\.\* TO /', $grant, $matches) !== 1) {
            return false;
        }

        return $matches[1] === str_replace('_', '\_', $databaseName);
    }

    public static function assertDisposable(Connection $connection): void
    {
        $databaseName = $connection->getDatabaseName();

        if ($connection->getDriverName() !== 'mysql' || ! self::isDisposableName($databaseName)) {
            throw new LogicException('Concurrency cleanup requires a dedicated MySQL test database named ierp_<label>_testing.');
        }

        app(DisposableDatabaseGuard::class)->assertDisposable($connection);

        $grants = $connection->select('SHOW GRANTS FOR CURRENT_USER()');

        if ($grants === []) {
            throw new LogicException('Concurrency cleanup requires a least-privilege account scoped to its dedicated schema.');
        }

        foreach ($grants as $grantRow) {
            $grant = array_values((array) $grantRow)[0] ?? null;

            if (! is_string($grant) || ! self::isGrantScoped($grant, $databaseName)) {
                throw new LogicException('Concurrency cleanup requires a least-privilege account scoped to its dedicated schema.');
            }
        }
    }

    /**
     * Truncate every table of the dedicated schema except the migrations table.
     */
    public static function truncate(Connection $connection): void
    {
        self::assertDisposable($connection);

        $databaseName = $connection->getDatabaseName();
        $tables = $connection->getSchemaBuilder()->getTableListing($databaseName, false);

        if ($tables === []) {
            throw new LogicException('Concurrency cleanup found no tables in its dedicated schema.');
        }

        foreach ($tables as $table) {
            if (preg_match('/^[A-Za-z0-9_]+\z/', $table) !== 1) {
                throw new LogicException('Concurrency cleanup requires unqualified table names from its dedicated schema.');
            }
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                if ($table === 'migrations') {
                    continue;
                }

                $connection->table($table)->truncate();
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
