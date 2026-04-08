<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\QueryResult;

final readonly class QueryTool
{
    private const int MAX_ROWS = 1_000;

    public function __construct(
        private ConnectionManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_query',
            description: 'Execute a SQL query against the active (or specified) MySQL connection. SELECT queries return results as a markdown table. INSERT/UPDATE/DELETE return affected row counts. Use "params" for parameterized queries to prevent SQL injection.',
            parameters: [
                new StringParameter(
                    'sql',
                    'The SQL query to execute. Supports SELECT, INSERT, UPDATE, DELETE, and other valid MySQL SQL.',
                    required: true,
                ),
                new StringParameter(
                    'database',
                    'Connection alias to query (defaults to active connection)',
                    required: false,
                ),
                new StringParameter(
                    'params',
                    'JSON-encoded parameters for prepared statements. Use named (:name) or positional (?) placeholders. Example: {"name": "John", "age": 30}',
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $sql = trim((string) ($args['sql'] ?? ''));
        $database = (string) ($args['database'] ?? '');
        $paramsJson = (string) ($args['params'] ?? '');

        if ($sql === '') {
            return ToolResult::error('Parameter "sql" is required.');
        }

        $params = [];
        if ($paramsJson !== '') {
            $decoded = json_decode($paramsJson, true);
            if (!is_array($decoded)) {
                return ToolResult::error('Parameter "params" must be valid JSON. Example: {"name": "John"}');
            }
            $params = $decoded;
        }

        try {
            $db = $this->manager->resolveConnection($database);
            $isWrite = $this->isWriteQuery($sql);

            // Inject LIMIT for unbounded SELECT queries
            if (!$isWrite && $this->needsLimit($sql)) {
                $sql = rtrim($sql, "; \t\n\r") . sprintf(' LIMIT %d', self::MAX_ROWS);
            }

            $startTime = hrtime(true);

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $executionTimeMs = (hrtime(true) - $startTime) / 1_000_000;

            if ($isWrite) {
                $result = new QueryResult(
                    rows: [],
                    rowCount: 0,
                    columnNames: [],
                    executionTimeMs: $executionTimeMs,
                    lastInsertId: $db->lastInsertId() ?: null,
                    affectedRows: $stmt->rowCount(),
                    isWrite: true,
                );
            } else {
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $columnNames = $rows !== [] ? array_keys($rows[0]) : [];

                $result = new QueryResult(
                    rows: $rows,
                    rowCount: count($rows),
                    columnNames: $columnNames,
                    executionTimeMs: $executionTimeMs,
                );
            }

            return $result->toToolResult();
        } catch (\PDOException $e) {
            return ToolResult::error(sprintf('SQL error: %s', $e->getMessage()));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function isWriteQuery(string $sql): bool
    {
        $normalized = strtoupper(ltrim($sql));

        foreach (['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'RENAME', 'LOAD', 'GRANT', 'REVOKE'] as $keyword) {
            if (str_starts_with($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function needsLimit(string $sql): bool
    {
        $normalized = strtoupper(ltrim($sql));

        if (!str_starts_with($normalized, 'SELECT')) {
            return false;
        }

        if (preg_match('/\bLIMIT\s+\d+/i', $sql) === 1) {
            return false;
        }

        if (!preg_match('/\bFROM\b/i', $sql)) {
            return false;
        }

        return true;
    }
}
