<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;

final readonly class UserTool
{
    public function __construct(
        private ConnectionManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_user',
            description: 'Manage MySQL users and privileges: list users, create/drop users, grant/revoke privileges, show grants, and set passwords.',
            parameters: [
                new EnumParameter(
                    'action',
                    'User management action',
                    values: ['list', 'create', 'drop', 'grant', 'revoke', 'show_grants', 'set_password'],
                    required: true,
                ),
                new StringParameter(
                    'user',
                    'MySQL username (required for all actions except list)',
                    required: false,
                ),
                new StringParameter(
                    'host',
                    'User host (default: %)',
                    required: false,
                ),
                new StringParameter(
                    'password',
                    'Password for create or set_password actions',
                    required: false,
                ),
                new StringParameter(
                    'privileges',
                    'Comma-separated privileges for grant/revoke (e.g. SELECT,INSERT,UPDATE or ALL PRIVILEGES)',
                    required: false,
                ),
                new StringParameter(
                    'on_database',
                    'Database.table for grant/revoke scope (e.g. mydb.* or *.*). Defaults to *.*',
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
        $user = (string) ($args['user'] ?? '');
        $host = (string) ($args['host'] ?? '%');
        $password = (string) ($args['password'] ?? '');
        $privileges = (string) ($args['privileges'] ?? '');
        $onDatabase = (string) ($args['on_database'] ?? '*.*');
        $database = (string) ($args['database'] ?? '');

        try {
            $db = $this->manager->resolveConnection($database);

            return match ($action) {
                'list' => $this->listUsers($db),
                'create' => $this->createUser($db, $user, $host, $password),
                'drop' => $this->dropUser($db, $user, $host),
                'grant' => $this->grantPrivileges($db, $user, $host, $privileges, $onDatabase),
                'revoke' => $this->revokePrivileges($db, $user, $host, $privileges, $onDatabase),
                'show_grants' => $this->showGrants($db, $user, $host),
                'set_password' => $this->setPassword($db, $user, $host, $password),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('User management error: %s', $e->getMessage()));
        }
    }

    private function listUsers(\PDO $db): ToolResult
    {
        $stmt = $db->query('SELECT User, Host, account_locked, password_expired FROM mysql.user ORDER BY User, Host');
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($users === []) {
            return ToolResult::success('No users found.');
        }

        $output = "**MySQL Users:**\n\n";
        $output .= "| User | Host | Locked | Password Expired |\n";
        $output .= "| --- | --- | --- | --- |\n";

        foreach ($users as $u) {
            $output .= sprintf(
                "| %s | %s | %s | %s |\n",
                $u['User'],
                $u['Host'],
                $u['account_locked'] ?? 'N',
                $u['password_expired'] ?? 'N',
            );
        }

        $output .= sprintf("\n**Total users:** %d", count($users));

        return ToolResult::success($output);
    }

    private function createUser(\PDO $db, string $user, string $host, string $password): ToolResult
    {
        if ($user === '') {
            return ToolResult::error('Parameter "user" is required for create.');
        }
        if ($password === '') {
            return ToolResult::error('Parameter "password" is required for create.');
        }

        $stmt = $db->prepare('CREATE USER ?@? IDENTIFIED BY ?');
        $stmt->execute([$user, $host, $password]);

        return ToolResult::success(sprintf(
            "User **%s@%s** created.\n\nGrant privileges: `mysql_user(action: \"grant\", user: \"%s\", privileges: \"SELECT,INSERT\", on_database: \"mydb.*\")`",
            $user,
            $host,
            $user,
        ));
    }

    private function dropUser(\PDO $db, string $user, string $host): ToolResult
    {
        if ($user === '') {
            return ToolResult::error('Parameter "user" is required for drop.');
        }

        $stmt = $db->prepare('DROP USER ?@?');
        $stmt->execute([$user, $host]);

        return ToolResult::success(sprintf('User **%s@%s** dropped.', $user, $host));
    }

    private function grantPrivileges(\PDO $db, string $user, string $host, string $privileges, string $onDatabase): ToolResult
    {
        if ($user === '') {
            return ToolResult::error('Parameter "user" is required for grant.');
        }
        if ($privileges === '') {
            return ToolResult::error('Parameter "privileges" is required for grant (e.g. SELECT,INSERT,UPDATE or ALL PRIVILEGES).');
        }

        // Validate privilege names
        $validPrivileges = $this->validatePrivileges($privileges);
        if ($validPrivileges === null) {
            return ToolResult::error('Invalid privilege names. Use standard MySQL privileges: SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, EXECUTE, ALL PRIVILEGES, etc.');
        }

        $safeOn = $this->sanitizeGrantTarget($onDatabase);

        // GRANT cannot use parameterized queries for privilege/database names
        $sql = sprintf(
            'GRANT %s ON %s TO %s@%s',
            $validPrivileges,
            $safeOn,
            $db->quote($user),
            $db->quote($host),
        );
        $db->exec($sql);

        return ToolResult::success(sprintf(
            'Granted **%s** on **%s** to **%s@%s**.',
            $validPrivileges,
            $safeOn,
            $user,
            $host,
        ));
    }

    private function revokePrivileges(\PDO $db, string $user, string $host, string $privileges, string $onDatabase): ToolResult
    {
        if ($user === '') {
            return ToolResult::error('Parameter "user" is required for revoke.');
        }
        if ($privileges === '') {
            return ToolResult::error('Parameter "privileges" is required for revoke.');
        }

        $validPrivileges = $this->validatePrivileges($privileges);
        if ($validPrivileges === null) {
            return ToolResult::error('Invalid privilege names.');
        }

        $safeOn = $this->sanitizeGrantTarget($onDatabase);

        $sql = sprintf(
            'REVOKE %s ON %s FROM %s@%s',
            $validPrivileges,
            $safeOn,
            $db->quote($user),
            $db->quote($host),
        );
        $db->exec($sql);

        return ToolResult::success(sprintf(
            'Revoked **%s** on **%s** from **%s@%s**.',
            $validPrivileges,
            $safeOn,
            $user,
            $host,
        ));
    }

    private function showGrants(\PDO $db, string $user, string $host): ToolResult
    {
        if ($user === '') {
            return ToolResult::error('Parameter "user" is required for show_grants.');
        }

        $stmt = $db->prepare('SHOW GRANTS FOR ?@?');
        $stmt->execute([$user, $host]);
        $grants = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if ($grants === []) {
            return ToolResult::success(sprintf('No grants found for **%s@%s**.', $user, $host));
        }

        $output = sprintf("**Grants for %s@%s:**\n\n", $user, $host);
        foreach ($grants as $grant) {
            $output .= sprintf("```sql\n%s\n```\n\n", $grant);
        }

        return ToolResult::success($output);
    }

    private function setPassword(\PDO $db, string $user, string $host, string $password): ToolResult
    {
        if ($user === '') {
            return ToolResult::error('Parameter "user" is required for set_password.');
        }
        if ($password === '') {
            return ToolResult::error('Parameter "password" is required for set_password.');
        }

        $stmt = $db->prepare('ALTER USER ?@? IDENTIFIED BY ?');
        $stmt->execute([$user, $host, $password]);

        return ToolResult::success(sprintf('Password updated for **%s@%s**.', $user, $host));
    }

    private function validatePrivileges(string $privileges): ?string
    {
        $allowed = [
            'ALL', 'ALL PRIVILEGES', 'ALTER', 'ALTER ROUTINE', 'CREATE', 'CREATE ROUTINE',
            'CREATE TABLESPACE', 'CREATE TEMPORARY TABLES', 'CREATE USER', 'CREATE VIEW',
            'DELETE', 'DROP', 'EVENT', 'EXECUTE', 'FILE', 'GRANT OPTION', 'INDEX', 'INSERT',
            'LOCK TABLES', 'PROCESS', 'REFERENCES', 'RELOAD', 'REPLICATION CLIENT',
            'REPLICATION SLAVE', 'SELECT', 'SHOW DATABASES', 'SHOW VIEW', 'SHUTDOWN',
            'SUPER', 'TRIGGER', 'UPDATE', 'USAGE',
        ];

        $input = strtoupper(trim($privileges));

        // Handle "ALL PRIVILEGES" as a single token
        if ($input === 'ALL' || $input === 'ALL PRIVILEGES') {
            return 'ALL PRIVILEGES';
        }

        $parts = array_map('trim', explode(',', $input));
        $validated = [];

        foreach ($parts as $part) {
            $part = strtoupper($part);
            if (!in_array($part, $allowed, true)) {
                return null;
            }
            $validated[] = $part;
        }

        return implode(', ', $validated);
    }

    private function sanitizeGrantTarget(string $target): string
    {
        $target = trim($target);

        if ($target === '' || $target === '*.*') {
            return '*.*';
        }

        // Expected format: database.table or database.*
        if (!preg_match('/^[a-zA-Z0-9_]+\.\*$|^[a-zA-Z0-9_]+\.[a-zA-Z0-9_]+$/', $target)) {
            // Quote individual parts
            $parts = explode('.', $target, 2);
            if (count($parts) === 2) {
                $db = $parts[0] === '*' ? '*' : '`' . str_replace('`', '``', $parts[0]) . '`';
                $tbl = $parts[1] === '*' ? '*' : '`' . str_replace('`', '``', $parts[1]) . '`';

                return $db . '.' . $tbl;
            }
        }

        return $target;
    }
}
