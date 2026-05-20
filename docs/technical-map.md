# Mapa técnico inicial do plugin ACME

Este mapa foi gerado antes/depois da primeira etapa de refatoração segura para orientar as próximas migrações.

## Hooks WordPress / shortcodes

- `includes/Modules/Users/UsersActionsController.php`
- `includes/Modules/Users/UsersAdminHooks.php`
- `includes/Modules/Users/UsersAdminListController.php`
- `includes/Modules/Users/UsersLoginGuard.php`
- `includes/Modules/Users/UsersRegistrationHooks.php`
- `includes/Modules/Users/UsersShortcodesController.php`
- `includes/controllers/api-consumers-admin.php`
- `includes/controllers/api-consumers-frontend.php`
- `includes/controllers/clt-async.php`
- `includes/controllers/credits-admin.php`
- `includes/controllers/credits-frontend.php`
- `includes/controllers/inss-async.php`
- `includes/controllers/pbank-async.php`
- `includes/controllers/shortcodes_credits.php`
- `includes/models/credits-transactions.php`
- `includes/services/credits-module.php`
- `includes/services/credits-transactions-module.php`
- `includes/services/reports-export.php`
- `includes/support/api-consumers.php`
- `includes/support/helpers.php`
- `includes/support/reports.php`

## REST API

- `includes/controllers/clt-async.php`
- `includes/controllers/inss-async.php`
- `includes/controllers/pbank-async.php`

## AJAX / admin_post

- `includes/Modules/Users/UsersActionsController.php`
- `includes/Modules/Users/UsersAdminListController.php`
- `includes/controllers/api-consumers-admin.php`
- `includes/controllers/credits-frontend.php`
- `includes/models/credits-transactions.php`
- `includes/services/credits-transactions-module.php`
- `includes/services/reports-export.php`
- `includes/support/helpers.php`

## Banco de dados

- `app/Hooks/ActivationHooks.php`
- `includes/Modules/Users/UsersActivation.php`
- `includes/Modules/Users/UsersRegistrationHooks.php`
- `includes/Modules/Users/UsersRepository.php`
- `includes/Modules/Users/UsersShortcodesController.php`
- `includes/Modules/Users/UsersStatusService.php`
- `includes/controllers/clt-async.php`
- `includes/controllers/credits-admin.php`
- `includes/controllers/credits-frontend.php`
- `includes/controllers/inss-async.php`
- `includes/controllers/shortcodes_credits.php`
- `includes/models/credits-admin-model.php`
- `includes/models/credits-contracts.php`
- `includes/models/credits-lots.php`
- `includes/models/credits-transactions.php`
- `includes/services/credits-distribution.php`
- `includes/services/credits-engine.php`
- `includes/services/credits-module.php`
- `includes/services/credits-transactions-module.php`
- `includes/services/credits-transfer.php`
- `includes/services/services-module.php`
- `includes/support/api-consumers.php`
- `includes/support/helpers.php`
- `includes/support/reports.php`

## Integrações externas

- `config/constants.php`
- `includes/controllers/clt-async.php`
- `includes/controllers/inss-async.php`
- `includes/controllers/pbank-async.php`
- `includes/controllers/shortcodes_credits.php`
- `includes/services/InssTcpdfService.php`
- `includes/services/provider-balance-service.php`

## Uploads

- Nenhuma ocorrência encontrada.

## Cron

- Nenhuma ocorrência encontrada.
