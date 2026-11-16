<?php

$rootDir = realpath(__DIR__ . '/..');
chdir($rootDir);

$runnerFile = $rootDir . '/src/addons/Olakunlevpn/LargeSqlImporter/Runner.php';
if (!is_file($runnerFile))
{
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    die("Error: LargeSqlImporter add-on is missing or files not uploaded to src/addons/Olakunlevpn/LargeSqlImporter.");
}

require_once $runnerFile;
\Olakunlevpn\LargeSqlImporter\Runner::run($rootDir, 'install');
