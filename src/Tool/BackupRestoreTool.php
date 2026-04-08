<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;

final readonly class BackupRestoreTool
{
    public function __construct(
        private ConnectionManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_backup_restore',
            description: 'Backup a MySQL database using mysqldump, restore from a SQL dump, or clone a database. Restore and clone operations require user confirmation.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Backup/restore action',
                    values: ['backup', 'restore', 'clone'],
                    required: true,
                ),
                new StringParameter(
                    'destination',
                    'File path for backup output, or the SQL file to restore from. For clone: target database name.',
                    required: true,
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
        $destination = (string) ($args['destination'] ?? '');
        $database = (string) ($args['database'] ?? '');

        if ($destination === '') {
            return ToolResult::error('Parameter "destination" is required.');
        }

        try {
            return match ($action) {
                'backup' => $this->backup($database, $destination),
                'restore' => $this->restore($database, $destination),
                'clone' => $this->cloneDb($database, $destination),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('Backup/restore error: %s', $e->getMessage()));
        }
    }

    private function backup(string $database, string $destination): ToolResult
    {
        $mysqldump = $this->findBinary('mysqldump');
        if ($mysqldump === null) {
            return ToolResult::error('mysqldump binary not found. Ensure MySQL client tools are installed and in PATH.');
        }

        $alias = $this->manager->resolveAlias($database);
        $meta = $this->manager->getMetadata($alias);

        if ($meta['database'] === '') {
            return ToolResult::error('No database selected. Connect with a database specified.');
        }

        $resolvedPath = $this->resolveFilePath($destination);
        $dir = dirname($resolvedPath);
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
            escapeshellarg($resolvedPath),
        );

        $password = $this->manager->getPassword($alias);
        $envPrefix = $password !== '' ? sprintf('MYSQL_PWD=%s ', escapeshellarg($password)) : '';

        $output = [];
        $returnCode = 0;
        exec($envPrefix . $cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return ToolResult::error(sprintf('mysqldump failed (exit code %d): %s', $returnCode, implode("\n", $output)));
        }

        $size = file_exists($resolvedPath) ? (int) filesize($resolvedPath) : 0;

        return ToolResult::success(sprintf(
            "Database **%s** backed up to `%s` (%s).\n\nRestore with: `mysql_backup_restore(action: \"restore\", destination: \"%s\")`",
            $meta['database'],
            $resolvedPath,
            $this->formatBytes($size),
            $destination,
        ));
    }

    private function restore(string $database, string $source): ToolResult
    {
        $mysql = $this->findBinary('mysql');
        if ($mysql === null) {
            return ToolResult::error('mysql client binary not found. Ensure MySQL client tools are installed and in PATH.');
        }

        $resolvedSource = $this->resolveFilePath($source);
        if (!file_exists($resolvedSource)) {
            return ToolResult::error(sprintf('Backup file not found: %s', $resolvedSource));
        }

        $alias = $this->manager->resolveAlias($database);
        $meta = $this->manager->getMetadata($alias);

        if ($meta['database'] === '') {
            return ToolResult::error('No database selected. Connect with a database specified.');
        }

        $cmd = sprintf(
            '%s -h %s -P %d -u %s %s < %s 2>&1',
            escapeshellcmd($mysql),
            escapeshellarg($meta['host']),
            $meta['port'],
            escapeshellarg($meta['user']),
            escapeshellarg($meta['database']),
            escapeshellarg($resolvedSource),
        );

        $password = $this->manager->getPassword($alias);
        $envPrefix = $password !== '' ? sprintf('MYSQL_PWD=%s ', escapeshellarg($password)) : '';

        $output = [];
        $returnCode = 0;
        exec($envPrefix . $cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return ToolResult::error(sprintf('Restore failed (exit code %d): %s', $returnCode, implode("\n", $output)));
        }

        $size = (int) filesize($resolvedSource);

        return ToolResult::success(sprintf(
            'Database **%s** restored from `%s` (%s).',
            $meta['database'],
            $resolvedSource,
            $this->formatBytes($size),
        ));
    }

    private function cloneDb(string $database, string $targetDatabase): ToolResult
    {
        $mysqldump = $this->findBinary('mysqldump');
        $mysql = $this->findBinary('mysql');
        if ($mysqldump === null || $mysql === null) {
            return ToolResult::error('mysqldump and/or mysql binary not found. Ensure MySQL client tools are installed and in PATH.');
        }

        $alias = $this->manager->resolveAlias($database);
        $meta = $this->manager->getMetadata($alias);
        $db = $this->manager->resolveConnection($database);

        if ($meta['database'] === '') {
            return ToolResult::error('No database selected. Connect with a database specified.');
        }

        // Sanitize target database name
        $safeTarget = preg_replace('/[^a-zA-Z0-9_]/', '', $targetDatabase);
        if ($safeTarget === '' || $safeTarget === null) {
            return ToolResult::error('Invalid target database name.');
        }

        // Create target database
        $db->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', str_replace('`', '``', $safeTarget)));

        // Pipe: mysqldump → mysql
        $password = $this->manager->getPassword($alias);
        $envPrefix = $password !== '' ? sprintf('MYSQL_PWD=%s ', escapeshellarg($password)) : '';

        $cmd = sprintf(
            '%s --single-transaction --routines --triggers --events -h %s -P %d -u %s %s | %s -h %s -P %d -u %s %s 2>&1',
            escapeshellcmd($mysqldump),
            escapeshellarg($meta['host']),
            $meta['port'],
            escapeshellarg($meta['user']),
            escapeshellarg($meta['database']),
            escapeshellcmd($mysql),
            escapeshellarg($meta['host']),
            $meta['port'],
            escapeshellarg($meta['user']),
            escapeshellarg($safeTarget),
        );

        $output = [];
        $returnCode = 0;
        exec($envPrefix . $cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return ToolResult::error(sprintf('Clone failed (exit code %d): %s', $returnCode, implode("\n", $output)));
        }

        return ToolResult::success(sprintf(
            "Database **%s** cloned to **%s**.\n\nConnect to clone: `mysql_connect(host: \"%s\", user: \"%s\", database: \"%s\")`",
            $meta['database'],
            $safeTarget,
            $meta['host'],
            $meta['user'],
            $safeTarget,
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
            return rtrim($this->manager->storagePath(), '/') . '/backups/' . $path;
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
