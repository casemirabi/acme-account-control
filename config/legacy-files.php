<?php

declare(strict_types=1);

/**
 * Manifesto de arquivos legados carregados pelo plugin.
 *
 * Objetivo:
 * Centralizar a ordem de carregamento para que hooks, funções globais e módulos
 * antigos sejam fáceis de localizar durante a migração gradual para MVC.
 *
 * Por que manter a ordem?
 * Muitos arquivos ainda expõem funções globais e registram hooks no momento do
 * require. Trocar a ordem sem testes pode quebrar shortcodes, AJAX, REST API ou
 * telas administrativas. Por isso, este manifesto preserva a sequência original
 * do plugin antes da refatoração.
 *
 * required=true significa que a ausência do arquivo é crítica e deve falhar cedo.
 * required=false indica módulo opcional ou legado que pode não existir em alguma
 * instalação sem derrubar todo o WordPress.
 */
return [
    ['path' => 'includes/support/helpers.php', 'required' => true],
    ['path' => 'includes/support/api-consumers.php', 'required' => false],

    // Módulo Users consolidado. Ainda é legado, mas já possui separação próxima de MVC.
    ['path' => 'includes/Modules/Users/UsersActivation.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersShortcodesController.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersActionsController.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersRepository.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersService.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersManageService.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersAdminHooks.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersRegistrationHooks.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersLoginGuard.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersAdminListController.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersRegistrationService.php', 'required' => true],

    ['path' => 'includes/services/services-module.php', 'required' => true],
    ['path' => 'includes/Modules/Users/UsersStatusService.php', 'required' => true],

    ['path' => 'includes/services/credits-engine.php', 'required' => true],
    ['path' => 'includes/services/credits-module.php', 'required' => true],
    ['path' => 'includes/services/provider-balance-service.php', 'required' => false],

    ['path' => 'includes/controllers/shortcodes_credits.php', 'required' => false],
    ['path' => 'includes/controllers/credits-admin.php', 'required' => false],
    ['path' => 'includes/controllers/credits-frontend.php', 'required' => false],
    ['path' => 'includes/controllers/api-consumers-admin.php', 'required' => false],

    ['path' => 'includes/models/credits-transactions.php', 'required' => false],
    ['path' => 'includes/services/credits-distribution.php', 'required' => false],
    ['path' => 'includes/services/credits-transfer.php', 'required' => false],

    ['path' => 'includes/models/credits-contracts.php', 'required' => false],
    ['path' => 'includes/models/credits-lots.php', 'required' => false],

    ['path' => 'includes/support/role-labels.php', 'required' => false],
    ['path' => 'includes/support/api-global-control.php', 'required' => true],
    ['path' => 'includes/controllers/api-consumers-frontend.php', 'required' => true],

    // Controllers assíncronos com REST API / integrações externas.
    ['path' => 'includes/controllers/clt-async.php', 'required' => true],
    ['path' => 'includes/controllers/inss-async.php', 'required' => false],
    ['path' => 'includes/controllers/pbank-async.php', 'required' => false],

    ['path' => 'includes/services/reports-export.php', 'required' => false],
    ['path' => 'includes/support/reports.php', 'required' => false],
];
