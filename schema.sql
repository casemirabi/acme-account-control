-- ACME Account Control - schema.sql
-- Gera as tabelas usadas pelo plugin.
-- IMPORTANTE:
-- 1) Substitua `wp_` pelo prefixo real do seu WordPress (veja $table_prefix no wp-config.php).
-- 2) Execute em um banco MySQL/MariaDB com ENGINE InnoDB e suporte a utf8mb4.
-- 3) Rodar em manutenção / baixo tráfego.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =====================================================================
-- Usuários: hierarquia (account_links) + status (account_status)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wp_account_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_user_id` BIGINT UNSIGNED NOT NULL,
  `child_user_id` BIGINT UNSIGNED NOT NULL,
  `depth` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_link` (`parent_user_id`, `child_user_id`),
  KEY `idx_parent` (`parent_user_id`),
  KEY `idx_child` (`child_user_id`),
  KEY `idx_depth` (`depth`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_account_status` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Catálogo de serviços cobrados em créditos
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wp_services` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(50) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `credits_cost` INT NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Wallet "legado" (saldo agregado por usuário e serviço)
-- Observação: o fluxo mais novo usa credit_lots + credit_contracts,
-- mas o plugin ainda acessa esta tabela em alguns módulos.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wp_wallet` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `master_user_id` BIGINT UNSIGNED NOT NULL,
  `service_id` BIGINT UNSIGNED NOT NULL,
  `credits_total` INT NOT NULL DEFAULT 0,
  `credits_used` INT NOT NULL DEFAULT 0,
  `expires_at` DATETIME NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_service` (`master_user_id`, `service_id`),
  KEY `idx_user` (`master_user_id`),
  KEY `idx_service` (`service_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Histórico de transações (log de créditos)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wp_credit_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,              -- alvo: quem recebeu / quem foi debitado
  `service_id` BIGINT UNSIGNED NOT NULL,
  `service_slug` VARCHAR(100) NULL,
  `service_name` VARCHAR(120) NULL,
  `type` VARCHAR(20) NOT NULL,                     -- ex: grant | debit | refund | transfer | ...
  `origin` VARCHAR(20) NOT NULL,
  `credits` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'success',
  `attempts` INT NOT NULL DEFAULT 1,
  `request_id` VARCHAR(64) NULL,
  `actor_user_id` BIGINT UNSIGNED NULL,            -- quem executou (admin/sistema)
  `notes` TEXT NULL,
  `meta` LONGTEXT NULL,                            -- JSON (lot_id, refund_of_tx etc)
  `created_at` DATETIME NOT NULL,

  `wallet_total_before` INT NOT NULL DEFAULT 0,
  `wallet_used_before` INT NOT NULL DEFAULT 0,
  `wallet_total_after` INT NOT NULL DEFAULT 0,
  `wallet_used_after` INT NOT NULL DEFAULT 0,

  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_service` (`service_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_request` (`request_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Assinaturas (contratos mensais) + lotes de crédito + limites Neto
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wp_credit_contracts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `child_user_id` BIGINT UNSIGNED NOT NULL,
  `service_id` BIGINT UNSIGNED NOT NULL,
  `credits_total` INT NOT NULL DEFAULT 0,
  `credits_used` INT NOT NULL DEFAULT 0,
  `valid_until` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_child` (`child_user_id`),
  KEY `idx_service` (`service_id`),
  KEY `idx_valid` (`valid_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_credit_lots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_user_id` BIGINT UNSIGNED NOT NULL,
  `service_id` BIGINT UNSIGNED NOT NULL,
  `source` VARCHAR(20) NOT NULL,                   -- subscription | full | transfer
  `contract_id` BIGINT UNSIGNED NULL,
  `credits_total` INT NOT NULL DEFAULT 0,
  `credits_used` INT NOT NULL DEFAULT 0,
  `expires_at` DATETIME NULL,
  `meta` LONGTEXT NULL,                            -- JSON
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_service` (`service_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_contract` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wp_credit_allowances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_user_id` BIGINT UNSIGNED NOT NULL,
  `child_user_id` BIGINT UNSIGNED NOT NULL,
  `service_id` BIGINT UNSIGNED NOT NULL,
  `limit_total` INT NOT NULL DEFAULT 0,
  `limit_used` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_parent_child_service` (`parent_user_id`, `child_user_id`, `service_id`),
  KEY `idx_parent` (`parent_user_id`),
  KEY `idx_child` (`child_user_id`),
  KEY `idx_service` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- INSS (async/queue)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wp_inss_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `request_id` VARCHAR(64) NOT NULL,               -- inss_xxx (interno)
  `provider_request_id` VARCHAR(80) NULL,          -- uuid retornado pelo /api-queue-request
  `beneficio_hash` CHAR(64) NOT NULL,
  `beneficio_masked` VARCHAR(20) NOT NULL,
  `webhook_url` VARCHAR(255) NULL,
  `kind` ENUM('online','queue') NOT NULL DEFAULT 'online',
  `status` ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  `response_json` LONGTEXT NULL,
  `error_code` VARCHAR(220) NULL,
  `error_message` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_request_id` (`request_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_provider` (`provider_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Logs de API (debug/observabilidade)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wp_api_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `service_slug` VARCHAR(100) NULL,
  `provider` VARCHAR(50) NULL,
  `status` VARCHAR(20) NULL,
  `request_id` VARCHAR(64) NULL,
  `message` TEXT NULL,
  `payload` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_service` (`service_slug`),
  KEY `idx_request` (`request_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIM

-- =============================================================
-- CLT (legado e fluxo novo assíncrono)
-- =============================================================

-- Resultados "legado" (um registro por consulta salva)
CREATE TABLE IF NOT EXISTS wp_clt_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  cpf_hash CHAR(64) NULL,
  cpf_masked VARCHAR(20) NULL,
  json_full LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_cpf_hash (cpf_hash),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Requisições do fluxo novo (assíncrono / webhook / polling)
CREATE TABLE IF NOT EXISTS wp_clt_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  request_id VARCHAR(64) NOT NULL,
  clt_request_id CHAR(36) NOT NULL,
  provider_request_id VARCHAR(128) NULL,
  service_slug VARCHAR(100) NOT NULL DEFAULT 'clt',
  cpf_hash CHAR(64) NULL,
  cpf_masked VARCHAR(20) NULL,
  webhook_url VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  response_json LONGTEXT NULL,
  error_code VARCHAR(64) NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_request_id (request_id),
  KEY idx_user (user_id),
  KEY idx_status (status),
  KEY idx_provider_request (provider_request_id),
  KEY idx_cpf_hash (cpf_hash),
  KEY idx_created (created_at),
  KEY idx_completed (completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
