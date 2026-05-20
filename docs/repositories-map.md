# Mapa de Repositories — ACME Account Control

Este documento registra a organização inicial da camada `Models` após a Etapa 6 da refatoração.

## Objetivo

Centralizar acesso ao banco em classes pequenas, evitando SQL espalhado em hooks, controllers, shortcodes e funções globais.

## Repositories criados

### `ServiceRepository`

Responsável por consultas da tabela de serviços.

Métodos principais:

- `findBySlug(string $slug)`
- `findIdentityById(int $serviceId)`
- `listForSelection()`

Risco preservado:

- O slug continua sendo contrato público.
- A ordenação por nome continua igual ao legado.

---

### `WalletRepository`

Responsável pela carteira de créditos.

Métodos principais:

- `findByUserAndService(int $userId, int $serviceId)`
- `insert(array $walletData)`
- `update(int $walletId, array $walletData)`

Risco preservado:

- A relação `master_user_id + service_id` não foi alterada.
- O retorno segue o comportamento de `$wpdb`.

---

### `CreditTransactionRepository`

Responsável pelo log/auditoria de créditos.

Métodos principais:

- `insert(array $transactionData)`

Risco preservado:

- O schema da tabela não foi alterado.
- Campos usados em relatórios permanecem iguais.

---

### `DatabaseTransactionManager`

Responsável por transações SQL manuais.

Métodos principais:

- `begin()`
- `commit()`
- `rollback()`

Observação:

Use apenas em fluxos críticos. Transações longas podem aumentar locks no banco.

## Compatibilidade

`CreditRepository` permanece como fachada para evitar quebra nos Services já migrados.
Essa fachada poderá ser removida em etapa futura, quando houver cobertura de testes suficiente.
