<?php

declare(strict_types=1);

namespace codechap\yii2boost\Mcp\Tools;

use Yii;
use codechap\yii2boost\Mcp\Tools\Base\BaseTool;

/**
 * Widget Inspector Tool
 *
 * Inspects Yii2 widgets including:
 * - Discovery of available widgets (framework core, grid, application)
 * - Widget properties with types, defaults, and descriptions
 * - Public methods with parameter signatures
 * - Event constants (EVENT_*)
 * - Class hierarchy up to yii\base\Widget
 */
final class WidgetInspectorTool extends BaseTool
{
    public function getName(): string
    {
        return 'widget_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect widgets: discover available widgets, properties, methods, events, and hierarchy';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'widget' => [
                    'type' => 'string',
                    'description' => 'Widget class to inspect (short name like "ActiveForm" or FQCN). '
                        . 'Omit to list available widgets.',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: properties, methods, events, hierarchy, all. '
                        . 'Defaults to [properties, hierarchy].',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $widget = $arguments['widget'] ?? null;
        $include = $arguments['include'] ?? ['properties', 'hierarchy'];

        if (in_array('all', $include, true)) {
            $include = ['properties', 'methods', 'events', 'hierarchy'];
        }

        if ($widget === null) {
            return $this->listWidgets();
        }

        $className = $this->resolveWidgetClass($widget);

        return $this->inspectWidget($className, $include);
    }

    /**
     * List available widgets grouped by source
     *
     * @return array
     */
    private function listWidgets(): array
    {
        $result = [];

        $framework = $this->discoverFrameworkWidgets();
        if (!empty($framework)) {
            $result['framework'] = $framework;
        }

        $grid = $this->discoverGridWidgets();
        if (!empty($grid)) {
            $result['grid'] = $grid;
        }

        $app = $this->discoverAppWidgets();
        if (!empty($app)) {
            $result['application'] = $app;
        }

        $result['total'] = count($framework) + count($grid) + count($app);

        return $result;
    }

    /**
     * Inspect a widget class via reflection
     *
     * @param class-string $className Fully qualified widget class name
     * @param array $include Sections to include
     * @return array
     */
    private function inspectWidget(string $className, array $include): array
    {
        $ref = new \ReflectionClass($className);

        $result = [
            'class' => $className,
            'description' => $this->getClassDescription($ref),
        ];

        if (in_array('properties', $include, true)) {
            $result['properties'] = $this->getWidgetProperties($ref);
        }

        if (in_array('methods', $include, true)) {
            $result['methods'] = $this->getWidgetMethods($ref);
        }

        if (in_array('events', $include, true)) {
            $result['events'] = $this->getWidgetEvents($ref);
        }

        if (in_array('hierarchy', $include, true)) {
            $result['hierarchy'] = $this->getWidgetHierarchy($ref);
        }

        return $result;
    }

    /**
     * Get public non-static properties with types, defaults, and descriptions
     *
     * @param \ReflectionClass<object> $ref
     * @return array
     */
    private function getWidgetProperties(\ReflectionClass $ref): array
    {
        $properties = [];
        $defaults = $ref->getDefaultProperties();

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $declaringClass = $prop->getDeclaringClass()->getName();
            if ($this->isFrameworkBaseClass($declaringClass)) {
                continue;
            }

            $info = [
                'declared_in' => $declaringClass,
            ];

            $type = $this->getPropertyType($prop);
            if ($type !== null) {
                $info['type'] = $type;
            }

            $name = $prop->getName();
            if (array_key_exists($name, $defaults)) {
                $info['default'] = $defaults[$name];
            }

            $description = $this->getPropertyDescription($prop);
            if ($description !== null) {
                $info['description'] = $description;
            }

            $properties[$name] = $info;
        }

        return $properties;
    }

    /**
     * Get public non-inherited methods with parameter signatures
     *
     * @param \ReflectionClass<object> $ref
     * @return array
     */
    private function getWidgetMethods(\ReflectionClass $ref): array
    {
        $methods = [];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $declaringClass = $method->getDeclaringClass()->getName();
            if ($this->isFrameworkBaseClass($declaringClass)) {
                continue;
            }

            $name = $method->getName();

            // Skip magic methods
            if (strpos($name, '__') === 0) {
                continue;
            }

            // Skip static methods
            if ($method->isStatic()) {
                continue;
            }

            $info = [
                'declared_in' => $declaringClass,
            ];

            // Get parameters
            $params = [];
            foreach ($method->getParameters() as $param) {
                $paramInfo = [
                    'name' => $param->getName(),
                ];

                $paramType = $param->getType();
                if ($paramType !== null) {
                    $paramInfo['type'] = $paramType instanceof \ReflectionNamedType
                        ? $paramType->getName()
                        : (string) $paramType;
                    if ($paramType->allowsNull()) {
                        $paramInfo['nullable'] = true;
                    }
                }

                if ($param->isDefaultValueAvailable()) {
                    $paramInfo['default'] = $param->getDefaultValue();
                }

                $params[] = $paramInfo;
            }

            if (!empty($params)) {
                $info['parameters'] = $params;
            }

            // Get return type
            $returnType = $method->getReturnType();
            if ($returnType !== null) {
                $info['return_type'] = $returnType instanceof \ReflectionNamedType
                    ? $returnType->getName()
                    : (string) $returnType;
            }

            // Get description from PHPDoc
            $docComment = $method->getDocComment();
            if ($docComment !== false) {
                $description = $this->extractDocDescription($docComment);
                if ($description !== null) {
                    $info['description'] = $description;
                }
            }

            $methods[$name] = $info;
        }

        return $methods;
    }

    /**
     * Get EVENT_* constants from the widget class and its parents
     *
     * @param \ReflectionClass<object> $ref
     * @return array
     */
    private function getWidgetEvents(\ReflectionClass $ref): array
    {
        $events = [];

        foreach ($ref->getConstants() as $name => $value) {
            if (strpos($name, 'EVENT_') !== 0) {
                continue;
            }

            $declaringClass = $ref->getName();
            $constRef = $ref->getReflectionConstant($name);
            if ($constRef !== false) {
                $declaringClass = $constRef->getDeclaringClass()->getName();
            }

            $events[] = [
                'constant' => $name,
                'value' => $value,
                'declared_in' => $declaringClass,
            ];
        }

        return $events;
    }

    /**
     * Get the class hierarchy up to yii\base\Widget
     *
     * @param \ReflectionClass<object> $ref
     * @return array
     */
    private function getWidgetHierarchy(\ReflectionClass $ref): array
    {
        $chain = [$ref->getName()];

        $parent = $ref->getParentClass();
        while ($parent) {
            $chain[] = $parent->getName();
            if ($parent->getName() === 'yii\\base\\Widget') {
                break;
            }
            $parent = $parent->getParentClass();
        }

        return $chain;
    }

    /**
     * Resolve a widget name to a fully qualified class name
     *
     * @param string $name Short name or FQCN
     * @return class-string
     * @throws \Exception
     */
    private function resolveWidgetClass(string $name): string
    {
        // If it contains a backslash, treat as FQCN
        if (strpos($name, '\\') !== false) {
            if (!class_exists($name)) {
                throw new \Exception("Widget class '$name' not found");
            }
            if (!$this->isWidgetClass($name)) {
                throw new \Exception("Class '$name' is not a Widget");
            }
            return $name;
        }

        // Try known framework namespaces
        $namespaces = [
            'yii\\widgets\\',
            'yii\\grid\\',
        ];

        foreach ($namespaces as $ns) {
            $fqcn = $ns . $name;
            if (class_exists($fqcn) && $this->isWidgetClass($fqcn)) {
                return $fqcn;
            }
        }

        // Try @app/widgets/
        $appWidgets = $this->discoverAppWidgets();
        foreach ($appWidgets as $widget) {
            $parts = explode('\\', $widget['class']);
            $shortName = end($parts);
            if (strcasecmp($shortName, $name) === 0) {
                return $widget['class'];
            }
        }

        throw new \Exception(
            "Widget '$name' not found. Provide a full class name or ensure it exists "
            . "in yii\\widgets\\, yii\\grid\\, @app/widgets/, @app/components/, "
            . "or module widgets/components directories."
        );
    }

    /**
     * Discover widgets in yii\widgets namespace
     *
     * @return array
     */
    private function discoverFrameworkWidgets(): array
    {
        $path = \Yii::getAlias('@yii/widgets', false);
        if ($path === false || !is_dir($path)) {
            return [];
        }

        return $this->discoverWidgetsInPath($path, 'yii\\widgets\\');
    }

    /**
     * Discover widgets in yii\grid namespace
     *
     * @return array
     */
    private function discoverGridWidgets(): array
    {
        $path = \Yii::getAlias('@yii/grid', false);
        if ($path === false || !is_dir($path)) {
            return [];
        }

        return $this->discoverWidgetsInPath($path, 'yii\\grid\\');
    }

    /**
     * Discover widgets in @app/widgets, @app/components, and module directories
     *
     * @return array
     */
    private function discoverAppWidgets(): array
    {
        $widgets = [];

        // Scan @app/widgets/ and @app/components/
        $appDirs = ['@app/widgets', '@app/components'];
        foreach ($appDirs as $alias) {
            $path = Yii::getAlias($alias, false);
            if ($path !== false && is_dir($path)) {
                $widgets = array_merge($widgets, $this->scanDirectoryForWidgets($path));
            }
        }

        // Scan module widget and component directories
        $modulesPath = \Yii::getAlias('@app/modules', false);
        if ($modulesPath !== false && is_dir($modulesPath)) {
            $modulesDirIterator = new \DirectoryIterator($modulesPath);
            foreach ($modulesDirIterator as $moduleDir) {
                if ($moduleDir->isDot() || !$moduleDir->isDir()) {
                    continue;
                }

                $subdirs = ['widgets', 'components'];
                foreach ($subdirs as $subdir) {
                    $dirPath = $moduleDir->getPathname() . '/' . $subdir;
                    if (is_dir($dirPath)) {
                        $widgets = array_merge(
                            $widgets,
                            $this->scanDirectoryForWidgets($dirPath)
                        );
                    }
                }
            }
        }

        return $widgets;
    }

    /**
     * Scan a directory recursively for Widget classes
     *
     * @param string $path Directory to scan
     * @return array Found widgets
     */
    private function scanDirectoryForWidgets(string $path): array
    {
        $widgets = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $className = $this->getClassNameFromFile($file->getPathname());
                if ($className !== null && class_exists($className) && $this->isWidgetClass($className)) {
                    $ref = new \ReflectionClass($className);
                    $widgets[] = [
                        'class' => $className,
                        'description' => $this->getClassDescription($ref),
                    ];
                }
            }
        }

        return $widgets;
    }

    /**
     * Discover widgets in a directory with a known namespace
     *
     * @param string $path Directory path
     * @param string $namespace PHP namespace prefix
     * @return array
     */
    private function discoverWidgetsInPath(string $path, string $namespace): array
    {
        $widgets = [];
        $files = glob($path . '/*.php');
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $className = $namespace . basename($file, '.php');
            if (!class_exists($className)) {
                continue;
            }

            if ($this->isWidgetClass($className)) {
                $ref = new \ReflectionClass($className);
                $widgets[] = [
                    'class' => $className,
                    'description' => $this->getClassDescription($ref),
                ];
            }
        }

        return $widgets;
    }

    /**
     * Check if a class extends yii\base\Widget and is not abstract
     *
     * @param string $className
     * @return bool
     */
    private function isWidgetClass(string $className): bool
    {
        try {
            if (!class_exists($className)) {
                return false;
            }

            $ref = new \ReflectionClass($className);
            if ($ref->isAbstract()) {
                return false;
            }

            return $ref->isSubclassOf('yii\\base\\Widget');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a class is a framework base class (Widget or below)
     *
     * @param string $className
     * @return bool
     */
    private function isFrameworkBaseClass(string $className): bool
    {
        $baseClasses = [
            'yii\\base\\Widget',
            'yii\\base\\Component',
            'yii\\base\\BaseObject',
        ];

        return in_array($className, $baseClasses, true);
    }

    /**
     * Get description from class PHPDoc
     *
     * @param \ReflectionClass<object> $ref
     * @return string|null
     */
    private function getClassDescription(\ReflectionClass $ref): ?string
    {
        $docComment = $ref->getDocComment();
        if ($docComment === false) {
            return null;
        }

        return $this->extractDocDescription($docComment);
    }

    /**
     * Extract the first description line(s) from a PHPDoc block
     *
     * @param string $docComment
     * @return string|null
     */
    private function extractDocDescription(string $docComment): ?string
    {
        $lines = explode("\n", $docComment);
        $description = [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Strip leading doc-comment markers
            $line = preg_replace('/^\/?(\*+)\/?/', '', $line);
            if ($line === null) {
                continue;
            }
            $line = trim($line);

            // Skip empty lines at the start
            if ($line === '' && empty($description)) {
                continue;
            }

            // Stop at @tags
            if (strpos($line, '@') === 0) {
                break;
            }

            // Stop at empty lines after description (non-empty case guaranteed by earlier check)
            if ($line === '') {
                break;
            }

            $description[] = $line;
        }

        $text = implode(' ', $description);

        return $text !== '' ? $text : null;
    }

    /**
     * Get property type from type declaration or PHPDoc @var
     *
     * @param \ReflectionProperty $prop
     * @return string|null
     */
    private function getPropertyType(\ReflectionProperty $prop): ?string
    {
        $type = $prop->getType();
        if ($type !== null) {
            return $type instanceof \ReflectionNamedType
                ? $type->getName()
                : (string) $type;
        }

        // Fall back to PHPDoc @var
        $docComment = $prop->getDocComment();
        if ($docComment !== false && preg_match('/@var\s+(\S+)/', $docComment, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Get property description from PHPDoc
     *
     * @param \ReflectionProperty $prop
     * @return string|null
     */
    private function getPropertyDescription(\ReflectionProperty $prop): ?string
    {
        $docComment = $prop->getDocComment();
        if ($docComment === false) {
            return null;
        }

        // Try to get text after @var type
        if (preg_match('/@var\s+\S+\s+(.+)$/m', $docComment, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
