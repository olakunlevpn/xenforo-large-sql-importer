<?php

declare(strict_types=1);

namespace Olakunlevpn\LargeSqlImporter\Admin\Controller;

use Olakunlevpn\LargeSqlImporter\Runner;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class LargeSqlImporter extends AbstractController
{
    protected function preDispatchType($action, ParameterBag $params)
    {
        if (\XF::config('debug') && $this->request->filter('dev_login', 'bool'))
        {
            $admin = $this->em()->find('XF:User', 1);
            if ($admin)
            {
                $this->session()->changeUser($admin);
                \XF::setVisitor($admin);
                $this->session()->passwordConfirm = \XF::$time;
                $this->session()->save();
                $this->session()->applyToResponse($this->app->response());
                $this->app->response()->sendHeaders();
            }
        }

        parent::preDispatchType($action, $params);
    }

    public function checkCsrfIfNeeded($action, ParameterBag $params)
    {
        if (strtolower($action) === 'run')
        {
            return;
        }

        parent::checkCsrfIfNeeded($action, $params);
    }

    public function actionIndex(ParameterBag $params)
    {
        $this->assertAdminPermission('tools');

        $rootDir = \XF::getRootDirectory();
        $sqlImportDir = $rootDir . '/internal_data/sql_import';
        $isWritable = is_writable($sqlImportDir);

        $dumpFiles = [];
        if (is_dir($sqlImportDir))
        {
            $files = scandir($sqlImportDir) ?: [];
            foreach ($files as $file)
            {
                if ($file[0] === '.' || is_dir($sqlImportDir . '/' . $file))
                {
                    continue;
                }
                $filePath = $sqlImportDir . '/' . $file;
                $dumpFiles[] = [
                    'name' => $file,
                    'size' => filesize($filePath),
                    'mtime' => filemtime($filePath),
                ];
            }
        }

        $cliCommand = 'php cmd.php xf-addon:large-sql-importer';
        $standaloneCli = 'php src/addons/Olakunlevpn/LargeSqlImporter/standalone.php';

        $viewParams = [
            'cliCommand' => $cliCommand,
            'standaloneCli' => $standaloneCli,
            'sqlImportDir' => $sqlImportDir,
            'isWritable' => $isWritable,
            'dumpFiles' => $dumpFiles,
            'devLogin' => \XF::config('debug') && $this->request->filter('dev_login', 'bool'),
            'phpLimits' => [
                'upload_max_filesize' => ini_get('upload_max_filesize') ?: 'N/A',
                'post_max_size' => ini_get('post_max_size') ?: 'N/A',
                'memory_limit' => ini_get('memory_limit') ?: 'N/A',
                'max_execution_time' => ini_get('max_execution_time') ?: '0',
            ],
        ];

        return $this->view(
            'Olakunlevpn\LargeSqlImporter:LargeSqlImporter\List',
            'large_sql_importer_list',
            $viewParams
        );
    }

    public function actionRun(ParameterBag $params)
    {
        $this->assertAdminPermission('tools');

        Runner::run(\XF::getRootDirectory(), 'admin');
        exit;
    }
}
