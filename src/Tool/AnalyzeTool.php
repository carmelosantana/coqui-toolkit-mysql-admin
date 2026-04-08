<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;

final readonly class AnalyzeTool
{
    public function __construct(
        private ConnectionManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_analyze',
            description: 'Analyze query performance: EXPLAIN for execution plans, EXPLAIN FORMAT=JSON for detailed cost analysis, suggest missing indexes, slow query analysis via performance_schema, and unused index detection.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Analysis action',
                    values: ['explain', 'explain_json', 'index_suggestions', 'slow_queries', 'unused_indexes', 'table_stats'],
                    required: true,
                ),
                new StringParameter(
                    'sql',
                    'SQL query to analyze (required for explain and explain_json)',
                    required: false,
                ),
                new StringParameter(
                    'table',
                    'Table name (required for index_suggestions)',
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
        $sql = (string) ($args['sql'] ?? '');
        $table = (string) ($args['table'] ?? '');
        $database = (string) ($args['database'] ?? '');

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'explain' => $this->explain($db, $sql),
                'explain_json' => $this->explainJson($db, $sql),
                'index_suggestions' => $this->indexSuggestions($db, $table),
                'slow_queries' => $this->slowQueries($db),
                'unused_indexes' => $this->unusedIndexes($db),
                'table_stats' => $this->tableStats($db, $table),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('Analysis error: %s', $e->getMessage()));
        }
    }

    private function explain(\PDO $db, string $sql): ToolResult
    {
        if ($sql === '') {
            return ToolResult::error('Parameter "sql" is required for explain.');
        }

        $stmt = $db->query('EXPLAIN ' . $sql);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows === []) {
            return ToolResult::success('No EXPLAIN output.');
        }

        $output = "**EXPLAIN output:**\n\n";
        $output .= "| id | select_type | table | type | possible_keys | key | key_len | ref | rows | filtered | Extra |\n";
        $output .= "| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";

        $hasFullScan = false;

        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            if ($type === 'ALL') {
                $hasFullScan = true;
            }

            $output .= sprintf(
                "| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |\n",
                $row['id'] ?? '',
                $row['select_type'] ?? '',
                $row['table'] ?? '',
                $type,
                $row['possible_keys'] ?? '',
                $row['key'] ?? '',
                $row['key_len'] ?? '',
                $row['ref'] ?? '',
                $row['rows'] ?? '',
                $row['filtered'] ?? '',
                $row['Extra'] ?? '',
            );
        }

        $output .= "\n**Assessment:** ";
        if ($hasFullScan) {
            $output .= "Full table scan detected (type=ALL). Consider adding an index on the WHERE/JOIN columns.";
        } else {
            $output .= "Query uses index-based access. Review 'rows' column for estimated cost.";
        }

        return ToolResult::success($output);
    }

    private function explainJson(\PDO $db, string $sql): ToolResult
    {
        if ($sql === '') {
            return ToolResult::error('Parameter "sql" is required for explain_json.');
        }

        $stmt = $db->query('EXPLAIN FORMAT=JSON ' . $sql);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $json = (string) ($result['EXPLAIN'] ?? '');

        if ($json === '') {
            return ToolResult::success('No EXPLAIN JSON output.');
        }

        // Parse for key metrics
        $decoded = json_decode($json, true);
        $cost = '';
        if (is_array($decoded) && isset($decoded['query_block']['cost_info']['query_cost'])) {
            $cost = sprintf("\n\n**Estimated query cost:** %s", $decoded['query_block']['cost_info']['query_cost']);
        }

        // Truncate if very long
        if (strlen($json) > 8000) {
            $json = substr($json, 0, 8000) . "\n... (truncated)";
        }

        return ToolResult::success(sprintf("**EXPLAIN FORMAT=JSON:**\n\n```json\n%s\n```%s", $json, $cost));
    }

    private function indexSuggestions(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for index_suggestions.');
        }

        $safeTable = str_replace('`', '``', $table);

        // Get columns
        $stmt = $db->prepare(
            'SELECT COLUMN_NAME, COLUMN_KEY, DATA_TYPE, IS_NULLABLE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
        );
        $stmt->execute([$table]);
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($columns === []) {
            return ToolResult::error(sprintf('Table "%s" not found.', $table));
        }

        // Get existing indexes
        $idxStmt = $db->query(sprintf('SHOW INDEX FROM `%s`', $safeTable));
        $indexes = $idxStmt->fetchAll(\PDO::FETCH_ASSOC);

        $indexedColumns = [];
        foreach ($indexes as $idx) {
            $indexedColumns[] = $idx['Column_name'];
        }

        // Get row count
        $rowCount = (int) $db->query(sprintf('SELECT COUNT(*) FROM `%s`', $safeTable))->fetchColumn();

        $suggestions = [];

        foreach ($columns as $col) {
            $colName = $col['COLUMN_NAME'];
            $colKey = $col['COLUMN_KEY'];

            // Skip primary keys and already-indexed columns
            if ($colKey === 'PRI' || in_array($colName, $indexedColumns, true)) {
                continue;
            }

            // Suggest indexes for common patterns
            if (str_contains($colName, '_id') || str_ends_with($colName, 'Id')) {
                $suggestions[] = sprintf(
                    'Foreign key column `%s` — `CREATE INDEX idx_%s_%s ON `%s`(`%s`)`',
                    $colName, $table, $colName, $safeTable, $colName,
                );
            } elseif (in_array($colName, ['email', 'username', 'slug', 'code', 'uuid', 'token'], true)) {
                $suggestions[] = sprintf(
                    'Lookup column `%s` — `CREATE UNIQUE INDEX idx_%s_%s ON `%s`(`%s`)`',
                    $colName, $table, $colName, $safeTable, $colName,
                );
            } elseif (str_contains($colName, 'date') || str_contains($colName, 'time') || str_ends_with($colName, '_at')) {
                $suggestions[] = sprintf(
                    'Timestamp column `%s` — `CREATE INDEX idx_%s_%s ON `%s`(`%s`)`',
                    $colName, $table, $colName, $safeTable, $colName,
                );
            } elseif (str_contains($colName, 'status') || str_contains($colName, 'type') || str_contains($colName, 'category')) {
                if ($rowCount > 100) {
                    $suggestions[] = sprintf(
                        'Filter column `%s` (%d rows) — `CREATE INDEX idx_%s_%s ON `%s`(`%s`)`',
                        $colName, $rowCount, $table, $colName, $safeTable, $colName,
                    );
                }
            }
        }

        $output = sprintf("**Index suggestions for %s** (%s rows, %d existing indexes):\n\n", $table, number_format($rowCount), count($indexes));

        if ($suggestions === []) {
            $output .= "No obvious index improvements detected. Current indexing appears adequate.";
        } else {
            foreach ($suggestions as $i => $suggestion) {
                $output .= sprintf("%d. %s\n", $i + 1, $suggestion);
            }
        }

        return ToolResult::success($output);
    }

    private function slowQueries(\PDO $db): ToolResult
    {
        // Check if performance_schema is available
        $stmt = $db->query("SHOW VARIABLES LIKE 'performance_schema'");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || strtolower((string) $row['Value']) !== 'on') {
            return ToolResult::error('performance_schema is not enabled. Enable it in my.cnf: performance_schema = ON');
        }

        $stmt = $db->query(
            'SELECT DIGEST_TEXT, COUNT_STAR AS exec_count,
                    ROUND(SUM_TIMER_WAIT / 1000000000000, 4) AS total_time_sec,
                    ROUND(AVG_TIMER_WAIT / 1000000000000, 4) AS avg_time_sec,
                    SUM_ROWS_EXAMINED AS rows_examined,
                    SUM_ROWS_SENT AS rows_sent
             FROM performance_schema.events_statements_summary_by_digest
             WHERE DIGEST_TEXT IS NOT NULL AND SCHEMA_NAME = DATABASE()
             ORDER BY SUM_TIMER_WAIT DESC
             LIMIT 10',
        );
        $queries = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($queries === []) {
            return ToolResult::success('No query digest data available. Run some queries and try again.');
        }

        $output = "**Top 10 Slowest Queries (by total time):**\n\n";
        $output .= "| # | Executions | Total (s) | Avg (s) | Rows Exam. | Rows Sent | Query |\n";
        $output .= "| --- | --- | --- | --- | --- | --- | --- |\n";

        foreach ($queries as $i => $q) {
            $queryText = (string) ($q['DIGEST_TEXT'] ?? '');
            if (strlen($queryText) > 80) {
                $queryText = substr($queryText, 0, 77) . '...';
            }
            $queryText = str_replace('|', '\\|', $queryText);

            $output .= sprintf(
                "| %d | %s | %s | %s | %s | %s | `%s` |\n",
                $i + 1,
                number_format((int) $q['exec_count']),
                $q['total_time_sec'],
                $q['avg_time_sec'],
                number_format((int) $q['rows_examined']),
                number_format((int) $q['rows_sent']),
                $queryText,
            );
        }

        return ToolResult::success($output);
    }

    private function unusedIndexes(\PDO $db): ToolResult
    {
        // Try sys.schema_unused_indexes (MySQL 8.0+)
        try {
            $stmt = $db->query(
                "SELECT object_schema, object_name, index_name
                 FROM sys.schema_unused_indexes
                 WHERE object_schema = DATABASE()
                 ORDER BY object_name, index_name",
            );
            $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return ToolResult::error('The sys.schema_unused_indexes view is not available. Requires MySQL 8.0+ with sys schema and performance_schema enabled.');
        }

        if ($indexes === []) {
            return ToolResult::success('No unused indexes detected. All indexes have been used since the last server restart.');
        }

        $output = "**Unused Indexes** (since last server restart):\n\n";
        $output .= "| Table | Index | Schema |\n";
        $output .= "| --- | --- | --- |\n";

        foreach ($indexes as $idx) {
            $output .= sprintf(
                "| %s | %s | %s |\n",
                $idx['object_name'],
                $idx['index_name'],
                $idx['object_schema'],
            );
        }

        $output .= sprintf("\n**Total unused indexes:** %d\n", count($indexes));
        $output .= "\nConsider dropping unused indexes to improve write performance. Verify with: `mysql_analyze(action: \"explain\", sql: \"YOUR QUERY\")`";

        return ToolResult::success($output);
    }

    private function tableStats(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            // Show all tables
            $stmt = $db->query(
                'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE, TABLE_COLLATION
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'
                 ORDER BY DATA_LENGTH DESC',
            );
            $tables = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($tables === []) {
                return ToolResult::success('No tables found.');
            }

            $output = "**All Table Statistics:**\n\n";
            $output .= "| Table | Est. Rows | Data Size | Index Size | Engine |\n";
            $output .= "| --- | --- | --- | --- | --- |\n";

            $totalData = 0;
            $totalIndex = 0;

            foreach ($tables as $t) {
                $dataLen = (int) $t['DATA_LENGTH'];
                $indexLen = (int) $t['INDEX_LENGTH'];
                $totalData += $dataLen;
                $totalIndex += $indexLen;

                $output .= sprintf(
                    "| %s | %s | %s | %s | %s |\n",
                    $t['TABLE_NAME'],
                    number_format((int) $t['TABLE_ROWS']),
                    $this->formatBytes($dataLen),
                    $this->formatBytes($indexLen),
                    $t['ENGINE'] ?? 'n/a',
                );
            }

            $output .= sprintf(
                "\n**Totals:** Data: %s, Index: %s, Combined: %s",
                $this->formatBytes($totalData),
                $this->formatBytes($totalIndex),
                $this->formatBytes($totalData + $totalIndex),
            );

            return ToolResult::success($output);
        }

        // Single table
        $stmt = $db->prepare(
            'SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, AVG_ROW_LENGTH, ENGINE, TABLE_COLLATION, CREATE_TIME, UPDATE_TIME, AUTO_INCREMENT
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        );
        $stmt->execute([$table]);
        $info = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$info) {
            return ToolResult::error(sprintf('Table "%s" not found.', $table));
        }

        $output = sprintf("**Table Statistics: %s**\n\n", $table);
        $output .= sprintf("- Est. rows: %s\n", number_format((int) $info['TABLE_ROWS']));
        $output .= sprintf("- Data size: %s\n", $this->formatBytes((int) $info['DATA_LENGTH']));
        $output .= sprintf("- Index size: %s\n", $this->formatBytes((int) $info['INDEX_LENGTH']));
        $output .= sprintf("- Avg row length: %s\n", $this->formatBytes((int) $info['AVG_ROW_LENGTH']));
        $output .= sprintf("- Engine: %s\n", $info['ENGINE'] ?? 'n/a');
        $output .= sprintf("- Collation: %s\n", $info['TABLE_COLLATION'] ?? 'n/a');
        $output .= sprintf("- Created: %s\n", $info['CREATE_TIME'] ?? 'n/a');
        $output .= sprintf("- Last updated: %s\n", $info['UPDATE_TIME'] ?? 'n/a');

        if ($info['AUTO_INCREMENT'] !== null) {
            $output .= sprintf("- Auto increment: %s\n", number_format((int) $info['AUTO_INCREMENT']));
        }

        // Column count
        $colStmt = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $colStmt->execute([$table]);
        $colCount = (int) $colStmt->fetchColumn();

        // Index count
        $safeTable = str_replace('`', '``', $table);
        $idxStmt = $db->query(sprintf('SELECT COUNT(DISTINCT Key_name) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'%s\'', str_replace("'", "''", $table)));
        $idxCount = (int) $idxStmt->fetchColumn();

        $output .= sprintf("- Columns: %d\n", $colCount);
        $output .= sprintf("- Indexes: %d\n", $idxCount);

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
