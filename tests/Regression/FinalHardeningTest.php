<?php

declare(strict_types=1);

/**
 * Teste estrutural da Etapa 8.
 *
 * Objetivo:
 * Garantir que a limpeza final adicionou camadas reutilizáveis de segurança e
 * documentação sem remover a compatibilidade preservada nas etapas anteriores.
 *
 * Este teste continua estático para poder rodar fora do WordPress. Ele protege
 * contratos de arquitetura, não substitui testes manuais de admin/AJAX/REST.
 */

$rootPath = dirname(__DIR__, 2);
$failures = [];

$assertFileExists = static function (string $relativePath) use ($rootPath, &$failures): void {
    if (!is_file($rootPath . '/' . $relativePath)) {
        $failures[] = "Arquivo esperado não encontrado: {$relativePath}";
    }
};

$assertContains = static function (string $relativePath, string $expectedText) use ($rootPath, &$failures): void {
    $filePath = $rootPath . '/' . $relativePath;
    $contents = is_file($filePath) ? file_get_contents($filePath) : '';

    if ($contents === false || strpos($contents, $expectedText) === false) {
        $failures[] = "Texto esperado não encontrado em {$relativePath}: {$expectedText}";
    }
};

$assertFileExists('app/Security/RequestGuard.php');
$assertFileExists('docs/final-hardening-report.md');
$assertFileExists('docs/manual-validation-checklist.md');

$assertContains('app/Security/RequestGuard.php', 'function requireCapability');
$assertContains('app/Security/RequestGuard.php', 'function requireAdminNonce');
$assertContains('app/Security/RequestGuard.php', 'function readSanitizedText');
$assertContains('app/Security/RequestGuard.php', 'function readPositiveInt');
$assertContains('app/Security/RequestGuard.php', 'function redirectToAdminPage');
$assertContains('app/Views/View.php', 'realpath($this->pluginPath)');
$assertContains('app/Views/View.php', 'Caminho de View inválido.');
$assertContains('app/Controllers/Admin/AdminPageController.php', 'RequestGuard');
$assertContains('acme-account-control.php', "/app/Security/RequestGuard.php");
$assertContains('composer.json', 'FinalHardeningTest.php');

if ($failures !== []) {
    fwrite(STDERR, "Falhas de hardening final:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Final hardening regression test passed.\n");
