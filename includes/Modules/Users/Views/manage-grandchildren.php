<?php
if (!defined('ABSPATH')) {
    exit;
}
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
                    href="<?php echo esc_url($clearFiltersUrl); ?>">
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

        <?php foreach ($messages as $message): ?>
            <div style="padding:10px 12px;border-radius:12px;<?php echo esc_attr($message['style']); ?>margin-bottom:12px;">
                <?php echo esc_html($message['text']); ?>
            </div>
        <?php endforeach; ?>

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
                            <?php foreach ($childrenForFilter as $childUser): ?>
                                <option value="<?php echo (int) $childUser->ID; ?>" <?php echo selected($filterMaster, (int) $childUser->ID, false); ?>>
                                    <?php echo esc_html($childUser->display_name); ?>
                                </option>
                            <?php endforeach; ?>
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
    <?php return; ?>
    <?php endif; ?>

    <div style="overflow:auto;">
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
