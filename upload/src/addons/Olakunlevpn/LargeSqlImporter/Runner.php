<?php

namespace Olakunlevpn\LargeSqlImporter;

class Runner
{
    protected string $rootDir;
    protected string $context;
    protected string $uploadDir;
    protected string $stateDir;
    protected array $dbConfig;
    protected ?\mysqli $db = null;
    protected ?string $dbError = null;

    public static function run(string $rootDir, string $context = 'install'): void
    {
        $runner = new self($rootDir, $context);
        $runner->execute();
    }

    public function __construct(string $rootDir, string $context = 'install')
    {
        $this->rootDir = rtrim($rootDir, '/\\');
        $this->context = $context;

        $this->uploadDir = $this->rootDir . '/internal_data/sql_import';
        $this->stateDir = $this->uploadDir . '/.state';

        if (!is_dir($this->uploadDir))
        {
            @mkdir($this->uploadDir, 0777, true);
        }
        if (!is_dir($this->stateDir))
        {
            @mkdir($this->stateDir, 0777, true);
        }

        $htaccess = $this->uploadDir . '/.htaccess';
        if (!file_exists($htaccess))
        {
            @file_put_contents($htaccess, "Deny from all\n");
        }

        $this->loadDbConfig();
        $this->connectDb();
    }

    protected function loadDbConfig(): void
    {
        $defaults = [
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'password' => '',
            'dbname' => '',
            'source' => 'default'
        ];

        $configFile = $this->rootDir . '/src/config.php';
        if (is_file($configFile))
        {
            $config = [];
            $c = new class { public function extend($k, $v) { return $this; } };
            @include $configFile;

            if (!empty($config['db']))
            {
                $defaults['host'] = $config['db']['host'] ?? 'localhost';
                $defaults['port'] = (int)($config['db']['port'] ?? 3306);
                $defaults['username'] = $config['db']['username'] ?? 'root';
                $defaults['password'] = $config['db']['password'] ?? '';
                $defaults['dbname'] = $config['db']['dbname'] ?? '';
                $defaults['source'] = 'src/config.php';
            }
        }

        if (isset($_REQUEST['db_host']))
        {
            $defaults['host'] = trim((string)$_REQUEST['db_host']);
        }
        if (isset($_REQUEST['db_port']))
        {
            $defaults['port'] = (int)$_REQUEST['db_port'];
        }
        if (isset($_REQUEST['db_username']))
        {
            $defaults['username'] = trim((string)$_REQUEST['db_username']);
        }
        if (isset($_REQUEST['db_password']))
        {
            $defaults['password'] = (string)$_REQUEST['db_password'];
        }
        if (isset($_REQUEST['db_dbname']))
        {
            $defaults['dbname'] = trim((string)$_REQUEST['db_dbname']);
        }

        $this->dbConfig = $defaults;
    }

    protected function connectDb(): void
    {
        $cfg = $this->dbConfig;
        if (empty($cfg['dbname']) && empty($cfg['username']))
        {
            $this->dbError = 'Database credentials not configured yet.';
            return;
        }

        try
        {
            mysqli_report(MYSQLI_REPORT_OFF);
            $conn = @new \mysqli($cfg['host'], $cfg['username'], $cfg['password'], $cfg['dbname'], $cfg['port']);
            if ($conn->connect_errno)
            {
                $this->dbError = "MySQL connection failed ({$conn->connect_errno}): {$conn->connect_error}";
                $this->db = null;
            }
            else
            {
                $conn->set_charset('utf8mb4');
                $this->db = $conn;
                $this->dbError = null;
            }
        }
        catch (\Throwable $e)
        {
            $this->dbError = 'Database connection error: ' . $e->getMessage();
            $this->db = null;
        }
    }

    public function execute(): void
    {
        if (PHP_SAPI === 'cli')
        {
            $this->executeCli();
            return;
        }

        $action = $_REQUEST['action'] ?? '';

        switch ($action)
        {
            case 'ajax_test_db':
                $this->ajaxTestDb();
                break;
            case 'ajax_upload_chunk':
                $this->ajaxUploadChunk();
                break;
            case 'ajax_list_files':
                $this->ajaxListFiles();
                break;
            case 'ajax_delete_file':
                $this->ajaxDeleteFile();
                break;
            case 'ajax_import_batch':
                $this->ajaxImportBatch();
                break;
            case 'ajax_reset_state':
                $this->ajaxResetState();
                break;
            case 'ajax_list_chunks':
                $this->ajaxListChunks();
                break;
            case 'ajax_merge_chunks':
                $this->ajaxMergeChunks();
                break;
            case 'ajax_delete_chunks':
                $this->ajaxDeleteChunks();
                break;
            default:
                $this->renderUi();
                break;
        }
    }

    public function executeCli(): void
    {
        $output = new class {
            public function writeln(string $msg = ''): void
            {
                $clean = preg_replace('/<[^>]+>/', '', $msg);
                echo $clean . PHP_EOL;
            }
            public function write(string $msg = ''): void
            {
                $clean = preg_replace('/<[^>]+>/', '', $msg);
                echo $clean;
            }
        };

        global $argv;
        $args = $argv ?? [];
        $action = 'list';
        $options = [];

        for ($i = 1; $i < count($args); $i++)
        {
            $arg = $args[$i];
            if (str_starts_with($arg, '--action='))
            {
                $action = substr($arg, 9);
            }
            else if (str_starts_with($arg, '--file='))
            {
                $options['file'] = substr($arg, 7);
                $action = 'import';
            }
            else if (str_starts_with($arg, '--base-name='))
            {
                $options['baseName'] = substr($arg, 12);
                $action = 'merge';
            }
            else if (str_starts_with($arg, '--key='))
            {
                $options['key'] = substr($arg, 6);
            }
            else if ($arg === '--delete-chunks')
            {
                $options['deleteChunks'] = true;
            }
            else if ($arg === 'test-db' || $arg === 'list' || $arg === 'import' || $arg === 'merge')
            {
                $action = $arg;
            }
        }

        $code = $this->runCli($output, $action, $options);
        exit($code);
    }

    public function runCli($output, string $action = 'list', array $options = []): int
    {
        switch ($action)
        {
            case 'test-db':
                $output->writeln('<info>Testing Database Connection...</info>');
                $output->writeln('Host: ' . ($this->dbConfig['host'] ?? 'N/A') . ':' . ($this->dbConfig['port'] ?? 3306));
                $output->writeln('Database: ' . ($this->dbConfig['dbname'] ?? 'N/A'));
                $output->writeln('User: ' . ($this->dbConfig['username'] ?? 'N/A'));
                if ($this->db)
                {
                    $output->writeln('<info>Connection Successful! (MySQL ' . $this->db->server_info . ')</info>');
                    return 0;
                }
                $output->writeln('<error>Connection Failed: ' . ($this->dbError ?: 'Could not connect') . '</error>');
                return 1;

            case 'merge':
                $baseName = trim((string)($options['baseName'] ?? ''));
                if ($baseName === '')
                {
                    $output->writeln('<error>Please specify chunk base name via --base-name=<name></error>');
                    return 1;
                }
                return $this->cliMergeChunks($output, $baseName, (string)($options['key'] ?? ''), !empty($options['deleteChunks']));

            case 'import':
                $file = trim((string)($options['file'] ?? ''));
                if ($file === '')
                {
                    $output->writeln('<error>Please specify SQL file name via --file=<filename.sql></error>');
                    return 1;
                }
                return $this->cliImportFile($output, $file);

            case 'list':
            default:
                $output->writeln('<info>===============================================================</info>');
                $output->writeln('<info> [Olakunlevpn] Large SQL Importer & Chunk Merger </info>');
                $output->writeln('<info>===============================================================</info>');
                $output->writeln('Staged Directory: ' . $this->uploadDir);
                $output->writeln('Database: ' . ($this->dbConfig['dbname'] ?? 'N/A') . ' (' . ($this->db ? 'Connected' : 'Not Connected') . ')');
                $output->writeln('');

                $files = $this->getSqlFiles();
                $output->writeln('<comment>Available SQL Dump Files:</comment>');
                if (empty($files))
                {
                    $output->writeln('  (No SQL dump files found in ' . $this->uploadDir . ')');
                }
                else
                {
                    foreach ($files as $f)
                    {
                        $status = $f['state'] ? ($f['state']['status'] ?? 'ready') : 'ready';
                        $queries = $f['state'] ? ($f['state']['queries'] ?? 0) : 0;
                        $output->writeln(sprintf('  - %-35s %10s  [%s - %d queries]', $f['name'], $f['size_formatted'], strtoupper($status), $queries));
                    }
                }

                $output->writeln('');
                $output->writeln('<comment>Available Chunk Groups:</comment>');
                $chunks = $this->getChunkGroups();
                if (empty($chunks))
                {
                    $output->writeln('  (No chunk groups found)');
                }
                else
                {
                    foreach ($chunks as $c)
                    {
                        $output->writeln(sprintf('  - %-35s %2d chunks (%s)%s', $c['base_name'], count($c['chunks']), $this->formatBytes($c['total_size']), $c['is_encrypted'] ? ' [ENCRYPTED]' : ''));
                    }
                }

                $output->writeln('');
                $output->writeln('Usage:');
                $output->writeln('  php cmd.php xf-addon:large-sql-importer --action=test-db');
                $output->writeln('  php cmd.php xf-addon:large-sql-importer --action=import --file=<name.sql>');
                $output->writeln('  php cmd.php xf-addon:large-sql-importer --action=merge --base-name=<base>');
                return 0;
        }
    }

    public function cliImportFile($output, string $fileName): int
    {
        $fileName = basename($fileName);
        $filePath = $this->uploadDir . '/' . $fileName;

        if (!is_file($filePath))
        {
            $output->writeln("<error>File '$fileName' not found in {$this->uploadDir}</error>");
            return 1;
        }

        if (!$this->db)
        {
            $output->writeln("<error>Database not connected: " . ($this->dbError ?: 'Unknown error') . "</error>");
            return 1;
        }

        $isGz = str_ends_with(strtolower($fileName), '.gz');
        $fileSize = filesize($filePath);

        $output->writeln("<info>Starting SQL import for: $fileName (" . $this->formatBytes($fileSize) . ")</info>");

        $fp = $isGz ? @gzopen($filePath, 'rb') : @fopen($filePath, 'rb');
        if (!$fp)
        {
            $output->writeln("<error>Could not open file '$fileName' for streaming.</error>");
            return 1;
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS=0");
        $this->db->query("SET UNIQUE_CHECKS=0");
        $this->db->query("SET AUTOCOMMIT=1");
        $this->db->query("SET SQL_MODE=''");

        $startTime = microtime(true);
        $lastPrint = 0.0;
        $queriesExecuted = 0;
        $buffer = '';
        $delimiter = ';';
        $inString = false;
        $stringChar = '';
        $isEscaped = false;
        $errorsCount = 0;

        while (true)
        {
            $line = $isGz ? gzgets($fp, 4194304) : fgets($fp, 4194304);
            if ($line === false)
            {
                break;
            }

            $trimmedLine = trim($line);
            if (!$inString)
            {
                if ($trimmedLine === '' || str_starts_with($trimmedLine, '--') || str_starts_with($trimmedLine, '#'))
                {
                    continue;
                }

                if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmedLine, $m))
                {
                    $delimiter = trim($m[1]);
                    continue;
                }
            }

            $len = strlen($line);
            for ($i = 0; $i < $len; $i++)
            {
                $ch = $line[$i];

                if ($isEscaped)
                {
                    $isEscaped = false;
                    $buffer .= $ch;
                    continue;
                }

                if ($ch === '\\')
                {
                    $isEscaped = true;
                    $buffer .= $ch;
                    continue;
                }

                if ($inString)
                {
                    if ($ch === $stringChar)
                    {
                        $inString = false;
                    }
                    $buffer .= $ch;
                    continue;
                }

                if ($ch === '\'' || $ch === '"' || $ch === '`')
                {
                    $inString = true;
                    $stringChar = $ch;
                    $buffer .= $ch;
                    continue;
                }

                $delimLen = strlen($delimiter);
                if (substr($line, $i, $delimLen) === $delimiter)
                {
                    $query = trim($buffer);
                    $buffer = '';
                    $i += $delimLen - 1;

                    if ($query !== '')
                    {
                        if (!$this->db->query($query))
                        {
                            $err = $this->db->error;
                            if (
                                !str_contains($err, 'already exists') 
                                && !str_contains($err, 'Unknown table')
                                && !str_contains($err, "Variable '")
                            )
                            {
                                $errorsCount++;
                            }
                        }
                        $queriesExecuted++;
                    }
                    continue;
                }

                $buffer .= $ch;
            }

            $now = microtime(true);
            if ($now - $lastPrint >= 0.5)
            {
                $lastPrint = $now;
                $offset = $isGz ? gztell($fp) : ftell($fp);
                $percent = ($fileSize > 0) ? min(100.0, ($offset / $fileSize) * 100.0) : 0;
                $elapsed = max(0.1, $now - $startTime);
                $speed = $offset / $elapsed;
                $output->write(sprintf("\r  Progress: %5.1f%% | %10s/s | Queries: %d | Errors: %d", $percent, $this->formatBytes($speed), $queriesExecuted, $errorsCount));
            }
        }

        if ($isGz)
        {
            gzclose($fp);
        }
        else
        {
            fclose($fp);
        }

        if (trim($buffer) !== '')
        {
            $this->db->query(trim($buffer));
            $queriesExecuted++;
        }

        $totalElapsed = max(0.1, microtime(true) - $startTime);
        $avgSpeed = $fileSize / $totalElapsed;

        $state = [
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'offset' => $fileSize,
            'queries' => $queriesExecuted,
            'status' => 'completed',
            'start_time' => (int)$startTime,
            'last_update' => time(),
            'errors' => []
        ];
        $this->saveState($fileName, $state);

        $output->writeln('');
        $output->writeln("<info>Import finished successfully!</info>");
        $output->writeln("Total Queries: $queriesExecuted");
        $output->writeln("Duration: " . $this->formatDuration((int)$totalElapsed));
        $output->writeln("Average Speed: " . $this->formatBytes($avgSpeed) . "/s");
        return 0;
    }

    public function cliMergeChunks($output, string $baseName, string $password = '', bool $deleteChunks = false): int
    {
        $output->writeln("<info>Merging chunk files for: $baseName...</info>");
        $res = $this->doMergeChunks($baseName, $password, $deleteChunks);
        if (!$res['success'])
        {
            $output->writeln("<error>Merge failed: " . $res['error'] . "</error>");
            return 1;
        }

        $output->writeln("<info>Chunks merged successfully!</info>");
        $output->writeln("Output File: " . $res['output_file']);
        $output->writeln("Output Size: " . $res['total_size_formatted']);
        return 0;
    }

    protected function jsonOut(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    protected function ajaxTestDb(): void
    {
        if ($this->dbError)
        {
            $this->jsonOut(['success' => false, 'error' => $this->dbError]);
        }

        $res = $this->db->query("SELECT count(*) AS cnt FROM information_schema.tables WHERE table_schema = '" . $this->db->real_escape_string($this->dbConfig['dbname']) . "'");
        $count = 0;
        if ($res && ($row = $res->fetch_assoc()))
        {
            $count = (int)$row['cnt'];
        }

        $this->jsonOut([
            'success' => true,
            'tables' => $count,
            'message' => "Successfully connected to MySQL database '{$this->dbConfig['dbname']}' ($count existing tables)."
        ]);
    }

    protected function ajaxUploadChunk(): void
    {
        $fileName = basename($_POST['file_name'] ?? '');
        $chunkIndex = (int)($_POST['chunk_index'] ?? 0);
        $totalChunks = (int)($_POST['total_chunks'] ?? 1);

        if ($fileName === '' || !isset($_FILES['chunk']))
        {
            $this->jsonOut(['success' => false, 'error' => 'Invalid upload parameters.'], 400);
        }

        $targetPath = $this->uploadDir . '/' . $fileName;
        $tempPath = $targetPath . '.uploading';

        $mode = ($chunkIndex === 0) ? 'wb' : 'ab';
        $out = @fopen($tempPath, $mode);
        if (!$out)
        {
            $this->jsonOut(['success' => false, 'error' => 'Failed to open destination file for writing.'], 500);
        }

        $in = @fopen($_FILES['chunk']['tmp_name'], 'rb');
        if ($in)
        {
            while (!feof($in))
            {
                $buf = fread($in, 65536);
                if ($buf !== false)
                {
                    fwrite($out, $buf);
                }
            }
            fclose($in);
        }
        fclose($out);

        if ($chunkIndex + 1 >= $totalChunks)
        {
            @rename($tempPath, $targetPath);
            $this->jsonOut([
                'success' => true,
                'completed' => true,
                'file_name' => $fileName,
                'size' => filesize($targetPath)
            ]);
        }
        else
        {
            $this->jsonOut([
                'success' => true,
                'completed' => false,
                'chunk_index' => $chunkIndex
            ]);
        }
    }

    protected function ajaxListFiles(): void
    {
        $files = $this->getSqlFiles();
        $this->jsonOut(['success' => true, 'files' => $files]);
    }

    protected function getSqlFiles(): array
    {
        $items = [];
        $candidates = array_unique(array_merge(
            (array)glob($this->uploadDir . '/*.sql'),
            (array)glob($this->uploadDir . '/*.sql.gz'),
            (array)glob($this->uploadDir . '/*.gz'),
            (array)glob($this->uploadDir . '/*.zip')
        ));

        foreach ($candidates as $filePath)
        {
            if (!is_file($filePath)) continue;
            $name = basename($filePath);
            $size = filesize($filePath);
            $mtime = filemtime($filePath);

            $state = $this->getState($name);

            $items[] = [
                'name' => $name,
                'path' => $filePath,
                'size' => $size,
                'size_formatted' => $this->formatBytes($size),
                'modified' => date('Y-m-d H:i', $mtime),
                'state' => $state
            ];
        }

        usort($items, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $items;
    }

    protected function getState(string $fileName): ?array
    {
        $stateFile = $this->stateDir . '/' . md5($fileName) . '.json';
        if (is_file($stateFile))
        {
            $data = @json_decode(file_get_contents($stateFile), true);
            if (is_array($data))
            {
                return $data;
            }
        }
        return null;
    }

    protected function saveState(string $fileName, array $state): void
    {
        $stateFile = $this->stateDir . '/' . md5($fileName) . '.json';
        @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    protected function ajaxDeleteFile(): void
    {
        $fileName = basename($_POST['file_name'] ?? '');
        $filePath = $this->uploadDir . '/' . $fileName;

        if (is_file($filePath))
        {
            @unlink($filePath);
        }

        $stateFile = $this->stateDir . '/' . md5($fileName) . '.json';
        if (is_file($stateFile))
        {
            @unlink($stateFile);
        }

        $this->jsonOut(['success' => true]);
    }

    protected function ajaxResetState(): void
    {
        $fileName = basename($_POST['file_name'] ?? '');
        $stateFile = $this->stateDir . '/' . md5($fileName) . '.json';
        if (is_file($stateFile))
        {
            @unlink($stateFile);
        }
        $this->jsonOut(['success' => true]);
    }

    protected function ajaxImportBatch(): void
    {
        @set_time_limit(60);
        @ini_set('memory_limit', '512M');

        $fileName = basename($_POST['file_name'] ?? '');
        $filePath = $this->uploadDir . '/' . $fileName;

        if (!is_file($filePath))
        {
            $this->jsonOut(['success' => false, 'error' => "File '$fileName' not found."], 404);
        }

        if (!$this->db)
        {
            $this->jsonOut(['success' => false, 'error' => $this->dbError ?: 'Database not connected.'], 500);
        }

        $isGz = (str_ends_with(strtolower($fileName), '.gz'));
        $fileSize = filesize($filePath);

        $state = $this->getState($fileName) ?: [
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'offset' => 0,
            'queries' => 0,
            'status' => 'running',
            'start_time' => time(),
            'errors' => [],
            'buffer' => '',
            'delimiter' => ';',
            'in_string' => false,
            'string_char' => '',
            'is_escaped' => false
        ];

        $offset = (int)($state['offset'] ?? 0);
        $queriesExecuted = (int)($state['queries'] ?? 0);

        $fp = $isGz ? @gzopen($filePath, 'rb') : @fopen($filePath, 'rb');
        if (!$fp)
        {
            $this->jsonOut(['success' => false, 'error' => 'Could not open file for streaming.'], 500);
        }

        if ($offset > 0)
        {
            if ($isGz)
            {
                gzseek($fp, $offset);
            }
            else
            {
                fseek($fp, $offset);
            }
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS=0");
        $this->db->query("SET UNIQUE_CHECKS=0");
        $this->db->query("SET AUTOCOMMIT=1");
        $this->db->query("SET SQL_MODE=''");

        $batchStartTime = microtime(true);
        $maxDuration = 4.0;
        $buffer = (string)($state['buffer'] ?? '');
        $delimiter = (string)($state['delimiter'] ?? ';');
        $inString = (bool)($state['in_string'] ?? false);
        $stringChar = (string)($state['string_char'] ?? '');
        $isEscaped = (bool)($state['is_escaped'] ?? false);
        $lastStatement = '';

        while (true)
        {
            $elapsed = microtime(true) - $batchStartTime;
            if ($elapsed >= $maxDuration && trim($buffer) === '' && !$inString)
            {
                break;
            }
            if ($elapsed >= 10.0)
            {
                break;
            }

            $line = $isGz ? gzgets($fp, 4194304) : fgets($fp, 4194304);
            if ($line === false)
            {
                break;
            }

            $trimmedLine = trim($line);
            if (!$inString)
            {
                if ($trimmedLine === '' || str_starts_with($trimmedLine, '--') || str_starts_with($trimmedLine, '#'))
                {
                    continue;
                }

                if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmedLine, $m))
                {
                    $delimiter = trim($m[1]);
                    continue;
                }
            }

            $len = strlen($line);
            for ($i = 0; $i < $len; $i++)
            {
                $ch = $line[$i];

                if ($isEscaped)
                {
                    $isEscaped = false;
                    $buffer .= $ch;
                    continue;
                }

                if ($ch === '\\')
                {
                    $isEscaped = true;
                    $buffer .= $ch;
                    continue;
                }

                if ($inString)
                {
                    if ($ch === $stringChar)
                    {
                        $inString = false;
                    }
                    $buffer .= $ch;
                    continue;
                }

                if ($ch === '\'' || $ch === '"' || $ch === '`')
                {
                    $inString = true;
                    $stringChar = $ch;
                    $buffer .= $ch;
                    continue;
                }

                $delimLen = strlen($delimiter);
                if (substr($line, $i, $delimLen) === $delimiter)
                {
                    $query = trim($buffer);
                    $buffer = '';
                    $i += $delimLen - 1;

                    if ($query !== '')
                    {
                        $lastStatement = substr($query, 0, 100);
                        if (!$this->db->query($query))
                        {
                            $err = $this->db->error;
                            if (
                                !str_contains($err, 'already exists') 
                                && !str_contains($err, 'Unknown table')
                                && !str_contains($err, "Variable '")
                            )
                            {
                                $state['errors'][] = [
                                    'query' => substr($query, 0, 120),
                                    'error' => $err
                                ];
                            }
                        }
                        $queriesExecuted++;
                    }
                    continue;
                }

                $buffer .= $ch;
            }
        }

        $newOffset = $isGz ? gztell($fp) : ftell($fp);
        $isEof = $isGz ? gzeof($fp) : feof($fp);
        if ($fileSize > 0 && $newOffset >= $fileSize)
        {
            $isEof = true;
        }

        if ($isGz)
        {
            gzclose($fp);
        }
        else
        {
            fclose($fp);
        }

        if ($isEof)
        {
            if (trim($buffer) !== '')
            {
                $this->db->query(trim($buffer));
                $queriesExecuted++;
            }
            $state['status'] = 'completed';
            $state['offset'] = $fileSize;
            $newOffset = $fileSize;
            $state['buffer'] = '';
        }
        else
        {
            $state['status'] = 'running';
            $state['offset'] = $newOffset;
            $state['buffer'] = $buffer;
            $state['delimiter'] = $delimiter;
            $state['in_string'] = $inString;
            $state['string_char'] = $stringChar;
            $state['is_escaped'] = $isEscaped;
        }

        $state['queries'] = $queriesExecuted;
        $state['last_update'] = time();
        $this->saveState($fileName, $state);

        $percent = ($fileSize > 0) ? min(100, round(($newOffset / $fileSize) * 100, 1)) : 0;
        $elapsed = max(1, time() - (int)$state['start_time']);
        $bytesPerSec = $newOffset / $elapsed;
        $remainingBytes = max(0, $fileSize - $newOffset);
        $etaSec = ($bytesPerSec > 0) ? ceil($remainingBytes / $bytesPerSec) : 0;

        $this->jsonOut([
            'success' => true,
            'completed' => ($state['status'] === 'completed'),
            'file_name' => $fileName,
            'offset' => $newOffset,
            'total_size' => $fileSize,
            'percent' => $percent,
            'queries' => $queriesExecuted,
            'speed' => $this->formatBytes($bytesPerSec) . '/s',
            'eta' => $this->formatDuration((int)$etaSec),
            'last_statement' => $lastStatement,
            'errors_count' => count($state['errors'] ?? [])
        ]);
    }

    public function getChunkGroups(): array
    {
        $allFiles = (array)glob($this->uploadDir . '/*');
        $groups = [];

        foreach ($allFiles as $file)
        {
            if (!is_file($file)) continue;
            $name = basename($file);

            if (preg_match('/^(.*?)(?:\.(?:part|chunk)[_\.]?([0-9a-z]+)|\.([0-9]{3,4}))(?:\.enc)?$/i', $name, $matches))
            {
                $base = $matches[1];
                $partKey = !empty($matches[2]) ? $matches[2] : $matches[3];
                $isEncrypted = str_ends_with(strtolower($name), '.enc');

                if (!isset($groups[$base]))
                {
                    $groups[$base] = [
                        'base_name' => $base,
                        'chunks' => [],
                        'total_size' => 0,
                        'is_encrypted' => false
                    ];
                }

                $size = filesize($file);
                $groups[$base]['chunks'][] = [
                    'name' => $name,
                    'path' => $file,
                    'part' => $partKey,
                    'size' => $size,
                    'is_encrypted' => $isEncrypted
                ];
                $groups[$base]['total_size'] += $size;
                if ($isEncrypted)
                {
                    $groups[$base]['is_encrypted'] = true;
                }
            }
        }

        $result = [];
        foreach ($groups as $base => $info)
        {
            usort($info['chunks'], fn($a, $b) => strnatcasecmp((string)$a['part'], (string)$b['part']));

            $partsFound = array_column($info['chunks'], 'part');
            $missing = [];
            $allNumeric = true;
            foreach ($partsFound as $p)
            {
                if (!ctype_digit((string)$p))
                {
                    $allNumeric = false;
                    break;
                }
            }

            if ($allNumeric && !empty($partsFound))
            {
                $numericParts = array_map('intval', $partsFound);
                $min = min($numericParts);
                $max = max($numericParts);
                for ($p = $min; $p <= $max; $p++)
                {
                    if (!in_array($p, $numericParts, true))
                    {
                        $missing[] = (string)$p;
                    }
                }
            }

            // Check if first chunk begins with Salted__ (OpenSSL CLI encryption)
            $firstChunkPath = $info['chunks'][0]['path'] ?? null;
            if ($firstChunkPath && file_exists($firstChunkPath))
            {
                $fp = @fopen($firstChunkPath, 'rb');
                if ($fp)
                {
                    $magic = fread($fp, 8);
                    fclose($fp);
                    if ($magic === 'Salted__')
                    {
                        $info['is_encrypted'] = true;
                    }
                }
            }

            $outName = $base;
            if (!str_ends_with(strtolower($outName), '.sql') && !str_ends_with(strtolower($outName), '.sql.gz'))
            {
                $outName .= '.sql';
            }

            $result[] = [
                'base_name' => $base,
                'output_name' => $outName,
                'chunks_count' => count($info['chunks']),
                'total_size' => $info['total_size'],
                'total_size_formatted' => $this->formatBytes($info['total_size']),
                'is_encrypted' => $info['is_encrypted'],
                'missing_parts' => $missing,
                'is_valid_sequence' => (empty($missing) && count($info['chunks']) > 1),
                'chunks' => $info['chunks']
            ];
        }

        return $result;
    }

    protected function ajaxListChunks(): void
    {
        $this->jsonOut(['success' => true, 'groups' => $this->getChunkGroups()]);
    }

    protected function ajaxMergeChunks(): void
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $baseName = trim((string)($_POST['base_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $deleteChunks = !empty($_POST['delete_chunks']);

        if ($baseName === '')
        {
            $this->jsonOut(['success' => false, 'error' => 'Missing chunk base name.'], 400);
        }

        $allFiles = (array)glob($this->uploadDir . '/*');
        $parts = [];
        $isEncryptedName = false;

        foreach ($allFiles as $f)
        {
            if (!is_file($f)) continue;
            $n = basename($f);
            if (preg_match('/^' . preg_quote($baseName, '/') . '(?:\.(?:part|chunk)[_\.]?([0-9a-z]+)|\.([0-9]{3,4}))(?:\.enc)?$/i', $n, $m))
            {
                $partKey = !empty($m[1]) ? $m[1] : $m[2];
                $parts[] = ['path' => $f, 'part_key' => $partKey, 'filename' => $n];
                if (str_ends_with(strtolower($n), '.enc'))
                {
                    $isEncryptedName = true;
                }
            }
        }

        if (empty($parts))
        {
            $this->jsonOut(['success' => false, 'error' => 'No chunks found for base name.'], 404);
        }

        usort($parts, fn($a, $b) => strnatcasecmp((string)$a['part_key'], (string)$b['part_key']));

        $outName = $baseName;
        if (!str_ends_with(strtolower($outName), '.sql') && !str_ends_with(strtolower($outName), '.sql.gz'))
        {
            $outName .= '.sql';
        }
        $outPath = $this->uploadDir . '/' . $outName;

        // Check if encryption is detected or key is supplied
        $firstChunkPath = $parts[0]['path'];
        $fpFirst = @fopen($firstChunkPath, 'rb');
        $header16 = $fpFirst ? fread($fpFirst, 16) : '';
        if ($fpFirst) fclose($fpFirst);

        $hasSaltedMagic = str_starts_with($header16, 'Salted__');
        if (($isEncryptedName || $hasSaltedMagic) && $password === '')
        {
            $this->jsonOut(['success' => false, 'error' => 'This backup archive is encrypted with AES-256 (.enc). Please enter your decryption key to merge it.'], 400);
        }

        $mergedBytes = 0;

        if ($password !== '')
        {
            // Case 1: OpenSSL CLI format (TelegramBackup CLI split)
            if ($hasSaltedMagic)
            {
                $opensslBin = null;
                foreach (['/opt/homebrew/bin/openssl', '/usr/local/bin/openssl', '/usr/bin/openssl'] as $cand)
                {
                    if (@file_exists($cand) && @is_executable($cand))
                    {
                        $opensslBin = $cand;
                        break;
                    }
                }
                if (!$opensslBin)
                {
                    $which = trim((string)@exec('which openssl'));
                    if ($which !== '')
                    {
                        $opensslBin = $which;
                    }
                }

                $usedCli = false;
                if ($opensslBin)
                {
                    $cmd = sprintf('%s enc -d -aes-256-cbc -salt -pbkdf2 -pass pass:%s', escapeshellarg($opensslBin), escapeshellarg($password));
                    $descriptors = [
                        0 => ['pipe', 'r'],
                        1 => ['file', $outPath, 'w'],
                        2 => ['pipe', 'w']
                    ];
                    $process = @proc_open($cmd, $descriptors, $pipes);
                    if (is_resource($process))
                    {
                        foreach ($parts as $p)
                        {
                            $in = @fopen($p['path'], 'rb');
                            if ($in)
                            {
                                while (!feof($in))
                                {
                                    $buf = fread($in, 2097152);
                                    if ($buf === false || $buf === '') break;
                                    fwrite($pipes[0], $buf);
                                }
                                fclose($in);
                            }
                        }
                        fclose($pipes[0]);
                        $stderr = stream_get_contents($pipes[2]);
                        fclose($pipes[2]);
                        $returnCode = proc_close($process);

                        if ($returnCode === 0 && file_exists($outPath) && filesize($outPath) > 0)
                        {
                            $usedCli = true;
                            $mergedBytes = (int)filesize($outPath);
                        }
                        else
                        {
                            @unlink($outPath);
                            $errMsg = trim($stderr);
                            if (stripos($errMsg, 'bad decrypt') !== false || stripos($errMsg, 'error') !== false)
                            {
                                $this->jsonOut(['success' => false, 'error' => 'Decryption failed: Invalid encryption key or corrupted archive.'], 400);
                            }
                        }
                    }
                }

                if (!$usedCli)
                {
                    // Pure PHP streaming PBKDF2 decryption
                    $out = @fopen($outPath, 'wb');
                    if (!$out)
                    {
                        $this->jsonOut(['success' => false, 'error' => "Cannot create output file '$outName'."], 500);
                    }

                    $fp0 = @fopen($parts[0]['path'], 'rb');
                    fseek($fp0, 8);
                    $salt = fread($fp0, 8);
                    fclose($fp0);

                    $keyIv = hash_pbkdf2('sha256', $password, $salt, 10000, 48, true);
                    $key = substr($keyIv, 0, 32);
                    $iv = substr($keyIv, 32, 16);

                    // Assemble all ciphertext bytes
                    $allCipher = '';
                    $isFirst = true;
                    foreach ($parts as $p)
                    {
                        $chunkData = file_get_contents($p['path']);
                        if ($isFirst)
                        {
                            $allCipher .= substr($chunkData, 16);
                            $isFirst = false;
                        }
                        else
                        {
                            $allCipher .= $chunkData;
                        }
                    }

                    $plain = openssl_decrypt($allCipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                    if ($plain === false)
                    {
                        fclose($out);
                        @unlink($outPath);
                        $this->jsonOut(['success' => false, 'error' => 'Decryption failed. Please verify your encryption key.'], 400);
                    }

                    fwrite($out, $plain);
                    $mergedBytes = strlen($plain);
                    fclose($out);
                }
            }
            else
            {
                // Case 2: PHP Chunker format (each chunk has 16-byte prepended IV and was individually encrypted)
                $key = hash('sha256', $password, true);
                $out = @fopen($outPath, 'wb');
                if (!$out)
                {
                    $this->jsonOut(['success' => false, 'error' => "Cannot create output file '$outName'."], 500);
                }

                foreach ($parts as $p)
                {
                    $chunkData = @file_get_contents($p['path']);
                    if ($chunkData === false || strlen($chunkData) < 17)
                    {
                        fclose($out);
                        @unlink($outPath);
                        $this->jsonOut(['success' => false, 'error' => "Corrupted chunk '" . $p['filename'] . "'."], 400);
                    }

                    $iv = substr($chunkData, 0, 16);
                    $ciphertext = substr($chunkData, 16);
                    $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

                    if ($plaintext === false)
                    {
                        fclose($out);
                        @unlink($outPath);
                        $this->jsonOut(['success' => false, 'error' => 'Decryption failed. Please verify your encryption key.'], 400);
                    }

                    fwrite($out, $plaintext);
                    $mergedBytes += strlen($plaintext);
                }
                fclose($out);
            }
        }
        else
        {
            // Case 3: Plaintext concatenation
            $out = @fopen($outPath, 'wb');
            if (!$out)
            {
                $this->jsonOut(['success' => false, 'error' => "Cannot create output file '$outName'."], 500);
            }

            foreach ($parts as $p)
            {
                $chunkPath = $p['path'];
                $in = @fopen($chunkPath, 'rb');
                if (!$in)
                {
                    fclose($out);
                    @unlink($outPath);
                    $this->jsonOut(['success' => false, 'error' => "Cannot read chunk file '" . $p['filename'] . "'."], 500);
                }

                while (!feof($in))
                {
                    $buf = fread($in, 2097152);
                    if ($buf === false || $buf === '') break;
                    fwrite($out, $buf);
                    $mergedBytes += strlen($buf);
                }
                fclose($in);
            }
            fclose($out);
        }

        if ($deleteChunks)
        {
            foreach ($parts as $p)
            {
                @unlink($p['path']);
            }
        }

        $this->jsonOut([
            'success' => true,
            'output_file' => $outName,
            'total_size' => $mergedBytes,
            'total_size_formatted' => $this->formatBytes($mergedBytes)
        ]);
    }

    protected function ajaxDeleteChunks(): void
    {
        $baseName = trim((string)($_POST['base_name'] ?? ''));
        if ($baseName === '')
        {
            $this->jsonOut(['success' => false, 'error' => 'Missing base name.'], 400);
        }

        $allFiles = (array)glob($this->uploadDir . '/*');
        $deleted = 0;
        foreach ($allFiles as $f)
        {
            if (!is_file($f)) continue;
            $n = basename($f);
            if (preg_match('/^' . preg_quote($baseName, '/') . '(?:\.(?:part|chunk)[_\.]?\d+|\.\d{3,4})(?:\.enc)?$/i', $n))
            {
                if (@unlink($f))
                {
                    $deleted++;
                }
            }
        }

        $this->jsonOut(['success' => true, 'deleted_count' => $deleted]);
    }

    protected function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1)
        {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $bytes >= 100 || $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds}s";
        $m = floor($seconds / 60);
        $s = $seconds % 60;
        if ($m < 60) return "{$m}m {$s}s";
        $h = floor($m / 60);
        $m = $m % 60;
        return "{$h}h {$m}m";
    }

    public function renderUi(): void
    {
        $tab = $_GET['tab'] ?? 'import';
        if (!in_array($tab, ['import', 'merge', 'config'], true))
        {
            $tab = 'import';
        }

        $isWritable = is_writable($this->uploadDir);
        $cssUrl = ($this->context === 'install') ? 'install.css' : '../install/install.css';
        $logoUrl = ($this->context === 'install') ? '../styles/default/xenforo/xenforo-logo.svg' : '../../styles/default/xenforo/xenforo-logo.svg';
        $version = class_exists('\XF', false) && !empty(\XF::$version) ? \XF::$version : '2.3.13';

        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html id="XF" dir="ltr" class="has-no-js" data-app="install">
<head>
	<meta charset="utf-8" />
	<meta http-equiv="X-UA-Compatible" content="IE=Edge" />
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Large SQL Importer | XenForo</title>

	<meta name="robots" content="noindex, nofollow" />

	<link rel="stylesheet" href="<?= htmlspecialchars($cssUrl) ?>" />
</head>
<body>
<div class="p-pageWrapper">
	<header class="p-header">
		<div class="p-header-logo p-header-logo--image">
			<img src="<?= htmlspecialchars($logoUrl) ?>" width="100" height="36" alt="XenForo Ltd." />
		</div>
	</header>
	<div class="p-body">
		<div class="p-main">
			<main class="p-main-inner">

				<noscript><div class="blockMessage blockMessage--important blockMessage--iconic u-noJsOnly">JavaScript is disabled. For a better experience, please enable JavaScript in your browser before proceeding.</div></noscript>

				<div class="p-title">
					<h1 class="p-title-value">Large SQL Importer &amp; Chunk Merger</h1>
				</div>

				<div class="p-content" id="content">

					<div class="block">
						<div class="block-container">
							<!-- 3-TAB HEADER -->
							<div class="block-tabHeader tabs">
								<a href="?tab=import" class="tabs-tab <?= $tab === 'import' ? 'is-active' : '' ?>">SQL Importer</a>
								<a href="?tab=merge" class="tabs-tab <?= $tab === 'merge' ? 'is-active' : '' ?>">Chunk Merger &amp; Decryptor</a>
								<a href="?tab=config" class="tabs-tab <?= $tab === 'config' ? 'is-active' : '' ?>">Database Configuration</a>
							</div>

							<?php if ($tab === 'config'): ?>
								<!-- TAB: DATABASE CONFIGURATION -->
								<div class="block-body">
									<div class="formInfoRow">
										Configure your MySQL database connection credentials. Values below are automatically pre-loaded from <code>src/config.php</code>.
									</div>
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label" for="ctrl_db_host">MySQL server</label></dt>
										<dd>
											<input type="text" id="ctrl_db_host" class="input" value="<?= htmlspecialchars($this->dbConfig['host']) ?>" />
											<div class="formRow-explain">Hostname or IP address. Default: localhost</div>
										</dd>
									</dl>
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label" for="ctrl_db_port">MySQL port</label></dt>
										<dd>
											<input type="text" id="ctrl_db_port" class="input" value="<?= htmlspecialchars((string)$this->dbConfig['port']) ?>" />
											<div class="formRow-explain">Default port is 3306.</div>
										</dd>
									</dl>
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label" for="ctrl_db_username">MySQL user name</label></dt>
										<dd>
											<input type="text" id="ctrl_db_username" class="input" value="<?= htmlspecialchars($this->dbConfig['username']) ?>" />
										</dd>
									</dl>
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label" for="ctrl_db_password">MySQL password</label></dt>
										<dd>
											<input type="password" id="ctrl_db_password" class="input" value="<?= htmlspecialchars($this->dbConfig['password']) ?>" />
										</dd>
									</dl>
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label" for="ctrl_db_dbname">MySQL database name</label></dt>
										<dd>
											<input type="text" id="ctrl_db_dbname" class="input" value="<?= htmlspecialchars($this->dbConfig['dbname']) ?>" />
											<div class="formRow-explain">The target MySQL database must already exist.</div>
										</dd>
									</dl>
								</div>
								<dl class="formRow formSubmitRow">
									<dt></dt>
									<dd>
										<div class="formSubmitRow-main">
											<div class="formSubmitRow-bar"></div>
											<div class="formSubmitRow-controls">
												<button type="button" class="button button--primary" onclick="applyDbConfig()">
													<span class="button-text">Test &amp; Update Connection</span>
												</button>
											</div>
										</div>
									</dd>
								</dl>

							<?php elseif ($tab === 'import'): ?>
								<!-- TAB: SQL IMPORTER (ADAPTED TO EXACT SAME FORMINFOROW & FORMROW LAYOUT) -->
								<div class="block-body">
									<div class="formInfoRow">
										Import large SQL dumps into your MySQL database using streaming low-memory execution. Large files are processed statement by statement with automatic state checkpoints.
									</div>

									<!-- ROW 1: SFTP UPLOAD DIRECTORY -->
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label">Upload directory</label></dt>
										<dd>
											<div class="inputGroup inputGroup--joined">
												<input type="text" id="upload-dir-path" class="input" readonly value="<?= htmlspecialchars($this->uploadDir) ?>" />
												<button type="button" class="button" onclick="copyUploadPath()">Copy Path</button>
											</div>
											<div class="formRow-explain" style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px;">
												<span>Direct FTP/SFTP target folder for <code>.sql</code>, <code>.sql.gz</code>, or <code>.gz</code> archives.</span>
												<span><?= $isWritable ? '<span class="label label--green">Writable</span>' : '<span class="label label--red">Not Writable</span>' ?></span>
											</div>
										</dd>
									</dl>

									<!-- ROW 2: BROWSER CHUNKED UPLOADER -->
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label">Upload from browser</label></dt>
										<dd>
											<input type="file" id="sql-file-input" style="display: none;" accept=".sql,.gz,.zip" onchange="handleFileSelect(this.files)" />
											<button type="button" class="button" onclick="document.getElementById('sql-file-input').click()">Choose File...</button>
											<div id="upload-progress-container" style="display: none; margin-top: 10px;">
												<meter id="upload-meter" class="meterBar" min="0" max="100" value="0"></meter>
												<div id="upload-status-text" class="meterBarLabel" style="margin-top: 4px; font-weight: 500;">Uploading: 0%</div>
											</div>
											<div class="formRow-explain">Large dumps are automatically chunked in memory-safe blocks to bypass server upload size limits.</div>
										</dd>
									</dl>

									<!-- ROW 3: DETECTED SQL DUMPS LIST -->
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label">Available dump files</label></dt>
										<dd>
											<div id="files-list-container" class="block-container" style="overflow: hidden;">
												<div class="block-row u-muted" style="text-align: center; padding: 25px;">
													Scanning detected dump files...
												</div>
											</div>
											<div class="formRow-explain">Select any detected database dump to begin or resume streaming execution.</div>
										</dd>
									</dl>
								</div>
								<dl class="formRow formSubmitRow">
									<dt></dt>
									<dd>
										<div class="formSubmitRow-main">
											<div class="formSubmitRow-bar"></div>
											<div class="formSubmitRow-controls">
												<button type="button" class="button button--primary" onclick="loadFilesList()">
													<span class="button-text">Refresh File List</span>
												</button>
											</div>
										</div>
									</dd>
								</dl>

							<?php else: ?>
								<!-- TAB: CHUNK MERGER (ADAPTED TO EXACT SAME FORMINFOROW & FORMROW LAYOUT) -->
								<div class="block-body">
									<div class="formInfoRow">
										Reassemble and decrypt multi-part backup chunks (<code>.part01</code>, <code>.001</code>, <code>.enc</code>) into a single consolidated SQL archive ready for database import.
									</div>

									<!-- ROW 1: SFTP CHUNK DIRECTORY -->
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label">Chunk directory</label></dt>
										<dd>
											<div class="inputGroup inputGroup--joined">
												<input type="text" class="input" readonly value="<?= htmlspecialchars($this->uploadDir) ?>" />
												<button type="button" class="button" onclick="copyUploadPath()">Copy Path</button>
											</div>
											<div class="formRow-explain" style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px;">
												<span>Target folder for split backup chunks transferred via SFTP/FTP.</span>
												<span><?= $isWritable ? '<span class="label label--green">Writable</span>' : '<span class="label label--red">Not Writable</span>' ?></span>
											</div>
										</dd>
									</dl>

									<!-- ROW 2: DECRYPTION KEY (OPTIONAL / TELEGRAM BACKUP) -->
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label" for="ctrl_tab_encryption_key">Decryption key</label></dt>
										<dd>
											<input type="password" id="ctrl_tab_encryption_key" class="input" placeholder="Enter AES-256 decryption key if backup chunks are encrypted..." autocomplete="off" oninput="syncTabEncryptionKey(this.value)" />
											<div class="formRow-explain">
												If your multi-part backup was created with client-side AES-256 encryption via <strong>Olakunlevpn/TelegramBackup</strong>, enter your secret encryption key here. It will be used automatically to decrypt chunks during reassembly. Leave blank if chunks are unencrypted.
											</div>
										</dd>
									</dl>

									<!-- ROW 3: DETECTED MULTI-PART ARCHIVES -->
									<dl class="formRow formRow--input">
										<dt><label class="formRow-label">Detected archives</label></dt>
										<dd>
											<div id="chunk-groups-container" class="block-container" style="overflow: hidden;">
												<div class="block-row u-muted" style="text-align: center; padding: 25px;">
													Scanning for multi-part archives...
												</div>
											</div>
											<div class="formRow-explain">Sequential parts are verified and missing segments identified before streaming reassembly.</div>
										</dd>
									</dl>
								</div>
								<dl class="formRow formSubmitRow">
									<dt></dt>
									<dd>
										<div class="formSubmitRow-main">
											<div class="formSubmitRow-bar"></div>
											<div class="formSubmitRow-controls">
												<button type="button" class="button button--primary" onclick="loadChunkGroups()">
													<span class="button-text">Refresh Chunks</span>
												</button>
											</div>
										</div>
									</dd>
								</dl>

							<?php endif; ?>

						</div>
					</div>

					<!-- PROGRESS PANEL -->
					<div id="import-progress-panel" class="block" style="display: none; margin-top: 20px;">
						<div class="block-container">
							<h2 class="block-header">
								<span>Executing Import: <span id="current-import-filename"></span></span>
							</h2>
							<div class="block-body">
								<div class="block-row">
									<div style="font-size: 14px; font-weight: 500; margin-bottom: 6px;">
										Processing SQL stream...
									</div>
									<meter id="import-meter" class="meterBar" min="0" max="100" value="0" style="height: 12px;"></meter>
									<div class="meterBarLabel" style="display: flex; justify-content: space-between; margin-top: 6px; font-size: 13px;">
										<span id="import-bytes-text" style="font-weight: 500;">0 B / 0 B (0%)</span>
										<span id="import-speed-eta" class="u-muted">Calculating speed &amp; ETA...</span>
									</div>

									<div class="block-row block-row--alt block-row--separated" style="margin-top: 15px; border-radius: 4px;">
										<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; text-align: center; margin-bottom: 8px;">
											<div>
												<div id="stat-percent" style="font-size: 18px; font-weight: bold;">0%</div>
												<div class="u-muted" style="font-size: 11px;">Progress</div>
											</div>
											<div>
												<div id="stat-queries" style="font-size: 18px; font-weight: bold;">0</div>
												<div class="u-muted" style="font-size: 11px;">Queries</div>
											</div>
											<div>
												<div id="stat-speed" style="font-size: 18px; font-weight: bold;">0 B/s</div>
												<div class="u-muted" style="font-size: 11px;">Speed</div>
											</div>
											<div>
												<div id="stat-eta" style="font-size: 18px; font-weight: bold;">--</div>
												<div class="u-muted" style="font-size: 11px;">Remaining</div>
											</div>
										</div>
										<div class="u-muted" style="font-family: monospace; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" id="last-query-preview">
											Initializing session...
										</div>
									</div>
								</div>
							</div>
							<div class="block-footer" style="display: flex; justify-content: space-between; align-items: center;">
								<button type="button" id="btn-pause-import" class="button" onclick="pauseImport()"><span class="button-text">Pause Import</span></button>
								<button type="button" id="btn-reset-import" class="button button--link" onclick="resetCurrentImport()"><span class="button-text">Abort &amp; Reset</span></button>
							</div>
						</div>
					</div>

				</div>
			</main>
		</div>
	</div>

	<footer class="p-footer">
		<div class="p-footer-row">
			<div class="p-footer-row-main"></div>
			<div class="p-footer-row-opposite">
				<span class="p-footer-version">v<?= htmlspecialchars($version) ?></span>
			</div>
		</div>
		<div class="p-footer-copyright">
			Community platform by XenForo<sup>&reg;</sup> &copy; 2010-2026 XenForo Ltd.
		</div>
	</footer>
</div>

<!-- NATIVE XENFORO FLASH NOTIFICATION -->
<div id="xf-flash-container" class="flashMessage" style="display: none; font-size: 16px;">
	<div class="flashMessage-content" id="xf-flash-text"></div>
</div>

<!-- NATIVE XENFORO OVERLAY: ALERT MODAL -->
<div id="xf-modal-container" class="overlay-container" style="display: none;">
	<div class="overlay" tabindex="-1" style="max-width: 560px;">
		<div class="overlay-title">
			<a class="overlay-titleCloser" role="button" tabindex="0" onclick="XF.closeModal()"></a>
			<span id="xf-modal-title">Notice</span>
		</div>
		<div class="overlay-content">
			<div class="block">
				<div class="block-container">
					<div class="block-body">
						<div id="xf-modal-message" class="blockMessage blockMessage--error" style="margin: 0; font-size: 14px; line-height: 1.5;"></div>
					</div>
					<dl class="formRow formSubmitRow formSubmitRow--simple">
						<dt></dt>
						<dd>
							<div class="formSubmitRow-main">
								<div class="formSubmitRow-bar"></div>
								<div class="formSubmitRow-controls">
									<button type="button" class="button button--primary" onclick="XF.closeModal()">
										<span class="button-text">Dismiss</span>
									</button>
								</div>
							</div>
						</dd>
					</dl>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- NATIVE XENFORO OVERLAY: CONFIRMATION MODAL -->
<div id="xf-confirm-container" class="overlay-container" style="display: none;">
	<div class="overlay" tabindex="-1" style="max-width: 560px;">
		<div class="overlay-title">
			<a class="overlay-titleCloser" role="button" tabindex="0" onclick="XF.resolveConfirm(false)"></a>
			<span id="xf-confirm-title">Confirm Action</span>
		</div>
		<div class="overlay-content">
			<div class="block">
				<div class="block-container">
					<div class="block-body">
						<div id="xf-confirm-message" class="block-row" style="padding: 18px 16px; font-size: 14px; line-height: 1.5;"></div>
					</div>
					<dl class="formRow formSubmitRow formSubmitRow--simple">
						<dt></dt>
						<dd>
							<div class="formSubmitRow-main">
								<div class="formSubmitRow-bar"></div>
								<div class="formSubmitRow-controls">
									<button type="button" id="xf-confirm-submit-btn" class="button button--primary">
										<span class="button-text">Proceed</span>
									</button>
									<button type="button" class="button button--alt" onclick="XF.resolveConfirm(false)">
										<span class="button-text">Cancel</span>
									</button>
								</div>
							</div>
						</dd>
					</dl>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- NATIVE XENFORO OVERLAY: MERGE & DECRYPT MODAL -->
<div id="xf-merge-modal-container" class="overlay-container" style="display: none;">
	<div class="overlay" tabindex="-1" style="max-width: 620px;">
		<div class="overlay-title">
			<a class="overlay-titleCloser" role="button" tabindex="0" onclick="closeMergeModal()"></a>
			<span>Reassemble &amp; Decrypt Chunks</span>
		</div>
		<div class="overlay-content">
			<div class="block">
				<div class="block-container">
					<div class="block-body">
						<div class="formInfoRow">
							Consolidate multi-part backup chunks into a single unified SQL dump file for direct database import.
						</div>

						<dl class="formRow">
							<dt><label class="formRow-label">Archive base</label></dt>
							<dd>
								<div style="padding-top: 5px;">
									<strong id="merge-modal-basename" style="font-family: monospace; font-size: 13px;"></strong>
								</div>
							</dd>
						</dl>

						<dl class="formRow">
							<dt><label class="formRow-label">Target output</label></dt>
							<dd>
								<div style="padding-top: 5px;">
									<code id="merge-modal-outputname"></code> &bull; <span id="merge-modal-summary" class="u-muted"></span>
								</div>
							</dd>
						</dl>

						<dl class="formRow formRow--input" id="merge-password-row">
							<dt><label class="formRow-label" for="ctrl_merge_password">Decryption key</label></dt>
							<dd>
								<input type="password" id="ctrl_merge_password" class="input" autocomplete="off" placeholder="Leave blank if backup is unencrypted" />
								<div class="formRow-explain">
									If this backup was created with client-side AES-256 encryption via <strong>Olakunlevpn/TelegramBackup</strong>, enter your secret encryption key here. Leave blank if unencrypted.
								</div>
							</dd>
						</dl>

						<dl class="formRow">
							<dt></dt>
							<dd>
								<ul class="inputChoices">
									<li class="inputChoices-choice">
										<label class="iconic iconic--checkbox iconic--labelled">
											<input type="checkbox" id="ctrl_merge_delete_chunks" value="1" />
											<i aria-hidden="true"></i>
											Delete split chunk files after successful merge
										</label>
									</li>
								</ul>
								<div class="formRow-explain">Saves server disk space once the consolidated dump is verified.</div>
							</dd>
						</dl>
					</div>

					<dl class="formRow formSubmitRow formSubmitRow--simple">
						<dt></dt>
						<dd>
							<div class="formSubmitRow-main">
								<div class="formSubmitRow-bar"></div>
								<div class="formSubmitRow-controls">
									<button type="button" id="btn-execute-merge" class="button button--primary" onclick="submitMergeModal()">
										<span class="button-text">Merge &amp; Reassemble</span>
									</button>
									<button type="button" class="button button--alt" onclick="closeMergeModal()">
										<span class="button-text">Cancel</span>
									</button>
								</div>
							</div>
						</dd>
					</dl>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
const XF = window.XF || {};
let confirmResolver = null;

XF.flashMessage = function(message, duration = 3000) {
	const flash = document.getElementById('xf-flash-container');
	const text = document.getElementById('xf-flash-text');
	if (!flash || !text) return;

	text.innerHTML = message;
	flash.style.display = 'block';
	flash.classList.remove('is-active', 'is-transitioning');
	void flash.offsetWidth;
	flash.classList.add('is-transitioning', 'is-active');

	if (flash._timer) clearTimeout(flash._timer);
	flash._timer = setTimeout(() => {
		flash.classList.remove('is-active');
		setTimeout(() => {
			flash.classList.remove('is-transitioning');
			flash.style.display = 'none';
		}, 300);
	}, duration);
};

XF.alert = function(message, type = 'error', title = null) {
	const modal = document.getElementById('xf-modal-container');
	const titleEl = document.getElementById('xf-modal-title');
	const msgEl = document.getElementById('xf-modal-message');
	if (!modal) return;

	titleEl.textContent = title || (type === 'error' ? 'Oops! We ran into some problems.' : 'Notice');
	msgEl.className = 'blockMessage blockMessage--' + type;
	msgEl.innerHTML = message;

	document.body.classList.add('is-modalOpen');
	modal.style.display = 'block';
	void modal.offsetWidth;
	modal.classList.add('is-active');
};

XF.closeModal = function() {
	const modal = document.getElementById('xf-modal-container');
	if (!modal) return;
	modal.classList.remove('is-active');
	document.body.classList.remove('is-modalOpen');
	setTimeout(() => { modal.style.display = 'none'; }, 250);
};

XF.confirm = function(message, title = 'Confirm Action', confirmText = 'Proceed', isDanger = false) {
	return new Promise(resolve => {
		confirmResolver = resolve;
		const modal = document.getElementById('xf-confirm-container');
		const titleEl = document.getElementById('xf-confirm-title');
		const msgEl = document.getElementById('xf-confirm-message');
		const btn = document.getElementById('xf-confirm-submit-btn');

		titleEl.textContent = title;
		msgEl.innerHTML = message;
		btn.className = 'button button--primary';
		btn.innerHTML = `<span class="button-text">${escapeHtml(confirmText)}</span>`;

		btn.onclick = () => XF.resolveConfirm(true);

		document.body.classList.add('is-modalOpen');
		modal.style.display = 'block';
		void modal.offsetWidth;
		modal.classList.add('is-active');
	});
};

XF.resolveConfirm = function(val) {
	const modal = document.getElementById('xf-confirm-container');
	if (modal) {
		modal.classList.remove('is-active');
		document.body.classList.remove('is-modalOpen');
		setTimeout(() => { modal.style.display = 'none'; }, 250);
	}
	if (confirmResolver) {
		confirmResolver(val);
		confirmResolver = null;
	}
};

document.addEventListener('keydown', e => {
	if (e.key === 'Escape') {
		XF.closeModal();
		XF.resolveConfirm(false);
		closeMergeModal();
	}
});

document.addEventListener('click', e => {
	if (e.target && e.target.classList && e.target.classList.contains('overlay-container')) {
		XF.closeModal();
		XF.resolveConfirm(false);
		closeMergeModal();
	}
});

let currentMergeBaseName = null;

function syncTabEncryptionKey(val) {
	try {
		sessionStorage.setItem('large_sql_importer_enc_key', val);
	} catch (e) {}
}

function getStoredEncryptionKey() {
	const tabKey = document.getElementById('ctrl_tab_encryption_key')?.value;
	if (tabKey) return tabKey;
	try {
		return sessionStorage.getItem('large_sql_importer_enc_key') || '';
	} catch (e) {
		return '';
	}
}

function openMergeModal(baseName, outputName, chunksCount, totalSizeFormatted, isEncrypted) {
	currentMergeBaseName = baseName;
	document.getElementById('merge-modal-basename').textContent = baseName;
	document.getElementById('merge-modal-outputname').textContent = outputName;
	document.getElementById('merge-modal-summary').textContent = `${chunksCount} parts (${totalSizeFormatted})`;

	const tabKey = getStoredEncryptionKey();
	const passInput = document.getElementById('ctrl_merge_password');
	passInput.value = tabKey;
	if (isEncrypted) {
		passInput.placeholder = tabKey ? '' : 'Required: Enter AES-256 encryption key';
	} else {
		passInput.placeholder = 'Leave blank if backup is unencrypted';
	}
	document.getElementById('ctrl_merge_delete_chunks').checked = false;

	const modal = document.getElementById('xf-merge-modal-container');
	document.body.classList.add('is-modalOpen');
	modal.style.display = 'block';
	void modal.offsetWidth;
	modal.classList.add('is-active');
	setTimeout(() => {
		if (!passInput.value) {
			passInput.focus();
		}
	}, 80);
}

function closeMergeModal() {
	const modal = document.getElementById('xf-merge-modal-container');
	if (!modal) return;
	modal.classList.remove('is-active');
	document.body.classList.remove('is-modalOpen');
	setTimeout(() => { modal.style.display = 'none'; }, 250);
}

function submitMergeModal() {
	if (!currentMergeBaseName) return;

	const password = document.getElementById('ctrl_merge_password').value;
	const deleteChunks = document.getElementById('ctrl_merge_delete_chunks').checked ? '1' : '0';
	const baseName = currentMergeBaseName;

	if (password) {
		syncTabEncryptionKey(password);
		const tabInput = document.getElementById('ctrl_tab_encryption_key');
		if (tabInput) tabInput.value = password;
	}

	closeMergeModal();

	const fd = new FormData();
	fd.append('action', 'ajax_merge_chunks');
	fd.append('base_name', baseName);
	fd.append('password', password);
	fd.append('delete_chunks', deleteChunks);

	XF.flashMessage('Merging chunks for <strong>' + escapeHtml(baseName) + '</strong>...', 10000);

	fetch('?action=ajax_merge_chunks', { method: 'POST', body: fd })
		.then(r => r.json())
		.then(res => {
			if (res.success) {
				XF.flashMessage(`Chunks merged into <strong>${escapeHtml(res.output_file)}</strong> (${escapeHtml(res.total_size_formatted)}). Switching to SQL Importer...`, 3000);
				setTimeout(() => { window.location.href = '?tab=import'; }, 1200);
			} else {
				XF.alert(escapeHtml(res.error), 'error', 'Merge Error');
			}
		})
		.catch(err => {
			XF.alert('Request error: ' + escapeHtml(err.message || String(err)), 'error', 'Merge Failed');
		});
}

let currentImportFile = null;
let importPaused = false;
let isImporting = false;

function copyUploadPath() {
	const pathText = document.getElementById('upload-dir-path')?.value || '<?= addslashes($this->uploadDir) ?>';
	navigator.clipboard.writeText(pathText).then(() => {
		XF.flashMessage('Server path copied to clipboard: <code>' + escapeHtml(pathText) + '</code>');
	});
}

function applyDbConfig() {
	const host = document.getElementById('ctrl_db_host').value;
	const port = document.getElementById('ctrl_db_port').value;
	const user = document.getElementById('ctrl_db_username').value;
	const pass = document.getElementById('ctrl_db_password').value;
	const name = document.getElementById('ctrl_db_dbname').value;

	const fd = new FormData();
	fd.append('action', 'ajax_test_db');
	fd.append('db_host', host);
	fd.append('db_port', port);
	fd.append('db_username', user);
	fd.append('db_password', pass);
	fd.append('db_dbname', name);

	fetch('?action=ajax_test_db', { method: 'POST', body: fd })
		.then(r => r.json())
		.then(data => {
			if (data.success) {
				XF.flashMessage(escapeHtml(data.message) + ' Redirecting to SQL Importer...');
				setTimeout(() => { window.location.href = '?tab=import'; }, 1500);
			} else {
				XF.alert(escapeHtml(data.error), 'error', 'Database Connection Error');
			}
		})
		.catch(err => XF.alert('Request failed: ' + escapeHtml(err.message || String(err)), 'error', 'Connection Error'));
}

function handleFileSelect(files) {
	if (!files || files.length === 0) return;
	const file = files[0];
	const chunkSize = 5 * 1024 * 1024;
	const totalChunks = Math.ceil(file.size / chunkSize);
	let currentChunk = 0;

	const progContainer = document.getElementById('upload-progress-container');
	const meter = document.getElementById('upload-meter');
	const statusText = document.getElementById('upload-status-text');

	progContainer.style.display = 'block';

	function uploadNext() {
		const start = currentChunk * chunkSize;
		const end = Math.min(file.size, start + chunkSize);
		const blob = file.slice(start, end);

		const fd = new FormData();
		fd.append('action', 'ajax_upload_chunk');
		fd.append('file_name', file.name);
		fd.append('chunk_index', currentChunk);
		fd.append('total_chunks', totalChunks);
		fd.append('chunk', blob);

		fetch('?action=ajax_upload_chunk', { method: 'POST', body: fd })
			.then(r => r.json())
			.then(res => {
				if (!res.success) {
					XF.alert(escapeHtml(res.error), 'error', 'Upload Failed');
					progContainer.style.display = 'none';
					return;
				}

				currentChunk++;
				const pct = Math.round((currentChunk / totalChunks) * 100);
				meter.value = pct;
				statusText.innerText = `Uploading '${file.name}': ${pct}% (${currentChunk}/${totalChunks} chunks)`;

				if (currentChunk < totalChunks) {
					uploadNext();
				} else {
					statusText.innerText = `Upload Complete: ${file.name}`;
					XF.flashMessage('File <strong>' + escapeHtml(file.name) + '</strong> uploaded successfully.');
					setTimeout(() => {
						progContainer.style.display = 'none';
						loadFilesList();
					}, 1200);
				}
			})
			.catch(err => {
				XF.alert('Upload error: ' + escapeHtml(err.message || String(err)), 'error', 'Upload Error');
				progContainer.style.display = 'none';
			});
	}

	uploadNext();
}

function loadFilesList() {
	const container = document.getElementById('files-list-container');
	if (!container) return;

	fetch('?action=ajax_list_files')
		.then(r => r.json())
		.then(data => {
			if (!data.success || !data.files || data.files.length === 0) {
				container.innerHTML = `<div class="block-row u-muted" style="text-align: center; padding: 25px;">No SQL dump files detected in <code>internal_data/sql_import</code>. Transfer files via SFTP or upload above.</div>`;
				return;
			}

			let html = '';
			data.files.forEach(f => {
				let statusBadge = '<span class="label label--blue">Ready</span>';
				let actionBtn = `<button type="button" class="button button--primary" onclick="startImport('${escapeHtml(f.name)}')"><span class="button-text">Import</span></button>`;

				if (f.state) {
					if (f.state.status === 'completed') {
						statusBadge = '<span class="label label--green">Completed</span>';
						actionBtn = `<button type="button" class="button button--alt" onclick="resetState('${escapeHtml(f.name)}')"><span class="button-text">Re-Import</span></button>`;
					} else if (f.state.status === 'running') {
						const pct = f.size > 0 ? Math.round((f.state.offset / f.size) * 100) : 0;
						statusBadge = `<span class="label label--orange">${pct}% Paused</span>`;
						actionBtn = `<button type="button" class="button button--primary" onclick="startImport('${escapeHtml(f.name)}')"><span class="button-text">Resume</span></button> <button type="button" class="button button--alt" onclick="resetState('${escapeHtml(f.name)}')"><span class="button-text">Reset</span></button>`;
					}
				}

				html += `
				<div class="block-row block-row--separated">
					<div class="contentRow contentRow--alignMiddle" style="display: flex; justify-content: space-between; align-items: center;">
						<div class="contentRow-main" style="flex: 1; min-width: 0;">
							<div style="display: flex; align-items: center; gap: 8px;">
								<span style="font-family: monospace; font-size: 14px; font-weight: 600;">${escapeHtml(f.name)}</span>
								${statusBadge}
							</div>
							<div class="contentRow-minor" style="margin-top: 3px; font-size: 12px;">
								<span class="u-muted">Size:</span> <strong>${f.size_formatted}</strong> &bull; 
								<span class="u-muted">Modified:</span> ${f.modified}
							</div>
						</div>
						<div class="contentRow-extra" style="display: flex; gap: 8px; align-items: center; margin-left: 12px;">
							${actionBtn}
							<button type="button" class="button button--alt" onclick="deleteFile('${escapeHtml(f.name)}')"><span class="button-text">Delete</span></button>
						</div>
					</div>
				</div>`;
			});
			container.innerHTML = html;
		})
		.catch(err => {
			container.innerHTML = `<div class="blockMessage blockMessage--error">Failed to load files: ${escapeHtml(err)}</div>`;
		});
}

async function deleteFile(fileName) {
	const ok = await XF.confirm(
		`Are you sure you want to permanently delete '<strong>${escapeHtml(fileName)}</strong>'?`,
		'Confirm File Deletion',
		'Delete File',
		true
	);
	if (!ok) return;

	const fd = new FormData();
	fd.append('action', 'ajax_delete_file');
	fd.append('file_name', fileName);
	fetch('?action=ajax_delete_file', { method: 'POST', body: fd })
		.then(r => r.json())
		.then(() => {
			XF.flashMessage(`File <strong>${escapeHtml(fileName)}</strong> deleted.`);
			loadFilesList();
		});
}

async function resetState(fileName) {
	const ok = await XF.confirm(
		`Reset import state for '<strong>${escapeHtml(fileName)}</strong>' and start from the beginning?`,
		'Reset Import State',
		'Reset State',
		false
	);
	if (!ok) return;

	const fd = new FormData();
	fd.append('action', 'ajax_reset_state');
	fd.append('file_name', fileName);
	fetch('?action=ajax_reset_state', { method: 'POST', body: fd })
		.then(r => r.json())
		.then(() => loadFilesList());
}

function startImport(fileName) {
	currentImportFile = fileName;
	importPaused = false;
	isImporting = true;

	document.getElementById('import-progress-panel').style.display = 'block';
	document.getElementById('current-import-filename').innerText = fileName;
	document.getElementById('btn-pause-import').innerHTML = '<span class="button-text">Pause Import</span>';

	runImportLoop();
}

function pauseImport() {
	if (importPaused) {
		importPaused = false;
		document.getElementById('btn-pause-import').innerHTML = '<span class="button-text">Pause Import</span>';
		runImportLoop();
	} else {
		importPaused = true;
		document.getElementById('btn-pause-import').innerHTML = '<span class="button-text">Resume Import</span>';
	}
}

async function resetCurrentImport() {
	if (!currentImportFile) return;
	const ok = await XF.confirm(
		`Stop import of '<strong>${escapeHtml(currentImportFile)}</strong>' and reset progress?`,
		'Abort Import',
		'Abort & Reset',
		true
	);
	if (!ok) return;

	importPaused = true;
	isImporting = false;
	resetState(currentImportFile);
	document.getElementById('import-progress-panel').style.display = 'none';
}

function runImportLoop() {
	if (!isImporting || importPaused || !currentImportFile) return;

	const fd = new FormData();
	fd.append('action', 'ajax_import_batch');
	fd.append('file_name', currentImportFile);

	fetch('?action=ajax_import_batch', { method: 'POST', body: fd })
		.then(r => r.json())
		.then(res => {
			if (!res.success) {
				XF.alert(escapeHtml(res.error), 'error', 'Import Error');
				isImporting = false;
				return;
			}

			document.getElementById('import-meter').value = res.percent;
			document.getElementById('stat-percent').innerText = res.percent + '%';
			document.getElementById('stat-queries').innerText = Number(res.queries).toLocaleString();
			document.getElementById('stat-speed').innerText = res.speed;
			document.getElementById('stat-eta').innerText = res.eta;
			document.getElementById('import-bytes-text').innerText = `${res.percent}% completed (${formatBytes(res.offset)} / ${formatBytes(res.total_size)})`;
			document.getElementById('import-speed-eta').innerText = `Speed: ${res.speed} | ETA: ${res.eta}`;
			if (res.last_statement) {
				document.getElementById('last-query-preview').innerText = res.last_statement;
			}

			if (res.completed) {
				isImporting = false;
				document.getElementById('last-query-preview').innerText = 'All SQL queries executed successfully!';
				XF.alert(`<strong>${escapeHtml(currentImportFile)}</strong> has been successfully imported into the database.`, 'success', 'Import Complete');
				loadFilesList();
			} else {
				runImportLoop();
			}
		})
		.catch(err => {
			console.error(err);
			if (!importPaused) {
				setTimeout(runImportLoop, 2000);
			}
		});
}

function loadChunkGroups() {
	const container = document.getElementById('chunk-groups-container');
	if (!container) return;

	fetch('?action=ajax_list_chunks')
		.then(r => r.json())
		.then(data => {
			if (!data.success || !data.groups || data.groups.length === 0) {
				container.innerHTML = `<div class="block-row u-muted" style="text-align: center; padding: 25px;">No split chunks (.part01, .001, .enc) detected. Upload chunks via SFTP to the folder above.</div>`;
				return;
			}

			let html = '';
			data.groups.forEach(g => {
				let badge = g.is_valid_sequence 
					? `<span class="label label--green">${g.chunks_count} Parts Complete</span>`
					: `<span class="label label--red">Missing: ${g.missing_parts.join(', ')}</span>`;
				
				let encBadge = g.is_encrypted ? `<span class="label label--orange" style="margin-left:4px;">AES-256</span>` : '';

				html += `
				<div class="block-row block-row--separated">
					<div class="contentRow contentRow--alignMiddle" style="display: flex; justify-content: space-between; align-items: center;">
						<div class="contentRow-main" style="flex: 1; min-width: 0;">
							<div style="display: flex; align-items: center; gap: 8px;">
								<span style="font-family: monospace; font-size: 14px; font-weight: 600;">${escapeHtml(g.base_name)}</span>
								${badge} ${encBadge}
							</div>
							<div class="contentRow-minor" style="margin-top: 3px; font-size: 12px;">
								<span class="u-muted">Total Size:</span> <strong>${g.total_size_formatted}</strong> (${g.chunks_count} chunks) &bull; 
								<span class="u-muted">Target:</span> <code>${escapeHtml(g.output_name)}</code>
							</div>
						</div>
						<div class="contentRow-extra" style="display: flex; gap: 8px; align-items: center; margin-left: 12px;">
							<button type="button" class="button button--primary" ${g.is_valid_sequence ? '' : 'disabled'} onclick="openMergeModal('${escapeHtml(g.base_name)}', '${escapeHtml(g.output_name)}', ${g.chunks_count}, '${escapeHtml(g.total_size_formatted)}', ${g.is_encrypted ? 1 : 0})"><span class="button-text">Merge Chunks</span></button>
							<button type="button" class="button button--alt" onclick="deleteChunks('${escapeHtml(g.base_name)}')"><span class="button-text">Delete</span></button>
						</div>
					</div>
				</div>`;
			});

			container.innerHTML = html;
		})
		.catch(err => {
			container.innerHTML = `<div class="blockMessage blockMessage--error">Failed to scan chunks: ${escapeHtml(err)}</div>`;
		});
}

async function deleteChunks(baseName) {
	const ok = await XF.confirm(
		`Permanently delete all split chunk parts for '<strong>${escapeHtml(baseName)}</strong>'? This action cannot be undone.`,
		'Confirm Chunk Deletion',
		'Delete Chunks',
		true
	);
	if (!ok) return;

	const fd = new FormData();
	fd.append('action', 'ajax_delete_chunks');
	fd.append('base_name', baseName);
	fetch('?action=ajax_delete_chunks', { method: 'POST', body: fd })
		.then(r => r.json())
		.then(() => {
			XF.flashMessage(`Chunk set <strong>${escapeHtml(baseName)}</strong> deleted.`);
			loadChunkGroups();
		});
}

function formatBytes(bytes) {
	bytes = Number(bytes);
	if (isNaN(bytes) || bytes === 0) return '0 B';
	const k = 1024;
	const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
	const i = Math.floor(Math.log(bytes) / Math.log(k));
	return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function escapeHtml(text) {
	return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', () => {
	const storedKey = getStoredEncryptionKey();
	const tabInput = document.getElementById('ctrl_tab_encryption_key');
	if (tabInput && storedKey) {
		tabInput.value = storedKey;
	}
	<?php if ($tab === 'import'): ?>
	loadFilesList();
	<?php elseif ($tab === 'merge'): ?>
	loadChunkGroups();
	<?php endif; ?>
});
</script>
</body>
</html>
        <?php
        exit;
    }
}
