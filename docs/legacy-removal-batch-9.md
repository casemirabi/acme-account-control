# Legacy Removal Batch 9 — SQL residual em créditos frontend

## Objetivo

Continuar a remoção gradual de SQL procedural residual sem alterar endpoints públicos, hooks, shortcodes ou funções globais.

## Alteração realizada

O arquivo `includes/controllers/credits-frontend.php` deixou de executar consultas diretas para resolver serviços por slug nos fluxos de:

- concessão de créditos
- recuperação de créditos

Antes, o controller acessava diretamente `$wpdb->get_var()` com `SELECT id FROM ... WHERE slug=%s`.

Agora, o controller delega essa responsabilidade para:

```text
app/Models/ServiceRepository.php
```

usando:

```php
$serviceRepository = new \Acme\AccountControl\Models\ServiceRepository($wpdb);
$service = $serviceRepository->findBySlug($service_slug);
```

## Compatibilidade preservada

Nada público foi removido.

Foram preservados:

- `admin_post_acme_admin_grant_credits`
- `admin_post_acme_recover_credits`
- contratos de POST existentes
- validações de nonce
- permissões
- redirects
- mensagens de erro
- funções globais chamadas pelos fluxos de créditos

## Decisão conservadora

A validação de status do usuário deixou de consultar SQL diretamente no controller e passou a usar `acme_users_repo_get_status()` quando disponível.

A migração completa do repository procedural de usuários para uma classe moderna foi adiada para um lote específico, porque essa área ainda concentra regras sensíveis de vínculo Master/Sub-Login.

## Risco

Baixo a médio.

A alteração reduz SQL no controller, mas não muda o comportamento funcional esperado.

## Rollback

Restaurar o bloco anterior de consulta direta em `includes/controllers/credits-frontend.php` a partir do Batch 8.
