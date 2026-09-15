<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pest = $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pest';
$forcedDriver = getenv('COVERAGE_DRIVER') ?: null;

if ($forcedDriver !== null && ! in_array($forcedDriver, ['pcov', 'xdebug'], true)) {
    fwrite(STDERR, "Unsupported COVERAGE_DRIVER: {$forcedDriver}. Use pcov or xdebug.\n");
    exit(2);
}

$hasPcov = extension_loaded('pcov');
$hasXdebug = extension_loaded('xdebug');

if ($forcedDriver === 'pcov' && ! $hasPcov) {
    fwrite(STDERR, "PCOV was requested but is not installed.\n");
    exit(2);
}

if ($forcedDriver === 'xdebug' && ! $hasXdebug) {
    fwrite(STDERR, "Xdebug was requested but is not installed.\n");
    exit(2);
}

$usePcov = $forcedDriver === 'pcov' || ($forcedDriver !== 'xdebug' && $hasPcov);

if (! $usePcov && ! $hasXdebug) {
    fwrite(STDERR, "No supported coverage driver is installed. Install PCOV or Xdebug.\n");
    exit(2);
}

$environment = getenv();
$environment = is_array($environment) ? $environment : [];
if ($usePcov) {
    $driver = 'PCOV';
    $phpOptions = ['-d', 'memory_limit=4G', '-d', 'pcov.enabled=1', '-d', 'pcov.directory=app'];
    $passthruPhp = '-d memory_limit=4G -d pcov.enabled=1 -d pcov.directory=app';
    $environment['XDEBUG_MODE'] = 'off';
} else {
    $driver = 'Xdebug';
    $phpOptions = ['-d', 'memory_limit=4G', '-d', 'pcov.enabled=0', '-d', 'xdebug.mode=coverage'];
    $passthruPhp = '-d memory_limit=4G -d pcov.enabled=0 -d xdebug.mode=coverage';
    $environment['XDEBUG_MODE'] = 'coverage';
}

$minimum = getenv('COVERAGE_MIN');
$minimum = $minimum === false ? '100' : $minimum;
$pestOptions = [
    '--coverage',
    '--only-summary-for-coverage-text',
    '--compact',
    '--parallel',
    '--max-processes=16',
];

if ($minimum !== 'off') {
    $pestOptions[] = '--min='.$minimum;
}
$command = array_merge(
    [PHP_BINARY],
    $phpOptions,
    [$pest],
    $pestOptions,
    array_slice($argv, 1),
    ['--passthru-php='.$passthruPhp],
);

fwrite(STDOUT, "Coverage driver: {$driver}\n");

$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, $environment);

if (! is_resource($process)) {
    fwrite(STDERR, "Unable to start the Pest coverage process.\n");
    exit(2);
}

exit(proc_close($process));
