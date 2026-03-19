<?php

namespace App\Libraries\Tools;

/**
 * Execute SQL queries against configured databases.
 * Supports MySQL, PostgreSQL, and SQLite via PDO.
 */
class DbQueryTool extends BaseTool
{
    protected string $name = 'db_query';
    protected string $description = 'Execute SQL queries against a configured database (MySQL, PostgreSQL, SQLite)';

    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'timeout' => 30,
            'max_rows' => 1000,
            'read_only' => false,
            'connections' => [
                'default' => [
                    'driver' => 'sqlite',
                    'database' => 'writable/agent/data/agent.db',
                ],
            ],
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'required' => true,
                'description' => 'SQL query to execute',
            ],
            'params' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Parameterized query values (array of values for ? placeholders)',
            ],
            'connection' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Named connection from config (default: "default")',
            ],
        ];
    }

    public function execute(array $args): array
    {
        if ($err = $this->requireArgs($args, ['query'])) return $err;

        $query = trim($args['query']);
        $params = $args['params'] ?? [];
        $connName = $args['connection'] ?? 'default';

        $connections = $this->config['connections'] ?? [];
        if (!isset($connections[$connName])) {
            return $this->error("Unknown connection: {$connName}. Available: " . implode(', ', array_keys($connections)));
        }

        $connConfig = $connections[$connName];
        $readOnly = (bool)($this->config['read_only'] ?? false);
        $maxRows = (int)($this->config['max_rows'] ?? 1000);

        // Block write operations if read_only mode
        if ($readOnly && $this->isWriteQuery($query)) {
            return $this->error("Read-only mode is enabled. Write queries (INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, CREATE) are blocked.");
        }

        try {
            $pdo = $this->connect($connConfig);
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            $isSelect = $this->isSelectQuery($query);

            if ($isSelect) {
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $totalRows = count($rows);
                if ($totalRows > $maxRows) {
                    $rows = array_slice($rows, 0, $maxRows);
                }

                return $this->success([
                    'query' => $query,
                    'connection' => $connName,
                    'rows' => $rows,
                    'row_count' => count($rows),
                    'total_rows' => $totalRows,
                    'truncated' => $totalRows > $maxRows,
                    'columns' => $totalRows > 0 ? array_keys($rows[0]) : [],
                ]);
            }

            return $this->success([
                'query' => $query,
                'connection' => $connName,
                'affected_rows' => $stmt->rowCount(),
                'last_insert_id' => $pdo->lastInsertId() ?: null,
            ]);
        } catch (\PDOException $e) {
            return $this->error("Query failed: " . $e->getMessage());
        }
    }

    private function connect(array $config): \PDO
    {
        $driver = $config['driver'] ?? 'sqlite';
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        return match ($driver) {
            'sqlite' => new \PDO("sqlite:" . $config['database'], null, null, $options),
            'mysql' => new \PDO(
                sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                    $config['host'] ?? '127.0.0.1',
                    $config['port'] ?? 3306,
                    $config['database'] ?? ''
                ),
                $config['username'] ?? 'root',
                $config['password'] ?? '',
                $options
            ),
            'pgsql' => new \PDO(
                sprintf("pgsql:host=%s;port=%s;dbname=%s",
                    $config['host'] ?? '127.0.0.1',
                    $config['port'] ?? 5432,
                    $config['database'] ?? ''
                ),
                $config['username'] ?? 'postgres',
                $config['password'] ?? '',
                $options
            ),
            default => throw new \PDOException("Unsupported driver: {$driver}"),
        };
    }

    private function isWriteQuery(string $query): bool
    {
        $first = strtoupper(strtok(trim($query), " \t\n"));
        return in_array($first, ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'REPLACE']);
    }

    private function isSelectQuery(string $query): bool
    {
        $first = strtoupper(strtok(trim($query), " \t\n"));
        return in_array($first, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'PRAGMA']);
    }
}
