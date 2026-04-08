<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\SchemaInspector;

final readonly class SchemaModifyTool
{
    public function __construct(
        private ConnectionManager $manager,
        private SchemaInspector $inspector,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_schema_modify',
            description: 'Modify MySQL schema: create/drop/rename tables and databases, add/modify/drop columns, create/drop indexes and views. Destructive operations (drops) require user confirmation.',
            parameters: [
                new EnumParameter(
                    'action',
                    'DDL action to perform',
                    values: ['create_table', 'add_column', 'modify_column', 'drop_column', 'create_index', 'drop_index', 'drop_table', 'rename_table', 'create_view', 'drop_view', 'create_database', 'drop_database'],
                    required: true,
                ),
                new StringParameter(
                    'table',
                    'Table, view, or database name to operate on',
                    required: false,
                ),
                new StringParameter(
                    'definition',
                    'Column definition, table DDL body, index expression, or view SELECT. Context depends on action. For create_table: JSON column definitions like [{"name":"id","type":"INT","auto_increment":true,"pk":true},{"name":"email","type":"VARCHAR(255)","nullable":false,"unique":true}] or raw SQL column definitions. For add_column/modify_column: "column_name TYPE [constraints]". For create_index: "col1, col2". For create_view: "SELECT ..." query.',
                    required: false,
                ),
                new StringParameter(
                    'new_name',
                    'New name for rename operations (rename_table)',
                    required: false,
                ),
                new StringParameter(
                    'options',
                    'JSON options for create_table: {"engine": "InnoDB", "charset": "utf8mb4", "collate": "utf8mb4_unicode_ci"}',
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
        $definition = (string) ($args['definition'] ?? '');
        $newName = (string) ($args['new_name'] ?? '');
        $optionsJson = (string) ($args['options'] ?? '');
        $database = (string) ($args['database'] ?? '');

        $options = [];
        if ($optionsJson !== '') {
            $decoded = json_decode($optionsJson, true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'create_table' => $this->createTable($db, $table, $definition, $options),
                'add_column' => $this->addColumn($db, $table, $definition),
                'modify_column' => $this->modifyColumn($db, $table, $definition),
                'drop_column' => $this->dropColumn($db, $table, $definition),
                'create_index' => $this->createIndex($db, $table, $definition),
                'drop_index' => $this->dropIndex($db, $table, $definition),
                'drop_table' => $this->dropTable($db, $table),
                'rename_table' => $this->renameTable($db, $table, $newName),
                'create_view' => $this->createView($db, $table, $definition),
                'drop_view' => $this->dropView($db, $table),
                'create_database' => $this->createDatabase($db, $table),
                'drop_database' => $this->dropDatabase($db, $table),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\PDOException $e) {
            return ToolResult::error(sprintf('Schema error: %s', $e->getMessage()));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createTable(\PDO $db, string $table, string $definition, array $options): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for create_table.');
        }
        if ($definition === '') {
            return ToolResult::error('Parameter "definition" is required for create_table. Provide JSON column array or raw SQL column definitions.');
        }

        $sql = $this->buildCreateTableSql($table, $definition, $options);
        $db->exec($sql);

        return ToolResult::success(sprintf("Table **%s** created.\n\n```sql\n%s\n```", $table, $sql));
    }

    private function addColumn(\PDO $db, string $table, string $definition): ToolResult
    {
        if ($table === '' || $definition === '') {
            return ToolResult::error('Parameters "table" and "definition" are required. Example: "email VARCHAR(255) NOT NULL DEFAULT \'\'"');
        }

        $sql = sprintf('ALTER TABLE %s ADD COLUMN %s', $this->quoteName($table), $definition);
        $db->exec($sql);

        return ToolResult::success(sprintf('Column added to **%s**: `%s`', $table, $definition));
    }

    private function modifyColumn(\PDO $db, string $table, string $definition): ToolResult
    {
        if ($table === '' || $definition === '') {
            return ToolResult::error('Parameters "table" and "definition" are required. Example: "email VARCHAR(512) NOT NULL"');
        }

        $sql = sprintf('ALTER TABLE %s MODIFY COLUMN %s', $this->quoteName($table), $definition);
        $db->exec($sql);

        return ToolResult::success(sprintf('Column modified on **%s**: `%s`', $table, $definition));
    }

    private function dropColumn(\PDO $db, string $table, string $column): ToolResult
    {
        if ($table === '' || $column === '') {
            return ToolResult::error('Parameters "table" and "definition" (column name) are required for drop_column.');
        }

        $sql = sprintf('ALTER TABLE %s DROP COLUMN %s', $this->quoteName($table), $this->quoteName($column));
        $db->exec($sql);

        return ToolResult::success(sprintf('Column `%s` dropped from **%s**.', $column, $table));
    }

    private function createIndex(\PDO $db, string $table, string $definition): ToolResult
    {
        if ($table === '' || $definition === '') {
            return ToolResult::error('Parameters "table" and "definition" are required. Provide column names: "col1, col2"');
        }

        $columns = $this->extractIndexColumns($definition);
        $indexName = sprintf('idx_%s_%s', $table, implode('_', $columns));

        $expression = str_contains($definition, '(') ? $definition : '(' . $definition . ')';

        $sql = sprintf('CREATE INDEX %s ON %s %s', $this->quoteName($indexName), $this->quoteName($table), $expression);
        $db->exec($sql);

        return ToolResult::success(sprintf("Index **%s** created on **%s**.\n\n```sql\n%s\n```", $indexName, $table, $sql));
    }

    private function dropIndex(\PDO $db, string $table, string $indexName): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for drop_index in MySQL.');
        }

        $name = $indexName !== '' ? $indexName : $table;

        $sql = sprintf('DROP INDEX %s ON %s', $this->quoteName($name), $this->quoteName($table));
        $db->exec($sql);

        return ToolResult::success(sprintf('Index **%s** dropped from **%s**.', $name, $table));
    }

    private function dropTable(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for drop_table.');
        }

        $sql = sprintf('DROP TABLE IF EXISTS %s', $this->quoteName($table));
        $db->exec($sql);

        return ToolResult::success(sprintf('Table **%s** dropped.', $table));
    }

    private function renameTable(\PDO $db, string $table, string $newName): ToolResult
    {
        if ($table === '' || $newName === '') {
            return ToolResult::error('Parameters "table" and "new_name" are required for rename_table.');
        }

        $sql = sprintf('RENAME TABLE %s TO %s', $this->quoteName($table), $this->quoteName($newName));
        $db->exec($sql);

        return ToolResult::success(sprintf('Table renamed: **%s** → **%s**', $table, $newName));
    }

    private function createView(\PDO $db, string $name, string $definition): ToolResult
    {
        if ($name === '' || $definition === '') {
            return ToolResult::error('Parameters "table" (view name) and "definition" (SELECT query) are required for create_view.');
        }

        $sql = sprintf('CREATE VIEW %s AS %s', $this->quoteName($name), $definition);
        $db->exec($sql);

        return ToolResult::success(sprintf("View **%s** created.\n\n```sql\n%s\n```", $name, $sql));
    }

    private function dropView(\PDO $db, string $name): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('Parameter "table" (view name) is required for drop_view.');
        }

        $sql = sprintf('DROP VIEW IF EXISTS %s', $this->quoteName($name));
        $db->exec($sql);

        return ToolResult::success(sprintf('View **%s** dropped.', $name));
    }

    private function createDatabase(\PDO $db, string $name): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('Parameter "table" (database name) is required for create_database.');
        }

        $sanitized = $this->inspector->sanitizeName($name);
        $sql = sprintf('CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->quoteName($sanitized));
        $db->exec($sql);

        return ToolResult::success(sprintf('Database **%s** created.', $sanitized));
    }

    private function dropDatabase(\PDO $db, string $name): ToolResult
    {
        if ($name === '') {
            return ToolResult::error('Parameter "table" (database name) is required for drop_database.');
        }

        $sanitized = $this->inspector->sanitizeName($name);
        $sql = sprintf('DROP DATABASE IF EXISTS %s', $this->quoteName($sanitized));
        $db->exec($sql);

        return ToolResult::success(sprintf('Database **%s** dropped.', $sanitized));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildCreateTableSql(string $table, string $definition, array $options): string
    {
        $engine = (string) ($options['engine'] ?? 'InnoDB');
        $charset = (string) ($options['charset'] ?? 'utf8mb4');
        $collate = (string) ($options['collate'] ?? 'utf8mb4_unicode_ci');

        // Try JSON column definitions first
        $columns = json_decode($definition, true);

        if (is_array($columns) && $columns !== [] && isset($columns[0]['name'])) {
            $colDefs = [];
            $pkColumns = [];

            foreach ($columns as $col) {
                $name = (string) ($col['name'] ?? '');
                $type = (string) ($col['type'] ?? 'VARCHAR(255)');
                $parts = [$this->quoteName($name), $type];

                if (!empty($col['unsigned'])) {
                    $parts[] = 'UNSIGNED';
                }

                if (!empty($col['auto_increment'])) {
                    $parts[] = 'AUTO_INCREMENT';
                }

                if (isset($col['nullable']) && $col['nullable'] === false) {
                    $parts[] = 'NOT NULL';
                } elseif (!empty($col['notnull'])) {
                    $parts[] = 'NOT NULL';
                }

                if (!empty($col['unique'])) {
                    $parts[] = 'UNIQUE';
                }

                if (array_key_exists('default', $col)) {
                    $default = $col['default'];
                    if (is_string($default)) {
                        $parts[] = sprintf("DEFAULT '%s'", str_replace("'", "''", $default));
                    } elseif (is_null($default)) {
                        $parts[] = 'DEFAULT NULL';
                    } else {
                        $parts[] = sprintf('DEFAULT %s', $default);
                    }
                }

                if (!empty($col['pk'])) {
                    $pkColumns[] = $name;
                }

                if (!empty($col['references'])) {
                    $parts[] = sprintf('REFERENCES %s', $col['references']);
                }

                $colDefs[] = implode(' ', $parts);
            }

            if ($pkColumns !== []) {
                $colDefs[] = sprintf('PRIMARY KEY (%s)', implode(', ', array_map($this->quoteName(...), $pkColumns)));
            }

            $body = implode(",\n    ", $colDefs);

            return sprintf(
                "CREATE TABLE %s (\n    %s\n) ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s",
                $this->quoteName($table),
                $body,
                $engine,
                $charset,
                $collate,
            );
        }

        // Raw SQL column definitions
        return sprintf(
            'CREATE TABLE %s (%s) ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s',
            $this->quoteName($table),
            $definition,
            $engine,
            $charset,
            $collate,
        );
    }

    /**
     * @return list<string>
     */
    private function extractIndexColumns(string $definition): array
    {
        $clean = preg_replace('/\bWHERE\b.*/i', '', $definition) ?? $definition;
        $clean = trim($clean, '() ');
        $parts = array_map('trim', explode(',', $clean));

        return array_map(
            fn(string $col): string => preg_replace('/[^a-zA-Z0-9_]/', '', $col) ?: 'col',
            $parts,
        );
    }

    private function quoteName(string $name): string
    {
        $name = trim($name, '`"\'[]');

        return '`' . str_replace('`', '``', $name) . '`';
    }
}
