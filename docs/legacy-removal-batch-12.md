# Legacy Removal Batch 12 — Remoção de órfãos e manifesto duplicado

## Objetivo

Remover apenas legado de baixo risco, sem alterar contratos públicos, hooks, shortcodes, AJAX, REST API ou funções `acme_*` ainda utilizadas.

## Arquivos removidos

- `config/legacy-files.php`
- `includes/views/api-consumers-panel.php`
- `includes/models/credits-admin-model.php`
- `includes/models/users-module.php`
- `includes/services/credits-transactions-module.php`
- `includes/services/users-manage-service.php`
- `includes/services/users-service.php`
- `includes/services/users-status-service.php`

## Critério de segurança

Os arquivos removidos não fazem parte do manifesto ativo `config/hook-files.php` ou já possuem substituto consolidado:

- View moderna em `app/Views/frontend/api-consumers-panel.php`.
- Módulo Users consolidado em `includes/Modules/Users/`.
- Manifesto ativo único em `config/hook-files.php`.
- Persistência/serviços de créditos parcialmente consolidados em `app/Models` e `app/Services`.

## Compatibilidade

Não foram removidas funções públicas carregadas pelo manifesto ativo.
Não foram removidos endpoints REST, AJAX, shortcodes ou hooks públicos.

## Rollback

Restaurar os arquivos removidos a partir do controle de versão. Nenhum backup de legado foi mantido no pacote final para evitar reintrodução acidental de código morto.

## Validação obrigatória

- PHP lint em todos os arquivos PHP.
- Testes de regressão existentes.
- Validação manual de admin, frontend, AJAX, REST API e banco.

## Complemento do batch

Também foram removidos placeholders estruturais sem uso ativo:

- `app/Hooks/AjaxHooks.php`
- `app/Hooks/CronHooks.php`
- `app/Hooks/LegacyHookRegistry.php`

Esses arquivos não eram carregados pelo bootstrap atual e os grupos reais continuam centralizados em `config/hook-files.php` via `HookRegistrar`.

## Migração pontual de serviço PDF INSS

- `includes/services/InssTcpdfService.php` foi movido para `app/Services/InssTcpdfService.php`.
- A classe global `InssTcpdfService` foi preservada para compatibilidade.
- O carregamento preguiçoso em `includes/support/helpers.php` passou a apontar para o novo caminho.

Esta mudança reduz `includes/` sem alterar API pública nem assinatura do serviço.

## Migração de views do módulo Users

- `includes/Modules/Users/Views/my-profile-page.php` foi movida para `app/Views/users/my-profile-page.php`.
- `includes/Modules/Users/Views/manage-grandchildren.php` foi movida para `app/Views/users/manage-grandchildren.php`.
- Os pontos de renderização foram atualizados para os novos caminhos.

A mudança remove templates de `includes/` e mantém a renderização existente baseada em variáveis locais.
