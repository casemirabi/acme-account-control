<?php

declare(strict_types=1);

/**
 * Teste estrutural da Etapa 6.
 *
 * Objetivo:
 * Garantir que a extração de Models/Repositories foi aplicada sem remover a
 * fachada `CreditRepository`, que ainda é necessária para compatibilidade com os
 * Services e wrappers legados.
 */

$rootPath = dirname(__DIR__, 2);

$assertFileExists = static function (string $relativePath) use ($rootPath): void {
    $fullPath = $rootPath . '/' . $relativePath;

    if (!is_file($fullPath)) {
        fwrite(STDERR, "Arquivo esperado não encontrado: {$relativePath}\n");
        exit(1);
    }
};

$assertContains = static function (string $relativePath, string $expectedText) use ($rootPath): void {
    $fullPath = $rootPath . '/' . $relativePath;
    $contents = is_file($fullPath) ? file_get_contents($fullPath) : '';

    if ($contents === false || strpos($contents, $expectedText) === false) {
        fwrite(STDERR, "Texto esperado não encontrado em {$relativePath}: {$expectedText}\n");
        exit(1);
    }
};

$assertFileExists('app/Models/ServiceRepository.php');
$assertFileExists('app/Models/WalletRepository.php');
$assertFileExists('app/Models/CreditTransactionRepository.php');
$assertFileExists('app/Models/DatabaseTransactionManager.php');

$assertContains('app/Models/CreditRepository.php', 'private $serviceRepository;');
$assertContains('app/Models/CreditRepository.php', 'private $walletRepository;');
$assertContains('app/Models/CreditRepository.php', 'private $transactionRepository;');
$assertContains('app/Models/CreditRepository.php', 'private $transactionManager;');
$assertContains('app/Models/CreditRepository.php', 'return $this->serviceRepository->findBySlug($slug);');
$assertContains('app/Models/CreditRepository.php', 'return $this->walletRepository->findByUserAndService($userId, $serviceId);');
$assertContains('app/Models/CreditRepository.php', 'return $this->transactionRepository->insert($transactionData);');

$assertContains('acme-account-control.php', "/app/Models/ServiceRepository.php");
$assertContains('acme-account-control.php', "/app/Models/WalletRepository.php");
$assertContains('acme-account-control.php', "/app/Models/CreditTransactionRepository.php");
$assertContains('acme-account-control.php', "/app/Models/DatabaseTransactionManager.php");

fwrite(STDOUT, "Repository extraction regression test passed.\n");
