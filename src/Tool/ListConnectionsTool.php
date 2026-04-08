<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;

final readonly class ListConnectionsTool
{
    public function __construct(
        private ConnectionManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_connections',
            description: 'List all open MySQL connections with alias, host, database, server version, table count, and which is active.',
            parameters: [],
            callback: fn(array $args): ToolResult => $this->execute(),
        );
    }

    private function execute(): ToolResult
    {
        try {
            $connections = $this->manager->list();

            if ($connections === []) {
                return ToolResult::success('No MySQL connections open. Use `mysql_connect` to connect to a server.');
            }

            $output = sprintf("**%d connection%s open:**\n\n", count($connections), count($connections) === 1 ? '' : 's');
            $output .= "| Alias | Host | Database | Version | Tables | Active | Transaction |\n";
            $output .= "| --- | --- | --- | --- | --- | --- | --- |\n";

            foreach ($connections as $conn) {
                $active = $conn['active'] ? '**→ yes**' : 'no';
                $tx = $conn['in_transaction'] ? 'yes' : 'no';
                $database = $conn['database'] !== '' ? $conn['database'] : '(none)';

                $output .= sprintf(
                    "| %s | %s:%d | %s | %s | %d | %s | %s |\n",
                    $conn['alias'],
                    $conn['host'],
                    $conn['port'],
                    $database,
                    $conn['server_version'],
                    $conn['tables'],
                    $active,
                    $tx,
                );
            }

            return ToolResult::success($output);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
