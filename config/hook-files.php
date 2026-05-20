<?php

declare(strict_types=1);

/**
 * Manifesto central de hooks do ACME agrupado por responsabilidade.
 *
 * Objetivo:
 * Facilitar a localização dos arquivos que registram hooks do WordPress sem
 * alterar o comportamento público do plugin.
 *
 * Como ler este arquivo:
 * - Cada grupo representa uma área funcional da aplicação.
 * - A ordem dos grupos no HookRegistrar preserva a ordem de carregamento legada.
 * - `required=true` indica dependência crítica para o plugin funcionar.
 * - `required=false` indica integração/módulo opcional ou legado tolerante a
 *   ausência em instalações diferentes.
 *
 * Importante:
 * Alguns arquivos legados ainda misturam shortcodes, actions, filtros e funções
 * auxiliares. Eles foram colocados no grupo que representa seu contrato público
 * mais relevante. A separação fina será feita nas próximas etapas, quando a regra
 * de negócio migrar para Controllers, Services e Models.
 */
return [
    'compatibility_base' => [
        // Helpers globais, filtros REST, callbacks AJAX e funções compartilhadas.
        ['path' => 'includes/support/helpers.php', 'required' => true],

        // Suporte legado para consumidores de API usado por frontend/admin.
        ['path' => 'includes/support/api-consumers.php', 'required' => false],
    ],

    'users' => [
        // Módulo Users consolidado. Ainda preserva callbacks globais antigos.
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
    ],

    'business_core' => [
        // Serviços base usados por créditos, usuários e integrações.
        ['path' => 'includes/services/services-module.php', 'required' => true],
        ['path' => 'includes/Modules/Users/UsersStatusService.php', 'required' => true],
        ['path' => 'includes/services/credits-engine.php', 'required' => true],
        ['path' => 'includes/services/credits-module.php', 'required' => true],
        ['path' => 'includes/services/provider-balance-service.php', 'required' => false],
    ],

    'frontend' => [
        // Shortcodes e assets públicos de créditos, CLT, INSS e perfil.
        ['path' => 'includes/controllers/shortcodes_credits.php', 'required' => false],
    ],

    'admin' => [
        // Menus, telas e ações administrativas principais.
        ['path' => 'includes/controllers/credits-admin.php', 'required' => false],
        ['path' => 'includes/controllers/credits-frontend.php', 'required' => false],
        ['path' => 'includes/controllers/api-consumers-admin.php', 'required' => false],
    ],

    'credit_admin' => [
        // Persistência e actions administrativas relacionadas a transações/lotes.
        ['path' => 'includes/models/credits-transactions.php', 'required' => false],
        ['path' => 'includes/services/credits-distribution.php', 'required' => false],
        ['path' => 'includes/services/credits-transfer.php', 'required' => false],
        ['path' => 'includes/models/credits-contracts.php', 'required' => false],
        ['path' => 'includes/models/credits-lots.php', 'required' => false],
    ],

    'external_api' => [
        // Controle global de API e painel de consumidores no frontend.
        ['path' => 'includes/support/role-labels.php', 'required' => false],
        ['path' => 'includes/support/api-global-control.php', 'required' => true],
        ['path' => 'includes/controllers/api-consumers-frontend.php', 'required' => true],
    ],

    'rest_api' => [
        // Controllers assíncronos com rotas REST e integrações externas.
        ['path' => 'includes/controllers/clt-async.php', 'required' => true],
        ['path' => 'includes/controllers/inss-async.php', 'required' => false],
        ['path' => 'includes/controllers/pbank-async.php', 'required' => false],
    ],

    'ajax' => [
        // Reservado para a próxima etapa: extrair callbacks wp_ajax_* de helpers.php.
    ],

    'cron' => [
        // Reservado para a próxima etapa: extrair callbacks assíncronos de helpers.php e controllers REST.
    ],

    'reports' => [
        // Exportações e relatórios carregados no final para enxergar filtros/serviços já registrados.
        ['path' => 'includes/services/reports-export.php', 'required' => false],
        ['path' => 'includes/support/reports.php', 'required' => false],
    ],
];
