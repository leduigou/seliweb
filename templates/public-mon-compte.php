<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Redirection si non connecté
if ( ! is_user_logged_in() ) {
    if ( is_admin() || ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) ) {
        echo '<p><em>' . esc_html__( 'Espace membre Seliweb — connexion requise.', 'seliweb' ) . '</em></p>';
        return;
    }
    wp_safe_redirect( wp_login_url( get_permalink() ) );
    exit;
}

global $wpdb;
$ta   = $wpdb->prefix . 'seliweb_annonces';
$tc   = $wpdb->prefix . 'seliweb_categories';
$tr   = $wpdb->prefix . 'seliweb_rubriques';
$ts   = $wpdb->prefix . 'seliweb_statuts';
$tm   = $wpdb->prefix . 'seliweb_membres';
$tg   = $wpdb->prefix . 'seliweb_groupes';
$tgm  = $wpdb->prefix . 'seliweb_groupes_monnaies';
$tmon = $wpdb->prefix . 'seliweb_monnaies';
$tap  = $wpdb->prefix . 'seliweb_annonces_prix';

$wp_user_id = get_current_user_id();
$page_url   = get_permalink();

// Récupérer ou créer le membre
$membre = $wpdb->get_row( $wpdb->prepare(
    "SELECT m.*, g.limite_annonces FROM $tm m LEFT JOIN $tg g ON g.id=m.groupe_id WHERE m.wp_user_id=%d LIMIT 1",
    $wp_user_id
) );
if ( ! $membre ) {
    $wpdb->insert( $tm, array( 'wp_user_id' => $wp_user_id ) );
    $membre = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tm WHERE wp_user_id=%d", $wp_user_id ) );
}

// Données WP du membre (nom, prénom, organisme gérés par WordPress)
$_wp_user_data     = get_userdata( $wp_user_id );
$membre_prenom     = $_wp_user_data ? (string) $_wp_user_data->first_name : '';
$membre_nom        = $_wp_user_data ? (string) $_wp_user_data->last_name  : '';
$membre_organisme  = (string) get_user_meta( $wp_user_id, 'seliweb_organisme', true );
$membre_photo_id   = (int) get_user_meta( $wp_user_id, 'seliweb_photo_id', true );

// Paramètres SEL (utilisés dans plusieurs onglets)
$tp_sel        = $wpdb->prefix . 'seliweb_parametres';
$sel_actif     = (bool) $wpdb->get_var( "SELECT valeur FROM $tp_sel WHERE cle='sel_actif' LIMIT 1" );
$sel_gid       = (int)  $wpdb->get_var( "SELECT valeur FROM $tp_sel WHERE cle='sel_groupe_id' LIMIT 1" );
$is_sel_membre = $sel_actif && $sel_gid > 0 && (int) $membre->groupe_id === $sel_gid;

// Monnaies autorisées par le groupe
$monnaies_dispo = array();
if ( $membre->groupe_id ) {
    $monnaies_dispo = $wpdb->get_results( $wpdb->prepare(
        "SELECT mo.* FROM $tmon mo INNER JOIN $tgm gm ON gm.monnaie_id=mo.id WHERE gm.groupe_id=%d ORDER BY mo.nom ASC",
        $membre->groupe_id
    ) );
}
if ( empty( $monnaies_dispo ) ) {
    $monnaies_dispo = $wpdb->get_results( "SELECT * FROM $tmon ORDER BY nom ASC" );
}

$categories = $wpdb->get_results( "SELECT * FROM $tc ORDER BY nom ASC" );
$rubriques  = $wpdb->get_results( "SELECT * FROM $tr ORDER BY categorie_id, nom ASC" );
$statuts    = $wpdb->get_results( "SELECT * FROM $ts ORDER BY id ASC" );

$action     = isset( $_GET['sel_action'] ) ? sanitize_key( $_GET['sel_action'] ) : 'liste';
$annonce_id = isset( $_GET['sel_id'] )     ? intval( $_GET['sel_id'] )           : 0;

// Mes annonces
$mes_annonces       = $wpdb->get_results( $wpdb->prepare(
    "SELECT a.*, c.nom AS cat_nom, r.nom AS rub_nom, s.nom AS statut_nom, s.slug AS statut_slug
     FROM $ta a
     LEFT JOIN $tc c ON c.id=a.categorie_id
     LEFT JOIN $tr r ON r.id=a.rubrique_id
     LEFT JOIN {$wpdb->prefix}seliweb_statuts s ON s.id=a.statut_id
     WHERE a.membre_id=%d ORDER BY a.date_creation DESC", $membre->id
) );
$nb_annonces_membre = count( $mes_annonces );
$limite             = (int) ( $membre->limite_annonces ?? 0 );
?>

<div class="seliweb-wrap seliweb-compte">

    <?php if ( isset( $_GET['sel_saved'] ) ) : ?>
        <div class="seliweb-notice seliweb-notice-ok"><?php esc_html_e( 'Annonce enregistrée.', 'seliweb' ); ?></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_warn_expire'] ) ) : ?>
        <div class="seliweb-notice" style="background:#fff8e1;border-left:4px solid #f0ad00;padding:10px 14px;border-radius:4px;margin-bottom:12px;color:#7a5800;">
            <?php esc_html_e( "Cette annonce ne sera pas visible car le statut est Expiré.", 'seliweb' ); ?>
        </div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_error'] ) && $_GET['sel_error'] === 'no_rubrique' ) : ?>
        <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:12px;color:#b32d2e;">
            <?php esc_html_e( 'Vous devez choisir une rubrique.', 'seliweb' ); ?>
        </div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_error'] ) && $_GET['sel_error'] === 'bad_date' ) : ?>
        <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:12px;color:#b32d2e;">
            <?php esc_html_e( "Veuillez corriger la date d'expiration ou laisser le champ vide.", 'seliweb' ); ?>
        </div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_error'] ) && $_GET['sel_error'] === 'no_photo1' ) : ?>
        <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:12px;color:#b32d2e;">
            <?php esc_html_e( 'La photo 1 est obligatoire.', 'seliweb' ); ?>
        </div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_error'] ) && $_GET['sel_error'] === 'no_photo2' ) : ?>
        <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:12px;color:#b32d2e;">
            <?php esc_html_e( 'Les photos 1 et 2 sont obligatoires.', 'seliweb' ); ?>
        </div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_error'] ) && $_GET['sel_error'] === 'photo_bad_format' ) : ?>
        <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:12px;color:#b32d2e;">
            <?php esc_html_e( "Format d'image non pris en charge. Formats acceptés : JPG, PNG, GIF, WEBP.", 'seliweb' ); ?>
        </div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_error'] ) && $_GET['sel_error'] === 'photo_too_large' ) : ?>
        <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:12px;color:#b32d2e;">
            <?php esc_html_e( 'Le fichier est trop volumineux (5 Mo maximum).', 'seliweb' ); ?>
        </div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_error'] ) && $_GET['sel_error'] === 'photo_upload_error' ) : ?>
        <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:12px;color:#b32d2e;">
            <?php esc_html_e( "Erreur lors de l'envoi de l'image, merci de réessayer.", 'seliweb' ); ?>
        </div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_deleted'] ) ) : ?>
        <div class="seliweb-notice seliweb-notice-ok"><?php esc_html_e( 'Annonce supprimée.', 'seliweb' ); ?></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_saved_profil'] ) ) : ?>
        <div class="seliweb-notice seliweb-notice-ok"><?php esc_html_e( 'Profil mis à jour.', 'seliweb' ); ?></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_limite'] ) ) : ?>
        <div class="seliweb-notice seliweb-notice-warn">
            <?php printf( esc_html__( 'Limite atteinte : votre groupe autorise %d annonce(s) maximum.', 'seliweb' ), $limite ); ?>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['sel_saved_prefs'] ) ) : ?>
        <div class="seliweb-notice seliweb-notice-ok"><?php esc_html_e( 'Préférences enregistrées.', 'seliweb' ); ?></div>
    <?php endif; ?>

    <!-- Onglets -->
    <div class="seliweb-compte-tabs">
        <a href="<?php echo esc_url( $page_url ); ?>"
           class="seliweb-tab <?php echo in_array($action,array('liste','creer','modifier')) ? 'seliweb-tab-active' : ''; ?>">
            <?php esc_html_e( 'Mes annonces', 'seliweb' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg('sel_action','profil',$page_url) ); ?>"
           class="seliweb-tab <?php echo $action==='profil' ? 'seliweb-tab-active' : ''; ?>">
            <?php esc_html_e( 'Mon profil', 'seliweb' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg('sel_action','prefs',$page_url) ); ?>"
           class="seliweb-tab <?php echo $action==='prefs' ? 'seliweb-tab-active' : ''; ?>">
            <?php esc_html_e( 'Préférences', 'seliweb' ); ?>
        </a>
        <?php if ( $is_sel_membre ) : ?>
        <a href="<?php echo esc_url( add_query_arg('sel_action','transactions',$page_url) ); ?>"
           class="seliweb-tab <?php echo in_array($action,array('transactions','creer_transaction','confirmer_transaction')) ? 'seliweb-tab-active' : ''; ?>">
            <?php esc_html_e( 'Transactions', 'seliweb' ); ?>
        </a>
        <?php endif; ?>
        <?php if ( class_exists('Seliweb_Cotisations') && Seliweb_Cotisations::cotisations_actif() ) : ?>
        <a href="<?php echo esc_url( add_query_arg('sel_action','cotisations',$page_url) ); ?>"
           class="seliweb-tab <?php echo $action==='cotisations' ? 'seliweb-tab-active' : ''; ?>">
            <?php esc_html_e( 'Cotisations', 'seliweb' ); ?>
        </a>
        <?php endif; ?>
    </div>

    <?php if ( in_array( $action, array( 'liste', 'creer', 'modifier' ) ) ) : ?>

        <?php if ( $action === 'liste' ) : ?>

            <div class="seliweb-compte-toolbar">
                <span class="seliweb-limite-info">
                    <?php printf(
                        esc_html__( 'Total annonce(s) : %d', 'seliweb' ),
                        $nb_annonces_membre
                    );
                    if ( $limite > 0 ) {
                        echo ' / ' . $limite;
                    } ?>
                </span>

                <?php if ( $limite === 0 || $nb_annonces_membre < $limite ) : ?>
                    <a href="<?php echo esc_url( add_query_arg('sel_action','creer',$page_url) ); ?>"
                       class="seliweb-btn">
                        <?php esc_html_e( '+ Créer une annonce', 'seliweb' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ( empty( $mes_annonces ) ) : ?>
                <p class="seliweb-empty"><?php esc_html_e( "Vous n'avez pas encore d'annonce.", 'seliweb' ); ?></p>
            <?php else : ?>
            <table class="seliweb-table">
                <thead><tr>
                    <th style="width:50px;">ID</th>
                    <th><?php esc_html_e( 'Titre', 'seliweb' ); ?></th>
                    <th><?php esc_html_e( 'Catégorie', 'seliweb' ); ?></th>
                    <th><?php esc_html_e( 'Rubrique', 'seliweb' ); ?></th>
                    <th><?php esc_html_e( 'Créée le', 'seliweb' ); ?></th>
                    <th><?php esc_html_e( 'Statut', 'seliweb' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $mes_annonces as $a ) : ?>
                    <tr>
                        <td>#<?php echo intval( $a->id ); ?></td>
                        <td><?php echo esc_html( $a->titre ); ?></td>
                        <td><?php echo esc_html( $a->cat_nom ); ?></td>
                        <td><?php echo esc_html( $a->rub_nom ?: '—' ); ?></td>
                        <td><?php echo esc_html( date_i18n( get_option('date_format'), strtotime($a->date_creation) ) ); ?></td>
                        <td>
                            <?php if ( $a->statut_nom ) :
                                $bold = in_array($a->statut_slug, array('urgent','repondu','expire'));
                            ?>
                                <span <?php echo $bold ? 'style="font-weight:700;"' : ''; ?>>
                                    <?php echo esc_html($a->statut_nom); ?>
                                </span>
                            <?php else : ?>
                                <span style="color:#aaa;"><?php esc_html_e('Aucun','seliweb'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="seliweb-table-actions">
                            <a href="<?php echo esc_url( add_query_arg(array('sel_action'=>'modifier','sel_id'=>$a->id),$page_url) ); ?>"
                               class="seliweb-action-btn" title="<?php esc_attr_e('Modifier','seliweb'); ?>">&#9998;</a>
                            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg(array('sel_action'=>'supprimer','sel_id'=>$a->id),$page_url), 'seliweb_suppr_'.$a->id ) ); ?>"
                               class="seliweb-action-btn seliweb-action-delete"
                               title="<?php esc_attr_e('Supprimer','seliweb'); ?>"
                               onclick="return confirm(<?php echo wp_json_encode(__('Supprimer cette annonce ?','seliweb')); ?>)">&#10005;</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

        <?php else : // creer ou modifier ?>

            <?php
            $edit_annonce   = null;
            $prix_existants = array();
            $is_modif       = false;

            if ( $action === 'modifier' && $annonce_id ) {
                $edit_annonce = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM $ta WHERE id=%d AND membre_id=%d", $annonce_id, $membre->id
                ) );
                if ( $edit_annonce ) {
                    foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tap WHERE annonce_id=%d", $annonce_id ) ) as $p ) {
                        $prix_existants[ $p->monnaie_id ] = $p->prix;
                    }
                    $is_modif = true;
                }
            }

            // Lignes de prix : existantes ou 1 ligne vide
            $prix_lignes = ! empty( $prix_existants ) ? $prix_existants : array( '' => '' );

            $tp_mc          = $wpdb->prefix . 'seliweb_parametres';
            $photos_min_raw = $wpdb->get_var( "SELECT valeur FROM $tp_mc WHERE cle='annonces_photos_min' LIMIT 1" );
            $photos_min     = $photos_min_raw !== null ? max( 0, min( 2, (int) $photos_min_raw ) ) : 1;
            ?>

            <div class="seliweb-compte-toolbar">
                <a href="<?php echo esc_url($page_url); ?>" class="seliweb-btn seliweb-btn-secondary">
                    &larr; <?php esc_html_e('Retour à mes annonces','seliweb'); ?>
                </a>
            </div>

            <h3><?php echo $is_modif ? esc_html__("Modifier l'annonce",'seliweb') : esc_html__('Créer une annonce','seliweb'); ?></h3>

            <?php if ( $is_modif ) : ?>
            <div class="seliweb-notice seliweb-notice-info">
                <label>
                    <input type="checkbox" name="notifier_membres" form="seliweb-form-annonce" value="1">
                    <?php esc_html_e('Notifier les membres par mail de cette modification','seliweb'); ?>
                </label>
            </div>
            <?php endif; ?>

            <!-- Le formulaire pointe vers seliweb.php qui gère l'upload via init -->
            <form id="seliweb-form-annonce" method="post"
                  action="<?php echo esc_url($page_url); ?>"
                  enctype="multipart/form-data"
                  class="seliweb-form">
                <?php wp_nonce_field('seliweb_annonce_membre','seliweb_nonce_annonce'); ?>
                <input type="hidden" name="annonce_id" value="<?php echo $is_modif ? intval($edit_annonce->id) : 0; ?>">

                <div class="seliweb-field">
                    <label><?php esc_html_e('Titre','seliweb'); ?> *</label>
                    <input type="text" name="titre" class="seliweb-input"
                           value="<?php echo $is_modif ? esc_attr($edit_annonce->titre) : ''; ?>" required>
                </div>

                <div class="seliweb-field">
                    <label><?php esc_html_e('Catégorie','seliweb'); ?> *</label>
                    <select name="categorie_id" class="seliweb-select" required
                            onchange="selMCRub(this.value); selMCType(this.value)">
                        <option value=""><?php esc_html_e('— Choisir —','seliweb'); ?></option>
                        <?php foreach ($categories as $cat) : ?>
                            <option value="<?php echo intval($cat->id); ?>"
                                    data-slug="<?php echo esc_attr($cat->slug); ?>"
                                    <?php selected($is_modif ? $edit_annonce->categorie_id : 0, $cat->id); ?>>
                                <?php echo esc_html($cat->nom); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="seliweb-field" id="mc_field_type"
                     style="<?php echo ($is_modif && $edit_annonce->type_annonce) ? '' : 'display:none'; ?>">
                    <label><?php esc_html_e('Type','seliweb'); ?></label>
                    <div class="seliweb-radio-group">
                        <label><input type="radio" name="type_annonce" value="offre"
                                      <?php checked($is_modif ? $edit_annonce->type_annonce : '', 'offre'); ?>>
                            <?php esc_html_e('Offre','seliweb'); ?></label>
                        <label><input type="radio" name="type_annonce" value="demande"
                                      <?php checked($is_modif ? $edit_annonce->type_annonce : '', 'demande'); ?>>
                            <?php esc_html_e('Demande','seliweb'); ?></label>
                    </div>
                </div>

                <div class="seliweb-field">
                    <label><?php esc_html_e('Rubrique','seliweb'); ?></label>
                    <select name="rubrique_id" id="sel_rub_mc" class="seliweb-select">
                        <option value=""><?php esc_html_e('— Choisir —','seliweb'); ?></option>
                        <?php foreach ($rubriques as $rub) : ?>
                            <option value="<?php echo intval($rub->id); ?>"
                                    data-categorie="<?php echo intval($rub->categorie_id); ?>"
                                    <?php selected($is_modif ? $edit_annonce->rubrique_id : 0, $rub->id); ?>>
                                <?php echo esc_html($rub->nom); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="seliweb-field">
                    <label><?php esc_html_e('Texte','seliweb'); ?> <span class="seliweb-hint">(1000 car. max)</span></label>
                    <textarea name="texte" class="seliweb-textarea" rows="6"
                              maxlength="1000"><?php echo $is_modif ? esc_textarea($edit_annonce->texte) : ''; ?></textarea>
                    <span class="seliweb-counter" id="mc_counter"></span>
                </div>

                <div class="seliweb-field">
                    <label><?php esc_html_e('Statut','seliweb'); ?></label>
                    <select name="statut_id" class="seliweb-select">
                        <option value=""><?php esc_html_e('— Aucun —','seliweb'); ?></option>
                        <?php foreach ($statuts as $st) : ?>
                            <option value="<?php echo intval($st->id); ?>"
                                    <?php selected($is_modif ? $edit_annonce->statut_id : 0, $st->id); ?>>
                                <?php echo esc_html($st->nom); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="seliweb-field">
                    <label><?php esc_html_e("Date d'expiration",'seliweb'); ?></label>
                    <input type="date" name="date_expiration" class="seliweb-input"
                           value="<?php echo $is_modif ? esc_attr($edit_annonce->date_expiration) : ''; ?>">
                    <p class="seliweb-hint"><?php esc_html_e('Ne pas remplir si annonce permanente', 'seliweb'); ?></p>
                </div>

                <!-- Don -->
                <div class="seliweb-field">
                    <label>
                        <input type="checkbox" name="est_don" value="1" id="mc_est_don"
                               onchange="selMCTogglePrix(this.checked)"
                               <?php checked($is_modif ? $edit_annonce->est_don : 0); ?>>
                        <?php esc_html_e("Je fais un don (le prix ne sera pas affiché)",'seliweb'); ?>
                    </label>
                </div>

                <!-- PRIX — même logique que backend : montant + select monnaie -->
                <div class="seliweb-field" id="mc_field_prix"
                     <?php echo ($is_modif && $edit_annonce->est_don) ? 'style="display:none"' : ''; ?>>
                    <label><?php esc_html_e('Prix','seliweb'); ?></label>
                    <div id="mc_prix_container">
                        <?php
                        $mc_coord_map = array();
                        if ( $is_modif && $annonce_id ) {
                            foreach ( $wpdb->get_results( $wpdb->prepare(
                                "SELECT monnaie_id, coordination FROM {$wpdb->prefix}seliweb_annonces_prix WHERE annonce_id=%d ORDER BY id ASC", $annonce_id
                            ) ) as $pc ) {
                                $mc_coord_map[$pc->monnaie_id] = $pc->coordination;
                            }
                        }
                        $mc_n = 0;
                        foreach ($prix_lignes as $mon_id => $montant) :
                            $mc_coord = $mc_coord_map[$mon_id] ?? 'OU';
                        ?>
                        <div class="seliweb-prix-row" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                            <?php if ( $mc_n === 0 ) : ?>
                                <span style="display:inline-block;width:65px;"></span>
                            <?php else : ?>
                                <select name="prix[<?php echo $mc_n; ?>][coordination]" style="width:65px;">
                                    <option value="OU" <?php selected($mc_coord,'OU'); ?>>OU</option>
                                    <option value="ET" <?php selected($mc_coord,'ET'); ?>>ET</option>
                                </select>
                            <?php endif; ?>
                            <input type="number" name="prix[<?php echo $mc_n; ?>][montant]"
                                   value="<?php echo esc_attr($montant); ?>"
                                   min="1" step="1" class="seliweb-input seliweb-prix-input"
                                   placeholder="<?php esc_attr_e('Montant','seliweb'); ?>">
                            <select name="prix[<?php echo $mc_n; ?>][monnaie_id]" class="seliweb-select mc-prix-select" style="max-width:200px;">
                                <option value=""><?php esc_html_e('— Monnaie —','seliweb'); ?></option>
                                <?php foreach ($monnaies_dispo as $mon) : ?>
                                    <option value="<?php echo intval($mon->id); ?>"
                                            <?php selected(intval($mon->id), intval($mon_id)); ?>>
                                        <?php echo esc_html($mon->nom.($mon->symbole?' ('.$mon->symbole.')':'')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm"
                                    onclick="this.closest('.seliweb-prix-row').remove()">✕</button>
                        </div>
                        <?php $mc_n++; endforeach; ?>
                    </div>
                    <?php if (count($monnaies_dispo) > 1) : ?>
                    <button type="button" class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm"
                            onclick="selMCAddPrix()">
                        <?php esc_html_e('+ Ajouter une monnaie','seliweb'); ?>
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Photos -->
                <div class="seliweb-field">
                    <label><?php esc_html_e('Photo 1','seliweb'); ?> <?php echo ( ! $is_modif && $photos_min >= 1 ) ? '*' : ''; ?></label>
                    <input type="file" name="photo1" accept="image/jpeg,image/png,image/gif,image/webp"
                           class="seliweb-file" <?php echo ( ! $is_modif && $photos_min >= 1 ) ? 'required' : ''; ?>>
                    <p class="seliweb-hint"><?php esc_html_e('Formats acceptés : JPG, PNG, GIF, WEBP — 5 Mo maximum.', 'seliweb'); ?></p>
                    <?php if ($is_modif && $edit_annonce->photo1) : ?>
                        <img src="<?php echo esc_url($edit_annonce->photo1); ?>"
                             class="seliweb-photo-preview" alt="">
                    <?php elseif ( ! $is_modif && $photos_min >= 1 ) : ?>
                        <span class="seliweb-hint"><?php esc_html_e('Obligatoire','seliweb'); ?></span>
                    <?php endif; ?>
                </div>

                <div class="seliweb-field">
                    <label><?php esc_html_e('Photo 2','seliweb'); ?> <?php echo ( ! $is_modif && $photos_min >= 2 ) ? '*' : ''; ?></label>
                    <input type="file" name="photo2" accept="image/jpeg,image/png,image/gif,image/webp" class="seliweb-file"
                           <?php echo ( ! $is_modif && $photos_min >= 2 ) ? 'required' : ''; ?>>
                    <p class="seliweb-hint"><?php esc_html_e('Formats acceptés : JPG, PNG, GIF, WEBP — 5 Mo maximum.', 'seliweb'); ?></p>
                    <?php if ($is_modif && $edit_annonce->photo2) : ?>
                        <img src="<?php echo esc_url($edit_annonce->photo2); ?>"
                             class="seliweb-photo-preview" alt="">
                    <?php elseif ( ! $is_modif && $photos_min >= 2 ) : ?>
                        <span class="seliweb-hint"><?php esc_html_e('Obligatoire','seliweb'); ?></span>
                    <?php endif; ?>
                </div>

                <div class="seliweb-form-footer">
                    <button type="submit" class="seliweb-btn">
                        <?php echo $is_modif ? esc_html__('Mettre à jour','seliweb') : esc_html__("Publier l'annonce",'seliweb'); ?>
                    </button>
                    <a href="<?php echo esc_url($page_url); ?>" class="seliweb-btn seliweb-btn-secondary">
                        <?php esc_html_e('Annuler','seliweb'); ?>
                    </a>
                </div>
            </form>

            <div id="seliweb-saving-overlay" class="seliweb-saving-overlay" style="display:none;" aria-live="assertive">
                <div class="seliweb-saving-box">
                    <div class="seliweb-saving-spinner"></div>
                    <?php esc_html_e('Enregistrement en cours…','seliweb'); ?>
                </div>
            </div>

        <?php endif; ?>

    <?php elseif ( $action === 'profil' ) : ?>

        <?php
        // Récupérer les données d'inscription existantes
        $ti = $wpdb->prefix . 'seliweb_inscriptions';
        $inscription = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $ti WHERE wp_user_id=%d LIMIT 1", $wp_user_id
        ) );
        ?>

    <h3><?php esc_html_e('Mon profil','seliweb'); ?></h3>
    <form method="post" action="<?php echo esc_url($page_url); ?>" style="max-width:560px;" enctype="multipart/form-data">
        <?php wp_nonce_field('seliweb_profil_'.$wp_user_id,'seliweb_nonce_profil'); ?>
        <style>
        .sel-prf-table { width:100%; border-collapse:collapse; }
        .sel-prf-table td { padding:5px 8px 5px 0; vertical-align:middle; }
        .sel-prf-table td:first-child { width:165px; text-align:right; padding-right:14px; font-size:14px; font-weight:500; color:#333; white-space:nowrap; }
        .sel-prf-table input[type="text"],
        .sel-prf-table input[type="email"],
        .sel-prf-table input[type="tel"] { width:100%; padding:7px 10px; border:1px solid #ccc; border-radius:4px; font-size:14px; box-sizing:border-box; }
        .sel-prf-radio { display:flex; gap:20px; align-items:center; padding-top:2px; }
        .sel-prf-radio label { display:flex; align-items:center; gap:6px; font-size:14px; cursor:pointer; }
        .sel-prf-sep td { padding-top:14px; border-top:1px solid #e0e0e0; font-weight:600; color:#1d6a4a; font-size:13px; text-transform:uppercase; letter-spacing:.04em; }
        .sel-prf-photo { display:flex; align-items:center; gap:14px; }
        .sel-prf-photo img { width:80px; height:80px; object-fit:cover; border-radius:50%; border:2px solid #ddd; }
        .sel-prf-photo-placeholder { width:80px; height:80px; border-radius:50%; background:#e8f0eb; border:2px solid #ddd; display:flex; align-items:center; justify-content:center; font-size:32px; color:#1d6a4a; }
        </style>

        <table class="sel-prf-table">
            <!-- Photo -->
            <tr class="sel-prf-sep"><td colspan="2"><?php esc_html_e('Photo','seliweb'); ?></td></tr>
            <tr>
                <td><?php esc_html_e('Photo de profil','seliweb'); ?></td>
                <td>
                    <div class="sel-prf-photo">
                        <?php if ( $membre_photo_id ) : ?>
                            <?php echo wp_get_attachment_image( $membre_photo_id, array(80,80), false, array('style'=>'width:80px;height:80px;object-fit:cover;border-radius:50%;border:2px solid #ddd;') ); ?>
                        <?php else : ?>
                            <div class="sel-prf-photo-placeholder">&#128100;</div>
                        <?php endif; ?>
                        <div>
                            <input type="file" name="seliweb_photo" accept="image/*" style="font-size:13px;">
                            <p style="margin:4px 0 0;font-size:12px;color:#666;"><?php esc_html_e('JPG, PNG, WebP — max 2 Mo','seliweb'); ?></p>
                            <?php if ( $membre_photo_id ) : ?>
                                <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:13px;cursor:pointer;color:#b32d2e;">
                                    <input type="checkbox" name="delete_photo" value="1">
                                    <?php esc_html_e('Supprimer la photo','seliweb'); ?>
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
            </tr>

            <!-- Identité -->
            <tr class="sel-prf-sep"><td colspan="2"><?php esc_html_e('Identité','seliweb'); ?></td></tr>
            <tr>
                <td><?php esc_html_e('Civilité','seliweb'); ?></td>
                <td>
                    <div class="sel-prf-radio">
                        <label><input type="radio" name="civilite" value="Mr" <?php checked($membre->civilite??'','Mr'); ?>> <?php esc_html_e('M.','seliweb'); ?></label>
                        <label><input type="radio" name="civilite" value="Mme" <?php checked($membre->civilite??'','Mme'); ?>> <?php esc_html_e('Mme','seliweb'); ?></label>
                    </div>
                </td>
            </tr>
            <tr>
                <td><?php esc_html_e('Nom','seliweb'); ?></td>
                <td><input type="text" name="nom" value="<?php echo esc_attr($membre_nom); ?>"></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Prénom','seliweb'); ?></td>
                <td><input type="text" name="prenom" value="<?php echo esc_attr($membre_prenom); ?>"></td>
            </tr>
            <tr>
                <td><?php esc_html_e("Organisme",'seliweb'); ?></td>
                <td><input type="text" name="organisme" value="<?php echo esc_attr($membre_organisme); ?>"></td>
            </tr>

            <!-- Contact -->
            <tr class="sel-prf-sep"><td colspan="2"><?php esc_html_e('Contact','seliweb'); ?></td></tr>
            <tr>
                <td><?php esc_html_e('Tél. portable','seliweb'); ?></td>
                <td><input type="tel" name="tel_portable" value="<?php echo esc_attr($membre->tel_portable??''); ?>"></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Tél. fixe','seliweb'); ?></td>
                <td><input type="tel" name="tel_fixe" value="<?php echo esc_attr($membre->tel_fixe??''); ?>"></td>
            </tr>
            <tr>
                <td><?php esc_html_e('E-mail','seliweb'); ?></td>
                <td><input type="email" name="user_email" value="<?php echo esc_attr(get_userdata($wp_user_id)->user_email??''); ?>" autocomplete="email"></td>
            </tr>

            <!-- Adresse -->
            <tr class="sel-prf-sep"><td colspan="2"><?php esc_html_e('Adresse','seliweb'); ?></td></tr>
            <tr>
                <td><?php esc_html_e('Adresse 1','seliweb'); ?></td>
                <td><input type="text" name="adresse1" value="<?php echo esc_attr($membre->adresse1??''); ?>"></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Adresse 2','seliweb'); ?></td>
                <td><input type="text" name="adresse2" value="<?php echo esc_attr($membre->adresse2??''); ?>"></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Ville','seliweb'); ?></td>
                <td><input type="text" name="ville" value="<?php echo esc_attr($membre->ville??''); ?>"></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Code postal','seliweb'); ?></td>
                <td><input type="text" name="code_postal" maxlength="10" value="<?php echo esc_attr($membre->code_postal??''); ?>"></td>
            </tr>

            <!-- Mot de passe -->
            <tr>
                <td style="width:165px;text-align:right;padding-right:14px;font-size:14px;font-weight:500;color:#333;padding-top:5px;">
                    <?php esc_html_e('Mot de passe','seliweb'); ?>
                </td>
                <td style="padding:5px 0;">
                    <button type="button" id="mc_toggle_pwd" class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm"
                            onclick="mcTogglePwd()" style="font-size:13px;">
                        🔑 <?php esc_html_e('Modifier le mot de passe','seliweb'); ?>
                    </button>
                    <div id="mc_pwd_block" style="display:none;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;padding:12px;margin-top:8px;">
                        <p style="margin:0 0 8px;font-size:13px;color:#555;">
                            <?php esc_html_e('Saisissez un nouveau mot de passe (6 caractères minimum).','seliweb'); ?>
                        </p>
                        <div style="margin-bottom:8px;">
                            <label style="display:block;font-size:13px;margin-bottom:4px;"><?php esc_html_e('Nouveau mot de passe','seliweb'); ?></label>
                            <input type="password" name="new_password" id="mc_new_pwd"
                                   class="seliweb-input" minlength="6" autocomplete="new-password"
                                   style="max-width:280px;">
                        </div>
                        <div style="margin-bottom:8px;">
                            <label style="display:block;font-size:13px;margin-bottom:4px;"><?php esc_html_e('Confirmation','seliweb'); ?></label>
                            <input type="password" name="new_password_confirm" id="mc_conf_pwd"
                                   class="seliweb-input" autocomplete="new-password"
                                   style="max-width:280px;" oninput="mcCheckPwd()">
                            <span id="mc_pwd_msg" style="display:block;font-size:12px;color:#b32d2e;margin-top:3px;"></span>
                        </div>
                        <button type="button" class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm"
                                onclick="mcCancelPwd()" style="color:#b32d2e;">
                            <?php esc_html_e('Annuler','seliweb'); ?>
                        </button>
                    </div>
                </td>
            </tr>

            <tr>
                <td></td>
                <td style="padding-top:16px;border-top:1px solid #e0e0e0;">
                    <button type="submit" class="seliweb-btn"><?php esc_html_e('Enregistrer','seliweb'); ?></button>
                </td>
            </tr>
        </table>
    </form>

    <?php elseif ( $action === 'prefs' ) : ?>

    <?php
    $nom_groupe = $membre->groupe_id
        ? $wpdb->get_var( $wpdb->prepare( "SELECT nom FROM $tg WHERE id=%d", $membre->groupe_id ) )
        : '';
    ?>

    <h3><?php esc_html_e( 'Préférences', 'seliweb' ); ?></h3>

    <?php if ( $nom_groupe ) : ?>
        <p style="margin-bottom:16px;">
            <?php esc_html_e( 'Groupe :', 'seliweb' ); ?>
            <span class="seliweb-tag" style="margin-left:6px;"><?php echo esc_html( $nom_groupe ); ?></span>
            <?php if ( $sel_gid > 0 && (int)$membre->groupe_id === $sel_gid && !empty($membre->numero_sel) ) : ?>
                <span style="margin-left:10px;font-size:13px;color:#555;font-weight:600;">
                    <?php printf( esc_html__( 'N° %d', 'seliweb' ), intval( $membre->numero_sel ) ); ?>
                </span>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( $page_url ); ?>" style="max-width:560px;">
        <?php wp_nonce_field( 'seliweb_prefs_' . $wp_user_id, 'seliweb_nonce_prefs' ); ?>
        <style>
        .sel-prf-pref-row { display:flex; flex-direction:column; gap:4px; margin-bottom:14px; }
        .sel-prf-pref-row label { display:flex; align-items:flex-start; gap:8px; font-size:14px; cursor:pointer; }
        .sel-prf-pref-row label input[type="checkbox"] { margin-top:2px; flex-shrink:0; }
        .sel-prf-pref-hint { font-size:12px; color:#888; font-style:italic; margin-left:24px; }
        </style>

        <fieldset style="border:1px solid #e0e0e0;border-radius:6px;padding:14px 18px;margin-bottom:18px;">
            <legend style="font-weight:600;font-size:13px;color:#1d6a4a;text-transform:uppercase;letter-spacing:.04em;padding:0 6px;">
                <?php esc_html_e( 'Notifications', 'seliweb' ); ?>
            </legend>
            <div class="sel-prf-pref-row" style="margin-top:8px;">
                <label>
                    <input type="checkbox" name="notif_annonces" value="1" <?php checked( $membre->notif_annonces ?? 1 ); ?>>
                    <?php esc_html_e( 'Recevoir un mail à chaque nouvelle annonce', 'seliweb' ); ?>
                </label>
            </div>
        </fieldset>

        <fieldset style="border:1px solid #e0e0e0;border-radius:6px;padding:14px 18px;margin-bottom:18px;">
            <legend style="font-weight:600;font-size:13px;color:#1d6a4a;text-transform:uppercase;letter-spacing:.04em;padding:0 6px;">
                <?php esc_html_e( 'Confidentialité', 'seliweb' ); ?>
            </legend>

            <div class="sel-prf-pref-row" style="margin-top:8px;">
                <label>
                    <input type="checkbox" name="show_email" value="1" <?php checked( $membre->show_email ?? 1 ); ?>>
                    <?php esc_html_e( 'Autoriser à montrer mon e-mail', 'seliweb' ); ?>
                </label>
                <span class="sel-prf-pref-hint"><?php esc_html_e( 'Si la case est décochée, vous recevrez quand même les mails qui vous sont destinés.', 'seliweb' ); ?></span>
            </div>

            <div class="sel-prf-pref-row">
                <label>
                    <input type="checkbox" name="show_tel_portable" value="1" <?php checked( $membre->show_tel_portable ?? 1 ); ?>>
                    <?php esc_html_e( 'Autoriser à montrer mon tél. portable', 'seliweb' ); ?>
                </label>
            </div>

            <div class="sel-prf-pref-row">
                <label>
                    <input type="checkbox" name="show_tel_fixe" value="1" <?php checked( $membre->show_tel_fixe ?? 1 ); ?>>
                    <?php esc_html_e( 'Autoriser à montrer mon tél. fixe', 'seliweb' ); ?>
                </label>
            </div>

            <div class="sel-prf-pref-row">
                <label>
                    <input type="checkbox" name="show_adresse" value="1" <?php checked( $membre->show_adresse ?? 1 ); ?>>
                    <?php esc_html_e( 'Autoriser à montrer mon organisme', 'seliweb' ); ?>
                </label>
                <span class="sel-prf-pref-hint"><?php esc_html_e( 'Si la case est décochée, l\'organisme ne sera pas affiché.', 'seliweb' ); ?></span>
            </div>
        </fieldset>

        <button type="submit" class="seliweb-btn"><?php esc_html_e( 'Enregistrer', 'seliweb' ); ?></button>
    </form>

    <?php elseif ( in_array( $action, array( 'transactions', 'creer_transaction', 'confirmer_transaction' ) ) ) : ?>

    <?php if ( ! $is_sel_membre ) : ?>
        <p><em><?php esc_html_e( 'Accès non autorisé.', 'seliweb' ); ?></em></p>
    <?php else : ?>

    <?php
    $sel_info    = Seliweb_Transactions::get_sel_info();
    $monnaie_sel = $sel_info['monnaie_id'] ? Seliweb_Transactions::get_monnaie( $sel_info['monnaie_id'] ) : null;
    $symbole_txn = $monnaie_sel ? ( $monnaie_sel->symbole ?: $monnaie_sel->nom ) : '';
    $nom_monnaie = $monnaie_sel ? $monnaie_sel->nom : __( 'unités', 'seliweb' );
    $balance_sel = Seliweb_Transactions::get_balance( $membre->id );

    $te_t = $wpdb->prefix . 'seliweb_ecritures';
    $tt_t = $wpdb->prefix . 'seliweb_transactions';
    $tm_t = $wpdb->prefix . 'seliweb_membres';

    $nb_txn = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT transaction_id) FROM $te_t WHERE membre_id=%d", $membre->id
    ) );

    $par_page = 20;
    $page_num = max( 1, intval( $_GET['sel_txn_page'] ?? 1 ) );
    $offset   = ( $page_num - 1 ) * $par_page;
    $nb_pages = max( 1, (int) ceil( $nb_txn / $par_page ) );

    $txn_ids = array();
    if ( $nb_txn > 0 ) {
        $txn_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT transaction_id FROM $te_t WHERE membre_id=%d ORDER BY transaction_id DESC LIMIT %d OFFSET %d",
            $membre->id, $par_page, $offset
        ) );
    }

    $ecritures_txn = array();
    if ( ! empty( $txn_ids ) ) {
        $ids_ph = implode( ',', array_map( 'intval', $txn_ids ) );
        $my_id  = intval( $membre->id );
        // Une seule ligne par transaction : l'écriture du membre courant + nom de la contrepartie
        $ecritures_txn = $wpdb->get_results(
            "SELECT e_me.transaction_id AS txn_id, t.date, t.libelle, t.montant, e_me.type AS my_type,
                    m_ctr.numero_sel AS ctr_numero,
                    um_fn_ctr.meta_value AS ctr_prenom, um_ln_ctr.meta_value AS ctr_nom
             FROM $te_t e_me
             JOIN $tt_t t ON t.id = e_me.transaction_id
             LEFT JOIN $te_t e_ctr ON e_ctr.transaction_id = e_me.transaction_id AND e_ctr.membre_id != $my_id
             LEFT JOIN $tm_t m_ctr ON m_ctr.id = e_ctr.membre_id
             LEFT JOIN {$wpdb->users} u_ctr ON u_ctr.ID = m_ctr.wp_user_id
             LEFT JOIN {$wpdb->usermeta} um_fn_ctr ON um_fn_ctr.user_id = u_ctr.ID AND um_fn_ctr.meta_key = 'first_name'
             LEFT JOIN {$wpdb->usermeta} um_ln_ctr ON um_ln_ctr.user_id = u_ctr.ID AND um_ln_ctr.meta_key = 'last_name'
             WHERE e_me.membre_id = $my_id AND e_me.transaction_id IN ($ids_ph)
             ORDER BY t.id DESC"
        );
    }

    $txn_url   = add_query_arg( 'sel_action', 'transactions', $page_url );
    $creer_url = add_query_arg( 'sel_action', 'creer_transaction', $page_url );
    ?>

    <?php if ( isset( $_GET['sel_txn_added'] ) ) : ?>
        <div class="seliweb-notice seliweb-notice-ok"><?php esc_html_e( 'Transaction enregistrée.', 'seliweb' ); ?></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['sel_txn_err'] ) ) : ?>
        <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:12px;color:#b32d2e;">
            <?php echo esc_html( rawurldecode( $_GET['sel_txn_err'] ) ); ?>
        </div>
    <?php endif; ?>

    <!-- Solde + découvert -->
    <style>
    .sel-txn-info { width:100%; max-width:420px; border-collapse:collapse; margin-top:12px; margin-bottom:20px; }
    .sel-txn-info td { padding:6px 8px 6px 0; vertical-align:middle; font-size:14px; }
    .sel-txn-info td:first-child { width:200px; text-align:right; padding-right:14px; font-weight:500; color:#333; white-space:nowrap; }
    </style>
    <table class="sel-txn-info">
        <tr>
            <td><?php esc_html_e( 'Solde actuel', 'seliweb' ); ?></td>
            <td>
                <strong style="color:<?php echo $balance_sel >= 0 ? '#27ae60' : '#c0392b'; ?>;">
                    <?php echo intval( $balance_sel ) . ( $symbole_txn ? ' ' . esc_html( $symbole_txn ) : '' ); ?>
                </strong>
            </td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Découvert max autorisé', 'seliweb' ); ?></td>
            <td>
                <?php
                $dec_val = $membre->decouvert_max !== null
                    ? intval( $membre->decouvert_max )
                    : ( $sel_info['decouvert_possible'] ? intval( $sel_info['decouvert_max'] ) : null );
                ?>
                <?php if ( $dec_val !== null ) : ?>
                    <strong><?php echo $dec_val . ( $symbole_txn ? ' ' . esc_html( $symbole_txn ) : '' ); ?></strong>
                <?php else : ?>
                    <em style="color:#888;"><?php esc_html_e( 'Aucun', 'seliweb' ); ?></em>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php if ( $action === 'transactions' ) : ?>

        <!-- Toolbar -->
        <div class="seliweb-compte-toolbar">
            <span class="seliweb-limite-info">
                <?php printf( esc_html__( 'Total transaction(s) : %d', 'seliweb' ), $nb_txn ); ?>
            </span>
            <?php if ( ! Seliweb_Transactions::is_compte_sel( $membre->id ) ) : ?>
            <a href="<?php echo esc_url( $creer_url ); ?>" class="seliweb-btn">
                <?php esc_html_e( '+ Créer une transaction', 'seliweb' ); ?>
            </a>
            <?php endif; ?>
        </div>

        <!-- Tableau -->
        <?php if ( empty( $ecritures_txn ) ) : ?>
            <p class="seliweb-empty"><?php esc_html_e( "Vous n'avez pas encore de transaction.", 'seliweb' ); ?></p>
        <?php else : ?>
        <div style="overflow-x:auto;">
        <table class="seliweb-table">
            <thead><tr>
                <th style="width:50px;">ID</th>
                <th style="width:88px;"><?php esc_html_e( 'Date', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Libellé', 'seliweb' ); ?></th>
                <th style="width:76px;text-align:right;"><?php esc_html_e( 'Débit', 'seliweb' ); ?></th>
                <th style="width:76px;text-align:right;"><?php esc_html_e( 'Crédit', 'seliweb' ); ?></th>
                <th style="width:60px;text-align:center;"><?php esc_html_e( 'N°', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Contrepartie', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $ecritures_txn as $e_row ) :
                $is_debit    = ( $e_row->my_type === 'debit' );
                $ctr_label   = intval( $e_row->ctr_numero ) === 1
                    ? __( 'Compte du SEL', 'seliweb' )
                    : trim( ( $e_row->ctr_prenom ?? '' ) . ' ' . ( $e_row->ctr_nom ?? '' ) );
                $montant_fmt = intval( $e_row->montant ) . ( $symbole_txn ? ' ' . $symbole_txn : '' );
            ?>
            <tr>
                <td><?php echo '#' . intval( $e_row->txn_id ); ?></td>
                <td><?php echo esc_html( date_i18n( get_option('date_format'), strtotime( $e_row->date ) ) ); ?></td>
                <td><?php echo esc_html( $e_row->libelle ); ?></td>
                <td style="text-align:right;">
                    <?php if ( $is_debit ) : ?>
                        <span style="color:#c0392b;font-weight:600;"><?php echo esc_html( $montant_fmt ); ?></span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;">
                    <?php if ( ! $is_debit ) : ?>
                        <span style="color:#27ae60;font-weight:600;"><?php echo esc_html( $montant_fmt ); ?></span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;"><?php echo esc_html( $e_row->ctr_numero ); ?></td>
                <td><?php echo esc_html( $ctr_label ); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Pagination -->
        <?php if ( $nb_pages > 1 ) : ?>
        <div style="display:flex;gap:8px;margin-top:16px;align-items:center;">
            <?php if ( $page_num > 1 ) : ?>
                <a href="<?php echo esc_url( add_query_arg( array( 'sel_action' => 'transactions', 'sel_txn_page' => $page_num - 1 ), $page_url ) ); ?>"
                   class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm">
                    &larr; <?php esc_html_e( 'Précédente', 'seliweb' ); ?>
                </a>
            <?php else : ?>
                <span class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm" style="opacity:.4;cursor:default;">&larr; <?php esc_html_e( 'Précédente', 'seliweb' ); ?></span>
            <?php endif; ?>
            <span style="font-size:14px;color:#555;">
                <?php printf( esc_html__( 'Page %d / %d', 'seliweb' ), $page_num, $nb_pages ); ?>
            </span>
            <?php if ( $page_num < $nb_pages ) : ?>
                <a href="<?php echo esc_url( add_query_arg( array( 'sel_action' => 'transactions', 'sel_txn_page' => $page_num + 1 ), $page_url ) ); ?>"
                   class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm">
                    <?php esc_html_e( 'Suivante', 'seliweb' ); ?> &rarr;
                </a>
            <?php else : ?>
                <span class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm" style="opacity:.4;cursor:default;"><?php esc_html_e( 'Suivante', 'seliweb' ); ?> &rarr;</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; // empty ecritures_txn ?>

    <?php elseif ( $action === 'creer_transaction' ) : ?>

        <div class="seliweb-compte-toolbar">
            <a href="<?php echo esc_url( $txn_url ); ?>" class="seliweb-btn seliweb-btn-secondary">
                &larr; <?php esc_html_e( 'Retour aux transactions', 'seliweb' ); ?>
            </a>
        </div>

        <h3><?php esc_html_e( 'Créer une transaction', 'seliweb' ); ?></h3>

        <?php
        $membres_sel    = Seliweb_Transactions::get_sel_membres( $sel_info['groupe_id'] );
        $membres_credit = array_values( array_filter( $membres_sel, function( $m ) use ( $membre ) {
            return intval( $m->numero_sel ) !== 1 && intval( $m->id ) !== intval( $membre->id );
        } ) );
        $ac_credit = array_map( function( $m ) {
            return array( 'id' => intval( $m->id ), 'label' => Seliweb_Transactions::membre_label( $m ) );
        }, $membres_credit );
        ?>

        <p style="margin-bottom:20px;font-size:15px;">
            <?php printf(
                esc_html__( 'A quel membre, voulez-vous virer des %s ?', 'seliweb' ),
                '<strong>' . esc_html( $nom_monnaie ) . '</strong>'
            ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( $page_url ); ?>" style="max-width:480px;" class="seliweb-form">
            <?php wp_nonce_field( 'seliweb_txn_creer_' . $wp_user_id, 'seliweb_nonce_txn_creer' ); ?>

            <div class="seliweb-field">
                <label><?php esc_html_e( 'Membre à créditer', 'seliweb' ); ?> *</label>
                <div class="swv-ac-wrap" id="swv_wrap_fe_credit" data-value="" data-label=""></div>
            </div>

            <div class="seliweb-field">
                <label>
                    <?php esc_html_e( 'Montant', 'seliweb' ); ?> *
                    <?php if ( $symbole_txn ) : ?>
                        <span class="seliweb-hint">(<?php echo esc_html( $symbole_txn ); ?>)</span>
                    <?php endif; ?>
                </label>
                <input type="number" name="montant" class="seliweb-input" min="1" step="1"
                       style="max-width:150px;" required>
            </div>

            <div class="seliweb-field">
                <label><?php esc_html_e( 'Libellé', 'seliweb' ); ?> *</label>
                <input type="text" name="libelle" class="seliweb-input" required>
            </div>

            <div class="seliweb-field">
                <label><?php esc_html_e( 'Date', 'seliweb' ); ?> *</label>
                <input type="date" name="date" class="seliweb-input"
                       value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>"
                       style="max-width:180px;" required>
            </div>

            <div class="seliweb-form-footer">
                <button type="submit" class="seliweb-btn"><?php esc_html_e( 'Valider', 'seliweb' ); ?></button>
                <a href="<?php echo esc_url( $txn_url ); ?>" class="seliweb-btn seliweb-btn-secondary">
                    <?php esc_html_e( 'Annuler', 'seliweb' ); ?>
                </a>
            </div>
        </form>
        <style>
        .swv-ac-wrap{position:relative;display:block;width:100%;}
        .swv-ac-input{width:100%;padding:8px 12px;font-size:15px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box;font-family:inherit;}
        .swv-ac-input:focus{border-color:#1d6a4a;outline:none;box-shadow:0 0 0 2px rgba(29,106,74,.15);}
        .swv-ac-list{position:absolute;top:100%;left:0;right:0;z-index:9999;background:#fff;border:1px solid #ccc;border-top:0;border-radius:0 0 6px 6px;list-style:none;margin:0;padding:0;max-height:220px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.12);}
        .swv-ac-list li{padding:9px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid #f0f0f0;}
        .swv-ac-list li:hover{background:#e0ede7;}
        </style>
        <script>
        (function() {
            function swvAc(wrapId, hiddenName, items, placeholder) {
                var wrap = document.getElementById(wrapId);
                if (!wrap) return;
                var txt = document.createElement('input');
                txt.type = 'text'; txt.placeholder = placeholder || ''; txt.autocomplete = 'off'; txt.className = 'swv-ac-input';
                var hid = document.createElement('input');
                hid.type = 'hidden'; hid.name = hiddenName; hid.value = wrap.dataset.value || '';
                var list = document.createElement('ul');
                list.className = 'swv-ac-list'; list.hidden = true;
                wrap.appendChild(txt); wrap.appendChild(hid); wrap.appendChild(list);
                function render(matches) {
                    list.innerHTML = '';
                    if (!matches.length) { list.hidden = true; return; }
                    matches.slice(0, 15).forEach(function(item) {
                        var li = document.createElement('li');
                        li.textContent = item.label;
                        li.addEventListener('mousedown', function(e) { e.preventDefault(); txt.value = item.label; hid.value = item.id; list.hidden = true; });
                        list.appendChild(li);
                    });
                    list.hidden = false;
                }
                txt.addEventListener('input', function() {
                    hid.value = '';
                    var q = this.value.toLowerCase().trim();
                    if (!q) { list.hidden = true; return; }
                    render(items.filter(function(i) { return i.label.toLowerCase().indexOf(q) !== -1; }));
                });
                txt.addEventListener('focus', function() {
                    var q = this.value.toLowerCase().trim();
                    if (q) render(items.filter(function(i) { return i.label.toLowerCase().indexOf(q) !== -1; }));
                });
                txt.addEventListener('blur', function() { setTimeout(function() { list.hidden = true; }, 200); });
            }
            swvAc('swv_wrap_fe_credit', 'membre_credit_id',
                <?php echo wp_json_encode( $ac_credit ); ?>,
                '<?php echo esc_js( __( 'Taper un nom ou un N°…', 'seliweb' ) ); ?>'
            );
            document.querySelector('.seliweb-form').addEventListener('submit', function(e) {
                if (!document.querySelector('[name="membre_credit_id"]').value)
                    { e.preventDefault(); alert('<?php echo esc_js( __( 'Veuillez choisir le membre à créditer.', 'seliweb' ) ); ?>'); }
            });
        })();
        </script>

    <?php elseif ( $action === 'confirmer_transaction' ) :

        $pending = get_transient( 'seliweb_pending_txn_' . $wp_user_id );

        if ( ! $pending ) :
            // Transient expiré
            ?>
            <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:12px;color:#b32d2e;">
                <?php esc_html_e( 'Session expirée. Veuillez recommencer.', 'seliweb' ); ?>
            </div>
            <p><a href="<?php echo esc_url( $creer_url ); ?>" class="seliweb-btn seliweb-btn-secondary">
                &larr; <?php esc_html_e( 'Retour au formulaire', 'seliweb' ); ?>
            </a></p>
        <?php else :
            // Récupérer les infos du membre crédité pour l'affichage
            $credit_membre = null;
            $membres_all   = Seliweb_Transactions::get_sel_membres( $sel_info['groupe_id'] );
            foreach ( $membres_all as $m ) {
                if ( intval( $m->id ) === intval( $pending['credit_id'] ) ) {
                    $credit_membre = $m;
                    break;
                }
            }
            $credit_label = $credit_membre
                ? Seliweb_Transactions::membre_label( $credit_membre )
                : '#' . intval( $pending['credit_id'] );
        ?>

        <div class="seliweb-compte-toolbar">
            <a href="<?php echo esc_url( $creer_url ); ?>" class="seliweb-btn seliweb-btn-secondary">
                &larr; <?php esc_html_e( 'Modifier la saisie', 'seliweb' ); ?>
            </a>
        </div>

        <h3><?php esc_html_e( 'Confirmation de la transaction', 'seliweb' ); ?></h3>

        <p style="margin-bottom:16px;color:#555;">
            <?php esc_html_e( 'Veuillez vérifier les informations ci-dessous avant de valider.', 'seliweb' ); ?>
        </p>

        <style>
        .sel-txn-confirm { width:100%; max-width:480px; border-collapse:collapse; margin-bottom:24px; }
        .sel-txn-confirm td { padding:10px 12px; font-size:14px; border-bottom:1px solid #e0e0e0; }
        .sel-txn-confirm td:first-child { width:180px; font-weight:600; color:#555; background:#f9f9f9; }
        .sel-txn-confirm tr:last-child td { border-bottom:none; }
        .sel-txn-confirm-wrap { border:2px solid #2271b1; border-radius:6px; overflow:hidden; max-width:480px; margin-bottom:24px; }
        </style>

        <div class="sel-txn-confirm-wrap">
            <table class="sel-txn-confirm">
                <tr>
                    <td><?php esc_html_e( 'Débité (vous)', 'seliweb' ); ?></td>
                    <td>
                        <?php
                        $debit_nom_full = trim( $membre_prenom . ' ' . $membre_nom );
                        echo '<strong>N°' . intval( $membre->numero_sel )
                            . ( $debit_nom_full ? ' — ' . esc_html( $debit_nom_full ) : '' )
                            . '</strong>';
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Crédité', 'seliweb' ); ?></td>
                    <td><strong><?php echo esc_html( $credit_label ); ?></strong></td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Montant', 'seliweb' ); ?></td>
                    <td>
                        <strong style="font-size:16px;">
                            <?php echo intval( $pending['montant'] ) . ( $symbole_txn ? ' ' . esc_html( $symbole_txn ) : '' ); ?>
                        </strong>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Libellé', 'seliweb' ); ?></td>
                    <td><?php echo esc_html( $pending['libelle'] ); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Date', 'seliweb' ); ?></td>
                    <td><?php echo esc_html( date_i18n( get_option('date_format'), strtotime( $pending['date'] ) ) ); ?></td>
                </tr>
            </table>
        </div>

        <form method="post" action="<?php echo esc_url( $page_url ); ?>">
            <?php wp_nonce_field( 'seliweb_txn_confirmer_' . $wp_user_id, 'seliweb_nonce_txn_confirmer' ); ?>
            <div class="seliweb-form-footer">
                <button type="submit" class="seliweb-btn">
                    <?php esc_html_e( 'Confirmer la transaction', 'seliweb' ); ?>
                </button>
                <a href="<?php echo esc_url( $txn_url ); ?>" class="seliweb-btn seliweb-btn-secondary">
                    <?php esc_html_e( 'Annuler', 'seliweb' ); ?>
                </a>
            </div>
        </form>

        <?php endif; // pending ?>

    <?php endif; // transactions vs creer_transaction vs confirmer_transaction ?>

    <?php endif; // is_sel_membre ?>

    <?php elseif ( $action === 'cotisations' ) : ?>

    <?php
    $tc_cot  = $wpdb->prefix . 'seliweb_cotisations';
    $tc_regl = $wpdb->prefix . 'seliweb_cotisations_reglements';
    $mes_cots = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $tc_cot WHERE wp_user_id=%d AND statut='paye' ORDER BY date_paiement DESC",
        $wp_user_id
    ) );
    ?>

    <h3><?php esc_html_e( 'Mes cotisations', 'seliweb' ); ?></h3>

    <?php if ( empty( $mes_cots ) ) : ?>
        <p class="seliweb-empty"><?php esc_html_e( "Vous n'avez pas encore de cotisation enregistrée.", 'seliweb' ); ?></p>
    <?php else : ?>
    <div style="overflow-x:auto;">
    <table class="seliweb-table">
        <thead><tr>
            <th style="width:90px;"><?php esc_html_e( 'Date', 'seliweb' ); ?></th>
            <th style="width:110px;"><?php esc_html_e( 'Exercice', 'seliweb' ); ?></th>
            <th><?php esc_html_e( 'Libellé', 'seliweb' ); ?></th>
            <th style="width:100px;text-align:right;"><?php esc_html_e( 'Montant', 'seliweb' ); ?></th>
            <th style="width:120px;"><?php esc_html_e( 'Règlement', 'seliweb' ); ?></th>
        </tr></thead>
        <tbody>
        <?php
        $modes = array(
            'especes'   => __( 'Espèces', 'seliweb' ),
            'cheque'    => __( 'Chèque', 'seliweb' ),
            'virement'  => __( 'Virement', 'seliweb' ),
            'helloasso' => __( 'HelloAsso', 'seliweb' ),
        );
        foreach ( $mes_cots as $cot ) :
            $reglements_cot = $wpdb->get_results( $wpdb->prepare(
                "SELECT r.montant, r.mode_paiement, mo.nom AS monnaie_nom, mo.symbole AS monnaie_symbole
                 FROM $tc_regl r
                 LEFT JOIN {$wpdb->prefix}seliweb_monnaies mo ON mo.id = r.monnaie_id
                 WHERE r.cotisation_id = %d ORDER BY r.id ASC",
                $cot->id
            ) );
            $date_fmt  = date_i18n( get_option('date_format'), strtotime( $cot->date_paiement ) );
            $exercice  = $cot->exercice ?: '—';
            $libelle   = $cot->libelle  ?: '—';
            $border    = 'border-top:2px solid #e0e0e0;';

            if ( empty( $reglements_cot ) ) :
        ?>
        <tr>
            <td style="<?php echo $border; ?>"><?php echo esc_html( $date_fmt ); ?></td>
            <td style="<?php echo $border; ?>"><?php echo esc_html( $exercice ); ?></td>
            <td style="<?php echo $border; ?>"><?php echo esc_html( $libelle ); ?></td>
            <td style="<?php echo $border; ?>text-align:right;font-weight:600;">—</td>
            <td style="<?php echo $border; ?>"><em style="color:#aaa;">—</em></td>
        </tr>
        <?php
            else :
                foreach ( $reglements_cot as $i => $rg ) :
                    $symbole_rg = $rg->monnaie_symbole ?: $rg->monnaie_nom;
                    $montant_rg = number_format( $rg->montant / 100, 2, ',', ' ' );
                    $mode_label = $modes[ $rg->mode_paiement ] ?? ucfirst( $rg->mode_paiement );
                    $row_border = ( $i === 0 ) ? $border : '';
        ?>
        <tr>
            <td style="<?php echo $row_border; ?>"><?php echo $i === 0 ? esc_html( $date_fmt ) : ''; ?></td>
            <td style="<?php echo $row_border; ?>"><?php echo $i === 0 ? esc_html( $exercice ) : ''; ?></td>
            <td style="<?php echo $row_border; ?>"><?php echo $i === 0 ? esc_html( $libelle ) : ''; ?></td>
            <td style="<?php echo $row_border; ?>text-align:right;font-weight:600;">
                <?php echo esc_html( $montant_rg . ' ' . $symbole_rg ); ?>
            </td>
            <td style="<?php echo $row_border; ?>"><?php echo esc_html( $mode_label ); ?></td>
        </tr>
        <?php
                endforeach;
            endif;
        endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php  endif; ?>


</div>

<script>
var selMCMonnaies = <?php echo wp_json_encode(array_map(function($m){
    return array('id'=>$m->id,'label'=>$m->nom.($m->symbole?' ('.$m->symbole.')':''));
}, $monnaies_dispo)); ?>;
var prixMCNextIdx = <?php echo isset($prix_lignes) ? count($prix_lignes) : 1; ?>;

function selMCRub(catId){
    var opts=document.querySelectorAll('#sel_rub_mc option[data-categorie]');
    opts.forEach(function(o){ o.style.display=(!catId||o.dataset.categorie==catId)?'':'none'; });
    var sel=document.getElementById('sel_rub_mc');
    if(sel.value){
        var cur=sel.querySelector('option[value="'+sel.value+'"]');
        if(cur&&cur.style.display==='none') sel.value='';
    }
}
function selMCType(catId){
    var sel=document.querySelector('[name="categorie_id"]');
    var opt=sel?sel.options[sel.selectedIndex]:null;
    var isA=opt&&opt.dataset.slug==='annonces';
    document.getElementById('mc_field_type').style.display=(catId&&isA)?'':'none';
}
function selMCTogglePrix(isDon){
    document.getElementById('mc_field_prix').style.display=isDon?'none':'';
}
function selMCUsedIds(){
    var ids=[];
    document.querySelectorAll('#mc_prix_container .mc-prix-select').forEach(function(s){ if(s.value) ids.push(s.value); });
    return ids;
}
function selMCAddPrix(){
    var usedIds=selMCUsedIds();
    var available=selMCMonnaies.filter(function(m){ return usedIds.indexOf(String(m.id))===-1; });
    if(available.length===0){ alert(<?php echo wp_json_encode(__('Toutes les monnaies sont déjà utilisées.','seliweb')); ?>); return; }
    var idx=prixMCNextIdx++;
    var opts='<option value=""><?php esc_attr_e("— Monnaie —","seliweb"); ?></option>';
    selMCMonnaies.forEach(function(m){ opts+='<option value="'+m.id+'">'+m.label+'</option>'; });
    var row=document.createElement('div');
    row.className='seliweb-prix-row';
    row.style.cssText='display:flex;align-items:center;gap:8px;margin-bottom:8px;';
    row.innerHTML='<select name="prix['+idx+'][coordination]" style="width:65px;"><option value="OU">OU</option><option value="ET">ET</option></select>'
                 +'<input type="number" name="prix['+idx+'][montant]" min="1" step="1" class="seliweb-input seliweb-prix-input" placeholder="Montant">'
                 +'<select name="prix['+idx+'][monnaie_id]" class="seliweb-select mc-prix-select" style="max-width:200px;">'+opts+'</select>'
                 +'<button type="button" class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm" onclick="this.closest(\'.seliweb-prix-row\').remove()">✕</button>';
    document.getElementById('mc_prix_container').appendChild(row);
    row.querySelector('.mc-prix-select').addEventListener('change',function(){
        var used=selMCUsedIds();
        var dups=used.filter(function(id,i){ return used.indexOf(id)!==i; });
        if(dups.length>0){ alert(<?php echo wp_json_encode(__('Cette monnaie est déjà utilisée.','seliweb')); ?>); this.value=''; }
    });
}
// Compteur de caractères
(function(){
    var ta=document.querySelector('textarea[name="texte"]');
    var ctr=document.getElementById('mc_counter');
    if(!ta||!ctr) return;
    function upd(){ var left=1000-ta.value.length; ctr.textContent=left+' car. restant(s)'; ctr.style.color=left<50?'#b32d2e':'#888'; }
    ta.addEventListener('input',upd); upd();
})();
// Init au chargement
function mcTogglePwd() {
    document.getElementById('mc_pwd_block').style.display = 'block';
    document.getElementById('mc_toggle_pwd').style.display = 'none';
    document.getElementById('mc_new_pwd').focus();
}
function mcCancelPwd() {
    document.getElementById('mc_pwd_block').style.display = 'none';
    document.getElementById('mc_toggle_pwd').style.display = '';
    document.getElementById('mc_new_pwd').value = '';
    document.getElementById('mc_conf_pwd').value = '';
    document.getElementById('mc_pwd_msg').textContent = '';
}
function mcCheckPwd() {
    var p1 = document.getElementById('mc_new_pwd').value;
    var p2 = document.getElementById('mc_conf_pwd').value;
    document.getElementById('mc_pwd_msg').textContent =
        (p2 && p1 !== p2) ? 'Les mots de passe ne correspondent pas.' : '';
}
// Vérification avant envoi du formulaire profil
document.addEventListener('DOMContentLoaded', function(){
    var form = document.querySelector('.sel-prf-table')?.closest('form');
    if (form) {
        form.addEventListener('submit', function(e){
            var p1 = document.getElementById('mc_new_pwd')?.value || '';
            var p2 = document.getElementById('mc_conf_pwd')?.value || '';
            if (p1 && p1 !== p2) {
                e.preventDefault();
                mcCheckPwd();
                document.getElementById('mc_new_pwd').focus();
            }
        });
    }
});
document.addEventListener('DOMContentLoaded',function(){
    var sel=document.querySelector('[name="categorie_id"]');
    if(sel&&sel.value){ selMCRub(sel.value); selMCType(sel.value); }
});
document.addEventListener('DOMContentLoaded',function(){
    var form = document.getElementById('seliweb-form-annonce');
    var overlay = document.getElementById('seliweb-saving-overlay');
    if (form && overlay) {
        form.addEventListener('submit', function() {
            overlay.style.display = 'flex';
        });
    }
});
</script>
