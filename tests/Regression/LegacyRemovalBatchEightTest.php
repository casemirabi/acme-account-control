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

$assertFileExists('app/Models/ServiceRepository.php');
$assertFileExists('includes/controllers/credits-admin.php');
$assertFileExists('docs/legacy-removal-batch-8.md');

// O controller admin deve delegar a listagem de serviços ao Repository.
$assertContains(
    'includes/controllers/credits-admin.php',
    'new \\Acme\\AccountControl\\Models\\ServiceRepository($wpdb)'
);
$assertContains('includes/controllers/credits-admin.php', '$services = $serviceRepository->listForSelection();');

// A query direta removida do controller não deve voltar.
$assertNotContains(
    'includes/controllers/credits-admin.php',
    '$wpdb->get_results("SELECT slug, name, credits_cost FROM {$servicesT} ORDER BY name ASC")'
);

// A query permanece centralizada no Repository, preservando comportamento.
$assertContains(
    'app/Models/ServiceRepository.php',
    'SELECT slug, name, credits_cost FROM {$servicesTable} ORDER BY name ASC'
);

fwrite(STDOUT, "Legacy removal batch 8 regression passed.\n");
