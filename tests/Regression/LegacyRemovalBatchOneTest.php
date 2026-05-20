<?php

declare(strict_types=1);

/**
 * Teste estrutural da primeira remoção segura de legado.
 *
 * Objetivo:
 * Garantir que apenas aliases/documentos legados do módulo Users foram removidos,
 * mantendo as implementações ativas que realmente carregam hooks, shortcodes,
 * services e repositories do módulo.
 *
 * Por que este teste existe:
 * A fase atual tem como prioridade remover legado sem quebrar compatibilidade
 * pública. Por isso, este teste protege os arquivos ativos e confirma que os
 * arquivos removidos não voltaram para os manifestos de carregamento.
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
    'includes/models/users-module.php',
    'includes/services/users-service.php',
    'includes/services/users-manage-service.php',
    'includes/services/users-status-service.php',
];

$activeUsersFiles = [
    'includes/Modules/Users/UsersActivation.php',
    'includes/Modules/Users/UsersActionsController.php',
    'includes/Modules/Users/UsersShortcodesController.php',
    'includes/Modules/Users/UsersRepository.php',
    'includes/Modules/Users/UsersService.php',
    'includes/Modules/Users/UsersManageService.php',
    'includes/Modules/Users/UsersStatusService.php',
    'includes/Modules/Users/UsersAdminHooks.php',
    'includes/Modules/Users/UsersRegistrationHooks.php',
    'includes/Modules/Users/UsersLoginGuard.php',
    'includes/Modules/Users/UsersAdminListController.php',
    'includes/Modules/Users/UsersRegistrationService.php',
];

foreach ($removedLegacyFiles as $removedLegacyFile) {
    $assertFileMissing($removedLegacyFile);
    $assertNotContains('config/legacy-files.php', $removedLegacyFile);
    $assertNotContains('config/hook-files.php', $removedLegacyFile);
}

foreach ($activeUsersFiles as $activeUsersFile) {
    $assertFileExists($activeUsersFile);
}

if ($failures !== []) {
    fwrite(STDERR, "Falhas na remoção segura de legado:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Legacy removal batch 1 regression test passed.\n");
