<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\SchemaInspector;
use CoquiBot\Toolkits\MysqlAdmin\Tool\AnalyzeTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\BackupRestoreTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\ConnectTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\DisconnectTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\ImportExportTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\ListConnectionsTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\NiFiTemplateTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\OptimizeTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\QueryTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\SchemaModifyTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\SchemaTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\TransactionTool;
use CoquiBot\Toolkits\MysqlAdmin\Tool\UserTool;

final class MysqlAdminToolkit implements ToolkitInterface
{
    private readonly SchemaInspector $inspector;

    public function __construct(
        private readonly ConnectionManager $manager = new ConnectionManager(),
    ) {
        $this->inspector = new SchemaInspector();
    }

    public static function fromEnv(): self
    {
        return new self(
            manager: ConnectionManager::fromEnv(),
        );
    }

    /**
     * @return list<ToolInterface>
     */
    public function tools(): array
    {
        return [
            (new ConnectTool($this->manager))->build(),
            (new DisconnectTool($this->manager))->build(),
            (new ListConnectionsTool($this->manager))->build(),
            (new QueryTool($this->manager))->build(),
            (new SchemaTool($this->manager, $this->inspector))->build(),
            (new SchemaModifyTool($this->manager, $this->inspector))->build(),
            (new TransactionTool($this->manager))->build(),
            (new ImportExportTool($this->manager))->build(),
            (new BackupRestoreTool($this->manager))->build(),
            (new OptimizeTool($this->manager))->build(),
            (new AnalyzeTool($this->manager))->build(),
            (new UserTool($this->manager))->build(),
            (new NiFiTemplateTool($this->manager))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        <mysql_admin_guidelines>
        ## MySQL Admin Toolkit

        ### Connection Workflow
        1. If MYSQL_HOST, MYSQL_USER, and MYSQL_PASSWORD credentials are configured, a default connection is established automatically on startup.
        2. Connect to additional servers: `mysql_connect(host: "dbserver", user: "admin", password: "secret", database: "mydb")`.
        3. Multiple connections can be open simultaneously (max 10). Pass `database:` (alias) to any tool to target a specific connection.
        4. List open connections: `mysql_connections()`.
        5. Disconnect: `mysql_disconnect(database: "alias")`.

        ### Query Execution
        - `mysql_query(sql: "SELECT ...")` — read queries return markdown tables (auto-limited to 1000 rows).
        - `mysql_query(sql: "INSERT INTO users(name) VALUES(?)", params: '["Alice"]')` — use parameterized queries for safe writes.
        - Write queries (INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, GRANT, REVOKE, LOAD, RENAME) are auto-detected.
        - Always prefer parameterized queries over string concatenation for safety.

        ### Schema Inspection
        - List databases: `mysql_schema(action: "databases")`.
        - List tables: `mysql_schema(action: "tables")`.
        - Describe a table: `mysql_schema(action: "describe", table: "users")`.
        - View DDL: `mysql_schema(action: "ddl", table: "users")`.
        - Indexes, foreign keys, triggers, views, routines: `mysql_schema(action: "indexes|foreign_keys|triggers|views|routines", table: "users")`.
        - Server variables: `mysql_schema(action: "variables", name: "innodb%")`.
        - Active queries: `mysql_schema(action: "processlist")`.
        - Database/table stats: `mysql_schema(action: "db_stats")`, `mysql_schema(action: "table_stats", table: "users")`.

        ### Schema Modification (DDL)
        - Create table: `mysql_schema_modify(action: "create_table", table: "users", columns: '[{"name": "id", "type": "INT", "pk": true, "auto_increment": true}, {"name": "email", "type": "VARCHAR(255)"}]')`.
        - Options: `options: '{"engine": "InnoDB", "charset": "utf8mb4", "collate": "utf8mb4_unicode_ci"}'`.
        - Add/modify/drop column, create/drop index, drop/rename table, create/drop view, create/drop database.

        ### Transactions
        - `mysql_transaction(action: "begin")` → writes → `mysql_transaction(action: "commit")`.
        - Uses `START TRANSACTION` for MySQL compatibility.
        - Savepoints: `mysql_transaction(action: "savepoint", name: "sp1")`, `mysql_transaction(action: "release", name: "sp1")`.

        ### Import/Export
        - Import CSV: `mysql_import_export(action: "import_csv", table: "data", file_path: "data.csv")`.
        - Import JSON: `mysql_import_export(action: "import_json", table: "data", file_path: "data.json")`.
        - Export CSV/JSON: `mysql_import_export(action: "export_csv", table: "data", file_path: "output.csv")`.
        - SQL dump: `mysql_import_export(action: "dump_sql", file_path: "backup.sql")` — uses mysqldump.

        ### Backup & Restore
        - Backup: `mysql_backup_restore(action: "backup", destination: "mydb_backup.sql")` — uses mysqldump with --single-transaction.
        - Restore: `mysql_backup_restore(action: "restore", destination: "mydb_backup.sql")` — pipes into mysql client.
        - Clone: `mysql_backup_restore(action: "clone", destination: "new_database_name")` — dump + recreate.

        ### Performance Analysis
        - EXPLAIN: `mysql_analyze(action: "explain", sql: "SELECT ...")`.
        - Detailed cost: `mysql_analyze(action: "explain_json", sql: "SELECT ...")`.
        - Index suggestions: `mysql_analyze(action: "index_suggestions", table: "users")`.
        - Slow queries: `mysql_analyze(action: "slow_queries")` — from performance_schema.
        - Unused indexes: `mysql_analyze(action: "unused_indexes")` — from sys.schema_unused_indexes.

        ### Optimization & Maintenance
        - Optimize: `mysql_optimize(action: "optimize", table: "users")` — defragment and reclaim space.
        - Analyze: `mysql_optimize(action: "analyze", table: "users")` — update index statistics.
        - Check: `mysql_optimize(action: "check", table: "users")` — detect corruption.
        - Repair: `mysql_optimize(action: "repair", table: "users")` — fix MyISAM tables.
        - InnoDB status: `mysql_optimize(action: "innodb_status")`.
        - Config report: `mysql_optimize(action: "variable_report")` — with recommendations.

        ### User & Privilege Management
        - List users: `mysql_user(action: "list")`.
        - Create: `mysql_user(action: "create", user: "newuser", password: "secure_pass")`.
        - Grant: `mysql_user(action: "grant", user: "newuser", privileges: "SELECT,INSERT", on_database: "mydb.*")`.
        - Revoke: `mysql_user(action: "revoke", user: "newuser", privileges: "INSERT", on_database: "mydb.*")`.
        - Show grants: `mysql_user(action: "show_grants", user: "newuser")`.

        ### NiFi Integration
        - Generate ETL ingest pipeline: `mysql_nifi_template(action: "etl_ingest", source_type: "sftp", table: "orders")`.
        - Generate data export pipeline: `mysql_nifi_template(action: "data_export", dest_type: "csv", table: "reports")`.
        - Generate CDC pipeline: `mysql_nifi_template(action: "cdc_pipeline", table: "orders")`.
        - List templates: `mysql_nifi_template(action: "list_templates")`.
        - Deploy generated JSON via: `nifi_pipeline(action: "deploy", definition: '<generated_json>')`.
        </mysql_admin_guidelines>
        GUIDELINES;
    }
}
