<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Runtime;

use CoquiBot\Toolkits\MysqlAdmin\Exception\MysqlAdminException;

final class ConnectionManager
{
    private const int MAX_CONNECTIONS = 10;
    private const string DEFAULT_CHARSET = 'utf8mb4';
    private const int DEFAULT_PORT = 3306;
    private const string STRICT_SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /** @var array<string, \PDO> */
    private array $connections = [];

    /** @var array<string, array{host: string, port: int, user: string, database: string}> alias → connection metadata */
    private array $metadata = [];

    /** @var array<string, string> alias → password (for mysqldump operations) */
    private array $passwords = [];

    /** @var array<string, bool> alias → has active transaction */
    private array $transactions = [];

    private string $activeAlias = '';

    public function __construct(
        private readonly string $storagePath = '',
    ) {}

    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');

        $manager = new self(
            storagePath: is_string($workspacePath) && $workspacePath !== '' ? $workspacePath : '',
        );

        // Auto-connect from env if credentials are available
        $host = getenv('MYSQL_HOST');
        $user = getenv('MYSQL_USER');
        $password = getenv('MYSQL_PASSWORD');

        if (is_string($host) && $host !== '' && is_string($user) && $user !== '') {
            $portEnv = getenv('MYSQL_PORT');
            $port = is_string($portEnv) && $portEnv !== '' ? (int) $portEnv : self::DEFAULT_PORT;

            $database = getenv('MYSQL_DATABASE');
            $database = is_string($database) && $database !== '' ? $database : '';

            $passwordStr = is_string($password) ? $password : '';

            try {
                $manager->connect($host, $user, $passwordStr, $database, $port);
            } catch (\Throwable) {
                // Silently skip — credentials may be invalid. Agent will see "no active connection" error.
            }
        }

        return $manager;
    }

    public function connect(
        string $host,
        string $user,
        string $password,
        string $database = '',
        int $port = self::DEFAULT_PORT,
        string $alias = '',
    ): string {
        if ($alias === '') {
            $alias = $this->generateAlias($host, $database);
        }

        $alias = $this->sanitizeAlias($alias);

        // Already connected with this alias? Just switch to it.
        if (isset($this->connections[$alias])) {
            $this->activeAlias = $alias;

            return $alias;
        }

        if (count($this->connections) >= self::MAX_CONNECTIONS) {
            throw MysqlAdminException::maxConnectionsReached(self::MAX_CONNECTIONS);
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $host,
            $port,
            self::DEFAULT_CHARSET,
        );

        if ($database !== '') {
            $dsn .= sprintf(';dbname=%s', $database);
        }

        try {
            $pdo = new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);

            $pdo->exec(sprintf("SET SESSION sql_mode='%s'", self::STRICT_SQL_MODE));
            $pdo->exec("SET NAMES " . self::DEFAULT_CHARSET);
        } catch (\PDOException $e) {
            throw MysqlAdminException::connectionFailed($host, $e->getMessage());
        }

        $this->connections[$alias] = $pdo;
        $this->metadata[$alias] = [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'database' => $database,
        ];
        $this->passwords[$alias] = $password;
        $this->transactions[$alias] = false;
        $this->activeAlias = $alias;

        return $alias;
    }

    public function disconnect(string $alias): void
    {
        if (!isset($this->connections[$alias])) {
            throw MysqlAdminException::connectionNotFound($alias);
        }

        // Rollback any active transaction
        if ($this->transactions[$alias]) {
            try {
                $this->connections[$alias]->rollBack();
            } catch (\PDOException) {
                // Ignore — connection is being closed
            }
        }

        unset($this->connections[$alias], $this->metadata[$alias], $this->passwords[$alias], $this->transactions[$alias]);

        if ($this->activeAlias === $alias) {
            $this->activeAlias = array_key_first($this->connections) ?? '';
        }
    }

    public function active(): \PDO
    {
        if ($this->activeAlias === '' || !isset($this->connections[$this->activeAlias])) {
            throw MysqlAdminException::noActiveConnection();
        }

        return $this->connections[$this->activeAlias];
    }

    public function activeAlias(): string
    {
        return $this->activeAlias;
    }

    public function switchTo(string $alias): void
    {
        if (!isset($this->connections[$alias])) {
            throw MysqlAdminException::connectionNotFound($alias);
        }

        $this->activeAlias = $alias;
    }

    public function getConnection(string $alias): \PDO
    {
        if (!isset($this->connections[$alias])) {
            throw MysqlAdminException::connectionNotFound($alias);
        }

        return $this->connections[$alias];
    }

    public function resolveConnection(string $database = ''): \PDO
    {
        if ($database !== '') {
            return $this->getConnection($database);
        }

        return $this->active();
    }

    public function resolveAlias(string $database = ''): string
    {
        if ($database !== '') {
            if (!isset($this->connections[$database])) {
                throw MysqlAdminException::connectionNotFound($database);
            }

            return $database;
        }

        if ($this->activeAlias === '') {
            throw MysqlAdminException::noActiveConnection();
        }

        return $this->activeAlias;
    }

    public function isConnected(string $alias): bool
    {
        return isset($this->connections[$alias]);
    }

    /**
     * @return list<array{alias: string, host: string, port: int, user: string, database: string, active: bool, server_version: string, tables: int, in_transaction: bool}>
     */
    public function list(): array
    {
        $result = [];

        foreach ($this->connections as $alias => $pdo) {
            $tableCount = 0;
            $serverVersion = '';

            try {
                $serverVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
                $stmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()");
                $tableCount = (int) $stmt->fetchColumn();
            } catch (\PDOException) {
                // Connection might be stale
            }

            $meta = $this->metadata[$alias] ?? ['host' => '', 'port' => 0, 'user' => '', 'database' => ''];

            $result[] = [
                'alias' => $alias,
                'host' => $meta['host'],
                'port' => $meta['port'],
                'user' => $meta['user'],
                'database' => $meta['database'],
                'active' => $alias === $this->activeAlias,
                'server_version' => is_string($serverVersion) ? $serverVersion : '',
                'tables' => $tableCount,
                'in_transaction' => $this->transactions[$alias] ?? false,
            ];
        }

        return $result;
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * @return array{host: string, port: int, user: string, database: string}
     */
    public function getMetadata(string $alias): array
    {
        if (!isset($this->metadata[$alias])) {
            throw MysqlAdminException::connectionNotFound($alias);
        }

        return $this->metadata[$alias];
    }

    public function beginTransaction(string $alias): void
    {
        $this->transactions[$alias] = true;
    }

    public function endTransaction(string $alias): void
    {
        $this->transactions[$alias] = false;
    }

    public function hasTransaction(string $alias): bool
    {
        return $this->transactions[$alias] ?? false;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    public function getPassword(string $alias): string
    {
        return $this->passwords[$alias] ?? '';
    }

    private function generateAlias(string $host, string $database): string
    {
        $base = $database !== '' ? sprintf('%s@%s', $database, $host) : $host;

        $alias = $this->sanitizeAlias($base);
        $original = $alias;
        $counter = 1;

        while (isset($this->connections[$alias])) {
            $alias = $original . '_' . $counter;
            $counter++;
        }

        return $alias;
    }

    private function sanitizeAlias(string $alias): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-@.]/', '_', $alias);

        return $sanitized !== '' && $sanitized !== null ? $sanitized : 'mysql';
    }
}
