# Legacy Removal Batch 11 — Limpeza final de bootstrap morto

## Objetivo

Finalizar a limpeza de legado morto confirmado após a consolidação de hooks do Batch 10.

## Alteração realizada

Foram removidos do arquivo principal `acme-account-control.php` os `require_once` residuais para:

- `app/Hooks/AjaxHooks.php`
- `app/Hooks/CronHooks.php`

Essas classes já haviam sido removidas no Batch 10 porque os grupos `ajax` e `cron` estavam vazios no manifesto central.

## Motivo

Os `require_once` apontavam para arquivos inexistentes e poderiam causar falha fatal no bootstrap do plugin.

## Compatibilidade

Nenhum hook público, endpoint, shortcode, AJAX handler, cron job ou função global foi removido.

A mudança apenas elimina referências mortas no bootstrap principal.

## Validação

Foi adicionado teste de regressão para garantir que todos os `require_once __DIR__` do arquivo principal apontem para arquivos existentes.

## Risco

Baixo. A alteração remove apenas referências a classes já inexistentes e sem grupos ativos no carregamento central.
