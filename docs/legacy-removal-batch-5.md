# Legacy Removal Batch 5 — Deprecated Wrappers Formais

## Objetivo

Iniciar a formalização dos wrappers deprecated sem remover contratos públicos.

Este batch segue a diretriz principal da fase final: remover legado apenas quando houver segurança, preservando compatibilidade com hooks, callbacks, integrações e snippets externos.

## Alterações realizadas

### 1. `CompatibilityLogger` ampliado

Arquivo alterado:

```text
app/Support/CompatibilityLogger.php
```

Foi adicionado o método:

```php
deprecatedFunctionUsed(string $functionName, string $replacement): void
```

### Motivo

Registrar, apenas quando `WP_DEBUG` estiver ativo, quais funções públicas antigas ainda são chamadas.

### Impacto

- não altera comportamento público
- não muda retorno de funções
- não remove hooks
- não afeta produção quando `WP_DEBUG` está desativado

---

## 2. Wrappers antigos de usuários marcados como deprecated

Arquivo alterado:

```text
includes/Modules/Users/UsersActionsController.php
```

Funções marcadas:

```text
acme_controller_toggle_status()
acme_controller_bulk_activate()
acme_controller_bulk_deactivate()
acme_controller_set_password()
acme_controller_update_phone()
acme_controller_create_user()
```

Essas funções continuam existindo porque ainda são usadas como callbacks dos hooks `admin_post_*`.

---

## Classificação de risco

### Alto risco para remoção imediata

Esses wrappers não foram removidos porque estão vinculados a hooks públicos administrativos.

### Baixo risco para depreciação

A marcação como deprecated é segura porque:

- mantém nome da função
- mantém assinatura
- mantém fluxo original
- delega para a função atual existente
- registra uso temporário apenas em debug

---

## Compatibilidade preservada

Nada foi removido.

Foram preservados:

- hooks `admin_post_*`
- funções públicas antigas
- redirecionamentos
- validações existentes
- nonces
- permissões
- comportamento de frontend/admin

---

## Próxima ação recomendada

Após monitoramento:

1. verificar logs de uso desses wrappers
2. confirmar se integrações externas ainda chamam `acme_controller_*`
3. migrar o registro dos hooks para callbacks finais quando seguro
4. remover wrappers apenas em versão futura e documentada

