# Legacy Removal Batch 8 — SQL procedural residual

## Objetivo

Reduzir SQL procedural residual em controllers legados sem alterar comportamento público.

## Alteração aplicada

Foi removida a query direta da tela administrativa de créditos em:

```text
includes/controllers/credits-admin.php
```

Antes o controller acessava diretamente `$wpdb->get_results()` para listar serviços.

Agora ele delega a listagem para:

```text
app/Models/ServiceRepository.php
```

## Motivo

Controllers devem apenas:

- validar permissão
- preparar dados para a view/formulário
- chamar Models/Repositories ou Services
- preservar resposta e fluxo do WordPress

Queries SQL devem ficar isoladas em Models/Repositories.

## Compatibilidade preservada

Nada foi removido publicamente:

- menu `acme_root` preservado
- submenu `acme_credits` preservado
- callback `acme_credits_admin_page()` preservado
- action `acme_admin_grant_credits` preservada
- capability `manage_options` preservada
- schema de banco preservado
- tabela retornada por `acme_table_services()` preservada via `ServiceRepository`

## Risco

Baixo.

A query migrada já existia no `ServiceRepository::listForSelection()` com os mesmos campos e mesma ordenação:

```sql
SELECT slug, name, credits_cost FROM {services_table} ORDER BY name ASC
```

## Validação recomendada

- acessar `/wp-admin/admin.php?page=acme_credits`
- confirmar que a lista de serviços aparece normalmente
- confirmar envio do formulário de concessão de créditos
- confirmar ausência de erro fatal em ambientes sem dados

## Próximo alvo

Continuar a redução de SQL procedural em controllers, priorizando:

- `includes/controllers/credits-frontend.php`
- trechos pequenos de `includes/controllers/shortcodes_credits.php`
- consultas repetidas de serviços e carteiras

Arquivos grandes de AJAX/shortcodes devem ser migrados por fatias pequenas, nunca em bloco único.
