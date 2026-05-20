<?php

declare(strict_types=1);

/**
 * Teste estrutural do Batch 5.
 *
 * Objetivo:
 * Garantir que wrappers públicos antigos foram marcados como deprecated e
 * instrumentados com log temporário, sem remover callbacks de hooks admin_post.
 */

$pluginRoot = dirname(__DIR__, 2);
$failures = [];

$assertFileExists = static function (string $relativePath) use ($pluginRoot, &$failures): void {
    if (!is_file($pluginRoot . '/' . $relativePath)) {
        $failures[] = "Arquivo esperado não encontrado: {$relativePath}";
    }
};

$assertContains = static function (string $relativePath, string $expectedText) use ($pluginRoot, &$failures): void {
    $filePath = $pluginRoot . '/' . $relativePath;
    $contents = is_file($filePath) ? file_get_contents($filePath) : '';

    if ($contents === false || strpos($contents, $expectedText) === false) {
        $failures[] = "Texto esperado não encontrado em {$relativePath}: {$expectedText}";
    }
};

$assertFileExists('docs/legacy-removal-batch-5.md');
$assertFileExists('app/Support/CompatibilityLogger.php');
$assertFileExists('includes/Modules/Users/UsersActionsController.php');

$assertContains('app/Support/CompatibilityLogger.php', 'deprecatedFunctionUsed');
$assertContains('app/Support/CompatibilityLogger.php', 'Deprecated function used');

$assertContains('includes/Modules/Users/UsersActionsController.php', '@deprecated 3.0.0 Use acme_fe_toggle_status() instead.');
$assertContains('includes/Modules/Users/UsersActionsController.php', "do_action('deprecated_function_run', __FUNCTION__, '3.0.0', 'acme_fe_toggle_status');");
$assertContains('includes/Modules/Users/UsersActionsController.php', 'CompatibilityLogger::deprecatedFunctionUsed(__FUNCTION__,');

$assertContains('includes/Modules/Users/UsersActionsController.php', "add_action('admin_post_acme_fe_toggle_status', 'acme_controller_toggle_status');");
$assertContains('includes/Modules/Users/UsersActionsController.php', 'function acme_controller_create_user()');

$assertContains('composer.json', 'LegacyRemovalBatchFiveTest.php');

if ($failures !== []) {
    fwrite(STDERR, "Falhas do Batch 5:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Legacy removal batch 5 regression test passed.\n");
