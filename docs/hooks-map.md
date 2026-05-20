# Mapa de hooks — ACME Account Control

Este documento registra a etapa de centralização dos hooks do WordPress.

## Objetivo da etapa

A refatoração atual não altera regras de negócio. Ela apenas organiza o carregamento dos arquivos legados em grupos funcionais dentro de `app/Hooks`.

Isso reduz o acoplamento do bootstrap principal e torna mais fácil localizar onde cada tipo de integração com WordPress começa.

## Arquivos principais

- `app/Hooks/HookRegistrar.php`: ponto único que inicializa todos os grupos de hooks.
- `config/hook-files.php`: manifesto agrupado por responsabilidade.
- `app/Hooks/AdminHooks.php`: menus, telas e `admin_post`.
- `app/Hooks/FrontendHooks.php`: shortcodes e assets públicos.
- `app/Hooks/RestApiHooks.php`: `rest_api_init` e endpoints REST.
- `app/Hooks/AjaxHooks.php`: reservado para extração dos callbacks `wp_ajax_*`.
- `app/Hooks/CronHooks.php`: reservado para extração dos jobs assíncronos.
- `app/Hooks/UserHooks.php`: cadastro, login guard, campos de usuário e hierarquia.
- `app/Hooks/ReportHooks.php`: exportações e relatórios.
- `app/Hooks/CompatibilityHooks.php`: helpers e suporte legado compartilhado.

## Garantia de compatibilidade

O arquivo `tests/Regression/HookManifestTest.php` valida o manifesto único `config/hook-files.php`, garantindo caminhos críticos, ausência de duplicidade e remoção do manifesto legado duplicado.

Esse teste garante que a nova organização continue carregando os mesmos arquivos, na mesma ordem, evitando regressões causadas por ordem de `require_once`.

## Próximo passo recomendado

A próxima etapa deve migrar callbacks específicos para classes reais, começando pelos hooks de menor risco:

1. assets administrativos e públicos;
2. shortcodes pequenos;
3. actions `admin_post` isoladas;
4. callbacks AJAX;
5. rotas REST;
6. jobs assíncronos/cron.

Os hooks críticos de cobrança, créditos e integrações externas devem ficar para depois de mais testes de regressão.
