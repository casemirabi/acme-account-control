# ACME — Mapa da Etapa 7: Controllers e Views

## Objetivo da etapa

Esta etapa inicia a separação entre entrada HTTP, renderização e compatibilidade legada.

A prioridade foi migrar somente pontos seguros, mantendo nomes públicos e arquivos legados necessários para evitar regressões.

## Arquivos criados

```txt
app/Controllers
├── Admin
│   ├── AdminPageController.php
│   └── ApiConsumersAdminController.php
└── Frontend
    └── ApiControlPanelController.php

app/Views
└── View.php
```

## O que foi migrado

### Shortcode `[acme_api_control_panel]`

A função global `acme_api_control_panel_shortcode()` continua existindo, mas agora é um wrapper para:

```php
Acme\AccountControl\Controllers\Frontend\ApiControlPanelController::shortcode()
```

Isso preserva compatibilidade com:

- shortcode público existente;
- snippets que chamam a função diretamente;
- página visual atual;
- template legado `includes/views/api-consumers-panel.php`.

### Enqueue dos assets do painel

A função global `acme_api_control_panel_enqueue_assets()` também foi preservada como wrapper e delega para:

```php
ApiControlPanelController::enqueueAssetsWhenShortcodeIsPresent()
```

O comportamento antigo foi mantido: CSS/JS só são carregados quando o post contém o shortcode.

## Renderer de Views

Foi criado `app/Views/View.php`.

Ele permite dois fluxos:

1. Renderizar novas views em `app/Views`.
2. Renderizar views legadas em `includes/views` durante a migração incremental.

Essa ponte evita duplicar HTML e reduz risco de regressão visual.

## Compatibilidade preservada

Não foram alterados:

- shortcode `[acme_api_control_panel]`;
- função `acme_api_control_panel_shortcode()`;
- função `acme_api_control_panel_enqueue_assets()`;
- template `includes/views/api-consumers-panel.php`;
- permissões `manage_options`;
- assets CSS/JS;
- fluxo POST legado do painel da API.

## Próximo passo recomendado

Migrar o processamento de POST do painel da API para um Service específico, mantendo a função global `acme_api_control_panel_handle_post()` como wrapper.

Sugestão de próximos arquivos:

```txt
app/Services/ApiConsumerPanelService.php
app/Models/ApiConsumerRepository.php
app/Controllers/Frontend/ApiControlPanelController.php
```

Essa migração deve ser feita com cuidado porque envolve criação/revogação de chaves e controle global da API.
