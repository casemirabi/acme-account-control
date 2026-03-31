<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('acme_users_register_shortcodes')) {
    function acme_users_register_shortcodes()
    {
        $shortcodes = [
            'acme_add_user'                => 'acme_shortcode_add_user',
            'acme_edit_user_credits_page' => 'acme_shortcode_edit_user_credits_page',
            'acme_edit_user'              => 'acme_shortcode_edit_user',
            'acme_edit_user_page'         => 'acme_shortcode_edit_user_page',
            'acme_my_grandchildren'       => 'acme_shortcode_my_grandchildren',
            'acme_my_grandchildren_manage'=> 'acme_shortcode_my_grandchildren_manage',
            'acme_view_user_atual'        => 'acme_shortcode_view_user_atual',
            'acme_view_user'              => 'acme_shortcode_view_user',
            'acme_view_user_fixed'        => 'acme_shortcode_view_user_fixed',
        ];

        foreach ($shortcodes as $tag => $callback) {
            if (shortcode_exists($tag)) {
                continue;
            }

            add_shortcode($tag, function ($atts = [], $content = null, $shortcodeTag = '') use ($callback, $tag) {
                if (!function_exists($callback)) {
                    return '<p>Erro interno (' . esc_html($tag) . ').</p>';
                }

                if ($tag === 'acme_my_grandchildren_manage') {
                    return call_user_func($callback, $atts);
                }

                return call_user_func($callback);
            });
        }
    }
}

add_action('init', 'acme_users_register_shortcodes');


if (!function_exists('acme_shortcode_my_grandchildren')) {
    function acme_shortcode_my_grandchildren($atts = [])
    {
        if (!is_user_logged_in()) {
            return '<p>Você precisa estar logado.</p>';
        }

        $uid = get_current_user_id();
        $me = wp_get_current_user();

        $is_admin = user_can($uid, 'administrator');
        $is_child = in_array('child', (array) $me->roles, true);

        if ($is_admin) {
            $rows = acme_users_repo_get_all_grandchildren_rows();
        } else {
            $rows = acme_users_repo_get_grandchildren_rows_by_parent($uid);
        }

        if (empty($rows)) {
            return '<p>Nenhum ' . esc_html(acme_role_label('grandchild')) . ' encontrado.</p>';
        }

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
    }
}


if (!function_exists('acme_shortcode_my_grandchildren_manage')) {
    function acme_shortcode_my_grandchildren_manage($atts = [])
    {
        if (!is_user_logged_in()) {
            return '<p>Você precisa estar logado.</p>';
        }

        $screenData = acme_users_manage_build_screen_data(get_current_user_id());

        $isAdmin = $screenData['isAdmin'];
        $isChild = $screenData['isChild'];

        if (!$isAdmin && !$isChild) {
            return '<p>Sem permissão.</p>';
        }

        $filters = $screenData['filters'];
        $q = $filters['q'];
        $filterMaster = $filters['filter_master'];
        $filterStatus = $filters['filter_status'];
        $filterCredits = $filters['filter_credits'];

        $rows = $screenData['rows'];
        $baseUrl = $screenData['baseUrl'];
        $clearFiltersUrl = $screenData['clearFiltersUrl'];
        $hasAnyFilter = $screenData['hasAnyFilter'];
        $messages = $screenData['messages'];
        $childrenForFilter = $screenData['childrenForFilter'];
        $scopeIds = $screenData['scopeIds'];

        ob_start();
        echo function_exists('acme_ui_panel_css') ? acme_ui_panel_css() : '';
        require dirname(__FILE__) . '/Views/manage-grandchildren.php';
        return ob_get_clean();
    }
}

function acme_enqueue_edit_user_shortcode_assets()
{
    // Registra, mas NÃO carrega ainda
    wp_register_style(
        'acme-shortcode-edit-user',
        ACME_ACC_URL . 'assets/css/shortcode-acme-edit-user.css',
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
