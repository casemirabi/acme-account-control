# ACME Account Control

Plugin WordPress para controle hierárquico de usuários, gestão de
créditos, bloqueio de login, integração com serviços externos (CLT /
Crédito Privado), geração de contratos em PDF e operação front-end via
Elementor.

Versão atual: 1.2

------------------------------------------------------------------------

## 📌 Visão Geral

O ACME Account Control é um plugin modular projetado para ambientes
WordPress que necessitam de:

-   Hierarquia de usuários (Admin \> Filho \> Neto)
-   Bloqueio de login em cascata
-   Gestão e distribuição de créditos
-   Controle de contratos e lotes
-   Integração com APIs externas (CLT / Crédito Privado)
-   Geração de PDF (via Dompdf)
-   Operação e gestão via front-end (compatível com Elementor)
-   Segurança reforçada e fallback seguro

------------------------------------------------------------------------

# 🏗 Arquitetura

## Estrutura principal

acme-account-control/
├── app/
│   ├── controllers/
│   ├── services/
│   ├── models/
│   ├── support/
│   └── integrations/
├── includes/ (wrappers de compatibilidade)
├── services/ (wrapper de compatibilidade)
├── lib/
│   └── dompdf/
└── vendor/

------------------------------------------------------------------------

# 🧠 Conceito Central

## Hierarquia de Usuários

Modelo:

ADMIN └── FILHO └── NETO

Funcionalidades:

-   Relacionamento pai \> filho
-   Cascata automática
-   Bloqueio herdado
-   Distribuição de créditos descendente
-   Controle de permissões baseado em hierarquia

------------------------------------------------------------------------

# 💳 Sistema de Créditos

Módulos:

-   credits-engine.php
-   credits-module.php
-   credits-transactions.php
-   credits-distribution.php
-   credits-transfer.php
-   credits-contracts.php
-   credits-lots.php

Funcionalidades:

-   Saldo por usuário
-   Transferência interna
-   Distribuição hierárquica
-   Registro de transações
-   Gestão de lotes
-   Geração de contratos
-   Integração com serviços externos

------------------------------------------------------------------------

# 🔐 Segurança

O plugin foi desenvolvido com foco em:

-   Evitar erro fatal por includes ausentes
-   Isolamento de constantes
-   Fallback seguro de autoload
-   Proteção contra acesso direto (ABSPATH)
-   Modo mock para evitar chamadas indevidas
-   Uso de chaves internas para integrações
-   Separação de módulos críticos e opcionais

------------------------------------------------------------------------

# ⚙️ Constantes Importantes

ACME_DEBUG\
ACME_CLT_MOCK\
ACME_CLT_API_BASE\
ACME_CLT_API_KEY\
ACME_CLT_BRIDGE_URL\
ACME_CLT_BRIDGE_KEY\
ACME_PB_INTERNAL_KEY

------------------------------------------------------------------------

# 🧾 Geração de PDF

Biblioteca utilizada:

Dompdf

Local:

lib/dompdf/

Autoload:

vendor/autoload.php

------------------------------------------------------------------------

# 🧪 Modo Simulação

define('ACME_CLT_MOCK', true);

Efeitos:

-   Não chama API real
-   Gera token fake
-   Permite teste seguro em produção
-   Ideal para homologação

------------------------------------------------------------------------



## ✅ Como usar agora no WordPress (pós-refatoração)

Mesmo com a nova estrutura em `app/`, o WordPress continua usando o arquivo raiz:

- `acme-account-control.php` (entrada oficial do plugin)

### Passo a passo

1. Copie a pasta do plugin para `wp-content/plugins/acme-account-control/`.
2. Confirme que existe o arquivo `acme-account-control.php` na raiz da pasta.
3. Ative no painel do WordPress.
4. Configure as chaves:
   - Recomendado: no `wp-config.php`.
   - Alternativa: usar os fallbacks já definidos no próprio `acme-account-control.php`.

### Constantes principais

- `ACME_CLT_API_BASE`
- `ACME_CLT_API_KEY`
- `ACME_CLT_BRIDGE_URL`
- `ACME_CLT_BRIDGE_KEY`
- `ACME_PB_INTERNAL_KEY`
- `ACME_PB_BRIDGE_URL`
- `ACME_INSS_API_BASE`
- `ACME_INSS_API_KEY`
- `ACME_CLT_MOCK` (true/false)
- `ACME_DEBUG` (true/false)

### Exemplo no `wp-config.php`

```php
define('ACME_DEBUG', false);
define('ACME_CLT_MOCK', true);
define('ACME_CLT_API_BASE', 'https://seu-endpoint-clt');
define('ACME_CLT_API_KEY', 'sua-chave');
define('ACME_INSS_API_BASE', 'https://seu-endpoint-inss');
define('ACME_INSS_API_KEY', 'sua-chave-inss');
```

# 🛠 Requisitos Técnicos

-   WordPress 6.x+
-   PHP 7.4+ (ideal 8.1+)
-   Extensão mbstring
-   cURL habilitado
-   Permissão de escrita para logs

------------------------------------------------------------------------

# 🚀 Instalação

1.  Enviar pasta para: wp-content/plugins/acme-account-control/

2.  Ativar no painel WordPress

3.  Configurar constantes

4.  Testar em modo MOCK

5.  Validar integração

------------------------------------------------------------------------

# 🔄 Processo Seguro de Atualização

1.  Backup completo
2.  Ativar modo MOCK
3.  Atualizar plugin
4.  Testar login, créditos e integrações
5.  Desativar MOCK
6.  Monitorar logs por 24h

Rollback:

-   Restaurar backup
-   Reverter versão do plugin

------------------------------------------------------------------------

# 📌 Objetivo do Projeto

Motor de governança hierárquica + controle financeiro + integração
externa, projetado para:

-   Operações escaláveis
-   Segurança de dados
-   Controle organizacional
-   Baixo risco operacional

------------------------------------------------------------------------

# 👨‍💻 Manutenção

-   Revisão periódica de logs
-   Testes controlados
-   Auditoria de permissões
-   Monitoramento de endpoints externos

------------------------------------------------------------------------

# 🔐 Observação Final

Este plugin opera com:

-   Hierarquia sensível
-   Controle financeiro
-   Integração externa

Qualquer alteração deve ser feita com:

✔ teste em staging\
✔ validação de segurança\
✔ análise de impacto\
✔ plano de rollback

------------------------------------------------------------------------

ACME Account Control\
Arquitetura modular, segura e preparada para produção.
