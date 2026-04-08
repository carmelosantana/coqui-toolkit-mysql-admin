<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Exception;

final class MysqlAdminException extends \RuntimeException
{
    public static function connectionFailed(string $host, string $reason): self
    {
        return new self(sprintf('Failed to connect to MySQL server "%s": %s', $host, $reason));
    }

    public static function noActiveConnection(): self
    {
        return new self('No active MySQL connection. Use mysql_connect to connect to a server first.');
    }

    public static function connectionNotFound(string $alias): self
    {
        return new self(sprintf('No MySQL connection found with alias "%s". Use mysql_connections to list open connections.', $alias));
    }

    public static function maxConnectionsReached(int $max): self
    {
        return new self(sprintf('Maximum of %d concurrent connections reached. Disconnect an existing connection first.', $max));
    }

    public static function queryFailed(string $sql, string $reason): self
    {
        return new self(sprintf('Query failed: %s — SQL: %s', $reason, mb_substr($sql, 0, 200)));
    }

    public static function schemaError(string $operation, string $reason): self
    {
        return new self(sprintf('Schema operation "%s" failed: %s', $operation, $reason));
    }

    public static function importExportError(string $operation, string $reason): self
    {
        return new self(sprintf('Import/export operation "%s" failed: %s', $operation, $reason));
    }

    public static function backupError(string $operation, string $reason): self
    {
        return new self(sprintf('Backup operation "%s" failed: %s', $operation, $reason));
    }

    public static function userError(string $operation, string $reason): self
    {
        return new self(sprintf('User management operation "%s" failed: %s', $operation, $reason));
    }

    public static function invalidTable(string $table): self
    {
        return new self(sprintf('Invalid table name: "%s". Table names must be alphanumeric with underscores.', $table));
    }
}
