<?php

declare(strict_types=1);

/**
 * Teste de regressão do Batch 3.
 *
 * Objetivo:
 * Garantir que o manifesto legado duplicado foi removido e que o carregador
 * interno de arquivos de hooks permanece disponível com o novo nome.
 */
$pluginRoot = dirname(__DIR__, 2);
$failures = [];

$assertFileExists = static function (string $relativePath) use ($pluginRoot, &$failures): void {
    if (!file_exists($pluginRoot . DIRECTORY_SEPARATOR . $relativePath)) {
        $failures[] = "Arquivo esperado ausente: {$relativePath}";
    }
};

$assertFileMissing = static function (string $relativePath) use ($pluginRoot, &$failures): void {
    if (file_exists($pluginRoot . DIRECTORY_SEPARATOR . $relativePath)) {
        $failures[] = "Arquivo legado deveria ter sido removido: {$relativePath}";
    }
};

$assertContains = static function (string $relativePath, string $expectedText) use ($pluginRoot, &$failures): void {
    $filePath = $pluginRoot . DIRECTORY_SEPARATOR . $relativePath;
    if (!file_exists($filePath)) {
        $failures[] = "Não foi possível ler arquivo ausente: {$relativePath}";
        return;
    }

    $contents = (string) file_get_contents($filePath);
    if (strpos($contents, $expectedText) === false) {
        $failures[] = "Texto esperado não encontrado em {$relativePath}: {$expectedText}";
    }
};

$assertFileMissing('config/legacy-files.php');
$assertFileMissing('app/Hooks/LegacyHookRegistry.php');
$assertFileExists('app/Hooks/HookFileLoader.php');
$assertContains('acme-account-control.php', "app/Hooks/HookFileLoader.php");
$assertContains('app/Hooks/HookFileLoader.php', 'final class HookFileLoader');
$assertContains('app/Hooks/AdminHooks.php', 'new HookFileLoader');
$assertContains('app/Hooks/CompatibilityHooks.php', 'new HookFileLoader');
$assertContains('config/hook-files.php', 'includes/services/credits-engine.php');
$assertContains('config/hook-files.php', 'includes/controllers/clt-async.php');

if ($failures !== []) {
    fwrite(STDERR, "Falhas de regressão Batch 3:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Legacy removal Batch 3 OK.\n";
