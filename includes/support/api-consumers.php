<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('acme_api_consumers_table')) {
  function acme_api_consumers_table(): string
  {
    global $wpdb;
    return $wpdb->prefix . 'acme_api_consumers';
  }
}

if (!function_exists('acme_api_consumers_activate')) {
  function acme_api_consumers_activate(): void
  {
    global $wpdb;

    $tableName = acme_api_consumers_table();
    $charsetCollate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$tableName} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      wp_user_id BIGINT UNSIGNED NOT NULL,
      consumer_name VARCHAR(190) NOT NULL,
      api_key_hash CHAR(64) NOT NULL,
      api_key_prefix VARCHAR(32) NOT NULL DEFAULT '',
      status VARCHAR(20) NOT NULL DEFAULT 'active',
      allowed_services VARCHAR(255) NOT NULL DEFAULT 'clt',
      last_used_at DATETIME NULL DEFAULT NULL,
      last_ip VARCHAR(100) NULL DEFAULT NULL,
      last_user_agent TEXT NULL DEFAULT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY api_key_hash (api_key_hash),
      KEY wp_user_id (wp_user_id),
      KEY status (status)
    ) {$charsetCollate};";

    dbDelta($sql);
  }
}

if (!function_exists('acme_api_consumer_normalize_allowed_services')) {
  function acme_api_consumer_normalize_allowed_services($allowedServices): string
  {
    $serviceSlugs = is_array($allowedServices) ? $allowedServices : explode(',', (string) $allowedServices);
    $serviceSlugs = array_map('sanitize_key', $serviceSlugs);
    $serviceSlugs = array_filter($serviceSlugs, static function ($serviceSlug) {
      return $serviceSlug !== '';
    });
    $serviceSlugs = array_values(array_unique($serviceSlugs));

    if (empty($serviceSlugs)) {
      $serviceSlugs = ['clt'];
    }

    sort($serviceSlugs);
    return implode(',', $serviceSlugs);
  }
}

if (!function_exists('acme_api_consumer_generate_plain_key')) {
  function acme_api_consumer_generate_plain_key(): string
  {
    return 'acme_live_' . wp_generate_password(32, false, false);
  }
}

if (!function_exists('acme_api_consumer_hash_key')) {
  function acme_api_consumer_hash_key(string $plainApiKey): string
  {
    return hash('sha256', $plainApiKey);
  }
}

if (!function_exists('acme_api_consumer_create')) {
  function acme_api_consumer_create(int $wpUserId, string $consumerName, $allowedServices = ['clt'])
  {
    global $wpdb;

    acme_api_consumers_activate();

    if ($wpUserId <= 0 || !get_user_by('id', $wpUserId)) {
      return new WP_Error('acme_invalid_user', 'Usuário inválido.');
    }

    $consumerName = sanitize_text_field($consumerName);
    if ($consumerName === '') {
      $consumerName = 'Consumidor API #' . $wpUserId;
    }

    $allowedServicesText = acme_api_consumer_normalize_allowed_services($allowedServices);
    $plainApiKey = acme_api_consumer_generate_plain_key();

    $inserted = $wpdb->insert(
      acme_api_consumers_table(),
      [
        'wp_user_id' => $wpUserId,
        'consumer_name' => $consumerName,
        'api_key_hash' => acme_api_consumer_hash_key($plainApiKey),
        'api_key_prefix' => substr($plainApiKey, 0, 12),
        'status' => 'active',
        'allowed_services' => $allowedServicesText,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
      ],
      ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );

    if (!$inserted) {
      return new WP_Error('acme_api_consumer_create_failed', 'Não foi possível criar a chave.');
    }

    return [
      'consumer_id' => (int) $wpdb->insert_id,
      'api_key' => $plainApiKey,
      'wp_user_id' => $wpUserId,
      'consumer_name' => $consumerName,
      'allowed_services' => $allowedServicesText,
    ];
  }
}

if (!function_exists('acme_api_consumer_regenerate_key')) {
  function acme_api_consumer_regenerate_key(int $consumerId)
  {
    global $wpdb;

    acme_api_consumers_activate();

    $consumerRow = $wpdb->get_row(
      $wpdb->prepare('SELECT * FROM ' . acme_api_consumers_table() . ' WHERE id = %d LIMIT 1', $consumerId),
      ARRAY_A
    );

    if (!$consumerRow) {
      return new WP_Error('acme_api_consumer_not_found', 'Consumidor não encontrado.');
    }

    $plainApiKey = acme_api_consumer_generate_plain_key();

    $updated = $wpdb->update(
      acme_api_consumers_table(),
      [
        'api_key_hash' => acme_api_consumer_hash_key($plainApiKey),
        'api_key_prefix' => substr($plainApiKey, 0, 12),
        'status' => 'active',
        'updated_at' => current_time('mysql'),
      ],
      ['id' => $consumerId],
      ['%s', '%s', '%s', '%s'],
      ['%d']
    );

    if ($updated === false) {
      return new WP_Error('acme_api_consumer_regenerate_failed', 'Não foi possível regenerar a chave.');
    }

    return [
      'consumer_id' => (int) $consumerId,
      'api_key' => $plainApiKey,
      'wp_user_id' => (int) $consumerRow['wp_user_id'],
      'consumer_name' => (string) $consumerRow['consumer_name'],
      'allowed_services' => (string) $consumerRow['allowed_services'],
    ];
  }
}

if (!function_exists('acme_api_consumer_revoke')) {
  function acme_api_consumer_revoke(int $consumerId): bool
  {
    global $wpdb;

    acme_api_consumers_activate();

    $updated = $wpdb->update(
      acme_api_consumers_table(),
      [
        'status' => 'revoked',
        'updated_at' => current_time('mysql'),
      ],
      ['id' => $consumerId],
      ['%s', '%s'],
      ['%d']
    );

    return $updated !== false;
  }
}

if (!function_exists('acme_api_consumer_get_all')) {
  function acme_api_consumer_get_all(int $limit = 200): array
  {
    global $wpdb;

    acme_api_consumers_activate();

    $limit = max(1, min(500, $limit));
    $tableName = acme_api_consumers_table();

    $sql = $wpdb->prepare("SELECT * FROM {$tableName} ORDER BY id DESC LIMIT %d", $limit);
    $rows = $wpdb->get_results($sql, ARRAY_A);

    return is_array($rows) ? $rows : [];
  }
}


if (!function_exists('acme_get_api_key_from_request')) {
  function acme_get_api_key_from_request($request): string
  {
    if (is_object($request) && method_exists($request, 'get_header')) {
      $apiKeyHeader = (string) $request->get_header('x-acme-key');
      if ($apiKeyHeader !== '') {
        return trim($apiKeyHeader);
      }
    }

    $serverKeys = [
      'HTTP_X_ACME_KEY',
      'REDIRECT_HTTP_X_ACME_KEY',
      'X_ACME_KEY',
    ];

    foreach ($serverKeys as $serverKey) {
      $apiKeyHeader = isset($_SERVER[$serverKey]) ? (string) $_SERVER[$serverKey] : '';
      if ($apiKeyHeader !== '') {
        return trim($apiKeyHeader);
      }
    }

    return '';
  }
}

if (!function_exists('acme_find_api_consumer_by_key')) {
  function acme_find_api_consumer_by_key(string $plainApiKey): ?array
  {
    global $wpdb;

    $plainApiKey = trim($plainApiKey);
    if ($plainApiKey === '') {
      return null;
    }

    acme_api_consumers_activate();

    $consumerRow = $wpdb->get_row(
      $wpdb->prepare(
        'SELECT * FROM ' . acme_api_consumers_table() . ' WHERE api_key_hash = %s LIMIT 1',
        acme_api_consumer_hash_key($plainApiKey)
      ),
      ARRAY_A
    );

    return is_array($consumerRow) ? $consumerRow : null;
  }
}

if (!function_exists('acme_api_consumer_allows_service')) {
  function acme_api_consumer_allows_service(array $consumerRow, string $serviceSlug): bool
  {
    $serviceSlug = sanitize_key($serviceSlug);
    if ($serviceSlug === '') {
      return false;
    }

    $allowedServices = (string) ($consumerRow['allowed_services'] ?? '');
    $allowedServiceList = array_filter(array_map('trim', explode(',', $allowedServices)));

    return in_array($serviceSlug, $allowedServiceList, true);
  }
}

if (!function_exists('acme_touch_api_consumer_usage')) {
  function acme_touch_api_consumer_usage(int $consumerId): void
  {
    global $wpdb;

    if ($consumerId <= 0) {
      return;
    }

    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 1000) : null;
    $remoteIp = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 100) : null;

    $wpdb->update(
      acme_api_consumers_table(),
      [
        'last_used_at' => current_time('mysql'),
        'last_ip' => $remoteIp,
        'last_user_agent' => $userAgent,
        'updated_at' => current_time('mysql'),
      ],
      ['id' => $consumerId],
      ['%s', '%s', '%s', '%s'],
      ['%d']
    );
  }
}

if (!function_exists('acme_validate_api_key')) {
  function acme_validate_api_key(string $plainApiKey, string $serviceSlug = 'clt')
  {
    if (function_exists('acme_api_public_is_enabled') && !acme_api_public_is_enabled()) {
      return new WP_Error(
        'api_disabled',
        'Public API disabled',
        ['status' => 503]
      );
    }

    $plainApiKey = trim($plainApiKey);
    if ($plainApiKey === '') {
      return new WP_Error('acme_api_key_required', 'A chave da API é obrigatória.', ['status' => 401]);
    }

    $consumerRow = acme_find_api_consumer_by_key($plainApiKey);
    if (!$consumerRow) {
      return new WP_Error('acme_invalid_api_key', 'Chave da API inválida.', ['status' => 401]);
    }

    $consumerStatus = (string) ($consumerRow['status'] ?? '');
    if ($consumerStatus !== 'active') {
      return new WP_Error('acme_inactive_api_key', 'Esta chave está inativa.', ['status' => 401]);
    }

    $wpUserId = (int) ($consumerRow['wp_user_id'] ?? 0);
    if ($wpUserId <= 0 || !get_user_by('id', $wpUserId)) {
      return new WP_Error('acme_api_key_user_not_found', 'Usuário vinculado à chave não encontrado.', ['status' => 401]);
    }

    if (!acme_api_consumer_allows_service($consumerRow, $serviceSlug)) {
      return new WP_Error('acme_service_not_allowed', 'Esta chave não possui permissão para este serviço.', ['status' => 403]);
    }

    acme_touch_api_consumer_usage((int) $consumerRow['id']);

    return $consumerRow;
  }
}


if (!function_exists('acme_api_consumer_update_status')) {
  function acme_api_consumer_update_status(int $consumerId, string $status)
  {
    global $wpdb;

    $allowedStatuses = ['active', 'inactive', 'revoked'];

    if (!in_array($status, $allowedStatuses, true)) {
      return new WP_Error('acme_invalid_status', 'Status inválido.');
    }

    $consumerId = (int) $consumerId;
    if ($consumerId <= 0) {
      return new WP_Error('acme_invalid_consumer_id', 'Consumidor inválido.');
    }

    acme_api_consumers_activate();

    $updated = $wpdb->update(
      acme_api_consumers_table(),
      [
        'status' => $status,
        'updated_at' => current_time('mysql'),
      ],
      [
        'id' => $consumerId,
      ],
      ['%s', '%s'],
      ['%d']
    );

    if ($updated === false) {
      return new WP_Error('acme_api_consumer_update_failed', 'Erro ao atualizar status.');
    }

    return true;
  }
}

if (!function_exists('acme_api_consumer_bulk_update_status')) {
  function acme_api_consumer_bulk_update_status(array $ids, string $status)
  {
    if (empty($ids)) {
      return true;
    }

    foreach ($ids as $id) {
      $consumerId = (int) $id;
      if ($consumerId <= 0) {
        continue;
      }

      $result = acme_api_consumer_update_status($consumerId, $status);
      if (is_wp_error($result)) {
        return $result;
      }
    }

    return true;
  }
}


/*
* Liberando página da API
*/

if (!function_exists('acme_user_has_active_service')) {
  function acme_user_has_active_service(int $wpUserId, string $serviceSlug): bool
  {
    global $wpdb;

    if ($wpUserId <= 0) {
      return false;
    }

    $tableName = acme_api_consumers_table();

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM {$tableName}
         WHERE wp_user_id = %d
         AND status = 'active'
         LIMIT 1
         "
         ,
        $wpUserId
      ),
      ARRAY_A
    );

    if (empty($rows)) {
      return false;
    }

    foreach ($rows as $row) {
      if (acme_api_consumer_allows_service($row, $serviceSlug)) {
        return true;
      }
    }

    return false;
  }
}

add_action('template_redirect', function () {

  if (!is_page('api-clt')) {
    return;
  }

  // Admin sempre pode acessar
  if (current_user_can('manage_options')) {
    return;
  }

  // 🔒 NOVA REGRA: API global bloqueada
  if (function_exists('acme_api_public_is_enabled') && !acme_api_public_is_enabled()) {
    wp_safe_redirect(home_url('/sem-permissao/'));
    exit;
  }

  if (!is_user_logged_in()) {
    wp_safe_redirect(wp_login_url());
    exit;
  }

  $userId = get_current_user_id();

  if (!acme_user_has_active_service($userId, 'clt')) {
    wp_safe_redirect(home_url('/sem-permissao/'));
    exit;
  }

});