<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MysqlAdmin\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\MysqlAdmin\Runtime\ConnectionManager;

final readonly class NiFiTemplateTool
{
    public function __construct(
        private ConnectionManager $manager,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'mysql_nifi_template',
            description: 'Generate NiFi pipeline JSON definitions for common MySQL workflows. Output can be passed directly to nifi_pipeline(action: "deploy", definition: <output>) for deployment.',
            parameters: [
                new EnumParameter(
                    'action',
                    'Template to generate',
                    values: ['etl_ingest', 'data_export', 'cdc_pipeline', 'list_templates'],
                    required: true,
                ),
                new StringParameter(
                    'source_type',
                    'Source type for etl_ingest: sftp, http, kafka, s3',
                    required: false,
                ),
                new StringParameter(
                    'dest_type',
                    'Destination type for data_export: csv, json, s3, kafka',
                    required: false,
                ),
                new StringParameter(
                    'table',
                    'Target MySQL table name',
                    required: false,
                ),
                new StringParameter(
                    'options',
                    'JSON options for template customization (schedule, batch size, etc.)',
                    required: false,
                ),
                new StringParameter(
                    'database',
                    'Connection alias to use for connection properties (defaults to active)',
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');
        $sourceType = (string) ($args['source_type'] ?? 'sftp');
        $destType = (string) ($args['dest_type'] ?? 'csv');
        $table = (string) ($args['table'] ?? '');
        $optionsJson = (string) ($args['options'] ?? '');
        $database = (string) ($args['database'] ?? '');

        $options = [];
        if ($optionsJson !== '') {
            $decoded = json_decode($optionsJson, true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        try {
            return match ($action) {
                'etl_ingest' => $this->etlIngest($sourceType, $table, $options, $database),
                'data_export' => $this->dataExport($destType, $table, $options, $database),
                'cdc_pipeline' => $this->cdcPipeline($table, $options, $database),
                'list_templates' => $this->listTemplates(),
                default => ToolResult::error(sprintf('Unknown action "%s".', $action)),
            };
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('NiFi template error: %s', $e->getMessage()));
        }
    }

    private function etlIngest(string $sourceType, string $table, array $options, string $database): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for etl_ingest.');
        }

        $dbProps = $this->resolveDbProperties($database);
        $schedule = (string) ($options['schedule'] ?? '0 sec');
        $batchSize = (int) ($options['batch_size'] ?? 1000);

        $sourceProcessor = match ($sourceType) {
            'sftp' => [
                'name' => 'Fetch SFTP',
                'type' => 'org.apache.nifi.processors.standard.GetSFTP',
                'properties' => [
                    'Hostname' => '${sftp.hostname}',
                    'Port' => '${sftp.port}',
                    'Username' => '${sftp.username}',
                    'Password' => '${sftp.password}',
                    'Remote Path' => '${sftp.remote.path}',
                ],
            ],
            'http' => [
                'name' => 'Fetch HTTP',
                'type' => 'org.apache.nifi.processors.standard.InvokeHTTP',
                'properties' => [
                    'HTTP Method' => 'GET',
                    'Remote URL' => '${http.url}',
                ],
            ],
            'kafka' => [
                'name' => 'Consume Kafka',
                'type' => 'org.apache.nifi.processors.kafka.pubsub.ConsumeKafka_2_6',
                'properties' => [
                    'Kafka Brokers' => '${kafka.brokers}',
                    'Topic Name(s)' => '${kafka.topic}',
                    'Group ID' => '${kafka.group.id}',
                ],
            ],
            's3' => [
                'name' => 'Fetch S3',
                'type' => 'org.apache.nifi.processors.aws.s3.FetchS3Object',
                'properties' => [
                    'Bucket' => '${s3.bucket}',
                    'Object Key' => '${s3.key}',
                    'Region' => '${s3.region}',
                ],
            ],
            default => [
                'name' => 'Generate FlowFile',
                'type' => 'org.apache.nifi.processors.standard.GenerateFlowFile',
                'properties' => [],
            ],
        };

        $pipeline = $this->buildPipelineDefinition(
            name: sprintf('MySQL ETL Ingest: %s → %s', ucfirst($sourceType), $table),
            processors: [
                $sourceProcessor,
                [
                    'name' => 'Convert Record',
                    'type' => 'org.apache.nifi.processors.standard.ConvertRecord',
                    'properties' => [
                        'record-reader' => 'csv-reader',
                        'record-writer' => 'json-writer',
                    ],
                ],
                [
                    'name' => 'Insert MySQL',
                    'type' => 'org.apache.nifi.processors.standard.PutDatabaseRecord',
                    'properties' => [
                        'Database Connection Pooling Service' => 'mysql-pool',
                        'Schema Name' => $dbProps['database'],
                        'Table Name' => $table,
                        'Statement Type' => 'INSERT',
                        'Record Reader' => 'json-reader',
                        'Maximum Batch Size' => (string) $batchSize,
                    ],
                    'scheduling' => ['schedulingPeriod' => $schedule],
                ],
                [
                    'name' => 'Log Failures',
                    'type' => 'org.apache.nifi.processors.standard.LogAttribute',
                    'properties' => ['Log Level' => 'ERROR'],
                    'autoTerminatedRelationships' => ['success'],
                ],
            ],
            connections: [
                ['source' => $sourceProcessor['name'], 'dest' => 'Convert Record', 'relationships' => ['success']],
                ['source' => 'Convert Record', 'dest' => 'Insert MySQL', 'relationships' => ['success']],
                ['source' => 'Convert Record', 'dest' => 'Log Failures', 'relationships' => ['failure']],
                ['source' => 'Insert MySQL', 'dest' => 'Log Failures', 'relationships' => ['failure', 'retry']],
            ],
            controllerServices: [
                $this->buildMysqlPoolService($dbProps),
                [
                    'name' => 'csv-reader',
                    'type' => 'org.apache.nifi.csv.CSVReader',
                    'properties' => ['Schema Access Strategy' => 'Infer Schema'],
                ],
                [
                    'name' => 'json-reader',
                    'type' => 'org.apache.nifi.json.JsonTreeReader',
                    'properties' => ['Schema Access Strategy' => 'Infer Schema'],
                ],
                [
                    'name' => 'json-writer',
                    'type' => 'org.apache.nifi.json.JsonRecordSetWriter',
                    'properties' => ['Schema Access Strategy' => 'Inherit Record Schema'],
                ],
            ],
            parameterContextName: sprintf('mysql-etl-%s-params', $table),
            dbProps: $dbProps,
        );

        $json = json_encode($pipeline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return ToolResult::success(sprintf(
            "**NiFi ETL Ingest Pipeline: %s → %s**\n\nDeploy with:\n```\nnifi_pipeline(action: \"deploy\", definition: '<json>')\n```\n\n```json\n%s\n```",
            ucfirst($sourceType),
            $table,
            $json,
        ));
    }

    private function dataExport(string $destType, string $table, array $options, string $database): ToolResult
    {
        if ($table === '') {
            return ToolResult::error('Parameter "table" is required for data_export.');
        }

        $dbProps = $this->resolveDbProperties($database);
        $schedule = (string) ($options['schedule'] ?? '1 hour');
        $query = (string) ($options['query'] ?? sprintf('SELECT * FROM `%s`', str_replace('`', '``', $table)));

        $destProcessor = match ($destType) {
            'csv' => [
                'name' => 'Write CSV',
                'type' => 'org.apache.nifi.processors.standard.PutFile',
                'properties' => [
                    'Directory' => '${output.directory}',
                    'Conflict Resolution Strategy' => 'replace',
                ],
                'autoTerminatedRelationships' => ['success', 'failure'],
            ],
            'json' => [
                'name' => 'Write JSON',
                'type' => 'org.apache.nifi.processors.standard.PutFile',
                'properties' => [
                    'Directory' => '${output.directory}',
                    'Conflict Resolution Strategy' => 'replace',
                ],
                'autoTerminatedRelationships' => ['success', 'failure'],
            ],
            's3' => [
                'name' => 'Put S3',
                'type' => 'org.apache.nifi.processors.aws.s3.PutS3Object',
                'properties' => [
                    'Bucket' => '${s3.bucket}',
                    'Region' => '${s3.region}',
                    'Object Key' => '${s3.key.prefix}/${filename}',
                ],
                'autoTerminatedRelationships' => ['success', 'failure'],
            ],
            'kafka' => [
                'name' => 'Publish Kafka',
                'type' => 'org.apache.nifi.processors.kafka.pubsub.PublishKafka_2_6',
                'properties' => [
                    'Kafka Brokers' => '${kafka.brokers}',
                    'Topic Name' => '${kafka.topic}',
                ],
                'autoTerminatedRelationships' => ['success', 'failure'],
            ],
            default => [
                'name' => 'Write Output',
                'type' => 'org.apache.nifi.processors.standard.PutFile',
                'properties' => ['Directory' => '/tmp/nifi-export'],
                'autoTerminatedRelationships' => ['success', 'failure'],
            ],
        };

        $writerService = $destType === 'csv'
            ? ['name' => 'csv-writer', 'type' => 'org.apache.nifi.csv.CSVRecordSetWriter', 'properties' => ['Schema Access Strategy' => 'Inherit Record Schema']]
            : ['name' => 'json-writer', 'type' => 'org.apache.nifi.json.JsonRecordSetWriter', 'properties' => ['Schema Access Strategy' => 'Inherit Record Schema']];

        $pipeline = $this->buildPipelineDefinition(
            name: sprintf('MySQL Export: %s → %s', $table, ucfirst($destType)),
            processors: [
                [
                    'name' => 'Query MySQL',
                    'type' => 'org.apache.nifi.processors.standard.ExecuteSQLRecord',
                    'properties' => [
                        'Database Connection Pooling Service' => 'mysql-pool',
                        'SQL select query' => $query,
                        'Record Writer' => $writerService['name'],
                    ],
                    'scheduling' => ['schedulingPeriod' => $schedule],
                ],
                $destProcessor,
                [
                    'name' => 'Log Failures',
                    'type' => 'org.apache.nifi.processors.standard.LogAttribute',
                    'properties' => ['Log Level' => 'ERROR'],
                    'autoTerminatedRelationships' => ['success'],
                ],
            ],
            connections: [
                ['source' => 'Query MySQL', 'dest' => $destProcessor['name'], 'relationships' => ['success']],
                ['source' => 'Query MySQL', 'dest' => 'Log Failures', 'relationships' => ['failure']],
            ],
            controllerServices: [
                $this->buildMysqlPoolService($dbProps),
                $writerService,
            ],
            parameterContextName: sprintf('mysql-export-%s-params', $table),
            dbProps: $dbProps,
        );

        $json = json_encode($pipeline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return ToolResult::success(sprintf(
            "**NiFi Data Export Pipeline: %s → %s**\n\nDeploy with:\n```\nnifi_pipeline(action: \"deploy\", definition: '<json>')\n```\n\n```json\n%s\n```",
            $table,
            ucfirst($destType),
            $json,
        ));
    }

    private function cdcPipeline(string $table, array $options, string $database): ToolResult
    {
        $dbProps = $this->resolveDbProperties($database);
        $serverId = (string) ($options['server_id'] ?? '1');

        $processors = [
            [
                'name' => 'Capture MySQL CDC',
                'type' => 'org.apache.nifi.cdc.mysql.processors.CaptureChangeMySQL',
                'properties' => [
                    'MySQL Hosts' => sprintf('%s:%d', $dbProps['host'], $dbProps['port']),
                    'MySQL Driver Class Name' => 'com.mysql.cj.jdbc.Driver',
                    'MySQL Driver Location(s)' => '${mysql.driver.path}',
                    'Username' => '#{mysql.user}',
                    'Password' => '#{mysql.password}',
                    'Server ID' => $serverId,
                    'Database/Schema Name Pattern' => $dbProps['database'] !== '' ? $dbProps['database'] : '.*',
                    'Table Name Pattern' => $table !== '' ? $table : '.*',
                    'Include Begin/Commit Events' => 'false',
                ],
            ],
            [
                'name' => 'Route CDC Events',
                'type' => 'org.apache.nifi.processors.standard.RouteOnAttribute',
                'properties' => [
                    'Routing Strategy' => 'Route to Property name',
                    'insert' => '${cdc.event.type:equals(\'insert\')}',
                    'update' => '${cdc.event.type:equals(\'update\')}',
                    'delete' => '${cdc.event.type:equals(\'delete\')}',
                ],
            ],
            [
                'name' => 'Process Inserts',
                'type' => 'org.apache.nifi.processors.standard.LogAttribute',
                'properties' => ['Log Level' => 'INFO', 'Log prefix' => 'CDC-INSERT'],
                'autoTerminatedRelationships' => ['success'],
            ],
            [
                'name' => 'Process Updates',
                'type' => 'org.apache.nifi.processors.standard.LogAttribute',
                'properties' => ['Log Level' => 'INFO', 'Log prefix' => 'CDC-UPDATE'],
                'autoTerminatedRelationships' => ['success'],
            ],
            [
                'name' => 'Process Deletes',
                'type' => 'org.apache.nifi.processors.standard.LogAttribute',
                'properties' => ['Log Level' => 'INFO', 'Log prefix' => 'CDC-DELETE'],
                'autoTerminatedRelationships' => ['success'],
            ],
        ];

        $connections = [
            ['source' => 'Capture MySQL CDC', 'dest' => 'Route CDC Events', 'relationships' => ['success']],
            ['source' => 'Route CDC Events', 'dest' => 'Process Inserts', 'relationships' => ['insert']],
            ['source' => 'Route CDC Events', 'dest' => 'Process Updates', 'relationships' => ['update']],
            ['source' => 'Route CDC Events', 'dest' => 'Process Deletes', 'relationships' => ['delete']],
        ];

        $pipeline = $this->buildPipelineDefinition(
            name: sprintf('MySQL CDC: %s', $table !== '' ? $table : 'all tables'),
            processors: $processors,
            connections: $connections,
            controllerServices: [],
            parameterContextName: 'mysql-cdc-params',
            dbProps: $dbProps,
        );

        $json = json_encode($pipeline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $output = sprintf(
            "**NiFi CDC Pipeline: %s**\n\n",
            $table !== '' ? $table : 'all tables',
        );
        $output .= "**Prerequisites:**\n";
        $output .= "- MySQL binlog must be enabled (`log_bin = ON`)\n";
        $output .= "- binlog format must be ROW (`binlog_format = ROW`)\n";
        $output .= "- User needs `REPLICATION SLAVE` and `REPLICATION CLIENT` privileges\n\n";
        $output .= "Deploy with:\n```\nnifi_pipeline(action: \"deploy\", definition: '<json>')\n```\n\n";
        $output .= sprintf("```json\n%s\n```", $json);

        return ToolResult::success($output);
    }

    private function listTemplates(): ToolResult
    {
        $output = "**Available MySQL NiFi Pipeline Templates:**\n\n";
        $output .= "| Template | Description | Usage |\n";
        $output .= "| --- | --- | --- |\n";
        $output .= "| `etl_ingest` | Ingest data from external source into MySQL | `source_type`: sftp, http, kafka, s3 |\n";
        $output .= "| `data_export` | Export MySQL table data to external destination | `dest_type`: csv, json, s3, kafka |\n";
        $output .= "| `cdc_pipeline` | MySQL binlog-based Change Data Capture | Routes insert/update/delete events |\n";
        $output .= "\n**Example:**\n";
        $output .= "```\nmysql_nifi_template(action: \"etl_ingest\", source_type: \"sftp\", table: \"orders\")\n```\n\n";
        $output .= "The generated JSON can be deployed directly:\n";
        $output .= "```\nnifi_pipeline(action: \"deploy\", definition: '<generated_json>')\n```";

        return ToolResult::success($output);
    }

    /**
     * @return array{host: string, port: int, user: string, database: string}
     */
    private function resolveDbProperties(string $database): array
    {
        try {
            $alias = $this->manager->resolveAlias($database);

            return $this->manager->getMetadata($alias);
        } catch (\Throwable) {
            // Fallback to env vars
            $host = getenv('MYSQL_HOST');
            $port = getenv('MYSQL_PORT');
            $user = getenv('MYSQL_USER');
            $db = getenv('MYSQL_DATABASE');

            return [
                'host' => is_string($host) && $host !== '' ? $host : 'localhost',
                'port' => is_string($port) && $port !== '' ? (int) $port : 3306,
                'user' => is_string($user) && $user !== '' ? $user : 'root',
                'database' => is_string($db) && $db !== '' ? $db : '',
            ];
        }
    }

    /**
     * @param array{host: string, port: int, user: string, database: string} $dbProps
     * @return array{name: string, type: string, properties: array<string, string>}
     */
    private function buildMysqlPoolService(array $dbProps): array
    {
        return [
            'name' => 'mysql-pool',
            'type' => 'org.apache.nifi.dbcp.DBCPConnectionPool',
            'properties' => [
                'Database Connection URL' => sprintf('jdbc:mysql://%s:%d/%s', $dbProps['host'], $dbProps['port'], $dbProps['database']),
                'Database Driver Class Name' => 'com.mysql.cj.jdbc.Driver',
                'database-driver-locations' => '${mysql.driver.path}',
                'Database User' => '#{mysql.user}',
                'Password' => '#{mysql.password}',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $processors
     * @param list<array{source: string, dest: string, relationships: list<string>}> $connections
     * @param list<array{name: string, type: string, properties: array<string, string>}> $controllerServices
     * @param array{host: string, port: int, user: string, database: string} $dbProps
     * @return array<string, mixed>
     */
    private function buildPipelineDefinition(
        string $name,
        array $processors,
        array $connections,
        array $controllerServices,
        string $parameterContextName,
        array $dbProps,
    ): array {
        $pipeline = [
            'name' => $name,
            'processors' => array_map(fn(array $p): array => array_filter([
                'name' => $p['name'],
                'type' => $p['type'],
                'properties' => $p['properties'] ?? [],
                'scheduling' => $p['scheduling'] ?? [],
                'autoTerminatedRelationships' => $p['autoTerminatedRelationships'] ?? [],
            ], fn(mixed $v): bool => $v !== []), $processors),
            'connections' => array_map(fn(array $c): array => [
                'sourceName' => $c['source'],
                'destinationName' => $c['dest'],
                'relationships' => $c['relationships'],
            ], $connections),
        ];

        if ($controllerServices !== []) {
            $pipeline['controllerServices'] = array_map(fn(array $s): array => [
                'name' => $s['name'],
                'type' => $s['type'],
                'properties' => $s['properties'],
            ], $controllerServices);
        }

        // Add parameter context for sensitive values
        $pipeline['parameterContexts'] = [
            [
                'name' => $parameterContextName,
                'parameters' => [
                    'mysql.user' => ['value' => $dbProps['user'], 'sensitive' => false],
                    'mysql.password' => ['value' => '', 'sensitive' => true, 'description' => 'MySQL password — set before deployment'],
                    'mysql.driver.path' => ['value' => '/opt/nifi/drivers/mysql-connector-j.jar', 'sensitive' => false],
                ],
            ],
        ];

        return $pipeline;
    }
}
