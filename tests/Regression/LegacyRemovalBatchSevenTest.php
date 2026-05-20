<?php

declare(strict_types=1);

/**
 * Teste estrutural do Batch 7.
 *
 * Objetivo:
 * Garantir que o template do painel de API saiu de `includes/views` e passou
 * para `app/Views`, sem remover o shortcode público nem os wrappers globais.
 */

$pluginRoot = dirname(__DIR__, 2);
$failures = [];

$assertFileExists = static function (string $relativePath) use ($pluginRoot, &$failures): void {
    if (!is_file($pluginRoot . '/' . $relativePath)) {
        $failures[] = "Arquivo esperado não encontrado: {$relativePath}";
    }
};

$assertFileMissing = static function (string $relativePath) use ($pluginRoot, &$failures): void {
    if (file_exists($pluginRoot . '/' . $relativePath)) {
        $failures[] = "Arquivo ou diretório legado ainda existe: {$relativePath}";
    }
};

$assertContains = static function (string $relativePath, string $expectedText) use ($pluginRoot, &$failures): void {
    $filePath = $pluginRoot . '/' . $relativePath;
    $contents = is_file($filePath) ? file_get_contents($filePath) : '';

    if ($contents === false || strpos($contents, $expectedText) === false) {
        $failures[] = "Texto esperado não encontrado em {$relativePath}: {$expectedText}";
    }
};

$assertNotContains = static function (string $relativePath, string $unexpectedText) use ($pluginRoot, &$failures): void {
    $filePath = $pluginRoot . '/' . $relativePath;
    $contents = is_file($filePath) ? file_get_contents($filePath) : '';

    if ($contents !== false && strpos($contents, $unexpectedText) !== false) {
        $failures[] = "Texto legado inesperado encontrado em {$relativePath}: {$unexpectedText}";
    }
};

$assertFileExists('docs/legacy-removal-batch-7.md');
$assertFileExists('app/Views/frontend/api-consumers-panel.php');
$assertFileMissing('includes/views/api-consumers-panel.php');
$assertFileMissing('includes/views');

$assertContains('app/Controllers/Frontend/ApiControlPanelController.php', "capture('frontend.api-consumers-panel')");
$assertNotContains('app/Controllers/Frontend/ApiControlPanelController.php', "captureLegacy('includes/views/api-consumers-panel.php')");

$assertContains('includes/controllers/api-consumers-frontend.php', "add_shortcode('acme_api_control_panel', 'acme_api_control_panel_shortcode')");
$assertContains('includes/controllers/api-consumers-frontend.php', 'function acme_api_control_panel_shortcode()');
$assertContains('includes/controllers/api-consumers-frontend.php', 'function acme_api_control_panel_enqueue_assets()');

$assertContains('composer.json', 'LegacyRemovalBatchSevenTest.php');

if ($failures !== []) {
    fwrite(STDERR, "Falhas do Batch 7:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Legacy removal batch 7 regression test passed.\n");
