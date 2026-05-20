# ACME Account Control — Checklist de validação manual

Use este checklist em ambiente local ou staging antes de colocar a versão final em produção.

## Ativação

- [ ] Plugin ativa sem erro fatal.
- [ ] Plugin desativa sem erro fatal.
- [ ] Rotinas de ativação preservam tabelas e opções existentes.
- [ ] Não há warnings no `debug.log` após ativar.

## Admin

- [ ] Menus administrativos continuam com os mesmos slugs.
- [ ] Páginas administrativas carregam sem tela branca.
- [ ] Usuário sem permissão não acessa telas restritas.
- [ ] Notices administrativas continuam aparecendo corretamente.

## Frontend

- [ ] Shortcode `[acme_api_control_panel]` renderiza como antes.
- [ ] Usuário deslogado recebe a mesma mensagem/experiência esperada.
- [ ] Usuário sem permissão é redirecionado como antes.
- [ ] CSS e JS do painel carregam somente onde necessário.

## Créditos e carteira

- [ ] Consulta de carteira retorna os mesmos dados.
- [ ] Concessão de créditos funciona.
- [ ] Log de transação é gravado.
- [ ] Saldo não sofre alteração indevida em erro.
- [ ] Wrappers legados continuam funcionando.

## AJAX

- [ ] Todos os handlers AJAX usados pelo plugin respondem.
- [ ] Respostas de sucesso mantêm formato esperado.
- [ ] Respostas de erro mantêm formato esperado.
- [ ] Requisições sem permissão são bloqueadas.

## REST API

- [ ] Endpoints continuam registrados.
- [ ] Permissões continuam compatíveis.
- [ ] Payloads antigos continuam aceitos.
- [ ] Respostas continuam no mesmo formato.

## Cron

- [ ] Eventos cron continuam registrados.
- [ ] Nenhum evento duplicado foi criado.
- [ ] Execução manual do cron não gera erro fatal.

## Uploads, PDFs e relatórios

- [ ] Uploads continuam funcionando.
- [ ] Geração de PDF continua funcionando.
- [ ] Relatórios continuam exportando.
- [ ] Bibliotecas `dompdf` e `tcpdf` continuam carregando.

## Banco de dados

- [ ] Nenhuma tabela foi renomeada.
- [ ] Nenhuma coluna foi removida.
- [ ] Queries principais continuam retornando dados.
- [ ] Operações de escrita continuam consistentes.

## Segurança

- [ ] Formulários sensíveis possuem nonce.
- [ ] Ações administrativas validam capability.
- [ ] Saídas HTML usam escaping adequado.
- [ ] Entradas de request são sanitizadas.
- [ ] Redirecionamentos usam URLs seguras.

## Regressão geral

- [ ] Fluxos principais foram testados com Admin.
- [ ] Fluxos principais foram testados com usuário comum.
- [ ] Fluxos principais foram testados com usuário sem permissão.
- [ ] `WP_DEBUG` não registra erros novos.
