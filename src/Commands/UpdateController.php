<?php

declare(strict_types=1);

namespace codechap\yii2boost\Commands;

use codechap\yii2boost\Mcp\Search\GitHubGuideDownloader;
use codechap\yii2boost\Mcp\Search\MarkdownSectionParser;
use codechap\yii2boost\Mcp\Search\SearchIndexManager;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Update Command
 *
 * Updates Yii2 AI Boost components including guidelines.
 *
 * Usage:
 *   php yii boost/update
 */
class UpdateController extends Controller
{
    /**
     * Update Yii2 AI Boost components
     *
     * @return int Exit code
     */
    public function actionIndex(): int
    {
        $this->stdout("┌───────────────────────────────────────────┐\n", 32);
        $this->stdout("│   Yii2 AI Boost - Update                  │\n", 32);
        $this->stdout("└───────────────────────────────────────────┘\n\n", 32);

        try {
            $this->stdout("[1/4] Updating Guidelines\n", 33);
            $this->updateGuidelines();

            $this->stdout("\n[2/4] Fetching Yii2 Guide from GitHub\n", 33);
            $this->fetchYii2Guide();

            $this->stdout("\n[3/4] Building Search Index\n", 33);
            $this->buildSearchIndex();

            $this->stdout("\n[4/4] Verifying Installation\n", 33);
            $this->verifyInstallation();

            $this->stdout("\n", 0);
            $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n", 32);
            $this->stdout("Update Complete!\n", 32);
            $this->stdout("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n", 32);

            return ExitCode::OK;
        } catch (\Exception $e) {
            $this->stderr("✗ Update failed: " . $e->getMessage() . "\n", 31);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * Update guidelines from package source
     */
    private function updateGuidelines(): void
    {
        $appPath = \Yii::getAlias('@yii2-boost-installation-path');
        $targetPath = $appPath . '/.ai/guidelines';

        // Locate source directory relative to this file
        // src/Commands/UpdateController.php -> .ai/guidelines
        $packageRoot = dirname(__DIR__, 2);
        $sourcePath = $packageRoot . '/.ai/guidelines';

        if (!is_dir($sourcePath)) {
            $this->stdout("  ! Package source guidelines not found at: $sourcePath\n", 33);
            return;
        }

        $this->stdout("  Copying guidelines from package...\n", 0);

        try {
            \yii\helpers\FileHelper::copyDirectory($sourcePath, $targetPath, [
                'dirMode' => 0755,
                'fileMode' => 0644,
            ]);
            $this->stdout("  ✓ Guidelines updated successfully\n", 32);
        } catch (\Exception $e) {
            throw new \Exception("Failed to copy guidelines: " . $e->getMessage());
        }
    }

    /**
     * Fetch Yii2 definitive guide from GitHub
     */
    private function fetchYii2Guide(): void
    {
        $appPath = \Yii::getAlias('@yii2-boost-installation-path');
        $cachePath = $appPath . '/.ai/yii2-guide';

        $downloader = new GitHubGuideDownloader($cachePath);

        $this->stdout("  Downloading from GitHub...\n", 0);

        $result = $downloader->download();

        if ($result['downloaded'] > 0) {
            $this->stdout("  ✓ Downloaded {$result['downloaded']} guide files\n", 32);
        }
        if ($result['skipped'] > 0) {
            $this->stdout("  ✓ {$result['skipped']} files already up-to-date\n", 32);
        }
        if ($result['failed'] > 0) {
            $this->stdout("  ! {$result['failed']} files failed to download\n", 33);
        }
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->stdout("  ! $error\n", 33);
            }
        }

        // If nothing was downloaded and no cache exists, warn
        if ($result['downloaded'] === 0 && $result['skipped'] === 0 && !$downloader->hasCachedFiles()) {
            $this->stdout("  ! Guide download failed. Bundled guidelines will still be indexed.\n", 33);
        }
    }

    /**
     * Build the FTS5 search index
     */
    private function buildSearchIndex(): void
    {
        if (!SearchIndexManager::isFts5Available()) {
            $this->stdout("  ! FTS5 extension not available. Search index not built.\n", 33);
            return;
        }

        $appPath = \Yii::getAlias('@yii2-boost-installation-path');
        $dbPath = \Yii::getAlias('@runtime') . '/boost/search.db';

        $manager = new SearchIndexManager($dbPath);
        $manager->createSchema();
        $manager->clearIndex();

        $parser = new MarkdownSectionParser();
        $totalSections = 0;

        // Index bundled guidelines
        $guidelinesPath = $appPath . '/.ai/guidelines';
        if (is_dir($guidelinesPath)) {
            $files = \yii\helpers\FileHelper::findFiles($guidelinesPath, [
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

            $this->stdout("  ✓ Indexed bundled guidelines: {$totalSections} sections\n", 32);
        }

        // Index Yii2 guide (if cached)
        $guidePath = $appPath . '/.ai/yii2-guide';
        if (is_dir($guidePath)) {
            $guideDownloader = new GitHubGuideDownloader($guidePath);
            $guideFiles = $guideDownloader->getCachedFiles();
            $guideSections = 0;

            foreach ($guideFiles as $file) {
                $filename = basename($file);
                $category = GitHubGuideDownloader::mapCategory($filename);
                $content = file_get_contents($file);
                $parsed = $parser->parse($content, $file);

                $count = $manager->indexSections(
                    'yii2_guide',
                    $category,
                    $filename,
                    $parsed['file_title'],
                    $parsed['sections']
                );
                $guideSections += $count;
            }

            $totalSections += $guideSections;
            $this->stdout("  ✓ Indexed Yii2 guide: {$guideSections} sections\n", 32);
        }

        $manager->setMeta('last_rebuild', date('Y-m-d H:i:s'));
        $manager->setMeta('section_count', (string) $totalSections);

        $this->stdout("  ✓ Search index built: {$totalSections} total sections\n", 32);
    }

    /**
     * Verify installation
     */
    private function verifyInstallation(): void
    {
        $basePath = \Yii::getAlias('@yii2-boost-installation-path');

        $files = [
            '.mcp.json',
            '.ai/guidelines',
        ];

        foreach ($files as $file) {
            $path = $basePath . '/' . $file;
            if (file_exists($path)) {
                $this->stdout("  ✓ $file exists\n", 32);
            } else {
                $this->stdout("  ✗ $file missing\n", 31);
            }
        }

        // Check search index
        $searchDb = \Yii::getAlias('@runtime') . '/boost/search.db';
        if (file_exists($searchDb)) {
            $sizeKb = round(filesize($searchDb) / 1024, 1);
            $this->stdout("  ✓ Search index ({$sizeKb}KB)\n", 32);
        } else {
            $this->stdout("  ✗ Search index not built\n", 31);
        }
    }
}
