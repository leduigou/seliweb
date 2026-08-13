<?php
/**
 * Modèle de page "Annonces SEL", fourni par le plugin.
 * Enregistré via theme_page_templates / template_include (voir class-front.php).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$filters = array(
    'categorie_id' => isset( $_GET['categorie_id'] ) ? intval( $_GET['categorie_id'] )      : 0,
    'rubrique_id'  => isset( $_GET['rubrique_id'] )  ? intval( $_GET['rubrique_id'] )        : 0,
    'type_annonce' => isset( $_GET['type_annonce'] ) ? sanitize_key( $_GET['type_annonce'] ) : '',
    'ville'        => isset( $_GET['ville'] )         ? sanitize_text_field( $_GET['ville'] ) : '',
);

$par_page      = swv_per_page();
$page_courante = max( 1, intval( $_GET['sel_page'] ?? 1 ) );
$offset        = ( $page_courante - 1 ) * $par_page;

Seliweb_Annonces::check_expired();
$toutes   = Seliweb_Annonces::get_annonces_publiques( array_filter( $filters ) );
$total    = count( $toutes );
$nb_pages = $par_page > 0 ? (int) ceil( $total / $par_page ) : 1;

$annonces = array_slice( $toutes, $offset, $par_page );

$groupe_visiteur = null;
if ( is_user_logged_in() ) {
    global $wpdb;
    $tm = $wpdb->prefix . 'seliweb_membres';
    $tg = $wpdb->prefix . 'seliweb_groupes';
    $groupe_visiteur = $wpdb->get_row( $wpdb->prepare(
        "SELECT g.* FROM $tg g INNER JOIN $tm m ON m.groupe_id=g.id WHERE m.wp_user_id=%d LIMIT 1",
        get_current_user_id()
    ) );
}

if ( isset( $_GET['seliweb_annonce'] ) ) :
    $args = array(
        'annonce_id'      => intval( $_GET['seliweb_annonce'] ),
        'groupe_visiteur' => $groupe_visiteur,
    );
    include SELIWEB_DIR . 'templates/parts/annonce-detail.php';
    get_footer();
    return;
endif;

swv_render_search( $filters );
swv_render_pagination( $page_courante, $nb_pages, $total, true );
?>

<main id="swv-main">
    <div class="swv-main-inner">
        <section id="swv-annonces">
            <?php if ( empty( $annonces ) ) : ?>
                <div class="swv-annonces-empty">
                    <?php esc_html_e( 'Aucune annonce trouvée.', 'seliweb-view' ); ?>
                </div>
            <?php else : ?>
                <?php
                $swv_mode = apply_filters( 'seliweb_display_mode', 'grille' );
                $swv_cols = intval( apply_filters( 'seliweb_grid_cols', 3 ) );
                $swv_cls  = 'swv-annonces-' . $swv_mode;
                if ( $swv_mode === 'grille' ) $swv_cls .= ' swv-cols-' . $swv_cols;
                ?>
                <div class="<?php echo esc_attr( $swv_cls ); ?>"
                     <?php if ( $swv_mode === 'grille' ) echo 'style="--swv-cols:' . $swv_cols . '"'; ?>>
                    <?php foreach ( $annonces as $annonce ) swv_render_card( $annonce ); ?>
                </div>
            <?php endif; ?>
        </section>

        <aside id="swv-sidebar">
            <?php if ( is_active_sidebar( 'swv-sidebar' ) ) :
                dynamic_sidebar( 'swv-sidebar' );
            else : ?>
                <div class="swv-widget">
                    <h3 class="swv-widget-title"><?php esc_html_e( 'À propos du SEL', 'seliweb-view' ); ?></h3>
                    <p style="font-size:.9rem;color:#555;">
                        <?php esc_html_e( "Un Système d'Échange Local (SEL) est un réseau d'échanges de biens, services et savoirs entre particuliers.", 'seliweb-view' ); ?>
                    </p>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</main>

<?php swv_render_pagination( $page_courante, $nb_pages, $total ); ?>
<?php get_footer(); ?>
