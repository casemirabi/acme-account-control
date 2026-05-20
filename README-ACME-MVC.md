# ACME Account Control — Refatoração MVC segura

## Estrutura criada

```text
app/
  Controllers/  # Recebem ações, validam entradas simples e chamam Services.
  Services/     # Regras de negócio, integrações e processamento.
  Models/       # Banco de dados, queries e persistência.
  Helpers/      # Utilitários simples e reutilizáveis.
  Hooks/        # Registro central de hooks, AJAX, REST, cron e ativação.
  Views/        # Templates HTML/PHP.
config/
  constants.php     # Constantes preservadas do plugin legado.
  legacy-files.php  # Manifesto centralizado de includes legados.
tests/
  Regression/       # Testes de regressão estruturais.
```

## Estratégia adotada

A refatoração foi feita de forma incremental para preservar compatibilidade. O plugin original registrava hooks em muitos arquivos diferentes; mover tudo de uma vez aumentaria o risco de quebrar shortcodes, AJAX, REST API, telas administrativas e banco de dados.

Por isso, a primeira etapa cria um bootstrap MVC limpo e centraliza o carregamento dos arquivos legados. As funcionalidades continuam disponíveis com os mesmos nomes públicos, slugs, endpoints e tabelas.

## Próximas migrações recomendadas

1. Migrar `includes/controllers/*` para `app/Controllers` módulo por módulo.
2. Migrar regras de negócio de `includes/services/*` para `app/Services`.
3. Migrar queries de `includes/models/*` para `app/Models`.
4. Trocar hooks diretos por classes em `app/Hooks`.
5. Manter wrappers globais temporários quando funções públicas antigas forem usadas por shortcodes, AJAX ou integrações externas.

## Testes

Executar validação de sintaxe:

```bash
composer run test:syntax
```

Executar regressão estrutural:

```bash
composer run test:regression
```

## Atualização — Etapa 4: centralização de hooks

Nesta etapa, os hooks passaram a ser inicializados por `app/Hooks/HookRegistrar.php` usando o manifesto agrupado `config/hook-files.php`.

A mudança é conservadora: os arquivos legados continuam existindo e os callbacks públicos antigos continuam disponíveis. O ganho principal é organização e rastreabilidade.

### Novas classes adicionadas

- `CompatibilityHooks`
- `UserHooks`
- `AdminHooks`
- `FrontendHooks`
- `RestApiHooks`
- `AjaxHooks`
- `CronHooks`
- `ReportHooks`
- `HookRegistrar`

### Teste novo

Foi adicionado `tests/Regression/HookManifestTest.php`, que garante que o manifesto agrupado carrega os mesmos arquivos do manifesto legado e na mesma ordem.

Execute:

```bash
composer run test:regression
composer run test:syntax
```

