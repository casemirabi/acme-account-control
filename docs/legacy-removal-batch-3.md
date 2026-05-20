# Legacy Removal Batch 3 — Consolidação do Carregamento

## Objetivo

Remover legado estrutural de carregamento sem alterar hooks públicos, shortcodes, AJAX, REST, cron ou funções globais `acme_*`.

## Alterações realizadas

### Removido

- `config/legacy-files.php`

Motivo:

- o arquivo era um manifesto duplicado;
- não era usado em runtime pelo `Bootstrap`;
- a fonte real de carregamento já é `config/hook-files.php`;
- manter dois manifestos aumentava risco de divergência e manutenção duplicada.

### Renomeado

- `app/Hooks/LegacyHookRegistry.php`
- para `app/Hooks/HookFileLoader.php`

Motivo:

- a responsabilidade real da classe é carregar arquivos de hooks por grupo funcional;
- o nome antigo reforçava acoplamento conceitual com legado;
- a mudança não altera slugs, endpoints, actions, filters, shortcodes ou callbacks públicos.

## Compatibilidade preservada

Não foram alterados:

- hooks públicos;
- REST routes;
- AJAX handlers;
- shortcodes;
- cron jobs;
- funções públicas `acme_*`;
- arquivos funcionais carregados por `config/hook-files.php`.

## Risco

Classificação: **baixo risco**.

Justificativa:

- remoção limitada a manifesto duplicado sem uso em runtime;
- renome de classe interna carregada diretamente pelo plugin;
- manifesto ativo preservado;
- testes atualizados para impedir perda de arquivos críticos.

## Validações adicionadas

O teste `tests/Regression/HookManifestTest.php` agora valida:

- existência dos arquivos críticos no manifesto ativo;
- ausência de duplicidades no manifesto ativo;
- remoção definitiva do manifesto duplicado `config/legacy-files.php`.

## Rollback

Se necessário:

1. restaurar `config/legacy-files.php` a partir do Batch 2;
2. renomear `app/Hooks/HookFileLoader.php` para `app/Hooks/LegacyHookRegistry.php`;
3. atualizar referências em `acme-account-control.php` e classes de hooks.
