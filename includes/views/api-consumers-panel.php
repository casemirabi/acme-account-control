<?php
if (!defined('ABSPATH')) {
    exit;
}

$apiEnabled = function_exists('acme_api_public_is_enabled')
    ? acme_api_public_is_enabled()
    : true;

$noticeData = get_transient('acme_api_panel_notice_' . get_current_user_id());
if ($noticeData) {
    delete_transient('acme_api_panel_notice_' . get_current_user_id());
}

$plainKeyData = get_transient('acme_api_consumer_plain_key_front_' . get_current_user_id());
if ($plainKeyData) {
    delete_transient('acme_api_consumer_plain_key_front_' . get_current_user_id());
}

$currentUsers = get_users([
    'orderby' => 'display_name',
    'order'   => 'ASC',
    'number'  => 500,
    'fields'  => ['ID', 'display_name', 'user_email', 'user_login'],
]);

$consumerRows = function_exists('acme_api_consumer_get_all')
    ? acme_api_consumer_get_all(200)
    : [];

$activeCount = 0;
$revokedCount = 0;
$lastUsageLabel = '—';

foreach ($consumerRows as $consumerRow) {
    $rowStatus = (string) ($consumerRow['status'] ?? '');

    if ($rowStatus === 'active') {
        $activeCount++;
    }

    if ($rowStatus === 'revoked') {
        $revokedCount++;
    }
}

$lastUsedValues = array_filter(array_map(static function ($row) {
    return (string) ($row['last_used_at'] ?? '');
}, $consumerRows));

if (!empty($lastUsedValues)) {
    rsort($lastUsedValues);
    $lastUsageLabel = (string) $lastUsedValues[0];
}
?>

<div class="acme-api-panel">
    <div class="acme-api-panel-header">
        <div>
            <h2>API Control Panel</h2>
            <p class="acme-api-panel-subtitle">
                Painel administrativo para controle da API pública, chaves de acesso e operação segura.
            </p>
        </div>

        <span class="acme-api-admin-badge">Somente admin</span>
    </div>

    <?php if (!empty($noticeData['message'])) : ?>
        <div class="acme-api-notice acme-api-notice-<?php echo esc_attr(($noticeData['type'] ?? 'success') === 'error' ? 'error' : 'success'); ?>">
            <?php echo esc_html($noticeData['message']); ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($plainKeyData) && !empty($plainKeyData['api_key'])) : ?>
        <div class="acme-api-notice acme-api-notice-warning">
            <strong>Guarde esta chave agora.</strong> Ela só é exibida uma vez.
            <div class="acme-api-key-box">
                <code><?php echo esc_html($plainKeyData['api_key']); ?></code>
            </div>
            <div class="acme-api-key-meta">
                Consumidor:
                <strong><?php echo esc_html((string) ($plainKeyData['consumer_name'] ?? '')); ?></strong>
                |
                Usuário WP:
                <strong>#<?php echo (int) ($plainKeyData['wp_user_id'] ?? 0); ?></strong>
            </div>
        </div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field('acme_api_panel', 'acme_api_nonce'); ?>
        <input type="hidden" name="acme_api_panel_action" value="toggle_global_api">

        <div class="acme-api-card">
            <div class="acme-api-status-row">
                <div>
                    <h3>Status global da API</h3>

                    <p class="acme-api-status-text">
                        API pública:
                        <span class="acme-api-status-pill <?php echo $apiEnabled ? 'active' : 'disabled'; ?>">
                            <?php echo $apiEnabled ? 'ATIVA' : 'BLOQUEADA'; ?>
                        </span>
                    </p>

                
                </div>

                <div class="acme-api-actions">
                    <button type="submit" name="api_global_toggle" value="0" class="acme-api-button acme-api-button-danger">
                        Bloquear API
                    </button>

                    <button type="submit" name="api_global_toggle" value="1" class="acme-api-button acme-api-button-success">
                        Reativar API
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div class="acme-api-grid">
        <div class="acme-api-card">
            <h3>Gerar nova chave</h3>

            <form method="post">
                <?php wp_nonce_field('acme_api_panel', 'acme_api_nonce'); ?>
                <input type="hidden" name="acme_api_panel_action" value="create_consumer_key">

                <div class="acme-api-form-row">
                    <div class="acme-api-field">
                        <label for="acme-api-user">Usuário</label>
                        <select id="acme-api-user" name="consumer_user_id" required>
                            <option value="">Selecionar usuário</option>
                            <?php foreach ($currentUsers as $currentUser) : ?>
                                <option value="<?php echo esc_attr($currentUser->ID); ?>">
                                    <?php
                                    echo esc_html(
                                        $currentUser->display_name . ' (' .
                                            ($currentUser->user_email ?: $currentUser->user_login) . ')'
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="acme-api-field">
                        <label for="acme-api-key-name">Nome da chave</label>
                        <input
                            id="acme-api-key-name"
                            type="text"
                            name="consumer_name"
                            placeholder="Ex.: Integração Financeira"
                            required>
                    </div>
                </div>

                <div class="acme-api-field">
                    <label>Serviços liberados</label>

                    <div class="acme-api-service-list">
                        <label class="acme-api-service-item">
                            <input type="checkbox" name="allowed_services[]" value="clt" checked>
                            clt
                        </label>
                    </div>
                </div>

                <button type="submit" class="acme-api-button acme-api-button-success">
                    Gerar chave
                </button>
            </form>
        </div>

        <div>
            <div class="acme-api-summary-grid">
                <div class="acme-api-summary-card">
                    <span class="acme-api-summary-label">Consumidores ativos</span>
                    <span class="acme-api-summary-value"><?php echo (int) $activeCount; ?></span>
                </div>

                <div class="acme-api-summary-card">
                    <span class="acme-api-summary-label">Chaves revogadas</span>
                    <span class="acme-api-summary-value"><?php echo (int) $revokedCount; ?></span>
                </div>

                <div class="acme-api-summary-card">
                    <span class="acme-api-summary-label">Último uso da API</span>
                    <span class="acme-api-summary-value" style="font-size:18px;">
                        <?php echo esc_html($lastUsageLabel); ?>
                    </span>
                </div>
            </div>

            <div class="acme-api-card" style="margin-top:20px;">
                <h3>Consumidores</h3>

                <div class="acme-api-table-wrap">
                    <table class="acme-api-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nome</th>
                                <th>Usuário</th>
                                <th>Serviços</th>
                                <th>Status</th>
                                <th>Prefixo</th>
                                <th>Último uso</th>
                                <th>Criado em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($consumerRows)) : ?>
                                <tr>
                                    <td colspan="8">Nenhuma chave cadastrada até o momento.</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($consumerRows as $consumerRow) : ?>
                                    <?php
                                    $wpUserId = (int) ($consumerRow['wp_user_id'] ?? 0);
                                    $linkedUser = $wpUserId > 0 ? get_user_by('id', $wpUserId) : false;
                                    $linkedUserLabel = $linkedUser
                                        ? sprintf('#%d — %s', $wpUserId, $linkedUser->display_name)
                                        : '#' . $wpUserId . ' — usuário não encontrado';

                                    $rowStatus = (string) ($consumerRow['status'] ?? '');
                                    $statusClass = 'acme-api-tag-revoked';
                                    $statusLabel = 'Revogado';

                                    if ($rowStatus === 'active') {
                                        $statusClass = 'acme-api-tag-active';
                                        $statusLabel = 'Ativo';
                                    } elseif ($rowStatus === 'inactive') {
                                        $statusClass = 'acme-api-tag-inactive';
                                        $statusLabel = 'Inativo';
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo (int) ($consumerRow['id'] ?? 0); ?></td>
                                        <td><?php echo esc_html((string) ($consumerRow['consumer_name'] ?? '')); ?></td>
                                        <td><?php echo esc_html($linkedUserLabel); ?></td>
                                        <td><?php echo esc_html((string) ($consumerRow['allowed_services'] ?? '')); ?></td>
                                        <td>
                                            <span class="acme-api-tag <?php echo esc_attr($statusClass); ?>">
                                                <?php echo esc_html($statusLabel); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <code><?php echo esc_html((string) ($consumerRow['api_key_prefix'] ?? '')); ?>...</code>
                                        </td>
                                        <td><?php echo esc_html((string) ($consumerRow['last_used_at'] ?? '—')); ?></td>
                                        <td><?php echo esc_html((string) ($consumerRow['created_at'] ?? '—')); ?></td>

                                        <td>
                                            <div class="acme-api-row-actions">
                                                <?php if ($rowStatus === 'active') : ?>
                                                    <form method="post" class="acme-api-inline-form">
                                                        <?php wp_nonce_field('acme_api_panel', 'acme_api_nonce'); ?>
                                                        <input type="hidden" name="acme_api_panel_action" value="update_consumer_status">
                                                        <input type="hidden" name="consumer_id" value="<?php echo (int) ($consumerRow['id'] ?? 0); ?>">
                                                        <input type="hidden" name="target_status" value="inactive">
                                                        <button type="submit" class="acme-api-link-button acme-api-link-button-warning">Inativar</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($rowStatus === 'inactive') : ?>
                                                    <form method="post" class="acme-api-inline-form">
                                                        <?php wp_nonce_field('acme_api_panel', 'acme_api_nonce'); ?>
                                                        <input type="hidden" name="acme_api_panel_action" value="update_consumer_status">
                                                        <input type="hidden" name="consumer_id" value="<?php echo (int) ($consumerRow['id'] ?? 0); ?>">
                                                        <input type="hidden" name="target_status" value="active">
                                                        <button type="submit" class="acme-api-link-button acme-api-link-button-success">
                                                            Reativar
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($rowStatus !== 'revoked') : ?>
                                                    <form method="post" class="acme-api-inline-form" onsubmit="return confirm('Tem certeza que deseja revogar esta chave?');">
                                                        <?php wp_nonce_field('acme_api_panel', 'acme_api_nonce'); ?>
                                                        <input type="hidden" name="acme_api_panel_action" value="update_consumer_status">
                                                        <input type="hidden" name="consumer_id" value="<?php echo (int) ($consumerRow['id'] ?? 0); ?>">
                                                        <input type="hidden" name="target_status" value="revoked">
                                                        <button type="submit" class="acme-api-link-button acme-api-link-button-danger">Revogar</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>