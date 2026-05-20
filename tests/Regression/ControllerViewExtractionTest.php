<?php

declare(strict_types=1);

/**
 * Teste estrutural da Etapa 7.
 *
 * Objetivo:
 * Garantir que a primeira extração de Controllers e Views foi aplicada mantendo
 * wrappers legados. Isso reduz risco porque shortcodes e funções públicas ainda
 * existem, mas a execução já passa por classes MVC novas.
 */

$rootPath = dirname(__DIR__, 2);

$assertFileExists = static function (string $relativePath) use ($rootPath): void {
    $fullPath = $rootPath . '/' . $relativePath;

    if (!is_file($fullPath)) {
        fwrite(STDERR, "Arquivo esperado não encontrado: {$relativePath}\n");
        exit(1);
    }
};

$assertContains = static function (string $relativePath, string $expectedText) use ($rootPath): void {
    $fullPath = $rootPath . '/' . $relativePath;
    $contents = is_file($fullPath) ? file_get_contents($fullPath) : '';

    if ($contents === false || strpos($contents, $expectedText) === false) {
        fwrite(STDERR, "Texto esperado não encontrado em {$relativePath}: {$expectedText}\n");
        exit(1);
    }
};

$assertFileExists('app/Views/View.php');
$assertFileExists('app/Controllers/Frontend/ApiControlPanelController.php');
$assertFileExists('app/Controllers/Admin/AdminPageController.php');
$assertFileExists('app/Controllers/Admin/ApiConsumersAdminController.php');

$assertContains('app/Views/View.php', 'captureLegacy');
$assertFileExists('app/Views/frontend/api-consumers-panel.php');
$assertContains('app/Views/View.php', 'resolveAppViewPath');
$assertContains('app/Controllers/Frontend/ApiControlPanelController.php', 'acme_api_control_panel_handle_post');
$assertContains('app/Controllers/Frontend/ApiControlPanelController.php', "capture('frontend.api-consumers-panel')");
$assertContains('includes/controllers/api-consumers-frontend.php', 'ApiControlPanelController(ACME_ACC_PATH))->shortcode()');
$assertContains('includes/controllers/api-consumers-frontend.php', 'enqueueAssetsWhenShortcodeIsPresent');
$assertContains('acme-account-control.php', "/app/Views/View.php");
$assertContains('acme-account-control.php', "/app/Controllers/Frontend/ApiControlPanelController.php");

fwrite(STDOUT, "Controller/View extraction regression test passed.\n");
