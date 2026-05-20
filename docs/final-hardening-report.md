# ACME Account Control — Relatório de limpeza final e hardening

## Objetivo desta etapa

Esta etapa fecha a refatoração MVC incremental com foco em estabilidade, segurança e manutenção. A regra principal foi preservar compatibilidade pública: nenhum slug, shortcode, endpoint, tabela, hook público ou função global legada foi removido.

## O que foi adicionado

### `app/Security/RequestGuard.php`

Classe pequena e propositalmente simples para centralizar validações recorrentes de segurança em Controllers e futuros callbacks migrados.

Responsabilidades atuais:

- validar capability com `current_user_can()`;
- validar nonce administrativo com `check_admin_referer()`;
- ler texto de request com `wp_unslash()` e `sanitize_text_field()`;
- ler IDs positivos com `absint()`;
- redirecionar para páginas administrativas com `wp_safe_redirect()`.

### Hardening do renderer de Views

O método interno de validação de caminho em `app/Views/View.php` agora usa `realpath()` para confirmar que o arquivo renderizado existe e permanece dentro do diretório do plugin.

Motivo:

- reduzir risco de path traversal;
- evitar inclusão acidental de arquivos fora do plugin;
- manter compatibilidade com Views novas e legadas.

### Controllers administrativos

`AdminPageController` passou a usar `RequestGuard` para validações comuns. Isso mantém a classe pequena e prepara as próximas migrações sem duplicar checks de permissão.

## O que foi preservado

- shortcode `[acme_api_control_panel]`;
- funções globais legadas usadas como wrappers;
- manifestos de carregamento legado;
- arquivos `includes/*` ainda necessários;
- slugs administrativos;
- callbacks AJAX/REST existentes;
- tabelas e queries legadas;
- assets e bibliotecas locais.

## Riscos mitigados

- validações duplicadas em novos Controllers;
- includes de View sem validação robusta de caminho;
- redirecionamentos administrativos montados manualmente;
- leitura inconsistente de `$_POST` e `$_GET` em futuras migrações.

## Riscos ainda existentes

Como a refatoração foi conservadora, parte do código procedural legado ainda permanece em `includes/*`. Isso é intencional para evitar regressão, mas ainda exige revisões futuras em:

- nonces de todos os formulários legados;
- escaping de todas as saídas HTML;
- sanitização de todos os campos de request;
- capabilities específicas por ação;
- queries antigas que ainda usam `$wpdb` diretamente;
- respostas AJAX/REST antigas.

## Estratégia recomendada daqui para frente

1. Manter os wrappers legados até pelo menos uma versão estável em produção.
2. Migrar callbacks restantes em lotes pequenos.
3. Para cada callback migrado, usar o fluxo:
   - Hook → Controller → Service → Repository → View/Response.
4. Criar teste de regressão antes de remover qualquer função pública antiga.
5. Só remover código legado após confirmar que não há snippets, integrações ou addons usando esses nomes.
