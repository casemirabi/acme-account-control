<?php

declare(strict_types=1);

/**
 * Testes de regressão estrutural do plugin ACME.
 *
 * Objetivo:
 * Garantir que a refatoração não removeu arquivos críticos, constantes legadas,
 * manifesto de includes nem rotinas de ativação esperadas.
 *
 * Este teste não inicializa o WordPress. Ele valida a estrutura do plugin de
 * forma segura em ambiente local/CI e complementa testes manuais dentro do WP.
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
    'acme-account-control.php',
    'app/Bootstrap.php',
    'app/Hooks/ActivationHooks.php',
    'app/Hooks/HookFileLoader.php',
    'app/Helpers/SafeRequire.php',
    'app/Services/VendorLoader.php',
    'config/constants.php',
    'config/hook-files.php',
] as $requiredFile) {
    $assertFileExists($requiredFile);
}

$assertContains('acme-account-control.php', 'Plugin Name: ACME Account Control');
$assertContains('acme-account-control.php', 'new Acme\AccountControl\Bootstrap');
$assertContains('config/constants.php', 'ACME_ACC_PATH');
$assertContains('config/constants.php', 'ACME_CLT_BRIDGE_URL');
$assertContains('app/Hooks/ActivationHooks.php', 'acme_services_activate');
$assertContains('app/Hooks/ActivationHooks.php', 'acme_inss_activate');
$assertContains('config/hook-files.php', 'includes/controllers/clt-async.php');
$assertContains('config/hook-files.php', 'includes/controllers/api-consumers-frontend.php');
$assertContains('app/Hooks/HookFileLoader.php', 'final class HookFileLoader');

if ($failures !== []) {
    fwrite(STDERR, "Falhas de regressão:
- " . implode("
- ", $failures) . "
");
    exit(1);
}

echo "OK - estrutura MVC e compatibilidade básica preservadas.
";
