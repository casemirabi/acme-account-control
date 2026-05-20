# Remoção Segura de Legado — Batch 2

## Objetivo

Remover apenas arquivos legados comprovadamente órfãos, sem alterar hooks públicos, REST APIs, AJAX handlers, shortcodes, cron jobs ou funções públicas ativas.

## Arquivos removidos

```text
includes/models/credits-admin-model.php
includes/services/credits-transactions-module.php
```

## Critério de segurança utilizado

Os arquivos removidos foram classificados como **baixo risco** porque:

1. não estavam no manifesto central `config/legacy-files.php`;
2. não estavam no manifesto agrupado `config/hook-files.php`;
3. não eram carregados pelo bootstrap principal;
4. não possuíam referência ativa fora deles mesmos;
5. tinham responsabilidades já cobertas por arquivos ativos da camada atual.

## Observações importantes

### `includes/models/credits-admin-model.php`

Arquivo procedural órfão que expunha `acme_credits_admin_model_get_page_data()`, sem referência ativa no restante do plugin.

### `includes/services/credits-transactions-module.php`

Arquivo procedural antigo com menu admin e callbacks internos. Ele não estava carregado pelo manifesto atual. A função pública sensível `acme_credits_tx_log()` permanece preservada na implementação ativa:

```text
includes/services/credits-engine.php
```

## Compatibilidade preservada

Não foram removidos:

- hooks públicos ativos;
- shortcodes;
- endpoints REST;
- AJAX handlers;
- admin_post ativo;
- funções públicas atualmente carregadas;
- templates ainda referenciados;
- camada de compatibilidade.

## Validação esperada

Após este batch, devem continuar passando:

```bash
composer run test:syntax
composer run test:regression
```

## Próximo batch recomendado

O próximo lote deve focar em revisar arquivos carregados como `required=false` no manifesto legado, mas sem removê-los antes de validar impacto em admin, frontend, REST, AJAX e integrações externas.
