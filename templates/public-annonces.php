<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// $filters injecté par le shortcode
$filters      = isset( $filters ) ? $filters : array();
$par_page     = isset( $filters['limite'] ) && $filters['limite'] > 0 ? intval( $filters['limite'] ) : 10;
$page_courante = isset( $_GET['sel_page'] ) && $_GET['sel_page'] ? max( 1, intval( $_GET['sel_page'] ) ) : 1;
$offset        = ( $page_courante - 1 ) * $par_page;

global $wpdb;
$tc = $wpdb->prefix . 'seliweb_categories';
$tr = $wpdb->prefix . 'seliweb_rubriques';

$categories = $wpdb->get_results( "SELECT * FROM $tc ORDER BY nom ASC" );
$rubriques  = $wpdb->get_results( "SELECT * FROM $tr ORDER BY categorie_id, nom ASC" );
$villes     = Seliweb_Annonces::get_villes();

// Total pour la pagination
$toutes      = Seliweb_Annonces::get_annonces_publiques( array_filter( $filters ) );
$total       = count( $toutes );
$nb_pages    = $par_page > 0 ? (int) ceil( $total / $par_page ) : 1;
$annonces    = array_slice( $toutes, $offset, $par_page );

// Groupe du visiteur connecté
$groupe_visiteur = null;
if ( is_user_logged_in() ) {
    $tm = $wpdb->prefix . 'seliweb_membres';
    $tg = $wpdb->prefix . 'seliweb_groupes';
    $groupe_visiteur = $wpdb->get_row( $wpdb->prepare(
        "SELECT g.* FROM $tg g
         INNER JOIN $tm m ON m.groupe_id = g.id
         WHERE m.wp_user_id = %d LIMIT 1",
        get_current_user_id()
    ) );
}

$page_url = get_permalink();

// Helper : construire une URL en conservant tous les paramètres GET sauf sel_page
function seliweb_paginate_url( $base, $num ) {
    $params = $_GET;
    unset( $params['seliweb_annonce'] );
    $params['sel_page'] = $num;
    return add_query_arg( array_map( 'urlencode', $params ), $base );
}
?>

<div class="seliweb-wrap">

    <?php if ( isset( $_GET['seliweb_message_envoye'] ) ) : ?>
        <div class="seliweb-notice seliweb-notice-ok">
            <?php esc_html_e( 'Votre message a bien été envoyé à l\'annonceur.', 'seliweb' ); ?>
        </div>
    <?php endif; ?>

    <?php if ( empty( $_GET['seliweb_annonce'] ) ) : ?>

    <!-- ===== FILTRES ===== -->
    <form method="get" action="<?php echo esc_url( $page_url ); ?>" class="seliweb-filtres">
        <div class="seliweb-filtres-inner">

            <select name="categorie_id"
                    onchange="seliweb_pub_rubriques(this.value); seliweb_pub_type(this.value)">
                <option value=""><?php esc_html_e( 'Toutes catégories', 'seliweb' ); ?></option>
                <?php foreach ( $categories as $cat ) : ?>
                    <option value="<?php echo intval( $cat->id ); ?>"
                            data-slug="<?php echo esc_attr( $cat->slug ); ?>"
                            <?php selected( $filters['categorie_id'] ?? 0, $cat->id ); ?>>
                        <?php echo esc_html( $cat->nom ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="type_annonce" id="sel_type"
                    style="<?php echo ! empty( $filters['categorie_id'] ) ? '' : 'display:none'; ?>">
                <option value=""><?php esc_html_e( 'Offres & demandes', 'seliweb' ); ?></option>
                <option value="offre"   <?php selected( $filters['type_annonce'] ?? '', 'offre' ); ?>><?php esc_html_e( 'Offres',   'seliweb' ); ?></option>
                <option value="demande" <?php selected( $filters['type_annonce'] ?? '', 'demande' ); ?>><?php esc_html_e( 'Demandes', 'seliweb' ); ?></option>
            </select>

            <select name="rubrique_id" id="sel_rubrique">
                <option value=""><?php esc_html_e( 'Toutes rubriques', 'seliweb' ); ?></option>
                <?php foreach ( $rubriques as $rub ) : ?>
                    <option value="<?php echo intval( $rub->id ); ?>"
                            data-categorie="<?php echo intval( $rub->categorie_id ); ?>"
                            style="<?php echo ( empty( $filters['categorie_id'] ) || $rub->categorie_id == $filters['categorie_id'] ) ? '' : 'display:none'; ?>"
                            <?php selected( $filters['rubrique_id'] ?? 0, $rub->id ); ?>>
                        <?php echo esc_html( $rub->nom ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ( ! empty( $villes ) ) : ?>
            <select name="ville">
                <option value=""><?php esc_html_e( 'Toutes villes', 'seliweb' ); ?></option>
                <?php foreach ( $villes as $ville ) : ?>
                    <option value="<?php echo esc_attr( $ville ); ?>"
                            <?php selected( $filters['ville'] ?? '', $ville ); ?>>
                        <?php echo esc_html( $ville ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <button type="submit" class="seliweb-btn">
                <?php esc_html_e( 'Rechercher', 'seliweb' ); ?>
            </button>
            <a href="<?php echo esc_url( $page_url ); ?>" class="seliweb-btn seliweb-btn-secondary">
                <?php esc_html_e( 'Réinitialiser', 'seliweb' ); ?>
            </a>
        </div>
    </form>

    <!-- ===== BARRE DE PAGINATION (haut) ===== -->
    <?php if ( $total > 0 ) : ?>
        <div class="seliweb-pagination-info">
            <?php printf(
                esc_html( _n( '%d annonce trouvée', '%d annonces trouvées', $total, 'seliweb' ) ),
                $total
            ); ?>
            &nbsp;&mdash;&nbsp;
            <?php printf(
                esc_html__( 'Page %1$d sur %2$d', 'seliweb' ),
                $page_courante, $nb_pages
            ); ?>
        </div>
    <?php endif; ?>

    <?php if ( $nb_pages > 1 ) : ?>
    <nav class="seliweb-pagination">

        <?php if ( $page_courante > 1 ) : ?>
            <a href="<?php echo esc_url( seliweb_paginate_url( $page_url, $page_courante - 1 ) ); ?>"
               class="seliweb-page-btn seliweb-page-prev">
               &laquo; <?php esc_html_e( 'Précédente', 'seliweb' ); ?>
            </a>
        <?php else : ?>
            <span class="seliweb-page-btn seliweb-page-prev seliweb-page-disabled">
                &laquo; <?php esc_html_e( 'Précédente', 'seliweb' ); ?>
            </span>
        <?php endif; ?>

        <div class="seliweb-page-numbers">
            <?php
            // Affiche toujours : première, dernière, courante ±2, et "…" entre les sauts
            $shown = array();
            for ( $i = 1; $i <= $nb_pages; $i++ ) {
                if ( $i === 1 || $i === $nb_pages
                     || ( $i >= $page_courante - 2 && $i <= $page_courante + 2 ) ) {
                    $shown[] = $i;
                }
            }
            $prev_shown = null;
            foreach ( $shown as $n ) :
                if ( $prev_shown !== null && $n > $prev_shown + 1 ) :
                    echo '<span class="seliweb-page-ellipsis">&hellip;</span>';
                endif;
                if ( $n === $page_courante ) : ?>
                    <span class="seliweb-page-num seliweb-page-current"><?php echo $n; ?></span>
                <?php else : ?>
                    <a href="<?php echo esc_url( seliweb_paginate_url( $page_url, $n ) ); ?>"
                       class="seliweb-page-num"><?php echo $n; ?></a>
                <?php endif;
                $prev_shown = $n;
            endforeach;
            ?>
        </div>

        <?php if ( $page_courante < $nb_pages ) : ?>
            <a href="<?php echo esc_url( seliweb_paginate_url( $page_url, $page_courante + 1 ) ); ?>"
               class="seliweb-page-btn seliweb-page-next">
               <?php esc_html_e( 'Suivante', 'seliweb' ); ?> &raquo;
            </a>
        <?php else : ?>
            <span class="seliweb-page-btn seliweb-page-next seliweb-page-disabled">
                <?php esc_html_e( 'Suivante', 'seliweb' ); ?> &raquo;
            </span>
        <?php endif; ?>

    </nav>
    <?php endif; ?>

    <!-- ===== LISTE DES ANNONCES ===== -->
    <?php if ( empty( $annonces ) ) : ?>
        <p class="seliweb-empty"><?php esc_html_e( 'Aucune annonce trouvée.', 'seliweb' ); ?></p>
    <?php else : ?>
        <div class="seliweb-annonces-liste">
        <?php foreach ( $annonces as $annonce ) :
            $prix       = Seliweb_Annonces::get_prix( $annonce->id );
            $is_urgent  = ( $annonce->statut_slug === 'urgent' );
            $is_repondu = ( $annonce->statut_slug === 'repondu' );
            $url_detail = add_query_arg( array( 'seliweb_annonce' => $annonce->id, 'sel_page' => $page_courante ), $page_url );
        ?>
            <div class="seliweb-annonce-card">

                <div class="seliweb-annonce-photo">
                    <?php if ( $annonce->photo1 ) : ?>
                        <a href="<?php echo esc_url( $url_detail ); ?>">
                            <img src="<?php echo esc_url( $annonce->photo1 ); ?>"
                                 alt="<?php echo esc_attr( $annonce->titre ); ?>">
                        </a>
                    <?php else : ?>
                        <div class="seliweb-no-photo"></div>
                    <?php endif; ?>
                </div>

                <div class="seliweb-annonce-infos">
                    <span class="seliweb-annonce-id">#<?php echo intval( $annonce->id ); ?></span>

                    <h3 class="seliweb-annonce-titre">
                        <a href="<?php echo esc_url( $url_detail ); ?>">
                            <?php echo esc_html( $annonce->titre ); ?>
                        </a>
                    </h3>

                    <div class="seliweb-annonce-meta">
                        <span class="seliweb-tag"><?php echo esc_html( $annonce->cat_nom ); ?></span>
                        <?php if ( $annonce->cat_slug === 'annonces' && $annonce->type_annonce ) : ?>
                            <span class="seliweb-tag seliweb-tag-type">
                                <?php echo esc_html( ucfirst( $annonce->type_annonce ) ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $annonce->rub_nom ) : ?>
                            <span class="seliweb-tag seliweb-tag-rubrique">
                                <?php echo esc_html( $annonce->rub_nom ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ( $annonce->date_expiration ) : ?>
                        <div class="seliweb-expiration">
                            <?php esc_html_e( 'Expire le :', 'seliweb' ); ?>
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $annonce->date_expiration ) ) ); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $annonce->statut_nom ) :
                        $statut_gras = in_array( $annonce->statut_slug, array('urgent','repondu','expire') );
                    ?>
                        <div class="seliweb-statut <?php echo $statut_gras ? 'seliweb-statut-bold' : ''; ?>">
                            <?php echo esc_html( $annonce->statut_nom ); ?>
                        </div>
                    <?php endif; ?>

                    <div class="seliweb-prix">
                        <?php if ( $annonce->est_don ) : ?>
                            <span class="seliweb-don"><?php esc_html_e( 'Don', 'seliweb' ); ?></span>
                        <?php elseif ( ! empty( $prix ) ) : ?>
                            <?php foreach ( $prix as $idx_p => $p ) : ?>
                                <?php if ( $idx_p > 0 && $p->coordination ) : ?>
                                    <span class="seliweb-prix-coord"><?php echo esc_html($p->coordination); ?></span>
                                <?php endif; ?>
                                <span class="seliweb-prix-item">
                                    <?php echo esc_html( $p->prix . ' ' . ( $p->symbole ?: $p->nom ) ); ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        <?php endforeach; ?>
        </div>

        <?php if ( $nb_pages > 1 ) : ?>
        <nav class="seliweb-pagination seliweb-pagination-bottom">

            <?php if ( $page_courante > 1 ) : ?>
                <a href="<?php echo esc_url( seliweb_paginate_url( $page_url, $page_courante - 1 ) ); ?>"
                   class="seliweb-page-btn seliweb-page-prev">
                   &laquo; <?php esc_html_e( 'Précédente', 'seliweb' ); ?>
                </a>
            <?php else : ?>
                <span class="seliweb-page-btn seliweb-page-prev seliweb-page-disabled">
                    &laquo; <?php esc_html_e( 'Précédente', 'seliweb' ); ?>
                </span>
            <?php endif; ?>

            <div class="seliweb-page-numbers">
                <?php
                $prev_shown = null;
                foreach ( $shown as $n ) :
                    if ( $prev_shown !== null && $n > $prev_shown + 1 ) :
                        echo '<span class="seliweb-page-ellipsis">&hellip;</span>';
                    endif;
                    if ( $n === $page_courante ) : ?>
                        <span class="seliweb-page-num seliweb-page-current"><?php echo $n; ?></span>
                    <?php else : ?>
                        <a href="<?php echo esc_url( seliweb_paginate_url( $page_url, $n ) ); ?>"
                           class="seliweb-page-num"><?php echo $n; ?></a>
                    <?php endif;
                    $prev_shown = $n;
                endforeach;
                ?>
            </div>

            <?php if ( $page_courante < $nb_pages ) : ?>
                <a href="<?php echo esc_url( seliweb_paginate_url( $page_url, $page_courante + 1 ) ); ?>"
                   class="seliweb-page-btn seliweb-page-next">
                   <?php esc_html_e( 'Suivante', 'seliweb' ); ?> &raquo;
                </a>
            <?php else : ?>
                <span class="seliweb-page-btn seliweb-page-next seliweb-page-disabled">
                    <?php esc_html_e( 'Suivante', 'seliweb' ); ?> &raquo;
                </span>
            <?php endif; ?>

        </nav>
        <?php endif; ?>

    <?php endif; ?>

    <?php endif; // fin liste ?>

    <!-- ===== DÉTAIL ANNONCE ===== -->
    <?php if ( ! empty( $_GET['seliweb_annonce'] ) ) :
        $annonce_id = intval( $_GET['seliweb_annonce'] );
        $ta   = $wpdb->prefix . 'seliweb_annonces';
        $tc2  = $wpdb->prefix . 'seliweb_categories';
        $tr2  = $wpdb->prefix . 'seliweb_rubriques';
        $ts   = $wpdb->prefix . 'seliweb_statuts';
        $tm_m = $wpdb->prefix . 'seliweb_membres';

        $detail = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.* FROM $ta a WHERE a.id = %d",
            $annonce_id
        ) );

        if ( $detail ) {
            // Ajouter les infos liées séparément
            $detail->cat_nom = $wpdb->get_var( $wpdb->prepare(
                "SELECT nom FROM $tc2 WHERE id = %d",
                intval( $detail->categorie_id )
            ) );
            $detail->rub_nom = $wpdb->get_var( $wpdb->prepare(
                "SELECT nom FROM $tr2 WHERE id = %d",
                intval( $detail->rubrique_id )
            ) );
            $detail->statut_slug = $wpdb->get_var( $wpdb->prepare(
                "SELECT slug FROM $ts WHERE id = %d",
                intval( $detail->statut_id )
            ) );
            $membre = $wpdb->get_row( $wpdb->prepare(
                "SELECT telephone, adresse, ville FROM $tm_m WHERE id = %d",
                intval( $detail->membre_id )
            ) );
            if ( $membre ) {
                $detail->telephone = $membre->telephone;
                $detail->adresse = $membre->adresse;
                $detail->ville = $membre->ville;
            }
            $detail->user_email = $wpdb->get_var( $wpdb->prepare(
                "SELECT user_email FROM {$wpdb->users} WHERE ID = (SELECT wp_user_id FROM $tm_m WHERE id = %d)",
                intval( $detail->membre_id )
            ) );
            $detail->membre_nom = $wpdb->get_var( $wpdb->prepare(
                "SELECT display_name FROM {$wpdb->users} WHERE ID = (SELECT wp_user_id FROM $tm_m WHERE id = %d)",
                intval( $detail->membre_id )
            ) );
        }

        $detail_ok = $detail && ( $detail->statut_slug !== 'expire' );

        if ( $detail_ok ) :
            $prix_detail = Seliweb_Annonces::get_prix( $annonce_id );
        ?>
        <div class="seliweb-detail">

            <?php
                $retour_page = ! empty( $_GET['sel_page'] ) ? intval( $_GET['sel_page'] ) : 1;
                $retour_url  = $retour_page > 1
                    ? add_query_arg( 'sel_page', $retour_page, $page_url )
                    : $page_url;
                ?>
            <a href="<?php echo esc_url( $retour_url ); ?>" class="seliweb-retour">
                &larr; <?php esc_html_e( 'Retour aux annonces', 'seliweb' ); ?>
            </a>

            <h2><?php echo esc_html( $detail->titre ); ?></h2>

            <?php if ( $detail->photo1 || $detail->photo2 ) : ?>
            <div class="seliweb-detail-photos">
                <?php if ( $detail->photo1 ) : ?>
                    <img src="<?php echo esc_url( $detail->photo1 ); ?>" alt="">
                <?php endif; ?>
                <?php if ( $detail->photo2 ) : ?>
                    <img src="<?php echo esc_url( $detail->photo2 ); ?>" alt="">
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="seliweb-annonce-meta" style="margin-bottom:12px;">
                <span class="seliweb-tag"><?php echo esc_html( $detail->cat_nom ); ?></span>
                <?php if ( isset( $detail->cat_slug ) && $detail->cat_slug === 'annonces' && $detail->type_annonce ) : ?>
                    <span class="seliweb-tag seliweb-tag-type">
                        <?php echo esc_html( ucfirst( $detail->type_annonce ) ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( $detail->rub_nom ) : ?>
                    <span class="seliweb-tag seliweb-tag-rubrique">
                        <?php echo esc_html( $detail->rub_nom ); ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="seliweb-detail-texte">
                <?php echo esc_html( $detail->texte ); ?>
            </div>

            <?php if ( ! empty( $prix_detail ) && ! $detail->est_don ) : ?>
            <div class="seliweb-prix" style="margin-bottom:16px;">
                <?php foreach ( $prix_detail as $idx_pd => $p ) : ?>
                    <?php if ( $idx_pd > 0 && $p->coordination ) : ?>
                        <span class="seliweb-prix-coord"><?php echo esc_html($p->coordination); ?></span>
                    <?php endif; ?>
                    <span class="seliweb-prix-item">
                        <?php echo esc_html( $p->prix . ' ' . ( $p->symbole ?: $p->nom ) ); ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php elseif ( $detail->est_don ) : ?>
                <p class="seliweb-don"><?php esc_html_e( 'Don', 'seliweb' ); ?></p>
            <?php endif; ?>

            <div class="seliweb-detail-contact">
                <h4><?php esc_html_e( 'Contacter l\'annonceur', 'seliweb' ); ?></h4>

                <?php if ( ! is_user_logged_in() ) : ?>
                    <p><em>
                        <?php printf(
                            wp_kses( __( '<a href="%s">Connectez-vous</a> pour contacter l\'annonceur.', 'seliweb' ), array( 'a' => array( 'href' => array() ) ) ),
                            esc_url( wp_login_url( get_permalink() ) )
                        ); ?>
                    </em></p>

                <?php elseif ( ! $groupe_visiteur ) : ?>
                    <p><em><?php esc_html_e( 'Vous n\'êtes rattaché à aucun groupe. Contactez l\'administrateur.', 'seliweb' ); ?></em></p>

                <?php else : ?>

                    <?php if ( $groupe_visiteur->contact_mail_cache ) : ?>
                        <?php if ( isset( $_GET['seliweb_message_envoye'] ) ) : ?>
                            <div class="seliweb-notice seliweb-notice-ok">
                                <?php esc_html_e( 'Message envoyé !', 'seliweb' ); ?>
                            </div>
                        <?php else : ?>
                        <form method="post" action="<?php echo esc_url( $page_url ); ?>"
                              class="seliweb-form-contact">
                            <?php wp_nonce_field( 'seliweb_contact_' . $annonce_id, 'seliweb_contact_nonce' ); ?>
                            <input type="hidden" name="annonce_id" value="<?php echo intval( $annonce_id ); ?>">
                            <textarea name="message" rows="4"
                                      placeholder="<?php esc_attr_e( 'Votre message…', 'seliweb' ); ?>"
                                      required class="seliweb-textarea"></textarea>
                            <button type="submit" name="seliweb_envoyer_message" class="seliweb-btn">
                                <?php esc_html_e( 'Envoyer un message', 'seliweb' ); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ( $groupe_visiteur->contact_mail_visible && $detail->user_email ) : ?>
                        <p><?php esc_html_e( 'Email :', 'seliweb' ); ?>
                            <a href="mailto:<?php echo esc_attr( $detail->user_email ); ?>">
                                <?php echo esc_html( $detail->user_email ); ?>
                            </a>
                        </p>
                    <?php endif; ?>

                    <?php if ( $groupe_visiteur->contact_tel && $detail->telephone ) : ?>
                        <p><?php esc_html_e( 'Téléphone :', 'seliweb' ); ?>
                            <?php echo esc_html( $detail->telephone ); ?>
                        </p>
                    <?php endif; ?>

                    <?php if ( $groupe_visiteur->contact_adresse && $detail->adresse ) : ?>
                        <p><?php esc_html_e( 'Adresse :', 'seliweb' ); ?>
                            <?php echo esc_html( $detail->adresse );
                                  echo $detail->ville ? ' — ' . esc_html( $detail->ville ) : ''; ?>
                        </p>
                    <?php endif; ?>

                <?php endif; ?>
            </div>

        </div>
        <?php else : ?>
            <p class="seliweb-empty">
                <?php esc_html_e( 'Annonce introuvable ou expirée.', 'seliweb' ); ?>
            </p>
            <p>Debug: detail=<?php echo $detail ? 'oui' : 'non'; ?>, statut_slug=<?php echo esc_html($detail->statut_slug ?? 'null'); ?>, detail_ok=<?php echo $detail_ok ? 'oui' : 'non'; ?></p>
        <?php endif; ?>
    <?php endif; ?>

</div>

<script>
function seliweb_pub_rubriques(cat_id) {
    var opts = document.querySelectorAll('#sel_rubrique option[data-categorie]');
    opts.forEach(function(o) {
        o.style.display = (!cat_id || o.dataset.categorie == cat_id) ? '' : 'none';
    });
    document.getElementById('sel_rubrique').value = '';
}
function seliweb_pub_type(cat_id) {
    var catSel = document.querySelector('[name="categorie_id"]');
    var selOpt = catSel ? catSel.options[catSel.selectedIndex] : null;
    var isAnnonces = selOpt && selOpt.dataset.slug === 'annonces';
    document.getElementById('sel_type').style.display = (cat_id && isAnnonces) ? '' : 'none';
}
</script>
