<?php

declare(strict_types=1);

/**
 * Teste estrutural do Batch 6.
 *
 * Objetivo:
 * Garantir que wrappers públicos de crédito foram instrumentados como
 * deprecated, sem remoção do contrato público, e que o fallback morto de
 * `credits-lots.php` não voltou a existir.
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

$assertNotContains = static function (string $relativePath, string $unexpectedText) use ($pluginRoot, &$failures): void {
    $filePath = $pluginRoot . '/' . $relativePath;
    $contents = is_file($filePath) ? file_get_contents($filePath) : '';

    if ($contents !== false && strpos($contents, $unexpectedText) !== false) {
        $failures[] = "Texto legado inesperado encontrado em {$relativePath}: {$unexpectedText}";
    }
};

$assertFileExists('docs/legacy-removal-batch-6.md');
$assertFileExists('includes/services/credits-engine.php');
$assertFileExists('includes/models/credits-lots.php');

$assertContains('includes/services/credits-engine.php', 'use Acme\\AccountControl\\Support\\CompatibilityLogger;');
$assertContains('includes/services/credits-engine.php', '@deprecated 3.0.0 Use CreditRepository::findServiceBySlug() instead.');
$assertContains('includes/services/credits-engine.php', '@deprecated 3.0.0 Use CreditRepository::findWallet() instead.');
$assertContains('includes/services/credits-engine.php', '@deprecated 3.0.0 Use CreditTransactionService::log() instead.');
$assertContains('includes/services/credits-engine.php', '@deprecated 3.0.0 Use CreditGrantService::grant() instead.');
$assertContains('includes/services/credits-engine.php', "CompatibilityLogger::deprecatedFunctionUsed(__FUNCTION__, 'Acme\\\\AccountControl\\\\Services\\\\CreditGrantService::grant');");
$assertContains('includes/services/credits-engine.php', 'function acme_credits_grant(');
$assertContains('includes/services/credits-engine.php', 'function acme_credits_tx_log(array $data): array');

$assertNotContains('includes/models/credits-lots.php', "if (!function_exists('acme_credits_tx_log'))");
$assertNotContains('includes/models/credits-lots.php', 'Logger padrão para wp_credit_transactions');
$assertNotContains('includes/models/credits-lots.php', 'DESCRIBE {$t}');

$assertContains('composer.json', 'LegacyRemovalBatchSixTest.php');

if ($failures !== []) {
    fwrite(STDERR, "Falhas do Batch 6:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Legacy removal batch 6 regression test passed.\n");
