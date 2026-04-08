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

final readonly class SchemaTool
{
    public function __construct(
        private ConnectionManager $manager,
        private SchemaInspector $inspector,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_schema',
            description: 'Inspect MySQL schema: list databases, tables, describe columns, view indexes, foreign keys, triggers, views, routines (stored procedures/functions), DDL, server variables, active processes, and database/table statistics.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Schema inspection action',
                    values: ['databases', 'tables', 'describe', 'indexes', 'foreign_keys', 'triggers', 'views', 'routines', 'ddl', 'table_stats', 'db_stats', 'variables', 'processlist'],
                    required: true,
                ),
                new StringParameter(
                    'table',
                    'Table name (required for describe, indexes, foreign_keys, ddl, table_stats)',
                    required: false,
                ),
                new StringParameter(
                    'filter',
                    'Filter pattern for variables (e.g. "%innodb%") or processlist filtering',
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
        $filter = (string) ($args['filter'] ?? '');
        $database = (string) ($args['database'] ?? '');

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'databases' => $this->listDatabases($db),
                'tables' => $this->listTables($db),
                'describe' => $this->describeTable($db, $table),
                'indexes' => $this->listIndexes($db, $table),
                'foreign_keys' => $this->listForeignKeys($db, $table),
                'triggers' => $this->listTriggers($db, $table !== '' ? $table : null),
                'views' => $this->listViews($db),
                'routines' => $this->listRoutines($db),
                'ddl' => $this->showDdl($db, $table),
                'table_stats' => $this->tableStats($db, $table),
                'db_stats' => $this->dbStats($db),
                'variables' => $this->showVariables($db, $filter),
                'processlist' => $this->showProcesslist($db),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function listDatabases(\PDO $db): ToolResult
    {
        $databases = $this->inspector->getDatabases($db);

        if ($databases === []) {
            return ToolResult::success('No databases found.');
        }

        $currentDb = (string) $db->query('SELECT DATABASE()')->fetchColumn();

        $output = "| Database | Active |\n| --- | --- |\n";
        foreach ($databases as $name) {
            $active = $name === $currentDb ? '**→ yes**' : '';
            $output .= sprintf("| %s | %s |\n", $name, $active);
        }

        return ToolResult::success($output);
    }

    private function listTables(\PDO $db): ToolResult
    {
        $items = $this->inspector->getTables($db);

        if ($items === []) {
            return ToolResult::success('Database has no tables or views.');
        }

        $output = "| Name | Type | Engine | Est. Rows |\n| --- | --- | --- | --- |\n";
        foreach ($items as $item) {
            $output .= sprintf(
                "| %s | %s | %s | %s |\n",
                $item['TABLE_NAME'],
                $item['TABLE_TYPE'],
                $item['ENGINE'] ?? 'n/a',
                $item['TABLE_ROWS'] !== null ? number_format((int) $item['TABLE_ROWS']) : 'n/a',
            );
        }

        return ToolResult::success($output);
    }

    private function describeTable(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for "describe" action.');
        }

        $columns = $this->inspector->getTableInfo($db, $table);

        if ($columns === []) {
            return ToolResult::error(sprintf('Table "%s" not found or has no columns.', $table));
        }

        $output = sprintf("**Table: %s**\n\n", $table);
        $output .= "| # | Name | Type | Nullable | Default | Key | Extra |\n";
        $output .= "| --- | --- | --- | --- | --- | --- | --- |\n";

        foreach ($columns as $col) {
            $output .= sprintf(
                "| %d | %s | %s | %s | %s | %s | %s |\n",
                (int) $col['ORDINAL_POSITION'],
                $col['COLUMN_NAME'],
                $col['COLUMN_TYPE'],
                $col['IS_NULLABLE'],
                $col['COLUMN_DEFAULT'] ?? 'NULL',
                $col['COLUMN_KEY'] !== '' ? $col['COLUMN_KEY'] : '',
                $col['EXTRA'] !== '' ? $col['EXTRA'] : '',
            );
        }

        return ToolResult::success($output);
    }

    private function listIndexes(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for "indexes" action.');
        }

        $indexes = $this->inspector->getIndexes($db, $table);

        if ($indexes === []) {
            return ToolResult::success(sprintf('Table "%s" has no indexes.', $table));
        }

        // Group by index name
        $grouped = [];
        foreach ($indexes as $idx) {
            $name = $idx['Key_name'];
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'unique' => !((bool) $idx['Non_unique']),
                    'type' => $idx['Index_type'] ?? 'BTREE',
                    'columns' => [],
                ];
            }
            $grouped[$name]['columns'][] = $idx['Column_name'];
        }

        $output = sprintf("**Indexes on %s:**\n\n", $table);
        $output .= "| Name | Unique | Type | Columns |\n";
        $output .= "| --- | --- | --- | --- |\n";

        foreach ($grouped as $idx) {
            $output .= sprintf(
                "| %s | %s | %s | %s |\n",
                $idx['name'],
                $idx['unique'] ? 'yes' : 'no',
                $idx['type'],
                implode(', ', $idx['columns']),
            );
        }

        return ToolResult::success($output);
    }

    private function listForeignKeys(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for "foreign_keys" action.');
        }

        $fks = $this->inspector->getForeignKeys($db, $table);

        if ($fks === []) {
            return ToolResult::success(sprintf('Table "%s" has no foreign keys.', $table));
        }

        $output = sprintf("**Foreign keys on %s:**\n\n", $table);
        $output .= "| Constraint | Column | References Table | References Column | On Update | On Delete |\n";
        $output .= "| --- | --- | --- | --- | --- | --- |\n";

        foreach ($fks as $fk) {
            $output .= sprintf(
                "| %s | %s | %s | %s | %s | %s |\n",
                $fk['CONSTRAINT_NAME'],
                $fk['COLUMN_NAME'],
                $fk['REFERENCED_TABLE_NAME'],
                $fk['REFERENCED_COLUMN_NAME'],
                $fk['UPDATE_RULE'] ?? 'NO ACTION',
                $fk['DELETE_RULE'] ?? 'NO ACTION',
            );
        }

        return ToolResult::success($output);
    }

    private function listTriggers(\PDO $db, ?string $table): ToolResult
    {
        $triggers = $this->inspector->getTriggers($db, $table);

        if ($triggers === []) {
            $scope = $table !== null ? sprintf(' on table "%s"', $table) : '';

            return ToolResult::success(sprintf('No triggers found%s.', $scope));
        }

        $output = "**Triggers:**\n\n";
        $output .= "| Name | Event | Timing | Table |\n";
        $output .= "| --- | --- | --- | --- |\n";

        foreach ($triggers as $trigger) {
            $output .= sprintf(
                "| %s | %s | %s | %s |\n",
                $trigger['TRIGGER_NAME'],
                $trigger['EVENT_MANIPULATION'],
                $trigger['ACTION_TIMING'],
                $trigger['EVENT_OBJECT_TABLE'],
            );
        }

        return ToolResult::success($output);
    }

    private function listViews(\PDO $db): ToolResult
    {
        $views = $this->inspector->getViews($db);

        if ($views === []) {
            return ToolResult::success('No views found.');
        }

        $output = "**Views:**\n\n";
        foreach ($views as $view) {
            $def = $view['VIEW_DEFINITION'] !== '' ? $view['VIEW_DEFINITION'] : '(definition not accessible)';
            $output .= sprintf("### %s\n```sql\n%s\n```\n\n", $view['TABLE_NAME'], $def);
        }

        return ToolResult::success($output);
    }

    private function listRoutines(\PDO $db): ToolResult
    {
        $routines = $this->inspector->getRoutines($db);

        if ($routines === []) {
            return ToolResult::success('No stored procedures or functions found.');
        }

        $output = "**Routines:**\n\n";
        $output .= "| Name | Type | Return Type | Created | Last Altered |\n";
        $output .= "| --- | --- | --- | --- | --- |\n";

        foreach ($routines as $routine) {
            $output .= sprintf(
                "| %s | %s | %s | %s | %s |\n",
                $routine['ROUTINE_NAME'],
                $routine['ROUTINE_TYPE'],
                $routine['DATA_TYPE'] ?? 'n/a',
                $routine['CREATED'] ?? '',
                $routine['LAST_ALTERED'] ?? '',
            );
        }

        return ToolResult::success($output);
    }

    private function showDdl(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for "ddl" action.');
        }

        $ddl = $this->inspector->getTableDdl($db, $table);

        if ($ddl === '') {
            return ToolResult::error(sprintf('Table or view "%s" not found.', $table));
        }

        return ToolResult::success(sprintf("```sql\n%s;\n```", $ddl));
    }

    private function tableStats(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for "table_stats" action.');
        }

        $stats = $this->inspector->getTableStats($db, $table);

        $output = sprintf("**Table: %s**\n\n", $table);
        $output .= sprintf("- Engine: %s\n", $stats['engine']);
        $output .= sprintf("- Collation: %s\n", $stats['collation']);
        $output .= sprintf("- Est. Rows: %s\n", number_format($stats['row_count']));
        $output .= sprintf("- Data size: %s\n", $this->formatBytes($stats['data_length']));
        $output .= sprintf("- Index size: %s\n", $this->formatBytes($stats['index_length']));
        $output .= sprintf("- Columns: %d\n", $stats['column_count']);
        $output .= sprintf("- Indexes: %d\n", $stats['index_count']);
        $output .= sprintf("- Foreign keys: %d\n", $stats['foreign_key_count']);
        $output .= sprintf("- Triggers: %d\n", $stats['trigger_count']);

        if ($stats['auto_increment'] !== null) {
            $output .= sprintf("- Next AUTO_INCREMENT: %d\n", $stats['auto_increment']);
        }

        return ToolResult::success($output);
    }

    private function dbStats(\PDO $db): ToolResult
    {
        $stats = $this->inspector->getDatabaseStats($db);
        $allTables = $this->inspector->getAllTableStats($db);
        $currentDb = (string) $db->query('SELECT DATABASE()')->fetchColumn();

        $output = sprintf("**Database: %s**\n\n", $currentDb !== '' ? $currentDb : '(none)');
        $output .= sprintf("- Tables: %d\n", $stats['table_count']);
        $output .= sprintf("- Total rows: %s\n", number_format($stats['total_rows']));
        $output .= sprintf("- Data size: %s\n", $this->formatBytes($stats['data_size_bytes']));
        $output .= sprintf("- Index size: %s\n", $this->formatBytes($stats['index_size_bytes']));
        $output .= sprintf("- Total size: %s\n\n", $this->formatBytes($stats['total_size_bytes']));

        if ($allTables !== []) {
            $output .= "| Table | Est. Rows | Data Size | Index Size | Engine |\n";
            $output .= "| --- | --- | --- | --- | --- |\n";
            foreach ($allTables as $t) {
                $output .= sprintf(
                    "| %s | %s | %s | %s | %s |\n",
                    $t['table'],
                    number_format($t['rows']),
                    $this->formatBytes($t['data_size']),
                    $this->formatBytes($t['index_size']),
                    $t['engine'],
                );
            }
        }

        return ToolResult::success($output);
    }

    private function showVariables(\PDO $db, string $filter): ToolResult
    {
        $sql = 'SHOW GLOBAL VARIABLES';
        if ($filter !== '') {
            $sql .= sprintf(" LIKE '%s'", str_replace("'", "''", $filter));
        }

        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return ToolResult::success('No variables found matching the filter.');
        }

        $output = sprintf("**Server Variables%s:**\n\n", $filter !== '' ? sprintf(' (filter: %s)', $filter) : '');
        $output .= "| Variable | Value |\n| --- | --- |\n";

        foreach ($rows as $row) {
            $value = (string) ($row['Value'] ?? '');
            if (mb_strlen($value) > 80) {
                $value = mb_substr($value, 0, 77) . '...';
            }
            $output .= sprintf("| %s | `%s` |\n", $row['Variable_name'] ?? '', $value);
        }

        return ToolResult::success($output);
    }

    private function showProcesslist(\PDO $db): ToolResult
    {
        $stmt = $db->query('SHOW PROCESSLIST');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return ToolResult::success('No active processes.');
        }

        $output = "**Active Processes:**\n\n";
        $output .= "| ID | User | Host | DB | Command | Time | State | Info |\n";
        $output .= "| --- | --- | --- | --- | --- | --- | --- | --- |\n";

        foreach ($rows as $row) {
            $info = (string) ($row['Info'] ?? '');
            if (mb_strlen($info) > 60) {
                $info = mb_substr($info, 0, 57) . '...';
            }

            $output .= sprintf(
                "| %s | %s | %s | %s | %s | %s | %s | %s |\n",
                $row['Id'] ?? '',
                $row['User'] ?? '',
                $row['Host'] ?? '',
                $row['db'] ?? '',
                $row['Command'] ?? '',
                $row['Time'] ?? '',
                $row['State'] ?? '',
                $info,
            );
        }

        return ToolResult::success($output);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1_048_576) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        if ($bytes < 1_073_741_824) {
            return sprintf('%.1f MB', $bytes / 1_048_576);
        }

        return sprintf('%.1f GB', $bytes / 1_073_741_824);
    }
}
