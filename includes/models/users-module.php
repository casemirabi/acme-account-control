<?php
if (!defined('ABSPATH'))
    exit;
/**
 * ============================================================
 * MÓDULO: Usuários (hierarquia + cascata + front-end)
 * ============================================================
 */

/**
 * Se você já criou as tabelas via SQL, pode deixar vazio.
 * Se quiser criar via plugin depois, aqui vai o dbDelta.
 */
function acme_users_activate()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $linksT = acme_table_links();   // deve retornar wp_account_links
    $statusT = acme_table_status();  // deve retornar wp_account_status

    // 1) Links (vínculo hierárquico)
    $sql_links = "CREATE TABLE {$linksT} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_user_id BIGINT UNSIGNED NOT NULL,
    child_user_id BIGINT UNSIGNED NOT NULL,
    depth TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_link (parent_user_id, child_user_id),
    KEY idx_parent (parent_user_id),
    KEY idx_child (child_user_id),
    KEY idx_depth (depth)
  ) {$charset};";

    // 2) Status por usuário
    /*$sql_status = "CREATE TABLE {$statusT} (
    user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_status (status)
  ) {$charset};";*/
    $sql_status = "CREATE TABLE {$statusT} (
    user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    disabled_at DATETIME NULL,
    disabled_by BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_status (status),
    KEY idx_disabled_by (disabled_by)
    ) {$charset};";


    dbDelta($sql_links);
    dbDelta($sql_status);
}


/**
 * ============================================================
 * 1) ADMIN: CAMPOS DE VÍNCULO NETO -> FILHO (UI)
 * - Mostra no perfil/edição do usuário
 * - Mostra na criação de usuário (Add New) também
 * ============================================================
 */

add_action('show_user_profile', 'acme_parent_field');
add_action('edit_user_profile', 'acme_parent_field');
add_action('user_new_form', 'acme_parent_field');

function acme_parent_field($user)
{
    $is_new_user_screen = is_string($user);
    $roles = $is_new_user_screen ? [] : (array) $user->roles;

    if (!$is_new_user_screen && !in_array('grandchild', $roles, true))
        return;

    global $wpdb;
    $links = acme_table_links();

    $children = get_users(['role' => 'child']);

    $current = null;
    if (!$is_new_user_screen) {
        $current = $wpdb->get_var($wpdb->prepare("
            SELECT parent_user_id
            FROM {$links}
            WHERE child_user_id = %d AND depth = 2
        ", $user->ID));
    }
?>
    <h3>Vínculo</h3>
    <table class="form-table">
        <tr>
            <th><?php echo esc_html(acme_role_label('child')); ?> responsável</th>
            <td>
                <select name="acme_parent_child" required>
                    <option value="">Selecione</option>
                    <?php foreach ($children as $c): ?>
                        <option value="<?php echo (int) $c->ID; ?>" <?php echo selected($current, $c->ID, false); ?>>
                            <?php echo esc_html($c->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Obrigatório para usuários do tipo
                    <strong><?php echo esc_html(acme_role_label('grandchild')); ?></strong>.
                </p>
            </td>
        </tr>
    </table>
<?php
}

/**
 * ============================================================
 * 2) ADMIN: VALIDAÇÃO (CRIAÇÃO) -> NETO PRECISA TER FILHO
 * ============================================================
 */

add_filter('user_profile_update_errors', 'acme_validate_parent_on_create', 10, 3);

function acme_validate_parent_on_create($errors, $update, $user)
{
    if ($update)
        return;
    if (empty($_POST['role']) || $_POST['role'] !== 'grandchild')
        return;

    if (empty($_POST['acme_parent_child'])) {
        $errors->add('missing_parent', 'Sub-Login precisa obrigatoriamente ter um Master.');
    }
}



/**
 * ============================================================
 * 3) ADMIN: TELEFONE (usermeta phone)
 * - Campo no perfil do usuário
 * - Coluna na listagem do admin
 * - Campo na criação (Add New)
 * ============================================================
 */

add_action('show_user_profile', 'acme_admin_phone_field');
add_action('edit_user_profile', 'acme_admin_phone_field');

function acme_admin_phone_field($user)
{
    $phone = get_user_meta($user->ID, 'phone', true);
?>
    <h3>Contato</h3>
    <table class="form-table">
        <tr>
            <th><label for="acme_phone">Telefone</label></th>
            <td>
                <input type="text" name="acme_phone" id="acme_phone" value="<?php echo esc_attr($phone); ?>"
                    class="regular-text" placeholder="+5511999999999">
                <p class="description">Salvo em usermeta: <code>phone</code></p>
            </td>
        </tr>
    </table>
<?php
}

add_action('personal_options_update', 'acme_admin_save_phone');
add_action('edit_user_profile_update', 'acme_admin_save_phone');

function acme_admin_save_phone($user_id)
{
    if (!current_user_can('edit_user', $user_id))
        return;
    if (!isset($_POST['acme_phone']))
        return;

    $phone = acme_sanitize_phone((string) $_POST['acme_phone']);
    update_user_meta($user_id, 'phone', $phone);
}

// Coluna Telefone no admin
add_filter('manage_users_columns', function ($cols) {
    $cols['acme_phone'] = 'Telefone';
    return $cols;
});

add_filter('manage_users_custom_column', function ($val, $column, $user_id) {
    if ($column !== 'acme_phone')
        return $val;
    $phone = get_user_meta($user_id, 'phone', true);
    return $phone ? esc_html($phone) : '—';
}, 10, 3);

// Campo Telefone na criação
add_action('user_new_form', function ($context) {
?>
    <h3>Contato</h3>
    <table class="form-table">
        <tr>
            <th><label for="acme_phone">Telefone</label></th>
            <td>
                <input type="text" name="acme_phone" id="acme_phone" class="regular-text" placeholder="+5511999999999">
            </td>
        </tr>
    </table>
<?php
});

add_action('user_register', function ($user_id) {
    if (!isset($_POST['acme_phone']))
        return;
    $phone = acme_sanitize_phone((string) $_POST['acme_phone']);
    if ($phone !== '')
        update_user_meta($user_id, 'phone', $phone);
});

/**
 * ============================================================
 * 4) VÍNCULO: SALVAR NETO -> FILHO (depth=2)
 * ============================================================
 */

add_action('user_register', 'acme_save_parent');
add_action('personal_options_update', 'acme_save_parent');
add_action('edit_user_profile_update', 'acme_save_parent');

function acme_save_parent($user_id)
{

    $user = get_user_by('id', $user_id);

    // Criação: role vem no POST
    if (!$user) {
        if (empty($_POST['role']) || $_POST['role'] !== 'grandchild')
            return;
    } else {
        if (!acme_user_has_role($user, 'grandchild'))
            return;
    }

    if (empty($_POST['acme_parent_child']))
        wp_die('Sub-Login precisa obrigatoriamente ter um Master.');

    global $wpdb;
    $links = acme_table_links();
    $parent = (int) $_POST['acme_parent_child'];

    // Remove vínculo anterior e insere o novo
    $wpdb->delete($links, ['child_user_id' => $user_id, 'depth' => 2]);
    $wpdb->insert($links, [
        'parent_user_id' => $parent,
        'child_user_id' => $user_id,
        'depth' => 2,
    ]);
}

/**
 * ============================================================
 * 5) AUTO-VÍNCULO: FILHO -> ADMIN MASTER (depth=1)
 * ============================================================
 */

add_action('user_register', function ($user_id) {

    if (empty($_POST['role']) || $_POST['role'] !== 'child')
        return;

    global $wpdb;
    $links = acme_table_links();
    $admin_id = acme_master_admin_id();

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$links} WHERE parent_user_id=%d AND child_user_id=%d AND depth=1",
        $admin_id,
        $user_id
    ));

    if (!$exists) {
        $wpdb->insert($links, [
            'parent_user_id' => $admin_id,
            'child_user_id' => $user_id,
            'depth' => 1,
        ]);
    }
});

/**
 * ============================================================
 * 6) SEGURANÇA: BLOQUEAR LOGIN DE INATIVOS + DERRUBAR SESSÃO ATIVA
 * ============================================================
 */

add_filter('authenticate', function ($user) {

    if ($user instanceof WP_Error)
        return $user;
    if (!$user)
        return $user;

    // Admin sempre entra
    if (user_can($user, 'administrator'))
        return $user;

    global $wpdb;
    $statusT = acme_table_status();

    $s = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$statusT} WHERE user_id=%d",
        $user->ID
    ));

    if ($s === 'inactive') {
        return new WP_Error('inactive', 'Conta inativa.');
    }

    return $user;
}, 30);

// Logout forçado se estiver ativo e ficar inativo
add_action('init', function () {

    if (!is_user_logged_in())
        return;

    $uid = get_current_user_id();

    if (user_can($uid, 'administrator'))
        return;

    global $wpdb;
    $statusT = acme_table_status();

    $s = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$statusT} WHERE user_id=%d",
        $uid
    ));

    if ($s === 'inactive') {
        wp_logout();
        wp_clear_auth_cookie();
        wp_redirect(wp_login_url());
        exit;
    }
});



/**
 * ============================================================
 * 7) ADMIN: AÇÕES NA LISTAGEM (Inativar / Reativar) + STATUS BADGE
 * ============================================================
 */

// Link "Inativar" na lista de usuários
add_filter('user_row_actions', 'acme_add_deactivate_link', 10, 2);

function acme_add_deactivate_link($actions, $user)
{

    if (user_can($user, 'administrator'))
        return $actions;

    if (in_array('child', (array) $user->roles, true) || in_array('grandchild', (array) $user->roles, true)) {

        $url = wp_nonce_url(
            admin_url("admin-post.php?action=acme_deactivate_user&user_id=" . (int) $user->ID),
            'acme_deactivate_' . (int) $user->ID
        );

        $actions['acme_deactivate'] = '<a style="color:red" href="' . esc_url($url) . '">Inativar conta</a>';
    }

    return $actions;
}

// Handler do clique (admin)
add_action('admin_post_acme_deactivate_user', 'acme_handle_deactivate');

function acme_handle_deactivate()
{

    /*if (empty($_GET['user_id']))
        wp_die('Usuário inválido.');*/

    $user_id = (int) $_GET['user_id'];

    check_admin_referer('acme_deactivate_' . $user_id);

    if (!current_user_can('manage_options'))
        wp_die('Sem permissão.');

    acme_deactivate_tree($user_id);

    wp_redirect(admin_url('users.php'));
    exit;
}

// Cascata: inativa alvo; se alvo for FILHO, inativa netos dele


// Coluna Status no admin
add_filter('manage_users_columns', function ($cols) {
    $cols['acme_status'] = 'Status';
    return $cols;
});

add_filter('manage_users_custom_column', function ($val, $column, $user_id) {

    if ($column !== 'acme_status')
        return $val;

    global $wpdb;
    $statusT = acme_table_status();

    $status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$statusT} WHERE user_id=%d",
        $user_id
    ));

    if ($status === 'inactive')
        return '<span style="color:red;font-weight:600">Inativo</span>';
    return '<span style="color:green">Ativo</span>';
}, 10, 3);

// Link "Reativar" na lista de usuários (admin)
add_filter('user_row_actions', 'acme_add_activate_link', 11, 2);

function acme_add_activate_link($actions, $user)
{

    if (user_can($user, 'administrator'))
        return $actions;

    global $wpdb;
    $statusT = acme_table_status();

    $s = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$statusT} WHERE user_id=%d",
        $user->ID
    ));

    if ($s === 'inactive') {

        $url = wp_nonce_url(
            admin_url("admin-post.php?action=acme_activate_user&user_id=" . (int) $user->ID),
            'acme_activate_' . (int) $user->ID
        );

        $actions['acme_activate'] = '<a style="color:green" href="' . esc_url($url) . '">Reativar</a>';
    }

    return $actions;
}

add_action('admin_post_acme_activate_user', 'acme_handle_activate');

function acme_handle_activate()
{
    if (empty($_GET['user_id'])) {
        wp_die('Usuário inválido.');
    }

    $targetUserId = (int) $_GET['user_id'];

    check_admin_referer('acme_activate_' . $targetUserId);

    if (!current_user_can('manage_options')) {
        wp_die('Sem permissão.');
    }

    if (!function_exists('acme_users_set_status')) {
        wp_die('Service de status não carregado.');
    }

    $targetUser = get_user_by('id', $targetUserId);

    // Regra de negócio já existente no wp-admin:
    // Sub-Login só ativa se Master estiver ativo
    if ($targetUser && acme_user_has_role($targetUser, 'grandchild')) {
        $masterUserId = acme_get_master_id_of_grandchild($targetUserId);

        if ($masterUserId <= 0 || !acme_account_is_active($masterUserId)) {
            wp_die('Não é possível ativar este Sub-Login porque o Master está inativo (ou vínculo ausente).');
        }
    }

    $updated = acme_users_set_status(
        $targetUserId,
        'active',
        null,
        null,
        null,
        [
            'mode' => 'update_only',
        ]
    );

    if (!$updated) {
        wp_die('Falha ao ativar usuário.');
    }

    wp_safe_redirect(admin_url('users.php'));
    exit;
}

/**
 * ============================================================
 * 8) SHORTCODE SIMPLES (opcional): [acme_my_grandchildren]
 * - Admin vê netos
 * - Filho vê netos dele
 * ============================================================
 */

function acme_shortcode_my_grandchildren($atts)
{

    if (!is_user_logged_in())
        return '<p>Você precisa estar logado.</p>';

    $uid = get_current_user_id();
    $me = wp_get_current_user();

    $is_admin = user_can($uid, 'administrator');
    $is_child = in_array('child', (array) $me->roles, true);

    /*if (!$is_admin && !$is_child)
        return '<p>Sem permissão para visualizar.</p>';*/

    global $wpdb;
    $links = acme_table_links();
    $status = acme_table_status();
    $usersT = $wpdb->users;

    if ($is_admin) {
        $rows = $wpdb->get_results("
            SELECT u.ID, u.display_name, u.user_email,
                   COALESCE(s.status,'active') AS status,
                   s.disabled_at
            FROM {$links} l
            INNER JOIN {$usersT} u ON u.ID = l.child_user_id
            LEFT JOIN {$status} s ON s.user_id = u.ID
            WHERE l.depth = 2
            ORDER BY u.display_name ASC
        ");
    } else {

        $rows = $wpdb->get_results($wpdb->prepare("
        SELECT 
            u.ID,
            u.display_name,
            u.user_email,
            COALESCE(s.status,'active') AS status,
            s.disabled_at,
            COALESCE(SUM(ct.credits), 0) AS credits
        FROM {$links} l
        INNER JOIN {$usersT} u ON u.ID = l.child_user_id
        LEFT JOIN {$status} s ON s.user_id = u.ID
        LEFT JOIN wp_credit_transactions ct 
            ON ct.user_id = u.ID
            AND ct.type = 'credit'
            AND ct.status = 'success'
        WHERE l.parent_user_id = %d 
        AND l.depth = 2
        GROUP BY 
            u.ID, u.display_name, u.user_email, s.status, s.disabled_at
        ORDER BY u.display_name ASC
    ", $uid));
    }


    if (empty($rows))
        return '<p>Nenhum ' . esc_html(acme_role_label('grandchild')) . ' encontrado.</p>'; #return '<p>Nenhum neto encontrado.</p>';

    ob_start(); ?>
    <div class="acme-table-wrap">
        <table class="acme-table" style="width:100%;border-collapse:collapse">
            <thead>
                <tr>
                    <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px">Nome</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px">E-mail</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px">Status</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;padding:8px">Inativo desde</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="border-bottom:1px solid #eee;padding:8px"><?php echo esc_html($r->display_name); ?></td>
                        <td style="border-bottom:1px solid #eee;padding:8px"><?php echo esc_html($r->user_email); ?></td>
                        <td style="border-bottom:1px solid #eee;padding:8px">
                            <?php echo ($r->status === 'inactive')
                                ? '<span style="color:#b00020;font-weight:600">Inativo</span>'
                                : '<span style="color:#0a7a2f">Ativo</span>'; ?>
                        </td>
                        <td style="border-bottom:1px solid #eee;padding:8px">
                            <?php echo $r->disabled_at ? esc_html($r->disabled_at) : '-'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
    return ob_get_clean();
};

/**
 * ============================================================
 * 9) FRONT-END HANDLERS (admin-post):
 * - Ativar/Inativar (com cascata se alvo for FILHO)
 * - Alterar senha
 * - Atualizar telefone
 * ============================================================
 */



function acme_fe_toggle_status()
{
    if (!is_user_logged_in()) {
        wp_die('Você precisa estar logado.');
    }

    $actorId = get_current_user_id();

    if (!isset($_POST['user_id'], $_POST['_wpnonce'], $_POST['do'])) {
        wp_die('Requisição inválida.');
    }

    $targetId = (int) $_POST['user_id'];
    $action = sanitize_key((string) $_POST['do']);

    if (!wp_verify_nonce($_POST['_wpnonce'], 'acme_fe_toggle_' . $targetId)) {
        wp_die('Nonce inválido.');
    }

    $actor = wp_get_current_user();
    $actorIsAdmin = user_can($actorId, 'administrator');
    $actorIsChild = in_array('child', (array) $actor->roles, true);

    if (!$actorIsAdmin && !$actorIsChild) {
        wp_die('Sem permissão.');
    }

    $result = acme_users_toggle_status($actorId, $targetId, $action);
    #$redirectUrl = wp_get_referer() ?: home_url('/');
    $redirectUrl = isset($_POST['redirect_to'])
        ? esc_url_raw(wp_unslash($_POST['redirect_to']))
        : (wp_get_referer() ?: home_url('/'));

    if (!$result['success']) {
        if (in_array($result['code'], ['protected_user'], true)) {
            wp_safe_redirect($redirectUrl);
            exit;
        }

        if (in_array($result['code'], ['missing_master', 'inactive_master'], true)) {
            wp_safe_redirect(add_query_arg([
                'acme_msg' => 'err_master',
                'acme_err' => $result['message'],
            ], $redirectUrl));
            exit;
        }

        wp_die($result['message']);
    }

    wp_safe_redirect(add_query_arg('acme_msg', 'ok', $redirectUrl));
    exit;
}


function acme_fe_bulk_activate()
{
    if (!is_user_logged_in()) {
        wp_die('Você precisa estar logado.');
    }

    $actorId = get_current_user_id();
    $actor = wp_get_current_user();
    $isAdmin = user_can($actorId, 'administrator');
    $isChild = in_array('child', (array) $actor->roles, true);

    if (!$isAdmin && !$isChild) {
        wp_die('Sem permissão.');
    }

    if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acme_fe_bulk_activate')) {
        wp_die('Nonce inválido.');
    }

    if (!function_exists('acme_users_toggle_status')) {
        wp_die('Service de status não carregado.');
    }

    // IDs selecionados (checkbox) OU "todos" (scope_ids)
    $selectedIds = isset($_POST['user_ids']) ? (array) $_POST['user_ids'] : [];
    $scopeIds = isset($_POST['scope_ids']) ? (array) $_POST['scope_ids'] : [];
    $doAll = !empty($_POST['bulk_all']);

    $ids = $doAll ? $scopeIds : $selectedIds;

    // Sanitiza
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    $redirectUrl = wp_get_referer() ?: home_url('/');

    if (empty($ids)) {
        wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
        exit;
    }

    global $wpdb;
    $linksTable = acme_table_links();

    // Segurança: Master só pode ativar os próprios sub-logins
    if (!$isAdmin) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $allowedIds = $wpdb->get_col($wpdb->prepare(
            "SELECT child_user_id
             FROM {$linksTable}
             WHERE parent_user_id = %d
               AND depth = 2
               AND child_user_id IN ($placeholders)",
            array_merge([$actorId], $ids)
        ));

        $ids = array_values(array_unique(array_map('intval', (array) $allowedIds)));

        if (empty($ids)) {
            wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
            exit;
        }
    }

    // Proteções extras: não mexer em admin/master_admin
    $safeIds = [];

    foreach ($ids as $userId) {
        $userId = (int) $userId;

        if ($userId === (int) acme_master_admin_id()) {
            continue;
        }

        if (user_can($userId, 'administrator')) {
            continue;
        }

        $safeIds[] = $userId;
    }

    $ids = $safeIds;

    if (empty($ids)) {
        wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
        exit;
    }

    $activatedCount = 0;
    $skippedMasterInactive = 0;

    foreach ($ids as $userId) {
        $result = acme_users_toggle_status($actorId, (int) $userId, 'activate');

        if (!is_array($result) || empty($result['success'])) {
            if (!empty($result['code']) && in_array($result['code'], ['missing_master', 'inactive_master'], true)) {
                $skippedMasterInactive++;
            }

            continue;
        }

        $activatedCount++;
    }

    if ($activatedCount <= 0) {
        wp_safe_redirect(add_query_arg([
            'acme_msg'      => 'bulk_master_block',
            'bulk_skipped'  => $skippedMasterInactive,
        ], $redirectUrl));
        exit;
    }

    wp_safe_redirect(add_query_arg([
        'acme_msg'      => 'bulk_ok',
        'bulk_count'    => $activatedCount,
        'bulk_skipped'  => $skippedMasterInactive,
    ], $redirectUrl));
    exit;
}


function acme_fe_bulk_deactivate()
{
    if (!is_user_logged_in()) {
        wp_die('Você precisa estar logado.');
    }

    $actorId = get_current_user_id();
    $actor = wp_get_current_user();
    $isAdmin = user_can($actorId, 'administrator');
    $isChild = in_array('child', (array) $actor->roles, true);

    if (!$isAdmin && !$isChild) {
        wp_die('Sem permissão.');
    }

    $nonce = $_POST['_wpnonce'] ?? '';

    if (
        empty($nonce) ||
        (
            !wp_verify_nonce($nonce, 'acme_fe_bulk_deactivate') &&
            !wp_verify_nonce($nonce, 'acme_fe_bulk_activate')
        )
    ) {
        wp_die('Nonce inválido.');
    }

    if (!function_exists('acme_users_set_status')) {
        wp_die('Service de status não carregado.');
    }

    $selectedIds = isset($_POST['user_ids']) ? (array) $_POST['user_ids'] : [];
    $scopeIds = isset($_POST['scope_ids']) ? (array) $_POST['scope_ids'] : [];
    $doAll = !empty($_POST['bulk_all']);

    $ids = $doAll ? $scopeIds : $selectedIds;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    $redirectUrl = wp_get_referer() ?: home_url('/');

    if (empty($ids)) {
        wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
        exit;
    }

    global $wpdb;
    $linksTable = acme_table_links();

    // Master: só pode mexer nos próprios sub-logins (depth=2)
    if (!$isAdmin) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $allowedIds = $wpdb->get_col($wpdb->prepare(
            "SELECT child_user_id
             FROM {$linksTable}
             WHERE parent_user_id = %d
               AND depth = 2
               AND child_user_id IN ($placeholders)",
            array_merge([$actorId], $ids)
        ));

        $ids = array_values(array_unique(array_map('intval', (array) $allowedIds)));

        if (empty($ids)) {
            wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
            exit;
        }
    }

    // Proteções: nunca mexer em master_admin / admins
    $safeIds = [];

    foreach ($ids as $userId) {
        $userId = (int) $userId;

        if ($userId === (int) acme_master_admin_id()) {
            continue;
        }

        if (user_can($userId, 'administrator')) {
            continue;
        }

        $safeIds[] = $userId;
    }

    $ids = $safeIds;

    if (empty($ids)) {
        wp_safe_redirect(add_query_arg('acme_msg', 'bulk_none', $redirectUrl));
        exit;
    }

    $disabledAt = current_time('mysql');
    $count = 0;

    foreach ($ids as $userId) {
        $userId = (int) $userId;

        $updated = acme_users_set_status(
            $userId,
            'inactive',
            $actorId,
            'Bulk (Front-end)',
            $disabledAt,
            [
                'mode' => 'replace',
            ]
        );

        if ($updated) {
            $count++;
        }

        // Cascata: somente admin e somente se alvo for child
        if ($isAdmin) {
            $targetUser = get_user_by('id', $userId);
            $isTargetChild = $targetUser && in_array('child', (array) $targetUser->roles, true);

            if ($isTargetChild && function_exists('acme_users_deactivate_children_cascade')) {
                $count += (int) acme_users_deactivate_children_cascade(
                    $userId,
                    $actorId,
                    'Cascata (Bulk Front-end)'
                );
            }
        }
    }

    wp_safe_redirect(add_query_arg([
        'acme_msg'    => 'bulk_deact_ok',
        'bulk_count'  => $count,
    ], $redirectUrl));
    exit;
}



function acme_fe_set_password()
{

    if (!is_user_logged_in())
        wp_die('Você precisa estar logado.');

    $actor_id = get_current_user_id();

    if (!isset($_POST['user_id'], $_POST['_wpnonce'], $_POST['new_pass']))
        wp_die('Requisição inválida.');

    $target_id = (int) $_POST['user_id'];
    $new_pass = trim((string) $_POST['new_pass']);

    if (!wp_verify_nonce($_POST['_wpnonce'], 'acme_fe_pass_' . $target_id))
        wp_die('Nonce inválido.');

    $actor = wp_get_current_user();
    $is_admin = user_can($actor_id, 'administrator');
    $is_child = in_array('child', (array) $actor->roles, true);

    if (!$is_admin && !$is_child)
        wp_die('Sem permissão.');

    // Protege admin
    if ($target_id === acme_master_admin_id() || user_can($target_id, 'administrator')) {
        wp_safe_redirect(wp_get_referer() ?: home_url('/'));
        exit;
    }

    // Filho só altera senha dos próprios netos
    if (!$is_admin) {
        global $wpdb;
        $links = acme_table_links();

        $ok = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$links} WHERE parent_user_id=%d AND child_user_id=%d AND depth=2 LIMIT 1",
            $actor_id,
            $target_id
        ));
        if (!$ok)
            wp_die('Sem permissão para este usuário.');
    }

    if (strlen($new_pass) < 8)
        wp_die('Senha deve ter no mínimo 8 caracteres.');

    wp_set_password($new_pass, $target_id);

    // Derruba todas as sessões do alvo
    if (class_exists('WP_Session_Tokens')) {
        $tokens = WP_Session_Tokens::get_instance($target_id);
        $tokens->destroy_all();
    }

    wp_safe_redirect(add_query_arg('acme_msg', 'pass', wp_get_referer() ?: home_url('/')));
    exit;
}

function acme_fe_update_phone()
{

    if (!is_user_logged_in())
        wp_die('Você precisa estar logado.');

    $actor_id = get_current_user_id();

    if (!isset($_POST['user_id'], $_POST['_wpnonce'], $_POST['phone']))
        wp_die('Requisição inválida.');

    $target_id = (int) $_POST['user_id'];

    if (!wp_verify_nonce($_POST['_wpnonce'], 'acme_fe_phone_' . $target_id))
        wp_die('Nonce inválido.');

    $actor = wp_get_current_user();
    $is_admin = user_can($actor_id, 'administrator');
    $is_child = in_array('child', (array) $actor->roles, true);

    if (!$is_admin && !$is_child)
        wp_die('Sem permissão.');

    // Protege admin
    if ($target_id === acme_master_admin_id() || user_can($target_id, 'administrator')) {
        wp_safe_redirect(wp_get_referer() ?: home_url('/'));
        exit;
    }

    // Filho só edita telefone dos próprios netos
    if (!$is_admin) {
        global $wpdb;
        $links = acme_table_links();

        $ok = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$links} WHERE parent_user_id=%d AND child_user_id=%d AND depth=2 LIMIT 1",
            $actor_id,
            $target_id
        ));
        if (!$ok)
            wp_die('Sem permissão para este usuário.');
    }

    $phone = acme_sanitize_phone((string) $_POST['phone']);
    update_user_meta($target_id, 'phone', $phone);

    wp_safe_redirect(add_query_arg('acme_msg', 'phone', wp_get_referer() ?: home_url('/')));
    exit;
}

/**
 * ============================================================
 * 10) SHORTCODE PRINCIPAL (Elementor): [acme_my_grandchildren_manage]
 * Regras:
 * - Filho logado: vê apenas NETOS dele
 * - Admin logado: vê FILHOS + NETOS (com coluna Tipo)
 * - Filtro por nome/email/telefone (phone)
 * - Ações: telefone, ativar/inativar, senha
 * ============================================================
 */

function acme_shortcode_my_grandchildren_manage($atts = [])
{
    if (!is_user_logged_in()) {
        return '<p>Você precisa estar logado.</p>';
    }

    $currentUserId = get_current_user_id();
    $currentUser = wp_get_current_user();

    $isAdmin = user_can($currentUserId, 'administrator');
    $isChild = in_array('child', (array) $currentUser->roles, true);

    $filters = acme_users_manage_get_filters($isAdmin);

    $creditSummary = acme_users_manage_get_actor_credit_summary($currentUserId);
    $creditTotalAvailable = $creditSummary['credit_total_available'];
    $creditBreakdown = $creditSummary['credit_breakdown'];

    $rows = acme_users_manage_get_base_rows($currentUserId, $isAdmin);

    $creditsMap = acme_users_manage_get_credits_map($rows);
    foreach ($rows as $row) {
        $row->credits = $creditsMap[(int) $row->ID] ?? 0;
    }

    $rows = acme_users_manage_filter_rows($rows, $filters, $isAdmin);

    $q = $filters['q'];
    $filterMaster = $filters['filter_master'];
    $filterStatus = $filters['filter_status'];
    $filterCredits = $filters['filter_credits'];

    ob_start();

    echo function_exists('acme_ui_panel_css') ? acme_ui_panel_css() : '';

    $baseUrl = remove_query_arg(['acme_msg', 'acme_err']);

    $messageOk = (isset($_GET['acme_msg']) && in_array($_GET['acme_msg'], ['ok', 'pass', 'phone'], true));
    $messageText = '';

    if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'ok') {
        $messageText = 'Status atualizado.';
    }

    if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'pass') {
        $messageText = 'Senha alterada e sessão do usuário encerrada.';
    }

    if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'phone') {
        $messageText = 'Telefone atualizado.';
    }

    $hasAnyFilter = ($q !== '')
        || ($isAdmin && $filterMaster > 0)
        || ($filterStatus !== 'all')
        || ($filterCredits !== 'all');

?>
    <div class="acme-panel">

        <div class="acme-panel-h">
            <div>
                <div class="acme-panel-title">Usuários</div>
                <div class="acme-panel-sub">Gerencie Masters e Sub-Logins, filtre e visualize créditos.</div>
            </div>

            <div class="acme-actions">
                <a class="acme-btn" href="<?php echo esc_url($baseUrl); ?>">Atualizar</a>


                <?php echo do_shortcode('[acme_export_button report="users" label="Baixar usuários" class="acme-btn"]'); ?>

                <?php if ($hasAnyFilter): ?>
                    <a class="acme-btn" style="background:#fff;color:#0f172a;border:1px solid #e2e8f0;"
                        href="<?php echo esc_url(remove_query_arg(['q', 'master', 'status', 'credits', 'acme_msg'])); ?>">
                        Limpar filtros
                    </a>
                <?php endif; ?>


                <button type="submit"
                    form="acme-users-filter-form"
                    class="acme-btn-icon"
                    aria-label="Pesquisar">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2" />
                        <path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                </button>


            </div>
        </div>

        <div style="padding:14px 16px;">

            <?php if ($messageOk): ?>
                <div style="padding:10px 12px;border-radius:12px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:900;margin-bottom:12px;">
                    <?php echo esc_html($messageText); ?>
                </div>
            <?php endif; ?>

            <?php
            if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'bulk_ok') {
                $count = isset($_GET['bulk_count']) ? (int) $_GET['bulk_count'] : 0;
                echo '<div style="padding:10px 12px;border-radius:12px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:900;margin-bottom:12px;">
                    Ativação em massa concluída. Usuários ativados: ' . $count . '.
                </div>';
            } elseif (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'bulk_none') {
                echo '<div style="padding:10px 12px;border-radius:12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:900;margin-bottom:12px;">
                    Nenhum usuário selecionado (ou você não tem permissão).
                </div>';
            }

            if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'bulk_deact_ok') {
                $count = isset($_GET['bulk_count']) ? (int) $_GET['bulk_count'] : 0;
                echo '<div style="padding:10px 12px;border-radius:12px;background:#fff1f2;border:1px solid #fecaca;color:#991b1b;font-weight:900;margin-bottom:12px;">
                    Inativação em massa concluída. Usuários inativados: ' . $count . '.
                </div>';
            }

            if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'err_master') {
                $errorMessage = isset($_GET['acme_err'])
                    ? sanitize_text_field(wp_unslash($_GET['acme_err']))
                    : 'Não foi possível ativar.';

                echo '<div style="padding:10px 12px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-weight:900;margin-bottom:12px;">
                    ' . esc_html($errorMessage) . '
                </div>';
            }

            if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'bulk_master_block') {
                $skippedCount = isset($_GET['bulk_skipped']) ? (int) $_GET['bulk_skipped'] : 0;
                echo '<div style="padding:10px 12px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-weight:900;margin-bottom:12px;">
                    Nenhum usuário foi ativado: existem Sub-Logins cujo Master está inativo (ou sem vínculo). Bloqueados: ' . $skippedCount . '.
                </div>';
            }

            if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'bulk_ok') {
                $skippedCount = isset($_GET['bulk_skipped']) ? (int) $_GET['bulk_skipped'] : 0;

                if ($skippedCount > 0) {
                    echo '<div style="padding:10px 12px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-weight:900;margin-bottom:12px;">
                        Atenção: ' . $skippedCount . ' Sub-Login(s) não foram ativados porque o Master está inativo (ou vínculo ausente).
                    </div>';
                }
            }
            ?>

            <form id="acme-users-filter-form" method="get" class="acme-filter-grid">
                <div class="acme-filter-row-4">

                    <div class="acme-field">
                        <label class="acme-muted">Buscar (nome, email, telefone)</label>
                        <input class="acme-input" type="text" name="q"
                            value="<?php echo esc_attr($q); ?>"
                            placeholder="Digite para buscar...">
                    </div>

                    <div class="acme-field">
                        <label class="acme-muted">Status</label>
                        <select class="acme-input" name="status">
                            <option value="all" <?php selected($filterStatus, 'all'); ?>>Todos</option>
                            <option value="active" <?php selected($filterStatus, 'active'); ?>>Ativo</option>
                            <option value="inactive" <?php selected($filterStatus, 'inactive'); ?>>Inativo</option>
                        </select>
                    </div>

                    <div class="acme-field">
                        <label class="acme-muted">Créditos</label>
                        <select class="acme-input" name="credits">
                            <option value="all" <?php selected($filterCredits, 'all'); ?>>Todos</option>
                            <option value="has" <?php selected($filterCredits, 'has'); ?>>Com créditos</option>
                            <option value="none" <?php selected($filterCredits, 'none'); ?>>Sem créditos</option>
                        </select>
                    </div>

                    <?php if ($isAdmin): ?>
                        <div class="acme-field">
                            <label class="acme-muted">Master (somente Admin)</label>
                            <select class="acme-input" name="master">
                                <option value="0">Todos</option>
                                <?php
                                $childrenForFilter = get_users(['role' => 'child']);
                                foreach ((array) $childrenForFilter as $childUser) {
                                    //echo '<option value="' . (int) $childUser->ID . '" ' . selected($filterMaster, (int) $childUser->ID, false) . '>' .
                                    //esc_html($childUser->display_name) . ' (#' . (int) $childUser->ID . ')</option>';
                                    echo '<option value="' . (int) $childUser->ID . '" ' . selected($filterMaster, (int) $childUser->ID, false) . '>' .
                                        esc_html($childUser->display_name) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <div></div>
                    <?php endif; ?>

                </div>
            </form>

        </div>

        <?php if (empty($rows)): ?>
            <div style="padding:14px 16px;color:#64748b;">Nenhum usuário encontrado.</div>
    </div>
    <?php return ob_get_clean(); ?>
<?php endif; ?>

<div style="overflow:auto;">
    <?php
    $scopeIds = [];
    foreach ($rows as $row) {
        if (($row->acme_type ?? '') === acme_role_label('grandchild')) {
            $scopeIds[] = (int) $row->ID;
        }
    }
    $scopeIds = array_values(array_unique($scopeIds));
    ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('acme_fe_bulk_activate'); ?>

        <?php foreach ($scopeIds as $scopeId): ?>
            <input type="hidden" name="scope_ids[]" value="<?php echo (int) $scopeId; ?>">
        <?php endforeach; ?>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:0 16px 12px 16px;">

            <button type="submit"
                formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                name="action"
                value="acme_fe_bulk_activate"
                class="acme-btn"
                style="padding:7px 12px;font-size:12px;">
                Ativar selecionados
            </button>

            <button type="submit"
                formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                name="action"
                value="acme_fe_bulk_activate"
                class="acme-btn"
                style="padding:7px 12px;font-size:12px;"
                onclick="this.form.bulk_all.value='1';">
                Ativar todos
            </button>

            <button type="submit"
                formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                name="action"
                value="acme_fe_bulk_deactivate"
                class="acme-btn"
                style="padding:7px 12px;font-size:12px;background:#b00020;border-color:#b00020;color:#fff;">
                Inativar selecionados
            </button>

            <button type="submit"
                formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                name="action"
                value="acme_fe_bulk_deactivate"
                class="acme-btn"
                style="padding:7px 12px;font-size:12px;background:#b00020;border-color:#b00020;color:#fff;"
                onclick="this.form.bulk_all.value='1';">
                Inativar todos
            </button>

            <input type="hidden" name="bulk_all" value="">

            <span class="acme-muted" style="font-size:12px;">
                (Ação vale para usuários visíveis; Master só nos próprios Sub-Logins)
            </span>
        </div>

        <table class="acme-table">
            <thead>
                <tr>
                    <th style="width:34px;text-align:center;">
                        <input type="checkbox" id="acme_chk_all" />
                    </th>
                    <th>Tipo</th>
                    <th>Nome</th>
                    <th>Telefone</th>
                    <th>E-mail</th>
                    <th>Status</th>
                    <th>Créditos</th>
                    <th style="text-align:center;">Ações</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($rows as $row):
                    $isInactive = (($row->status ?? 'active') === 'inactive');

                    $editPage = site_url('/edit-user/');
                    $editUrl = add_query_arg([
                        'user_id' => (int) $row->ID,
                        'nonce'   => wp_create_nonce('acme_edit_user_' . (int) $row->ID),
                    ], $editPage);

                    $viewPage = site_url('/view-user/');
                    $viewUrl = add_query_arg([
                        'user_id' => (int) $row->ID,
                        'nonce'   => wp_create_nonce('acme_edit_user_' . (int) $row->ID),
                    ], $viewPage);

                    $isSubLogin = (($row->acme_type ?? '') === acme_role_label('grandchild'));
                ?>
                    <tr>

                        <td style="text-align:center;">
                            <?php if ($isSubLogin): ?>
                                <input type="checkbox" class="acme_chk_one" name="user_ids[]" value="<?php echo (int) $row->ID; ?>">
                            <?php else: ?>
                                <span style="opacity:.25;">—</span>
                            <?php endif; ?>
                        </td>

                        <td class="acme-muted"><?php echo esc_html($row->acme_type ?? '—'); ?></td>

                        <td>
                            <strong><?php echo esc_html($row->display_name); ?></strong>
                            <!--<div class="acme-muted" style="font-size:12px;">#<?php //echo (int) $row->ID; ?></div>-->
                        </td>

                        <td class="acme-muted"><?php echo esc_html($row->phone ?? '—'); ?></td>
                        <td class="acme-muted"><?php echo esc_html($row->user_email); ?></td>

                        <td>
                            <?php echo $isInactive
                                ? '<span class="acme-badge acme-badge-failed">Inativo</span>'
                                : '<span class="acme-badge acme-badge-completed">Ativo</span>'; ?>
                        </td>

                        <td style="font-weight:900;"><?php echo (int) ($row->credits ?? 0); ?></td>

                        <td style="text-align:center;">
                            <a class="acme-btn" href="<?php echo esc_url($viewUrl); ?>">Visualizar</a>
                            <a class="acme-btn" href="<?php echo esc_url($editUrl); ?>">Editar</a>
                        </td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </form>

    <script>
        (function() {
            const all = document.getElementById('acme_chk_all');
            if (!all) return;

            all.addEventListener('change', function() {
                document.querySelectorAll('.acme_chk_one').forEach(cb => cb.checked = all.checked);
            });
        })();
    </script>

</div>

</div>
<?php

    return ob_get_clean();
};


#CSS DO EDIT USER
function acme_enqueue_edit_user_shortcode_assets()
{
    // Registra, mas NÃO carrega ainda
    wp_register_style(
        'acme-shortcode-edit-user',
        plugin_dir_url(dirname(__FILE__)) . 'assets/css/shortcode-acme-edit-user.css',
        [],
        '1.0.0'
    );
}
add_action('wp_enqueue_scripts', 'acme_enqueue_edit_user_shortcode_assets');

function acme_maybe_enqueue_edit_user_assets()
{
    // Carrega o CSS quando o shortcode renderizar
    wp_enqueue_style('acme-shortcode-edit-user');
}

/**
 * ============================================================
 * 11) SHORTCODE Editar Usuarios (Elementor): [acme_edit_user]
 * ============================================================
 */
function acme_shortcode_edit_user()
{
    if (!is_user_logged_in()) {
        return '<p>Você precisa estar logado.</p>';
    }

    $actor_id = get_current_user_id();
    $actor = wp_get_current_user();
    $is_admin = user_can($actor_id, 'administrator');
    $is_child = in_array('child', (array) $actor->roles, true);

    if (!$is_admin && !$is_child) {
        return '<p>Sem permissão.</p>';
    }

    $target_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

    if ($target_id === acme_master_admin_id() || user_can($target_id, 'administrator')) {
        return '<p>Este usuário não pode ser editado aqui.</p>';
    }

    if (!$is_admin) {
        global $wpdb;
        $links = acme_table_links();
        $ok = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$links} WHERE parent_user_id=%d AND child_user_id=%d AND depth=2 LIMIT 1",
            $actor_id,
            $target_id
        ));

        if (!$ok) {
            return '<p>Sem permissão para editar este usuário.</p>';
        }
    }

    global $wpdb;
    $phone = get_user_meta($target_id, 'phone', true);

    $statusT = acme_table_status();
    $st = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$statusT} WHERE user_id=%d",
        $target_id
    ));
    $st = $st ?: 'active';

    $messageHtml = '';

    if (isset($_GET['acme_msg'])) {
        $msg = sanitize_text_field((string) $_GET['acme_msg']);

        if ($msg === 'phone') {
            $messageHtml = '<div class="acme-alert acme-alert--success">Telefone atualizado com sucesso.</div>';
        } elseif ($msg === 'pass') {
            $messageHtml = '<div class="acme-alert acme-alert--success">Senha alterada e sessão do usuário encerrada.</div>';
        } elseif ($msg === 'ok') {
            $messageHtml = '<div class="acme-alert acme-alert--success">Status atualizado com sucesso.</div>';
        } elseif ($msg === 'err_master') {
            $err = isset($_GET['acme_err']) ? sanitize_text_field(wp_unslash($_GET['acme_err'])) : 'Não foi possível atualizar o status.';
            $messageHtml = '<div class="acme-alert acme-alert--warning">' . esc_html($err) . '</div>';
        }
    }

    $actionStatus = ($st === 'inactive') ? 'activate' : 'deactivate';
    $statusButtonLabel = ($st === 'inactive') ? 'Ativar usuário' : 'Inativar usuário';
    $statusButtonStyle = ($st === 'inactive')
        ? 'background:#16a34a;border-color:#16a34a;color:#fff;'
        : 'background:#dc2626;border-color:#dc2626;color:#fff;';

    ob_start();



    echo function_exists('acme_ui_panel_css') ? acme_ui_panel_css() : '';
?>

    <div class="acme-panel">
        <div class="acme-panel-h">
            <div>
                <div class="acme-panel-sub">Atualize telefone, redefina senha e gerencie a situação do usuário.</div>
            </div>
        </div>

        <div style="padding:14px 16px;">
            <?php echo $messageHtml; ?>

            <div style="
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(320px,1fr));
    gap:20px;
">
                <div class="acme-field">
                    <label class="acme-muted" style="display:block;margin-bottom:6px;">Telefone</label>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <input type="hidden" name="action" value="acme_fe_update_phone">
                        <input type="hidden" name="user_id" value="<?php echo (int) $target_id; ?>">
                        <?php wp_nonce_field('acme_fe_phone_' . (int) $target_id); ?>

                        <input
                            type="text"
                            name="phone"
                            value="<?php echo esc_attr($phone); ?>"
                            placeholder="+5511999999999"
                            class="acme-inp"
                            style="flex:1;min-width:220px;font-size:12px">

                        <button type="submit" class="acme-btn">Salvar</button>
                    </form>
                </div>

                <div class="acme-field">
                    <label class="acme-muted" style="display:block;margin-bottom:6px;">Alterar senha</label>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <input type="hidden" name="action" value="acme_fe_set_password">
                        <input type="hidden" name="user_id" value="<?php echo (int) $target_id; ?>">
                        <?php wp_nonce_field('acme_fe_pass_' . (int) $target_id); ?>

                        <input
                            type="password"
                            name="new_pass"
                            placeholder="Nova senha (mín. 8)"
                            class="acme-inp"
                            style="flex:1;min-width:220px;font-size:12px">

                        <button type="submit" class="acme-btn">Salvar</button>
                    </form>

                    <div class="acme-muted" style="margin-top:8px;">A sessão do usuário será encerrada.</div>
                </div>

                <div class="acme-field">
                    <label class="acme-muted" style="display:block;margin-bottom:6px;">Situação</label>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <input type="hidden" name="action" value="acme_fe_toggle_status">
                        <input type="hidden" name="user_id" value="<?php echo (int) $target_id; ?>">
                        <input type="hidden" name="do" value="<?php echo esc_attr($actionStatus); ?>">
                        <input
                            type="hidden"
                            name="redirect_to"
                            value="<?php echo esc_url(get_permalink() . '?user_id=' . (int) $target_id); ?>">
                        <?php wp_nonce_field('acme_fe_toggle_' . (int) $target_id); ?>

                        <button type="submit" class="acme-btn" style="<?php echo esc_attr($statusButtonStyle); ?>">
                            <?php echo esc_html($statusButtonLabel); ?>
                        </button>
                    </form>

                    <?php if ($st !== 'inactive'): ?>
                        <div class="acme-muted" style="margin-top:8px;">
                            Se este usuário for <strong><?php echo esc_html(acme_role_label('child')); ?></strong>,
                            a inativação derruba os <strong><?php echo esc_html(acme_role_label('grandchild')); ?></strong> em cascata.
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

<?php
    return ob_get_clean();
};


/**
 * ============================================================
 * 12) SHORTCODE Visualizar Usuarios (Elementor): [acme_view_user]
 * ============================================================
 */

function acme_shortcode_view_user()
{
    if (!is_user_logged_in())
        return '<p>Você precisa estar logado.</p>';

    $actor_id = get_current_user_id();
    $actor = wp_get_current_user();

    $is_admin = user_can($actor_id, 'administrator');
    $is_child = in_array('child', (array) $actor->roles, true);

    if (!$is_admin && !$is_child)
        return '<p>Sem permissão.</p>';

    $target_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
    $nonce = isset($_GET['nonce']) ? (string) $_GET['nonce'] : '';

    $messageCode = isset($_GET['acme_msg'])
        ? sanitize_text_field(wp_unslash($_GET['acme_msg']))
        : '';

    $errorMessage = isset($_GET['acme_err'])
        ? sanitize_text_field(wp_unslash($_GET['acme_err']))
        : '';

    // Proteções
    if ($target_id === acme_master_admin_id() || user_can($target_id, 'administrator')) {
        return '<p>Este usuário não pode ser visualizado aqui.</p>';
    }

    // Filho só pode visualizar os próprios netos
    if (!$is_admin) {
        global $wpdb;
        $links = acme_table_links();
        $ok = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$links} WHERE parent_user_id=%d AND child_user_id=%d AND depth=2 LIMIT 1",
            $actor_id,
            $target_id
        ));
        if (!$ok)
            return '<p>Sem permissão para visualizar este usuário.</p>';
    }

    $u = get_user_by('id', $target_id);
    /*if (!$u)
        return '<p>Usuário não encontrado.</p>';*/

    // Dados extras
    $phone = get_user_meta($target_id, 'phone', true);

    // Status (account_status)
    global $wpdb;
    $statusT = acme_table_status();
    $st = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$statusT} WHERE user_id=%d", $target_id));
    $st = $st ?: 'active';

    // =========================
    // Créditos ativos (saldo disponível) via LOTES
    // Regra: soma (credits_total - credits_used) apenas lotes não expirados
    // =========================
    $lotsT = function_exists('acme_table_credit_lots')
        ? acme_table_credit_lots()
        : ($wpdb->prefix . 'credit_lots');

    $now_mysql = current_time('mysql');

    $credits_active = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(GREATEST(credits_total - credits_used, 0)), 0)
     FROM {$lotsT}
     WHERE owner_user_id = %d
       AND (expires_at IS NULL OR expires_at >= %s)",
        $target_id,
        $now_mysql
    ));


    // Roles (apenas pra exibir)
    $roles = $u->roles ? implode(', ', (array) $u->roles) : '-';

    // =========================
    // Perfil (Front-end)
    // - child => Master
    // - grandchild => Sub-Login
    // =========================
    $is_target_child = in_array('child', (array) $u->roles, true);
    $is_target_grandchild = in_array('grandchild', (array) $u->roles, true);

    $profile_front_label = $is_target_child
        ? 'Master'
        : ($is_target_grandchild ? 'Sub-Login' : $roles);

    // Links de navegação
    $edit_page = site_url('/edit-user/');  // ajuste se precisar
    $edit_url = add_query_arg([
        'user_id' => $target_id,
        'nonce' => $nonce, // reaproveita o mesmo nonce
    ], $edit_page);

    $back_url = wp_get_referer() ?: site_url('/');

    ob_start(); ?>

    <?php if ($messageCode === 'ok'): ?>
        <div style="max-width:1100px;margin:0 auto 12px auto;padding:10px 12px;border-radius:12px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:900;">
            Status atualizado.
        </div>
    <?php elseif ($messageCode === 'err_master'): ?>
        <div style="max-width:1100px;margin:0 auto 12px auto;padding:10px 12px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-weight:900;">
            <?php echo esc_html($errorMessage ?: 'Não é possível ativar este usuário porque o Master está inativo.'); ?>
        </div>
    <?php endif; ?>


    <div style="max-width:1100px;margin:0 auto;padding:12px 16px 24px;">

        <div style="background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:18px;padding:18px;margin-bottom:14px;">

            <!-- HEADER: título + botões alinhados -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                <h3 style="margin:0;font-size:16px;font-weight:700;line-height:1.2;">Dados do usuário</h3>

                <div style="margin-left:auto;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <a href="<?php echo esc_url(site_url('/usuarios/')); ?>"
                        style="border:1px solid rgba(15,23,42,.10);background:#fff;border-radius:14px;padding:10px 14px;font-weight:700;font-size:14px;white-space:nowrap;text-decoration:none;color:#0f172a;">
                        ← Voltar
                    </a>

                    <a href="<?php echo esc_url($edit_url); ?>"
                        style="border:1px solid rgba(15,23,42,.10);background:rgba(15,23,42,.04);border-radius:14px;padding:10px 14px;font-weight:700;font-size:14px;white-space:nowrap;text-decoration:none;color:#0f172a;">
                        Editar
                    </a>
                </div>
            </div>

            <!-- CONTEÚDO -->
            <table style="width:100%;border-collapse:collapse">
                <tbody>
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #eee;width:220px"><strong>ID</strong></td>
                        <td style="padding:10px;border-bottom:1px solid #eee"><?php echo (int) $u->ID; ?></td>
                    </tr>
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #eee"><strong>Nome</strong></td>
                        <td style="padding:10px;border-bottom:1px solid #eee"><?php echo esc_html($u->display_name); ?></td>
                    </tr>
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #eee"><strong>Usuário</strong></td>
                        <td style="padding:10px;border-bottom:1px solid #eee"><?php echo esc_html($u->user_login); ?></td>
                    </tr>
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #eee"><strong>E-mail</strong></td>
                        <td style="padding:10px;border-bottom:1px solid #eee"><?php echo esc_html($u->user_email); ?></td>
                    </tr>
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #eee"><strong>Telefone</strong></td>
                        <td style="padding:10px;border-bottom:1px solid #eee"><?php echo $phone ? esc_html($phone) : '—'; ?></td>
                    </tr>
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #eee"><strong>Perfil</strong></td>
                        <td style="padding:10px;border-bottom:1px solid #eee"><?php echo esc_html($profile_front_label); ?></td>
                    </tr>
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #eee"><strong>Situação</strong></td>
                        <td style="padding:10px;border-bottom:1px solid #eee">
                            <?php echo ($st === 'inactive')
                                ? '<span style="color:#b00020;font-weight:700">Inativo</span>'
                                : '<span style="color:#0a7a2f;font-weight:700">Ativo</span>'; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #eee"><strong>Créditos ativos</strong></td>
                        <td style="padding:10px;border-bottom:1px solid #eee"><?php echo (int) $credits_active; ?></td>
                    </tr>
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #eee"><strong>Registrado em</strong></td>
                        <td style="padding:10px;border-bottom:1px solid #eee;">
                            <?php echo $u->user_registered ? esc_html(date_i18n('d/m/Y H:i:s', strtotime($u->user_registered))) : ''; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p style="margin:12px 0 0 0;opacity:.75;">
                * Para editar telefone/senha/status, use o botão <strong>Editar</strong>.
            </p>

        </div>
    </div>

<?php
    return ob_get_clean();
};

/**
 * ============================================================
 * 13) SHORTCODE Adicionar Usuarios (Elementor): [acme_add_user]
 * ============================================================
 */
function acme_shortcode_add_user()
{

    if (!is_user_logged_in()) {
        return '<div style="padding:12px;border:1px solid #f2c;border-radius:10px;">Você precisa estar logado.</div>';
    }

    $actor_id = get_current_user_id();
    $actor    = wp_get_current_user();
    $is_admin = user_can($actor_id, 'administrator');
    $is_child = in_array('child', (array) $actor->roles, true);

    if (!$is_admin && !$is_child) {
        return '<div class="acme-panel" style="padding:14px 16px;">
      <div class="acme-panel-title">Acesso restrito</div>
      <div class="acme-panel-sub">Ops! Este conteúdo é restrito. Para continuar, fale com o administrador ou solicite acesso.</div>
    </div>';
    }

    $allowed_roles = $is_admin ? ['grandchild', 'child'] : ['grandchild'];

    //$children      = $is_admin ? get_users(['role' => 'child']) : [];
    $children = [];

    if ($is_admin) {
        $allChildren = get_users([
            'role'    => 'child',
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'number'  => 500,
        ]);

        $children = array_values(array_filter($allChildren, static function ($childUser) {
            return $childUser instanceof WP_User
                && acme_account_is_active((int) $childUser->ID);
        }));
    }

    $back_url      = wp_get_referer() ?: site_url('/');
    $home_url = home_url('/');

    ob_start();

    echo function_exists('acme_ui_panel_css') ? acme_ui_panel_css() : '';

    // Layout em coluna (um abaixo do outro), mantendo o design do painel
?>
    <div class="acme-panel" style="max-width:auto;margin:0 auto ; ">

        <div class="acme-panel-h">
            <div>
                <div class="acme-panel-title">Adicionar um novo usuário</div>
                <div class="acme-panel-sub">Crie Master (Filho) ou Sub-login (Neto) conforme seu nível de acesso.</div>
            </div>

            <div class="acme-form-actions">
                <a class="acme-btn" style="background:#fff;color:#0f172a;border:1px solid #e2e8f0;"
                    href="<?php echo esc_url($home_url); ?>">← Voltar</a>
            </div>
        </div>

        <?php if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'created'): ?>
            <div style="padding:12px 16px;border-top:1px solid #eef2f7;color:#166534;font-weight:700;">
                Usuário criado com sucesso.
            </div>
        <?php elseif (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'error'): ?>
            <div style="padding:12px 16px;border-top:1px solid #eef2f7;color:#b00020;font-weight:700;">
                Erro ao criar usuário. Verifique os dados.
            </div>
        <?php elseif (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'parent_inactive'): ?>
            <div style="padding:12px 16px;border-top:1px solid #eef2f7;color:#991b1b;font-weight:700;">
                Não é permitido criar Sub-Login para Master inativo.
            </div>
        <?php elseif (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'missing_parent'): ?>
            <div style="padding:12px 16px;border-top:1px solid #eef2f7;color:#991b1b;font-weight:700;">
                Selecione um Master responsável ativo para criar o Sub-Login.
            </div>
        <?php endif; ?>

        <form method="post" class="form-add-user"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            style="padding:12px 16px;border-top:1px solid #eef2f7;"
            class="acme-form-col">

            <input type="hidden" name="action" value="acme_fe_create_user">
            <?php wp_nonce_field('acme_fe_create_user'); ?>

            <div class="acme-field" style="margin-bottom:1%">
                <label class="acme-muted">Tipo de usuário</label>
                <select name="role" required class="acme-input">
                    <?php if (in_array('grandchild', $allowed_roles, true)): ?>
                        <option value="grandchild"><?php echo esc_html(acme_role_label('grandchild')); ?></option>
                    <?php endif; ?>
                    <?php if (in_array('child', $allowed_roles, true)): ?>
                        <option value="child"><?php echo esc_html(acme_role_label('child')); ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <?php if ($is_admin): ?>
                <div class="acme-field" style="margin-bottom:1%">
                    <label class="acme-muted">Master responsável (somente se for Sub-Login)</label>
                    <select name="parent_child_id" class="acme-input">
                        <option value="">Selecione (obrigatório para Neto)</option>
                        <?php foreach ($children as $c): ?>
                            <option value="<?php echo (int) $c->ID; ?>">
                                <?php echo esc_html($c->display_name); ?> (#<?php echo (int) $c->ID; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="acme-muted" style="margin-top:6px;opacity:.85;">
                        Se você criar Sub-login, precisa escolher qual Master ele pertence.
                    </div>
                </div>
            <?php endif; ?>

            <div class="acme-field" style="margin-bottom:1%">
                <label class="acme-muted">Nome</label>
                <input type="text" name="display_name" required class="acme-input">
            </div>

            <div class="acme-field" style="margin-bottom:1%">
                <label class="acme-muted">E-mail</label>
                <input type="email" name="email" required class="acme-input">
            </div>

            <div class="acme-field" style="margin-bottom:1%">
                <label class="acme-muted">Telefone</label>
                <input type="text" name="phone" placeholder="+5511999999999" class="acme-input">
            </div>

            <div class="acme-field" style="margin-bottom:1%">
                <label class="acme-muted">Senha</label>
                <input type="password" name="password" required minlength="8" class="acme-input">
                <div class="acme-muted" style="margin-top:6px;opacity:.85;">Mínimo 8 caracteres.</div>
            </div>

            <button type="submit" class="acme-btn" style="width:100%;">Criar usuário</button>

        </form>

    </div>
<?php

    return ob_get_clean();
};

/**
 * ============================================================
 * 14) Handler que cria usuário + grava vínculos
 * ============================================================
 */


function acme_fe_create_user()
{

    if (!is_user_logged_in())
        wp_die('Você precisa estar logado.');
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'acme_fe_create_user')) {
        wp_die('Nonce inválido.');
    }

    $actor_id = get_current_user_id();
    $actor = wp_get_current_user();
    $is_admin = user_can($actor_id, 'administrator');
    $is_child = in_array('child', (array) $actor->roles, true);

    if (!$is_admin && !$is_child)
        wp_die('Sem permissão.');

    $role = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : '';
    $display_name = isset($_POST['display_name']) ? sanitize_text_field($_POST['display_name']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $phone = isset($_POST['phone']) ? acme_sanitize_phone((string) $_POST['phone']) : '';

    // regras por perfil
    if ($is_child && $role !== 'grandchild') {
        wp_safe_redirect(add_query_arg('acme_msg', 'error', wp_get_referer() ?: site_url('/add-user/')));
        exit;
    }
    if ($is_admin && !in_array($role, ['child', 'grandchild'], true)) {
        wp_safe_redirect(add_query_arg('acme_msg', 'error', wp_get_referer() ?: site_url('/add-user/')));
        exit;
    }

    if (!$display_name || !$email || strlen(trim($password)) < 8) {
        wp_safe_redirect(add_query_arg('acme_msg', 'error', wp_get_referer() ?: site_url('/add-user/')));
        exit;
    }

    // Bloqueio: não permitir Sub-Login vinculado a Master inativo
    if ($role === 'grandchild') {
        $parentChildId = 0;

        if ($is_admin) {
            $parentChildId = isset($_POST['parent_child_id']) ? (int) $_POST['parent_child_id'] : 0;

            if ($parentChildId <= 0) {
                wp_safe_redirect(add_query_arg('acme_msg', 'missing_parent', wp_get_referer() ?: site_url('/add-user/')));
                exit;
            }

            $parentChildUser = get_user_by('id', $parentChildId);
            if (
                !$parentChildUser ||
                !acme_user_has_role($parentChildUser, 'child') ||
                !acme_account_is_active($parentChildId)
            ) {
                wp_safe_redirect(add_query_arg('acme_msg', 'parent_inactive', wp_get_referer() ?: site_url('/add-user/')));
                exit;
            }
        } else {
            // Filho logado criando Sub-Login: ele próprio precisa estar ativo
            if (!acme_account_is_active($actor_id)) {
                wp_safe_redirect(add_query_arg('acme_msg', 'parent_inactive', wp_get_referer() ?: site_url('/add-user/')));
                exit;
            }
        }
    }


    // username simples baseado no email
    $base_login = sanitize_user(current(explode('@', $email)), true);
    if (!$base_login)
        $base_login = 'user';

    $user_login = $base_login;
    $i = 1;
    while (username_exists($user_login)) {
        $user_login = $base_login . $i;
        $i++;
    }


    $user_id = wp_create_user($user_login, $password, $email);
    if (is_wp_error($user_id)) {
        wp_safe_redirect(add_query_arg('acme_msg', 'error', wp_get_referer() ?: site_url('/add-user/')));
        exit;
    }

    // seta role e nome
    wp_update_user([
        'ID' => $user_id,
        'display_name' => $display_name,
        'role' => $role,
    ]);

    if ($phone)
        update_user_meta($user_id, 'phone', $phone);

    // vínculos
    global $wpdb;
    $links = acme_table_links();

    $admin_master = acme_master_admin_id();

    if ($role === 'child') {
        // Admin criou Filho -> vincula no Admin (depth=1)
        $wpdb->insert($links, [
            'parent_user_id' => $admin_master,
            'child_user_id' => $user_id,
            'depth' => 1,
        ]);
    }

    if ($role === 'grandchild') {
        // Neto: precisa de filho responsável
        $parent_child_id = 0;

        if ($is_admin) {
            $parent_child_id = isset($_POST['parent_child_id']) ? (int) $_POST['parent_child_id'] : 0;
            if ($parent_child_id <= 0) {
                // Neto criado por admin precisa escolher filho
                wp_delete_user($user_id);
                wp_safe_redirect(add_query_arg('acme_msg', 'error', wp_get_referer() ?: site_url('/add-user/')));
                exit;
            }
        } else {
            // Filho criando neto: vincula direto no filho logado
            $parent_child_id = $actor_id;
        }

        $wpdb->insert($links, [
            'parent_user_id' => $parent_child_id,
            'child_user_id' => $user_id,
            'depth' => 2,
        ]);
    }

    wp_safe_redirect(add_query_arg('acme_msg', 'created', site_url('/add-user/')));
    exit;
}





/**
 * ============================================================
 * 11) SHORTCODE Editar Usuarios (Elementor): [acme_edit_user]
 * ============================================================
 */


function acme_shortcode_view_user_fixed()
{
    if (!is_user_logged_in())
        return '<p>Você precisa estar logado.</p>';

    $actor_id = get_current_user_id();
    $actor = wp_get_current_user();
    $is_admin = user_can($actor_id, 'administrator');
    $is_child = in_array('child', (array) $actor->roles, true);

    if (!$is_admin && !$is_child)
        return '<p>Sem permissão.</p>';

    $target_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
    $nonce = isset($_GET['nonce']) ? (string) $_GET['nonce'] : '';

    /*if (!$target_id || !$nonce)
        return '<p>Usuário inválido.</p>';*/

    /*if (!wp_verify_nonce($nonce, 'acme_edit_user_' . $target_id))
        return '<p>Link inválido/expirado.</p>';*/

    // Proteções
    if ($target_id === acme_master_admin_id() || user_can($target_id, 'administrator')) {
        return '<p>Este usuário não pode ser editado aqui.</p>';
    }

    // Filho só edita netos dele
    if (!$is_admin) {
        global $wpdb;
        $links = acme_table_links();
        $ok = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$links} WHERE parent_user_id=%d AND child_user_id=%d AND depth=2 LIMIT 1",
            $actor_id,
            $target_id
        ));
        if (!$ok)
            return '<p>Sem permissão para editar este usuário.</p>';
    }

    $u = get_user_by('id', $target_id);
    /*if (!$u)
        return '<p>Usuário não encontrado.</p>';*/

    $phone = get_user_meta($target_id, 'phone', true);

    // status atual
    global $wpdb;
    $statusT = acme_table_status();
    $st = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$statusT} WHERE user_id=%d", $target_id));
    $st = $st ?: 'active';

    $back_url = remove_query_arg(['acme_msg'], wp_get_referer() ?: site_url('/'));

    ob_start(); ?>

    <div style="max-width:auto;margin:0 auto">
        <div
            style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
            <!--<h2 style="margin:10px;">Editar usuário</h2>-->
            <!--<a href="<?php #echo esc_url($back_url); 
                            ?>" style="margin-left:auto;text-decoration:none">← Voltar</a>-->
        </div>

        <!--<?php #if (isset($_GET['acme_msg']) && $_GET['acme_msg'] === 'ok'): 
            ?>
            <p style="color:green;font-weight:600">Atualizado com sucesso.</p>
        <?php #endif; 
        ?>-->

        <!--<div style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px; ">
            <p style="margin:0 0 6px 0"><strong>Nome:</strong> <?php #echo esc_html($u->display_name); 
                                                                ?></p>
            <p style="margin:0 0 6px 0"><strong>E-mail:</strong> <?php #echo esc_html($u->user_email); 
                                                                    ?></p>
            <p style="margin:0"><strong>Status:</strong>
                <?php #echo $st === 'inactive'
                #? '<span style="color:#b00020;font-weight:700">Inativo</span>'
                #: '<span style="color:#0a7a2f;font-weight:700">Ativo</span>'; 
                ?>
            </p>
        </div>-->
        <div style="border:1px solid #e5e7eb;
            border-radius:12px;
            padding:16px;
            margin-bottom:14px;
            display:flex;
            justify-content:center;
            align-items:center;
            gap:20px;">

            <p style="margin:0">
                <strong>Nome:</strong> <?php echo esc_html($u->display_name); ?>
            </p>

            <p style="margin:0">
                <strong>E-mail:</strong> <?php echo esc_html($u->user_email); ?>
            </p>

            <p style="margin:0">
                <strong>Status:</strong>
                <?php echo $st === 'inactive'
                    ? '<span style="color:#b00020;font-weight:700">Inativo</span>'
                    : '<span style="color:#0a7a2f;font-weight:700">Ativo</span>'; ?>
            </p>
        </div>


    </div>

<?php
    return ob_get_clean();
};


#==========================
#usuario atual
function acme_shortcode_view_user_atual()
{
    if (!is_user_logged_in())
        return '<p>Você precisa estar logado.</p>';

    $target_id = get_current_user_id();
    $u = get_user_by('id', $target_id);
    /*if (!$u)
        return '<p>Usuário não encontrado.</p>';*/

    // 🔒 Proteção opcional (mantida)
    /*if ($target_id === acme_master_admin_id() || user_can($target_id, 'administrator')) {
      return '<p>Este usuário não pode ser visualizado aqui.</p>';
    }*/

    global $wpdb;

    // =========================
    // UPDATE TELEFONE (inline)
    // =========================
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acme_update_phone'])) {

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, 'acme_update_phone_' . $target_id)) {
            $err = 'Sessão expirada. Recarregue a página e tente novamente.';
        } else {
            $new_phone_raw = isset($_POST['phone']) ? (string) $_POST['phone'] : '';
            $new_phone_raw = wp_unslash($new_phone_raw);

            // sanitização simples (permite +, espaço, parênteses, hífen)
            $new_phone = preg_replace('/[^0-9\+\-\(\)\s]/', '', $new_phone_raw);
            $new_phone = trim($new_phone);

            // validação básica (opcional)
            if ($new_phone !== '' && strlen(preg_replace('/\D+/', '', $new_phone)) < 8) {
                $err = 'Telefone inválido.';
            } else {
                update_user_meta($target_id, 'phone', $new_phone);
                $msg = 'Telefone atualizado com sucesso.';
            }
        }
    }

    // Dados extras (carrega depois do update)
    $phone = get_user_meta($target_id, 'phone', true);

    // Status (account_status)
    $statusT = acme_table_status();
    $st = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$statusT} WHERE user_id=%d", $target_id));
    $st = $st ?: 'active';

    // Créditos ativos (saldo disponível) via LOTES
    $lotsT = function_exists('acme_table_credit_lots')
        ? acme_table_credit_lots()
        : ($wpdb->prefix . 'credit_lots');

    $now_mysql = current_time('mysql');

    $credits_active = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(GREATEST(credits_total - credits_used, 0)), 0)
     FROM {$lotsT}
     WHERE owner_user_id = %d
       AND (expires_at IS NULL OR expires_at >= %s)",
        $target_id,
        $now_mysql
    ));

    // Roles (apenas pra exibir)
    $roles = $u->roles ? implode(', ', (array) $u->roles) : '-';

    // Perfil (Front-end)
    $is_target_child = in_array('child', (array) $u->roles, true);
    $is_target_grandchild = in_array('grandchild', (array) $u->roles, true);

    $profile_front_label = $is_target_child
        ? 'Master'
        : ($is_target_grandchild ? 'Sub-Login' : $roles);

    ob_start(); ?>

    <div style="max-width:820px;margin:0 auto">
        <div
            style="margin-right: auto;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px">
            <!--<a href="<?php #echo site_url('/usuarios'); 
                            ?>" style="margin-left: auto;text-decoration:none;">← Voltar</a>-->
        </div>
    </div>

    <div style="border:1px solid #e5e7eb;border-radius:12px;padding:16px;background:#fff">
        <h3 style="margin-top:0">Meus dados</h3>

        <?php if ($msg): ?>
            <div
                style="margin:10px 0;padding:10px 12px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:10px;color:#166534;font-weight:600">
                <?php echo esc_html($msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($err): ?>
            <div
                style="margin:10px 0;padding:10px 12px;border:1px solid #fecaca;background:#fff1f2;border-radius:10px;color:#9f1239;font-weight:600">
                <?php echo esc_html($err); ?>
            </div>
        <?php endif; ?>

        <table style="width:100%;border-collapse:collapse">
            <tbody>
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #eee;width:220px"><strong>ID</strong></td>
                    <td style="padding:10px;border-bottom:1px solid #eee"><?php echo (int) $u->ID; ?></td>
                </tr>
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #eee"><strong>Nome</strong></td>
                    <td style="padding:10px;border-bottom:1px solid #eee"><?php echo esc_html($u->display_name); ?></td>
                </tr>
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #eee"><strong>Usuário</strong></td>
                    <td style="padding:10px;border-bottom:1px solid #eee"><?php echo esc_html($u->user_login); ?></td>
                </tr>
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #eee"><strong>E-mail</strong></td>
                    <td style="padding:10px;border-bottom:1px solid #eee"><?php echo esc_html($u->user_email); ?></td>
                </tr>

                <!-- ✅ TELEFONE EDITÁVEL -->
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #eee"><strong>Telefone</strong></td>
                    <td style="padding:10px;border-bottom:1px solid #eee">
                        <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0; ">
                            <?php wp_nonce_field('acme_update_phone_' . $target_id); ?>
                            <input type="hidden" name="acme_update_phone" value="1" />
                            <input type="text" name="phone" value="<?php echo esc_attr($phone ?: ''); ?>"
                                placeholder="Ex: (11) 99999-9999"
                                style="width:260px;max-width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px; font-size: 12px" />
                            <button type="submit"
                                style="padding:10px 14px;border-radius:10px;border:1px solid #e5e7eb;background:#111827;color:#fff;cursor:pointer;font-weight:700;font-size: 12px">
                                Atualizar
                            </button>

                        </form>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10px;border-bottom:1px solid #eee"><strong>Perfil</strong></td>
                    <td style="padding:10px;border-bottom:1px solid #eee"><?php echo esc_html($profile_front_label); ?></td>
                </tr>
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #eee"><strong>Situação</strong></td>
                    <td style="padding:10px;border-bottom:1px solid #eee">
                        <?php echo ($st === 'inactive')
                            ? '<span style="color:#b00020;font-weight:700">Inativo</span>'
                            : '<span style="color:#0a7a2f;font-weight:700">Ativo</span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #eee"><strong>Créditos ativos</strong></td>
                    <td style="padding:10px;border-bottom:1px solid #eee"><?php echo (int) $credits_active; ?></td>
                </tr>
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #eee"><strong>Registrado em</strong></td>
                    <td style="padding:10px;border-bottom:1px solid #eee;">
                        <?php echo esc_html($u->user_registered) ? date_i18n('d/m/Y H:i:s', strtotime(esc_html($u->user_registered))) : ''; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <p style="margin:12px 0 0 0;opacity:.75;">
            * Nesta tela você pode editar apenas o <strong>telefone</strong>.
        </p>
    </div>

    <?php
    return ob_get_clean();
};






/**
 * Edit perfil - shortcode consolidado
 */


if (!function_exists('acme_register_edit_user_page_assets')) {
    function acme_register_edit_user_page_assets()
    {
        $cssRelativePath = 'assets/css/acme-my-profile-page.css';
        $cssFilePath = ACME_ACC_PATH . $cssRelativePath;
        $cssFileUrl  = ACME_ACC_URL . $cssRelativePath;
        $cssVersion  = file_exists($cssFilePath) ? (string) filemtime($cssFilePath) : '1.0.0';

        wp_register_style(
            'acme-edit-user-page',
            $cssFileUrl,
            [],
            $cssVersion
        );

        $jsRelativePath = 'assets/js/acme-shortcode-layout.js';
        $jsFilePath = ACME_ACC_PATH . $jsRelativePath;
        $jsFileUrl  = ACME_ACC_URL . $jsRelativePath;
        $jsVersion  = file_exists($jsFilePath) ? (string) filemtime($jsFilePath) : '1.0.0';

        wp_register_script(
            'acme-shortcode-layout',
            $jsFileUrl,
            [],
            $jsVersion,
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'acme_register_edit_user_page_assets');

if (!function_exists('acme_enqueue_edit_user_page_assets')) {
    function acme_enqueue_edit_user_page_assets()
    {
        wp_enqueue_style('acme-edit-user-page');
        wp_enqueue_script('acme-shortcode-layout');
    }
}

#=========================

if (!function_exists('acme_shortcode_edit_user_page')) {
    function acme_shortcode_edit_user_page()
    {
        if (!is_user_logged_in()) {
            return '<p>Você precisa estar logado.</p>';
        }

        $actorId = get_current_user_id();
        $actor = wp_get_current_user();
        $isAdmin = user_can($actorId, 'administrator');
        $isChild = in_array('child', (array) $actor->roles, true);

        if (!$isAdmin && !$isChild) {
            return '<p>Sem permissão.</p>';
        }

        $targetId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        if ($targetId <= 0) {
            return '<p>Usuário inválido.</p>';
        }

        if ($targetId === acme_master_admin_id() || user_can($targetId, 'administrator')) {
            return '<p>Este usuário não pode ser editado aqui.</p>';
        }

        if (!$isAdmin) {
            global $wpdb;
            $linksTable = acme_table_links();

            $hasPermission = $wpdb->get_var($wpdb->prepare(
                "SELECT id
         FROM {$linksTable}
         WHERE parent_user_id = %d
           AND child_user_id = %d
           AND depth = 2
         LIMIT 1",
                $actorId,
                $targetId
            ));

            if (!$hasPermission) {
                return '<p>Sem permissão para editar este usuário.</p>';
            }
        }

        acme_enqueue_edit_user_page_assets();

        $targetUser = get_user_by('id', $targetId);
        if (!$targetUser) {
            return '<p>Usuário não encontrado.</p>';
        }

        $editUserHtml = do_shortcode('[acme_edit_user]');
        $grantCreditsHtml   = do_shortcode('[acme_grant_credits]');
        $recoverCreditsHtml = do_shortcode('[acme_recover_credits_form]');
        ob_start();
    ?>
        <div class="acme-my-profile-page acme-edit-user-page">

            <section class="acme-profile-section">
                <div class="acme-profile-card">
                    <?php echo $editUserHtml; ?>
                </div>
            </section>

            <section class="acme-profile-section">
                <div class="acme-profile-card">

                    <div class="acme-credit-toggle" data-acme-credit-toggle>
                        <button
                            type="button"
                            class="acme-credit-toggle__button is-active"
                            data-acme-credit-target="grant">
                            Conceder
                        </button>

                        <button
                            type="button"
                            class="acme-credit-toggle__button"
                            data-acme-credit-target="recover">
                            Estornar
                        </button>
                    </div>

                    <div
                        class="acme-credit-toggle__panel is-active"
                        data-acme-credit-panel="grant">
                        <?php echo $grantCreditsHtml; ?>
                    </div>

                    <div
                        class="acme-credit-toggle__panel"
                        data-acme-credit-panel="recover"
                        hidden>
                        <?php echo $recoverCreditsHtml; ?>
                    </div>

                </div>
            </section>

        </div>

    <?php
        return ob_get_clean();
    }
}

/**
 * Shortcode dedicado para Créditos (Conceder / Estornar)
 * Uso: [acme_edit_user_credits_page]
 */

if (!function_exists('acme_shortcode_edit_user_credits_page')) {
    function acme_shortcode_edit_user_credits_page()
    {
        if (!is_user_logged_in()) {
            return '<p>Você precisa estar logado.</p>';
        }

        $actorId = get_current_user_id();
        $actor = wp_get_current_user();

        $isAdmin = user_can($actorId, 'administrator');
        $isChild = in_array('child', (array) $actor->roles, true);

        if (!$isAdmin && !$isChild) {
            return '<p>Sem permissão.</p>';
        }

        // ✅ apenas carrega assets
        acme_enqueue_edit_user_page_assets();

        // ✅ shortcodes internos controlam usuário
        $grantCreditsHtml   = do_shortcode('[acme_grant_credits]');
        $recoverCreditsHtml = do_shortcode('[acme_recover_credits_form]');

        ob_start();
    ?>

        <div class="acme-my-profile-page acme-edit-user-credits-page">

            <section class="acme-profile-section">
                <div class="acme-profile-card">

                    <div class="acme-credit-toggle" data-acme-credit-toggle>
                        <button
                            type="button"
                            class="acme-credit-toggle__button is-active"
                            data-acme-credit-target="grant">
                            Conceder
                        </button>

                        <button
                            type="button"
                            class="acme-credit-toggle__button"
                            data-acme-credit-target="recover">
                            Estornar
                        </button>
                    </div>

                    <div
                        class="acme-credit-toggle__panel is-active"
                        data-acme-credit-panel="grant">

                        <?php echo $grantCreditsHtml; ?>

                    </div>

                    <div
                        class="acme-credit-toggle__panel"
                        data-acme-credit-panel="recover"
                        hidden>

                        <?php echo $recoverCreditsHtml; ?>

                    </div>

                </div>
            </section>

        </div>

<?php

        return ob_get_clean();
    }
}
