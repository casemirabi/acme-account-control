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

## Etapa 5 - Migração inicial de regras de negócio para Services

Nesta etapa, o fluxo de créditos começou a sair do arquivo legado `includes/services/credits-engine.php` e foi separado em classes MVC simples:

- `app/Models/CreditRepository.php`
  - concentra queries de serviços, carteiras e transações;
  - preserva as mesmas tabelas legadas resolvidas por `acme_table_services()`, `acme_table_wallet()` e `acme_table_credit_transactions()`;
  - mantém transações SQL manuais (`START TRANSACTION`, `COMMIT`, `ROLLBACK`) porque o fluxo original já dependia desse comportamento.

- `app/Services/CreditTransactionService.php`
  - contém a regra de registro de transações de crédito;
  - preserva preenchimento automático de `service_slug` e `service_name`;
  - mantém o mesmo formato de retorno usado por `acme_credits_tx_log()`.

- `app/Services/CreditGrantService.php`
  - contém a regra de concessão de créditos;
  - atualiza/cria wallet;
  - registra auditoria na tabela de transações;
  - executa rollback em falhas para preservar consistência de saldo.

### Compatibilidade preservada

As funções públicas abaixo continuam existindo no arquivo legado e agora atuam como wrappers:

- `acme_debug_db_error()`
- `acme_service_get_by_slug()`
- `acme_wallet_get()`
- `acme_credits_tx_log()`
- `acme_credits_grant()`

Isso evita quebra em shortcodes, telas administrativas, callbacks `admin_post`, integrações e qualquer código externo que chame essas funções diretamente.

### Próximo alvo recomendado

Migrar gradualmente os fluxos de distribuição e transferência de créditos:

- `includes/services/credits-distribution.php`
- `includes/services/credits-transfer.php`
- `includes/controllers/credits-frontend.php`

Esses arquivos dependem do motor de créditos e devem ser movidos somente depois de validar concessão e auditoria em ambiente WordPress real.

## Etapa 6 — Extração inicial de Models/Repositories

Nesta etapa, a persistência do módulo de créditos foi dividida em repositórios menores, mantendo `CreditRepository` como fachada de compatibilidade.

### Arquivos criados

- `app/Models/ServiceRepository.php`
  - Centraliza consultas da tabela de serviços.
  - Preserva busca por slug, identidade por ID e listagem para seleção.

- `app/Models/WalletRepository.php`
  - Centraliza leitura e escrita de carteiras de crédito.
  - Mantém a chave lógica `master_user_id + service_id` usada pelo legado.

- `app/Models/CreditTransactionRepository.php`
  - Centraliza inserts na tabela de transações de crédito.
  - Preserva o schema usado por relatórios e auditoria.

- `app/Models/DatabaseTransactionManager.php`
  - Centraliza `START TRANSACTION`, `COMMIT` e `ROLLBACK`.
  - Deve ser usado apenas em fluxos críticos, como concessão de créditos.

### Decisão de compatibilidade

`CreditRepository` continua existindo porque `CreditGrantService`, `CreditTransactionService` e wrappers legados já dependem dele.
Em vez de remover essa classe, ela agora atua como fachada simples para os repositórios específicos.

Essa decisão evita mudanças bruscas e reduz risco de regressão.

### Fluxo atual

```text
Funções legadas
  → Services
    → CreditRepository [fachada]
      → ServiceRepository
      → WalletRepository
      → CreditTransactionRepository
      → DatabaseTransactionManager
```

### O que ainda permanece legado

Ainda existem queries espalhadas em arquivos de shortcodes, relatórios e integrações assíncronas.
A migração deve continuar por domínio, priorizando baixo risco e testes de regressão.


## Etapa 7 — Controllers e Views

A primeira extração de Controllers/Views foi aplicada no painel público de controle da API.

- `app/Views/View.php` centraliza renderização de templates novos e legados.
- `app/Controllers/Frontend/ApiControlPanelController.php` passou a controlar o shortcode `[acme_api_control_panel]`.
- `app/Controllers/Admin/AdminPageController.php` prepara validações comuns de wp-admin.
- `app/Controllers/Admin/ApiConsumersAdminController.php` prepara a migração segura dos callbacks administrativos de chaves da API.

Compatibilidade preservada:

- wrappers globais continuam existindo;
- shortcodes não foram renomeados;
- templates legados continuam disponíveis;
- hooks públicos não foram removidos.
