<?php

declare(strict_types=1);

namespace Olakunlevpn\LargeSqlImporter\Cli\Command;

use Olakunlevpn\LargeSqlImporter\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Import extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('xf-addon:large-sql-importer')
            ->setDescription('High-performance streaming SQL dump importer and chunk merger')
            ->addOption('action', 'a', InputOption::VALUE_OPTIONAL, 'Action to perform: list, test-db, merge, import', 'list')
            ->addOption('file', 'f', InputOption::VALUE_OPTIONAL, 'File name in internal_data/sql_import/ to import')
            ->addOption('base-name', 'b', InputOption::VALUE_OPTIONAL, 'Chunk base name to merge')
            ->addOption('key', 'k', InputOption::VALUE_OPTIONAL, 'Decryption key for encrypted chunks or dumps')
            ->addOption('delete-chunks', null, InputOption::VALUE_NONE, 'Delete chunks after successful merge');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootDir = \XF::getRootDirectory();
        $runner = new Runner($rootDir, 'cli');

        $action = (string)($input->getOption('action') ?: 'list');
        $file = $input->getOption('file');
        $baseName = $input->getOption('base-name');
        $key = $input->getOption('key');
        $deleteChunks = (bool)$input->getOption('delete-chunks');

        return $runner->runCli($output, $action, [
            'file' => $file,
            'baseName' => $baseName,
            'key' => $key,
            'deleteChunks' => $deleteChunks,
        ]);
    }
}
