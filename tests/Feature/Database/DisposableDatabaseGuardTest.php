<?php

declare(strict_types=1);

use App\Listeners\GuardDestructiveDatabaseCommand;
use App\Support\Database\DisposableDatabaseGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

uses(WithConsoleEvents::class);

function guardedConnection(string $driver, string $databaseName): Connection
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn($driver);
    $connection->shouldReceive('getDatabaseName')->andReturn($databaseName);

    return $connection;
}

it('allows destructive operations on SQLite without querying the connection', function (): void {
    $connection = guardedConnection('sqlite', ':memory:');
    $connection->shouldNotReceive('selectOne');

    new DisposableDatabaseGuard([])->assertDisposable($connection);
});

it('refuses a MySQL schema that is not explicitly listed as disposable before any query', function (string $databaseName): void {
    $connection = guardedConnection('mysql', $databaseName);
    $connection->shouldNotReceive('selectOne');

    expect(fn () => new DisposableDatabaseGuard(['ierp_fresh_ab12_testing'])->assertDisposable($connection))
        ->toThrow(LogicException::class, 'not listed in IERP_DISPOSABLE_DATABASES');
})->with([
    'application database' => 'ierp',
    'unrelated schema' => 'dllni-pro-test',
    'prefix of a listed schema' => 'ierp_fresh_ab12',
    'listed schema with a suffix' => 'ierp_fresh_ab12_testing_backup',
    'different case' => 'IERP_FRESH_AB12_TESTING',
    'empty name' => '',
]);

it('refuses a listed MySQL schema when the connection has another schema selected', function (?string $selectedDatabase): void {
    $connection = guardedConnection('mysql', 'ierp_fresh_ab12_testing');
    $connection->shouldReceive('selectOne')->with('SELECT DATABASE() AS selected_database')->once()
        ->andReturn((object) ['selected_database' => $selectedDatabase]);

    expect(fn () => new DisposableDatabaseGuard(['ierp_fresh_ab12_testing'])->assertDisposable($connection))
        ->toThrow(LogicException::class, 'does not have it selected');
})->with([
    'unrelated schema' => 'beroya',
    'no schema' => [null],
]);

it('allows a listed MySQL schema that the connection has selected', function (string $driver): void {
    $connection = guardedConnection($driver, 'ierp_fresh_ab12_testing');
    $connection->shouldReceive('selectOne')->with('SELECT DATABASE() AS selected_database')->once()
        ->andReturn((object) ['selected_database' => 'ierp_fresh_ab12_testing']);

    new DisposableDatabaseGuard(['ierp_other_testing', 'ierp_fresh_ab12_testing'])->assertDisposable($connection);
})->with(['mysql', 'mariadb']);

it('refuses drivers whose selected schema it cannot verify', function (): void {
    $connection = guardedConnection('pgsql', 'ierp_fresh_ab12_testing');
    $connection->shouldNotReceive('selectOne');

    expect(fn () => new DisposableDatabaseGuard(['ierp_fresh_ab12_testing'])->assertDisposable($connection))
        ->toThrow(LogicException::class, 'unsupported [pgsql] connection');
});

it('reads the disposable allow-list from configuration', function (): void {
    config(['database.disposable_databases' => ['ierp_fresh_ab12_testing']]);
    $connection = guardedConnection('mysql', 'ierp');

    expect(fn () => app(DisposableDatabaseGuard::class)->assertDisposable($connection))
        ->toThrow(LogicException::class, 'Refusing a destructive operation on database [ierp]');
});

it('guards the connection named by a destructive command', function (string $command, string $arguments): void {
    $connection = guardedConnection('mysql', 'ierp');
    $databases = Mockery::mock(DatabaseManager::class);
    $databases->shouldReceive('connection')->with('warehouse_concurrency')->once()->andReturn($connection);

    $listener = new GuardDestructiveDatabaseCommand(new DisposableDatabaseGuard([]), $databases);

    expect(fn () => $listener->handle(new CommandStarting($command, new StringInput($arguments), new NullOutput)))
        ->toThrow(LogicException::class, 'Refusing a destructive operation on database [ierp]');
})->with([
    'fresh with separate option value' => ['migrate:fresh', 'migrate:fresh --database warehouse_concurrency --force'],
    'refresh with inline option value' => ['migrate:refresh', 'migrate:refresh --database=warehouse_concurrency'],
    'reset' => ['migrate:reset', 'migrate:reset --database=warehouse_concurrency'],
    'wipe' => ['db:wipe', 'db:wipe --database=warehouse_concurrency'],
]);

it('guards the default connection when a destructive command names none', function (): void {
    $databases = Mockery::mock(DatabaseManager::class);
    $databases->shouldReceive('connection')->with(null)->once()->andReturn(guardedConnection('mysql', 'ierp'));

    $listener = new GuardDestructiveDatabaseCommand(new DisposableDatabaseGuard([]), $databases);

    expect(fn () => $listener->handle(new CommandStarting('migrate:fresh', new ArrayInput(['--force' => true]), new NullOutput)))
        ->toThrow(LogicException::class, 'not listed in IERP_DISPOSABLE_DATABASES');
});

it('ignores commands that do not destroy data', function (): void {
    $databases = Mockery::mock(DatabaseManager::class);
    $databases->shouldNotReceive('connection');

    $listener = new GuardDestructiveDatabaseCommand(new DisposableDatabaseGuard([]), $databases);

    $listener->handle(new CommandStarting('migrate', new StringInput('migrate --database=warehouse_concurrency'), new NullOutput));
});

it('refuses to set up a test whose default connection is an unlisted MySQL schema', function (): void {
    config([
        'database.disposable_databases' => [],
        'database.connections.guard_probe' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'ierp'],
        'database.default' => 'guard_probe',
    ]);

    expect(fn () => $this->assertDefaultConnectionIsDisposable())
        ->toThrow(LogicException::class, 'Refusing a destructive operation on database [ierp]');
});

it('stops a real migrate:fresh run against an unlisted MySQL schema before it connects', function (): void {
    config([
        'database.disposable_databases' => [],
        'database.connections.guard_probe' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'ierp',
            'username' => 'guard_probe',
            'password' => '',
        ],
    ]);

    expect(fn () => $this->artisan('migrate:fresh', ['--database' => 'guard_probe', '--force' => true])->run())
        ->toThrow(LogicException::class, 'Refusing a destructive operation on database [ierp]');
});
