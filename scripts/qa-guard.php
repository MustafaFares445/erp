<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Invoke before every QA migration, seed, and mutating suite.
// This checks the effective Laravel config, not merely shell environment variables.
$workspace = realpath($argv[1] ?? '') ?: '';
$expected = realpath($argv[2] ?? '') ?: '';
$source = realpath(__DIR__.'/..') ?: '';
if ($workspace === '' || $expected === '' || ! str_starts_with(mb_strtolower($expected), mb_strtolower($workspace.DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('QA guard: expected database must exist inside the workspace.');
}
if (is_file($workspace.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('QA guard: cached Laravel configuration is not allowed.');
}
if (mb_strtolower((string) getcwd()) !== mb_strtolower($workspace)) {
    throw new RuntimeException('QA guard: current directory must be the isolated workspace.');
}
require $workspace.'/vendor/autoload.php';
$app = require $workspace.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$driver = (string) config('database.default');
$sqlitePath = (string) config('database.connections.sqlite.database');
$resolved = realpath($sqlitePath) ?: '';
$actual = (string) DB::connection()->getDatabaseName();
if ($driver !== 'sqlite' || mb_strtolower($resolved) !== mb_strtolower($expected)
    || mb_strtolower((realpath($actual) ?: '')) !== mb_strtolower($expected)
    || DB::connection()->getDriverName() !== 'sqlite') {
    throw new RuntimeException('QA guard: effective connection is not the approved SQLite database.');
}
if ((string) config('app.env') !== 'e2e' && (string) config('app.env') !== 'guide-qa') {
    throw new RuntimeException('QA guard: wrong application environment.');
}
if (getenv('DB_URL') || getenv('DATABASE_URL')) {
    throw new RuntimeException('QA guard: database URL overrides are forbidden.');
}
$main = DB::select('PRAGMA database_list');
foreach ($main as $item) {
    if (($item->name ?? '') === 'main' && mb_strtolower((realpath($item->file ?? '') ?: '')) !== mb_strtolower($expected)) {
        throw new RuntimeException('QA guard: SQLite main database differs from approved path.');
    }
}
echo "SAFE_SQLITE_VERIFIED\n";
