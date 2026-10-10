<?php

declare(strict_types=1);

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\MySqlBuilder;
use Mockery\MockInterface;
use Tests\Support\DisposableMySqlSchema;

beforeEach(function (): void {
    config(['database.disposable_databases' => ['ierp_isolation_testing']]);
});

function mockDisposableSchemaConnection(string $databaseName, string $driver = 'mysql'): MockInterface
{
    $connection = Mockery::mock(MySqlConnection::class);
    $connection->shouldReceive('getDriverName')->andReturn($driver);
    $connection->shouldReceive('getDatabaseName')->andReturn($databaseName);

    return $connection;
}

function selectDisposableSchema(MockInterface $connection, ?string $selectedDatabase): void
{
    $connection->shouldReceive('selectOne')->with('SELECT DATABASE() AS selected_database')->once()
        ->andReturn((object) ['selected_database' => $selectedDatabase]);
}

/**
 * @param  list<string>  $grants
 */
function grantDisposableSchemaAccount(MockInterface $connection, array $grants): void
{
    $connection->shouldReceive('select')->with('SHOW GRANTS FOR CURRENT_USER()')->once()->andReturn(array_map(
        static fn (string $grant): object => (object) ['Grants for qa@localhost' => $grant],
        $grants,
    ));
}

/** @return list<string> */
function scopedDisposableSchemaGrants(): array
{
    return [
        'GRANT USAGE ON *.* TO `qa`@`localhost`',
        'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, CREATE TEMPORARY TABLES ON `ierp\_isolation\_testing`.* TO `qa`@`localhost`',
    ];
}

it('refuses cleanup of a non-disposable or non-MySQL database before any query', function (string $databaseName, string $driver): void {
    $connection = mockDisposableSchemaConnection($databaseName, $driver);
    $connection->shouldNotReceive('selectOne', 'select', 'getSchemaBuilder', 'statement', 'table');

    expect(fn () => DisposableMySqlSchema::truncate($connection))
        ->toThrow(LogicException::class, 'dedicated MySQL test database');
})->with([
    'application database' => ['ierp', 'mysql'],
    'empty database name' => ['', 'mysql'],
    'unrelated _test schema' => ['alnadha_worker_test', 'mysql'],
    'unrelated -test schema' => ['dllni-pro-test', 'mysql'],
    'unlabelled ierp schema' => ['ierp_testing', 'mysql'],
    'text after the testing suffix' => ['ierp_race_testing_backup', 'mysql'],
    'trailing newline' => ["ierp_isolation_testing\n", 'mysql'],
    'foreign prefix' => ['beroya_ierp_race_testing', 'mysql'],
    'SQLite connection' => ['ierp_isolation_testing', 'sqlite'],
]);

it('refuses cleanup of a well-named schema that is not listed as disposable before any query', function (): void {
    $connection = mockDisposableSchemaConnection('ierp_other_testing');
    $connection->shouldNotReceive('selectOne', 'select', 'getSchemaBuilder', 'statement', 'table');

    expect(fn () => DisposableMySqlSchema::truncate($connection))
        ->toThrow(LogicException::class, 'not listed in IERP_DISPOSABLE_DATABASES');
});

it('refuses cleanup when the connection has another schema selected', function (): void {
    $connection = mockDisposableSchemaConnection('ierp_isolation_testing');
    selectDisposableSchema($connection, 'beroya');
    $connection->shouldNotReceive('select', 'getSchemaBuilder', 'statement', 'table');

    expect(fn () => DisposableMySqlSchema::truncate($connection))
        ->toThrow(LogicException::class, 'does not have it selected');
});

it('refuses cleanup for an account granted more than its dedicated schema', function (array $grants): void {
    $connection = mockDisposableSchemaConnection('ierp_isolation_testing');
    selectDisposableSchema($connection, 'ierp_isolation_testing');
    grantDisposableSchemaAccount($connection, $grants);
    $connection->shouldNotReceive('getSchemaBuilder', 'statement', 'table');

    expect(fn () => DisposableMySqlSchema::truncate($connection))
        ->toThrow(LogicException::class, 'least-privilege account');
})->with([
    'global root account' => [['GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` WITH GRANT OPTION']],
    'root proxy grant' => [['GRANT USAGE ON *.* TO `qa`@`localhost`', 'GRANT PROXY ON ``@`` TO `qa`@`localhost` WITH GRANT OPTION']],
    'unrelated schema grant' => [[
        ...scopedDisposableSchemaGrants(),
        'GRANT ALL PRIVILEGES ON `beroya`.* TO `qa`@`localhost`',
    ]],
    'unescaped wildcard grant matching other schemas' => [['GRANT ALL PRIVILEGES ON `ierp_isolation_testing`.* TO `qa`@`localhost`']],
    'pattern grant' => [['GRANT ALL PRIVILEGES ON `ierp\_%\_testing`.* TO `qa`@`localhost`']],
    'column grant' => [['GRANT SELECT (`id`) ON `ierp\_isolation\_testing`.`users` TO `qa`@`localhost`']],
    'inherited role' => [['GRANT `dba`@`%` TO `qa`@`localhost`']],
    'no grants' => [[]],
]);

it('restricts cleanup enumeration and mutations to its dedicated schema', function (): void {
    $connection = mockDisposableSchemaConnection('ierp_isolation_testing');
    selectDisposableSchema($connection, 'ierp_isolation_testing');
    grantDisposableSchemaAccount($connection, scopedDisposableSchemaGrants());
    $schema = Mockery::mock(MySqlBuilder::class);
    $query = Mockery::mock(QueryBuilder::class);
    $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
    $schema->shouldReceive('getTableListing')->with('ierp_isolation_testing', false)->once()
        ->andReturn(['migrations', 'inventory_stocks']);
    $connection->shouldReceive('statement')->with('SET FOREIGN_KEY_CHECKS=0')->once()->andReturn(true);
    $connection->shouldReceive('statement')->with('SET FOREIGN_KEY_CHECKS=1')->once()->andReturn(true);
    $connection->shouldReceive('table')->with('inventory_stocks')->once()->andReturn($query);
    $connection->shouldNotReceive('table')->with('migrations');
    $query->shouldReceive('truncate')->once();

    DisposableMySqlSchema::truncate($connection);
});

it('refuses cleanup for a qualified or empty table listing before any mutation', function (array $tables, string $message): void {
    $connection = mockDisposableSchemaConnection('ierp_isolation_testing');
    selectDisposableSchema($connection, 'ierp_isolation_testing');
    grantDisposableSchemaAccount($connection, scopedDisposableSchemaGrants());
    $schema = Mockery::mock(MySqlBuilder::class);
    $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
    $schema->shouldReceive('getTableListing')->with('ierp_isolation_testing', false)->once()->andReturn($tables);
    $connection->shouldNotReceive('statement', 'table');

    expect(fn () => DisposableMySqlSchema::truncate($connection))
        ->toThrow(LogicException::class, $message);
})->with([
    'foreign qualified table' => [['inventory_stocks', 'foreign_schema.users'], 'unqualified table names'],
    'unmigrated schema' => [[], 'found no tables'],
]);
