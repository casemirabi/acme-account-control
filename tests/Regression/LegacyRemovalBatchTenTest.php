<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$assertFileExists = static function (string $relativePath) use ($root): void {
    $fullPath = $root . '/' . $relativePath;

    if (!file_exists($fullPath)) {
        fwrite(STDERR, "Expected file to exist: {$relativePath}\n");
        exit(1);
    }
};

$assertFileMissing = static function (string $relativePath) use ($root): void {
    $fullPath = $root . '/' . $relativePath;

    if (file_exists($fullPath)) {
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

$assertFileExists('config/hook-files.php');
$assertFileExists('app/Hooks/HookRegistrar.php');
$assertFileExists('docs/legacy-removal-batch-10.md');

// Placeholders vazios de hooks não devem continuar no manifesto ativo.
$assertNotContains('config/hook-files.php', "'ajax' => [");
$assertNotContains('config/hook-files.php', "'cron' => [");

// O registrador não deve instanciar loaders que não carregam arquivos reais.
$assertNotContains('app/Hooks/HookRegistrar.php', 'new AjaxHooks');
$assertNotContains('app/Hooks/HookRegistrar.php', 'new CronHooks');

// As classes removidas não tinham arquivos no manifesto e eram ruído estrutural.
$assertFileMissing('app/Hooks/AjaxHooks.php');
$assertFileMissing('app/Hooks/CronHooks.php');

// Os grupos reais que carregam callbacks públicos continuam preservados.
$assertContains('config/hook-files.php', "'compatibility_base' => [");
$assertContains('config/hook-files.php', "'rest_api' => [");
$assertContains('config/hook-files.php', "'business_core' => [");

fwrite(STDOUT, "Legacy removal batch 10 regression passed.\n");
