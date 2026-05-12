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
    </div>

    <?php if ( in_array( $action, array( 'liste', 'creer', 'modifier' ) ) ) : ?>

        <?php if ( $action === 'liste' ) : ?>

            <div class="seliweb-compte-toolbar">
                <!-- CORRECTION : "Total annonce(s) : N" -->
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

                <div class="seliweb-field-row">
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

                <div class="seliweb-field-row">
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
                    </div>
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
                        $mc_is_first = true;
                        $mc_coord_map = array();
                        if ( $is_modif && $annonce_id ) {
                            foreach ( $wpdb->get_results( $wpdb->prepare(
                                "SELECT monnaie_id, coordination FROM {$wpdb->prefix}seliweb_annonces_prix WHERE annonce_id=%d ORDER BY id ASC", $annonce_id
                            ) ) as $pc ) {
                                $mc_coord_map[$pc->monnaie_id] = $pc->coordination;
                            }
                        }
                        foreach ($prix_lignes as $mon_id => $montant) :
                            $mc_coord = $mc_coord_map[$mon_id] ?? 'OU';
                        ?>
                        <div class="seliweb-prix-row" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                            <!-- ET/OU : masqué sur la 1ère ligne -->
                            <select name="prix_coordination[]"
                                    style="width:65px;<?php echo $mc_is_first ? 'visibility:hidden;' : ''; ?>">
                                <option value="OU" <?php selected($mc_coord,'OU'); ?>>OU</option>
                                <option value="ET" <?php selected($mc_coord,'ET'); ?>>ET</option>
                            </select>
                            <input type="text" name="prix_montant[]"
                                   value="<?php echo esc_attr($montant); ?>"
                                   maxlength="10" class="seliweb-input seliweb-prix-input"
                                   placeholder="<?php esc_attr_e('Montant','seliweb'); ?>">
                            <select name="prix_monnaie[]" class="seliweb-select mc-prix-select" style="max-width:200px;">
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
                        <?php $mc_is_first = false; endforeach; ?>
                    </div>
                    <?php if (count($monnaies_dispo) > 1) : ?>
                    <button type="button" class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm"
                            onclick="selMCAddPrix()">
                        <?php esc_html_e('+ Ajouter une monnaie','seliweb'); ?>
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Photos -->
                <div class="seliweb-field-row">
                    <div class="seliweb-field">
                        <label><?php esc_html_e('Photo 1','seliweb'); ?> <?php echo !$is_modif ? '*' : ''; ?></label>
                        <input type="file" name="photo1" accept="image/*"
                               class="seliweb-file" <?php echo !$is_modif ? 'required' : ''; ?>>
                        <?php if ($is_modif && $edit_annonce->photo1) : ?>
                            <img src="<?php echo esc_url($edit_annonce->photo1); ?>"
                                 class="seliweb-photo-preview" alt="">
                        <?php elseif (!$is_modif) : ?>
                            <span class="seliweb-hint"><?php esc_html_e('Obligatoire','seliweb'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="seliweb-field">
                        <label><?php esc_html_e('Photo 2','seliweb'); ?></label>
                        <input type="file" name="photo2" accept="image/*" class="seliweb-file">
                        <?php if ($is_modif && $edit_annonce->photo2) : ?>
                            <img src="<?php echo esc_url($edit_annonce->photo2); ?>"
                                 class="seliweb-photo-preview" alt="">
                        <?php endif; ?>
                    </div>
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
    <form method="post" action="<?php echo esc_url($page_url); ?>" style="max-width:560px;">
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
        </style>

        <table class="sel-prf-table">
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
                <td><input type="text" name="nom" value="<?php echo esc_attr($membre->nom??''); ?>"></td>
            </tr>
            <tr>
                <td><?php esc_html_e('Prénom','seliweb'); ?></td>
                <td><input type="text" name="prenom" value="<?php echo esc_attr($membre->prenom??''); ?>"></td>
            </tr>
            <tr>
                <td><?php esc_html_e("Organisme",'seliweb'); ?></td>
                <td><input type="text" name="organisme" value="<?php echo esc_attr($membre->organisme??''); ?>"></td>
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

            <!-- Préférences -->
            <tr class="sel-prf-sep"><td colspan="2"><?php esc_html_e('Préférences','seliweb'); ?></td></tr>
            <tr>
                <td><?php esc_html_e('Notifications','seliweb'); ?></td>
                <td>
                    <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;">
                        <input type="checkbox" name="notif_annonces" value="1" <?php checked($membre->notif_annonces??1); ?>>
                        <?php esc_html_e('Recevoir un mail à chaque nouvelle annonce','seliweb'); ?>
                    </label>
                </td>
            </tr>
            <?php if ($membre->groupe_id) :
                $nom_groupe = $wpdb->get_var($wpdb->prepare("SELECT nom FROM $tg WHERE id=%d",$membre->groupe_id)); ?>
            <tr>
                <td><?php esc_html_e('Groupe','seliweb'); ?></td>
                <td><span class="seliweb-tag"><?php echo esc_html($nom_groupe); ?></span></td>
            </tr>
            <?php endif; ?>

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

    <?php  endif; ?>


</div>

<script>
var selMCMonnaies = <?php echo wp_json_encode(array_map(function($m){
    return array('id'=>$m->id,'label'=>$m->nom.($m->symbole?' ('.$m->symbole.')':''));
}, $monnaies_dispo)); ?>;

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
    var opts='<option value=""><?php esc_attr_e("— Monnaie —","seliweb"); ?></option>';
    selMCMonnaies.forEach(function(m){ opts+='<option value="'+m.id+'">'+m.label+'</option>'; });
    var row=document.createElement('div');
    row.className='seliweb-prix-row';
    row.style.cssText='display:flex;align-items:center;gap:8px;margin-bottom:8px;';
    row.innerHTML='<select name="prix_coordination[]" style="width:65px;"><option value="OU">OU</option><option value="ET">ET</option></select>'
                 +'<input type="text" name="prix_montant[]" maxlength="10" class="seliweb-input seliweb-prix-input" placeholder="Montant">'
                 +'<select name="prix_monnaie[]" class="seliweb-select mc-prix-select" style="max-width:200px;">'+opts+'</select>'
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
</script>
