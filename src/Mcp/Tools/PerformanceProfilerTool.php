<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use Yii;
use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * Performance Profiler Tool
 *
 * Provides database performance analysis including:
 * - EXPLAIN query plans with driver-specific formatting
 * - Table-level index analysis and missing index detection
 * - Per-table overview with row counts and index coverage
 */
final class PerformanceProfilerTool extends BaseTool
{
    public function getName(): string
    {
        return 'performance_profiler';
    }

    public function getDescription(): string
    {
        return 'Analyze query performance with EXPLAIN plans, index coverage, and table statistics';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'SQL query to EXPLAIN. Returns execution plan with analysis.',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Bound parameters for the SQL query (e.g., {":id": 1})',
                    'additionalProperties' => true,
                ],
                'table' => [
                    'type' => 'string',
                    'description' => 'Table name for index analysis. Used when sql is not provided.',
                ],
                'db' => [
                    'type' => 'string',
                    'description' => 'Database connection name (default: db)',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $sql = $arguments['sql'] ?? null;
        $params = $arguments['params'] ?? [];
        $table = $arguments['table'] ?? null;
        $dbName = $arguments['db'] ?? 'db';

        if (!Yii::$app->has($dbName)) {
            throw new \Exception("Database connection '$dbName' not found");
        }

        $db = Yii::$app->get($dbName);

        if ($sql !== null) {
            return $this->explainQuery((string) $sql, (array) $params, $db);
        }

        if ($table !== null) {
            return $this->analyzeTable((string) $table, $db);
        }

        return $this->getOverview($db);
    }

    /**
     * Run EXPLAIN on a SQL query and return formatted results with analysis
     *
     * @param string $sql SQL query to explain
     * @param array $params Bound parameters
     * @param object $db Database connection
     * @return array
     */
    private function explainQuery(string $sql, array $params, object $db): array
    {
        $sql = trim($sql);
        if (empty($sql)) {
            throw new \Exception('SQL query cannot be empty');
        }

        $driver = $this->getDbDriver($db->dsn);
        $explainSql = $this->getExplainPrefix($driver) . ' ' . $sql;

        try {
            $command = $db->createCommand($explainSql);

            if (!empty($params)) {
                foreach ($params as $name => $value) {
                    $command->bindValue($name, $value);
                }
            }

            $startTime = microtime(true);
            $rows = $command->queryAll();
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $plan = $this->formatExplainRows($rows, $driver);
            $warnings = $this->analyzeExplainResult($rows, $driver);

            $result = [
                'mode' => 'explain',
                'driver' => $driver,
                'original_sql' => $sql,
                'duration_ms' => $duration,
                'plan' => $plan,
            ];

            if (!empty($warnings)) {
                $result['warnings'] = $warnings;
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'mode' => 'explain',
                'driver' => $driver,
                'original_sql' => $sql,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Analyze a specific table: indexes, foreign keys, missing index detection, stats
     *
     * @param string $table Table name
     * @param object $db Database connection
     * @return array
     */
    private function analyzeTable(string $table, object $db): array
    {
        $schema = $db->getSchema();
        $tableSchema = $schema->getTableSchema($table);

        if (!$tableSchema) {
            throw new \Exception("Table '$table' not found");
        }

        $driver = $this->getDbDriver($db->dsn);
        $indexAnalysis = $this->getIndexAnalysis($db, $table);
        $stats = $this->getTableStats($db, $table, $driver);

        return [
            'mode' => 'table_analysis',
            'table' => $table,
            'driver' => $driver,
            'columns' => count($tableSchema->columns),
            'primary_key' => $tableSchema->primaryKey,
            'indexes' => $indexAnalysis['indexes'],
            'foreign_key_columns' => $indexAnalysis['foreign_key_columns'],
            'missing_indexes' => $indexAnalysis['missing_indexes'],
            'stats' => $stats,
        ];
    }

    /**
     * Get overview of all tables with row counts, index counts, and missing index report
     *
     * @param object $db Database connection
     * @return array
     */
    private function getOverview(object $db): array
    {
        $schema = $db->getSchema();
        $driver = $this->getDbDriver($db->dsn);
        $tableNames = $schema->getTableNames();
        $tables = [];
        $allMissing = [];

        foreach ($tableNames as $tableName) {
            try {
                $rowCount = (int) $db->createCommand(
                    "SELECT COUNT(*) FROM [[" . $tableName . "]]"
                )->queryScalar();

                $indexAnalysis = $this->getIndexAnalysis($db, $tableName);

                $entry = [
                    'row_count' => $rowCount,
                    'index_count' => count($indexAnalysis['indexes']),
                    'fk_column_count' => count($indexAnalysis['foreign_key_columns']),
                ];

                if (!empty($indexAnalysis['missing_indexes'])) {
                    $entry['missing_indexes'] = $indexAnalysis['missing_indexes'];
                    foreach ($indexAnalysis['missing_indexes'] as $missing) {
                        $allMissing[] = $tableName . '.' . $missing;
                    }
                }

                $tables[$tableName] = $entry;
            } catch (\Exception $e) {
                $tables[$tableName] = ['error' => $e->getMessage()];
            }
        }

        $result = [
            'mode' => 'overview',
            'driver' => $driver,
            'table_count' => count($tableNames),
            'tables' => $tables,
        ];

        if (!empty($allMissing)) {
            $result['missing_index_summary'] = $allMissing;
        }

        return $result;
    }

    /**
     * Get driver-specific EXPLAIN prefix
     *
     * @param string $driver Database driver name
     * @return string
     */
    private function getExplainPrefix(string $driver): string
    {
        if ($driver === 'sqlite') {
            return 'EXPLAIN QUERY PLAN';
        }

        return 'EXPLAIN';
    }

    /**
     * Analyze EXPLAIN result rows for potential performance issues
     *
     * @param array $rows EXPLAIN output rows
     * @param string $driver Database driver name
     * @return array Warning messages
     */
    private function analyzeExplainResult(array $rows, string $driver): array
    {
        $warnings = [];

        if ($driver === 'mysql' || $driver === 'mariadb') {
            foreach ($rows as $row) {
                $type = $row['type'] ?? $row['select_type'] ?? '';
                $extra = $row['Extra'] ?? '';
                $possibleKeys = $row['possible_keys'] ?? null;
                $key = $row['key'] ?? null;
                $rowsEstimate = $row['rows'] ?? 0;

                if (strtoupper($type) === 'ALL') {
                    $tableName = $row['table'] ?? 'unknown';
                    $warnings[] = "Full table scan on '$tableName' ($rowsEstimate rows estimated)";
                }

                if ($possibleKeys === null && $key === null) {
                    $tableName = $row['table'] ?? 'unknown';
                    $warnings[] = "No index used for '$tableName'";
                }

                if (stripos($extra, 'Using filesort') !== false) {
                    $warnings[] = 'Using filesort (consider adding an index for ORDER BY)';
                }

                if (stripos($extra, 'Using temporary') !== false) {
                    $warnings[] = 'Using temporary table (consider optimizing GROUP BY/ORDER BY)';
                }
            }
        } elseif ($driver === 'pgsql') {
            foreach ($rows as $row) {
                $plan = $row['QUERY PLAN'] ?? '';
                if (stripos($plan, 'Seq Scan') !== false) {
                    $warnings[] = 'Sequential scan detected (consider adding an index)';
                }
            }
        } elseif ($driver === 'sqlite') {
            foreach ($rows as $row) {
                $detail = $row['detail'] ?? '';
                if (stripos($detail, 'SCAN') !== false && stripos($detail, 'USING INDEX') === false) {
                    $warnings[] = "Table scan detected: $detail";
                }
            }
        }

        return $warnings;
    }

    /**
     * Analyze indexes vs foreign key columns for a table
     *
     * @param object $db Database connection
     * @param string $table Table name
     * @return array With keys: indexes, foreign_key_columns, missing_indexes
     */
    private function getIndexAnalysis(object $db, string $table): array
    {
        $schema = $db->getSchema();
        $tableSchema = $schema->getTableSchema($table);

        if (!$tableSchema) {
            return ['indexes' => [], 'foreign_key_columns' => [], 'missing_indexes' => []];
        }

        // Get indexes
        $indexes = [];
        $indexedColumns = [];
        try {
            $tableIndexes = $schema->getTableIndexes($table);
            foreach ($tableIndexes as $index) {
                $cols = $index->columnNames;
                $indexes[] = [
                    'name' => $index->name,
                    'columns' => $cols,
                    'is_unique' => $index->isUnique,
                    'is_primary' => $index->isPrimary,
                ];
                // Track first column of each index for covering analysis
                if (!empty($cols)) {
                    foreach ($cols as $col) {
                        $indexedColumns[$col] = true;
                    }
                }
            }
        } catch (\Exception $e) {
            // Index retrieval not supported
        }

        // Get formal foreign key columns
        $fkColumns = [];
        try {
            if (method_exists($schema, 'getTableForeignKeys')) {
                $foreignKeys = $schema->getTableForeignKeys($table);
                foreach ($foreignKeys as $fk) {
                    foreach ($fk->columnNames as $col) {
                        if (!in_array($col, $fkColumns)) {
                            $fkColumns[] = $col;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Foreign key retrieval not supported
        }

        // Detect FK-like columns by naming convention (*_id suffix)
        foreach ($tableSchema->columns as $name => $column) {
            if (
                preg_match('/_id$/', $name) &&
                !in_array($name, $fkColumns) &&
                !in_array($name, $tableSchema->primaryKey)
            ) {
                $fkColumns[] = $name;
            }
        }

        // Find FK columns without covering index
        $missingIndexes = [];
        foreach ($fkColumns as $col) {
            if (!isset($indexedColumns[$col])) {
                $missingIndexes[] = $col;
            }
        }

        return [
            'indexes' => $indexes,
            'foreign_key_columns' => $fkColumns,
            'missing_indexes' => $missingIndexes,
        ];
    }

    /**
     * Get driver-specific table statistics
     *
     * @param object $db Database connection
     * @param string $table Table name
     * @param string $driver Database driver name
     * @return array
     */
    private function getTableStats(object $db, string $table, string $driver): array
    {
        $stats = [];

        // Row count (always available)
        try {
            $stats['row_count'] = (int) $db->createCommand(
                "SELECT COUNT(*) FROM [[" . $table . "]]"
            )->queryScalar();
        } catch (\Exception $e) {
            $stats['row_count'] = null;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                $row = $db->createCommand(
                    "SELECT ENGINE, DATA_LENGTH, INDEX_LENGTH, AUTO_INCREMENT, TABLE_ROWS "
                    . "FROM information_schema.TABLES "
                    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
                )->bindValue(':table', $table)->queryOne();

                if ($row) {
                    $stats['engine'] = $row['ENGINE'];
                    $stats['data_length'] = (int) $row['DATA_LENGTH'];
                    $stats['index_length'] = (int) $row['INDEX_LENGTH'];
                    $stats['auto_increment'] = $row['AUTO_INCREMENT'] !== null
                        ? (int) $row['AUTO_INCREMENT'] : null;
                    $stats['estimated_rows'] = (int) $row['TABLE_ROWS'];
                }
            } catch (\Exception $e) {
                // information_schema not available
            }
        } elseif ($driver === 'pgsql') {
            try {
                $row = $db->createCommand(
                    "SELECT n_live_tup, seq_scan, idx_scan "
                    . "FROM pg_stat_user_tables WHERE relname = :table"
                )->bindValue(':table', $table)->queryOne();

                if ($row) {
                    $stats['estimated_rows'] = (int) $row['n_live_tup'];
                    $stats['seq_scan_count'] = (int) $row['seq_scan'];
                    $stats['idx_scan_count'] = (int) $row['idx_scan'];
                }
            } catch (\Exception $e) {
                // pg_stat not available
            }
        }

        // Index count
        try {
            $indexes = $db->getSchema()->getTableIndexes($table);
            $stats['index_count'] = count($indexes);
        } catch (\Exception $e) {
            $stats['index_count'] = null;
        }

        return $stats;
    }

    /**
     * Format EXPLAIN rows for readability
     *
     * @param array $rows Raw EXPLAIN output
     * @param string $driver Database driver name
     * @return array Formatted plan rows
     */
    private function formatExplainRows(array $rows, string $driver): array
    {
        if (empty($rows)) {
            return [];
        }

        if ($driver === 'sqlite') {
            $formatted = [];
            foreach ($rows as $row) {
                $formatted[] = [
                    'id' => $row['id'] ?? $row['selectid'] ?? null,
                    'parent' => $row['parent'] ?? null,
                    'detail' => $row['detail'] ?? implode(' ', array_values($row)),
                ];
            }
            return $formatted;
        }

        // MySQL/PostgreSQL: return rows as-is with string values
        $formatted = [];
        foreach ($rows as $row) {
            $entry = [];
            foreach ($row as $key => $value) {
                $entry[$key] = $value;
            }
            $formatted[] = $entry;
        }

        return $formatted;
    }
}
