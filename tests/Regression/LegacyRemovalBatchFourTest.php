<?php

declare(strict_types=1);

/**
 * Teste estrutural do Batch 4.
 *
 * Objetivo:
 * Garantir que a observabilidade temporária de compatibilidade foi adicionada
 * sem remover o carregador central nem alterar o manifesto de hooks públicos.
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

$assertFileExists('app/Support/CompatibilityLogger.php');
$assertFileExists('docs/legacy-removal-batch-4.md');

$assertContains('app/Support/CompatibilityLogger.php', 'final class CompatibilityLogger');
$assertContains('app/Support/CompatibilityLogger.php', 'WP_DEBUG');
$assertContains('app/Support/CompatibilityLogger.php', 'legacyFileLoaded');
$assertContains('app/Hooks/HookFileLoader.php', 'CompatibilityLogger::legacyFileLoaded($relativePath);');
$assertContains('acme-account-control.php', "/app/Support/CompatibilityLogger.php");
$assertContains('config/hook-files.php', 'includes/controllers/clt-async.php');
$assertContains('config/hook-files.php', 'includes/controllers/api-consumers-frontend.php');
$assertContains('composer.json', 'LegacyRemovalBatchFourTest.php');

if ($failures !== []) {
    fwrite(STDERR, "Falhas do Batch 4:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Legacy removal batch 4 regression test passed.\n");
