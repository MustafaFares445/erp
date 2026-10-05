<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

$dryRun = in_array('--dry-run', $argv, true);

function gitLines(string ...$args): array
{
    $command = array_merge(['git'], $args);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        return [];
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if ($code !== 0) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (string $line): string => str_replace('\\', '/', mb_trim($line)),
        preg_split('/\R/', (string) $stdout) ?: [],
    )));
}

$files = array_merge(
    gitLines('diff', '--name-only', '--diff-filter=ACMR', 'HEAD'),
    gitLines('diff', '--name-only', '--cached', '--diff-filter=ACMR', 'HEAD'),
    gitLines('ls-files', '--others', '--exclude-standard'),
);

$baseRef = getenv('TEST_CHANGED_BASE') ?: 'origin/dev';
$mergeBase = gitLines('merge-base', 'HEAD', $baseRef)[0] ?? null;

if (is_string($mergeBase) && $mergeBase !== '') {
    $files = array_merge($files, gitLines('diff', '--name-only', '--diff-filter=ACMR', $mergeBase.'...HEAD'));
}

$files = array_values(array_unique($files));

if ($files === []) {
    fwrite(STDOUT, "No changed files detected.\n");
    exit(0);
}

$domains = [];
$exactTests = [];
$needsFast = false;
$docsOnly = true;

$domainPath = static function (string $domain): string {
    return match ($domain) {
        'Accounting' => 'tests/Feature/Accounting',
        'Api' => 'tests/Feature/Api',
        'Crm' => 'tests/Feature/Crm',
        'Employees' => 'tests/Feature/Employees',
        'Filament' => 'tests/Feature/Filament',
        'Inventory' => 'tests/Feature/Inventory',
        'Notifications' => 'tests/Feature/Notifications',
        'Payments' => 'tests/Feature/Payments',
        'Purchasing' => 'tests/Feature/Purchasing',
        'Sales' => 'tests/Feature/Sales',
        'Settings' => 'tests/Feature/Settings',
        'Shipments' => 'tests/Feature/Shipments',
        'Support' => 'tests/Feature/Support',
        default => throw new LogicException('Unknown test domain '.$domain),
    };
};

$add = static function (array $names) use (&$domains): void {
    foreach ($names as $name) {
        $domains[$name] = true;
    }
};

foreach ($files as $file) {
    if (
        str_ends_with($file, '.md')
        || str_starts_with($file, 'Docs/')
        || str_starts_with($file, '.agents/')
        || str_starts_with($file, '.claude/')
        || str_starts_with($file, '.ai/')
        || in_array($file, ['AGENTS.md', 'CLAUDE.md'], true)
    ) {
        continue;
    }

    $docsOnly = false;

    if (str_starts_with($file, 'tests/Feature/') && str_ends_with($file, 'Test.php')) {
        if (! str_contains($file, '/Coverage/')) {
            $exactTests[$file] = true;
        }

        continue;
    }

    if (str_starts_with($file, 'tests/Unit/') && str_ends_with($file, 'Test.php')) {
        if (! str_contains($file, '/Coverage/')) {
            $exactTests[$file] = true;
        }

        continue;
    }

    if (
        in_array($file, ['composer.json', 'composer.lock', 'phpunit.xml', 'tests/Pest.php', 'tests/Feature/Pest.php'], true)
        || str_starts_with($file, '.github/')
        || str_starts_with($file, 'bootstrap/')
        || str_starts_with($file, 'config/')
        || str_starts_with($file, 'database/migrations/')
        || str_starts_with($file, 'app/Models/')
        || str_starts_with($file, 'app/Enums/')
        || str_starts_with($file, 'app/Providers/')
        || str_starts_with($file, 'app/Support/')
    ) {
        $needsFast = true;

        continue;
    }

    if (str_starts_with($file, 'app/Services/Accounting/') || str_starts_with($file, 'app/Reporting/Accounting/')) {
        $add(['Accounting']);

        continue;
    }

    if (str_starts_with($file, 'app/Services/Payments/')) {
        $add(['Payments', 'Accounting', 'Sales']);

        continue;
    }

    if (str_starts_with($file, 'app/Services/Purchasing/')) {
        $add(['Purchasing', 'Inventory']);

        continue;
    }

    if (str_starts_with($file, 'app/Services/Inventory/')) {
        $add(['Inventory']);

        continue;
    }

    if (str_starts_with($file, 'app/Services/Sales/')) {
        $add(['Sales']);

        continue;
    }

    if (str_starts_with($file, 'app/Services/Support/')) {
        $add(['Support']);

        continue;
    }

    if (str_starts_with($file, 'app/Services/Employees/')) {
        $add(['Employees']);

        continue;
    }

    if (str_starts_with($file, 'app/Services/Crm/')) {
        $add(['Crm']);

        continue;
    }

    if (str_starts_with($file, 'app/Services/Settings/')) {
        $add(['Settings']);

        continue;
    }

    if (str_starts_with($file, 'app/Services/Shipments/') || str_starts_with($file, 'app/Services/Logistics/')) {
        $add(['Shipments', 'Inventory', 'Sales']);

        continue;
    }

    if (str_starts_with($file, 'app/Notifications/') || str_starts_with($file, 'app/Listeners/')) {
        $add(['Notifications']);

        continue;
    }

    if (str_starts_with($file, 'app/Http/Controllers/Api/') || str_starts_with($file, 'app/Http/Requests/Api/') || str_starts_with($file, 'app/Http/Resources/')) {
        $add(['Api']);

        continue;
    }

    if (str_starts_with($file, 'app/Filament/') || str_starts_with($file, 'resources/views/filament/') || str_starts_with($file, 'resources/css/filament/')) {
        $add(['Filament']);

        continue;
    }

    if (str_starts_with($file, 'database/seeders/')) {
        $needsFast = true;

        continue;
    }

    if (str_starts_with($file, 'app/') || str_starts_with($file, 'routes/') || str_starts_with($file, 'resources/')) {
        $needsFast = true;
    }
}

fwrite(STDOUT, 'Changed files: '.count($files)."\n");

if ($docsOnly) {
    fwrite(STDOUT, "Selection: documentation checks only.\n");

    if ($dryRun) {
        exit(0);
    }

    passthru(PHP_BINARY.' '.escapeshellarg($root.'/scripts/docs-check.php'), $code);
    exit($code);
}

if ($needsFast) {
    fwrite(STDOUT, "Selection: full fast behavioral regression (shared/infrastructure impact).\n");
    $targets = [];
} else {
    $targets = array_keys($exactTests);

    foreach (array_keys($domains) as $domain) {
        $targets[] = $domainPath($domain);
    }

    $targets = array_values(array_unique($targets));

    if ($targets === []) {
        fwrite(STDOUT, "Selection: full fast behavioral regression (unmapped impact).\n");
    } else {
        fwrite(STDOUT, "Selection:\n - ".implode("\n - ", $targets)."\n");
    }
}

if ($dryRun) {
    exit(0);
}

$command = [
    PHP_BINARY,
    '-d', 'memory_limit=2G',
    '-d', 'pcov.enabled=0',
    '-d', 'xdebug.mode=off',
    $root.'/vendor/bin/pest',
    '--compact',
    '--exclude-group=coverage-only',
];

if ($targets === []) {
    $command[] = '--parallel';
    $command[] = '--max-processes=16';
} else {
    array_push($command, ...$targets);
}

$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root);

if (! is_resource($process)) {
    fwrite(STDERR, "Unable to start selected Pest tests.\n");
    exit(2);
}

exit(proc_close($process));
