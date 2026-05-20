<?php

declare(strict_types=1);

/**
 * Teste de regressão da migração inicial para Services/Models.
 *
 * Objetivo:
 * Validar que a regra de créditos foi movida para classes MVC sem remover as
 * funções públicas legadas que outras áreas do plugin ainda utilizam.
 *
 * Este teste é estático e não inicializa o WordPress. Ele protege a estrutura e
 * os contratos principais até existirem testes de integração com banco real.
 */

$pluginRoot = dirname(__DIR__, 2);
$failures = [];

$assertFileExists = static function (string $relativePath) use ($pluginRoot, &$failures): void {
    if (!file_exists($pluginRoot . DIRECTORY_SEPARATOR . $relativePath)) {
        $failures[] = "Arquivo obrigatório ausente: {$relativePath}";
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

foreach ([
    'app/Models/CreditRepository.php',
    'app/Services/CreditTransactionService.php',
    'app/Services/CreditGrantService.php',
] as $requiredFile) {
    $assertFileExists($requiredFile);
}

$assertContains('includes/services/credits-engine.php', 'function acme_credits_grant(');
$assertContains('includes/services/credits-engine.php', 'function acme_credits_tx_log(array $data): array');
$assertContains('includes/services/credits-engine.php', 'new CreditGrantService');
$assertContains('includes/services/credits-engine.php', 'new CreditTransactionService');
$assertContains('app/Models/CreditRepository.php', 'function findServiceBySlug');
$assertContains('app/Models/CreditRepository.php', 'function findWallet');
$assertContains('app/Services/CreditGrantService.php', 'function grant(');
$assertContains('app/Services/CreditTransactionService.php', 'function log(array $data): array');
$assertContains('acme-account-control.php', "app/Models/CreditRepository.php");
$assertContains('acme-account-control.php', "app/Services/CreditGrantService.php");

if ($failures !== []) {
    fwrite(STDERR, "Falhas de regressão de Services/Models:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Service extraction regression OK.\n";
