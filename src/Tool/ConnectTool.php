<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;

final readonly class ConnectTool
{
    public function __construct(
        private ConnectionManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_connect',
            description: 'Connect to a MySQL server. The connection becomes the active connection. Multiple servers can be connected simultaneously (max 10).',
            parameters: [
                new StringParameter(
                    'host',
                    'MySQL server hostname or IP address (e.g. localhost, db.example.com)',
                    required: true,
                ),
                new StringParameter(
                    'user',
                    'MySQL username for authentication',
                    required: true,
                ),
                new StringParameter(
                    'password',
                    'MySQL password for authentication',
                    required: true,
                ),
                new StringParameter(
                    'database',
                    'Database name to select on connect (optional — can switch later)',
                    required: false,
                ),
                new NumberParameter(
                    'port',
                    'MySQL server port (default: 3306)',
                    required: false,
                ),
                new StringParameter(
                    'alias',
                    'Short name for this connection (auto-generated as "database@host" if omitted)',
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $host = (string) ($args['host'] ?? '');
        $user = (string) ($args['user'] ?? '');
        $password = (string) ($args['password'] ?? '');
        $database = (string) ($args['database'] ?? '');
        $port = (int) ($args['port'] ?? 3306);
        $alias = (string) ($args['alias'] ?? '');

        if ($host === '' || $user === '') {
            return ToolResult::error('Parameters "host" and "user" are required.');
        }

        try {
            $connectedAlias = $this->manager->connect($host, $user, $password, $database, $port, $alias);
            $db = $this->manager->getConnection($connectedAlias);

            // Gather connection info
            $serverVersion = $db->getAttribute(\PDO::ATTR_SERVER_VERSION);

            $tableCount = 0;
            $dbName = '';
            try {
                $dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
                if ($dbName !== '') {
                    $stmt = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()");
                    $tableCount = (int) $stmt->fetchColumn();
                }
            } catch (\PDOException) {
                // No database selected — that's fine
            }

            $info = sprintf(
                'Connected to **%s** (MySQL %s)',
                $connectedAlias,
                is_string($serverVersion) ? $serverVersion : 'unknown',
            );

            if ($dbName !== '') {
                $info .= sprintf(' — database `%s` with %d table%s', $dbName, $tableCount, $tableCount === 1 ? '' : 's');
            } else {
                $info .= ' — no database selected';
            }

            $info .= '. This is now the active connection.';

            return ToolResult::success($info);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
