<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fonctions frontend partagées entre le plugin et le thème.
 *
 * Ce fichier contient tout le code qui accède aux données du plugin
 * (tables DB, classes Seliweb_*) mais produit du HTML destiné au thème.
 * Il est chargé par le plugin afin que le thème n'ait plus à embarquer
 * de logique métier dans son functions.php.
 *
 * Le thème peut personnaliser le mode d'affichage via le filtre :
 *   add_filter( 'seliweb_display_mode', 'swv_display_mode' );
 */

// ================================================================
// PAGINATION : nombre d'annonces par page (lu depuis la table plugin)
// ================================================================
if ( ! function_exists( 'swv_per_page' ) ) {
    function swv_per_page() {
        global $wpdb;
        $val = $wpdb->get_var( "SELECT valeur FROM {$wpdb->prefix}seliweb_parametres WHERE cle='annonces_par_page' LIMIT 1" );
        return ( $val !== null && intval( $val ) > 0 ) ? intval( $val ) : 12;
    }
}

// ================================================================
// FILTRE MENU PRINCIPAL
// — Masque Connexion et Inscription en permanence
// — Masque Mon Compte si non connecté
// ================================================================
if ( ! function_exists( 'swv_filter_primary_menu' ) ) {
    function swv_filter_primary_menu( $items, $args ) {
        if ( ! isset( $args->theme_location ) || $args->theme_location !== 'primary' ) {
            return $items;
        }
        global $wpdb;

        $login_id = (int) $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type='page'
               AND post_content LIKE '%seliweb_login%' LIMIT 1"
        );
        $compte_id = (int) $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type='page'
               AND post_content LIKE '%seliweb_mon_compte%' LIMIT 1"
        );
        $inscription    = get_page_by_path( 'inscription-sel' );
        $inscription_id = $inscription ? (int) $inscription->ID : 0;
        $logged_in      = is_user_logged_in();

        foreach ( $items as $key => $item ) {
            $page_id = (int) $item->object_id;
            if ( $login_id       && $page_id === $login_id )       { unset( $items[$key] ); continue; }
            if ( $inscription_id && $page_id === $inscription_id )  { unset( $items[$key] ); continue; }
            if ( $compte_id      && $page_id === $compte_id && ! $logged_in ) { unset( $items[$key] ); }
        }

        return $items;
    }
    add_filter( 'wp_nav_menu_objects', 'swv_filter_primary_menu', 10, 2 );
}

// ================================================================
// URL DE LA PAGE ANNONCES
// ================================================================
if ( ! function_exists( 'swv_annonces_page_url' ) ) {
    function swv_annonces_page_url() {
        static $url = null;
        if ( $url ) return $url;

        // Référence fiable, déjà utilisée par le reste du plugin (création
        // des pages, migration du modèle) — l'ID est toujours enregistré ici
        // à la création de la page.
        $page_ids = get_option( 'seliweb_page_ids', array() );
        $page_id  = ! empty( $page_ids['seliweb_annonces'] ) ? intval( $page_ids['seliweb_annonces'] ) : 0;

        // Repli : recherche par modèle de page assigné (installations plus
        // anciennes que l'option seliweb_page_ids).
        if ( ! $page_id ) {
            global $wpdb;
            $page_id = (int) $wpdb->get_var(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_status='publish' AND p.post_type='page'
                   AND pm.meta_key='_wp_page_template'
                   AND pm.meta_value='template-annonces.php'
                 LIMIT 1"
            );
        }

        // Dernier repli : ancien système par shortcode dans le contenu
        // (avant le passage au modèle de page dédié).
        if ( ! $page_id ) {
            global $wpdb;
            $page_id = (int) $wpdb->get_var(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_status='publish' AND post_type='page'
                   AND post_content LIKE '%seliweb_annonces%' LIMIT 1"
            );
        }

        $url = $page_id ? get_permalink( $page_id ) : home_url('/');
        return $url;
    }
}

// ================================================================
// URL DE LA PAGE DE CONNEXION (page contenant [seliweb_login])
// ================================================================
if ( ! function_exists( 'swv_login_page_url' ) ) {
    function swv_login_page_url( $redirect = '' ) {
        global $wpdb;
        $id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type='page'
             AND post_content LIKE '%seliweb_login%' LIMIT 1"
        );
        $base = $id ? get_permalink( $id ) : wp_login_url();
        return $redirect ? add_query_arg( 'redirect_to', urlencode( $redirect ), $base ) : $base;
    }
}

// ================================================================
// URL D'UNE PAGE DE PAGINATION
// ================================================================
if ( ! function_exists( 'swv_page_url' ) ) {
    function swv_page_url( $num ) {
        $params = $_GET;
        unset( $params['seliweb_annonce'] );
        $params['sel_page'] = $num;
        return add_query_arg( array_map( 'urlencode', $params ), swv_annonces_page_url() );
    }
}

// ================================================================
// RENDU PAGINATION
//
// La barre du haut est rendue directement (position fixe par rapport
// à la liste des annonces).
//
// La barre du bas est rendue via l'action 'seliweb_pagination_bottom' :
// un thème tiers peut supprimer le handler par défaut et appeler
// do_action('seliweb_pagination_bottom', $page, $nb, $total) à
// l'endroit de son choix dans ses propres templates.
//
//   Exemple dans functions.php d'un thème tiers :
//   remove_action( 'seliweb_pagination_bottom', '_swv_render_pagination_bottom' );
//   // puis dans le template, à l'endroit voulu :
//   do_action( 'seliweb_pagination_bottom', $page_courante, $nb_pages, $total );
// ================================================================
if ( ! function_exists( 'swv_render_pagination' ) ) {
    function swv_render_pagination( $page_courante, $nb_pages, $total, $top = false ) {
        if ( $total < 1 ) return;

        if ( ! $top ) {
            if ( $nb_pages < 2 ) return;
            do_action( 'seliweb_pagination_bottom', $page_courante, $nb_pages, $total );
            return;
        }

        // ---- Barre du HAUT (avec vue-toggle et JS) ----
        ?>
        <div class="swv-pagination-bar swv-pagination-bar-top">
            <div class="swv-pagination-bar-inner">

                <span class="swv-page-info">
                    <?php printf(
                        esc_html( _n('%d annonce','%d annonces',$total,'seliweb') ),
                        $total
                    ); ?>
                    &nbsp;&mdash;&nbsp;
                    <?php printf( esc_html__('Page %1$d / %2$d','seliweb'), $page_courante, $nb_pages ); ?>
                </span>

                <div class="swv-bar-controls">
                    <div class="swv-vue-toggle">
                        <button type="button" id="swv-vue-liste" class="swv-vue-btn" title="<?php esc_attr_e('Vue liste','seliweb'); ?>">
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><rect x="0" y="1" width="16" height="2" rx="1"/><rect x="0" y="7" width="16" height="2" rx="1"/><rect x="0" y="13" width="16" height="2" rx="1"/></svg>
                            <?php esc_html_e('Liste','seliweb'); ?>
                        </button>
                        <button type="button" id="swv-vue-grille" class="swv-vue-btn" title="<?php esc_attr_e('Vue colonnes','seliweb'); ?>">
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><rect x="0" y="0" width="7" height="7" rx="1"/><rect x="9" y="0" width="7" height="7" rx="1"/><rect x="0" y="9" width="7" height="7" rx="1"/><rect x="9" y="9" width="7" height="7" rx="1"/></svg>
                            <?php esc_html_e('Colonnes','seliweb'); ?>
                        </button>
                    </div>

                    <nav class="swv-pages-nav">
                        <?php if ( $page_courante > 1 ) : ?>
                            <a href="<?php echo esc_url(swv_page_url($page_courante-1)); ?>" class="swv-page-prev">&laquo; <?php esc_html_e('Préc.','seliweb'); ?></a>
                        <?php else : ?>
                            <span class="swv-page-prev disabled">&laquo; <?php esc_html_e('Préc.','seliweb'); ?></span>
                        <?php endif; ?>

                        <?php if ( $page_courante < $nb_pages ) : ?>
                            <a href="<?php echo esc_url(swv_page_url($page_courante+1)); ?>" class="swv-page-next"><?php esc_html_e('Suiv.','seliweb'); ?> &raquo;</a>
                        <?php else : ?>
                            <span class="swv-page-next disabled"><?php esc_html_e('Suiv.','seliweb'); ?> &raquo;</span>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var KEY = 'seliweb_vue';
            var wrap = document.querySelector('#swv-annonces > div[class*="swv-annonces-"]');
            var btnL = document.getElementById('swv-vue-liste');
            var btnG = document.getElementById('swv-vue-grille');
            if (!wrap || !btnL || !btnG) return;

            function apply(vue) {
                wrap.className = wrap.className.replace(/swv-annonces-\w+/, 'swv-annonces-' + vue);
                btnG.classList.toggle('swv-vue-btn-actif', vue === 'grille');
                btnL.classList.toggle('swv-vue-btn-actif', vue === 'liste');
                try { localStorage.setItem(KEY, vue); } catch(e) {}
            }

            var pref;
            try { pref = localStorage.getItem(KEY); } catch(e) {}
            if (!pref) pref = wrap.className.indexOf('grille') !== -1 ? 'grille' : 'liste';
            apply(pref);

            btnL.addEventListener('click', function(){ apply('liste'); });
            btnG.addEventListener('click', function(){ apply('grille'); });
        });
        </script>
        <?php
    }
}

// Rendu par défaut de la barre du bas — hookable via 'seliweb_pagination_bottom'
if ( ! function_exists( '_swv_render_pagination_bottom' ) ) {
    function _swv_render_pagination_bottom( $page_courante, $nb_pages, $total ) {
        ?>
        <div id="swv-pagination-bottom" class="swv-pagination-bar swv-pagination-bar-bottom">
            <div class="swv-pagination-bar-inner">

                <span class="swv-page-info">
                    <?php printf(
                        esc_html( _n('%d annonce','%d annonces',$total,'seliweb') ),
                        $total
                    ); ?>
                    &nbsp;&mdash;&nbsp;
                    <?php printf( esc_html__('Page %1$d / %2$d','seliweb'), $page_courante, $nb_pages ); ?>
                </span>

                <div class="swv-bar-controls">
                    <nav class="swv-pages-nav">
                        <?php if ( $page_courante > 1 ) : ?>
                            <a href="<?php echo esc_url(swv_page_url($page_courante-1)); ?>" class="swv-page-prev">&laquo; <?php esc_html_e('Préc.','seliweb'); ?></a>
                        <?php else : ?>
                            <span class="swv-page-prev disabled">&laquo; <?php esc_html_e('Préc.','seliweb'); ?></span>
                        <?php endif; ?>

                        <?php
                        $shown = array();
                        for ($i=1; $i<=$nb_pages; $i++) {
                            if ($i===1 || $i===$nb_pages || ($i>=$page_courante-2 && $i<=$page_courante+2)) $shown[]=$i;
                        }
                        $prev=null;
                        foreach($shown as $n):
                            if ($prev!==null && $n>$prev+1) echo '<span class="swv-page-ellipsis">&hellip;</span>';
                            if ($n===$page_courante): ?>
                                <span class="swv-page-num current"><?php echo $n; ?></span>
                            <?php else: ?>
                                <a href="<?php echo esc_url(swv_page_url($n)); ?>" class="swv-page-num"><?php echo $n; ?></a>
                            <?php endif;
                            $prev=$n;
                        endforeach; ?>

                        <?php if ( $page_courante < $nb_pages ) : ?>
                            <a href="<?php echo esc_url(swv_page_url($page_courante+1)); ?>" class="swv-page-next"><?php esc_html_e('Suiv.','seliweb'); ?> &raquo;</a>
                        <?php else : ?>
                            <span class="swv-page-next disabled"><?php esc_html_e('Suiv.','seliweb'); ?> &raquo;</span>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        </div>
        <?php
    }
    add_action( 'seliweb_pagination_bottom', '_swv_render_pagination_bottom', 10, 3 );
}

// ================================================================
// RENDU FORMULAIRE DE RECHERCHE
// ================================================================
if ( ! function_exists( 'swv_render_search' ) ) {
    function swv_render_search( $filters = array() ) {
        if ( ! class_exists('Seliweb_Annonces') ) return;

        global $wpdb;
        $tc = $wpdb->prefix . 'seliweb_categories';
        $tr = $wpdb->prefix . 'seliweb_rubriques';

        $categories = $wpdb->get_results("SELECT * FROM $tc ORDER BY nom ASC");
        $rubriques  = $wpdb->get_results("SELECT * FROM $tr ORDER BY categorie_id, nom ASC");
        $villes     = Seliweb_Annonces::get_villes();
        $page_url   = swv_annonces_page_url();
        ?>
        <div id="swv-search">
            <div class="swv-search-inner">
                <form method="get" action="<?php echo esc_url($page_url); ?>" class="swv-search-form">

                    <select name="categorie_id"
                            onchange="swvRubUpdate(this.value); swvTypeUpdate(this.value)">
                        <option value=""><?php esc_html_e('Toutes catégories','seliweb'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo intval($cat->id); ?>"
                                    data-slug="<?php echo esc_attr($cat->slug); ?>"
                                    <?php selected($filters['categorie_id']??0, $cat->id); ?>>
                                <?php echo esc_html($cat->nom); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="type_annonce" id="swv-sel-type"
                            style="<?php echo !empty($filters['categorie_id']) ? '' : 'display:none'; ?>">
                        <option value=""><?php esc_html_e('Offres & demandes','seliweb'); ?></option>
                        <option value="offre"   <?php selected($filters['type_annonce']??'','offre'); ?>><?php esc_html_e('Offres','seliweb'); ?></option>
                        <option value="demande" <?php selected($filters['type_annonce']??'','demande'); ?>><?php esc_html_e('Demandes','seliweb'); ?></option>
                    </select>

                    <select name="rubrique_id" id="swv-sel-rub">
                        <option value=""><?php esc_html_e('Toutes rubriques','seliweb'); ?></option>
                        <?php foreach ($rubriques as $rub): ?>
                            <option value="<?php echo intval($rub->id); ?>"
                                    data-categorie="<?php echo intval($rub->categorie_id); ?>"
                                    style="<?php echo (empty($filters['categorie_id']) || $rub->categorie_id==$filters['categorie_id']) ? '' : 'display:none'; ?>"
                                    <?php selected($filters['rubrique_id']??0, $rub->id); ?>>
                                <?php echo esc_html($rub->nom); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if (!empty($villes)): ?>
                    <select name="ville">
                        <option value=""><?php esc_html_e('Toutes villes','seliweb'); ?></option>
                        <?php foreach ($villes as $ville): ?>
                            <option value="<?php echo esc_attr($ville); ?>"
                                    <?php selected($filters['ville']??'', $ville); ?>>
                                <?php echo esc_html($ville); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <button type="submit" class="swv-search-btn"
                            title="<?php esc_attr_e('Cliquer pour valider la recherche','seliweb'); ?>">
                        <?php esc_html_e('Rechercher','seliweb'); ?>
                    </button>
                    <button type="button" class="swv-reset-btn"
                            onclick="window.location=<?php echo esc_attr( wp_json_encode( $page_url ) ); ?>">
                        <?php esc_html_e('Réinitialiser','seliweb'); ?>
                    </button>

                </form>
            </div>
        </div>
        <script>
        function swvRubUpdate(catId){
            document.querySelectorAll('#swv-sel-rub option[data-categorie]').forEach(function(o){
                o.style.display=(!catId||o.dataset.categorie==catId)?'':'none';
            });
            document.getElementById('swv-sel-rub').value='';
        }
        function swvTypeUpdate(catId){
            var sel=document.querySelector('[name="categorie_id"]');
            var opt=sel?sel.options[sel.selectedIndex]:null;
            var isA=opt&&opt.dataset.slug==='annonces';
            document.getElementById('swv-sel-type').style.display=(catId&&isA)?'':'none';
        }
        </script>
        <?php
    }
}

// ================================================================
// RENDU CARTE ANNONCE
// Le mode d'affichage est récupéré via le filtre 'seliweb_display_mode'
// afin que le thème puisse le surcharger sans que le plugin le connaisse.
// ================================================================
if ( ! function_exists( 'swv_render_card' ) ) {
    function swv_render_card( $annonce, $mode = null ) {
        if ( ! class_exists('Seliweb_Annonces') ) return;
        if ( $mode === null ) $mode = apply_filters( 'seliweb_display_mode', 'grille' );

        $prix       = Seliweb_Annonces::get_prix( $annonce->id );
        $has_statut = ( ! empty( $annonce->statut_slug ) && $annonce->statut_slug !== 'expire' );
        $url        = add_query_arg( 'seliweb_annonce', $annonce->id, swv_annonces_page_url() );
        $date       = date_i18n( get_option('date_format'), strtotime( $annonce->date_creation ) );
        // Photo choisie par le membre, sinon image de la rubrique
        $img_url    = $annonce->photo_affichee ?: $annonce->rub_image;

        if ( $mode === 'grille' ) : ?>
            <div class="swv-card">
                <div class="swv-card-photo">
                    <?php if ($img_url): ?>
                        <a href="<?php echo esc_url($url); ?>">
                            <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($annonce->titre); ?>">
                        </a>
                    <?php else: ?>
                        <div class="swv-card-no-photo">&#128247;</div>
                    <?php endif; ?>
                </div>
                <div class="swv-card-body">
                    <div class="swv-card-id">#<?php echo intval($annonce->id); ?></div>
                    <div class="swv-card-title"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($annonce->titre); ?></a></div>
                    <div class="swv-card-date"><?php echo esc_html__('Publié le','seliweb') . ' ' . esc_html($date); ?></div>
                    <?php if ($has_statut): ?><span class="swv-card-statut"><?php echo esc_html($annonce->statut_nom); ?></span><?php endif; ?>
                    <div class="swv-card-prix">
                        <?php if ($annonce->est_don): ?>
                            <span class="swv-card-don"><?php esc_html_e('Don','seliweb'); ?></span>
                        <?php elseif (!empty($prix)): ?>
                            <?php foreach($prix as $idx_p => $p): ?>
                                <?php if ($idx_p > 0): ?>
                                    <span class="swv-card-prix-coord"><?php echo esc_html($p->coordination ?: 'OU'); ?></span>
                                <?php endif; ?>
                                <span class="swv-card-prix-item"><?php echo esc_html($p->prix.' '.($p->symbole?:$p->nom)); ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php else: // liste ?>
            <div class="swv-card">
                <div class="swv-card-photo">
                    <?php if ($img_url): ?>
                        <a href="<?php echo esc_url($url); ?>">
                            <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($annonce->titre); ?>">
                        </a>
                    <?php else: ?>
                        <div class="swv-card-no-photo"></div>
                    <?php endif; ?>
                </div>
                <div class="swv-card-body">
                    <div class="swv-card-id">#<?php echo intval($annonce->id); ?></div>
                    <div class="swv-card-title"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($annonce->titre); ?></a></div>
                    <div class="swv-card-tags">
                        <span class="swv-tag swv-tag-cat"><?php echo esc_html($annonce->cat_nom); ?></span>
                        <?php if ($annonce->cat_slug==='annonces' && $annonce->type_annonce): ?>
                            <span class="swv-tag swv-tag-type"><?php echo esc_html(ucfirst($annonce->type_annonce)); ?></span>
                        <?php endif; ?>
                        <?php if ($annonce->rub_nom): ?>
                            <span class="swv-tag swv-tag-rubrique"><?php echo esc_html($annonce->rub_nom); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="swv-card-date"><?php echo esc_html__('Publié le','seliweb') . ' ' . esc_html($date); ?></div>
                    <?php if ($has_statut): ?><span class="swv-card-statut"><?php echo esc_html($annonce->statut_nom); ?></span><?php endif; ?>
                    <div class="swv-card-prix">
                        <?php if ($annonce->est_don): ?>
                            <span class="swv-card-don"><?php esc_html_e('Don','seliweb'); ?></span>
                        <?php elseif (!empty($prix)): ?>
                            <?php foreach($prix as $idx_p => $p): ?>
                                <?php if ($idx_p > 0): ?>
                                    <span class="swv-card-prix-coord"><?php echo esc_html($p->coordination ?: 'OU'); ?></span>
                                <?php endif; ?>
                                <span class="swv-card-prix-item"><?php echo esc_html($p->prix.' '.($p->symbole?:$p->nom)); ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif;
    }
}

// ================================================================
// MODÈLE DE PAGE "ANNONCES SEL" — fourni par le plugin
//
// La mise en page des annonces est désormais indépendante du thème actif :
// le plugin propose et fournit son propre modèle de page (templates/page-annonces.php),
// au lieu de dépendre d'un fichier template-annonces.php présent dans le thème.
//
// On réutilise volontairement la même clé ('template-annonces.php') qu'utilisait
// l'ancien fichier du thème, afin que les pages déjà assignées à ce modèle
// continuent de fonctionner sans ré-affectation manuelle dans l'admin.
// ================================================================
if ( ! function_exists( 'swv_register_page_template' ) ) {
    function swv_register_page_template( $templates ) {
        $templates['template-annonces.php'] = __( 'Annonces SEL', 'seliweb' );
        // Variante "groupe SEL uniquement" — seulement proposée si le SEL est
        // actif, sans intérêt sinon.
        if ( class_exists( 'Seliweb_Transactions' ) && Seliweb_Transactions::sel_actif() ) {
            $templates['template-annonces-sel.php'] = __( 'Annonces — groupe SEL uniquement', 'seliweb' );
        }
        return $templates;
    }
    add_filter( 'theme_page_templates', 'swv_register_page_template' );
}

if ( ! function_exists( 'swv_load_page_template' ) ) {
    function swv_load_page_template( $template ) {
        $slug = get_page_template_slug();
        if ( is_page() && in_array( $slug, array( 'template-annonces.php', 'template-annonces-sel.php' ), true ) ) {
            $plugin_template = SELIWEB_DIR . 'templates/page-annonces.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }
        return $template;
    }
    add_filter( 'template_include', 'swv_load_page_template' );
}
