<?php

declare(strict_types=1);

/**
 * Regressão do Batch 11.
 *
 * Objetivo:
 * Garantir que a limpeza final do bootstrap não deixou `require_once` apontando
 * para arquivos removidos. Isso evita falha fatal antes mesmo do WordPress
 * inicializar os hooks públicos do plugin.
 */
$pluginRoot = dirname(__DIR__, 2);
$mainPluginFile = $pluginRoot . '/acme-account-control.php';

if (!is_file($mainPluginFile)) {
    fwrite(STDERR, 'Arquivo principal do plugin não encontrado.' . PHP_EOL);
    exit(1);
}

$mainPluginSource = file_get_contents($mainPluginFile);

if ($mainPluginSource === false) {
    fwrite(STDERR, 'Não foi possível ler o arquivo principal do plugin.' . PHP_EOL);
    exit(1);
}

$forbiddenRequires = [
    "app/Hooks/AjaxHooks.php",
    "app/Hooks/CronHooks.php",
];

foreach ($forbiddenRequires as $forbiddenRequire) {
    if (strpos($mainPluginSource, $forbiddenRequire) !== false) {
        fwrite(STDERR, sprintf('Require morto ainda presente: %s', $forbiddenRequire) . PHP_EOL);
        exit(1);
    }
}

if (preg_match_all("/require_once __DIR__ \. '([^']+)'/", $mainPluginSource, $matches) === false) {
    fwrite(STDERR, 'Falha ao analisar require_once do arquivo principal.' . PHP_EOL);
    exit(1);
}

foreach ($matches[1] as $relativeRequiredFile) {
    $absoluteRequiredFile = $pluginRoot . $relativeRequiredFile;

    if (!is_file($absoluteRequiredFile)) {
        fwrite(STDERR, sprintf('Require aponta para arquivo inexistente: %s', $relativeRequiredFile) . PHP_EOL);
        exit(1);
    }
}

echo 'LegacyRemovalBatchElevenTest OK' . PHP_EOL;
