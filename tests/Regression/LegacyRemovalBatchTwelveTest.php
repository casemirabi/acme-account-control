<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$assertFileExists = static function (string $relativePath) use ($root): void {
    if (!file_exists($root . '/' . $relativePath)) {
        fwrite(STDERR, "Expected file to exist: {$relativePath}\n");
        exit(1);
    }
};

$assertFileMissing = static function (string $relativePath) use ($root): void {
    if (file_exists($root . '/' . $relativePath)) {
        fwrite(STDERR, "Expected file to be removed: {$relativePath}\n");
        exit(1);
    }
};

$assertContains = static function (string $relativePath, string $expected) use ($root): void {
    $contents = file_get_contents($root . '/' . $relativePath);

    if ($contents === false || strpos($contents, $expected) === false) {
        fwrite(STDERR, "Expected {$relativePath} to contain: {$expected}\n");
        exit(1);
    }
};

$assertNotContains = static function (string $relativePath, string $unexpected) use ($root): void {
    $contents = file_get_contents($root . '/' . $relativePath);

    if ($contents !== false && strpos($contents, $unexpected) !== false) {
        fwrite(STDERR, "Expected {$relativePath} not to contain: {$unexpected}\n");
        exit(1);
    }
};

// Manifesto duplicado e placeholders sem uso não devem voltar.
$assertFileMissing('config/legacy-files.php');
$assertFileMissing('app/Hooks/AjaxHooks.php');
$assertFileMissing('app/Hooks/CronHooks.php');
$assertFileMissing('app/Hooks/LegacyHookRegistry.php');

// Órfãos de includes removidos neste batch.
$assertFileMissing('includes/models/credits-admin-model.php');
$assertFileMissing('includes/models/users-module.php');
$assertFileMissing('includes/services/credits-transactions-module.php');
$assertFileMissing('includes/services/users-manage-service.php');
$assertFileMissing('includes/services/users-service.php');
$assertFileMissing('includes/services/users-status-service.php');
$assertFileMissing('includes/views/api-consumers-panel.php');

// Serviços e templates movidos para app sem quebrar contratos públicos.
$assertFileExists('app/Services/InssTcpdfService.php');
$assertFileMissing('includes/services/InssTcpdfService.php');
$assertContains('includes/support/helpers.php', "app/Services/InssTcpdfService.php");

$assertFileExists('app/Views/users/my-profile-page.php');
$assertFileExists('app/Views/users/manage-grandchildren.php');
$assertFileMissing('includes/Modules/Users/Views/my-profile-page.php');
$assertFileMissing('includes/Modules/Users/Views/manage-grandchildren.php');
$assertContains('includes/controllers/shortcodes_credits.php', "app/Views/users/my-profile-page.php");
$assertContains('includes/Modules/Users/UsersShortcodesController.php', "app/Views/users/manage-grandchildren.php");

// O pacote final não deve manter backup de legado removido.
$assertFileMissing('docs/removed-legacy-backup');

// O manifesto ativo ainda preserva os callbacks públicos carregados em includes.
$assertContains('config/hook-files.php', 'includes/support/helpers.php');
$assertContains('config/hook-files.php', 'includes/controllers/api-consumers-frontend.php');
$assertContains('config/hook-files.php', 'includes/controllers/clt-async.php');
$assertNotContains('config/hook-files.php', 'config/legacy-files.php');

fwrite(STDOUT, "Legacy removal batch 12 regression passed.\n");
