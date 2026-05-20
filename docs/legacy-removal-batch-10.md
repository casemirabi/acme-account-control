# Legacy Removal Batch 10 — limpeza de placeholders de hooks

## Objetivo

Remover estruturas de hooks que já não carregavam nenhum arquivo real e que só
mantinham ruído na fase final de remoção do legado.

## Alterações realizadas

- Removidos os grupos vazios `ajax` e `cron` de `config/hook-files.php`.
- Removidas as chamadas correspondentes em `HookRegistrar`.
- Removidas as classes vazias `AjaxHooks` e `CronHooks`.

## Por que é seguro

Os grupos `ajax` e `cron` estavam vazios no manifesto ativo. Portanto, essas
classes não registravam callbacks, não carregavam arquivos e não alteravam o
comportamento público do plugin.

Os callbacks AJAX e assíncronos reais continuam preservados nos arquivos já
carregados pelos grupos existentes, especialmente `compatibility_base`,
`rest_api` e `business_core`.

## O que NÃO foi alterado

- Nenhum hook público foi removido.
- Nenhum `wp_ajax_*` real foi removido.
- Nenhuma rota REST foi removida.
- Nenhum cron/action assíncrono real foi removido.
- Nenhum shortcode foi removido.
- Nenhuma função pública `acme_*` foi removida.

## Validação esperada

- O manifesto não deve conter grupos vazios `ajax` ou `cron`.
- `HookRegistrar` não deve tentar registrar loaders sem arquivos.
- As classes `AjaxHooks` e `CronHooks` não devem existir mais.
