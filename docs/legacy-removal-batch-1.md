# Remoção de Legado — Lote 1

## Objetivo

Remover somente arquivos legados de baixo risco, sem alterar hooks públicos, shortcodes, REST APIs, AJAX handlers, cron jobs, banco de dados, slugs ou funções públicas carregadas pelo plugin.

## Arquivos removidos

```text
includes/models/users-module.php
includes/services/users-service.php
includes/services/users-manage-service.php
includes/services/users-status-service.php
```

## Motivo da remoção

Esses arquivos eram aliases/documentos legados do módulo Users e não faziam parte dos manifestos ativos de carregamento:

- `config/legacy-files.php`
- `config/hook-files.php`

As implementações reais continuam preservadas em:

```text
includes/Modules/Users/
```

## Classificação de risco

**Baixo risco.**

Motivos:

- não registravam hooks públicos diretamente;
- não continham shortcodes ativos;
- não continham rotas REST;
- não continham callbacks AJAX;
- não continham cron jobs;
- não eram carregados pelo manifesto central;
- as classes ativas equivalentes continuam existentes.

## Compatibilidade preservada

Foram preservados os arquivos ativos do módulo Users:

```text
includes/Modules/Users/UsersActivation.php
includes/Modules/Users/UsersActionsController.php
includes/Modules/Users/UsersShortcodesController.php
includes/Modules/Users/UsersRepository.php
includes/Modules/Users/UsersService.php
includes/Modules/Users/UsersManageService.php
includes/Modules/Users/UsersStatusService.php
includes/Modules/Users/UsersAdminHooks.php
includes/Modules/Users/UsersRegistrationHooks.php
includes/Modules/Users/UsersLoginGuard.php
includes/Modules/Users/UsersAdminListController.php
includes/Modules/Users/UsersRegistrationService.php
```

## Validação adicionada

Foi criado o teste:

```text
tests/Regression/LegacyRemovalBatchOneTest.php
```

Esse teste garante que:

1. os arquivos legados removidos não existem mais;
2. os manifestos não voltaram a referenciar esses arquivos;
3. os arquivos ativos do módulo Users continuam presentes.

## Próximo lote recomendado

Auditar antes de qualquer remoção:

```text
includes/services/credits-transactions-module.php
includes/models/credits-admin-model.php
```

Esses arquivos ainda contêm funções e SQL procedural, portanto devem ser tratados como **médio risco** até comprovação completa de ausência de uso externo.
