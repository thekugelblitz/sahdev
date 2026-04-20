<?php
/**
 * Sahdev standalone cron runner.
 *
 * Usage:
 *   php modules/addons/sahdev/cron/sahdev-cron.php
 *   php modules/addons/sahdev/cron/sahdev-cron.php --verbose
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from CLI.\n";
    exit(1);
}

$rootInit = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'init.php';
if (!is_file($rootInit)) {
    fwrite(STDERR, "WHMCS init.php not found at expected path: {$rootInit}\n");
    exit(1);
}

require_once $rootInit;
require_once dirname(__DIR__) . '/lib/AIProviderInterface.php';
require_once dirname(__DIR__) . '/lib/GoogleAIProvider.php';
require_once dirname(__DIR__) . '/lib/LMStudioAIProvider.php';
require_once dirname(__DIR__) . '/lib/ReplicateAIProvider.php';
require_once dirname(__DIR__) . '/lib/TicketDataExtractor.php';
require_once dirname(__DIR__) . '/lib/WhmcsTicketTagHelper.php';
require_once dirname(__DIR__) . '/lib/AIController.php';
require_once dirname(__DIR__) . '/lib/AutopilotProcessor.php';
require_once dirname(__DIR__) . '/lib/CronProcessor.php';
require_once dirname(__DIR__) . '/modules/ToolsExecution/ToolsExecutionService.php';

$argvList = isset($argv) && is_array($argv) ? $argv : [];
$verbose = in_array('--verbose', $argvList, true);

$exitCode = 0;
$summary = [
    'ran_at' => date('c'),
    'insights' => null,
    'tools' => null,
    'errors' => [],
];

try {
    $summary['tools'] = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::runCron($verbose);
} catch (\Throwable $e) {
    $summary['errors'][] = 'Tools cron failed: ' . $e->getMessage();
    $exitCode = 1;
}

try {
    $processor = new \Sahdev\Lib\CronProcessor();
    $summary['insights'] = $processor->run($verbose);
} catch (\Throwable $e) {
    $summary['errors'][] = 'Insights cron failed: ' . $e->getMessage();
    $exitCode = 1;
}

if ($verbose || !empty($summary['errors'])) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit($exitCode);
