<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Runtime;

use CarmeloSantana\PHPAgents\Tool\ToolResult;

final readonly class QueryResult
{
    private const int MAX_OUTPUT_BYTES = 65_536;

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columnNames
     */
    public function __construct(
        public array $rows,
        public int $rowCount,
        public array $columnNames,
        public float $executionTimeMs,
        public ?string $lastInsertId = null,
        public int $affectedRows = 0,
        public bool $isWrite = false,
    ) {}

    public function toToolResult(): ToolResult
    {
        if ($this->isWrite) {
            return ToolResult::success($this->formatWriteResult());
        }

        return ToolResult::success($this->formatReadResult());
    }

    private function formatWriteResult(): string
    {
        $parts = [];
        $parts[] = sprintf('**Query executed successfully** (%.1fms)', $this->executionTimeMs);

        if ($this->affectedRows > 0) {
            $parts[] = sprintf('Rows affected: %d', $this->affectedRows);
        }

        if ($this->lastInsertId !== null && $this->lastInsertId !== '0') {
            $parts[] = sprintf('Last insert ID: %s', $this->lastInsertId);
        }

        return implode("\n", $parts);
    }

    private function formatReadResult(): string
    {
        if ($this->rowCount === 0) {
            return sprintf('No results returned. (%.1fms)', $this->executionTimeMs);
        }

        $output = sprintf("**%d row%s** (%.1fms)\n\n", $this->rowCount, $this->rowCount === 1 ? '' : 's', $this->executionTimeMs);

        // Build markdown table
        $output .= '| ' . implode(' | ', $this->columnNames) . " |\n";
        $output .= '| ' . implode(' | ', array_fill(0, count($this->columnNames), '---')) . " |\n";

        foreach ($this->rows as $row) {
            $cells = [];
            foreach ($this->columnNames as $col) {
                $value = $row[$col] ?? '';
                $cell = $this->formatCell($value);
                $cells[] = $cell;
            }
            $output .= '| ' . implode(' | ', $cells) . " |\n";

            if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                $output .= sprintf("\n*[Output truncated — showing partial results of %d rows]*", $this->rowCount);
                break;
            }
        }

        return $output;
    }

    private function formatCell(mixed $value): string
    {
        if ($value === null) {
            return '`NULL`';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $str = (string) $value;

        // Escape pipe characters in markdown tables
        $str = str_replace('|', '\\|', $str);

        // Truncate long cell values
        if (mb_strlen($str) > 100) {
            $str = mb_substr($str, 0, 97) . '...';
        }

        // Replace newlines with spaces for table display
        $str = str_replace(["\r\n", "\r", "\n"], ' ', $str);

        return $str;
    }
}
