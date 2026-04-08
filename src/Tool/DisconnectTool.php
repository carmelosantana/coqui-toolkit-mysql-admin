<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;

final readonly class DisconnectTool
{
    public function __construct(
        private ConnectionManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_disconnect',
            description: 'Close a MySQL connection. If the disconnected connection was active, the next available connection becomes active.',
            parameters: [
                new StringParameter(
                    'alias',
                    'Alias of the connection to disconnect',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $alias = (string) ($args['alias'] ?? '');

        if ($alias === '') {
            return ToolResult::error('Parameter "alias" is required.');
        }

        try {
            $meta = $this->manager->getMetadata($alias);
            $this->manager->disconnect($alias);

            $remaining = $this->manager->connectionCount();
            $activeInfo = '';
            if ($remaining > 0 && $this->manager->activeAlias() !== '') {
                $activeInfo = sprintf(' Active connection is now **%s**.', $this->manager->activeAlias());
            }

            return ToolResult::success(sprintf(
                'Disconnected from **%s** (`%s@%s`). %d connection%s remaining.%s',
                $alias,
                $meta['user'],
                $meta['host'],
                $remaining,
                $remaining === 1 ? '' : 's',
                $activeInfo,
            ));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
