<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('show_user_profile', 'acme_parent_field');
add_action('edit_user_profile', 'acme_parent_field');
add_action('user_new_form', 'acme_parent_field');

function acme_parent_field($user)
{
    $is_new_user_screen = is_string($user);
    $roles = $is_new_user_screen ? [] : (array) $user->roles;

    if (!$is_new_user_screen && !in_array('grandchild', $roles, true)) {
        return;
    }

    $children = acme_users_repo_get_children_for_filter();

    $current = null;
    if (!$is_new_user_screen) {
        $current = acme_users_repo_get_parent_for_grandchild((int) $user->ID);
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

add_filter('user_profile_update_errors', 'acme_validate_parent_on_create', 10, 3);

function acme_validate_parent_on_create($errors, $update, $user)
{
    if ($update) {
        return;
    }

    if (empty($_POST['role']) || $_POST['role'] !== 'grandchild') {
        return;
    }

    if (empty($_POST['acme_parent_child'])) {
        $errors->add('missing_parent', 'Sub-Login precisa obrigatoriamente ter um Master.');
    }
}

add_action('show_user_profile', 'acme_admin_phone_field');
add_action('edit_user_profile', 'acme_admin_phone_field');

function acme_admin_phone_field($user)
{
    $phone = acme_users_repo_get_phone((int) $user->ID);
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
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }

    if (!isset($_POST['acme_phone'])) {
        return;
    }

    $phone = acme_sanitize_phone((string) $_POST['acme_phone']);
    acme_users_repo_set_phone((int) $user_id, $phone);
}

if (!function_exists('acme_users_admin_add_phone_column')) {
    function acme_users_admin_add_phone_column($cols)
    {
        $cols['acme_phone'] = 'Telefone';
        return $cols;
    }
}
add_filter('manage_users_columns', 'acme_users_admin_add_phone_column');

if (!function_exists('acme_users_admin_render_phone_column')) {
    function acme_users_admin_render_phone_column($val, $column, $user_id)
    {
        if ($column !== 'acme_phone') {
            return $val;
        }

        $phone = acme_users_repo_get_phone((int) $user_id);
        return $phone ? esc_html($phone) : '—';
    }
}
add_filter('manage_users_custom_column', 'acme_users_admin_render_phone_column', 10, 3);

if (!function_exists('acme_users_admin_phone_field_on_create')) {
    function acme_users_admin_phone_field_on_create($context)
    {
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
    }
}
add_action('user_new_form', 'acme_users_admin_phone_field_on_create');

if (!function_exists('acme_users_admin_save_phone_on_register')) {
    function acme_users_admin_save_phone_on_register($user_id)
    {
        if (!isset($_POST['acme_phone'])) {
            return;
        }

        $phone = acme_sanitize_phone((string) $_POST['acme_phone']);
        if ($phone !== '') {
            acme_users_repo_set_phone((int) $user_id, $phone);
        }
    }
}
add_action('user_register', 'acme_users_admin_save_phone_on_register');

add_action('user_register', 'acme_save_parent');
add_action('personal_options_update', 'acme_save_parent');
add_action('edit_user_profile_update', 'acme_save_parent');

function acme_save_parent($user_id)
{
    $user = get_user_by('id', $user_id);

    if (!$user) {
        if (empty($_POST['role']) || $_POST['role'] !== 'grandchild') {
            return;
        }
    } else {
        if (!acme_user_has_role($user, 'grandchild')) {
            return;
        }
    }

    if (empty($_POST['acme_parent_child'])) {
        wp_die('Sub-Login precisa obrigatoriamente ter um Master.');
    }

    $parent = (int) $_POST['acme_parent_child'];
    acme_users_repo_replace_grandchild_parent((int) $user_id, $parent);
}
