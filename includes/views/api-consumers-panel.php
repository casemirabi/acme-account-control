<?php
if (!defined('ABSPATH')) {
    exit;
}

$apiEnabled = function_exists('acme_api_public_is_enabled')
    ? acme_api_public_is_enabled()
    : true;

$summaryActiveConsumers = 128;
$summaryRevokedKeys = 12;
$summaryLastUsage = 'há 2 min';

$currentUsers = get_users(
    array(
        'role__in' => array('administrator'),
        'orderby'  => 'display_name',
        'order'    => 'ASC',
    )
);
?>

<div class="acme-api-panel">
    <div class="acme-api-panel-header">
        <div>
            <h2>ACME API Control Panel</h2>
            <p class="acme-api-panel-subtitle">
                Mockup funcional do front-end administrativo com kill switch global, geração de chave, filtros e ações em massa.
            </p>
        </div>

        <span class="acme-api-admin-badge">Somente admin</span>
    </div>

    <form method="post">
        <?php wp_nonce_field('acme_api_panel', 'acme_api_nonce'); ?>

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

                    <p class="acme-api-status-description">
                        Kill switch global para interromper temporariamente o acesso público sem alterar endpoints existentes.
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

            <div class="acme-api-form-row">
                <div class="acme-api-field">
                    <label for="acme-api-user">Usuário</label>
                    <select id="acme-api-user" name="consumer_user_id">
                        <option value="">Selecionar usuário</option>
                        <?php foreach ($currentUsers as $currentUser) : ?>
                            <option value="<?php echo esc_attr($currentUser->ID); ?>">
                                <?php echo esc_html($currentUser->display_name . ' (' . $currentUser->user_login . ')'); ?>
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
                    >
                </div>
            </div>

            <div class="acme-api-field">
                <label>Serviços liberados</label>

                <div class="acme-api-service-list">
                    <label class="acme-api-service-item">
                        <input type="checkbox" name="allowed_services[]" value="saldo">
                        saldo
                    </label>

                    <label class="acme-api-service-item">
                        <input type="checkbox" name="allowed_services[]" value="extrato">
                        extrato
                    </label>

                    <label class="acme-api-service-item">
                        <input type="checkbox" name="allowed_services[]" value="saque">
                        saque
                    </label>

                    <label class="acme-api-service-item">
                        <input type="checkbox" name="allowed_services[]" value="webhook">
                        webhook
                    </label>
                </div>
            </div>

            <button type="button" class="acme-api-button acme-api-button-success">
                Gerar chave
            </button>
        </div>

        <div>
            <div class="acme-api-summary-grid">
                <div class="acme-api-summary-card">
                    <span class="acme-api-summary-label">Consumidores ativos</span>
                    <span class="acme-api-summary-value">128</span>
                </div>

                <div class="acme-api-summary-card">
                    <span class="acme-api-summary-label">Chaves revogadas</span>
                    <span class="acme-api-summary-value">12</span>
                </div>

                <div class="acme-api-summary-card">
                    <span class="acme-api-summary-label">Último uso da API</span>
                    <span class="acme-api-summary-value" style="font-size: 24px;">há 2 min</span>
                </div>
            </div>

            <div class="acme-api-card" style="margin-top: 20px;">
                <h3>Consumidores</h3>

                <div class="acme-api-toolbar">
                    <select class="acme-api-field acme-api-filter">
                        <option>Filtro status</option>
                    </select>

                    <select class="acme-api-field acme-api-filter">
                        <option>Filtro usuário</option>
                    </select>

                    <input class="acme-api-field acme-api-filter" type="text" placeholder="Busca">
                </div>

                <div class="acme-api-bulk-actions">
                    <strong>Selecionar todos</strong>
                    <button type="button" class="acme-api-button" style="background:#d97706;color:#fff;">Inativar</button>
                    <button type="button" class="acme-api-button acme-api-button-success">Reativar</button>
                    <button type="button" class="acme-api-button acme-api-button-danger">Revogar</button>
                </div>

                <div class="acme-api-table-wrap">
                    <table class="acme-api-table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Nome</th>
                                <th>Usuário</th>
                                <th>Serviços</th>
                                <th>Status</th>
                                <th>Último uso</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><input type="checkbox"></td>
                                <td>ERP Matriz</td>
                                <td>joao</td>
                                <td>saldo, extrato</td>
                                <td><span class="acme-api-tag acme-api-tag-active">Ativo</span></td>
                                <td>12:44</td>
                                <td>...</td>
                            </tr>
                            <tr>
                                <td><input type="checkbox"></td>
                                <td>Filial Sul</td>
                                <td>maria</td>
                                <td>saldo</td>
                                <td><span class="acme-api-tag acme-api-tag-inactive">Inativo</span></td>
                                <td>09:17</td>
                                <td>...</td>
                            </tr>
                            <tr>
                                <td><input type="checkbox"></td>
                                <td>Portal B2B</td>
                                <td>admin</td>
                                <td>saldo, saque</td>
                                <td><span class="acme-api-tag acme-api-tag-active">Ativo</span></td>
                                <td>08:51</td>
                                <td>...</td>
                            </tr>
                            <tr>
                                <td><input type="checkbox"></td>
                                <td>Legacy App</td>
                                <td>suporte</td>
                                <td>extrato</td>
                                <td><span class="acme-api-tag acme-api-tag-revoked">Revogado</span></td>
                                <td>Ontem</td>
                                <td>...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>