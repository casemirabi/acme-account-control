<?php
if (!defined('ABSPATH'))
  exit;

/**
 * ACME Reports Registry (central)
 * - Adicione novos relatórios aqui sem criar novos arquivos
 */

add_filter('acme_export_registry', function ($r) {

  // ============================================================
  // REPORT: credits_snapshot (saldo por serviço)
  // ============================================================
  $r['credits_snapshot'] = [
    'columns' => [
      'service_id' => 'Service ID',
      'service_name' => 'Serviço',
      'saldo' => 'Créditos',
    ],
    'filters' => [
      'user_id' => ['type' => 'int', 'default' => 0], // admin
    ],
    'chunk_size' => 500,
    'filename' => fn($state) => 'creditos_snapshot_' . date('Y-m-d') . '.csv',
    'can_export' => fn() => is_user_logged_in(),

    'normalize_state' => function ($state) {
      $viewer_id = get_current_user_id();
      $is_admin = current_user_can('manage_options');

      $target_id = $viewer_id;
      if ($is_admin && !empty($state['user_id'])) {
        $target_id = (int) $state['user_id'];
      }

      $state['target_id'] = $target_id;
      if (!$is_admin)
        $state['user_id'] = 0;

      return $state;
    },

    'fetch' => function ($state, $limit, $offset) {
      global $wpdb;

      $txT = $wpdb->prefix . 'credit_transactions';
      $servicesT = $wpdb->prefix . 'services';

      $target_id = (int) ($state['target_id'] ?? get_current_user_id());

      $sql = $wpdb->prepare("
        SELECT
          t.service_id AS service_id,
          COALESCE(NULLIF(t.service_name,''), s.name, t.service_slug) AS service_name
          COALESCE(SUM(
            CASE
              WHEN t.type = 'credit' THEN t.credits
              WHEN t.type = 'debit'  THEN -t.credits
              ELSE 0
            END
          ), 0) AS saldo
        FROM {$txT} t
        LEFT JOIN {$servicesT} s ON s.id = t.service_id
        WHERE t.user_id = %d
          AND t.status = 'success'
        GROUP BY t.service_id, service_name
        ORDER BY service_name ASC
        LIMIT %d OFFSET %d
      ", $target_id, (int) $limit, (int) $offset);

      return $wpdb->get_results($sql, ARRAY_A);
    },

    'map_row' => fn($row) => [
      (int) ($row['service_id'] ?? 0),
      (string) ($row['service_name'] ?? ''),
      (int) ($row['saldo'] ?? 0),
    ],
  ];

  // ============================================================
  // REPORT: credits_extract_last20 (20 últimas transações)
  // - CSV mantém todas as colunas
  // - PDF usa pdf_columns/pdf_map_row (mais legível)
  // ============================================================
  $r['credits_extract_last20'] = [
    // CSV (se quiser manter)
    'columns' => [
      'created_at' => 'Data',
      'user' => 'Usuário',
      'movement' => 'Movimentação',
      'delta' => 'Saldo',
    ],

    // PDF igual a tela: Histórico / Movimentação / Saldo
    'pdf_columns' => [
      'Data',
      'Usuário',
      'Movimentação',
      'Saldo'
    ],

    'filters' => [
      'user_id' => ['type' => 'int', 'default' => 0], // admin: opcional (0 = todos)
      'service_id' => ['type' => 'int', 'default' => 0], // opcional
    ],

    'chunk_size' => 20,
    'filename' => fn($state) => 'extrato_ultimas_20_' . date('Y-m-d') . '.csv',
    'can_export' => fn() => is_user_logged_in(),

    'normalize_state' => function ($state) {
      $viewer_id = get_current_user_id();
      $is_admin = current_user_can('manage_options');

      // ✅ regra da tela:
      // - admin sem user_id -> todos (target_id = 0)
      // - admin com user_id -> filtra
      // - não-admin -> somente ele
      if ($is_admin) {
        $state['target_id'] = !empty($state['user_id']) ? (int) $state['user_id'] : 0;
      } else {
        $state['target_id'] = $viewer_id;
        $state['user_id'] = 0;
      }

      $state['service_id'] = (int) ($state['service_id'] ?? 0);
      return $state;
    },

    'fetch' => function ($state, $limit, $offset) {
      global $wpdb;

      $txT = $wpdb->prefix . 'credit_transactions';
      $usersT = $wpdb->users;

      $target_id = (int) ($state['target_id'] ?? 0);     // 0 = todos (admin)
      $service_id = (int) ($state['service_id'] ?? 0);

      $where = "t.status = 'success'";
      $params = [];

      if ($target_id > 0) {
        $where .= " AND t.user_id = %d";
        $params[] = $target_id;
      }

      if ($service_id > 0) {
        $where .= " AND t.service_id = %d";
        $params[] = $service_id;
      }

      $sql = "
      SELECT
        t.id,
        t.created_at,
        t.user_id,
        u.display_name AS user_name,
        COALESCE(NULLIF(t.service_name,''), t.service_slug, CONCAT('service#', t.service_id)) AS service_label,
        t.type,
        t.credits,
        COALESCE(t.notes,'') AS notes
      FROM {$txT} t
      INNER JOIN {$usersT} u ON u.ID = t.user_id
      WHERE {$where}
      ORDER BY t.id DESC
      LIMIT %d OFFSET %d
    ";

      $params[] = (int) $limit;
      $params[] = (int) $offset;

      return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    },

    // CSV row (igual tela, porém com data)
    'map_row' => function ($row) {
      $type = (string) ($row['type'] ?? '');
      $credits = (int) ($row['credits'] ?? 0);
      $delta = ($type === 'debit') ? -$credits : $credits;

      $dt = (string) ($row['created_at'] ?? '');
      $dt_fmt = $dt ? date_i18n('d/m/Y H:i', strtotime($dt)) : '';

      $user = (string) ($row['user_name'] ?? '');
      $uid = (int) ($row['user_id'] ?? 0);

      return [
        $dt_fmt,
        $user . ' #' . $uid,
        ucwords(str_replace(['_', '-'], ' ', (string) ($row['service_label'] ?? ''))),  // (string)($row['service_label'] ?? ''),
        (string) $delta,
      ];
    },

    // PDF row (Histórico / Movimentação / Saldo)
    'pdf_map_row' => function ($row, $state) {
      $type = (string) ($row['type'] ?? '');
      $credits = (int) ($row['credits'] ?? 0);
      $delta = ($type === 'debit') ? -$credits : $credits;

      $dt = (string) ($row['created_at'] ?? '');
      $dt_fmt = $dt ? date_i18n('d/m/Y H:i', strtotime($dt)) : '';

      $user = (string) ($row['user_name'] ?? '');
      $uid = (int) ($row['user_id'] ?? 0);

      return [
        $dt_fmt,
        $user . ' #' . $uid,                 // Histórico (igual sua tabela mostra usuário + id)
        (string) ($row['service_label'] ?? ''), // Movimentação = serviço
        (string) $delta,                      // Saldo = delta (-1, +5, etc)
      ];
    },
  ];



  // ============================================================
  // REPORT: users (export igual a tabela da tela, sem "Ações")
  // Colunas: Tipo | Nome | Telefone | E-mail | Status | Créditos
  // ============================================================
  $r['users'] = [
    'columns' => [
      'tipo' => 'Tipo',
      'nome' => 'Nome',
      'telefone' => 'Telefone',
      'email' => 'E-mail',
      'status' => 'Status',
      'creditos' => 'Créditos',
    ],

    'filters' => [
      'q' => ['type' => 'text', 'default' => ''],
      'master' => ['type' => 'int', 'default' => 0], // admin
      'status' => ['type' => 'enum', 'default' => 'all', 'allowed' => ['all', 'active', 'inactive']],
      'credits' => ['type' => 'enum', 'default' => 'all', 'allowed' => ['all', 'has', 'none']],
    ],

    'chunk_size' => 500,
    'filename' => fn($state) => 'usuarios_' . date('Y-m-d') . '.csv',

    'can_export' => function () {
      if (!is_user_logged_in())
        return false;
      if (current_user_can('manage_options'))
        return true;
      $me = wp_get_current_user();
      return in_array('child', (array) $me->roles, true);
    },

    'normalize_state' => function ($state) {
      $is_admin = current_user_can('manage_options');
      if (!$is_admin)
        $state['master'] = 0;

      if (!in_array($state['status'], ['all', 'active', 'inactive'], true))
        $state['status'] = 'all';
      if (!in_array($state['credits'], ['all', 'has', 'none'], true))
        $state['credits'] = 'all';

      return $state;
    },

    'fetch' => function ($state, $limit, $offset) {
      global $wpdb;

      $viewer_id = get_current_user_id();
      $is_admin = current_user_can('manage_options');

      $q = trim((string) ($state['q'] ?? ''));
      $q_norm = mb_strtolower($q);

      $filter_master = (int) ($state['master'] ?? 0);
      $filter_status = (string) ($state['status'] ?? 'all');
      $filter_credits = (string) ($state['credits'] ?? 'all');

      $linksT = function_exists('acme_table_links') ? acme_table_links() : ($wpdb->prefix . 'account_links');
      $statusT = function_exists('acme_table_status') ? acme_table_status() : ($wpdb->prefix . 'account_status');

      $rows = [];

      // Netos (Sub-Logins)
      if ($is_admin) {
        $grand_rows = $wpdb->get_results("
          SELECT u.ID, u.display_name, u.user_email,
                 l.parent_user_id AS master_id,
                 COALESCE(s.status,'active') AS status
          FROM {$linksT} l
          INNER JOIN {$wpdb->users} u ON u.ID = l.child_user_id
          LEFT JOIN {$statusT} s ON s.user_id = u.ID
          WHERE l.depth = 2
        ");
      } else {
        $grand_rows = $wpdb->get_results($wpdb->prepare("
          SELECT u.ID, u.display_name, u.user_email,
                 l.parent_user_id AS master_id,
                 COALESCE(s.status,'active') AS status
          FROM {$linksT} l
          INNER JOIN {$wpdb->users} u ON u.ID = l.child_user_id
          LEFT JOIN {$statusT} s ON s.user_id = u.ID
          WHERE l.depth = 2 AND l.parent_user_id = %d
        ", $viewer_id));
      }

      foreach ((array) $grand_rows as $gr) {
        // ✅ evita N+1 (meta/status serão preenchidos em batch mais abaixo)
        $gr->tipo = 'Sub-Login';
        $gr->phone = '';
        $rows[] = $gr;
      }

      // Admin também inclui Masters (role: child) — sem get_users() (evita carga e N+1)
      if ($is_admin) {
        $cap_key = $wpdb->prefix . 'capabilities';

        // Procura pela role "child" dentro do array serializado de capabilities
        // (compatível com WP, e bem mais leve que carregar objetos completos via get_users)
        $masters = $wpdb->get_results($wpdb->prepare("
          SELECT u.ID, u.display_name, u.user_email
          FROM {$wpdb->users} u
          INNER JOIN {$wpdb->usermeta} um
            ON um.user_id = u.ID
           AND um.meta_key = %s
           AND um.meta_value LIKE %s
        ", $cap_key, '%"child"%'));

        foreach ((array) $masters as $m) {
          $rows[] = (object) [
            'ID' => (int) $m->ID,
            'display_name' => (string) $m->display_name,
            'user_email' => (string) $m->user_email,
            'master_id' => (int) $m->ID,
            'status' => 'active', // será sobrescrito em batch se existir em account_status
            'tipo' => 'Master',
            'phone' => '',       // preenchido em batch
          ];
        }
      }

      // ✅ Batch: phone (usermeta) + status (account_status) para TODOS
      $all_ids = array_values(array_unique(array_map(fn($x) => (int) $x->ID, $rows)));
      if (!empty($all_ids)) {
        $in = implode(',', array_fill(0, count($all_ids), '%d'));

        // phone (usermeta)
        $phone_map = [];
        $phone_sql = $wpdb->prepare(
          "SELECT user_id, meta_value
           FROM {$wpdb->usermeta}
           WHERE meta_key = %s
             AND user_id IN ($in)",
          array_merge(['phone'], $all_ids)
        );
        $phones = $wpdb->get_results($phone_sql);
        foreach ((array) $phones as $p) {
          $phone_map[(int) $p->user_id] = (string) $p->meta_value;
        }

        // status (account_status)
        $status_map = [];
        $status_sql = $wpdb->prepare(
          "SELECT user_id, status
           FROM {$statusT}
           WHERE user_id IN ($in)",
          $all_ids
        );
        $statuses = $wpdb->get_results($status_sql);
        foreach ((array) $statuses as $s) {
          $status_map[(int) $s->user_id] = (string) $s->status;
        }

        foreach ($rows as $rrow) {
          $uid = (int) $rrow->ID;
          $rrow->phone = $phone_map[$uid] ?? '';
          if (isset($status_map[$uid]) && $status_map[$uid] !== '') {
            $rrow->status = $status_map[$uid];
          } elseif (!isset($rrow->status) || $rrow->status === '') {
            $rrow->status = 'active';
          }
        }
      }
      // Filtro por master (admin)
      if ($is_admin && $filter_master > 0) {
        $rows = array_values(array_filter($rows, function ($r) use ($filter_master) {
          return ((int) $r->ID === $filter_master) || ((int) ($r->master_id ?? 0) === $filter_master);
        }));
      }

      // Filtro status
      if ($filter_status === 'active' || $filter_status === 'inactive') {
        $rows = array_values(array_filter($rows, fn($r) => (string) ($r->status ?? 'active') === $filter_status));
      }

      // Busca livre
      if ($q_norm !== '') {
        $rows = array_values(array_filter($rows, function ($r) use ($q_norm) {
          $name = mb_strtolower((string) ($r->display_name ?? ''));
          $email = mb_strtolower((string) ($r->user_email ?? ''));
          $phone = mb_strtolower((string) ($r->phone ?? ''));
          return (strpos($name, $q_norm) !== false) ||
            (strpos($email, $q_norm) !== false) ||
            (strpos($phone, $q_norm) !== false);
        }));
      }

      // Créditos (batch via lots)
      $lotsT = $wpdb->prefix . 'credit_lots';
      $now_mysql = current_time('mysql');

      $ids = array_values(array_unique(array_map(fn($x) => (int) $x->ID, $rows)));
      $credits_map = [];

      if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
          "SELECT owner_user_id AS user_id,
                  COALESCE(SUM(GREATEST(credits_total - credits_used, 0)), 0) AS available
           FROM {$lotsT}
           WHERE owner_user_id IN ($placeholders)
             AND (expires_at IS NULL OR expires_at >= %s)
           GROUP BY owner_user_id",
          array_merge($ids, [$now_mysql])
        );

        $sums = $wpdb->get_results($sql);
        foreach ((array) $sums as $w) {
          $credits_map[(int) $w->user_id] = (int) $w->available;
        }
      }

      foreach ($rows as $rr) {
        $rr->creditos = $credits_map[(int) $rr->ID] ?? 0;
      }

      // Filtro por créditos
      if ($filter_credits === 'has') {
        $rows = array_values(array_filter($rows, fn($r) => (int) ($r->creditos ?? 0) > 0));
      } elseif ($filter_credits === 'none') {
        $rows = array_values(array_filter($rows, fn($r) => (int) ($r->creditos ?? 0) === 0));
      }

      // Ordena por nome
      usort($rows, fn($a, $b) => strcasecmp((string) $a->display_name, (string) $b->display_name));

      // Chunk
      return array_slice($rows, (int) $offset, (int) $limit);
    },

    'map_row' => function ($row) {
      $obj = is_array($row) ? (object) $row : $row;

      $status = (string) ($obj->status ?? 'active');
      $status_txt = ($status === 'inactive') ? 'Inativo' : 'Ativo';

      return [
        (string) ($obj->tipo ?? 'Sub-Login'),
        (string) ($obj->display_name ?? ''),
        (string) ($obj->phone ?? ''),
        (string) ($obj->user_email ?? ''),
        $status_txt,
        (int) ($obj->creditos ?? 0),
      ];
    },
  ];

  // ============================================================
  // REPORT: subscriptions (export do shortcode assinaturas)
  // ============================================================
  $r['subscriptions'] = [
    'columns' => [
      'contract_id' => 'Nº contrato',
      'service' => 'Serviço',
      'valid_until' => 'Vencimento',
      'total' => 'Créditos totais',
      'available' => 'Créditos disponíveis',
      'used' => 'Créditos usados',
      'status' => 'Status',
    ],

    'filters' => [
      'user_id' => ['type' => 'int', 'default' => 0], // admin usa
      'service_id' => ['type' => 'int', 'default' => 0],
    ],

    'chunk_size' => 500,
    'filename' => fn($state) => 'assinaturas_' . date('Y-m-d') . '.csv',
    'can_export' => fn() => is_user_logged_in(),

    'normalize_state' => function ($state) {
      $is_admin = current_user_can('manage_options');

      if ($is_admin) {
        $state['target_id'] = (int) ($state['user_id'] ?? 0);
      } else {
        $state['target_id'] = get_current_user_id();
        $state['user_id'] = 0;
      }

      $state['service_id'] = (int) ($state['service_id'] ?? 0);

      return $state;
    },

    'fetch' => function ($state, $limit, $offset) {
      global $wpdb;

      $contractsT = function_exists('acme_table_credit_contracts') ? acme_table_credit_contracts() : ($wpdb->prefix . 'credit_contracts');
      $servicesT = function_exists('acme_table_services') ? acme_table_services() : ($wpdb->prefix . 'services');

      $target_id = (int) ($state['target_id'] ?? 0);
      $service_id = (int) ($state['service_id'] ?? 0);

      if (current_user_can('manage_options') && $target_id <= 0)
        return [];

      $where = "c.child_user_id = %d";
      $params = [$target_id];

      if ($service_id > 0) {
        $where .= " AND c.service_id = %d";
        $params[] = $service_id;
      }

      $sql = "
        SELECT
          c.id,
          c.child_user_id,
          c.service_id,
          c.credits_total,
          c.credits_used,
          c.valid_until,
          c.created_at,
          s.name AS service_name,
          s.slug AS service_slug
        FROM {$contractsT} c
        LEFT JOIN {$servicesT} s ON s.id = c.service_id
        WHERE {$where}
        ORDER BY c.valid_until DESC, c.id DESC
        LIMIT %d OFFSET %d
      ";

      $params2 = array_merge($params, [(int) $limit, (int) $offset]);
      return $wpdb->get_results($wpdb->prepare($sql, ...$params2), ARRAY_A);
    },

    'map_row' => function ($row) {
      $now_ts = current_time('timestamp');

      $total = (int) ($row['credits_total'] ?? 0);
      $used = (int) ($row['credits_used'] ?? 0);
      $avail = max(0, $total - $used);

      $valid_until = (string) ($row['valid_until'] ?? '');
      $exp = $valid_until ? strtotime($valid_until) : false;

      if ($exp && $now_ts > $exp)
        $status = 'VENCIDO';
      elseif ($avail <= 0)
        $status = 'EXCEDIDO';
      else
        $status = 'ATIVO';

      $svc = $row['service_name'] ?: ('Serviço #' . (int) ($row['service_id'] ?? 0));
      $venc = $valid_until ? date_i18n('d/m/Y H:i', strtotime($valid_until)) : '-';

      return [
        '#' . (int) ($row['id'] ?? 0),
        (string) $svc,
        (string) $venc,
        $total,
        $avail,
        $used,
        $status,
      ];
    },
  ];


  // ============================================================
  // REPORT: clt_history (Histórico CLT com filtros da tela)
  // ============================================================
  $r['clt_history'] = [
    'columns' => [
      'identificador' => 'Identificação',
      'status' => 'Status',
      'user' => 'Usuário',
      'cpf' => 'CPF',
      'created_at' => 'Criado em',
      'completed_at' => 'Concluído em',
      'error' => 'Erro',
    ],

    // Mantém o mesmo nome dos GET da tela:
    // clt_user, clt_status, clt_cpf, clt_date_from, clt_date_to
    'filters' => [
      'clt_user' => ['type' => 'text', 'default' => ''], // admin: id OU nome/email
      'clt_status' => ['type' => 'enum', 'default' => '', 'allowed' => ['pending', 'completed', 'failed']],
      'clt_cpf' => ['type' => 'text', 'default' => ''],
      'clt_date_from' => ['type' => 'date', 'default' => ''],
      'clt_date_to' => ['type' => 'date', 'default' => ''],
    ],

    'chunk_size' => 500,
    'filename' => fn($state) => 'historico_clt_' . date('Y-m-d') . '.csv',
    'can_export' => fn() => is_user_logged_in(),

    'normalize_state' => function ($state) {
      $viewer_id = get_current_user_id();
      $is_admin = current_user_can('manage_options');

      // Normaliza datas para montar range no fetch()
      $state['dt_from'] = !empty($state['clt_date_from']) ? ($state['clt_date_from'] . ' 00:00:00') : '';
      $state['dt_to'] = !empty($state['clt_date_to']) ? ($state['clt_date_to'] . ' 23:59:59') : '';

      // Permissão: não-admin só exporta dele
      if (!$is_admin) {
        $state['target_mode'] = 'id';
        $state['target_id'] = (int) $viewer_id;

        // zera filtros admin-only
        $state['clt_user'] = '';
        return $state;
      }

      // Admin: pode exportar todos (sem filtro) ou filtrar por usuário
      $u = trim((string) ($state['clt_user'] ?? ''));
      if ($u === '') {
        $state['target_mode'] = 'all';   // todos
        $state['target_id'] = 0;
        $state['target_q'] = '';
        return $state;
      }

      if (ctype_digit($u)) {
        $state['target_mode'] = 'id';
        $state['target_id'] = (int) $u;
        $state['target_q'] = '';
      } else {
        $state['target_mode'] = 'q';
        $state['target_id'] = 0;
        $state['target_q'] = $u; // nome/email
      }

      return $state;
    },

    'fetch' => function ($state, $limit, $offset) {
      global $wpdb;

      $t = $wpdb->prefix . 'clt_requests';
      $usersT = $wpdb->users;

      $where = [];
      $params = [];

      // Garantia explícita: exportar somente registros do serviço CLT
      $where[] = 't.service_slug = %s';
      $params[] = 'clt';

      // Usuário alvo (admin e não-admin)
      $mode = (string) ($state['target_mode'] ?? 'all');

      if ($mode === 'id') {
        $where[] = 't.user_id = %d';
        $params[] = (int) ($state['target_id'] ?? 0);
      } elseif ($mode === 'q') {
        $like = '%' . $wpdb->esc_like((string) ($state['target_q'] ?? '')) . '%';
        $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
        $params[] = $like;
        $params[] = $like;
      } // 'all' => sem filtro

      // Status
      $st = (string) ($state['clt_status'] ?? '');
      if ($st !== '') {
        $where[] = 't.status = %s';
        $params[] = $st;
      }

      // CPF: 11 dígitos => cpf_hash (exato), senão => cpf_masked LIKE
      $cpf_raw = (string) ($state['clt_cpf'] ?? '');
      if ($cpf_raw !== '') {
        $cpf_numbers = preg_replace('/\D/', '', $cpf_raw);

        if (strlen($cpf_numbers) === 11) {
          $where[] = 't.cpf_hash = %s';
          $params[] = hash('sha256', $cpf_numbers); // igual ao padrão que você grava
        } else {
          $where[] = 't.cpf_masked LIKE %s';
          $params[] = '%' . $wpdb->esc_like($cpf_raw) . '%';
        }
      }

      // Datas (created_at)
      $dt_from = (string) ($state['dt_from'] ?? '');
      $dt_to = (string) ($state['dt_to'] ?? '');

      if ($dt_from && $dt_to) {
        $where[] = '(t.created_at BETWEEN %s AND %s)';
        $params[] = $dt_from;
        $params[] = $dt_to;
      } elseif ($dt_from) {
        $where[] = 't.created_at >= %s';
        $params[] = $dt_from;
      } elseif ($dt_to) {
        $where[] = 't.created_at <= %s';
        $params[] = $dt_to;
      }

      $where_sql = '';
      if (!empty($where)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where);
      }

      $sql = "
      SELECT
        t.*,
        u.display_name,
        u.user_email
      FROM {$t} t
      LEFT JOIN {$usersT} u ON u.ID = t.user_id
      {$where_sql}
      ORDER BY t.created_at DESC
      LIMIT %d OFFSET %d
    ";

      $params[] = (int) $limit;
      $params[] = (int) $offset;

      return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    },

    'map_row' => function ($row) {
      $request_id = (string) ($row['request_id'] ?? '');
      $ident = $request_id ? str_replace('clt_', '', $request_id) : '';

      $status = (string) ($row['status'] ?? '');

      $uid = (int) ($row['user_id'] ?? 0);
      $name = (string) ($row['display_name'] ?? '');
      $user = $name ? ($name . ' #' . $uid) : ('#' . $uid);

      $cpf = (string) ($row['cpf_masked'] ?? '');

      $created = (string) ($row['created_at'] ?? '');
      $created_fmt = $created ? date_i18n('d/m/Y H:i', strtotime($created)) : '';

      $completed = (string) ($row['completed_at'] ?? '');
      $completed_fmt = $completed ? date_i18n('d/m/Y H:i', strtotime($completed)) : '';

      $err = trim((string) ($row['error_code'] ?? ''));
      $msg = trim((string) ($row['error_message'] ?? ''));
      $error = $err || $msg ? ($err . ($err && $msg ? ' | ' : '') . $msg) : '';

      return [
        $ident,
        $status,
        $user,
        $cpf,
        $created_fmt,
        $completed_fmt,
        $error,
      ];
    },
  ];


  return $r;
});
