<?php
if (!defined('ABSPATH')) exit;

/**
 * View: Meu Perfil
 * Espera receber:
 * - $profileSections (array)
 */

$inventoryHtml     = $profileSections['inventory_html'] ?? '';
$subscriptionsHtml = $profileSections['subscriptions_html'] ?? '';
$snapshotHtml      = $profileSections['snapshot_html'] ?? '';
$userDataHtml      = $profileSections['user_data_html'] ?? '';
?>

<div class="acme-my-profile-page">

    <section class="acme-my-profile-hero">
        <div class="acme-my-profile-hero__content">
            <h2 class="acme-my-profile-title">Meu perfil</h2>
            <p class="acme-my-profile-subtitle">
                Acompanhe seus créditos, assinaturas, histórico e dados cadastrais em um só lugar.
            </p>
        </div>
        
        <nav class="acme-my-profile-nav" aria-label="Navegação da página Meu perfil">
            <a href="#acme-profile-creditos">Créditos</a>
            <a href="#acme-profile-assinaturas">Assinaturas</a>
            <a href="#acme-profile-historico">Histórico</a>
            <a href="#acme-profile-dados">Meus dados</a>
        </nav>
    </section>

    <section id="acme-profile-dados" class="acme-profile-section">

        <div class="acme-profile-card">
            <?php echo $userDataHtml; ?>
        </div>
    </section>
    
    <section id="acme-profile-creditos" class="acme-profile-section">


        <div class="acme-profile-grid acme-profile-grid--credits">
            <div class="acme-profile-card">
                <?php if ($inventoryHtml !== ''): ?>
                    <?php echo $inventoryHtml; ?>
                <?php else: ?>
                    <div class="acme-profile-empty-state">
                        <strong>Inventário de créditos indisponível.</strong>
                        <p>O shortcode <code>[acme_credit_inventory_table]</code> não foi encontrado no plugin atual.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section id="acme-profile-assinaturas" class="acme-profile-section">
        <div class="acme-profile-section__header">
            <h3>Assinaturas</h3>
            <p>Contratos e créditos vinculados ao usuário.</p>
        </div>

        <div class="acme-profile-card">
            <?php echo $subscriptionsHtml; ?>
        </div>
    </section>

    <section id="acme-profile-historico" class="acme-profile-section">
        <div class="acme-profile-section__header">
            <h3>Histórico</h3>
            <p>Movimentações e snapshot de créditos.</p>
        </div>

        <div class="acme-profile-card">
            <?php echo $snapshotHtml; ?>
        </div>
    </section>


</div>