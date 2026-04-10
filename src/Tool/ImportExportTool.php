<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;

final readonly class ImportExportTool
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private ConnectionManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_import_export',
            description: 'Import data from CSV/JSON into MySQL tables, export tables to CSV/JSON, or dump the database schema and data as SQL using mysqldump.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Import/export action',
                    values: ['import_csv', 'import_json', 'export_csv', 'export_json', 'dump_sql'],
                    required: true,
                ),
                new StringParameter(
                    'table',
                    'Table name for import or single-table export',
                    required: false,
                ),
                new StringParameter(
                    'file_path',
                    'Path to the file for import/export (relative paths resolve in workspace)',
                    required: true,
                ),
                new StringParameter(
                    'options',
                    'JSON options: {"delimiter": ",", "header": true, "if_exists": "append|replace|fail"} for CSV. {"pretty": true} for JSON export.',
                    required: false,
                ),
                new StringParameter(
                    'database',
                    'Connection alias (defaults to active)',
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');
        $table = (string) ($args['table'] ?? '');
        $filePath = (string) ($args['file_path'] ?? '');
        $optionsJson = (string) ($args['options'] ?? '');
        $database = (string) ($args['database'] ?? '');

        if ($filePath === '') {
            return ToolResult::error('Parameter "file_path" is required.');
        }

        $options = [];
        if ($optionsJson !== '') {
            $decoded = json_decode($optionsJson, true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        $resolvedPath = $this->resolveFilePath($filePath);

        try {
            if ($action === 'dump_sql') {
                return $this->dumpSql($database, $resolvedPath);
            }

            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'import_csv' => $this->importCsv($db, $table, $resolvedPath, $options),
                'import_json' => $this->importJson($db, $table, $resolvedPath, $options),
                'export_csv' => $this->exportCsv($db, $table, $resolvedPath, $options),
                'export_json' => $this->exportJson($db, $table, $resolvedPath, $options),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('%s error: %s', $action, $e->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function importCsv(\PDO $db, string $table, string $filePath, array $options): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for CSV import.');
        }

        if (!file_exists($filePath)) {
            return ToolResult::error(sprintf('File not found: %s', $filePath));
        }

        $delimiter = (string) ($options['delimiter'] ?? ',');
        $hasHeader = (bool) ($options['header'] ?? true);
        $ifExists = (string) ($options['if_exists'] ?? 'append');

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ToolResult::error(sprintf('Cannot open file: %s', $filePath));
        }

        try {
            $headers = null;
            if ($hasHeader) {
                $headers = fgetcsv($handle, 0, $delimiter);
                if ($headers === false || $headers === [null]) {
                    return ToolResult::error('CSV file is empty or has no valid header row.');
                }
                $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            }

            if ($ifExists === 'replace') {
                $db->exec(sprintf('DELETE FROM `%s`', str_replace('`', '``', $table)));
            }

            $db->beginTransaction();
            $rowCount = 0;

            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($row === [null]) {
                    continue;
                }

                if ($headers === null) {
                    $headers = array_map(fn(int $i): string => 'col_' . ($i + 1), array_keys($row));
                }

                $quotedCols = array_map(fn(string $h): string => '`' . str_replace('`', '``', trim($h)) . '`', $headers);
                $placeholders = implode(', ', array_fill(0, count($row), '?'));
                $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', str_replace('`', '``', $table), implode(', ', $quotedCols), $placeholders);

                $db->prepare($sql)->execute($row);
                $rowCount++;

                if ($rowCount % self::BATCH_SIZE === 0) {
                    $db->commit();
                    $db->beginTransaction();
                }
            }

            $db->commit();

            return ToolResult::success(sprintf('Imported **%d rows** into table **%s** from `%s`.', $rowCount, $table, basename($filePath)));
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function importJson(\PDO $db, string $table, string $filePath, array $options): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for JSON import.');
        }

        if (!file_exists($filePath)) {
            return ToolResult::error(sprintf('File not found: %s', $filePath));
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return ToolResult::error(sprintf('Cannot read file: %s', $filePath));
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return ToolResult::error('JSON file must contain an array of objects.');
        }

        // Handle both flat arrays and nested structures
        if (isset($data[0]) && is_array($data[0])) {
            $rows = $data;
        } else {
            $rows = null;
            foreach ($data as $value) {
                if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                    $rows = $value;
                    break;
                }
            }
            if ($rows === null) {
                $rows = [$data];
            }
        }

        $ifExists = (string) ($options['if_exists'] ?? 'append');
        if ($ifExists === 'replace') {
            $db->exec(sprintf('DELETE FROM `%s`', str_replace('`', '``', $table)));
        }

        $columns = array_keys($rows[0]);
        $quotedCols = array_map(fn(string $c): string => '`' . str_replace('`', '``', $c) . '`', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', str_replace('`', '``', $table), implode(', ', $quotedCols), $placeholders);

        $db->beginTransaction();
        $rowCount = 0;
        $stmt = $db->prepare($sql);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $values = array_map(fn(string $col): mixed => $row[$col] ?? null, $columns);
            $values = array_map(fn(mixed $v): mixed => is_array($v) ? json_encode($v) : $v, $values);

            $stmt->execute($values);
            $rowCount++;

            if ($rowCount % self::BATCH_SIZE === 0) {
                $db->commit();
                $db->beginTransaction();
            }
        }

        $db->commit();

        return ToolResult::success(sprintf('Imported **%d rows** into table **%s** from `%s`.', $rowCount, $table, basename($filePath)));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function exportCsv(\PDO $db, string $table, string $filePath, array $options): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for CSV export.');
        }

        $delimiter = (string) ($options['delimiter'] ?? ',');

        $stmt = $db->query(sprintf('SELECT * FROM `%s`', str_replace('`', '``', $table)));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            return ToolResult::error(sprintf('Cannot write to file: %s', $filePath));
        }

        try {
            if ($rows !== []) {
                fputcsv($handle, array_keys($rows[0]), $delimiter);
                foreach ($rows as $row) {
                    fputcsv($handle, $row, $delimiter);
                }
            }

            return ToolResult::success(sprintf('Exported **%d rows** from **%s** to `%s`.', count($rows), $table, $filePath));
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function exportJson(\PDO $db, string $table, string $filePath, array $options): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for JSON export.');
        }

        $pretty = (bool) ($options['pretty'] ?? true);

        $stmt = $db->query(sprintf('SELECT * FROM `%s`', str_replace('`', '``', $table)));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($rows, $flags);
        file_put_contents($filePath, $json);

        return ToolResult::success(sprintf('Exported **%d rows** from **%s** to `%s`.', count($rows), $table, $filePath));
    }

    private function dumpSql(string $database, string $filePath): ToolResult
    {
        $mysqldump = $this->findBinary('mysqldump');
        if ($mysqldump === null) {
            return ToolResult::error('mysqldump binary not found. Ensure MySQL client tools are installed and in PATH.');
        }

        $alias = $this->manager->resolveAlias($database);
        $meta = $this->manager->getMetadata($alias);

        if ($meta['database'] === '') {
            return ToolResult::error('No database selected on this connection. Connect with a database specified.');
        }

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $cmd = sprintf(
            '%s --single-transaction --routines --triggers --events -h %s -P %d -u %s %s > %s 2>&1',
            escapeshellcmd($mysqldump),
            escapeshellarg($meta['host']),
            $meta['port'],
            escapeshellarg($meta['user']),
            escapeshellarg($meta['database']),
            escapeshellarg($filePath),
        );

        // Pass password via MYSQL_PWD env var (never on command line)
        $password = $this->manager->getPassword($alias);
        $envPrefix = '';
        if ($password !== '') {
            $envPrefix = sprintf('MYSQL_PWD=%s ', escapeshellarg($password));
        }

        $output = [];
        $returnCode = 0;
        exec($envPrefix . $cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return ToolResult::error(sprintf('mysqldump failed (exit code %d): %s', $returnCode, implode("\n", $output)));
        }

        $size = file_exists($filePath) ? (int) filesize($filePath) : 0;

        return ToolResult::success(sprintf(
            'Database **%s** dumped to `%s` (%s).',
            $meta['database'],
            $filePath,
            $this->formatBytes($size),
        ));
    }

    private function findBinary(string $name): ?string
    {
        $output = [];
        $returnCode = 0;
        exec(sprintf('which %s 2>/dev/null', escapeshellarg($name)), $output, $returnCode);

        return $returnCode === 0 && $output !== [] ? $output[0] : null;
    }

    private function resolveFilePath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME');
            if (is_string($home) && $home !== '') {
                return $home . substr($path, 1);
            }
        }

        if (!str_starts_with($path, '/') && $this->manager->storagePath() !== '') {
            return rtrim($this->manager->storagePath(), '/') . '/' . $path;
        }

        return $path;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1_048_576) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / 1_048_576);
    }
}
