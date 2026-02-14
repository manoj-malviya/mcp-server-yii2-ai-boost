<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools\Base;

use yii\base\Component;

/**
 * Base class for MCP Tools
 *
 * All MCP tools should extend this class and implement the required methods.
 */
abstract class BaseTool extends Component
{
    /**
     * @var string Base path to the Yii2 application
     */
    public $basePath;

    /**
     * Get the tool name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get the tool description
     *
     * @return string
     */
    abstract public function getDescription(): string;

    /**
     * Get the tool input schema (JSON Schema)
     *
     * @return array
     */
    abstract public function getInputSchema(): array;

    /**
     * Execute the tool with given arguments
     *
     * @param array $arguments Tool arguments
     * @return mixed Result data
     * @throws \Exception
     */
    abstract public function execute(array $arguments): mixed;

    /**
     * Sanitize output to remove sensitive data
     *
     * @param mixed $data Data to sanitize
     * @return mixed Sanitized data
     */
    protected function sanitize(mixed $data): mixed
    {
        // List of sensitive keys to filter
        $sensitiveKeys = [
            'password', 'passwd', 'secret', 'key', 'token', 'api_key', 'private_key',
            'auth_key', 'access_token', 'refresh_token', 'client_secret',
            'credential', 'dsn', 'database_url', 'connection_string',
        ];

        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                // Only check string keys for sensitive patterns
                if (is_string($key)) {
                    $lowerKey = strtolower($key);

                    // Check if key contains sensitive pattern
                    $isSensitive = false;
                    foreach ($sensitiveKeys as $pattern) {
                        if (stripos($lowerKey, $pattern) !== false) {
                            $isSensitive = true;
                            break;
                        }
                    }

                    if ($isSensitive) {
                        $sanitized[$key] = '***REDACTED***';
                    } else {
                        $sanitized[$key] = $this->sanitize($value);
                    }
                } else {
                    // Non-string keys (integers, etc) are always safe
                    $sanitized[$key] = $this->sanitize($value);
                }
            }
            return $sanitized;
        } elseif (is_string($data) && !empty($data)) {
            // Don't sanitize regular strings
            return $data;
        }

        return $data;
    }

    /**
     * Get all database connections
     *
     * @return array Array of database names and connection info
     */
    protected function getDbConnections(): array
    {
        $connections = [];
        $app = \Yii::$app;

        // Main database connection
        if ($app->has('db')) {
            $db = $app->get('db');
            $connections['main'] = [
                'dsn' => $db->dsn,
                'driver' => $this->getDbDriver($db->dsn),
                'username' => $db->username,
            ];
        }

        // Additional named connections
        foreach ($app->get('components', []) as $name => $config) {
            if (
                is_array($config) && isset($config['class']) &&
                (stripos($config['class'], 'yii\db\Connection') !== false)
            ) {
                if ($name !== 'db') {
                    $db = $app->get($name);
                    $connections[$name] = [
                        'dsn' => $db->dsn,
                        'driver' => $this->getDbDriver($db->dsn),
                        'username' => $db->username,
                    ];
                }
            }
        }

        return $connections;
    }

    /**
     * Extract database driver from DSN
     *
     * @param string $dsn Database DSN
     * @return string Driver name
     */
    protected function getDbDriver(string $dsn): string
    {
        $driver = explode(':', $dsn)[0] ?? 'unknown';
        return $driver;
    }

    /**
     * Discover Active Record models in the application models directory
     *
     * @return array Fully qualified class names
     */
    protected function getActiveRecordModels(): array
    {
        $modelsPath = \Yii::getAlias('@app/models');
        if (!is_dir($modelsPath)) {
            return [];
        }

        $models = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modelsPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $className = $this->getClassNameFromFile($file->getPathname());
                if ($className && $this->isActiveRecordModel($className)) {
                    $models[] = $className;
                }
            }
        }

        return $models;
    }

    /**
     * Extract fully qualified class name from a PHP file using token parsing
     *
     * @param string $file Absolute file path
     * @return string|null Fully qualified class name or null
     */
    protected function getClassNameFromFile(string $file): ?string
    {
        $namespace = '';
        $className = '';

        $tokens = token_get_all(file_get_contents($file));

        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i][0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    // PHP 8.0+ uses T_NAME_QUALIFIED for multi-part namespace names
                    if (defined('T_NAME_QUALIFIED') && $tokens[$j][0] === T_NAME_QUALIFIED) {
                        $namespace = $tokens[$j][1];
                        break;
                    } elseif ($tokens[$j][0] === T_STRING) {
                        $namespace .= $tokens[$j][1];
                    } elseif ($tokens[$j][0] === T_NS_SEPARATOR) {
                        $namespace .= '\\';
                    } elseif ($tokens[$j][0] === ';') {
                        break;
                    }
                }
            }

            // Skip ::class constant access (T_DOUBLE_COLON followed by T_CLASS)
            if ($tokens[$i][0] === T_CLASS && ($i === 0 || $tokens[$i - 1][0] !== T_DOUBLE_COLON)) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j][0] === T_STRING) {
                        $className = $tokens[$j][1];
                        break;
                    }
                }
            }
        }

        return $namespace && $className ? $namespace . '\\' . $className : null;
    }

    /**
     * Check if a class extends yii\db\ActiveRecord by walking the parent chain
     *
     * @param string $className Fully qualified class name
     * @return bool
     */
    protected function isActiveRecordModel(string $className): bool
    {
        try {
            if (!class_exists($className)) {
                return false;
            }

            $reflection = new \ReflectionClass($className);
            $parent = $reflection->getParentClass();

            while ($parent) {
                if ($parent->getName() === 'yii\db\ActiveRecord') {
                    return true;
                }
                $parent = $parent->getParentClass();
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Resolve a model class from either a short name or fully qualified class name
     *
     * @param string $model Short class name (e.g., "User") or FQCN (e.g., "app\models\User")
     * @return string Fully qualified class name
     * @throws \Exception If model cannot be found or is not an ActiveRecord
     */
    protected function resolveModelClass(string $model): string
    {
        // If it contains a backslash, treat as FQCN
        if (strpos($model, '\\') !== false) {
            if (!class_exists($model)) {
                throw new \Exception("Model class '$model' not found");
            }
            if (!$this->isActiveRecordModel($model)) {
                throw new \Exception("Class '$model' is not an ActiveRecord model");
            }
            return $model;
        }

        // Short name — scan @app/models to find a match
        $allModels = $this->getActiveRecordModels();
        foreach ($allModels as $className) {
            $parts = explode('\\', $className);
            $shortName = end($parts);
            if (strcasecmp($shortName, $model) === 0) {
                return $className;
            }
        }

        throw new \Exception(
            "Model '$model' not found. Provide a full class name or ensure the model exists in @app/models."
        );
    }
}
