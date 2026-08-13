<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Accès refusé.', 'seliweb' ) ); }

global $wpdb;
$tm = $wpdb->prefix . 'seliweb_membres';
$ti = $wpdb->prefix . 'seliweb_inscriptions';
$tg = $wpdb->prefix . 'seliweb_groupes';
$tp = $wpdb->prefix . 'seliweb_parametres';

// Groupe SEL configuré (0 si module SEL non activé)
$sel_groupe_id = (int) $wpdb->get_var( "SELECT valeur FROM $tp WHERE cle='sel_groupe_id' LIMIT 1" );

$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
$edit_id   = isset( $_GET['id'] )     ? intval( $_GET['id'] )           : 0;

// ----------------------------------------------------------------
// Traitement POST : mise à jour groupe d'un membre (liste)
// ----------------------------------------------------------------
if ( isset( $_POST['seliweb_nonce'] )
     && wp_verify_nonce( $_POST['seliweb_nonce'], 'seliweb_membres' )
     && isset( $_POST['membre_id'] ) ) {
    $membre_id = intval( $_POST['membre_id'] );
    $groupe_id = ! empty( $_POST['groupe_id'] ) ? intval( $_POST['groupe_id'] ) : null;
    $wpdb->update( $tm, array(
        'groupe_id' => $groupe_id,
        'bloque'    => isset( $_POST['bloque'] ) ? 1 : 0,
    ), array( 'id' => $membre_id ) );
    // Auto-numérotation SEL lors d'un changement rapide de groupe
    if ( $sel_groupe_id > 0 && (int) $groupe_id === $sel_groupe_id ) {
        $current_num = $wpdb->get_var( $wpdb->prepare( "SELECT numero_sel FROM $tm WHERE id=%d", $membre_id ) );
        if ( $current_num === null ) {
            $max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT GREATEST(COALESCE(MAX(numero_sel),1),1) FROM $tm WHERE groupe_id=%d", $sel_groupe_id ) );
            $wpdb->update( $tm, array( 'numero_sel' => $max + 1 ), array( 'id' => $membre_id ) );
        }
    }
}

// ----------------------------------------------------------------
// Traitement POST : modification d'un membre existant
// ----------------------------------------------------------------
$erreurs_modif = array();
$modif_ok      = false;

if ( isset( $_POST['seliweb_nonce_modif'] )
     && wp_verify_nonce( $_POST['seliweb_nonce_modif'], 'seliweb_modif_membre' ) ) {

    $mid       = intval( $_POST['membre_id_modif'] );
    $civilite  = in_array( $_POST['civilite'] ?? '', array('Mr','Mme') ) ? $_POST['civilite'] : '';
    $nom       = sanitize_text_field( wp_unslash( $_POST['nom']          ?? '' ) );
    $prenom    = sanitize_text_field( wp_unslash( $_POST['prenom']       ?? '' ) );
    $organisme = sanitize_text_field( wp_unslash( $_POST['organisme']    ?? '' ) );
    $tel_port  = sanitize_text_field( wp_unslash( $_POST['tel_portable'] ?? '' ) );
    $tel_fixe  = sanitize_text_field( wp_unslash( $_POST['tel_fixe']     ?? '' ) );
    $email     = sanitize_email( wp_unslash( $_POST['user_email']        ?? '' ) );
    $adresse1  = sanitize_text_field( wp_unslash( $_POST['adresse1']     ?? '' ) );
    $adresse2  = sanitize_text_field( wp_unslash( $_POST['adresse2']     ?? '' ) );
    $ville     = sanitize_text_field( wp_unslash( $_POST['ville']        ?? '' ) );
    $cp        = sanitize_text_field( wp_unslash( $_POST['code_postal']  ?? '' ) );
    $groupe_id = ! empty( $_POST['groupe_id_modif'] ) ? intval( $_POST['groupe_id_modif'] ) : null;
    $notif     = isset( $_POST['notif_annonces'] ) ? 1 : 0;
    $new_pwd   = $_POST['new_password'] ?? '';
    $new_pwd2  = $_POST['new_password_confirm'] ?? '';

    // Récupérer le wp_user_id du membre
    $membre_modif = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tm WHERE id=%d", $mid ) );

    if ( ! $membre_modif ) {
        $erreurs_modif[] = __( 'Membre introuvable.', 'seliweb' );
    } else {
        if ( ! $nom )    $erreurs_modif[] = __( 'Le nom est obligatoire.', 'seliweb' );
        if ( ! $prenom ) $erreurs_modif[] = __( 'Le prénom est obligatoire.', 'seliweb' );
        if ( ! is_email( $email ) ) $erreurs_modif[] = __( "L'email est invalide.", 'seliweb' );
        // Vérifier email unique (sauf pour ce membre)
        $existing_email = email_exists( $email );
        if ( $existing_email && $existing_email != $membre_modif->wp_user_id ) {
            $erreurs_modif[] = __( 'Cette adresse email est déjà utilisée par un autre compte.', 'seliweb' );
        }
        if ( $new_pwd && strlen( $new_pwd ) < 6 ) {
            $erreurs_modif[] = __( 'Le nouveau mot de passe doit comporter au moins 6 caractères.', 'seliweb' );
        }
        if ( $new_pwd && $new_pwd !== $new_pwd2 ) {
            $erreurs_modif[] = __( 'Les mots de passe ne correspondent pas.', 'seliweb' );
        }
        // Validation numéro SEL
        $te = $wpdb->prefix . 'seliweb_ecritures';
        $membre_a_transactions = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $te WHERE membre_id=%d LIMIT 1", $mid
        ) );
        if ( $sel_groupe_id > 0 && (int) $groupe_id === $sel_groupe_id && ! $membre_a_transactions ) {
            $numero_check = isset( $_POST['numero_sel'] ) ? intval( $_POST['numero_sel'] ) : 0;
            if ( $numero_check === 1 ) {
                $erreurs_modif[] = __( 'Le numéro 1 est réservé au compte du SEL et ne peut pas être attribué à un membre.', 'seliweb' );
            } elseif ( $numero_check > 1 ) {
                $deja_attribue = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $tm WHERE groupe_id=%d AND numero_sel=%d AND id != %d LIMIT 1",
                    $sel_groupe_id, $numero_check, $mid
                ) );
                if ( $deja_attribue ) {
                    $erreurs_modif[] = sprintf(
                        __( 'Le numéro %d est déjà attribué à un autre membre.', 'seliweb' ),
                        $numero_check
                    );
                }
            }
        }
    }

    if ( empty( $erreurs_modif ) && $membre_modif ) {
        // Mettre à jour seliweb_membres (champs SEL uniquement)
        $wpdb->update( $tm, array(
            'civilite'       => $civilite,
            'tel_portable'   => $tel_port,   'tel_fixe'     => $tel_fixe,
            'adresse1'       => $adresse1,   'adresse2'     => $adresse2,
            'ville'          => $ville,      'code_postal'  => $cp,
            'groupe_id'          => $groupe_id,  'notif_annonces'    => $notif,
            'show_email'         => isset( $_POST['show_email'] )        ? 1 : 0,
            'show_tel_portable'  => isset( $_POST['show_tel_portable'] ) ? 1 : 0,
            'show_tel_fixe'      => isset( $_POST['show_tel_fixe'] )     ? 1 : 0,
            'show_adresse'       => isset( $_POST['show_adresse'] )      ? 1 : 0,
        ), array( 'id' => $mid ) );

        // Numérotation et découvert SEL
        if ( $sel_groupe_id > 0 && (int) $groupe_id === $sel_groupe_id ) {
            if ( ! $membre_a_transactions ) {
                $numero_sel_input = isset( $_POST['numero_sel'] ) ? intval( $_POST['numero_sel'] ) : 0;
                if ( $numero_sel_input > 0 ) {
                    $wpdb->update( $tm, array( 'numero_sel' => $numero_sel_input ), array( 'id' => $mid ) );
                } elseif ( $membre_modif->numero_sel === null ) {
                    $max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT GREATEST(COALESCE(MAX(numero_sel),1),1) FROM $tm WHERE groupe_id=%d", $sel_groupe_id ) );
                    $wpdb->update( $tm, array( 'numero_sel' => $max + 1 ), array( 'id' => $mid ) );
                }
            }
            $decouvert_input = ( isset( $_POST['decouvert_max'] ) && $_POST['decouvert_max'] !== '' )
                ? intval( $_POST['decouvert_max'] ) : null;
            $wpdb->update( $tm, array( 'decouvert_max' => $decouvert_input ), array( 'id' => $mid ) );
        }

        // Mettre à jour le compte WP + usermeta
        $wp_data = array(
            'ID'           => $membre_modif->wp_user_id,
            'first_name'   => $prenom,
            'last_name'    => $nom,
            'display_name' => $prenom . ' ' . $nom,
            'user_email'   => $email,
        );
        if ( $new_pwd ) $wp_data['user_pass'] = $new_pwd;
        wp_update_user( $wp_data );
        update_user_meta( $membre_modif->wp_user_id, 'seliweb_organisme', $organisme );

        // Mettre à jour seliweb_inscriptions
        $existe_ins = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ti WHERE wp_user_id=%d", $membre_modif->wp_user_id ) );
        $data_ins   = array(
            'civilite'     => $civilite,   'nom'          => $nom,
            'prenom'       => $prenom,     'organisme'    => $organisme,
            'tel_portable' => $tel_port,   'tel_fixe'     => $tel_fixe,
            'adresse1'     => $adresse1,   'adresse2'     => $adresse2,
            'ville'        => $ville,      'code_postal'  => $cp,
        );
        if ( $existe_ins ) {
            $wpdb->update( $ti, $data_ins, array( 'wp_user_id' => $membre_modif->wp_user_id ) );
        } else {
            $data_ins['wp_user_id'] = $membre_modif->wp_user_id;
            $wpdb->insert( $ti, $data_ins );
        }

        $modif_ok = true;
        $action   = 'list';
    } else {
        $action  = 'edit';
        $edit_id = $mid;
    }
}

// ----------------------------------------------------------------
// Traitement POST : création d'un nouveau membre
// ----------------------------------------------------------------
$erreurs_creation = array();
$creation_ok      = false;

if ( isset( $_POST['seliweb_nonce_creation'] )
     && wp_verify_nonce( $_POST['seliweb_nonce_creation'], 'seliweb_creation_membre' ) ) {

    $civilite  = in_array( $_POST['civilite'] ?? '', array('Mr','Mme') ) ? $_POST['civilite'] : '';
    $nom       = sanitize_text_field( wp_unslash( $_POST['nom']          ?? '' ) );
    $prenom    = sanitize_text_field( wp_unslash( $_POST['prenom']       ?? '' ) );
    $organisme = sanitize_text_field( wp_unslash( $_POST['organisme']    ?? '' ) );
    $tel_port  = sanitize_text_field( wp_unslash( $_POST['tel_portable'] ?? '' ) );
    $tel_fixe  = sanitize_text_field( wp_unslash( $_POST['tel_fixe']     ?? '' ) );
    $email     = sanitize_email( wp_unslash( $_POST['user_email']        ?? '' ) );
    $adresse1  = sanitize_text_field( wp_unslash( $_POST['adresse1']     ?? '' ) );
    $adresse2  = sanitize_text_field( wp_unslash( $_POST['adresse2']     ?? '' ) );
    $ville     = sanitize_text_field( wp_unslash( $_POST['ville']        ?? '' ) );
    $cp        = sanitize_text_field( wp_unslash( $_POST['code_postal']  ?? '' ) );
    $password  = $_POST['password']         ?? '';
    $password2 = $_POST['password_confirm'] ?? '';
    $groupe_id = ! empty( $_POST['groupe_id_creation'] ) ? intval( $_POST['groupe_id_creation'] ) : null;

    if ( ! $civilite )  $erreurs_creation[] = __( 'La civilité est obligatoire.', 'seliweb' );
    if ( ! $nom )       $erreurs_creation[] = __( 'Le nom est obligatoire.', 'seliweb' );
    if ( ! $prenom )    $erreurs_creation[] = __( 'Le prénom est obligatoire.', 'seliweb' );
    if ( ! is_email( $email ) )      $erreurs_creation[] = __( "L'email est invalide.", 'seliweb' );
    if ( email_exists( $email ) )    $erreurs_creation[] = __( 'Cette adresse email est déjà utilisée.', 'seliweb' );
    if ( ! $adresse1 )  $erreurs_creation[] = __( "L'adresse est obligatoire.", 'seliweb' );
    if ( ! $ville )     $erreurs_creation[] = __( 'La ville est obligatoire.', 'seliweb' );
    if ( ! $cp )        $erreurs_creation[] = __( 'Le code postal est obligatoire.', 'seliweb' );
    if ( strlen($password) < 6 )     $erreurs_creation[] = __( 'Le mot de passe doit comporter au moins 6 caractères.', 'seliweb' );
    if ( $password !== $password2 )  $erreurs_creation[] = __( 'Les mots de passe ne correspondent pas.', 'seliweb' );

    if ( empty( $erreurs_creation ) ) {
        $user_login = sanitize_user( strtolower( $prenom . '.' . $nom ), true );
        if ( username_exists( $user_login ) ) $user_login .= rand(10,99);

        $user_id = wp_create_user( $user_login, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            $erreurs_creation[] = $user_id->get_error_message();
        } else {
            // Masquer la barre d'outils WP sur le front-end
            update_user_meta( $user_id, 'show_admin_bar_front', 'false' );

            wp_update_user( array(
                'ID'           => $user_id,
                'first_name'   => $prenom,
                'last_name'    => $nom,
                'display_name' => $prenom . ' ' . $nom,
            ) );
            update_user_meta( $user_id, 'seliweb_organisme', $organisme );

            // Numérotation SEL automatique
            $numero_sel_new = null;
            if ( $sel_groupe_id > 0 && (int) $groupe_id === $sel_groupe_id ) {
                $max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT GREATEST(COALESCE(MAX(numero_sel),1),1) FROM $tm WHERE groupe_id=%d", $sel_groupe_id ) );
                $numero_sel_new = $max + 1;
            }

            // rattacher_groupe_defaut() (hook user_register) a déjà inséré la ligne ;
            // on met à jour plutôt qu'insérer pour éviter l'échec sur la UNIQUE KEY wp_user_id.
            $wpdb->update( $tm, array(
                'groupe_id'         => $groupe_id,
                'civilite'          => $civilite,
                'tel_portable'      => $tel_port,  'tel_fixe'          => $tel_fixe,
                'adresse1'          => $adresse1,  'adresse2'          => $adresse2,
                'ville'             => $ville,     'code_postal'       => $cp,
                'show_email'        => isset($_POST['show_email'])        ? 1 : 0,
                'show_tel_portable' => isset($_POST['show_tel_portable']) ? 1 : 0,
                'show_tel_fixe'     => isset($_POST['show_tel_fixe'])     ? 1 : 0,
                'show_adresse'      => isset($_POST['show_adresse'])      ? 1 : 0,
                'numero_sel'        => $numero_sel_new,
            ), array( 'wp_user_id' => $user_id ) );
            $wpdb->insert( $ti, array(
                'wp_user_id'   => $user_id,  'civilite'     => $civilite,
                'nom'          => $nom,       'prenom'       => $prenom,
                'organisme'    => $organisme, 'tel_portable' => $tel_port,
                'tel_fixe'     => $tel_fixe,  'adresse1'     => $adresse1,
                'adresse2'     => $adresse2,  'ville'        => $ville,
                'code_postal'  => $cp,
            ) );
            $creation_ok = true;
            $action      = 'list';
        }
    } else {
        $action = 'new';
    }
}

$groupes = $wpdb->get_results( "SELECT * FROM $tg ORDER BY nom ASC" );

// ----------------------------------------------------------------
// Filtres
// ----------------------------------------------------------------
$filtre_groupe = isset( $_GET['filtre_groupe'] ) ? intval( $_GET['filtre_groupe'] ) : 0;
$filtre_ville  = isset( $_GET['filtre_ville'] )  ? sanitize_text_field( $_GET['filtre_ville'] ) : '';
$filtre_bloque = isset( $_GET['filtre_bloque'] ) && in_array( $_GET['filtre_bloque'], array('bloques','actifs'), true )
    ? $_GET['filtre_bloque'] : '';
$villes_dispo  = $wpdb->get_col( "SELECT DISTINCT ville FROM $tm WHERE ville != '' AND ville IS NOT NULL ORDER BY ville ASC" );

$allowed_orderby = array(
    'id'     => 'm.id',
    'numero' => 'ISNULL(m.numero_sel), m.numero_sel',
    'prenom' => 'um_p.meta_value',
    'nom'    => 'um_n.meta_value',
    'email'  => 'u.user_email',
);
$orderby_key = ( isset( $_GET['orderby'] ) && array_key_exists( $_GET['orderby'], $allowed_orderby ) )
    ? sanitize_key( $_GET['orderby'] ) : 'nom';
$order       = ( isset( $_GET['order'] ) && strtolower( $_GET['order'] ) === 'desc' ) ? 'DESC' : 'ASC';
$order_sql   = $allowed_orderby[ $orderby_key ] . ' ' . $order;

$per_page_allowed = array( 50, 100, 250 );
$per_page_raw = isset( $_GET['per_page'] ) ? (int) $_GET['per_page'] : 50;
$per_page     = in_array( $per_page_raw, $per_page_allowed, true ) ? $per_page_raw : 50;
$paged        = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$offset       = ( $paged - 1 ) * $per_page;

$where  = array('1=1'); $values = array();
if ( $filtre_groupe ) { $where[] = 'm.groupe_id = %d'; $values[] = $filtre_groupe; }
if ( $filtre_ville )  { $where[] = 'm.ville = %s';     $values[] = $filtre_ville; }
if ( $filtre_bloque === 'bloques' ) { $where[] = 'm.bloque = 1'; }
if ( $filtre_bloque === 'actifs' )  { $where[] = 'm.bloque = 0'; }
$where_sql = implode(' AND ', $where);

$joins = "FROM $tm m
        LEFT JOIN $tg g ON g.id=m.groupe_id
        LEFT JOIN {$wpdb->users} u ON u.ID=m.wp_user_id
        LEFT JOIN {$wpdb->usermeta} um_p ON um_p.user_id=m.wp_user_id AND um_p.meta_key='first_name'
        LEFT JOIN {$wpdb->usermeta} um_n ON um_n.user_id=m.wp_user_id AND um_n.meta_key='last_name'
        LEFT JOIN {$wpdb->usermeta} um_o ON um_o.user_id=m.wp_user_id AND um_o.meta_key='seliweb_organisme'";

$sql_count = "SELECT COUNT(*) $joins WHERE $where_sql";
$total     = (int) ( ! empty($values) ? $wpdb->get_var( $wpdb->prepare( $sql_count, ...$values ) ) : $wpdb->get_var( $sql_count ) );
$total_pages = max( 1, (int) ceil( $total / $per_page ) );
$paged = min( $paged, $total_pages );

$sql = "SELECT m.*, g.nom AS groupe_nom, u.display_name, u.user_email,
               um_p.meta_value AS prenom, um_n.meta_value AS nom, um_o.meta_value AS organisme
        $joins
        WHERE $where_sql ORDER BY $order_sql
        LIMIT %d OFFSET %d";

$values_paged = array_merge( $values, array( $per_page, $offset ) );
$membres = $wpdb->get_results( $wpdb->prepare( $sql, ...$values_paged ) );
?>

<div class="wrap">
    <h1>
        <?php esc_html_e('Membres','seliweb'); ?>
        <?php if ($action==='list') : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=seliweb_membres&action=new')); ?>"
               class="page-title-action"><?php esc_html_e('+ Ajouter un membre','seliweb'); ?></a>
        <?php endif; ?>
    </h1>

    <?php if ($creation_ok) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Membre créé avec succès.','seliweb'); ?></p></div>
    <?php endif; ?>
    <?php if ($modif_ok) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Membre mis à jour.','seliweb'); ?></p></div>
    <?php endif; ?>

    <?php if ($action === 'new') : ?>
    <!-- ===== FORMULAIRE CRÉATION ===== -->
    <a href="<?php echo esc_url(admin_url('admin.php?page=seliweb_membres')); ?>" class="button" style="margin-bottom:16px;">
        &larr; <?php esc_html_e('Retour à la liste','seliweb'); ?>
    </a>

    <?php if (!empty($erreurs_creation)) : ?>
        <div class="notice notice-error"><ul style="margin:0;padding-left:18px;">
            <?php foreach ($erreurs_creation as $e) : ?>
                <li><?php echo esc_html($e); ?></li>
            <?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <form method="post" style="max-width:600px;margin-top:12px;">
        <?php wp_nonce_field('seliweb_creation_membre','seliweb_nonce_creation'); ?>

        <table class="form-table">
            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Identité','seliweb'); ?></th></tr>
            <tr>
                <th><?php esc_html_e('Civilité','seliweb'); ?> *</th>
                <td>
                    <label style="margin-right:14px;"><input type="radio" name="civilite" value="Mr" <?php checked($_POST['civilite']??'','Mr'); ?> required> M.</label>
                    <label><input type="radio" name="civilite" value="Mme" <?php checked($_POST['civilite']??'','Mme'); ?>> Mme</label>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Nom','seliweb'); ?> *</th>
                <td><input type="text" name="nom" class="regular-text" value="<?php echo esc_attr($_POST['nom']??''); ?>" required></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Prénom','seliweb'); ?> *</th>
                <td><input type="text" name="prenom" class="regular-text" value="<?php echo esc_attr($_POST['prenom']??''); ?>" required></td>
            </tr>
            <tr>
                <th><?php esc_html_e("Organisme",'seliweb'); ?></th>
                <td>
                    <input type="text" name="organisme" class="regular-text" value="<?php echo esc_attr($_POST['organisme']??''); ?>">
                    <p class="description"><?php esc_html_e("Ne renseigner que si la personne agit au nom d'une personne morale.",'seliweb'); ?></p>
                </td>
            </tr>

            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Contact','seliweb'); ?></th></tr>
            <tr>
                <th><?php esc_html_e('Tél. portable','seliweb'); ?></th>
                <td><input type="tel" name="tel_portable" class="regular-text" value="<?php echo esc_attr($_POST['tel_portable']??''); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Tél. fixe','seliweb'); ?></th>
                <td><input type="tel" name="tel_fixe" class="regular-text" value="<?php echo esc_attr($_POST['tel_fixe']??''); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e('E-mail','seliweb'); ?> *</th>
                <td><input type="email" name="user_email" class="regular-text" value="<?php echo esc_attr($_POST['user_email']??''); ?>" required></td>
            </tr>

            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Adresse','seliweb'); ?></th></tr>
            <tr>
                <th><?php esc_html_e('Adresse 1','seliweb'); ?> *</th>
                <td><input type="text" name="adresse1" class="regular-text" value="<?php echo esc_attr($_POST['adresse1']??''); ?>" required></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Adresse 2','seliweb'); ?></th>
                <td><input type="text" name="adresse2" class="regular-text" value="<?php echo esc_attr($_POST['adresse2']??''); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Ville','seliweb'); ?> *</th>
                <td><input type="text" name="ville" class="regular-text" value="<?php echo esc_attr($_POST['ville']??''); ?>" required></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Code postal','seliweb'); ?> *</th>
                <td><input type="text" name="code_postal" class="regular-text" maxlength="10" value="<?php echo esc_attr($_POST['code_postal']??''); ?>" required></td>
            </tr>

            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Compte','seliweb'); ?></th></tr>
            <tr>
                <th><?php esc_html_e('Groupe','seliweb'); ?></th>
                <td>
                    <select name="groupe_id_creation">
                        <option value=""><?php esc_html_e('— Aucun —','seliweb'); ?></option>
                        <?php foreach ($groupes as $g) : ?>
                            <option value="<?php echo intval($g->id); ?>" <?php selected($_POST['groupe_id_creation']??'',$g->id); ?>>
                                <?php echo esc_html($g->nom); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Mot de passe','seliweb'); ?> *</th>
                <td>
                    <input type="password" name="password" id="swb_pwd" class="regular-text" required minlength="6" autocomplete="new-password">
                    <p class="description"><?php esc_html_e('6 caractères minimum. À communiquer à la personne.','seliweb'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Confirmation','seliweb'); ?> *</th>
                <td>
                    <input type="password" name="password_confirm" id="swb_pwd2" class="regular-text" required autocomplete="new-password"
                           oninput="swbCheckPwd()">
                    <span id="swb_pwd_msg" style="color:#b32d2e;font-size:12px;display:block;margin-top:3px;"></span>
                </td>
            </tr>
            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Notifications','seliweb'); ?></th></tr>
            <tr>
                <th></th>
                <td>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                        <input type="checkbox" name="notif_annonces" value="1" checked>
                        <?php esc_html_e('Recevoir un mail à chaque nouvelle annonce','seliweb'); ?>
                    </label>
                </td>
            </tr>
            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Confidentialité','seliweb'); ?></th></tr>
            <tr>
                <th></th>
                <td>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="show_email" value="1" checked style="margin-top:2px;">
                            <span><?php esc_html_e('Autoriser à montrer mon e-mail','seliweb'); ?><br>
                                <em style="font-size:12px;color:#888;"><?php esc_html_e('Si la case est décochée, vous recevrez quand même les mails qui vous sont destinés.','seliweb'); ?></em>
                            </span>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="show_tel_portable" value="1" checked>
                            <?php esc_html_e('Autoriser à montrer mon tél. portable','seliweb'); ?>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="show_tel_fixe" value="1" checked>
                            <?php esc_html_e('Autoriser à montrer mon tél. fixe','seliweb'); ?>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="show_adresse" value="1" checked style="margin-top:2px;">
                            <span><?php esc_html_e('Autoriser à montrer mon organisme','seliweb'); ?><br>
                                <em style="font-size:12px;color:#888;"><?php esc_html_e('Si la case est décochée, l\'organisme ne sera pas affiché.','seliweb'); ?></em>
                            </span>
                        </label>
                    </div>
                </td>
            </tr>
            <tr>
                <td></td>
                <td><p class="description">* <?php esc_html_e('Champs obligatoires','seliweb'); ?></p></td>
            </tr>
        </table>

        <p>
            <?php submit_button(__('Créer le membre','seliweb'),'primary','submit',false); ?>
            &nbsp;
            <a href="<?php echo esc_url(admin_url('admin.php?page=seliweb_membres')); ?>" class="button">
                <?php esc_html_e('Annuler','seliweb'); ?>
            </a>
        </p>
    </form>
    <script>
    function swbCheckPwd() {
        var p1 = document.getElementById('swb_pwd').value;
        var p2 = document.getElementById('swb_pwd2').value;
        document.getElementById('swb_pwd_msg').textContent =
            (p2 && p1 !== p2) ? '<?php esc_attr_e('Les mots de passe ne correspondent pas.','seliweb'); ?>' : '';
    }
    document.querySelector('form').addEventListener('submit', function(e){
        if (document.getElementById('swb_pwd').value !== document.getElementById('swb_pwd2').value) {
            e.preventDefault();
            swbCheckPwd();
        }
    });
    </script>

    <?php elseif ($action === 'edit' && $edit_id) :
        // Charger les données du membre
        $users_table = $wpdb->users;
        $sql_edit    = $wpdb->prepare(
            "SELECT m.*, u.user_email FROM `$tm` m
             LEFT JOIN `$users_table` u ON u.ID = m.wp_user_id
             WHERE m.id = %d",
            $edit_id
        );
        $m_edit = $wpdb->get_row( $sql_edit );
        // Compléter avec les données WP (nom/prénom/organisme)
        if ( $m_edit ) {
            $_wp_edit = get_userdata( $m_edit->wp_user_id );
            $m_edit->nom       = $_wp_edit ? $_wp_edit->last_name  : '';
            $m_edit->prenom    = $_wp_edit ? $_wp_edit->first_name : '';
            $m_edit->organisme = get_user_meta( $m_edit->wp_user_id, 'seliweb_organisme', true );
        }
    ?>

    <!-- ===== FORMULAIRE MODIFICATION ===== -->
    <a href="<?php echo esc_url(admin_url('admin.php?page=seliweb_membres')); ?>" class="button" style="margin-bottom:16px;">
        &larr; <?php esc_html_e('Retour à la liste','seliweb'); ?>
    </a>

    <?php if (!empty($erreurs_modif)) : ?>
        <div class="notice notice-error"><ul style="margin:0;padding-left:18px;">
            <?php foreach ($erreurs_modif as $e) : ?>
                <li><?php echo esc_html($e); ?></li>
            <?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <?php if ($m_edit) : ?>
    <form method="post" style="max-width:600px;margin-top:12px;">
        <?php wp_nonce_field('seliweb_modif_membre','seliweb_nonce_modif'); ?>
        <input type="hidden" name="membre_id_modif" value="<?php echo intval($m_edit->id); ?>">

        <table class="form-table">
            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Identité','seliweb'); ?></th></tr>
            <tr>
                <th><?php esc_html_e('Civilité','seliweb'); ?></th>
                <td>
                    <label style="margin-right:14px;"><input type="radio" name="civilite" value="Mr" <?php checked($m_edit->civilite??'','Mr'); ?>> M.</label>
                    <label><input type="radio" name="civilite" value="Mme" <?php checked($m_edit->civilite??'','Mme'); ?>> Mme</label>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Nom','seliweb'); ?> *</th>
                <td><input type="text" name="nom" class="regular-text" value="<?php echo esc_attr($m_edit->nom??''); ?>" required></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Prénom','seliweb'); ?> *</th>
                <td><input type="text" name="prenom" class="regular-text" value="<?php echo esc_attr($m_edit->prenom??''); ?>" required></td>
            </tr>
            <tr>
                <th><?php esc_html_e("Organisme",'seliweb'); ?></th>
                <td><input type="text" name="organisme" class="regular-text" value="<?php echo esc_attr($m_edit->organisme??''); ?>"></td>
            </tr>

            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Contact','seliweb'); ?></th></tr>
            <tr>
                <th><?php esc_html_e('Tél. portable','seliweb'); ?></th>
                <td><input type="tel" name="tel_portable" class="regular-text" value="<?php echo esc_attr($m_edit->tel_portable??''); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Tél. fixe','seliweb'); ?></th>
                <td><input type="tel" name="tel_fixe" class="regular-text" value="<?php echo esc_attr($m_edit->tel_fixe??''); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e('E-mail','seliweb'); ?> *</th>
                <td><input type="email" name="user_email" class="regular-text" value="<?php echo esc_attr($m_edit->user_email??''); ?>" required></td>
            </tr>

            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Adresse','seliweb'); ?></th></tr>
            <tr>
                <th><?php esc_html_e('Adresse 1','seliweb'); ?></th>
                <td><input type="text" name="adresse1" class="regular-text" value="<?php echo esc_attr($m_edit->adresse1??''); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Adresse 2','seliweb'); ?></th>
                <td><input type="text" name="adresse2" class="regular-text" value="<?php echo esc_attr($m_edit->adresse2??''); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Ville','seliweb'); ?></th>
                <td><input type="text" name="ville" class="regular-text" value="<?php echo esc_attr($m_edit->ville??''); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Code postal','seliweb'); ?></th>
                <td><input type="text" name="code_postal" class="regular-text" maxlength="10" value="<?php echo esc_attr($m_edit->code_postal??''); ?>"></td>
            </tr>

            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Compte','seliweb'); ?></th></tr>
            <tr>
                <th><?php esc_html_e('Groupe','seliweb'); ?></th>
                <td>
                    <select name="groupe_id_modif">
                        <option value=""><?php esc_html_e('— Aucun —','seliweb'); ?></option>
                        <?php foreach ($groupes as $g) : ?>
                            <option value="<?php echo intval($g->id); ?>" <?php selected($m_edit->groupe_id,$g->id); ?>>
                                <?php echo esc_html($g->nom); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <?php if ( $sel_groupe_id > 0 && (int)$m_edit->groupe_id === $sel_groupe_id ) :
                $te_disp = $wpdb->prefix . 'seliweb_ecritures';
                $num_locked = (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM $te_disp WHERE membre_id=%d LIMIT 1", $m_edit->id
                ) );
            ?>
            <tr>
                <th><?php esc_html_e('N° membre SEL','seliweb'); ?></th>
                <td>
                    <?php if ( $num_locked ) : ?>
                        <span style="font-weight:600;"><?php echo intval( $m_edit->numero_sel ); ?></span>
                        <p class="description" style="color:#b32d2e;">
                            <?php esc_html_e( 'Ce numéro ne peut plus être modifié car ce membre a des transactions enregistrées.', 'seliweb' ); ?>
                        </p>
                    <?php else : ?>
                        <input type="number" name="numero_sel" class="small-text" min="1" max="999999"
                               value="<?php echo $m_edit->numero_sel ? intval($m_edit->numero_sel) : ''; ?>"
                               style="width:100px;">
                        <p class="description"><?php esc_html_e('Laissez vide pour attribution automatique (dernier numéro + 1).','seliweb'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Découvert max autorisé','seliweb'); ?></th>
                <td>
                    <input type="number" name="decouvert_max" class="small-text" min="0" step="1"
                           value="<?php echo $m_edit->decouvert_max !== null ? intval($m_edit->decouvert_max) : ''; ?>"
                           style="width:100px;">
                    <p class="description"><?php esc_html_e("Laissez vide pour aucun découvert autorisé.",'seliweb'); ?></p>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th><?php esc_html_e('Notifications','seliweb'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="notif_annonces" value="1" <?php checked($m_edit->notif_annonces??1); ?>>
                        <?php esc_html_e('Recevoir un mail à chaque nouvelle annonce','seliweb'); ?>
                    </label>
                </td>
            </tr>
            <tr><th colspan="2" style="padding:14px 0 4px;color:#1d6a4a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ddd;"><?php esc_html_e('Confidentialité','seliweb'); ?></th></tr>
            <tr>
                <th></th>
                <td>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="show_email" value="1" <?php checked($m_edit->show_email??1); ?> style="margin-top:2px;">
                            <span><?php esc_html_e('Autoriser à montrer mon e-mail','seliweb'); ?><br>
                                <em style="font-size:12px;color:#888;"><?php esc_html_e('Si la case est décochée, vous recevrez quand même les mails qui vous sont destinés.','seliweb'); ?></em>
                            </span>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="show_tel_portable" value="1" <?php checked($m_edit->show_tel_portable??1); ?>>
                            <?php esc_html_e('Autoriser à montrer mon tél. portable','seliweb'); ?>
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="show_tel_fixe" value="1" <?php checked($m_edit->show_tel_fixe??1); ?>>
                            <?php esc_html_e('Autoriser à montrer mon tél. fixe','seliweb'); ?>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="show_adresse" value="1" <?php checked($m_edit->show_adresse??1); ?> style="margin-top:2px;">
                            <span><?php esc_html_e('Autoriser à montrer mon organisme','seliweb'); ?><br>
                                <em style="font-size:12px;color:#888;"><?php esc_html_e('Si la case est décochée, l\'organisme ne sera pas affiché.','seliweb'); ?></em>
                            </span>
                        </label>
                    </div>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Mot de passe','seliweb'); ?></th>
                <td>
                    <button type="button" id="swb_toggle_pwd" class="button"
                            onclick="swbTogglePwd()"
                            style="margin-bottom:8px;">
                        🔑 <?php esc_html_e('Modifier le mot de passe','seliweb'); ?>
                    </button>
                    <div id="swb_pwd_block" style="display:none;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:12px;margin-top:4px;">
                        <p style="margin:0 0 8px;font-size:13px;color:#555;">
                            <?php esc_html_e('Saisissez un nouveau mot de passe (6 caractères minimum).','seliweb'); ?>
                        </p>
                        <label style="display:block;margin-bottom:4px;font-size:13px;">
                            <?php esc_html_e('Nouveau mot de passe','seliweb'); ?>
                        </label>
                        <input type="password" name="new_password" id="swb_mod_pwd" class="regular-text"
                               minlength="6" autocomplete="new-password" style="margin-bottom:8px;">
                        <label style="display:block;margin-bottom:4px;font-size:13px;">
                            <?php esc_html_e('Confirmation','seliweb'); ?>
                        </label>
                        <input type="password" name="new_password_confirm" id="swb_mod_pwd2" class="regular-text"
                               autocomplete="new-password" oninput="swbModCheckPwd()">
                        <span id="swb_mod_msg" style="color:#b32d2e;font-size:12px;display:block;margin-top:3px;"></span>
                        <button type="button" class="button" onclick="swbCancelPwd()"
                                style="margin-top:8px;color:#b32d2e;">
                            <?php esc_html_e('Annuler','seliweb'); ?>
                        </button>
                    </div>
                </td>
            </tr>
        </table>

        <p>
            <?php submit_button(__('Mettre à jour','seliweb'),'primary','submit',false); ?>
            &nbsp;
            <a href="<?php echo esc_url(admin_url('admin.php?page=seliweb_membres')); ?>" class="button">
                <?php esc_html_e('Annuler','seliweb'); ?>
            </a>
        </p>
    </form>
    <script>
    function swbTogglePwd() {
        document.getElementById('swb_pwd_block').style.display = 'block';
        document.getElementById('swb_toggle_pwd').style.display = 'none';
        document.getElementById('swb_mod_pwd').focus();
    }
    function swbCancelPwd() {
        document.getElementById('swb_pwd_block').style.display = 'none';
        document.getElementById('swb_toggle_pwd').style.display = '';
        document.getElementById('swb_mod_pwd').value = '';
        document.getElementById('swb_mod_pwd2').value = '';
        document.getElementById('swb_mod_msg').textContent = '';
    }
    function swbModCheckPwd() {
        var p1 = document.getElementById('swb_mod_pwd').value;
        var p2 = document.getElementById('swb_mod_pwd2').value;
        document.getElementById('swb_mod_msg').textContent =
            (p2 && p1 !== p2) ? '<?php esc_attr_e('Les mots de passe ne correspondent pas.','seliweb'); ?>' : '';
    }
    document.querySelector('form').addEventListener('submit', function(e){
        var p1 = document.getElementById('swb_mod_pwd').value;
        var p2 = document.getElementById('swb_mod_pwd2').value;
        if (p1 && p1 !== p2) { e.preventDefault(); swbModCheckPwd(); }
    });
    </script>
    <?php else : ?>
        <div class="notice notice-error"><p><?php esc_html_e('Membre introuvable.','seliweb'); ?></p></div>
    <?php endif; ?>

    <?php else : ?>
    <!-- ===== FILTRES ===== -->
    <form method="get" style="margin:16px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="page" value="seliweb_membres">
        <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;"><?php esc_html_e('Groupe','seliweb'); ?></label>
            <select name="filtre_groupe">
                <option value=""><?php esc_html_e('Tous les groupes','seliweb'); ?></option>
                <?php foreach ($groupes as $g) : ?>
                    <option value="<?php echo intval($g->id); ?>" <?php selected($filtre_groupe,$g->id); ?>>
                        <?php echo esc_html($g->nom); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (!empty($villes_dispo)) : ?>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;"><?php esc_html_e('Ville','seliweb'); ?></label>
            <select name="filtre_ville">
                <option value=""><?php esc_html_e('Toutes les villes','seliweb'); ?></option>
                <?php foreach ($villes_dispo as $v) : ?>
                    <option value="<?php echo esc_attr($v); ?>" <?php selected($filtre_ville,$v); ?>>
                        <?php echo esc_html($v); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;"><?php esc_html_e('Comptes bloqués','seliweb'); ?></label>
            <select name="filtre_bloque">
                <option value=""><?php esc_html_e('Tous','seliweb'); ?></option>
                <option value="bloques" <?php selected($filtre_bloque,'bloques'); ?>><?php esc_html_e('Bloqués uniquement','seliweb'); ?></option>
                <option value="actifs"  <?php selected($filtre_bloque,'actifs'); ?>><?php esc_html_e('Actifs uniquement','seliweb'); ?></option>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;"><?php esc_html_e('Par page','seliweb'); ?></label>
            <select name="per_page">
                <?php foreach ( $per_page_allowed as $pp ) : ?>
                    <option value="<?php echo $pp; ?>" <?php selected( $per_page, $pp ); ?>><?php echo $pp; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="button"><?php esc_html_e('Filtrer','seliweb'); ?></button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=seliweb_membres')); ?>" class="button"><?php esc_html_e('Réinitialiser','seliweb'); ?></a>
        </div>
        <span style="font-size:13px;color:#555;align-self:center;">
            <?php printf( esc_html__( '%d membre(s) — page %d/%d', 'seliweb' ), $total, $paged, $total_pages ); ?>
        </span>
    </form>

    <!-- ===== RECHERCHE ===== -->
    <div style="margin-bottom:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <input type="text" id="mbr-search"
               placeholder="<?php esc_attr_e('Rechercher ID, N°, prénom, nom, email…','seliweb'); ?>"
               style="width:320px;padding:5px 8px;font-size:13px;" autocomplete="off">
        <button type="button" id="mbr-search-clear" class="button" style="padding:4px 8px;" title="<?php esc_attr_e('Effacer','seliweb'); ?>">✕</button>
        <span id="mbr-search-count" style="font-size:13px;color:#555;"></span>
    </div>

    <!-- ===== LISTE ===== -->
    <?php
    $base_args = array( 'page' => 'seliweb_membres', 'per_page' => $per_page );
    if ( $filtre_groupe ) $base_args['filtre_groupe'] = $filtre_groupe;
    if ( $filtre_ville )  $base_args['filtre_ville']  = $filtre_ville;
    if ( $filtre_bloque ) $base_args['filtre_bloque'] = $filtre_bloque;

    $sort_url = function( $col ) use ( $orderby_key, $order, $base_args ) {
        $new_order = ( $orderby_key === $col && $order === 'ASC' ) ? 'desc' : 'asc';
        $args = array_merge( $base_args, array( 'orderby' => $col, 'order' => $new_order ) );
        return admin_url( 'admin.php?' . http_build_query( $args ) );
    };
    $page_url = function( $p ) use ( $base_args, $orderby_key, $order ) {
        $args = array_merge( $base_args, array( 'orderby' => $orderby_key, 'order' => strtolower($order), 'paged' => $p ) );
        return admin_url( 'admin.php?' . http_build_query( $args ) );
    };
    $sort_icon = function( $col ) use ( $orderby_key, $order ) {
        if ( $orderby_key !== $col ) return '<span style="color:#ccc;"> ⇅</span>';
        return $order === 'ASC'
            ? '<span style="color:#2271b1;"> ↑</span>'
            : '<span style="color:#2271b1;"> ↓</span>';
    };
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th style="width:50px;"><a href="<?php echo esc_url($sort_url('id')); ?>" style="text-decoration:none;color:inherit;"><?php esc_html_e('ID','seliweb'); echo $sort_icon('id'); ?></a></th>
            <th style="width:30px;"><a href="<?php echo esc_url($sort_url('numero')); ?>" style="text-decoration:none;color:inherit;"><?php esc_html_e('N°','seliweb'); echo $sort_icon('numero'); ?></a></th>
            <th style="width:100px;"><a href="<?php echo esc_url($sort_url('prenom')); ?>" style="text-decoration:none;color:inherit;"><?php esc_html_e('Prénom','seliweb'); echo $sort_icon('prenom'); ?></a></th>
            <th style="width:100px;"><a href="<?php echo esc_url($sort_url('nom')); ?>" style="text-decoration:none;color:inherit;"><?php esc_html_e('Nom','seliweb'); echo $sort_icon('nom'); ?></a></th>
            <th style="width:200px;"><a href="<?php echo esc_url($sort_url('email')); ?>" style="text-decoration:none;color:inherit;"><?php esc_html_e('Email','seliweb'); echo $sort_icon('email'); ?></a></th>
            <th style="width:100px;"><?php esc_html_e('Tél.','seliweb'); ?></th>
            <th style="width:100px;"><?php esc_html_e('Ville','seliweb'); ?></th>
            <th><?php esc_html_e('Groupe','seliweb'); ?></th>
            <th style="width:140px;"><?php esc_html_e('Actions','seliweb'); ?></th>
            <th style="width:70px;text-align:center;"><?php esc_html_e('Bloqué','seliweb'); ?></th>
        </tr></thead>
        <tbody id="mbr-tbody">
        <?php if (empty($membres)) : ?>
            <tr><td colspan="10"><em><?php esc_html_e('Aucun membre trouvé.','seliweb'); ?></em></td></tr>
        <?php else : ?>
            <tr id="mbr-no-results" style="display:none;">
                <td colspan="10" style="text-align:center;color:#888;font-style:italic;">
                    <?php esc_html_e('Aucun résultat pour cette recherche.','seliweb'); ?>
                </td>
            </tr>
            <?php foreach ($membres as $m) : ?>
            <tr data-s="<?php echo esc_attr( implode( ' ', array(
                $m->id,
                $m->numero_sel ?: '',
                $m->prenom ?? '',
                $m->nom ?? '',
                $m->user_email,
            ) ) ); ?>">
                <td style="text-align:center;color:#888;font-size:12px;"><?php echo intval( $m->id ); ?></td>
                <td style="text-align:center;color:#888;font-size:12px;font-weight:600;">
                    <?php echo ! empty( $m->numero_sel ) ? intval( $m->numero_sel ) : '—'; ?>
                </td>
                <td><?php echo esc_html( $m->prenom ?? '' ); ?></td>
                <td>
                    <strong><?php echo esc_html( $m->nom ?? '' ); ?></strong>
                    <?php if ( !empty($m->organisme) ) : ?>
                        <br><em style="font-size:12px;color:#888;"><?php echo esc_html($m->organisme); ?></em>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($m->user_email); ?></td>
                <td>
                    <?php if (!empty($m->tel_portable)) echo esc_html($m->tel_portable).'<br>'; ?>
                    <?php if (!empty($m->tel_fixe)) echo '<span style="font-size:12px;color:#888;">'.esc_html($m->tel_fixe).'</span>'; ?>
                    <?php if (empty($m->tel_portable) && empty($m->tel_fixe)) echo '—'; ?>
                </td>
                <td><?php echo esc_html($m->ville?:'—'); ?></td>
                <td>
                    <form method="post" id="mbr-form-<?php echo intval($m->id); ?>" style="display:inline;">
                        <?php wp_nonce_field('seliweb_membres','seliweb_nonce'); ?>
                        <input type="hidden" name="membre_id" value="<?php echo intval($m->id); ?>">
                        <select name="groupe_id" onchange="this.form.submit()" style="max-width:160px;">
                            <option value=""><?php esc_html_e('— Aucun —','seliweb'); ?></option>
                            <?php foreach ($groupes as $g) : ?>
                                <option value="<?php echo intval($g->id); ?>" <?php selected($m->groupe_id,$g->id); ?>>
                                    <?php echo esc_html($g->nom); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=seliweb_membres&action=edit&id='.$m->id)); ?>">
                        <?php esc_html_e('Modifier','seliweb'); ?>
                    </a>
                    &nbsp;|&nbsp;
                    <a href="<?php echo esc_url(get_edit_user_link($m->wp_user_id)); ?>" target="_blank">
                        <?php esc_html_e('Profil WP','seliweb'); ?>
                    </a>
                </td>
                <td style="text-align:center;">
                    <input type="checkbox" name="bloque" value="1" form="mbr-form-<?php echo intval($m->id); ?>"
                           <?php checked( ! empty( $m->bloque ) ); ?>
                           onchange="this.form.submit()"
                           title="<?php esc_attr_e('Bloquer ce compte (empêche la connexion)','seliweb'); ?>">
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
    <div style="display:flex;align-items:center;gap:8px;margin-top:12px;flex-wrap:wrap;">
        <?php if ( $paged > 1 ) : ?>
            <a href="<?php echo esc_url( $page_url( $paged - 1 ) ); ?>" class="button">&laquo; <?php esc_html_e('Précédente','seliweb'); ?></a>
        <?php else : ?>
            <button class="button" disabled>&laquo; <?php esc_html_e('Précédente','seliweb'); ?></button>
        <?php endif; ?>

        <span style="font-size:13px;color:#555;">
            <?php printf( esc_html__('Page %d sur %d','seliweb'), $paged, $total_pages ); ?>
        </span>

        <?php if ( $paged < $total_pages ) : ?>
            <a href="<?php echo esc_url( $page_url( $paged + 1 ) ); ?>" class="button"><?php esc_html_e('Suivante','seliweb'); ?> &raquo;</a>
        <?php else : ?>
            <button class="button" disabled><?php esc_html_e('Suivante','seliweb'); ?> &raquo;</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <script>
    (function() {
        function normalise(s) {
            return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
        }
        var input   = document.getElementById('mbr-search');
        var btnClr  = document.getElementById('mbr-search-clear');
        var countEl = document.getElementById('mbr-search-count');
        var noRes   = document.getElementById('mbr-no-results');
        var tbody   = document.getElementById('mbr-tbody');

        function doSearch() {
            var q     = normalise(input.value.trim());
            var words = q ? q.split(/\s+/) : [];
            var rows  = tbody ? tbody.querySelectorAll('tr[data-s]') : [];
            var count = 0;
            rows.forEach(function(tr) {
                var txt   = normalise(tr.getAttribute('data-s'));
                var match = !words.length || words.every(function(w) { return txt.indexOf(w) !== -1; });
                tr.style.display = match ? '' : 'none';
                if (match) count++;
            });
            if (noRes) noRes.style.display = (count === 0 && words.length > 0) ? '' : 'none';
            if (countEl) countEl.textContent = words.length ? count + ' / ' + rows.length : '';
        }

        if (input)  input.addEventListener('input', doSearch);
        if (btnClr) btnClr.addEventListener('click', function() { input.value = ''; doSearch(); input.focus(); });
    })();
    </script>
    <?php endif; ?>
</div>
