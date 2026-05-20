<?php

declare(strict_types=1);

/**
 * Entrada alternativa do ACME.
 *
 * Objetivo:
 * Atender à nova organização esperada sem alterar o arquivo principal já usado
 * pelo WordPress (`acme-account-control.php`). Este arquivo não possui cabeçalho
 * de plugin para evitar que o WordPress liste o mesmo plugin duas vezes.
 *
 * Compatibilidade:
 * Caso alguma automação interna passe a usar `acme.php`, ela cairá no mesmo
 * bootstrap MVC centralizado.
 */
require_once __DIR__ . '/acme-account-control.php';
