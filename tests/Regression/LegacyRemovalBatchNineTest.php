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

$frontendController = 'includes/controllers/credits-frontend.php';

$assertFileExists($frontendController);
$assertFileExists('app/Models/ServiceRepository.php');
$assertFileExists('docs/legacy-removal-batch-9.md');

// O controller deve resolver serviços por Repository, não por SQL inline.
$assertContains($frontendController, 'new \\Acme\\AccountControl\\Models\\ServiceRepository($wpdb)');
$assertContains($frontendController, '$serviceRepository->findBySlug($service_slug)');

// O status do usuário não deve mais ser consultado por SELECT direto neste controller.
$assertContains($frontendController, 'acme_users_repo_get_status($user_id)');
$assertNotContains($frontendController, 'SELECT status\n       FROM {$statusT}');

// A consulta de serviço por slug não deve voltar ao controller.
$assertNotContains($frontendController, 'SELECT id FROM {$servicesT} WHERE slug=%s LIMIT 1');

// A query permanece centralizada no repository.
$assertContains(
    'app/Models/ServiceRepository.php',
    'SELECT id, slug, name, credits_cost FROM {$servicesTable} WHERE slug=%s LIMIT 1'
);

fwrite(STDOUT, "Legacy removal batch 9 regression passed.\n");
