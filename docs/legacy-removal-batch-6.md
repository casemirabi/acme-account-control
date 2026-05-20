# Legacy Removal Batch 6 — Wrappers de crédito e fallback morto

## Objetivo

Continuar a remoção controlada do legado, sem alterar contratos públicos.

Este batch teve foco em dois pontos seguros:

1. marcar fachadas globais antigas de crédito como deprecated;
2. remover um fallback procedural inativo duplicado em `credits-lots.php`.

## Alterações realizadas

### 1. Wrappers públicos de crédito instrumentados

Arquivo alterado:

```text
includes/services/credits-engine.php
```

Funções preservadas e marcadas como deprecated:

- `acme_service_get_by_slug()`
- `acme_wallet_get()`
- `acme_credits_tx_log()`
- `acme_credits_grant()`

Todas continuam funcionando com a mesma assinatura pública.

A alteração adiciona:

- `@deprecated 3.0.0`
- `deprecated_function_run`
- `CompatibilityLogger::deprecatedFunctionUsed()`
- delegação para Repository/Service moderno

## 2. Remoção de fallback morto

Arquivo alterado:

```text
includes/models/credits-lots.php
```

Removido o bloco duplicado de fallback para:

- `acme_table_credit_transactions()`
- `acme_credits_tx_log()`

## Motivo da remoção

Esse fallback era legado e não deveria assumir responsabilidade de logging de transações.

O carregamento ativo já garante:

```text
includes/support/helpers.php
includes/services/credits-engine.php
```

Portanto:

- a resolução da tabela fica em `helpers.php`;
- o log de transação fica em `CreditTransactionService` via `credits-engine.php`;
- `credits-lots.php` continua apenas consumindo a API pública existente.

## Risco

Baixo.

Motivos:

- nenhum hook público foi removido;
- nenhuma função pública foi removida;
- nenhum shortcode foi alterado;
- nenhum endpoint REST/AJAX/cron foi alterado;
- a função `acme_credits_tx_log()` permanece ativa em `credits-engine.php`;
- o fallback removido estava protegido por `function_exists`, portanto não era executado no fluxo normal.

## Validações obrigatórias

Executar:

```bash
composer run test:syntax
composer run test:regression
```

## Próximo passo recomendado

Batch 7:

- continuar remoção de fallbacks duplicados;
- revisar funções globais utilitárias em `helpers.php`;
- extrair apenas o que já tiver implementação moderna equivalente;
- evitar remoção de funções públicas consumidas externamente.
