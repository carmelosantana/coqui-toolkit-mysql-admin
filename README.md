# MySQL Admin Toolkit for Coqui

A comprehensive MySQL database administration toolkit for [Coqui](https://github.com/carmelosantana/coqui). Provides 13 agent-facing tools for connecting, querying, schema management, backup, performance analysis, user management, and NiFi pipeline integration.

## Installation

```bash
composer require coquibot/coqui-toolkit-mysql-admin
```

The toolkit is auto-discovered by Coqui on startup via the `extra.php-agents.toolkits` declaration in `composer.json`.

## Requirements

- PHP 8.4+
- `ext-pdo_mysql`
- `carmelosantana/php-agents` ^0.7

### Optional

- `doctrine/dbal` ^4.4 — Enhanced schema introspection
- `carmelosantana/php-nifi-pipelines` ^0.1 — NiFi pipeline generation

## Credentials

The toolkit declares MySQL credentials via the Coqui credential system. Set them with:

```
credentials(action: "set", key: "MYSQL_HOST", value: "localhost")
credentials(action: "set", key: "MYSQL_USER", value: "root")
credentials(action: "set", key: "MYSQL_PASSWORD", value: "secret")
credentials(action: "set", key: "MYSQL_DATABASE", value: "mydb")
```

| Credential | Required | Description |
|-----------|----------|-------------|
| `MYSQL_HOST` | Yes | MySQL server hostname |
| `MYSQL_USER` | Yes | MySQL username |
| `MYSQL_PASSWORD` | Yes | MySQL password |
| `MYSQL_PORT` | No | Port (default: 3306) |
| `MYSQL_DATABASE` | No | Default database |

## Tools

| Tool | Description |
|------|-------------|
| `mysql_connect` | Connect to a MySQL server |
| `mysql_disconnect` | Disconnect from a MySQL server |
| `mysql_connections` | List all open connections |
| `mysql_query` | Execute SQL queries with parameterized inputs |
| `mysql_schema` | Inspect databases, tables, indexes, views, routines, triggers |
| `mysql_schema_modify` | DDL operations: create/alter/drop tables, indexes, views, databases |
| `mysql_transaction` | Transaction management with savepoints |
| `mysql_import_export` | Import/export CSV, JSON; SQL dump via mysqldump |
| `mysql_backup_restore` | Backup, restore, and clone databases |
| `mysql_optimize` | OPTIMIZE, ANALYZE, CHECK, REPAIR tables; InnoDB status |
| `mysql_analyze` | EXPLAIN queries, index suggestions, slow query analysis |
| `mysql_user` | User and privilege management (CREATE/DROP USER, GRANT, REVOKE) |
| `mysql_nifi_template` | Generate NiFi pipeline templates for MySQL workflows |

## Gated Operations

Destructive operations require user confirmation (unless `--auto-approve` is enabled):

- `mysql_schema_modify`: drop_table, drop_database, drop_view, drop_index
- `mysql_backup_restore`: restore, clone
- `mysql_query`: write operations
- `mysql_user`: create, drop, grant, revoke, set_password
- `mysql_optimize`: repair, flush

## Usage Examples

### Connect and Query

```
mysql_connect(host: "localhost", user: "root", password: "secret", database: "mydb")
mysql_query(sql: "SELECT * FROM users WHERE active = ?", params: '[1]')
```

### Schema Operations

```
mysql_schema(action: "tables")
mysql_schema(action: "describe", table: "users")
mysql_schema_modify(action: "create_table", table: "logs", columns: '[{"name": "id", "type": "INT", "pk": true, "auto_increment": true}, {"name": "message", "type": "TEXT"}]')
```

### Performance Analysis

```
mysql_analyze(action: "explain", sql: "SELECT * FROM orders WHERE customer_id = 42")
mysql_analyze(action: "index_suggestions", table: "orders")
mysql_analyze(action: "slow_queries")
```

### Backup and Restore

```
mysql_backup_restore(action: "backup", destination: "mydb_2024.sql")
mysql_backup_restore(action: "restore", destination: "mydb_2024.sql")
mysql_backup_restore(action: "clone", destination: "mydb_staging")
```

### User Management

```
mysql_user(action: "list")
mysql_user(action: "create", user: "app_user", password: "secure_pass")
mysql_user(action: "grant", user: "app_user", privileges: "SELECT,INSERT,UPDATE", on_database: "mydb.*")
```

### NiFi Integration

```
mysql_nifi_template(action: "etl_ingest", source_type: "sftp", table: "orders")
mysql_nifi_template(action: "data_export", dest_type: "csv", table: "reports")
mysql_nifi_template(action: "cdc_pipeline", table: "orders")
```

Deploy the generated JSON:

```
nifi_pipeline(action: "deploy", definition: '<generated_json>')
```

## Architecture

```
src/
├── MysqlAdminToolkit.php          # Main ToolkitInterface (13 tools + guidelines)
├── Exception/
│   └── MysqlAdminException.php    # Domain exception with static factories
├── Runtime/
│   ├── ConnectionManager.php      # Multi-connection PDO pool
│   ├── SchemaInspector.php        # INFORMATION_SCHEMA-based introspection
│   └── QueryResult.php            # Markdown table formatting
└── Tool/
    ├── ConnectTool.php
    ├── DisconnectTool.php
    ├── ListConnectionsTool.php
    ├── QueryTool.php
    ├── SchemaTool.php
    ├── SchemaModifyTool.php
    ├── TransactionTool.php
    ├── ImportExportTool.php
    ├── BackupRestoreTool.php
    ├── OptimizeTool.php
    ├── AnalyzeTool.php
    ├── UserTool.php
    └── NiFiTemplateTool.php
```

## License

MIT
