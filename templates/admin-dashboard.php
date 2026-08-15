<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$ta = $wpdb->prefix . 'seliweb_annonces';
$tm = $wpdb->prefix . 'seliweb_membres';
$tg = $wpdb->prefix . 'seliweb_groupes';
$ts = $wpdb->prefix . 'seliweb_statuts';

// ================================================================
// Statistiques membres (genre, ville, âge), calculées pour un groupe
// donné ou pour l'ensemble des membres (groupe_id = 0).
// ================================================================
if ( ! function_exists( 'seliweb_pct' ) ) {
function seliweb_pct( $n, $total ) {
    return $total ? round( $n / $total * 100, 1 ) : 0.0;
}
}

if ( ! function_exists( 'seliweb_calc_stats_membres' ) ) {
function seliweb_calc_stats_membres( $groupe_id ) {
    global $wpdb;
    $tm = $wpdb->prefix . 'seliweb_membres';

    $total = $groupe_id
        ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tm WHERE groupe_id=%d", $groupe_id ) )
        : (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tm" );

    // Genre
    $genre_sql = $groupe_id
        ? $wpdb->prepare( "SELECT civilite, COUNT(*) AS n FROM $tm WHERE groupe_id=%d GROUP BY civilite", $groupe_id )
        : "SELECT civilite, COUNT(*) AS n FROM $tm GROUP BY civilite";
    $genre = array( 'Mr' => 0, 'Mme' => 0, 'non_renseigne' => 0 );
    foreach ( $wpdb->get_results( $genre_sql ) as $r ) {
        if ( $r->civilite === 'Mr' )       $genre['Mr']  = (int) $r->n;
        elseif ( $r->civilite === 'Mme' )  $genre['Mme'] = (int) $r->n;
        else                                $genre['non_renseigne'] += (int) $r->n;
    }

    // Villes
    $villes_sql = $groupe_id
        ? $wpdb->prepare( "SELECT ville, COUNT(*) AS n FROM $tm WHERE groupe_id=%d AND ville IS NOT NULL AND ville != '' GROUP BY ville ORDER BY n DESC, ville ASC", $groupe_id )
        : "SELECT ville, COUNT(*) AS n FROM $tm WHERE ville IS NOT NULL AND ville != '' GROUP BY ville ORDER BY n DESC, ville ASC";
    $villes         = $wpdb->get_results( $villes_sql );
    $nb_ville_connue = array_sum( array_map( function( $v ) { return (int) $v->n; }, $villes ) );

    // Âge (à partir des date_naissance renseignées)
    $ages_sql = $groupe_id
        ? $wpdb->prepare( "SELECT TIMESTAMPDIFF(YEAR, date_naissance, CURDATE()) FROM $tm WHERE groupe_id=%d AND date_naissance IS NOT NULL", $groupe_id )
        : "SELECT TIMESTAMPDIFF(YEAR, date_naissance, CURDATE()) FROM $tm WHERE date_naissance IS NOT NULL";
    $ages           = array_map( 'intval', $wpdb->get_col( $ages_sql ) );
    $nb_age_declare = count( $ages );
    $age_moyen      = $nb_age_declare ? array_sum( $ages ) / $nb_age_declare : null;

    $tranches = array( 'moins_20' => 0, '20_39' => 0, '40_59' => 0, '60_79' => 0, '80_plus' => 0 );
    foreach ( $ages as $age ) {
        if ( $age < 20 )      $tranches['moins_20']++;
        elseif ( $age < 40 )  $tranches['20_39']++;
        elseif ( $age < 60 )  $tranches['40_59']++;
        elseif ( $age < 80 )  $tranches['60_79']++;
        else                   $tranches['80_plus']++;
    }

    return array(
        'total'             => $total,
        'genre'             => $genre,
        'villes'            => $villes,
        'nb_ville_inconnue' => max( 0, $total - $nb_ville_connue ),
        'nb_age_declare'    => $nb_age_declare,
        'age_moyen'         => $age_moyen,
        'tranches'          => $tranches,
    );
}
}

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

    <h2 style="margin-top:30px;"><?php esc_html_e( 'Statistiques membres', 'seliweb' ); ?></h2>
    <?php
    $stat_groupes    = $wpdb->get_results( "SELECT id, nom FROM $tg ORDER BY nom ASC" );
    $stat_groupe_id  = isset( $_GET['stat_groupe'] ) ? intval( $_GET['stat_groupe'] ) : 0;
    $stats           = seliweb_calc_stats_membres( $stat_groupe_id );
    ?>
    <h2 class="nav-tab-wrapper" style="border-bottom:1px solid #ccc;margin-bottom:0;">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb&stat_groupe=0' ) ); ?>"
           class="nav-tab <?php echo $stat_groupe_id === 0 ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Tous les membres', 'seliweb' ); ?>
        </a>
        <?php foreach ( $stat_groupes as $sg ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb&stat_groupe=' . intval( $sg->id ) ) ); ?>"
               class="nav-tab <?php echo $stat_groupe_id === (int) $sg->id ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html( $sg->nom ); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <div style="background:#fff;border:1px solid #ccc;border-top:none;padding:20px;max-width:900px;">
        <?php if ( $stats['total'] === 0 ) : ?>
            <p><em><?php esc_html_e( 'Aucun membre dans ce groupe.', 'seliweb' ); ?></em></p>
        <?php else : ?>

            <p><strong><?php printf( esc_html__( '%d membre(s)', 'seliweb' ), $stats['total'] ); ?></strong></p>

            <div style="display:flex;gap:40px;flex-wrap:wrap;">

                <div>
                    <h3><?php esc_html_e( 'Genre', 'seliweb' ); ?></h3>
                    <table class="widefat" style="max-width:280px;">
                        <tbody>
                            <tr>
                                <td><?php esc_html_e( 'Hommes', 'seliweb' ); ?></td>
                                <td><?php echo intval( $stats['genre']['Mr'] ); ?></td>
                                <td><?php echo esc_html( seliweb_pct( $stats['genre']['Mr'], $stats['total'] ) ); ?> %</td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Femmes', 'seliweb' ); ?></td>
                                <td><?php echo intval( $stats['genre']['Mme'] ); ?></td>
                                <td><?php echo esc_html( seliweb_pct( $stats['genre']['Mme'], $stats['total'] ) ); ?> %</td>
                            </tr>
                            <?php if ( $stats['genre']['non_renseigne'] > 0 ) : ?>
                            <tr>
                                <td><em><?php esc_html_e( 'Non renseigné', 'seliweb' ); ?></em></td>
                                <td><?php echo intval( $stats['genre']['non_renseigne'] ); ?></td>
                                <td><?php echo esc_html( seliweb_pct( $stats['genre']['non_renseigne'], $stats['total'] ) ); ?> %</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div>
                    <h3><?php esc_html_e( 'Âge', 'seliweb' ); ?></h3>
                    <p class="description">
                        <?php printf(
                            esc_html__( 'Date de naissance renseignée par %1$d membre(s) sur %2$d (%3$s %%).', 'seliweb' ),
                            $stats['nb_age_declare'], $stats['total'], seliweb_pct( $stats['nb_age_declare'], $stats['total'] )
                        ); ?>
                    </p>
                    <?php if ( $stats['nb_age_declare'] > 0 ) : ?>
                        <p><strong><?php printf( esc_html__( 'Moyenne d\'âge : %s ans', 'seliweb' ), round( $stats['age_moyen'], 1 ) ); ?></strong></p>
                        <table class="widefat" style="max-width:320px;">
                            <tbody>
                                <?php
                                $tranches_labels = array(
                                    'moins_20' => __( 'Moins de 20 ans', 'seliweb' ),
                                    '20_39'    => __( '20 à 40 ans', 'seliweb' ),
                                    '40_59'    => __( '40 à 60 ans', 'seliweb' ),
                                    '60_79'    => __( '60 à 80 ans', 'seliweb' ),
                                    '80_plus'  => __( 'Plus de 80 ans', 'seliweb' ),
                                );
                                foreach ( $tranches_labels as $cle => $label ) :
                                    $n = $stats['tranches'][ $cle ];
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $label ); ?></td>
                                    <td><?php echo intval( $n ); ?></td>
                                    <td><?php echo esc_html( seliweb_pct( $n, $stats['nb_age_declare'] ) ); ?> %</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="description"><?php esc_html_e( 'Pourcentages calculés sur les membres ayant renseigné leur date de naissance.', 'seliweb' ); ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <h3><?php esc_html_e( 'Villes', 'seliweb' ); ?></h3>
                    <?php if ( empty( $stats['villes'] ) ) : ?>
                        <p><em><?php esc_html_e( 'Aucune ville renseignée.', 'seliweb' ); ?></em></p>
                    <?php else : ?>
                        <table class="widefat" style="max-width:320px;">
                            <tbody>
                                <?php foreach ( $stats['villes'] as $v ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $v->ville ); ?></td>
                                    <td><?php echo intval( $v->n ); ?></td>
                                    <td><?php echo esc_html( seliweb_pct( $v->n, $stats['total'] ) ); ?> %</td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ( $stats['nb_ville_inconnue'] > 0 ) : ?>
                                <tr>
                                    <td><em><?php esc_html_e( 'Non renseignée', 'seliweb' ); ?></em></td>
                                    <td><?php echo intval( $stats['nb_ville_inconnue'] ); ?></td>
                                    <td><?php echo esc_html( seliweb_pct( $stats['nb_ville_inconnue'], $stats['total'] ) ); ?> %</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            </div>
        <?php endif; ?>
    </div>
</div>
