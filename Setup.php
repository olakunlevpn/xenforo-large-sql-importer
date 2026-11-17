<?php

namespace Olakunlevpn\LargeSqlImporter;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Util\File;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1(): void
    {
        $dir = \XF::getRootDirectory() . '/internal_data/sql_import';
        if (!is_dir($dir))
        {
            File::createDirectory($dir, false);
        }

        $stateDir = $dir . '/.state';
        if (!is_dir($stateDir))
        {
            File::createDirectory($stateDir, false);
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess))
        {
            File::writeFile($htaccess, "Deny from all\n", false);
        }
    }
}
