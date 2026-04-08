<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Runtime;

final readonly class SchemaInspector
{
    /**
     * @return list<array{TABLE_NAME: string, TABLE_TYPE: string, ENGINE: string|null, TABLE_ROWS: int|null}>
     */
    public function getTables(\PDO $db): array
    {
        $stmt = $db->query(
            "SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_TYPE, TABLE_NAME",
        );

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTableInfo(\PDO $db, string $table): array
    {
        $table = $this->sanitizeName($table);
        $stmt = $db->prepare(
            "SELECT ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             ORDER BY ORDINAL_POSITION",
        );
        $stmt->execute(['table' => $table]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getIndexes(\PDO $db, string $table): array
    {
        $table = $this->sanitizeName($table);
        $stmt = $db->query(sprintf('SHOW INDEX FROM %s', $this->quoteName($table)));

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getForeignKeys(\PDO $db, string $table): array
    {
        $table = $this->sanitizeName($table);
        $stmt = $db->prepare(
            "SELECT
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE,
                kcu.CONSTRAINT_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
             WHERE kcu.TABLE_SCHEMA = DATABASE()
                AND kcu.TABLE_NAME = :table
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION",
        );
        $stmt->execute(['table' => $table]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTriggers(\PDO $db, ?string $table = null): array
    {
        if ($table !== null) {
            $table = $this->sanitizeName($table);
            $stmt = $db->prepare(
                "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT
                 FROM INFORMATION_SCHEMA.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = :table
                 ORDER BY TRIGGER_NAME",
            );
            $stmt->execute(['table' => $table]);
        } else {
            $stmt = $db->query(
                "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT
                 FROM INFORMATION_SCHEMA.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE()
                 ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME",
            );
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array{TABLE_NAME: string, VIEW_DEFINITION: string}>
     */
    public function getViews(\PDO $db): array
    {
        $stmt = $db->query(
            "SELECT TABLE_NAME, VIEW_DEFINITION FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME",
        );

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRoutines(\PDO $db): array
    {
        $stmt = $db->query(
            "SELECT ROUTINE_NAME, ROUTINE_TYPE, DATA_TYPE, CREATED, LAST_ALTERED
             FROM INFORMATION_SCHEMA.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE()
             ORDER BY ROUTINE_TYPE, ROUTINE_NAME",
        );

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getTableDdl(\PDO $db, string $table): string
    {
        $table = $this->sanitizeName($table);
        $stmt = $db->query(sprintf('SHOW CREATE TABLE %s', $this->quoteName($table)));
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($result === false) {
            return '';
        }

        // SHOW CREATE TABLE returns 'Create Table' or 'Create View' column
        return (string) ($result['Create Table'] ?? $result['Create View'] ?? '');
    }

    /**
     * @return array{table_count: int, total_rows: int, data_size_bytes: int, index_size_bytes: int, total_size_bytes: int}
     */
    public function getDatabaseStats(\PDO $db): array
    {
        $stmt = $db->query(
            "SELECT
                COUNT(*) AS table_count,
                COALESCE(SUM(TABLE_ROWS), 0) AS total_rows,
                COALESCE(SUM(DATA_LENGTH), 0) AS data_size_bytes,
                COALESCE(SUM(INDEX_LENGTH), 0) AS index_size_bytes,
                COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS total_size_bytes
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
        );

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'table_count' => (int) ($result['table_count'] ?? 0),
            'total_rows' => (int) ($result['total_rows'] ?? 0),
            'data_size_bytes' => (int) ($result['data_size_bytes'] ?? 0),
            'index_size_bytes' => (int) ($result['index_size_bytes'] ?? 0),
            'total_size_bytes' => (int) ($result['total_size_bytes'] ?? 0),
        ];
    }

    /**
     * @return array{row_count: int, data_length: int, index_length: int, auto_increment: int|null, engine: string, collation: string, column_count: int, index_count: int, foreign_key_count: int, trigger_count: int}
     */
    public function getTableStats(\PDO $db, string $table): array
    {
        $table = $this->sanitizeName($table);

        $stmt = $db->prepare(
            "SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, AUTO_INCREMENT, ENGINE, TABLE_COLLATION
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table",
        );
        $stmt->execute(['table' => $table]);
        $info = $stmt->fetch(\PDO::FETCH_ASSOC);

        $columns = $this->getTableInfo($db, $table);
        $foreignKeys = $this->getForeignKeys($db, $table);
        $triggers = $this->getTriggers($db, $table);

        // Count distinct indexes
        $indexes = $this->getIndexes($db, $table);
        $indexNames = array_unique(array_column($indexes, 'Key_name'));

        return [
            'row_count' => (int) ($info['TABLE_ROWS'] ?? 0),
            'data_length' => (int) ($info['DATA_LENGTH'] ?? 0),
            'index_length' => (int) ($info['INDEX_LENGTH'] ?? 0),
            'auto_increment' => $info['AUTO_INCREMENT'] !== null ? (int) $info['AUTO_INCREMENT'] : null,
            'engine' => (string) ($info['ENGINE'] ?? 'unknown'),
            'collation' => (string) ($info['TABLE_COLLATION'] ?? 'unknown'),
            'column_count' => count($columns),
            'index_count' => count($indexNames),
            'foreign_key_count' => count($foreignKeys),
            'trigger_count' => count($triggers),
        ];
    }

    /**
     * @return list<array{table: string, rows: int, data_size: int, index_size: int, engine: string}>
     */
    public function getAllTableStats(\PDO $db): array
    {
        $stmt = $db->query(
            "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME",
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stats = [];
        foreach ($rows as $row) {
            $stats[] = [
                'table' => (string) $row['TABLE_NAME'],
                'rows' => (int) ($row['TABLE_ROWS'] ?? 0),
                'data_size' => (int) ($row['DATA_LENGTH'] ?? 0),
                'index_size' => (int) ($row['INDEX_LENGTH'] ?? 0),
                'engine' => (string) ($row['ENGINE'] ?? ''),
            ];
        }

        return $stats;
    }

    /**
     * @return list<string>
     */
    public function getDatabases(\PDO $db): array
    {
        $stmt = $db->query('SHOW DATABASES');

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Sanitize an identifier name to prevent SQL injection.
     */
    public function sanitizeName(string $name): string
    {
        $name = trim($name, '`"\'[]');

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_.\-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid identifier name: "%s"', $name));
        }

        return $name;
    }

    /**
     * Quote an identifier with backticks for MySQL.
     */
    public function quoteName(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
