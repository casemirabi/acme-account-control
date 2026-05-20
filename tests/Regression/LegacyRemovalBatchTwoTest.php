<?php

declare(strict_types=1);

/**
 * Teste estrutural da segunda remoção segura de legado.
 *
 * Objetivo:
 * Confirmar que somente arquivos órfãos e não carregados foram removidos.
 *
 * Por que este teste existe:
 * A fase atual deve remover legado com segurança. Portanto, este teste protege
 * contratos ativos e impede que arquivos removidos retornem aos manifestos de
 * carregamento, onde poderiam registrar hooks procedurais novamente.
 */

$pluginRoot = dirname(__DIR__, 2);
$failures = [];

$assertFileExists = static function (string $relativePath) use ($pluginRoot, &$failures): void {
    if (!is_file($pluginRoot . '/' . $relativePath)) {
        $failures[] = "Arquivo ativo esperado não encontrado: {$relativePath}";
    }
};

$assertFileMissing = static function (string $relativePath) use ($pluginRoot, &$failures): void {
    if (file_exists($pluginRoot . '/' . $relativePath)) {
        $failures[] = "Arquivo legado deveria ter sido removido: {$relativePath}";
    }
};

$assertContains = static function (string $relativePath, string $expectedText) use ($pluginRoot, &$failures): void {
    $filePath = $pluginRoot . '/' . $relativePath;
    $contents = is_file($filePath) ? file_get_contents($filePath) : '';

    if ($contents === false || strpos($contents, $expectedText) === false) {
        $failures[] = "Texto esperado não encontrado em {$relativePath}: {$expectedText}";
    }
};

$assertNotContains = static function (string $relativePath, string $legacyPath) use ($pluginRoot, &$failures): void {
    $filePath = $pluginRoot . '/' . $relativePath;
    $contents = is_file($filePath) ? file_get_contents($filePath) : '';

    if ($contents === false) {
        $failures[] = "Não foi possível ler {$relativePath}";
        return;
    }

    if (strpos($contents, $legacyPath) !== false) {
        $failures[] = "Referência legada ainda encontrada em {$relativePath}: {$legacyPath}";
    }
};

$removedLegacyFiles = [
    'includes/models/credits-admin-model.php',
    'includes/services/credits-transactions-module.php',
];

$activeCompatibilityFiles = [
    'includes/services/credits-engine.php',
    'includes/controllers/credits-admin.php',
    'includes/controllers/credits-frontend.php',
    'includes/models/credits-transactions.php',
    'includes/models/credits-contracts.php',
    'includes/models/credits-lots.php',
];

foreach ($removedLegacyFiles as $removedLegacyFile) {
    $assertFileMissing($removedLegacyFile);
    $assertNotContains('config/hook-files.php', $removedLegacyFile);
}

foreach ($activeCompatibilityFiles as $activeCompatibilityFile) {
    $assertFileExists($activeCompatibilityFile);
}

// Garante que a função pública de transações continua existindo na camada ativa.
$assertContains('includes/services/credits-engine.php', 'function acme_credits_tx_log(array $data): array');
$assertContains('docs/legacy-removal-batch-2.md', 'includes/services/credits-engine.php');

if ($failures !== []) {
    fwrite(STDERR, "Falhas na remoção segura de legado do Batch 2:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Legacy removal batch 2 regression test passed.\n");
