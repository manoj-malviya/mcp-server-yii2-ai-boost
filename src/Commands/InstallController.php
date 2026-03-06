<?php

declare(strict_types=1);

namespace codechap\yii2boost\Commands;

use codechap\yii2boost\Mcp\Search\MarkdownSectionParser;
use codechap\yii2boost\Mcp\Search\SearchIndexManager;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\FileHelper;

/**
 * Install Command
 *
 * Installs and configures Yii2 AI Boost in the application.
 *
 * Usage:
 *   php yii boost/install
 */
class InstallController extends Controller
{
    /**
     * Install Yii2 AI Boost in the application
     *
     * @return int Exit code
     */
    public function actionIndex(): int
    {
        $this->stdout("┌───────────────────────────────────────────┐\n", 32);
        $this->stdout("│      Yii2 AI Boost Installation Wizard    │\n", 32);
        $this->stdout("└───────────────────────────────────────────┘\n\n", 32);

        try {
            // Step 1: Detect environment
            $this->stdout("[1/5] Detecting Environment\n", 33);
            $envInfo = $this->detectEnvironment();
            $this->outputEnvironmentInfo($envInfo);

            // Step 2: Create directories
            $this->stdout("\n[2/5] Creating Directories\n", 33);
            $this->createDirectories();

            // Step 3: Generate configuration files
            $this->stdout("\n[3/5] Generating Configuration Files\n", 33);
            $this->generateConfigFiles($envInfo);

            // Step 4: Set guidelines
            $this->stdout("\n[4/5] Setting Guidelines\n", 33);
            $this->setGuidelines();

            // Step 5: Build search index
            $this->stdout("\n[5/5] Building Search Index\n", 33);
            $this->buildSearchIndex();

            // Success message
            $this->outputSuccessMessage($envInfo);

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("✗ Installation failed: " . $e->getMessage() . "\n", 31);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Detect application environment
     *
     * @return array
     */
    private function detectEnvironment(): array
    {
        $app = Yii::$app;

        return [
            'yii_version' => Yii::getVersion(),
            'php_version' => phpversion(),
            'app_base_path' => $app->getBasePath(),
            'runtime_path' => \Yii::getAlias('@runtime'),
            'yii_env' => YII_ENV,
            'yii_debug' => YII_DEBUG,
        ];
    }

    /**
     * Output environment detection results
     *
     * @param array $envInfo Environment information
     */
    private function outputEnvironmentInfo(array $envInfo): void
    {
        $this->stdout("  ✓ Yii2 version: {$envInfo['yii_version']}\n", 32);
        $this->stdout("  ✓ PHP version: {$envInfo['php_version']}\n", 32);
        $this->stdout("  ✓ Environment: {$envInfo['yii_env']}\n", 32);
        $this->stdout("  ✓ Debug mode: " . ($envInfo['yii_debug'] ? 'ON' : 'OFF') . "\n", 32);
    }

    /**
     * Create necessary directories
     *
     * @throws \Exception
     */
    private function createDirectories(): void
    {
        $basePath = \Yii::getAlias('@yii2-boost-installation-path');

        $directories = [
            $basePath . '/.ai',
            $basePath . '/.ai/guidelines',
            $basePath . '/.ai/guidelines/core',
        ];

        $created = 0;
        $existed = 0;

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                FileHelper::createDirectory($dir);
                $this->stdout("  ✓ Created: $dir\n", 32);
                $created++;
            } else {
                $existed++;
            }
        }

        if ($created === 0 && $existed > 0) {
            $this->stdout("  ✓ All directories already exist\n", 32);
        }
    }

    /**
     * Generate configuration files
     *
     * @param array $envInfo Environment information
     * @throws \Exception
     */
    private function generateConfigFiles(array $envInfo): void
    {
        $basePath = \Yii::getAlias('@yii2-boost-installation-path');

        // Generate .mcp.json with absolute paths for maximum compatibility with MCP clients
        $phpPath = PHP_BINARY;
        $yiiPath = $basePath . '/yii';

        $mcpConfig = [
            'mcpServers' => [
                'yii2-boost' => [
                    'command' => $phpPath,
                    'args' => [$yiiPath, 'boost/mcp'],
                    'env' => (object)[],
                ],
            ],
        ];

        $mcpConfigJson = json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->updateFileWithConfirmation(
            $basePath . '/.mcp.json',
            $mcpConfigJson,
            '.mcp.json'
        );

        // Add .mcp.json to .gitignore
        $this->addToGitignore($basePath, '.mcp.json');
    }

    /**
     * Update file with user confirmation
     *
     * @param string $filePath File path to update
     * @param string $newContent New file content
     * @param string $description Description of the file
     * @return bool Whether the file was updated
     */
    private function updateFileWithConfirmation(string $filePath, string $newContent, string $description): bool
    {
        $exists = file_exists($filePath);

        if ($exists) {
            $oldContent = file_get_contents($filePath);
            if ($oldContent === $newContent) {
                $this->stdout("  ✓ $description already up-to-date\n", 32);
                return false;
            }
        }

        $this->stdout("\nProposed update to $description:\n", 33);
        $this->stdout("────────────────────────────────────\n", 33);
        $this->stdout($newContent, 0);
        $this->stdout("────────────────────────────────────\n", 33);

        if ($this->confirm("Apply this update to $description?")) {
            file_put_contents($filePath, $newContent);
            $this->stdout("  ✓ Updated $description\n", 32);
            return true;
        } else {
            $this->stdout("  - Skipped updating $description\n", 33);
            return false;
        }
    }

    /**
     * Add entry to .gitignore
     *
     * @param string $basePath Application base path
     * @param string $entry Entry to add
     */
    private function addToGitignore(string $basePath, string $entry): void
    {
        $gitignore = $basePath . '/.gitignore';

        if (file_exists($gitignore)) {
            $content = file_get_contents($gitignore);
            if (stripos($content, $entry) === false) {
                $this->stdout("\nProposed update to .gitignore:\n", 33);
                $this->stdout("  + $entry\n", 32);

                if ($this->confirm("Add $entry to .gitignore?")) {
                    file_put_contents($gitignore, "\n$entry\n", FILE_APPEND);
                    $this->stdout("  ✓ Added $entry to .gitignore\n", 32);
                } else {
                    $this->stdout("  - Skipped adding to .gitignore\n", 33);
                }
            } else {
                $this->stdout("  ✓ .gitignore already contains $entry\n", 32);
            }
        } else {
            file_put_contents($gitignore, "$entry\n");
            $this->stdout("  ✓ Created .gitignore with $entry\n", 32);
        }
    }

    /**
     * Copy guidelines from package to application
     */
    private function setGuidelines(): void
    {
        $appBasePath = \Yii::getAlias('@yii2-boost-installation-path');
        $targetPath = $appBasePath . '/.ai/guidelines';

        // Determine package root (vendor/codechap/yii2-ai-boost)
        // This file is in src/Commands/InstallController.php
        $packageRoot = dirname(__DIR__, 2);
        $sourcePath = $packageRoot . '/.ai/guidelines';

        if (is_dir($sourcePath)) {
            try {
                FileHelper::copyDirectory($sourcePath, $targetPath, [
                    'dirMode' => 0755,
                    'fileMode' => 0644,
                ]);
                $this->stdout("  ✓ Copied guidelines from package\n", 32);
            } catch (\Exception $e) {
                $this->stderr("  ✗ Failed to copy guidelines: " . $e->getMessage() . "\n", 31);
            }
        } else {
            // Fallback if source not found (e.g. slight difference in dev vs prod structure)
            $this->stdout("  ! Guidelines source not found at: $sourcePath\n", 33);

            // Create placeholder
            $placeholderPath = $targetPath . '/core';
            if (!is_dir($placeholderPath)) {
                FileHelper::createDirectory($placeholderPath);
            }

            $placeholderFile = $placeholderPath . '/yii2-2.0.45.md';
            if (!file_exists($placeholderFile)) {
                $content = "# Yii2 Framework Guidelines\n\n";
                $content .= "[Guidelines could not be copied automatically]\n";
                file_put_contents($placeholderFile, $content);
                $this->stdout("  ✓ Created guidelines placeholder\n", 32);
            }
        }
    }

    /**
     * Build the FTS5 search index from bundled guidelines
     */
    private function buildSearchIndex(): void
    {
        if (!SearchIndexManager::isFts5Available()) {
            $this->stdout("  ! FTS5 extension not available. Search index not built.\n", 33);
            return;
        }

        $appPath = \Yii::getAlias('@yii2-boost-installation-path');
        $guidelinesPath = $appPath . '/.ai/guidelines';

        if (!is_dir($guidelinesPath)) {
            $this->stdout("  ! No guidelines to index.\n", 33);
            return;
        }

        $dbPath = \Yii::getAlias('@runtime') . '/boost/search.db';
        $manager = new SearchIndexManager($dbPath);
        $manager->createSchema();
        $manager->clearIndex();

        $parser = new MarkdownSectionParser();
        $totalSections = 0;

        $files = FileHelper::findFiles($guidelinesPath, [
            'only' => ['*.md'],
            'recursive' => true,
        ]);

        foreach ($files as $file) {
            $relativePath = str_replace($appPath . '/.ai/guidelines/', '', $file);
            $category = dirname($relativePath);
            $content = file_get_contents($file);
            $parsed = $parser->parse($content, $file);

            $count = $manager->indexSections(
                'bundled',
                $category,
                $relativePath,
                $parsed['file_title'],
                $parsed['sections']
            );
            $totalSections += $count;
        }

        $manager->setMeta('last_rebuild', date('Y-m-d H:i:s'));
        $manager->setMeta('section_count', (string) $totalSections);

        $this->stdout("  ✓ Search index built: {$totalSections} sections\n", 32);
        $this->stdout("  Tip: Run 'php yii boost/update' to also index the Yii2 guide.\n", 33);
    }

    /**
     * Output success message
     *
     * @param array $envInfo Environment information
     */
    private function outputSuccessMessage(array $envInfo): void
    {
        $this->stdout("\n", 0);
        $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n", 32);
        $this->stdout("Installation Complete!\n", 32);
        $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n", 32);

        $this->stdout("Next steps:\n", 36);
        $this->stdout("  1. (Optional) Add core guidelines to your CLAUDE.md file:\n", 0);
        $this->stdout("     @include .ai/guidelines/core/yii2-2.0.45.md\n\n", 37);
        $this->stdout("  2. Your AI assistant can search guidelines on-demand\n", 0);
        $this->stdout("     via the 'semantic_search' MCP tool (FTS5-powered)\n", 37);
        $this->stdout("     (database, cache, auth, etc.)\n\n", 37);
        $this->stdout("  3. Test MCP server: php yii boost/mcp\n", 0);
        $this->stdout("  4. View configuration: php yii boost/info\n\n", 0);

        $this->stdout("Configuration files created:\n", 36);
        $this->stdout("  • .mcp.json (IDE configuration)\n", 0);
        $this->stdout("  • .ai/guidelines/ (framework guidelines)\n\n", 0);

        $this->stdout("MCP Server command:\n", 36);
        $this->stdout("  php yii boost/mcp\n\n", 37);
    }
}
