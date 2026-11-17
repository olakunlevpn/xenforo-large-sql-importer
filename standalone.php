<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__, 4);
if (!$rootDir || !is_dir($rootDir))
{
    $rootDir = realpath(__DIR__ . '/../../../../..') ?: getcwd();
}
chdir($rootDir);

$runnerFile = __DIR__ . '/Runner.php';
if (!is_file($runnerFile))
{
    if (PHP_SAPI === 'cli')
    {
        fwrite(STDERR, "Error: Runner.php not found in " . __DIR__ . PHP_EOL);
    }
    else
    {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
        echo "Error: Runner.php not found in " . __DIR__ . "\n";
    }
    exit(1);
}

require_once $runnerFile;
\Olakunlevpn\LargeSqlImporter\Runner::run($rootDir, 'standalone');
