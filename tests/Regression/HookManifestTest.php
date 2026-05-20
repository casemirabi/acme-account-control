<?php

declare(strict_types=1);

/**
 * Teste de regressão do manifesto de hooks.
 *
 * Objetivo:
 * Garantir que a nova organização por grupos continue carregando exatamente os
 * mesmos arquivos legados e na mesma ordem do manifesto anterior.
 *
 * Por que isso é importante:
 * Muitos arquivos ainda registram hooks no momento em que são carregados. Se a
 * ordem mudar, shortcodes, admin_post, AJAX, REST API ou funções globais podem
 * deixar de existir no momento esperado.
 */
$pluginRoot = dirname(__DIR__, 2);
$legacyManifest = require $pluginRoot . '/config/legacy-files.php';
$hookGroups = require $pluginRoot . '/config/hook-files.php';

$flattenedHookManifest = [];
foreach ($hookGroups as $groupFiles) {
    foreach ($groupFiles as $groupFile) {
        $flattenedHookManifest[] = $groupFile;
    }
}

if ($legacyManifest !== $flattenedHookManifest) {
    fwrite(STDERR, "Hook manifest regression failed: grouped manifest differs from legacy order.\n");
    exit(1);
}

echo "Hook manifest regression OK.\n";
