<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Accès refusé.', 'seliweb' ) ); }

global $wpdb;
$tm = $wpdb->prefix . 'seliweb_membres';
$ti = $wpdb->prefix . 'seliweb_inscriptions';
$tg = $wpdb->prefix . 'seliweb_groupes';

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
    $wpdb->update( $tm, array( 'groupe_id' => $groupe_id ), array( 'id' => $membre_id ) );
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
    }

    if ( empty( $erreurs_modif ) && $membre_modif ) {
        // Mettre à jour seliweb_membres
        $wpdb->update( $tm, array(
            'civilite'     => $civilite,   'nom'          => $nom,
            'prenom'       => $prenom,     'organisme'    => $organisme,
            'tel_portable' => $tel_port,   'tel_fixe'     => $tel_fixe,
            'adresse1'     => $adresse1,   'adresse2'     => $adresse2,
            'ville'        => $ville,      'code_postal'  => $cp,
            'groupe_id'    => $groupe_id,  'notif_annonces' => $notif,
        ), array( 'id' => $mid ) );

        // Mettre à jour le compte WP
        $wp_data = array(
            'ID'           => $membre_modif->wp_user_id,
            'first_name'   => $prenom,
            'last_name'    => $nom,
            'display_name' => $prenom . ' ' . $nom,
            'user_email'   => $email,
        );
        if ( $new_pwd ) $wp_data['user_pass'] = $new_pwd;
        wp_update_user( $wp_data );

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
            wp_update_user( array(
                'ID'           => $user_id,
                'first_name'   => $prenom,
                'last_name'    => $nom,
                'display_name' => $prenom . ' ' . $nom,
            ) );
            $wpdb->insert( $tm, array(
                'wp_user_id'   => $user_id, 'groupe_id'    => $groupe_id,
                'civilite'     => $civilite, 'nom'          => $nom,
                'prenom'       => $prenom,   'organisme'    => $organisme,
                'tel_portable' => $tel_port, 'tel_fixe'     => $tel_fixe,
                'adresse1'     => $adresse1, 'adresse2'     => $adresse2,
                'ville'        => $ville,    'code_postal'  => $cp,
            ) );
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
$villes_dispo  = $wpdb->get_col( "SELECT DISTINCT ville FROM $tm WHERE ville != '' AND ville IS NOT NULL ORDER BY ville ASC" );

$where  = array('1=1'); $values = array();
if ( $filtre_groupe ) { $where[] = 'm.groupe_id = %d'; $values[] = $filtre_groupe; }
if ( $filtre_ville )  { $where[] = 'm.ville = %s';     $values[] = $filtre_ville; }
$where_sql = implode(' AND ', $where);

$sql = "SELECT m.*, g.nom AS groupe_nom, u.display_name, u.user_email
        FROM $tm m
        LEFT JOIN $tg g ON g.id=m.groupe_id
        LEFT JOIN {$wpdb->users} u ON u.ID=m.wp_user_id
        WHERE $where_sql ORDER BY u.display_name ASC";

$membres = ! empty($values) ? $wpdb->get_results( $wpdb->prepare($sql, ...$values) ) : $wpdb->get_results($sql);
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
                <td><input type="text" name="code_postal" class="small-text" maxlength="10" value="<?php echo esc_attr($_POST['code_postal']??''); ?>" required></td>
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
                <td><input type="text" name="code_postal" class="small-text" maxlength="10" value="<?php echo esc_attr($m_edit->code_postal??''); ?>"></td>
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
            <tr>
                <th><?php esc_html_e('Notifications','seliweb'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="notif_annonces" value="1" <?php checked($m_edit->notif_annonces??1); ?>>
                        <?php esc_html_e('Recevoir un mail à chaque nouvelle annonce','seliweb'); ?>
                    </label>
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
            <button type="submit" class="button"><?php esc_html_e('Filtrer','seliweb'); ?></button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=seliweb_membres')); ?>" class="button"><?php esc_html_e('Réinitialiser','seliweb'); ?></a>
        </div>
        <span style="font-size:13px;color:#555;align-self:center;">
            <?php printf(esc_html(_n('%d membre','%d membres',count($membres),'seliweb')),count($membres)); ?>
        </span>
    </form>

    <!-- ===== LISTE ===== -->
    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th><?php esc_html_e('Nom','seliweb'); ?></th>
            <th><?php esc_html_e('Email','seliweb'); ?></th>
            <th><?php esc_html_e('Tél.','seliweb'); ?></th>
            <th><?php esc_html_e('Ville','seliweb'); ?></th>
            <th><?php esc_html_e('Groupe','seliweb'); ?></th>
            <th style="width:40px;"><?php esc_html_e('Notif.','seliweb'); ?></th>
            <th style="width:140px;"><?php esc_html_e('Actions','seliweb'); ?></th>
        </tr></thead>
        <tbody>
        <?php if (empty($membres)) : ?>
            <tr><td colspan="7"><em><?php esc_html_e('Aucun membre trouvé.','seliweb'); ?></em></td></tr>
        <?php else : ?>
            <?php foreach ($membres as $m) : ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($m->display_name); ?></strong>
                    <?php if (!empty($m->organisme)) : ?>
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
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('seliweb_membres','seliweb_nonce'); ?>
                        <input type="hidden" name="membre_id" value="<?php echo intval($m->id); ?>">
                        <select name="groupe_id" onchange="this.form.submit()">
                            <option value=""><?php esc_html_e('— Aucun —','seliweb'); ?></option>
                            <?php foreach ($groupes as $g) : ?>
                                <option value="<?php echo intval($g->id); ?>" <?php selected($m->groupe_id,$g->id); ?>>
                                    <?php echo esc_html($g->nom); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td style="text-align:center;">
                    <?php echo $m->notif_annonces ? '<span style="color:green;">&#10003;</span>' : '<span style="color:#aaa;">&#10007;</span>'; ?>
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
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
