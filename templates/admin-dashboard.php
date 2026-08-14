<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$ta = $wpdb->prefix . 'seliweb_annonces';
$tm = $wpdb->prefix . 'seliweb_membres';
$tg = $wpdb->prefix . 'seliweb_groupes';
$ts = $wpdb->prefix . 'seliweb_statuts';

$nb_annonces = $wpdb->get_var( "SELECT COUNT(*) FROM $ta" );
$nb_membres  = $wpdb->get_var( "SELECT COUNT(*) FROM $tm" );
$nb_groupes  = $wpdb->get_var( "SELECT COUNT(*) FROM $tg" );

$statut_expire  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ts WHERE slug=%s", 'expire' ) );
$nb_expirees    = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ta WHERE statut_id=%d", $statut_expire ?? 0 ) );

$db_version     = Seliweb_Database::get_installed_version();

// Solde compte SEL N°1
$sel_info        = class_exists('Seliweb_Transactions') ? Seliweb_Transactions::get_sel_info() : null;
$solde_sel       = null;
$symbole_sel     = '';
if ( $sel_info && $sel_info['actif'] ) {
    $compte_sel = $wpdb->get_row( "SELECT id FROM $tm WHERE numero_sel = 1 LIMIT 1" );
    if ( $compte_sel ) {
        $solde_sel   = Seliweb_Transactions::get_balance( $compte_sel->id );
        $monnaie_sel = $sel_info['monnaie_id'] ? Seliweb_Transactions::get_monnaie( $sel_info['monnaie_id'] ) : null;
        $symbole_sel = $monnaie_sel ? ( $monnaie_sel->symbole ?: $monnaie_sel->nom ) : '';
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Tableau de bord Seliweb', 'seliweb' ); ?></h1>

    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:20px;">

        <div style="background:var(--wp-admin-theme-color,#2271b1);color:#fff;padding:20px 28px;border-radius:4px;min-width:160px;text-align:center;">
            <div style="font-size:36px;font-weight:600;margin-bottom:8px;"><?php echo intval( $nb_annonces ); ?></div>
            <div><?php esc_html_e( 'Annonces', 'seliweb' ); ?></div>
        </div>

        <div style="background:#3858e9;color:#fff;padding:20px 28px;border-radius:4px;min-width:160px;text-align:center;">
            <div style="font-size:36px;font-weight:600;margin-bottom:8px;"><?php echo intval( $nb_membres ); ?></div>
            <div><?php esc_html_e( 'Membres', 'seliweb' ); ?></div>
        </div>

        <div style="background:#1d9e75;color:#fff;padding:20px 28px;border-radius:4px;min-width:160px;text-align:center;">
            <div style="font-size:36px;font-weight:600;margin-bottom:8px;"><?php echo intval( $nb_groupes ); ?></div>
            <div><?php esc_html_e( 'Groupes', 'seliweb' ); ?></div>
        </div>

        <div style="background:#b32d2e;color:#fff;padding:20px 28px;border-radius:4px;min-width:160px;text-align:center;">
            <div style="font-size:36px;font-weight:600;margin-bottom:8px;"><?php echo intval( $nb_expirees ); ?></div>
            <div><?php esc_html_e( 'Annonces expirées', 'seliweb' ); ?></div>
        </div>

        <?php if ( $solde_sel !== null ) : ?>
        <div style="background:#5a5a9a;color:#fff;padding:20px 28px;border-radius:4px;min-width:160px;text-align:center;">
            <div style="font-size:36px;font-weight:600;margin-bottom:8px;">
                <?php echo intval( $solde_sel ) . ( $symbole_sel ? ' ' . esc_html( $symbole_sel ) : '' ); ?>
            </div>
            <div><?php esc_html_e( 'Solde compte SEL N°1', 'seliweb' ); ?></div>
        </div>
        <?php endif; ?>

    </div>

    <h2 style="margin-top:30px;"><?php esc_html_e( 'Accès rapide', 'seliweb' ); ?></h2>
    <p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_annonces&action=new' ) ); ?>" class="button button-primary">
            <?php esc_html_e( '+ Nouvelle annonce', 'seliweb' ); ?>
        </a>
        &nbsp;
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_groupes&action=new' ) ); ?>" class="button">
            <?php esc_html_e( '+ Nouveau groupe', 'seliweb' ); ?>
        </a>
        &nbsp;
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres' ) ); ?>" class="button">
            <?php esc_html_e( 'Paramètres', 'seliweb' ); ?>
        </a>
        <?php
        $doc_file = SELIWEB_DIR . 'docs/Manuel-Utilisateur-Seliweb-WP.odt';
        if ( file_exists( $doc_file ) ) :
        ?>
        &nbsp;
        <a href="<?php echo esc_url( SELIWEB_URL . 'docs/Manuel-Utilisateur-Seliweb-WP.odt' ); ?>" class="button" download>
            <?php esc_html_e( 'Télécharger le manuel utilisateur', 'seliweb' ); ?>
        </a>
        <?php endif; ?>
    </p>

    <h2><?php esc_html_e( 'Informations système', 'seliweb' ); ?></h2>
    <?php $cron_info = get_option('seliweb_cron_last_run'); ?>
    <table class="widefat" style="max-width:450px;">
        <tbody>
            <tr>
                <td><?php esc_html_e( 'Version du plugin', 'seliweb' ); ?></td>
                <td><strong><?php echo esc_html( SELIWEB_VERSION ); ?></strong></td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Version base de données', 'seliweb' ); ?></td>
                <td><strong><?php echo esc_html( $db_version ); ?></strong></td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'WordPress', 'seliweb' ); ?></td>
                <td><strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong></td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'PHP', 'seliweb' ); ?></td>
                <td><strong><?php echo esc_html( PHP_VERSION ); ?></strong></td>
            </tr>
            <tr>
                <td><?php esc_html_e( 'Expiration automatique (cron)', 'seliweb' ); ?></td>
                <td>
                    <?php if ( $cron_info ) : ?>
                        <strong><?php echo esc_html( $cron_info['date'] ); ?></strong>
                        — <?php printf(
                            esc_html( _n('%d annonce expirée', '%d annonces expirées', $cron_info['annonces_expirees'], 'seliweb') ),
                            $cron_info['annonces_expirees']
                        ); ?>
                    <?php else : ?>
                        <em><?php esc_html_e('Pas encore exécuté','seliweb'); ?></em>
                    <?php endif; ?>
                    <br><small style="color:#888;">
                        <?php
                        $next = wp_next_scheduled('seliweb_cron_expire');
                        echo $next
                            ? esc_html__('Prochain passage : ','seliweb') . esc_html(date_i18n(get_option('date_format').' H:i', $next))
                            : esc_html__('Cron non planifié','seliweb');
                        ?>
                    </small>
                </td>
            </tr>
        </tbody>
    </table>
</div>
