# Legacy Removal — Batch 7

## Objetivo

Remover template legado que ainda vivia em `includes/views`, preservando o shortcode público e o fluxo visual do painel de API.

## Alteração aplicada

### Movido

- `includes/views/api-consumers-panel.php`
- para `app/Views/frontend/api-consumers-panel.php`

### Atualizado

- `app/Controllers/Frontend/ApiControlPanelController.php`

O controller deixou de chamar:

```php
$this->view->captureLegacy('includes/views/api-consumers-panel.php');
```

E passou a chamar:

```php
$this->view->capture('frontend.api-consumers-panel');
```

## Compatibilidade preservada

Nada público foi removido:

- shortcode `[acme_api_control_panel]` preservado
- função `acme_api_control_panel_shortcode()` preservada
- função `acme_api_control_panel_enqueue_assets()` preservada
- fluxo de POST legado preservado temporariamente
- assets existentes preservados

## Risco

Classificação: **baixo a médio risco**.

Motivo:

- o arquivo movido era um template, não uma API pública
- a renderização continua passando pelo mesmo controller
- a saída HTML foi preservada
- o caminho antigo foi removido para reduzir legado estrutural

## Validação obrigatória

Após esta alteração, validar:

- shortcode do painel de API no frontend
- renderização visual do painel
- ações de POST do painel
- CSS/JS do painel
- permissões administrativas
- ausência do diretório `includes/views`

## Resultado

O plugin passa a ter menos dependência de templates dentro de `includes`, aproximando a camada visual da estrutura MVC final em `app/Views`.
