<?php

declare(strict_types=1);

namespace codechap\yii2boost\Commands;

use codechap\yii2boost\Mcp\Search\SearchIndexManager;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Info Command
 *
 * Displays information about Yii2 AI Boost installation and configuration.
 *
 * Usage:
 *   php yii boost/info
 */
class InfoController extends Controller
{
    /**
     * Display Yii2 AI Boost information
     *
     * @return int Exit code
     */
    public function actionIndex(): int
    {
        $this->stdout("╔════════════════════════════════════════╗\n", 36);
        $this->stdout("║    Yii2 AI Boost - Information         ║\n", 36);
        $this->stdout("╚════════════════════════════════════════╝\n\n", 36);

        try {
            $this->displayPackageInfo();
            $this->displayConfigStatus();
            $this->displayTools();
            $this->displayGuidelines();
            $this->displaySearchIndex();

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("✗ Error: " . $e->getMessage() . "\n", 31);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Display package information
     */
    private function displayPackageInfo(): void
    {
        $this->stdout("Package Information\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        $this->stdout("  Version: " . \codechap\yii2boost\Mcp\Server::VERSION . "\n", 32);
        $this->stdout("  Yii2 Version: " . Yii::getVersion() . "\n", 32);
        $this->stdout("  PHP Version: " . phpversion() . "\n", 32);
        $this->stdout("  Environment: " . YII_ENV . "\n", 32);

        $this->stdout("\n", 0);
    }

    /**
     * Display configuration status
     */
    private function displayConfigStatus(): void
    {
        $basePath = \Yii::getAlias('@yii2-boost-installation-path');

        $this->stdout("Configuration Status\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        $files = [
            '.mcp.json' => 'MCP server configuration',
            '.ai/guidelines' => 'Guidelines directory',
        ];

        foreach ($files as $file => $description) {
            $path = $basePath . '/' . $file;
            if (file_exists($path)) {
                $this->stdout("  ✓ $description\n", 32);
            } else {
                $this->stdout("  ✗ $description (missing)\n", 31);
            }
        }

        $this->stdout("\n", 0);
    }

    /**
     * Display available tools
     */
    private function displayTools(): void
    {
        $this->stdout("Available Tools\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        $server = new \codechap\yii2boost\Mcp\Server([
            'basePath' => Yii::$app->getBasePath(),
        ]);
        $tools = $server->getTools();

        foreach ($tools as $name => $tool) {
            $this->stdout("  • $name\n", 36);
            $this->stdout("    " . $tool->getDescription() . "\n", 0);
        }

        $this->stdout("\nTotal: " . count($tools) . " tools available\n\n", 32);
    }

    /**
     * Display guidelines status
     */
    private function displayGuidelines(): void
    {
        $basePath = \Yii::getAlias('@yii2-boost-installation-path');
        $guidelinesPath = $basePath . '/.ai/guidelines';

        $this->stdout("Guidelines\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        if (!is_dir($guidelinesPath)) {
            $this->stdout("  ✗ Guidelines directory not found\n", 31);
            return;
        }

        $coreGuidelinesPath = $guidelinesPath . '/core';
        if (is_dir($coreGuidelinesPath)) {
            $files = glob($coreGuidelinesPath . '/*.md');
            foreach ($files as $file) {
                $this->stdout("  ✓ " . basename($file) . "\n", 32);
            }
        }

        $this->stdout("\n", 0);
    }

    /**
     * Display search index status
     */
    private function displaySearchIndex(): void
    {
        $this->stdout("Search Index\n", 33);
        $this->stdout("─────────────────────────────────────────\n", 33);

        $searchDb = \Yii::getAlias('@runtime') . '/boost/search.db';

        if (!file_exists($searchDb)) {
            $this->stdout("  ✗ Not built (run 'php yii boost/update')\n", 31);
            $this->stdout("\n", 0);
            return;
        }

        try {
            $manager = new SearchIndexManager($searchDb);
            $stats = $manager->getStats();

            $sizeKb = round(filesize($searchDb) / 1024, 1);
            $this->stdout("  ✓ Index size: {$sizeKb}KB\n", 32);
            $this->stdout("  ✓ Total sections: {$stats['total_sections']}\n", 32);
            $this->stdout("  ✓ Last rebuild: {$stats['last_rebuild']}\n", 32);

            if (!empty($stats['sources'])) {
                foreach ($stats['sources'] as $source => $count) {
                    $label = $source === 'yii2_guide' ? 'Yii2 Guide' : 'Bundled';
                    $this->stdout("    - {$label}: {$count} sections\n", 36);
                }
            }
        } catch (\Exception $e) {
            $this->stdout("  ! Error reading index: " . $e->getMessage() . "\n", 33);
        }

        $this->stdout("\n", 0);
    }
}
