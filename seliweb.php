<?php
/*
 * Plugin Name: Seliweb-WP
 * Description: Gestion d'un S.E.L. Système d'Echange Local
 * Version: 0.7.6
 * Author: Philippe Le Duigou
 * Text Domain: seliweb
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SELIWEB_VERSION', '0.7.6' );
define( 'SELIWEB_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SELIWEB_URL',     plugin_dir_url( __FILE__ ) );

require_once SELIWEB_DIR . 'includes/class-database.php';
require_once SELIWEB_DIR . 'includes/class-parametres.php';
require_once SELIWEB_DIR . 'includes/class-groupes.php';
require_once SELIWEB_DIR . 'includes/class-annonces.php';
require_once SELIWEB_DIR . 'includes/class-transactions.php';
require_once SELIWEB_DIR . 'includes/class-cotisations.php';
require_once SELIWEB_DIR . 'includes/class-updater.php';
require_once SELIWEB_DIR . 'includes/class-front.php';

Seliweb_Groupes::init();
Seliweb_Annonces::init();
Seliweb_Parametres::init();
Seliweb_Transactions::init();
Seliweb_Cotisations::init();
Seliweb_Updater::init();

class Seliweb {

    public function __construct() {
        add_action( 'plugins_loaded',        array( $this, 'load_textdomain' ) );
        add_action( 'admin_init',            array( 'Seliweb_Database', 'install' ) );
        add_action( 'admin_menu',            array( $this, 'create_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_public_assets' ) );
        add_action( 'init',                  array( $this, 'handle_contact_message' ) );
        add_action( 'init',                  array( $this, 'handle_signalement' ) );
        add_action( 'init',                  array( $this, 'handle_mon_compte_post' ) );

        // Rattachement au groupe par défaut à l'inscription
        add_action( 'user_register',         array( $this, 'rattacher_groupe_defaut' ) );

        // Enregistrer les query vars personnalisées pour que WP ne les supprime pas
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );

        // Redirection connexion / déconnexion vers le front-end
        add_filter( 'login_redirect',        array( $this, 'redirect_apres_connexion' ), 10, 3 );
        add_action( 'wp_logout',             array( $this, 'redirect_apres_deconnexion' ) );

        // Blocage de compte membre : refuse l'authentification, puis renvoie
        // vers la page de connexion front-end avec un message dédié.
        add_filter( 'authenticate',    array( $this, 'refuser_membre_bloque' ), 30 );
        add_action( 'wp_login_failed', array( $this, 'redirect_echec_connexion' ), 10, 2 );

        add_shortcode( 'seliweb_mon_compte',     array( $this, 'shortcode_mon_compte' ) );
        add_shortcode( 'seliweb_login',          array( $this, 'shortcode_login' ) );
        add_shortcode( 'seliweb_inscription',    array( $this, 'shortcode_inscription' ) );

        // WP-Cron : vérification quotidienne des annonces expirées
        add_action( 'seliweb_cron_expire', array( $this, 'cron_expire_annonces' ) );
        if ( ! wp_next_scheduled( 'seliweb_cron_expire' ) ) {
            wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', 'seliweb_cron_expire' );
        }
    }

    public function register_query_vars( $vars ) {
        $vars[] = 'seliweb_annonce';
        $vars[] = 'sel_page';
        $vars[] = 'categorie_id';
        $vars[] = 'rubrique_id';
        $vars[] = 'type_annonce';
        $vars[] = 'ville';
        return $vars;
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'seliweb', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'seliweb' ) === false ) return;
        wp_enqueue_style(  'seliweb-admin', SELIWEB_URL . 'assets/css/admin.css',  array(), SELIWEB_VERSION );
        wp_enqueue_script( 'seliweb-admin', SELIWEB_URL . 'assets/js/admin.js', array( 'jquery' ), SELIWEB_VERSION, true );
    }

    public function enqueue_public_assets() {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) return;
        $has = has_shortcode( $post->post_content, 'seliweb_annonces' )
            || has_shortcode( $post->post_content, 'seliweb_mon_compte' )
            || has_shortcode( $post->post_content, 'seliweb_login' );
        if ( $has ) {
            wp_enqueue_style( 'seliweb-public', SELIWEB_URL . 'assets/css/public.css', array(), SELIWEB_VERSION );
        }
    }

    public function create_menu() {
        add_menu_page( __('Seliweb','seliweb'), __('Seliweb','seliweb'), 'manage_options', 'seliweb', array($this,'display_dashboard'), 'dashicons-networking', 2 );
        add_submenu_page( 'seliweb', __('Tableau de bord','seliweb'), __('Tableau de bord','seliweb'), 'manage_options', 'seliweb',            array($this,'display_dashboard') );
        add_submenu_page( 'seliweb', __('Paramètres','seliweb'),      __('Paramètres','seliweb'),      'manage_options', 'seliweb_parametres', array('Seliweb_Parametres','display') );
        add_submenu_page( 'seliweb', __('Annonces','seliweb'),        __('Annonces','seliweb'),        'manage_options', 'seliweb_annonces',   array('Seliweb_Annonces','display') );
        add_submenu_page( 'seliweb', __('Membres','seliweb'),         __('Membres','seliweb'),         'manage_options', 'seliweb_membres',    array($this,'display_membres') );
        if ( Seliweb_Transactions::sel_actif() ) {
            add_submenu_page( 'seliweb', __('Transactions','seliweb'), __('Transactions','seliweb'), 'manage_options', 'seliweb_transactions', array('Seliweb_Transactions','display') );
        }
        if ( Seliweb_Cotisations::cotisations_actif() ) {
            add_submenu_page( 'seliweb', __('Cotisations','seliweb'), __('Cotisations','seliweb'), 'manage_options', 'seliweb_cotisations', array('Seliweb_Cotisations','display_cotisations') );
        }
    }

    public function display_dashboard() { include SELIWEB_DIR . 'templates/admin-dashboard.php'; }
    public function display_membres()   { include SELIWEB_DIR . 'templates/admin-membres.php'; }

    // ----------------------------------------------------------------
    // Rattachement au groupe par défaut à l'inscription
    // ----------------------------------------------------------------
    public function rattacher_groupe_defaut( $user_id ) {
        global $wpdb;
        $tm        = $wpdb->prefix . 'seliweb_membres';
        $groupe_id = Seliweb_Groupes::get_groupe_defaut_id();

        $existe = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $tm WHERE wp_user_id=%d", $user_id
        ) );

        if ( $existe ) {
            // Mettre à jour le groupe seulement si un groupe par défaut existe
            if ( $groupe_id ) {
                $wpdb->update( $tm, array( 'groupe_id' => $groupe_id ), array( 'wp_user_id' => $user_id ) );
            }
        } else {
            // Créer le membre dans tous les cas, avec ou sans groupe par défaut
            $wpdb->insert( $tm, array(
                'wp_user_id' => $user_id,
                'groupe_id'  => $groupe_id ?: null,
            ) );
        }
    }

    // ----------------------------------------------------------------
    // Redirection après connexion → page Mon compte front-end
    // ----------------------------------------------------------------
    public function redirect_apres_connexion( $redirect_to, $request, $user ) {
        // Ne pas rediriger les admins
        if ( is_wp_error( $user ) || user_can( $user, 'manage_options' ) ) {
            return $redirect_to;
        }
        $url = $this->get_page_url_par_shortcode( 'seliweb_mon_compte' );
        return $url ?: $redirect_to;
    }

    // ----------------------------------------------------------------
    // Redirection après déconnexion → page de connexion front-end
    // ----------------------------------------------------------------
    public function redirect_apres_deconnexion() {
        if ( is_admin() ) return;
        $url = $this->get_page_url_par_shortcode( 'seliweb_login' );
        if ( $url ) {
            wp_safe_redirect( $url );
            exit;
        }
    }

    // ----------------------------------------------------------------
    // Refuse l'authentification si le membre est marqué "bloqué"
    // ----------------------------------------------------------------
    public function refuser_membre_bloque( $user ) {
        if ( ! ( $user instanceof WP_User ) ) {
            return $user; // identifiants déjà invalides, ou pas encore résolus
        }
        global $wpdb;
        $tm     = $wpdb->prefix . 'seliweb_membres';
        $bloque = (int) $wpdb->get_var( $wpdb->prepare( "SELECT bloque FROM $tm WHERE wp_user_id=%d", $user->ID ) );
        if ( $bloque ) {
            return new WP_Error( 'seliweb_bloque', __( 'Compte bloqué.', 'seliweb' ) );
        }
        return $user;
    }

    // ----------------------------------------------------------------
    // Échec de connexion depuis la page de connexion front-end : on y
    // reste plutôt que de tomber sur l'écran wp-login.php par défaut.
    // ----------------------------------------------------------------
    public function redirect_echec_connexion( $username, $error = null ) {
        $login_url = $this->get_page_url_par_shortcode( 'seliweb_login' );
        if ( ! $login_url ) return;

        $referer = wp_get_referer();
        if ( ! $referer || strpos( $referer, untrailingslashit( $login_url ) ) === false ) {
            return; // ne concerne pas notre formulaire (ex. wp-admin)
        }

        $code   = ( $error instanceof WP_Error ) ? $error->get_error_code() : '';
        $motif  = ( $code === 'seliweb_bloque' ) ? 'blocked' : 'failed';
        wp_safe_redirect( add_query_arg( 'login', $motif, $login_url ) );
        exit;
    }

    // ----------------------------------------------------------------
    // Utilitaire : trouver l'URL d'une page contenant un shortcode
    // ----------------------------------------------------------------
    private function get_page_url_par_shortcode( $shortcode ) {
        global $wpdb;
        $page_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type='page'
               AND post_content LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like( $shortcode ) . '%'
        ) );
        return $page_id ? get_permalink( $page_id ) : '';
    }

    // ----------------------------------------------------------------
    // Shortcode [seliweb_login]
    // Affiche un formulaire connexion + lien création de compte
    // ----------------------------------------------------------------
    public function shortcode_login( $atts ) {
        if ( is_admin() || ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) ) {
            return '<p><em>' . esc_html__( 'Formulaire de connexion Seliweb (prévisualisation désactivée).', 'seliweb' ) . '</em></p>';
        }
        if ( is_user_logged_in() && ! is_preview() ) {
            $url = $this->get_page_url_par_shortcode( 'seliweb_mon_compte' );
            if ( $url ) { wp_safe_redirect( $url ); exit; }
        }

        $inscription_url = $this->get_page_url_par_shortcode( 'seliweb_inscription' );

        ob_start(); ?>
        <div class="seliweb-wrap">

            <?php if ( isset( $_GET['login'] ) && $_GET['login'] === 'blocked' ) : ?>
                <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:16px;color:#b32d2e;">
                    <?php esc_html_e( 'Compte bloqué. Veuillez contacter l\'administrateur.', 'seliweb' ); ?>
                </div>
            <?php elseif ( isset( $_GET['login'] ) && $_GET['login'] === 'failed' ) : ?>
                <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:16px;color:#b32d2e;">
                    <?php esc_html_e( 'Identifiant ou mot de passe incorrect.', 'seliweb' ); ?>
                </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['registered'] ) ) : ?>
                <div class="seliweb-notice seliweb-notice-ok">
                    <?php esc_html_e( "Inscription réussie ! Un mot de passe a été envoyé sur votre boîte mail. Vous pouvez maintenant vous connecter.", 'seliweb' ); ?>
                </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['checkemail'] ) && $_GET['checkemail'] === 'confirm' ) : ?>
                <div class="seliweb-notice seliweb-notice-ok">
                    <?php esc_html_e( 'Un email vous a été envoyé avec un lien de réinitialisation de mot de passe.', 'seliweb' ); ?>
                </div>
            <?php endif; ?>

            <div class="seliweb-login-box" style="max-width:420px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:28px;">
                <h2 style="font-size:1.15rem;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #e0e0e0;color:var(--color-primary,#1d6a4a);">
                    <?php esc_html_e( 'Connexion', 'seliweb' ); ?>
                </h2>
                <form method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>">
                    <input type="hidden" name="redirect_to"
                           value="<?php echo esc_attr( $this->get_page_url_par_shortcode('seliweb_mon_compte') ?: home_url('/') ); ?>">
                    <input type="hidden" name="testcookie" value="1">
                    <div class="seliweb-field">
                        <label for="sel_login_user"><?php esc_html_e( 'Identifiant ou email', 'seliweb' ); ?></label>
                        <input type="text" id="sel_login_user" name="log" class="seliweb-input" required autocomplete="username">
                    </div>
                    <div class="seliweb-field">
                        <label for="sel_login_pwd"><?php esc_html_e( 'Mot de passe', 'seliweb' ); ?></label>
                        <div class="seliweb-pwd-wrap">
                            <input type="password" id="sel_login_pwd" name="pwd" class="seliweb-input" required autocomplete="current-password">
                            <button type="button" class="seliweb-pwd-toggle"
                                    aria-label="<?php esc_attr_e( 'Afficher le mot de passe', 'seliweb' ); ?>"
                                    aria-pressed="false"
                                    onclick="var i=document.getElementById('sel_login_pwd'),show=i.type==='password';i.type=show?'text':'password';this.setAttribute('aria-pressed',show?'true':'false');this.classList.toggle('seliweb-pwd-toggle-active',show);">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="seliweb-field">
                        <label>
                            <input type="checkbox" name="rememberme" value="forever">
                            <?php esc_html_e( 'Se souvenir de moi', 'seliweb' ); ?>
                        </label>
                    </div>
                    <div style="margin-top:12px;">
                        <button type="submit" name="wp-submit" class="seliweb-btn">
                            <?php esc_html_e( 'Se connecter', 'seliweb' ); ?>
                        </button>
                    </div>
                    <p style="margin-top:12px;font-size:.88rem;">
                        <a href="<?php echo esc_url( wp_lostpassword_url( add_query_arg( 'checkemail', 'confirm', get_permalink() ) ) ); ?>">
                            <?php esc_html_e( 'Mot de passe oublié ?', 'seliweb' ); ?>
                        </a>
                    </p>
                </form>

                <?php if ( $inscription_url ) : ?>
                <p style="margin-top:16px;padding-top:14px;border-top:1px solid #e0e0e0;font-size:.9rem;color:#555;">
                    <?php esc_html_e( "Pas encore de compte ?", 'seliweb' ); ?>
                    <a href="<?php echo esc_url( $inscription_url ); ?>" style="font-weight:600;">
                        <?php esc_html_e( "S'inscrire", 'seliweb' ); ?>
                    </a>
                </p>
                <?php endif; ?>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }


    // ----------------------------------------------------------------
    // Shortcode [seliweb_inscription]
    // ----------------------------------------------------------------
    public function shortcode_inscription( $atts ) {
        if ( is_admin() || ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) ) {
            return '<p><em>' . esc_html__( "Formulaire d'inscription Seliweb (prévisualisation désactivée).", 'seliweb' ) . '</em></p>';
        }
        if ( is_user_logged_in() && ! is_preview() ) {
            $url = $this->get_page_url_par_shortcode( 'seliweb_mon_compte' );
            if ( $url ) { wp_safe_redirect( $url ); exit; }
        }
        if ( ! get_option('users_can_register') ) {
            return '<p>' . esc_html__( "Les inscriptions sont actuellement fermées.", 'seliweb' ) . '</p>';
        }

        // Traitement POST du formulaire étendu
        $erreurs = array();
        $succes  = false;
        if ( isset( $_POST['seliweb_inscription_nonce'] )
             && wp_verify_nonce( $_POST['seliweb_inscription_nonce'], 'seliweb_inscription' ) ) {

            $civilite   = in_array( $_POST['civilite'] ?? '', array('Mr','Mme') ) ? $_POST['civilite'] : '';
            $nom        = sanitize_text_field( wp_unslash( $_POST['nom']        ?? '' ) );
            $prenom     = sanitize_text_field( wp_unslash( $_POST['prenom']     ?? '' ) );
            $organisme  = sanitize_text_field( wp_unslash( $_POST['organisme']  ?? '' ) );
            $tel_port   = sanitize_text_field( wp_unslash( $_POST['tel_portable'] ?? '' ) );
            $tel_fixe   = sanitize_text_field( wp_unslash( $_POST['tel_fixe']   ?? '' ) );
            $email      = sanitize_email( wp_unslash( $_POST['user_email']      ?? '' ) );
            $adresse1   = sanitize_text_field( wp_unslash( $_POST['adresse1']   ?? '' ) );
            $adresse2   = sanitize_text_field( wp_unslash( $_POST['adresse2']   ?? '' ) );
            $ville      = sanitize_text_field( wp_unslash( $_POST['ville']      ?? '' ) );
            $cp         = sanitize_text_field( wp_unslash( $_POST['code_postal'] ?? '' ) );

            // Validation
            if ( ! $civilite )  $erreurs[] = __( 'La civilité est obligatoire.', 'seliweb' );
            if ( ! $nom )       $erreurs[] = __( 'Le nom est obligatoire.', 'seliweb' );
            if ( ! $prenom )    $erreurs[] = __( 'Le prénom est obligatoire.', 'seliweb' );
            if ( ! $email || ! is_email( $email ) ) $erreurs[] = __( "L'email est invalide.", 'seliweb' );
            if ( ! $adresse1 )  $erreurs[] = __( "L'adresse est obligatoire.", 'seliweb' );
            if ( ! $ville )     $erreurs[] = __( 'La ville est obligatoire.', 'seliweb' );
            if ( ! $cp )        $erreurs[] = __( 'Le code postal est obligatoire.', 'seliweb' );

            if ( email_exists( $email ) ) {
                $erreurs[] = __( 'Cette adresse email est déjà utilisée.', 'seliweb' );
            }

            if ( empty( $erreurs ) ) {
                // Créer le login à partir du prénom + nom
                $user_login = sanitize_user( strtolower( $prenom . '.' . $nom ), true );
                $user_login = ! username_exists( $user_login ) ? $user_login : $user_login . rand(10,99);

                $user_id = wp_create_user( $user_login, wp_generate_password(), $email );

                if ( ! is_wp_error( $user_id ) ) {
                    // Masquer la barre d'outils WP sur le front-end
                    update_user_meta( $user_id, 'show_admin_bar_front', 'false' );

                    // Mettre à jour le profil WP
                    wp_update_user( array(
                        'ID'           => $user_id,
                        'first_name'   => $prenom,
                        'last_name'    => $nom,
                        'display_name' => $prenom . ' ' . $nom,
                    ) );

                    // Envoyer le mail avec le mot de passe
                    wp_new_user_notification( $user_id, null, 'user' );

                    // Sauvegarder les données étendues
                    global $wpdb;
                    $wpdb->insert(
                        $wpdb->prefix . 'seliweb_inscriptions',
                        array(
                            'wp_user_id'   => $user_id,
                            'civilite'     => $civilite,
                            'nom'          => $nom,
                            'prenom'       => $prenom,
                            'organisme'    => $organisme,
                            'tel_portable' => $tel_port,
                            'tel_fixe'     => $tel_fixe,
                            'adresse1'     => $adresse1,
                            'adresse2'     => $adresse2,
                            'ville'        => $ville,
                            'code_postal'  => $cp,
                        )
                    );

                    // Données complémentaires dans wp_usermeta
                    update_user_meta( $user_id, 'seliweb_organisme', $organisme );

                    // Créer/rattacher le membre (crée la ligne dans seliweb_membres)
                    $this->rattacher_groupe_defaut( $user_id );

                    // Préférences de confidentialité et notifications (issues du formulaire d'inscription)
                    $notif_ins    = isset( $_POST['notif_annonces'] )    ? 1 : 0;
                    $show_email   = isset( $_POST['show_email'] )        ? 1 : 0;
                    $show_tel_p   = isset( $_POST['show_tel_portable'] ) ? 1 : 0;
                    $show_tel_f   = isset( $_POST['show_tel_fixe'] )     ? 1 : 0;
                    $show_adr     = isset( $_POST['show_adresse'] )      ? 1 : 0;

                    // Mettre à jour les données SEL du membre
                    $tm = $wpdb->prefix . 'seliweb_membres';
                    $wpdb->update( $tm, array(
                        'civilite'          => $civilite,
                        'tel_portable'      => $tel_port,
                        'tel_fixe'          => $tel_fixe,
                        'adresse1'          => $adresse1,
                        'adresse2'          => $adresse2,
                        'ville'             => $ville,
                        'code_postal'       => $cp,
                        'notif_annonces'    => $notif_ins,
                        'show_email'        => $show_email,
                        'show_tel_portable' => $show_tel_p,
                        'show_tel_fixe'     => $show_tel_f,
                        'show_adresse'      => $show_adr,
                    ), array( 'wp_user_id' => $user_id ) );

                    // Sécurité : si la ligne n'existait pas encore (pas de groupe par défaut),
                    // on insère directement avec toutes les données
                    if ( $wpdb->rows_affected === 0 ) {
                        $wpdb->insert( $tm, array(
                            'wp_user_id'        => $user_id,
                            'groupe_id'         => null,
                            'civilite'          => $civilite,
                            'tel_portable'      => $tel_port,
                            'tel_fixe'          => $tel_fixe,
                            'adresse1'          => $adresse1,
                            'adresse2'          => $adresse2,
                            'ville'             => $ville,
                            'code_postal'       => $cp,
                            'notif_annonces'    => $notif_ins,
                            'show_email'        => $show_email,
                            'show_tel_portable' => $show_tel_p,
                            'show_tel_fixe'     => $show_tel_f,
                            'show_adresse'      => $show_adr,
                        ) );
                    }

                    $succes = true;
                } else {
                    $erreurs[] = $user_id->get_error_message();
                }
            }
        }

        $login_url = $this->get_page_url_par_shortcode( 'seliweb_login' );

        ob_start(); ?>
        <div class="seliweb-wrap">

            <?php if ( $succes ) : ?>
                <div class="seliweb-notice seliweb-notice-ok">
                    <?php esc_html_e( "Inscription réussie ! Un mot de passe vous a été envoyé par email. Vous pouvez maintenant vous connecter.", 'seliweb' ); ?>
                    <?php if ( $login_url ) : ?>
                        <br><a href="<?php echo esc_url($login_url); ?>" style="font-weight:600;">
                            <?php esc_html_e( 'Se connecter', 'seliweb' ); ?>
                        </a>
                    <?php endif; ?>
                </div>

            <?php else : ?>

                <?php if ( ! empty( $erreurs ) ) : ?>
                    <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:12px 16px;border-radius:4px;margin-bottom:20px;">
                        <ul style="margin:0;padding-left:18px;">
                            <?php foreach ( $erreurs as $e ) : ?>
                                <li style="color:#b32d2e;"><?php echo esc_html($e); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="seliweb-login-box" style="max-width:520px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:28px;">
                    <h2 style="font-size:1.15rem;margin-bottom:20px;padding-bottom:10px;border-bottom:2px solid #e0e0e0;color:var(--color-primary,#1d6a4a);">
                        <?php esc_html_e( 'Créer un compte', 'seliweb' ); ?>
                    </h2>

                    <form method="post" style="max-width:560px;">
                        <?php wp_nonce_field( 'seliweb_inscription', 'seliweb_inscription_nonce' ); ?>
                        <style>
                        .sel-ins-table { width:100%; border-collapse:collapse; }
                        .sel-ins-table tr { margin-bottom:12px; }
                        .sel-ins-table td { padding:5px 8px 5px 0; vertical-align:middle; }
                        .sel-ins-table td:first-child { width:175px; text-align:right; padding-right:14px; font-size:14px; font-weight:500; color:#333; white-space:nowrap; }
                        .sel-ins-table input[type="text"],
                        .sel-ins-table input[type="email"],
                        .sel-ins-table input[type="tel"] { width:100%; padding:7px 10px; border:1px solid #ccc; border-radius:4px; font-size:14px; box-sizing:border-box; }
                        .sel-ins-table input[type="text"]:focus,
                        .sel-ins-table input[type="email"]:focus,
                        .sel-ins-table input[type="tel"]:focus { outline:none; border-color:#1d6a4a; }
                        .sel-ins-radio { display:flex; gap:20px; align-items:center; padding-top:2px; }
                        .sel-ins-radio label { display:flex; align-items:center; gap:6px; font-size:14px; cursor:pointer; }
                        .sel-ins-hint { display:block; font-style:italic; font-size:12px; color:#888; margin-top:3px; }
                        .sel-ins-req { color:#b32d2e; }
                        @media(max-width:520px){
                            .sel-ins-table td:first-child { width:auto; text-align:left; display:block; padding-bottom:2px; }
                            .sel-ins-table td:last-child { display:block; padding-left:0; }
                        }
                        </style>

                        <table class="sel-ins-table">
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e('Civilité','seliweb'); ?></td>
                                <td>
                                    <div class="sel-ins-radio">
                                        <label><input type="radio" name="civilite" value="Mr" <?php checked($_POST['civilite']??'','Mr'); ?> required> <?php esc_html_e('M.','seliweb'); ?></label>
                                        <label><input type="radio" name="civilite" value="Mme" <?php checked($_POST['civilite']??'','Mme'); ?>> <?php esc_html_e('Mme','seliweb'); ?></label>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e('Nom','seliweb'); ?></td>
                                <td><input type="text" name="nom" value="<?php echo esc_attr($_POST['nom']??''); ?>" required></td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e('Prénom','seliweb'); ?></td>
                                <td><input type="text" name="prenom" value="<?php echo esc_attr($_POST['prenom']??''); ?>" required></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e("Nom de l'organisme",'seliweb'); ?></td>
                                <td>
                                    <input type="text" name="organisme" value="<?php echo esc_attr($_POST['organisme']??''); ?>">
                                    <span class="sel-ins-hint"><?php esc_html_e("Ne renseigner que si vous agissez au nom d'une personne morale",'seliweb'); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e('Tél. portable','seliweb'); ?></td>
                                <td><input type="tel" name="tel_portable" value="<?php echo esc_attr($_POST['tel_portable']??''); ?>"></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e('Tél. fixe','seliweb'); ?></td>
                                <td><input type="tel" name="tel_fixe" value="<?php echo esc_attr($_POST['tel_fixe']??''); ?>"></td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e('E-mail','seliweb'); ?></td>
                                <td><input type="email" name="user_email" value="<?php echo esc_attr($_POST['user_email']??''); ?>" required autocomplete="email"></td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e('Adresse 1','seliweb'); ?></td>
                                <td><input type="text" name="adresse1" value="<?php echo esc_attr($_POST['adresse1']??''); ?>" required></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e('Adresse 2','seliweb'); ?></td>
                                <td><input type="text" name="adresse2" value="<?php echo esc_attr($_POST['adresse2']??''); ?>"></td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e('Ville','seliweb'); ?></td>
                                <td><input type="text" name="ville" value="<?php echo esc_attr($_POST['ville']??''); ?>" required></td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e('Code postal','seliweb'); ?></td>
                                <td><input type="text" name="code_postal" maxlength="10" value="<?php echo esc_attr($_POST['code_postal']??''); ?>" required></td>
                            </tr>
                            <tr>
                                <td colspan="2" style="padding-top:14px;border-top:1px solid #e0e0e0;font-weight:600;color:#1d6a4a;font-size:13px;text-transform:uppercase;letter-spacing:.04em;text-align:left;white-space:normal;width:auto;">
                                    <?php esc_html_e('Notifications','seliweb'); ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2" style="padding:4px 0;text-align:left;white-space:normal;width:auto;">
                                    <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;margin-bottom:8px;">
                                        <input type="checkbox" name="notif_annonces" value="1" <?php checked(true); ?>>
                                        <?php esc_html_e('Recevoir un mail à chaque nouvelle annonce','seliweb'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2" style="padding-top:14px;border-top:1px solid #e0e0e0;font-weight:600;color:#1d6a4a;font-size:13px;text-transform:uppercase;letter-spacing:.04em;text-align:left;white-space:normal;width:auto;">
                                    <?php esc_html_e('Confidentialité','seliweb'); ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2" style="padding:4px 0;text-align:left;white-space:normal;width:auto;">
                                    <label style="display:flex;align-items:flex-start;gap:8px;font-size:14px;cursor:pointer;margin-bottom:8px;">
                                        <input type="checkbox" name="show_email" value="1" <?php checked(true); ?> style="margin-top:2px;">
                                        <span><?php esc_html_e('Autoriser à montrer mon e-mail','seliweb'); ?>
                                            <span class="sel-ins-hint"><?php esc_html_e('Si la case est décochée, vous recevrez quand même les mails qui vous sont destinés.','seliweb'); ?></span>
                                        </span>
                                    </label>
                                    <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;margin-bottom:8px;">
                                        <input type="checkbox" name="show_tel_portable" value="1" <?php checked(true); ?>>
                                        <?php esc_html_e('Autoriser à montrer mon tél. portable','seliweb'); ?>
                                    </label>
                                    <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;margin-bottom:8px;">
                                        <input type="checkbox" name="show_tel_fixe" value="1" <?php checked(true); ?>>
                                        <?php esc_html_e('Autoriser à montrer mon tél. fixe','seliweb'); ?>
                                    </label>
                                    <label style="display:flex;align-items:flex-start;gap:8px;font-size:14px;cursor:pointer;margin-bottom:8px;">
                                        <input type="checkbox" name="show_adresse" value="1" <?php checked(true); ?> style="margin-top:2px;">
                                        <span><?php esc_html_e('Autoriser à montrer mon organisme','seliweb'); ?>
                                            <span class="sel-ins-hint"><?php esc_html_e('Si la case est décochée, l\'organisme ne sera pas affiché.','seliweb'); ?></span>
                                        </span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <td></td>
                                <td>
                                    <p style="font-size:.82rem;color:#888;margin:8px 0 14px;">
                                        <span class="sel-ins-req">*</span> <?php esc_html_e('Champs obligatoires','seliweb'); ?>
                                    </p>
                                    <button type="submit" class="seliweb-btn"><?php esc_html_e("S'inscrire",'seliweb'); ?></button>
                                </td>
                            </tr>
                        </table>
                    </form>

                    <?php if ( $login_url ) : ?>
                    <p style="margin-top:16px;padding-top:14px;border-top:1px solid #e0e0e0;font-size:.9rem;color:#555;">
                        <?php esc_html_e( 'Déjà un compte ?', 'seliweb' ); ?>
                        <a href="<?php echo esc_url($login_url); ?>" style="font-weight:600;">
                            <?php esc_html_e( 'Se connecter', 'seliweb' ); ?>
                        </a>
                    </p>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }


    // ----------------------------------------------------------------
    // Shortcode [seliweb_annonces] — supprimé, affichage délégué au thème
    // ----------------------------------------------------------------

    // ----------------------------------------------------------------
    // Shortcode [seliweb_mon_compte]
    // ----------------------------------------------------------------
    public function shortcode_mon_compte( $atts ) {
        if ( is_admin() || ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) ) {
            return '<p><em>' . esc_html__( 'Espace membre Seliweb (prévisualisation désactivée).', 'seliweb' ) . '</em></p>';
        }
        ob_start();
        include SELIWEB_DIR . 'templates/public-mon-compte.php';
        return ob_get_clean();
    }

    // ----------------------------------------------------------------
    // WP-Cron : passage automatique des annonces expirées
    // ----------------------------------------------------------------
    public function cron_expire_annonces() {
        global $wpdb;
        $ta = $wpdb->prefix . 'seliweb_annonces';
        $ts = $wpdb->prefix . 'seliweb_statuts';

        $statut_expire  = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $ts WHERE slug=%s LIMIT 1", 'expire'
        ) );
        $statut_repondu = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $ts WHERE slug=%s LIMIT 1", 'repondu'
        ) );

        if ( ! $statut_expire ) return;

        $nb = $wpdb->query( $wpdb->prepare(
            "UPDATE $ta
             SET statut_id = %d
             WHERE date_expiration IS NOT NULL
               AND date_expiration < CURDATE()
               AND statut_id != %d
               AND ( statut_id != %d OR statut_id IS NULL )",
            $statut_expire,
            $statut_expire,   // déjà expiré → on ne retouche pas
            $statut_repondu ?? 0  // statut Répondu → on ne touche pas non plus
        ) );

        // Log discret dans les options WP pour suivi
        update_option( 'seliweb_cron_last_run', array(
            'date'              => current_time('mysql'),
            'annonces_expirees' => (int) $nb,
        ) );
    }

    // ----------------------------------------------------------------
    // Envoi message de contact — hook init
    // ----------------------------------------------------------------
    public function handle_contact_message() {
        if ( is_admin() ) return;
        if ( ! isset( $_POST['seliweb_envoyer_message'] ) ) return;

        $annonce_id = intval( $_POST['annonce_id'] );
        if ( ! wp_verify_nonce( $_POST['seliweb_contact_nonce'] ?? '', 'seliweb_contact_' . $annonce_id ) ) return;
        if ( ! is_user_logged_in() ) return;

        global $wpdb;
        $ta = $wpdb->prefix . 'seliweb_annonces';
        $tm = $wpdb->prefix . 'seliweb_membres';

        $annonce = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id=%d", $annonce_id ) );
        if ( ! $annonce ) return;

        $destinataire = $wpdb->get_row( $wpdb->prepare(
            "SELECT u.user_email, u.display_name FROM $tm m
             LEFT JOIN {$wpdb->users} u ON u.ID=m.wp_user_id WHERE m.id=%d", $annonce->membre_id
        ) );
        if ( ! $destinataire || ! $destinataire->user_email ) return;

        $expediteur = wp_get_current_user();
        $message    = sanitize_textarea_field( wp_unslash( $_POST['message'] ) );

        // Charger la config mail contact_annonce
        $tp       = $wpdb->prefix . 'seliweb_parametres';
        $cfg_rows = $wpdb->get_results( "SELECT cle, valeur FROM $tp WHERE cle LIKE 'mail\_contact\_%'" );
        $cfg      = array();
        foreach ( $cfg_rows as $r ) $cfg[ $r->cle ] = $r->valeur;

        $from_email = $cfg['mail_contact_from_email'] ?? '';
        $from_name  = $cfg['mail_contact_from_name']  ?? '';
        $sujet_tpl  = $cfg['mail_contact_subject']    ?? '';
        $intro      = $cfg['mail_contact_intro']       ?? '';
        $signature  = $cfg['mail_contact_signature']   ?? '';

        $default_subject = sprintf( '[%s] Message concernant votre annonce : {titre}', get_bloginfo('name') );
        if ( ! $sujet_tpl ) $sujet_tpl = $default_subject;
        $sujet = str_replace( '{titre}', $annonce->titre, $sujet_tpl );

        $corps_systeme = sprintf(
            __("Bonjour %s,\n\n%s (%s) vous a envoyé un message concernant \"%s\" :\n\n%s",'seliweb'),
            $destinataire->display_name, $expediteur->display_name, $expediteur->user_email, $annonce->titre, $message
        );
        $corps = trim( ( $intro ? $intro . "\n\n" : '' ) . $corps_systeme . ( $signature ? "\n\n" . $signature : '' ) );

        $headers = array(
            'Reply-To: ' . $expediteur->display_name . ' <' . $expediteur->user_email . '>',
        );
        if ( $from_email && is_email( $from_email ) ) {
            $headers[] = 'From: ' . ( $from_name ? $from_name . ' <' . $from_email . '>' : $from_email );
        }

        wp_mail( $destinataire->user_email, $sujet, $corps, $headers );

        wp_safe_redirect( add_query_arg('seliweb_message_envoye','1', wp_get_referer() ?: home_url()) );
        exit;
    }

    // ----------------------------------------------------------------
    // Signalement d'une annonce — hook init
    // ----------------------------------------------------------------
    public function handle_signalement() {
        if ( is_admin() ) return;
        if ( ! isset( $_POST['seliweb_signaler_annonce'] ) ) return;

        $annonce_id = intval( $_POST['annonce_id'] ?? 0 );
        if ( ! wp_verify_nonce( $_POST['seliweb_signal_nonce'] ?? '', 'seliweb_signal_' . $annonce_id ) ) return;

        global $wpdb;
        $ta      = $wpdb->prefix . 'seliweb_annonces';
        $annonce = $wpdb->get_row( $wpdb->prepare( "SELECT id, titre FROM $ta WHERE id=%d", $annonce_id ) );
        if ( ! $annonce ) return;

        $raison = sanitize_textarea_field( wp_unslash( $_POST['raison'] ?? '' ) );

        $tp       = $wpdb->prefix . 'seliweb_parametres';
        $cfg_rows = $wpdb->get_results( "SELECT cle, valeur FROM $tp WHERE cle LIKE 'mail\_signal\_%'" );
        $cfg      = array();
        foreach ( $cfg_rows as $r ) $cfg[ $r->cle ] = $r->valeur;

        $to_email      = trim( $cfg['mail_signal_to_email']      ?? '' );
        $from_email    = trim( $cfg['mail_signal_from_email']    ?? '' );
        $from_name     = trim( $cfg['mail_signal_from_name']     ?? '' );
        $sujet_tpl     = trim( $cfg['mail_signal_subject']       ?? '' );
        $avertissement = trim( $cfg['mail_signal_avertissement'] ?? '' );
        $prompt        = trim( $cfg['mail_signal_prompt']        ?? '' );
        $signature     = trim( $cfg['mail_signal_signature']     ?? '' );

        if ( ! $to_email || ! is_email( $to_email ) ) {
            $to_email = get_option( 'admin_email' );
        }

        $default_avertissement = __( "Toute annonce qui présente des caractéristiques contraires à la loi telles que pornographie, racisme, incitation à la haine, à la xénophobie, à la violence, vantant des produits illicites, incitant à l'intrusion dans des systèmes informatiques, atteinte aux droits d'auteur, etc. n'a pas sa place sur ce site.", 'seliweb' );
        $default_prompt        = __( "Vous allez signaler une annonce au webmaster de ce site. Merci d'en préciser les raisons.", 'seliweb' );

        $sujet = $sujet_tpl ?: sprintf( '[%s] %s', get_bloginfo('name'), __( "Signalement d'une annonce", 'seliweb' ) );

        if ( is_user_logged_in() ) {
            $user   = wp_get_current_user();
            $prenom = get_user_meta( $user->ID, 'first_name', true );
            $nom    = get_user_meta( $user->ID, 'last_name',  true );
            $declarant = sprintf(
                "ID : %d\n%s\n%s\n%s",
                $user->ID,
                __( 'Nom : ', 'seliweb' ) . trim( $prenom . ' ' . $nom ),
                __( 'Email : ', 'seliweb' ) . $user->user_email,
                ''
            );
        } else {
            $declarant = __( 'Visiteur non connecté', 'seliweb' );
        }

        $corps_systeme = sprintf(
            "%s %d\n%s %s\n\n%s\n%s\n\n%s\n%s",
            __( 'N° annonce :', 'seliweb' ), $annonce->id,
            __( 'Titre :', 'seliweb' ), $annonce->titre,
            __( 'Déclarant :', 'seliweb' ), trim( $declarant ),
            __( 'Raison invoquée :', 'seliweb' ), $raison
        );

        $parts = array();
        if ( $avertissement ) $parts[] = $avertissement;
        elseif ( ! $avertissement ) $parts[] = $default_avertissement;
        if ( $prompt ) $parts[] = $prompt;
        elseif ( ! $prompt ) $parts[] = $default_prompt;
        $parts[] = $corps_systeme;
        if ( $signature ) $parts[] = $signature;
        $corps = implode( "\n\n", $parts );

        $headers = array();
        if ( $from_email && is_email( $from_email ) ) {
            $headers[] = 'From: ' . ( $from_name ? $from_name . ' <' . $from_email . '>' : $from_email );
        }

        wp_mail( $to_email, $sujet, $corps, $headers );

        wp_safe_redirect( add_query_arg( 'seliweb_signal_envoye', '1', wp_get_referer() ?: home_url() ) );
        exit;
    }

    // ----------------------------------------------------------------
    // POST espace membre — hook init
    // ----------------------------------------------------------------
    public function handle_mon_compte_post() {
        if ( is_admin() ) return;
        if ( ! is_user_logged_in() ) return;

        global $wpdb;
        $ta  = $wpdb->prefix . 'seliweb_annonces';
        $tap = $wpdb->prefix . 'seliweb_annonces_prix';
        $tm  = $wpdb->prefix . 'seliweb_membres';
        $wp_user_id = get_current_user_id();

        // --- Profil ---
        if ( isset( $_POST['seliweb_nonce_profil'] )
             && wp_verify_nonce( $_POST['seliweb_nonce_profil'], 'seliweb_profil_' . $wp_user_id ) ) {

            $civilite    = in_array( $_POST['civilite'] ?? '', array('Mr','Mme') ) ? $_POST['civilite'] : null;
            $nom         = sanitize_text_field( wp_unslash( $_POST['nom']          ?? '' ) );
            $prenom      = sanitize_text_field( wp_unslash( $_POST['prenom']       ?? '' ) );
            $organisme   = sanitize_text_field( wp_unslash( $_POST['organisme']    ?? '' ) );
            $tel_port    = sanitize_text_field( wp_unslash( $_POST['tel_portable'] ?? '' ) );
            $tel_fixe    = sanitize_text_field( wp_unslash( $_POST['tel_fixe']     ?? '' ) );
            $email       = sanitize_email( wp_unslash( $_POST['user_email']        ?? '' ) );
            $adresse1    = sanitize_text_field( wp_unslash( $_POST['adresse1']     ?? '' ) );
            $adresse2    = sanitize_text_field( wp_unslash( $_POST['adresse2']     ?? '' ) );
            $ville       = sanitize_text_field( wp_unslash( $_POST['ville']        ?? '' ) );
            $cp          = sanitize_text_field( wp_unslash( $_POST['code_postal']  ?? '' ) );
            $new_pwd  = $_POST['new_password']         ?? '';
            $new_pwd2 = $_POST['new_password_confirm']  ?? '';


            // Mettre à jour l'email WP si valide et non déjà utilisé par un autre
            if ( $email && is_email( $email ) ) {
                $existing = email_exists( $email );
                if ( ! $existing || $existing == $wp_user_id ) {
                    wp_update_user( array( 'ID' => $wp_user_id, 'user_email' => $email ) );
                }
            }

            // Mettre à jour les champs WP : nom, prénom, display_name, organisme
            if ( $nom && $prenom ) {
                wp_update_user( array(
                    'ID'           => $wp_user_id,
                    'first_name'   => $prenom,
                    'last_name'    => $nom,
                    'display_name' => $prenom . ' ' . $nom,
                ) );
            }
            update_user_meta( $wp_user_id, 'seliweb_organisme', $organisme );

            // Mettre à jour le mot de passe si renseigné
            // On utilise wp_update_user() et non wp_set_password() qui déconnecte l'utilisateur
            if ( ! empty( $new_pwd ) && strlen( $new_pwd ) >= 6 && $new_pwd === $new_pwd2 ) {
                wp_update_user( array( 'ID' => $wp_user_id, 'user_pass' => $new_pwd ) );
            }

            // Mettre à jour seliweb_membres (champs SEL uniquement)
            $data_membre = array(
                'civilite'       => $civilite,
                'tel_portable'   => $tel_port,
                'tel_fixe'       => $tel_fixe,
                'adresse1'       => $adresse1,
                'adresse2'       => $adresse2,
                'ville'          => $ville,
                'code_postal'    => $cp,
                'notif_annonces' => isset( $_POST['notif_annonces'] ) ? 1 : 0,
            );
            $existe_membre = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $tm WHERE wp_user_id=%d", $wp_user_id
            ) );
            if ( $existe_membre ) {
                $wpdb->update( $tm, $data_membre, array( 'wp_user_id' => $wp_user_id ) );
            } else {
                $data_membre['wp_user_id'] = $wp_user_id;
                $wpdb->insert( $tm, $data_membre );
            }

            // Mettre à jour ou créer seliweb_inscriptions
            $ti = $wpdb->prefix . 'seliweb_inscriptions';
            $data_ins = array(
                'civilite'     => $civilite,
                'nom'          => $nom,
                'prenom'       => $prenom,
                'organisme'    => $organisme,
                'tel_portable' => $tel_port,
                'tel_fixe'     => $tel_fixe,
                'adresse1'     => $adresse1,
                'adresse2'     => $adresse2,
                'ville'        => $ville,
                'code_postal'  => $cp,
            );
            $existe_ins = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $ti WHERE wp_user_id=%d LIMIT 1", $wp_user_id
            ) );
            if ( $existe_ins ) {
                $wpdb->update( $ti, $data_ins, array( 'wp_user_id' => $wp_user_id ) );
            } else {
                $data_ins['wp_user_id'] = $wp_user_id;
                $wpdb->insert( $ti, $data_ins );
            }

            // Photo de profil
            if ( ! empty( $_FILES['seliweb_photo']['name'] ) && $_FILES['seliweb_photo']['error'] === UPLOAD_ERR_OK ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $att_id = media_handle_upload( 'seliweb_photo', 0 );
                if ( ! is_wp_error( $att_id ) ) {
                    $old_id = (int) get_user_meta( $wp_user_id, 'seliweb_photo_id', true );
                    if ( $old_id ) wp_delete_attachment( $old_id, true );
                    update_user_meta( $wp_user_id, 'seliweb_photo_id', $att_id );
                }
            }
            if ( isset( $_POST['delete_photo'] ) && (int) $_POST['delete_photo'] === 1 ) {
                $old_id = (int) get_user_meta( $wp_user_id, 'seliweb_photo_id', true );
                if ( $old_id ) wp_delete_attachment( $old_id, true );
                delete_user_meta( $wp_user_id, 'seliweb_photo_id' );
            }

            wp_safe_redirect( add_query_arg( array('sel_action'=>'profil','sel_saved_profil'=>'1'), get_permalink() ) );
            exit;
        }

        // --- Préférences membre ---
        if ( isset( $_POST['seliweb_nonce_prefs'] )
             && wp_verify_nonce( $_POST['seliweb_nonce_prefs'], 'seliweb_prefs_' . $wp_user_id ) ) {

            $wpdb->update( $tm, array(
                'notif_annonces'   => isset( $_POST['notif_annonces'] )   ? 1 : 0,
                'show_email'       => isset( $_POST['show_email'] )       ? 1 : 0,
                'show_tel_portable'=> isset( $_POST['show_tel_portable'] ) ? 1 : 0,
                'show_tel_fixe'    => isset( $_POST['show_tel_fixe'] )    ? 1 : 0,
                'show_adresse'     => isset( $_POST['show_adresse'] )     ? 1 : 0,
            ), array( 'wp_user_id' => $wp_user_id ) );

            wp_safe_redirect( add_query_arg( array( 'sel_action' => 'prefs', 'sel_saved_prefs' => '1' ), get_permalink() ) );
            exit;
        }

        // --- Annonce membre ---
        if ( isset( $_POST['seliweb_nonce_annonce'] )
             && wp_verify_nonce( $_POST['seliweb_nonce_annonce'], 'seliweb_annonce_membre' ) ) {

            $membre = $wpdb->get_row( $wpdb->prepare(
                "SELECT m.*, g.limite_annonces FROM $tm m
                 LEFT JOIN {$wpdb->prefix}seliweb_groupes g ON g.id=m.groupe_id
                 WHERE m.wp_user_id=%d LIMIT 1", $wp_user_id
            ) );
            if ( ! $membre ) return;

            $monnaies_ok = array();
            if ( $membre->groupe_id ) {
                $monnaies_ok = $wpdb->get_col( $wpdb->prepare(
                    "SELECT monnaie_id FROM {$wpdb->prefix}seliweb_groupes_monnaies WHERE groupe_id=%d",
                    $membre->groupe_id
                ) );
            }

            $id_post = intval( $_POST['annonce_id'] ?? 0 );
            $is_new  = ( $id_post === 0 );

            $nb     = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $ta WHERE membre_id=%d", $membre->id) );
            $limite = (int) ( $membre->limite_annonces ?? 0 );
            if ( $is_new && $limite > 0 && $nb >= $limite ) {
                wp_safe_redirect( add_query_arg('sel_limite','1', wp_get_referer() ?: get_permalink()) );
                exit;
            }

            $cat_id_f   = intval( $_POST['categorie_id'] );
            $rub_id_f   = ! empty($_POST['rubrique_id']) ? intval($_POST['rubrique_id']) : null;
            $statut_f   = ! empty($_POST['statut_id']) ? intval($_POST['statut_id']) : null;
            $date_exp_f = ! empty($_POST['date_expiration']) ? sanitize_text_field($_POST['date_expiration']) : null;

            // Validation : rubrique obligatoire
            if ( $cat_id_f && ! $rub_id_f ) {
                wp_safe_redirect( add_query_arg( array(
                    'sel_action' => $id_post ? 'modifier' : 'creer',
                    'sel_id'     => $id_post ?: '',
                    'sel_error'  => 'no_rubrique',
                ), get_permalink() ) );
                exit;
            }

            // Validation : date expiration incohérente
            if ( $date_exp_f && $date_exp_f < date('Y-m-d') ) {
                $slug_f = $statut_f ? $wpdb->get_var( $wpdb->prepare(
                    "SELECT slug FROM {$wpdb->prefix}seliweb_statuts WHERE id=%d", $statut_f
                ) ) : '';
                if ( $slug_f !== 'expire' ) {
                    wp_safe_redirect( add_query_arg( array(
                        'sel_action' => $id_post ? 'modifier' : 'creer',
                        'sel_id'     => $id_post ?: '',
                        'sel_error'  => 'bad_date',
                    ), get_permalink() ) );
                    exit;
                }
            }

            $data = array(
                'categorie_id'    => $cat_id_f,
                'rubrique_id'     => $rub_id_f,
                'type_annonce'    => in_array($_POST['type_annonce']??'',array('offre','demande')) ? $_POST['type_annonce'] : null,
                'titre'           => sanitize_text_field( wp_unslash( $_POST['titre'] ) ),
                'texte'           => sanitize_textarea_field( wp_unslash( substr( $_POST['texte'] ?? '', 0, 1000 ) ) ),
                'statut_id'       => $statut_f,
                'date_expiration' => $date_exp_f,
                'est_don'         => isset($_POST['est_don']) ? 1 : 0,
            );

            // Upload photos
            list( $photo1_f, $photo1_err_f ) = Seliweb_Annonces::handle_photo_upload( 'photo1' );
            list( $photo2_f, $photo2_err_f ) = Seliweb_Annonces::handle_photo_upload( 'photo2' );

            if ( $photo1_err_f || $photo2_err_f ) {
                wp_safe_redirect( add_query_arg( array(
                    'sel_action' => $id_post ? 'modifier' : 'creer',
                    'sel_id'     => $id_post ?: '',
                    'sel_error'  => $photo1_err_f ?: $photo2_err_f,
                ), get_permalink() ) );
                exit;
            }
            if ( $photo1_f ) $data['photo1'] = $photo1_f;
            if ( $photo2_f ) $data['photo2'] = $photo2_f;

            // Validation photos obligatoires (création uniquement)
            if ( $is_new ) {
                $tp_sv          = $wpdb->prefix . 'seliweb_parametres';
                $photos_min_raw = $wpdb->get_var( "SELECT valeur FROM $tp_sv WHERE cle='annonces_photos_min' LIMIT 1" );
                $photos_min_sv  = $photos_min_raw !== null ? max( 0, min( 2, (int) $photos_min_raw ) ) : 1;
                if ( $photos_min_sv >= 1 && empty( $data['photo1'] ) ) {
                    wp_safe_redirect( add_query_arg( array( 'sel_action' => 'creer', 'sel_error' => 'no_photo1' ), get_permalink() ) );
                    exit;
                }
                if ( $photos_min_sv >= 2 && empty( $data['photo2'] ) ) {
                    wp_safe_redirect( add_query_arg( array( 'sel_action' => 'creer', 'sel_error' => 'no_photo2' ), get_permalink() ) );
                    exit;
                }
            }

            if ( $is_new ) {
                $data['membre_id']     = $membre->id;
                $data['date_creation'] = current_time('mysql');
                $data = array_filter($data, fn($v) => $v !== null);
                $wpdb->insert($ta, $data);
                $id_post = $wpdb->insert_id;
                Seliweb_Annonces::notify_membres($id_post);
            } else {
                $proprio = (int) $wpdb->get_var($wpdb->prepare("SELECT membre_id FROM $ta WHERE id=%d",$id_post));
                if ($proprio !== (int)$membre->id) return;
                // Ne pas écraser les photos existantes si aucune nouvelle
                if ( empty($data['photo1']) ) unset($data['photo1']);
                if ( empty($data['photo2']) ) unset($data['photo2']);
                $wpdb->update($ta, $data, array('id'=>$id_post));
                if (isset($_POST['notifier_membres'])) Seliweb_Annonces::notify_membres($id_post);
            }

            $wpdb->delete($tap, array('annonce_id'=>$id_post));
            // Filtrer les lignes de prix aux monnaies autorisées par le groupe
            $post_filtré = $_POST;
            if ($membre->groupe_id && !empty($monnaies_ok)) {
                $monnaies_ok_int = array_map('intval', $monnaies_ok);
                $prix_ok = array();
                foreach ( (array)($post_filtré['prix'] ?? array()) as $line ) {
                    if (in_array(intval($line['monnaie_id'] ?? 0), $monnaies_ok_int)) {
                        $prix_ok[] = $line;
                    }
                }
                $post_filtré['prix'] = $prix_ok;
            }
            Seliweb_Annonces::save_prix_from_post($id_post, $post_filtré);

            // Alerte si statut Expiré
            $slug_saved_f = $statut_f ? $wpdb->get_var( $wpdb->prepare(
                "SELECT slug FROM {$wpdb->prefix}seliweb_statuts WHERE id=%d", $statut_f
            ) ) : '';
            $redir_args_f = array('sel_saved' => '1');
            if ( $slug_saved_f === 'expire' ) $redir_args_f['sel_warn_expire'] = '1';
            wp_safe_redirect(add_query_arg($redir_args_f, get_permalink()));
            exit;
        }

        // --- Suppression ---
        if (isset($_GET['sel_action'],$_GET['sel_id']) && $_GET['sel_action']==='supprimer') {
            $annonce_id = intval($_GET['sel_id']);
            if (!check_admin_referer('seliweb_suppr_'.$annonce_id)) return;
            $membre = $wpdb->get_row($wpdb->prepare("SELECT id FROM $tm WHERE wp_user_id=%d",$wp_user_id));
            if (!$membre) return;
            $proprio = (int)$wpdb->get_var($wpdb->prepare("SELECT membre_id FROM $ta WHERE id=%d",$annonce_id));
            if ($proprio===(int)$membre->id) {
                $wpdb->delete($tap, array('annonce_id'=>$annonce_id));
                $wpdb->delete($ta,  array('id'=>$annonce_id));
            }
            wp_safe_redirect(add_query_arg('sel_deleted','1',get_permalink()));
            exit;
        }
    }
}

register_activation_hook( __FILE__, array( 'Seliweb_Database', 'install' ) );
register_activation_hook( __FILE__, array( 'Seliweb_Database', 'create_pages_and_menu' ) );
register_deactivation_hook( __FILE__, 'seliweb_deactivate' );

function seliweb_deactivate() {
    wp_clear_scheduled_hook( 'seliweb_cron_expire' );
    Seliweb_Database::delete_pages_and_menu();
}

// -----------------------------------------------------------------------
// Enregistrer les query vars via plugins_loaded (avant parse_request WP)
// -----------------------------------------------------------------------
add_action( 'plugins_loaded', function() {
    add_filter( 'query_vars', function( $vars ) {
        $vars[] = 'seliweb_annonce';
        $vars[] = 'sel_page';
        $vars[] = 'categorie_id';
        $vars[] = 'rubrique_id';
        $vars[] = 'type_annonce';
        $vars[] = 'ville';
        return $vars;
    } );
} );

// Flush rewrite rules à l'activation et désactivation
register_activation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );

// Migration unique : assigner le template de page aux installations existantes
add_action( 'admin_init', function() {
    if ( get_option( 'seliweb_migrated_annonces_template' ) ) return;
    $page_ids = get_option( 'seliweb_page_ids', array() );
    $pid = isset( $page_ids['seliweb_annonces'] ) ? intval( $page_ids['seliweb_annonces'] ) : 0;
    if ( $pid > 0 ) {
        update_post_meta( $pid, '_wp_page_template', 'template-annonces.php' );
    }
    update_option( 'seliweb_migrated_annonces_template', '1' );
} );

new Seliweb();
