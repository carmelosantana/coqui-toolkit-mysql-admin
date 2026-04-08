<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;

final readonly class OptimizeTool
{
    public function __construct(
        private ConnectionManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_optimize',
            description: 'Database maintenance: OPTIMIZE TABLE to reclaim space and defragment, ANALYZE TABLE to update statistics, CHECK TABLE for corruption detection, REPAIR TABLE, FLUSH operations, and InnoDB status.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Maintenance action',
                    values: ['optimize', 'analyze', 'check', 'repair', 'flush', 'innodb_status', 'variable_report'],
                    required: true,
                ),
                new StringParameter(
                    'table',
                    'Target table (required for optimize, analyze, check, repair). Comma-separated for multiple tables.',
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
        $database = (string) ($args['database'] ?? '');

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'optimize' => $this->optimizeTable($db, $table),
                'analyze' => $this->analyzeTable($db, $table),
                'check' => $this->checkTable($db, $table),
                'repair' => $this->repairTable($db, $table),
                'flush' => $this->flush($db),
                'innodb_status' => $this->innodbStatus($db),
                'variable_report' => $this->variableReport($db),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('Optimize error: %s', $e->getMessage()));
        }
    }

    private function optimizeTable(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for optimize. Provide table name(s).');
        }

        $tables = $this->quoteTables($table);
        $stmt = $db->query(sprintf('OPTIMIZE TABLE %s', $tables));
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ToolResult::success($this->formatMaintenanceResults('OPTIMIZE TABLE', $results));
    }

    private function analyzeTable(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for analyze. Provide table name(s).');
        }

        $tables = $this->quoteTables($table);
        $stmt = $db->query(sprintf('ANALYZE TABLE %s', $tables));
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ToolResult::success($this->formatMaintenanceResults('ANALYZE TABLE', $results));
    }

    private function checkTable(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for check. Provide table name(s).');
        }

        $tables = $this->quoteTables($table);
        $stmt = $db->query(sprintf('CHECK TABLE %s', $tables));
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ToolResult::success($this->formatMaintenanceResults('CHECK TABLE', $results));
    }

    private function repairTable(\PDO $db, string $table): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for repair. Provide table name(s).');
        }

        $tables = $this->quoteTables($table);
        $stmt = $db->query(sprintf('REPAIR TABLE %s', $tables));
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ToolResult::success($this->formatMaintenanceResults('REPAIR TABLE', $results));
    }

    private function flush(\PDO $db): ToolResult
    {
        $db->exec('FLUSH TABLES');

        // Get buffer pool info
        $stmt = $db->query("SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool%'");
        $stats = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $stats[$row['Variable_name']] = $row['Value'];
        }

        $output = "**FLUSH TABLES** completed.\n\n**Buffer Pool Status:**\n\n";
        $output .= sprintf("- Pages total: %s\n", number_format((int) ($stats['Innodb_buffer_pool_pages_total'] ?? 0)));
        $output .= sprintf("- Pages free: %s\n", number_format((int) ($stats['Innodb_buffer_pool_pages_free'] ?? 0)));
        $output .= sprintf("- Pages dirty: %s\n", number_format((int) ($stats['Innodb_buffer_pool_pages_dirty'] ?? 0)));
        $output .= sprintf("- Read requests: %s\n", number_format((int) ($stats['Innodb_buffer_pool_read_requests'] ?? 0)));
        $output .= sprintf("- Reads (misses): %s\n", number_format((int) ($stats['Innodb_buffer_pool_reads'] ?? 0)));

        $requests = (int) ($stats['Innodb_buffer_pool_read_requests'] ?? 0);
        $reads = (int) ($stats['Innodb_buffer_pool_reads'] ?? 0);
        if ($requests > 0) {
            $hitRatio = (($requests - $reads) / $requests) * 100;
            $output .= sprintf("- **Hit ratio: %.2f%%**\n", $hitRatio);
        }

        return ToolResult::success($output);
    }

    private function innodbStatus(\PDO $db): ToolResult
    {
        $stmt = $db->query('SHOW ENGINE INNODB STATUS');
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $status = (string) ($result['Status'] ?? '');

        // Truncate if very long
        if (strlen($status) > 10000) {
            $status = substr($status, 0, 10000) . "\n\n... (truncated)";
        }

        return ToolResult::success(sprintf("**InnoDB Engine Status:**\n\n```\n%s\n```", $status));
    }

    private function variableReport(\PDO $db): ToolResult
    {
        $variables = [
            'innodb_buffer_pool_size' => 'Buffer Pool Size',
            'innodb_log_file_size' => 'Log File Size',
            'innodb_flush_log_at_trx_commit' => 'Log Flush at Commit',
            'innodb_file_per_table' => 'File Per Table',
            'innodb_flush_method' => 'Flush Method',
            'max_connections' => 'Max Connections',
            'thread_cache_size' => 'Thread Cache Size',
            'table_open_cache' => 'Table Open Cache',
            'query_cache_size' => 'Query Cache Size',
            'query_cache_type' => 'Query Cache Type',
            'tmp_table_size' => 'Temp Table Size',
            'max_heap_table_size' => 'Max Heap Table Size',
            'sort_buffer_size' => 'Sort Buffer Size',
            'join_buffer_size' => 'Join Buffer Size',
            'read_buffer_size' => 'Read Buffer Size',
            'read_rnd_buffer_size' => 'Random Read Buffer',
            'key_buffer_size' => 'Key Buffer Size',
            'wait_timeout' => 'Wait Timeout',
            'interactive_timeout' => 'Interactive Timeout',
            'slow_query_log' => 'Slow Query Log',
            'long_query_time' => 'Long Query Time',
        ];

        $output = "**MySQL Configuration Report:**\n\n";
        $output .= "| Variable | Value | Description |\n";
        $output .= "| --- | --- | --- |\n";

        foreach ($variables as $name => $label) {
            $stmt = $db->query(sprintf("SHOW GLOBAL VARIABLES LIKE '%s'", $name));
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $value = $row ? (string) $row['Value'] : 'n/a';

            // Format byte values
            if (str_ends_with($name, '_size') && is_numeric($value)) {
                $value = $this->formatBytes((int) $value);
            }

            $output .= sprintf("| %s | `%s` | %s |\n", $name, $value, $label);
        }

        // Add recommendations
        $recommendations = $this->getRecommendations($db);
        if ($recommendations !== []) {
            $output .= "\n**Recommendations:**\n\n";
            foreach ($recommendations as $rec) {
                $output .= sprintf("- %s\n", $rec);
            }
        }

        return ToolResult::success($output);
    }

    /**
     * @param list<array<string,mixed>> $results
     */
    private function formatMaintenanceResults(string $operation, array $results): string
    {
        $output = sprintf("**%s results:**\n\n", $operation);
        $output .= "| Table | Op | Msg_type | Msg_text |\n";
        $output .= "| --- | --- | --- | --- |\n";

        foreach ($results as $row) {
            $output .= sprintf(
                "| %s | %s | %s | %s |\n",
                $row['Table'] ?? '',
                $row['Op'] ?? '',
                $row['Msg_type'] ?? '',
                $row['Msg_text'] ?? '',
            );
        }

        return $output;
    }

    /**
     * @return list<string>
     */
    private function getRecommendations(\PDO $db): array
    {
        $recs = [];

        $bufferPool = $this->getVariable($db, 'innodb_buffer_pool_size');
        if ($bufferPool !== null && $bufferPool < 134_217_728) {
            $recs[] = sprintf(
                '**Increase innodb_buffer_pool_size** (currently %s). Recommended: 50-75%% of available RAM for dedicated MySQL servers.',
                $this->formatBytes($bufferPool),
            );
        }

        $slowLog = $this->getVariable($db, 'slow_query_log');
        if ($slowLog !== null && $slowLog === 0) {
            $recs[] = '**Enable slow query log** (`SET GLOBAL slow_query_log = 1`) to identify slow queries.';
        }

        $filePerTable = $this->getVariable($db, 'innodb_file_per_table');
        if ($filePerTable !== null && $filePerTable === 0) {
            $recs[] = '**Enable innodb_file_per_table** for better tablespace management and OPTIMIZE TABLE support.';
        }

        return $recs;
    }

    private function getVariable(\PDO $db, string $name): ?int
    {
        $stmt = $db->query(sprintf("SHOW GLOBAL VARIABLES LIKE '%s'", $name));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row && is_numeric($row['Value']) ? (int) $row['Value'] : null;
    }

    /** Quote comma-separated table names with backticks */
    private function quoteTables(string $table): string
    {
        $tables = array_map('trim', explode(',', $table));

        return implode(', ', array_map(
            fn(string $t): string => '`' . str_replace('`', '``', $t) . '`',
            $tables,
        ));
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
