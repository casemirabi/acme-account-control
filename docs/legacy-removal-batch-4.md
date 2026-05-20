# Legacy Removal Batch 4 — Observabilidade de compatibilidade

## Objetivo

Preparar a próxima remoção segura do legado sem excluir contratos públicos.

Este lote adiciona observabilidade temporária para descobrir quais arquivos procedurais ainda são carregados em ambiente real antes de qualquer remoção mais agressiva.

## Alterações realizadas

### Adicionado

- `app/Support/CompatibilityLogger.php`

### Atualizado

- `app/Hooks/HookFileLoader.php`
- `acme-account-control.php`
- `composer.json`

## O que o logger faz

Quando `WP_DEBUG` estiver ativo, cada arquivo carregado pelo manifesto central `config/hook-files.php` registra uma linha no log do PHP:

```text
[ACME Compatibility] Legacy file loaded: includes/...
```

## Por que isso é seguro

- não altera nomes de funções públicas
- não altera hooks
- não altera shortcodes
- não altera rotas REST
- não altera AJAX
- não altera cron
- não altera queries
- não remove arquivos carregados

## Como usar na próxima etapa

1. Ativar `WP_DEBUG` em staging.
2. Navegar pelos fluxos críticos do plugin.
3. Coletar os arquivos procedurais realmente carregados.
4. Cruzar os logs com referências estáticas.
5. Remover apenas arquivos sem uso confirmado.

## Risco

Baixo.

A mudança só adiciona logs opcionais e centralizados durante o carregamento legado já existente.

## Rollback

Remover:

- `app/Support/CompatibilityLogger.php`
- chamada `CompatibilityLogger::legacyFileLoaded(...)` em `HookFileLoader.php`
- `require_once` do logger no arquivo principal
