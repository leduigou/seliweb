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
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Tableau de bord Seliweb', 'seliweb' ); ?></h1>

    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:20px;">

        <div style="background:var(--wp-admin-theme-color,#2271b1);color:#fff;padding:20px 28px;border-radius:4px;min-width:160px;text-align:center;">
            <div style="font-size:36px;font-weight:600;"><?php echo intval( $nb_annonces ); ?></div>
            <div><?php esc_html_e( 'Annonces', 'seliweb' ); ?></div>
        </div>

        <div style="background:#3858e9;color:#fff;padding:20px 28px;border-radius:4px;min-width:160px;text-align:center;">
            <div style="font-size:36px;font-weight:600;"><?php echo intval( $nb_membres ); ?></div>
            <div><?php esc_html_e( 'Membres', 'seliweb' ); ?></div>
        </div>

        <div style="background:#1d9e75;color:#fff;padding:20px 28px;border-radius:4px;min-width:160px;text-align:center;">
            <div style="font-size:36px;font-weight:600;"><?php echo intval( $nb_groupes ); ?></div>
            <div><?php esc_html_e( 'Groupes', 'seliweb' ); ?></div>
        </div>

        <div style="background:#b32d2e;color:#fff;padding:20px 28px;border-radius:4px;min-width:160px;text-align:center;">
            <div style="font-size:36px;font-weight:600;"><?php echo intval( $nb_expirees ); ?></div>
            <div><?php esc_html_e( 'Annonces expirées', 'seliweb' ); ?></div>
        </div>

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
