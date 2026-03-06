<?php

declare(strict_types=1);

namespace codechap\yii2boost;

use yii\base\BootstrapInterface;
use yii\console\Application as ConsoleApplication;

/**
 * Bootstrap class for auto-registering Yii2 AI Boost commands
 *
 * This class is automatically loaded by Composer via the extra.bootstrap configuration.
 * It registers MCP-related console commands when the application is a console application.
 *
 * Provides the following commands (no extra application controller needed):
 * - php yii boost/install  (InstallController::actionInstall)
 * - php yii boost/mcp      (McpController::actionIndex)
 * - php yii boost/info     (InfoController::actionIndex)
 * - php yii boost/update   (UpdateController::actionIndex)
 */
final class Bootstrap implements BootstrapInterface
{
    /**
     * Bootstrap method called on application initialization
     *
     * @param \yii\base\Application $app The application instance
     */
    public function bootstrap($app): void
    {
        $rootPath = \Yii::getAlias('@app/..');

        Yii::setAlias('@yii2-boost-installation-path', $rootPath);

        // Only register commands in console applications
        if ($app instanceof ConsoleApplication) {
            // Register boost command controller (automatically handles all boost/* actions)
            $app->controllerMap['boost'] = [
                'class' => Commands\BoostController::class,
            ];

            // Disable debug module for MCP server command
            // The debug module spawns child processes on shutdown that output
            // to stdout, corrupting the MCP JSON-RPC protocol stream
            if ($this->isMcpCommand()) {
                $this->disableDebugModule($app);
            }
        }
    }

    /**
     * Check if we're running the boost/mcp command
     *
     * @return bool
     */
    private function isMcpCommand(): bool
    {
        $argv = $_SERVER['argv'] ?? [];
        foreach ($argv as $arg) {
            if ($arg === 'boost/mcp' || str_ends_with($arg, '/boost/mcp')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Disable the debug module to prevent stdout pollution
     *
     * The debug module's LogTarget calls exec('whoami') for console requests,
     * which spawns a child process that outputs to stdout, corrupting the
     * MCP JSON-RPC protocol stream.
     *
     * @param ConsoleApplication $app
     */
    private function disableDebugModule(ConsoleApplication $app): void
    {
        // Remove debug from bootstrap array (may already have run, but prevents future issues)
        $bootstrap = $app->bootstrap;
        if (($key = array_search('debug', $bootstrap)) !== false) {
            unset($bootstrap[$key]);
            $app->bootstrap = array_values($bootstrap);
        }

        // Remove the debug module configuration
        $modules = $app->getModules();
        if (isset($modules['debug'])) {
            unset($modules['debug']);
            $app->setModules($modules);
        }

        // Critical: Remove the debug LogTarget from the log component
        // The debug module registers a LogTarget that calls exec('whoami')
        // for console requests, which outputs to stdout
        if ($app->has('log')) {
            $log = $app->getLog();
            $targets = $log->targets;
            foreach ($targets as $key => $target) {
                if ($target instanceof \yii\debug\LogTarget) {
                    unset($targets[$key]);
                }
            }
            $log->targets = $targets;
        }

        // Detach any event handlers the debug module may have registered
        $app->off(\yii\base\Application::EVENT_BEFORE_REQUEST);
        $app->off(\yii\base\Application::EVENT_BEFORE_ACTION);
    }
}
