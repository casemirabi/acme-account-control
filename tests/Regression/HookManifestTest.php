<?php

declare(strict_types=1);

/**
 * Teste de regressão do manifesto de hooks.
 *
 * Objetivo:
 * Garantir que o manifesto único de carregamento continue íntegro após a
 * remoção do manifesto legado duplicado.
 *
 * Por que isso é importante:
 * Muitos arquivos ainda registram hooks no momento em que são carregados. Se a
 * ordem mudar ou um caminho crítico sumir, shortcodes, admin_post, AJAX, REST
 * API ou funções globais podem deixar de existir no momento esperado.
 */
$pluginRoot = dirname(__DIR__, 2);
$hookGroups = require $pluginRoot . '/config/hook-files.php';

$flattenedHookManifest = [];
foreach ($hookGroups as $groupName => $groupFiles) {
    if (!is_array($groupFiles)) {
        fwrite(STDERR, "Hook manifest regression failed: grupo inválido {$groupName}.\n");
        exit(1);
    }

    foreach ($groupFiles as $groupFile) {
        if (!isset($groupFile['path'], $groupFile['required'])) {
            fwrite(STDERR, "Hook manifest regression failed: item incompleto em {$groupName}.\n");
            exit(1);
        }

        $flattenedHookManifest[] = $groupFile['path'];
    }
}

$expectedCriticalFiles = [
    'includes/support/helpers.php',
    'includes/Modules/Users/UsersActivation.php',
    'includes/services/services-module.php',
    'includes/services/credits-engine.php',
    'includes/controllers/clt-async.php',
    'includes/controllers/api-consumers-frontend.php',
];

foreach ($expectedCriticalFiles as $expectedCriticalFile) {
    if (!in_array($expectedCriticalFile, $flattenedHookManifest, true)) {
        fwrite(STDERR, "Hook manifest regression failed: arquivo crítico ausente {$expectedCriticalFile}.\n");
        exit(1);
    }
}

if (count($flattenedHookManifest) !== count(array_unique($flattenedHookManifest))) {
    fwrite(STDERR, "Hook manifest regression failed: há arquivos duplicados no manifesto.\n");
    exit(1);
}

if (file_exists($pluginRoot . '/config/legacy-files.php')) {
    fwrite(STDERR, "Hook manifest regression failed: manifesto legado duplicado ainda existe.\n");
    exit(1);
}

echo "Hook manifest regression OK.\n";
