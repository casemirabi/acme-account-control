<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================================================
 * CRÉDITOS (ADMIN): Cadastro de serviços e custo em créditos
 * - Apenas Admin (manage_options)
 * - CRUD em wp-admin
 * - Tabela: wp_services (prefix + services)
 * Campos: id, slug, name, credits_cost, is_active, created_at, updated_at
 * ============================================================
 */

if (!function_exists('acme_credits_table_services')) {
    function acme_credits_table_services(): string {
        global $wpdb;
        return $wpdb->prefix . 'services';
    }
}

if (!function_exists('acme_credits_admin_menu')) {
    add_action('admin_menu', 'acme_credits_admin_menu');

    function acme_credits_admin_menu() {
        if (!current_user_can('manage_options')) return;

        add_menu_page(
            'Créditos',
            'Créditos',
            'manage_options',
            'acme-credits',
            'acme_credits_services_page',
            'dashicons-tickets-alt',
            59
        );

        add_submenu_page(
            'acme-credits',
            'Serviços',
            'Serviços',
            'manage_options',
            'acme-credits',
            'acme_credits_services_page'
        );
    }
}

if (!function_exists('acme_credits_services_page')) {
    function acme_credits_services_page() {
        if (!current_user_can('manage_options')) wp_die('Sem permissão.');

        global $wpdb;
        $table = acme_credits_table_services();

        // Ações (POST)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('acme_credits_services_action');

            $action = isset($_POST['acme_action']) ? sanitize_text_field($_POST['acme_action']) : '';

            // CREATE
            if ($action === 'create') {
                $slug        = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '';
                $name        = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
                $credits     = isset($_POST['credits_cost']) ? (int) $_POST['credits_cost'] : 0;
                $is_active   = isset($_POST['is_active']) ? 1 : 0;

                if (!$slug || !$name) {
                    add_settings_error('acme_credits', 'invalid', 'Preencha slug e nome.', 'error');
                } elseif ($credits < 0) {
                    add_settings_error('acme_credits', 'invalid', 'Créditos não pode ser negativo.', 'error');
                } else {
                    $now = current_time('mysql');

                    $ok = $wpdb->insert($table, [
                        'slug'         => $slug,
                        'name'         => $name,
                        'credits_cost' => $credits,
                        'is_active'    => $is_active,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ]);

                    if (!$ok) {
                        add_settings_error('acme_credits', 'db', 'Erro ao salvar. Slug já pode existir.', 'error');
                    } else {
                        add_settings_error('acme_credits', 'ok', 'Serviço criado.', 'updated');
                    }
                }
            }

            // UPDATE
            if ($action === 'update') {
                $id        = isset($_POST['id']) ? (int) $_POST['id'] : 0;
                $name      = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
                $credits   = isset($_POST['credits_cost']) ? (int) $_POST['credits_cost'] : 0;
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                if ($id <= 0) {
                    add_settings_error('acme_credits', 'invalid', 'ID inválido.', 'error');
                } elseif ($credits < 0) {
                    add_settings_error('acme_credits', 'invalid', 'Créditos não pode ser negativo.', 'error');
                } else {
                    $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d", $id));
                    if (!$row) {
                        add_settings_error('acme_credits', 'nf', 'Serviço não encontrado.', 'error');
                    } else {
                        $ok = $wpdb->update($table, [
                            'name'         => $name,
                            'credits_cost' => $credits,
                            'is_active'    => $is_active,
                            'updated_at'   => current_time('mysql'),
                        ], ['id' => $id]);

                        if ($ok === false) {
                            add_settings_error('acme_credits', 'db', 'Erro ao atualizar.', 'error');
                        } else {
                            add_settings_error('acme_credits', 'ok', 'Serviço atualizado.', 'updated');
                        }
                    }
                }
            }

            // DELETE
            if ($action === 'delete') {
                $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
                if ($id > 0) {
                    $wpdb->delete($table, ['id' => $id]);
                    add_settings_error('acme_credits', 'ok', 'Serviço removido.', 'updated');
                }
            }
        }

        // Lista
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");

        settings_errors('acme_credits');
        ?>
        <div class="wrap">
            <h1>Créditos • Serviços</h1>
            <p>Cadastre os tipos de serviço e defina quantos créditos cada um custa (ex: CLT = 1).</p>

            <hr>

            <h2>Novo serviço</h2>
            <form method="post">
                <?php wp_nonce_field('acme_credits_services_action'); ?>
                <input type="hidden" name="acme_action" value="create">

                <table class="form-table">
                    <tr>
                        <th><label>Slug</label></th>
                        <td>
                            <input name="slug" type="text" class="regular-text" placeholder="clt" required>
                            <p class="description">Identificador único (sem espaços). Ex: <code>clt</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Nome</label></th>
                        <td><input name="name" type="text" class="regular-text" placeholder="CLT" required></td>
                    </tr>
                    <tr>
                        <th><label>Créditos (custo)</label></th>
                        <td><input name="credits_cost" type="number" min="0" value="1"></td>
                    </tr>
                    <tr>
                        <th><label>Ativo</label></th>
                        <td><label><input type="checkbox" name="is_active" checked> Sim</label></td>
                    </tr>
                </table>

                <?php submit_button('Salvar serviço'); ?>
            </form>

            <hr>

            <h2>Serviços cadastrados</h2>

            <?php if (!$rows): ?>
                <p>Nenhum serviço cadastrado.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Slug</th>
                            <th>Nome</th>
                            <th>Créditos</th>
                            <th>Status</th>
                            <th style="width:320px">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo (int) $r->id; ?></td>
                                <td><code><?php echo esc_html($r->slug); ?></code></td>
                                <td><?php echo esc_html($r->name); ?></td>
                                <td><strong><?php echo (int) $r->credits_cost; ?></strong></td>
                                <td><?php echo ((int)$r->is_active === 1) ? 'Ativo' : 'Inativo'; ?></td>
                                <td>
                                    <details>
                                        <summary>Editar</summary>
                                        <form method="post" style="margin-top:10px">
                                            <?php wp_nonce_field('acme_credits_services_action'); ?>
                                            <input type="hidden" name="acme_action" value="update">
                                            <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">

                                            <p>
                                                <label>Nome<br>
                                                    <input name="name" type="text" value="<?php echo esc_attr($r->name); ?>" class="regular-text">
                                                </label>
                                            </p>

                                            <p>
                                                <label>Créditos (custo)<br>
                                                    <input name="credits_cost" type="number" min="0" value="<?php echo (int)$r->credits_cost; ?>">
                                                </label>
                                            </p>

                                            <p>
                                                <label>
                                                    <input type="checkbox" name="is_active" <?php checked((int)$r->is_active, 1); ?>>
                                                    Ativo
                                                </label>
                                            </p>

                                            <?php submit_button('Atualizar', 'primary', 'submit', false); ?>
                                        </form>
                                    </details>

                                    <form method="post" style="display:inline-block;margin-top:6px" onsubmit="return confirm('Remover este serviço?');">
                                        <?php wp_nonce_field('acme_credits_services_action'); ?>
                                        <input type="hidden" name="acme_action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">
                                        <?php submit_button('Excluir', 'delete', 'submit', false); ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach;?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
