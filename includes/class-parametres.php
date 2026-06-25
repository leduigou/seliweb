<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seliweb_Parametres {

    private static $sel_saved            = false;
    private static $compte_sel_message   = '';
    private static $compte_sel_error     = false;

    // ----------------------------------------------------------------
    // Hook init : traitement des suppressions GET avant tout affichage
    // ----------------------------------------------------------------
    public static function init() {
        add_action( 'init',       array( __CLASS__, 'handle_get_actions'  ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_post_actions' ) );

        // Filtres emails — appliqués uniquement si des valeurs sont enregistrées
        add_filter( 'wp_mail_from',              array( __CLASS__, 'filter_mail_from'        ) );
        add_filter( 'wp_mail_from_name',         array( __CLASS__, 'filter_mail_from_name'   ) );
        add_filter( 'retrieve_password_title',   array( __CLASS__, 'filter_reset_pw_title'   ), 10, 3 );
        add_filter( 'retrieve_password_message', array( __CLASS__, 'filter_reset_pw_message' ), 10, 4 );
    }

    // ----------------------------------------------------------------
    // Cache interne des réglages emails (un seul SELECT par requête)
    // ----------------------------------------------------------------
    private static $mail_cfg = null;

    private static function mail_cfg() {
        if ( self::$mail_cfg !== null ) return self::$mail_cfg;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT cle, valeur FROM {$wpdb->prefix}seliweb_parametres WHERE cle LIKE 'mail\_%'"
        );
        self::$mail_cfg = array();
        foreach ( $rows as $r ) self::$mail_cfg[ $r->cle ] = $r->valeur;
        return self::$mail_cfg;
    }

    public static function filter_mail_from( $email ) {
        $v = self::mail_cfg()['mail_from_email'] ?? '';
        return ( $v && is_email( $v ) ) ? $v : $email;
    }

    public static function filter_mail_from_name( $name ) {
        $v = self::mail_cfg()['mail_from_name'] ?? '';
        return $v ?: $name;
    }

    public static function filter_reset_pw_title( $title, $user_login, $user_data ) {
        $v = self::mail_cfg()['mail_reset_subject'] ?? '';
        return $v ?: $title;
    }

    public static function filter_reset_pw_message( $message, $key, $user_login, $user_data ) {
        $cfg  = self::mail_cfg();
        $intro = trim( $cfg['mail_reset_intro'] ?? '' );
        $sig   = trim( $cfg['mail_reset_signature'] ?? '' );
        if ( $intro )  $message = $intro . "\r\n\r\n" . $message;
        if ( $sig )    $message = $message . "\r\n" . $sig;
        return $message;
    }

    public static function handle_post_actions() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_parametres' ) return;
        if ( ! isset( $_POST['seliweb_nonce'] ) ) return;
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        self::handle_post( $tab );
    }

    public static function handle_get_actions() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_parametres' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

        // Suppressions
        if ( isset( $_GET['delete_id'] ) ) {
            $id = intval( $_GET['delete_id'] );
            if ( ! check_admin_referer( 'seliweb_delete_' . $id ) ) return;
            switch ( $tab ) {
                case 'categories':
                    $table = $GLOBALS['wpdb']->prefix . 'seliweb_categories';
                    $row   = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT supprimable FROM $table WHERE id=%d", $id ) );
                    if ( $row && $row->supprimable ) {
                        // Vérifier si des rubriques sont rattachées
                        $nb_rub = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
                            "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}seliweb_rubriques WHERE categorie_id=%d", $id
                        ) );
                        if ( $nb_rub > 0 ) {
                            wp_safe_redirect( admin_url(
                                'admin.php?page=seliweb_parametres&tab=categories&error=has_rubriques'
                            ) );
                            exit;
                        }
                        $GLOBALS['wpdb']->delete( $table, array('id'=>$id) );
                    }
                    break;
                case 'rubriques':
                    // Vérifier si des annonces sont rattachées
                    $nb_ann = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
                        "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}seliweb_annonces WHERE rubrique_id=%d", $id
                    ) );
                    if ( $nb_ann > 0 ) {
                        wp_safe_redirect( admin_url(
                            'admin.php?page=seliweb_parametres&tab=rubriques&error=has_annonces'
                        ) );
                        exit;
                    }
                    $GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->prefix . 'seliweb_rubriques', array('id'=>$id) );
                    break;
                case 'statuts':
                    $table = $GLOBALS['wpdb']->prefix . 'seliweb_statuts';
                    $row   = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT slug FROM $table WHERE id=%d", $id ) );
                    if ( $row && ! in_array( $row->slug, array('urgent','repondu','expire'), true ) ) {
                        $GLOBALS['wpdb']->delete( $table, array('id'=>$id) );
                    }
                    break;
                case 'monnaies':
                    $table = $GLOBALS['wpdb']->prefix . 'seliweb_monnaies';
                    $row   = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT est_defaut FROM $table WHERE id=%d", $id ) );
                    if ( $row && ! $row->est_defaut ) $GLOBALS['wpdb']->delete( $table, array('id'=>$id) );
                    break;
            }
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=' . $tab . '&deleted=1' ) );
            exit;
        }

        // Définir monnaie par défaut
        if ( isset( $_GET['set_defaut'] ) ) {
            $id    = intval( $_GET['set_defaut'] );
            if ( ! check_admin_referer( 'seliweb_defaut_' . $id ) ) return;
            $table = $GLOBALS['wpdb']->prefix . 'seliweb_monnaies';
            $GLOBALS['wpdb']->update( $table, array('est_defaut'=>0), array('est_defaut'=>1) );
            $GLOBALS['wpdb']->update( $table, array('est_defaut'=>1), array('id'=>$id) );
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies' ) );
            exit;
        }
    }

    // ----------------------------------------------------------------
    // Point d'entrée de la page admin
    // ----------------------------------------------------------------
    public static function display() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        $tabs = array(
            'general'    => __( 'Général',     'seliweb' ),
            'groupes'    => __( 'Groupes',     'seliweb' ),
            'monnaies'   => __( 'Monnaies',    'seliweb' ),
            'categories' => __( 'Catégories',  'seliweb' ),
            'rubriques'  => __( 'Rubriques',   'seliweb' ),
            'statuts'    => __( 'Statuts',     'seliweb' ),
            'sel'        => __( 'SEL',         'seliweb' ),
            'mails'      => __( 'Mails',       'seliweb' ),
        );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Paramètres Seliweb', 'seliweb' ) . '</h1>';

        echo '<nav class="nav-tab-wrapper">';
        foreach ( $tabs as $key => $label ) {
            $active = ( $tab === $key ) ? ' nav-tab-active' : '';
            $url    = admin_url( 'admin.php?page=seliweb_parametres&tab=' . $key );
            echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . $active . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav><div class="tab-content" style="margin-top:20px;">';

        switch ( $tab ) {
            case 'general':   self::tab_general();   break;
            case 'groupes':   self::tab_groupes();   break;
            case 'monnaies':  self::tab_monnaies();  break;
            case 'rubriques': self::tab_rubriques(); break;
            case 'statuts':   self::tab_statuts();   break;
            case 'sel':       self::tab_sel();        break;
            case 'mails':     self::tab_mails();      break;
            default:          self::tab_categories(); break;
        }

        echo '</div></div>';
    }

    // ----------------------------------------------------------------
    // Traitement POST
    // ----------------------------------------------------------------
    private static function handle_post( $tab ) {
        // Les POST de l'onglet Groupes sont traités par Seliweb_Groupes::handle_post() (hook init)
        if ( $tab === 'groupes' ) return;
        // Suppressions GET gérées par handle_get_actions() via hook init
        // Ici on traite uniquement les POST (ajouts et modifications)
        if ( ! isset( $_POST['seliweb_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['seliweb_nonce'], 'seliweb_parametres' ) ) {
            wp_die( __( 'Sécurité : nonce invalide.', 'seliweb' ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Accès refusé.', 'seliweb' ) );
        }
        $action = isset( $_POST['seliweb_action'] ) ? sanitize_key( $_POST['seliweb_action'] ) : '';
        switch ( $tab ) {
            case 'general':    self::handle_general();             break;
            case 'categories': self::handle_categories( $action ); break;
            case 'rubriques':  self::handle_rubriques( $action );  break;
            case 'statuts':    self::handle_statuts( $action );    break;
            case 'monnaies':   self::handle_monnaies( $action );   break;
            case 'sel':        self::handle_sel( $action );        break;
            case 'mails':      self::handle_mails( $action );      break;
        }
    }

    // ================================================================
    // GÉNÉRAL
    // ================================================================
    private static function tab_general() {
        global $wpdb;
        $tp  = $wpdb->prefix . 'seliweb_parametres';
        $par_page = (int) $wpdb->get_var( "SELECT valeur FROM $tp WHERE cle='annonces_par_page' LIMIT 1" );
        if ( $par_page < 1 ) $par_page = 12;

        $photos_min_raw = $wpdb->get_var( "SELECT valeur FROM $tp WHERE cle='annonces_photos_min' LIMIT 1" );
        $photos_min     = $photos_min_raw !== null ? max( 0, min( 2, (int) $photos_min_raw ) ) : 1;

        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paramètres enregistrés.', 'seliweb' ) . '</p></div>';
        }
        ?>
        <form method="post">
            <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="save_general">
            <table class="form-table">
                <tr>
                    <th><label for="annonces_par_page"><?php esc_html_e( 'Annonces par page', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="number" id="annonces_par_page" name="annonces_par_page"
                               value="<?php echo intval( $par_page ); ?>" min="1" max="100" class="small-text">
                        <p class="description"><?php esc_html_e( 'Nombre d\'annonces affichées par page sur le site. La dernière page affiche le reste.', 'seliweb' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="annonces_photos_min"><?php esc_html_e( 'Photos obligatoires par annonce', 'seliweb' ); ?></label></th>
                    <td>
                        <select id="annonces_photos_min" name="annonces_photos_min">
                            <option value="0" <?php selected( $photos_min, 0 ); ?>><?php esc_html_e( '0 — Aucune photo obligatoire', 'seliweb' ); ?></option>
                            <option value="1" <?php selected( $photos_min, 1 ); ?>><?php esc_html_e( '1 — Photo 1 obligatoire', 'seliweb' ); ?></option>
                            <option value="2" <?php selected( $photos_min, 2 ); ?>><?php esc_html_e( '2 — Photos 1 et 2 obligatoires', 'seliweb' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Nombre de photos requises à la création d\'une annonce (frontend et backend).', 'seliweb' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Enregistrer', 'seliweb' ) ); ?>
        </form>
        <?php
    }

    private static function handle_general() {
        global $wpdb;
        $tp         = $wpdb->prefix . 'seliweb_parametres';
        $par_page   = max( 1, intval( $_POST['annonces_par_page'] ?? 12 ) );
        $photos_min = max( 0, min( 2, intval( $_POST['annonces_photos_min'] ?? 1 ) ) );

        foreach ( array( 'annonces_par_page' => $par_page, 'annonces_photos_min' => $photos_min ) as $cle => $valeur ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tp WHERE cle=%s LIMIT 1", $cle ) );
            if ( $exists ) {
                $wpdb->update( $tp, array( 'valeur' => $valeur ), array( 'cle' => $cle ) );
            } else {
                $wpdb->insert( $tp, array( 'cle' => $cle, 'valeur' => $valeur ) );
            }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=general&updated=1' ) );
        exit;
    }

    // ================================================================
    // GROUPES
    // ================================================================
    private static function tab_groupes() {
        Seliweb_Groupes::render_tab();
    }

    // ================================================================
    // CATÉGORIES
    // ================================================================
    private static function tab_categories() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] )     ? intval( $_GET['id'] )           : 0;
        if ( $action === 'new' || $action === 'edit' ) {
            self::form_categories( $id );
        } else {
            self::liste_categories();
        }
    }

    private static function liste_categories() {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_categories';
        $items = $wpdb->get_results( "SELECT * FROM $table ORDER BY id ASC" );

        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Catégorie enregistrée.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Catégorie supprimée.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'has_rubriques' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( "Suppression impossible : des rubriques sont rattachées à cette catégorie. Supprimez d'abord les rubriques correspondantes.", 'seliweb' ) . '</p></div>';
        }
        ?>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=categories&action=new' ) ); ?>"
               class="button button-primary"><?php esc_html_e( 'Ajouter une catégorie', 'seliweb' ); ?></a>
        </p>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead><tr>
                <th><?php esc_html_e( 'Nom', 'seliweb' ); ?></th>
                <th style="width:150px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $items as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row->nom ); ?></td>
                    <td>
                        <?php if ( $row->modifiable ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=categories&action=edit&id=' . $row->id ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
                        <?php endif; ?>
                        <?php if ( $row->supprimable ) : ?>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=seliweb_parametres&tab=categories&delete_id=' . $row->id ), 'seliweb_delete_' . $row->id ) ); ?>"
                               onclick="return confirm('<?php esc_attr_e( 'Supprimer ?', 'seliweb' ); ?>')"
                               style="color:#b32d2e;"><?php esc_html_e( 'Supprimer', 'seliweb' ); ?></a>
                        <?php endif; ?>
                        <?php if ( ! $row->modifiable && ! $row->supprimable ) : ?>
                            <em><?php esc_html_e( 'Par défaut', 'seliweb' ); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function form_categories( $id = 0 ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'seliweb_categories';
        $item     = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) ) : null;
        $back_url = admin_url( 'admin.php?page=seliweb_parametres&tab=categories' );
        ?>
        <p><a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Retour à la liste', 'seliweb' ); ?></a></p>
        <h2><?php echo $item ? esc_html__( 'Modifier la catégorie', 'seliweb' ) : esc_html__( 'Ajouter une catégorie', 'seliweb' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="<?php echo $item ? 'update_categorie' : 'add_categorie'; ?>">
            <?php if ( $item ) : ?><input type="hidden" name="id" value="<?php echo intval( $item->id ); ?>"><?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Nom', 'seliweb' ); ?></th>
                    <td><input type="text" name="nom" class="regular-text" value="<?php echo $item ? esc_attr( $item->nom ) : ''; ?>" required></td>
                </tr>
            </table>
            <p>
                <?php submit_button( $item ? __( 'Mettre à jour', 'seliweb' ) : __( 'Ajouter', 'seliweb' ), 'primary', 'submit', false ); ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="button" style="margin-left:8px;"><?php esc_html_e( 'Annuler', 'seliweb' ); ?></a>
            </p>
        </form>
        <?php
    }

    private static function handle_categories( $action ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_categories';
        if ( $action === 'add_categorie' ) {
            $nom = sanitize_text_field( wp_unslash( $_POST['nom'] ) );
            $wpdb->insert( $table, array( 'nom' => $nom, 'slug' => sanitize_title( $nom ) ) );
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=categories&updated=1' ) );
            exit;
        }
        if ( $action === 'update_categorie' ) {
            $id  = intval( $_POST['id'] );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT modifiable FROM $table WHERE id=%d", $id ) );
            if ( $row && $row->modifiable ) {
                $nom = sanitize_text_field( wp_unslash( $_POST['nom'] ) );
                $wpdb->update( $table, array( 'nom' => $nom, 'slug' => sanitize_title( $nom ) ), array( 'id' => $id ) );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=categories&updated=1' ) );
            exit;
        }
    }

    // ================================================================
    // RUBRIQUES
    // ================================================================
    private static function tab_rubriques() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] )     ? intval( $_GET['id'] )           : 0;
        if ( $action === 'new' || $action === 'edit' ) {
            self::form_rubriques( $id );
        } else {
            self::liste_rubriques();
        }
    }

    private static function liste_rubriques() {
        global $wpdb;
        $table_r    = $wpdb->prefix . 'seliweb_rubriques';
        $table_c    = $wpdb->prefix . 'seliweb_categories';
        $categories = $wpdb->get_results( "SELECT * FROM $table_c ORDER BY nom" );
        $cat_filter = isset( $_GET['cat_filter'] ) ? intval( $_GET['cat_filter'] ) : 0;

        if ( $cat_filter ) {
            $items = $wpdb->get_results( $wpdb->prepare(
                "SELECT r.*, c.nom AS cat_nom FROM $table_r r LEFT JOIN $table_c c ON r.categorie_id=c.id WHERE r.categorie_id=%d ORDER BY r.nom",
                $cat_filter
            ) );
        } else {
            $items = $wpdb->get_results( "SELECT r.*, c.nom AS cat_nom FROM $table_r r LEFT JOIN $table_c c ON r.categorie_id=c.id ORDER BY c.nom, r.nom" );
        }

        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rubrique enregistrée.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rubrique supprimée.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'has_annonces' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( "Suppression impossible : des annonces sont rattachées à cette rubrique. Modifiez d'abord ces annonces.", 'seliweb' ) . '</p></div>';
        }
        ?>
        <p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=rubriques&action=new' ) ); ?>"
               class="button button-primary"><?php esc_html_e( 'Ajouter une rubrique', 'seliweb' ); ?></a>

            <?php if ( count( $categories ) > 1 ) : ?>
            <form method="get" style="display:contents;">
                <input type="hidden" name="page" value="seliweb_parametres">
                <input type="hidden" name="tab"  value="rubriques">
                <select name="cat_filter" onchange="this.form.submit()" style="max-width:220px;">
                    <option value=""><?php esc_html_e( '— Toutes les catégories —', 'seliweb' ); ?></option>
                    <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo intval( $cat->id ); ?>" <?php selected( $cat_filter, $cat->id ); ?>>
                            <?php echo esc_html( $cat->nom ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </p>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead><tr>
                <?php if ( ! $cat_filter ) : ?>
                <th><?php esc_html_e( 'Catégorie', 'seliweb' ); ?></th>
                <?php endif; ?>
                <th><?php esc_html_e( 'Rubrique', 'seliweb' ); ?></th>
                <th style="width:150px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $items as $row ) : ?>
                <tr>
                    <?php if ( ! $cat_filter ) : ?>
                    <td><?php echo esc_html( $row->cat_nom ); ?></td>
                    <?php endif; ?>
                    <td><?php echo esc_html( $row->nom ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=rubriques&action=edit&id=' . $row->id ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=seliweb_parametres&tab=rubriques&delete_id=' . $row->id ), 'seliweb_delete_' . $row->id ) ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Supprimer ?', 'seliweb' ); ?>')"
                           style="color:#b32d2e;"><?php esc_html_e( 'Supprimer', 'seliweb' ); ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $items ) ) : ?>
                <tr><td colspan="<?php echo $cat_filter ? 2 : 3; ?>"><em><?php esc_html_e( 'Aucune rubrique.', 'seliweb' ); ?></em></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function form_rubriques( $id = 0 ) {
        global $wpdb;
        $table_r    = $wpdb->prefix . 'seliweb_rubriques';
        $table_c    = $wpdb->prefix . 'seliweb_categories';
        $item       = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_r WHERE id=%d", $id ) ) : null;
        $categories = $wpdb->get_results( "SELECT * FROM $table_c ORDER BY nom" );
        $back_url   = admin_url( 'admin.php?page=seliweb_parametres&tab=rubriques' );
        ?>
        <p><a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Retour à la liste', 'seliweb' ); ?></a></p>
        <h2><?php echo $item ? esc_html__( 'Modifier la rubrique', 'seliweb' ) : esc_html__( 'Ajouter une rubrique', 'seliweb' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="<?php echo $item ? 'update_rubrique' : 'add_rubrique'; ?>">
            <?php if ( $item ) : ?><input type="hidden" name="id" value="<?php echo intval( $item->id ); ?>"><?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Catégorie', 'seliweb' ); ?></th>
                    <td>
                        <select name="categorie_id" required>
                            <option value=""><?php esc_html_e( '— Choisir —', 'seliweb' ); ?></option>
                            <?php foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo intval( $cat->id ); ?>" <?php selected( $item ? $item->categorie_id : '', $cat->id ); ?>>
                                    <?php echo esc_html( $cat->nom ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Nom', 'seliweb' ); ?></th>
                    <td><input type="text" name="nom" class="regular-text" value="<?php echo $item ? esc_attr( $item->nom ) : ''; ?>" required></td>
                </tr>
            </table>
            <p>
                <?php submit_button( $item ? __( 'Mettre à jour', 'seliweb' ) : __( 'Ajouter', 'seliweb' ), 'primary', 'submit', false ); ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="button" style="margin-left:8px;"><?php esc_html_e( 'Annuler', 'seliweb' ); ?></a>
            </p>
        </form>
        <?php
    }

    private static function handle_rubriques( $action ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_rubriques';
        if ( $action === 'add_rubrique' ) {
            $wpdb->insert( $table, array(
                'categorie_id' => intval( $_POST['categorie_id'] ),
                'nom'          => sanitize_text_field( wp_unslash( $_POST['nom'] ) ),
            ) );
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=rubriques&updated=1' ) );
            exit;
        }
        if ( $action === 'update_rubrique' ) {
            $wpdb->update( $table, array(
                'categorie_id' => intval( $_POST['categorie_id'] ),
                'nom'          => sanitize_text_field( wp_unslash( $_POST['nom'] ) ),
            ), array( 'id' => intval( $_POST['id'] ) ) );
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=rubriques&updated=1' ) );
            exit;
        }
    }

    // ================================================================
    // STATUTS
    // ================================================================
    private static function tab_statuts() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] )     ? intval( $_GET['id'] )           : 0;
        if ( $action === 'new' || $action === 'edit' ) {
            self::form_statuts( $id );
        } else {
            self::liste_statuts();
        }
    }

    private static function liste_statuts() {
        global $wpdb;
        $table         = $wpdb->prefix . 'seliweb_statuts';
        $items         = $wpdb->get_results( "SELECT * FROM $table ORDER BY id ASC" );
        $slugs_systeme = array( 'urgent', 'repondu', 'expire' );

        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Statut ajouté.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Statut supprimé.', 'seliweb' ) . '</p></div>';
        ?>
        <p class="description" style="margin-bottom:12px;">
            <?php esc_html_e( 'Les statuts Urgent, Répondu et Expiré sont des statuts système non modifiables. Vous pouvez ajouter vos propres statuts.', 'seliweb' ); ?>
        </p>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=statuts&action=new' ) ); ?>"
               class="button button-primary"><?php esc_html_e( 'Ajouter un statut', 'seliweb' ); ?></a>
        </p>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead><tr>
                <th><?php esc_html_e( 'Nom', 'seliweb' ); ?></th>
                <th style="width:180px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $items as $row ) :
                $is_systeme = in_array( $row->slug, $slugs_systeme, true );
            ?>
                <tr>
                    <td>
                        <?php echo esc_html( $row->nom ); ?>
                        <?php if ( $is_systeme ) : ?>
                            <em style="color:#888;font-size:12px;"> — <?php esc_html_e( 'Système', 'seliweb' ); ?></em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( ! $is_systeme ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=statuts&action=edit&id=' . $row->id ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url( wp_nonce_url(
                                admin_url( 'admin.php?page=seliweb_parametres&tab=statuts&delete_id=' . $row->id ),
                                'seliweb_delete_' . $row->id
                            ) ); ?>"
                               onclick="return confirm(<?php echo wp_json_encode( __( 'Supprimer ce statut ?', 'seliweb' ) ); ?>)"
                               style="color:#b32d2e;">
                                <?php esc_html_e( 'Supprimer', 'seliweb' ); ?>
                            </a>
                        <?php else : ?>
                            <em style="color:#aaa;font-size:12px;"><?php esc_html_e( 'Non modifiable', 'seliweb' ); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function form_statuts( $id = 0 ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'seliweb_statuts';
        $item     = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND modifiable=1", $id ) ) : null;
        $back_url = admin_url( 'admin.php?page=seliweb_parametres&tab=statuts' );
        ?>
        <p><a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Retour à la liste', 'seliweb' ); ?></a></p>
        <h2><?php echo $item ? esc_html__( 'Modifier le statut', 'seliweb' ) : esc_html__( 'Ajouter un statut', 'seliweb' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="<?php echo $item ? 'update_statut' : 'add_statut'; ?>">
            <?php if ( $item ) : ?><input type="hidden" name="id" value="<?php echo intval( $item->id ); ?>"><?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Nom du statut', 'seliweb' ); ?></th>
                    <td>
                        <input type="text" name="nom" class="regular-text"
                               value="<?php echo $item ? esc_attr( $item->nom ) : ''; ?>"
                               placeholder="<?php esc_attr_e( 'Ex. : En attente', 'seliweb' ); ?>" required>
                    </td>
                </tr>
            </table>
            <p>
                <?php submit_button( $item ? __( 'Mettre à jour', 'seliweb' ) : __( 'Ajouter', 'seliweb' ), 'primary', 'submit', false ); ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="button" style="margin-left:8px;"><?php esc_html_e( 'Annuler', 'seliweb' ); ?></a>
            </p>
        </form>
        <?php
    }

    private static function handle_statuts( $action ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_statuts';

        if ( $action === 'add_statut' ) {
            $nom  = sanitize_text_field( wp_unslash( $_POST['nom'] ) );
            $slug = sanitize_title( $nom );
            $existe = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE slug=%s LIMIT 1", $slug ) );
            if ( ! $existe && $nom ) {
                $wpdb->insert( $table, array(
                    'nom'         => $nom,
                    'slug'        => $slug,
                    'modifiable'  => 1,
                    'supprimable' => 1,
                ) );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=statuts&updated=1' ) );
            exit;
        }

        if ( $action === 'update_statut' ) {
            $id  = intval( $_POST['id'] );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT modifiable FROM $table WHERE id=%d", $id ) );
            if ( $row && $row->modifiable ) {
                $nom = sanitize_text_field( wp_unslash( $_POST['nom'] ) );
                $wpdb->update( $table, array(
                    'nom'  => $nom,
                    'slug' => sanitize_title( $nom ),
                ), array( 'id' => $id ) );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=statuts&updated=1' ) );
            exit;
        }
    }

    private static function tab_monnaies() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] )     ? intval( $_GET['id'] )           : 0;
        if ( $action === 'new' || $action === 'edit' ) {
            self::form_monnaies( $id );
        } else {
            self::liste_monnaies();
        }
    }

    private static function liste_monnaies() {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_monnaies';
        $items = $wpdb->get_results( "SELECT * FROM $table ORDER BY est_defaut DESC, nom ASC" );

        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Monnaie enregistrée.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Monnaie supprimée.', 'seliweb' ) . '</p></div>';
        ?>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies&action=new' ) ); ?>"
               class="button button-primary"><?php esc_html_e( 'Ajouter une monnaie', 'seliweb' ); ?></a>
        </p>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead><tr>
                <th><?php esc_html_e( 'Nom', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Symbole', 'seliweb' ); ?></th>
                <th style="width:80px;text-align:center;"><?php esc_html_e( 'Par défaut', 'seliweb' ); ?></th>
                <th style="width:150px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $items as $row ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $row->nom ); ?></strong></td>
                    <td><?php echo esc_html( $row->symbole ); ?></td>
                    <td style="text-align:center;">
                        <?php if ( $row->est_defaut ) : ?>
                            <span style="color:green;font-weight:700;" title="<?php esc_attr_e( 'Monnaie par défaut', 'seliweb' ); ?>">&#10003;</span>
                        <?php else : ?>
                            <a href="<?php echo esc_url( wp_nonce_url(
                                admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies&set_defaut=' . $row->id ),
                                'seliweb_defaut_' . $row->id
                            ) ); ?>" style="font-size:11px;"><?php esc_html_e( 'Définir', 'seliweb' ); ?></a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies&action=edit&id=' . $row->id ) ); ?>">
                            <?php esc_html_e( 'Modifier', 'seliweb' ); ?>
                        </a>
                        <?php if ( ! $row->est_defaut ) : ?>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url( wp_nonce_url(
                                admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies&delete_id=' . $row->id ),
                                'seliweb_delete_' . $row->id
                            ) ); ?>"
                               onclick="return confirm('<?php esc_attr_e( 'Supprimer cette monnaie ?', 'seliweb' ); ?>')"
                               style="color:#b32d2e;"><?php esc_html_e( 'Supprimer', 'seliweb' ); ?></a>
                        <?php else : ?>
                            &nbsp;<em style="color:#888;font-size:12px;"><?php esc_html_e( '(par défaut — non supprimable)', 'seliweb' ); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function form_monnaies( $id = 0 ) {
        global $wpdb;
        $table     = $wpdb->prefix . 'seliweb_monnaies';
        $item      = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) ) : null;
        $defaut_id = $wpdb->get_var( "SELECT id FROM $table WHERE est_defaut=1 LIMIT 1" );
        $back_url  = admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies' );
        ?>
        <p><a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Retour à la liste', 'seliweb' ); ?></a></p>
        <h2><?php echo $item ? esc_html__( 'Modifier la monnaie', 'seliweb' ) : esc_html__( 'Ajouter une monnaie', 'seliweb' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="<?php echo $item ? 'update_monnaie' : 'add_monnaie'; ?>">
            <?php if ( $item ) : ?><input type="hidden" name="id" value="<?php echo intval( $item->id ); ?>"><?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Nom', 'seliweb' ); ?> *</th>
                    <td><input type="text" name="nom" class="regular-text"
                               value="<?php echo $item ? esc_attr( $item->nom ) : ''; ?>" required></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Symbole', 'seliweb' ); ?></th>
                    <td><input type="text" name="symbole" class="small-text"
                               value="<?php echo $item ? esc_attr( $item->symbole ) : ''; ?>"></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Monnaie par défaut', 'seliweb' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="est_defaut" value="1"
                                   <?php checked( $item ? $item->est_defaut : 0 ); ?>>
                            <?php esc_html_e( 'Définir comme monnaie par défaut', 'seliweb' ); ?>
                        </label>
                        <?php if ( $defaut_id && ( ! $item || $defaut_id != $item->id ) ) : ?>
                            <p class="description" style="color:#b32d2e;">
                                <?php
                                $nom_defaut = $wpdb->get_var( $wpdb->prepare( "SELECT nom FROM $table WHERE id=%d", $defaut_id ) );
                                printf( esc_html__( 'Actuellement : "%s". Cocher remplacera cette monnaie par défaut.', 'seliweb' ), esc_html( $nom_defaut ) );
                                ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p>
                <?php submit_button( $item ? __( 'Mettre à jour', 'seliweb' ) : __( 'Ajouter', 'seliweb' ), 'primary', 'submit', false ); ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="button" style="margin-left:8px;"><?php esc_html_e( 'Annuler', 'seliweb' ); ?></a>
            </p>
        </form>
        <?php
    }

    // ================================================================
    // SEL
    // ================================================================
    private static function tab_sel() {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_parametres';

        $rows = $wpdb->get_results( "SELECT cle, valeur FROM $tp WHERE cle LIKE 'sel_%'" );
        $settings = array();
        foreach ( $rows as $row ) {
            $settings[ $row->cle ] = $row->valeur;
        }

        $sel_actif              = ! empty( $settings['sel_actif'] );
        $sel_groupe_id          = isset( $settings['sel_groupe_id'] )        ? intval( $settings['sel_groupe_id'] )       : 0;
        $sel_monnaie_id         = isset( $settings['sel_monnaie_id'] )       ? intval( $settings['sel_monnaie_id'] )      : 0;
        $sel_decouvert_possible = ! empty( $settings['sel_decouvert_possible'] );
        $sel_decouvert_max      = isset( $settings['sel_decouvert_max'] )    ? intval( $settings['sel_decouvert_max'] ) : 0;

        $groupes  = $wpdb->get_results( "SELECT id, nom FROM {$wpdb->prefix}seliweb_groupes ORDER BY nom ASC" );
        $monnaies = $wpdb->get_results( "SELECT id, nom, symbole FROM {$wpdb->prefix}seliweb_monnaies ORDER BY nom ASC" );
        ?>
        <h2><?php esc_html_e( "Système d'échange local", 'seliweb' ); ?></h2>

        <?php if ( self::$sel_saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Paramètres SEL enregistrés.', 'seliweb' ); ?></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="save_sel">

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Fonctionne comme un SEL', 'seliweb' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="sel_actif" value="1" id="sel_actif" <?php checked( $sel_actif ); ?>>
                            <?php esc_html_e( 'Activer le mode SEL', 'seliweb' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( "L'activation de ce paramètre confirmera que vous êtes un SEL, activera le module transactions et la numérotation des membres. Avant d'activer ce paramètre, créez un groupe du genre \"Membres du SEL\" (onglet Groupes) et créez la monnaie du SEL (onglet Monnaies). Le groupe qui sera défini comme le groupe principal du SEL ne devrait plus être modifié.", 'seliweb' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <div id="sel-options" style="<?php echo $sel_actif ? '' : 'display:none;'; ?>">

                <h3 style="margin-top:24px;"><?php esc_html_e( 'Groupe et monnaie', 'seliweb' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Groupe SEL', 'seliweb' ); ?></th>
                        <td>
                            <select name="sel_groupe_id">
                                <option value=""><?php esc_html_e( '— Choisir un groupe —', 'seliweb' ); ?></option>
                                <?php foreach ( $groupes as $g ) : ?>
                                    <option value="<?php echo intval( $g->id ); ?>" <?php selected( $sel_groupe_id, $g->id ); ?>>
                                        <?php echo esc_html( $g->nom ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Monnaie du SEL', 'seliweb' ); ?></th>
                        <td>
                            <select name="sel_monnaie_id">
                                <option value=""><?php esc_html_e( '— Choisir une monnaie —', 'seliweb' ); ?></option>
                                <?php foreach ( $monnaies as $mon ) : ?>
                                    <option value="<?php echo intval( $mon->id ); ?>" <?php selected( $sel_monnaie_id, $mon->id ); ?>>
                                        <?php echo esc_html( $mon->nom );
                                        if ( $mon->symbole ) echo ' (' . esc_html( $mon->symbole ) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Module Transactions', 'seliweb' ); ?></th>
                        <td>
                            <label style="opacity:0.6;">
                                <input type="checkbox" disabled checked>
                                <?php esc_html_e( 'Module Transactions activé', 'seliweb' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top:24px;"><?php esc_html_e( 'Membres', 'seliweb' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Découvert', 'seliweb' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="sel_decouvert_possible" value="1" id="sel_decouvert_possible" <?php checked( $sel_decouvert_possible ); ?>>
                                <?php esc_html_e( 'Découvert Possible', 'seliweb' ); ?>
                            </label>
                            <div id="sel-decouvert-montant" style="<?php echo $sel_decouvert_possible ? '' : 'display:none;'; ?> margin-top:8px;">
                                <label>
                                    <?php esc_html_e( 'Montant max du découvert', 'seliweb' ); ?>
                                    <input type="number" name="sel_decouvert_max" value="<?php echo esc_attr( intval( $sel_decouvert_max ) ); ?>" min="0" step="1" class="small-text" style="margin:0 6px;">
                                </label>
                                <p class="description"><?php esc_html_e( "Ce montant pourra être ajusté pour chaque membre par l'administrateur.", 'seliweb' ); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Numérotation', 'seliweb' ); ?></th>
                        <td>
                            <label style="opacity:0.6;">
                                <input type="checkbox" disabled checked>
                                <?php esc_html_e( 'Numérotation des membres activée', 'seliweb' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

            </div>

            <?php submit_button( __( 'Enregistrer', 'seliweb' ) ); ?>
        </form>

        <?php
        // Section Compte du SEL — hors du formulaire principal (formulaire imbriqué interdit)
        if ( $sel_actif && $sel_groupe_id ) :
            $tm = $wpdb->prefix . 'seliweb_membres';
            $compte_row = $wpdb->get_row(
                "SELECT m.id, m.wp_user_id FROM {$tm} m WHERE m.numero_sel = 1 LIMIT 1"
            );
            $compte_info = null;
            if ( $compte_row ) {
                $u = get_userdata( $compte_row->wp_user_id );
                $compte_info = array(
                    'email'  => $u ? $u->user_email : '',
                    'prenom' => get_user_meta( $compte_row->wp_user_id, 'first_name', true ),
                    'nom'    => get_user_meta( $compte_row->wp_user_id, 'last_name',  true ),
                );
            }
        ?>
        <h3 style="margin-top:28px;"><?php esc_html_e( 'Compte du SEL N°1', 'seliweb' ); ?></h3>

        <?php if ( self::$compte_sel_message ) : ?>
            <div class="notice <?php echo self::$compte_sel_error ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                <p><?php echo esc_html( self::$compte_sel_message ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( $compte_info ) : ?>
            <p style="margin-bottom:12px;">
                <?php
                $label = trim( $compte_info['prenom'] . ' ' . $compte_info['nom'] );
                echo esc_html( 'N°1' . ( $label ? ' — ' . $label : '' ) . ' — ' . $compte_info['email'] );
                ?>
            </p>
            <form method="post" style="max-width:600px;">
                <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
                <input type="hidden" name="seliweb_action" value="update_compte_sel_email">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Modifier l\'email', 'seliweb' ); ?></th>
                        <td>
                            <input type="email" name="sel_email" class="regular-text" required
                                   value="<?php echo esc_attr( $compte_info['email'] ); ?>">
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Enregistrer l\'email', 'seliweb' ), 'secondary' ); ?>
            </form>
        <?php else : ?>
            <p class="description" style="margin-bottom:12px;">
                <?php esc_html_e( "Aucun compte N°1 défini. Créez-en un pour pouvoir saisir des transactions avec le compte de l'association.", 'seliweb' ); ?>
            </p>
            <form method="post">
                <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
                <input type="hidden" name="seliweb_action" value="create_compte_sel">
                <table class="form-table" style="max-width:600px;">
                    <tr>
                        <th><?php esc_html_e( 'Email du SEL', 'seliweb' ); ?></th>
                        <td>
                            <input type="email" name="sel_email" class="regular-text" required
                                   placeholder="contact@monsel.fr"
                                   value="<?php echo esc_attr( $_POST['sel_email'] ?? '' ); ?>">
                            <p class="description">
                                <?php esc_html_e( "Un utilisateur WordPress sera créé pour ce compte. Il représente l'association, pas une personne physique.", 'seliweb' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Créer le compte du SEL', 'seliweb' ), 'secondary' ); ?>
            </form>
        <?php endif; ?>

        <?php endif; // sel_actif && sel_groupe_id ?>

        <script>
        (function(){
            var chkSel = document.getElementById('sel_actif');
            var divOpt = document.getElementById('sel-options');
            var chkDec = document.getElementById('sel_decouvert_possible');
            var divDec = document.getElementById('sel-decouvert-montant');
            chkSel.addEventListener('change', function(){ divOpt.style.display = this.checked ? '' : 'none'; });
            if ( chkDec ) {
                chkDec.addEventListener('change', function(){ divDec.style.display = this.checked ? '' : 'none'; });
            }
        })();
        </script>
        <?php
    }

    private static function handle_sel( $action ) {
        if ( $action === 'create_compte_sel' ) {
            self::create_compte_sel();
            return;
        }
        if ( $action === 'update_compte_sel_email' ) {
            self::update_compte_sel_email();
            return;
        }
        if ( $action !== 'save_sel' ) return;
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_parametres';

        $sel_actif              = isset( $_POST['sel_actif'] )              ? 1 : 0;
        $sel_groupe_id          = isset( $_POST['sel_groupe_id'] )          ? intval( $_POST['sel_groupe_id'] )        : 0;
        $sel_monnaie_id         = isset( $_POST['sel_monnaie_id'] )         ? intval( $_POST['sel_monnaie_id'] )       : 0;
        $sel_decouvert_possible = isset( $_POST['sel_decouvert_possible'] ) ? 1 : 0;
        $sel_decouvert_max      = isset( $_POST['sel_decouvert_max'] )      ? intval( $_POST['sel_decouvert_max'] )    : 0;

        $params = array(
            'sel_actif'              => $sel_actif,
            'sel_groupe_id'          => $sel_groupe_id,
            'sel_monnaie_id'         => $sel_monnaie_id,
            'sel_decouvert_possible' => $sel_decouvert_possible,
            'sel_decouvert_max'      => $sel_decouvert_max,
        );

        foreach ( $params as $cle => $valeur ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tp WHERE cle=%s LIMIT 1", $cle ) );
            if ( $exists ) {
                $wpdb->update( $tp, array( 'valeur' => $valeur ), array( 'cle' => $cle ) );
            } else {
                $wpdb->insert( $tp, array( 'cle' => $cle, 'valeur' => $valeur ) );
            }
        }

        // Appliquer le montant découvert aux membres du groupe SEL dont le champ est NULL
        if ( $sel_decouvert_possible && $sel_groupe_id && $sel_decouvert_max > 0 ) {
            $tm = $wpdb->prefix . 'seliweb_membres';
            $wpdb->query( $wpdb->prepare(
                "UPDATE `$tm` SET decouvert_max = %d WHERE groupe_id = %d AND decouvert_max IS NULL",
                $sel_decouvert_max, $sel_groupe_id
            ) );
        }

        self::$sel_saved = true;
    }

    private static function update_compte_sel_email() {
        global $wpdb;
        $tm = $wpdb->prefix . 'seliweb_membres';

        $email = sanitize_email( wp_unslash( $_POST['sel_email'] ?? '' ) );
        if ( ! is_email( $email ) ) {
            self::$compte_sel_message = __( 'Adresse email invalide.', 'seliweb' );
            self::$compte_sel_error   = true;
            return;
        }

        $compte_row = $wpdb->get_row( "SELECT wp_user_id FROM $tm WHERE numero_sel = 1 LIMIT 1" );
        if ( ! $compte_row ) {
            self::$compte_sel_message = __( 'Compte SEL N°1 introuvable.', 'seliweb' );
            self::$compte_sel_error   = true;
            return;
        }

        $result = wp_update_user( array( 'ID' => $compte_row->wp_user_id, 'user_email' => $email ) );
        if ( is_wp_error( $result ) ) {
            self::$compte_sel_message = sprintf( __( 'Erreur : %s', 'seliweb' ), $result->get_error_message() );
            self::$compte_sel_error   = true;
            return;
        }

        self::$compte_sel_message = __( 'Email du compte SEL mis à jour.', 'seliweb' );
    }

    private static function create_compte_sel() {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_parametres';
        $tm = $wpdb->prefix . 'seliweb_membres';

        $email = sanitize_email( wp_unslash( $_POST['sel_email'] ?? '' ) );
        if ( ! is_email( $email ) ) {
            self::$compte_sel_message = __( 'Adresse email invalide.', 'seliweb' );
            self::$compte_sel_error   = true;
            return;
        }

        if ( $wpdb->get_var( "SELECT id FROM $tm WHERE numero_sel = 1 LIMIT 1" ) ) {
            self::$compte_sel_message = __( 'Le compte SEL N°1 existe déjà.', 'seliweb' );
            return;
        }

        $sel_groupe_id = intval( $wpdb->get_var( "SELECT valeur FROM $tp WHERE cle='sel_groupe_id' LIMIT 1" ) );
        if ( ! $sel_groupe_id ) {
            self::$compte_sel_message = __( "Définissez d'abord le groupe SEL avant de créer le compte.", 'seliweb' );
            self::$compte_sel_error   = true;
            return;
        }

        // Utiliser un WP user existant ou en créer un nouveau
        $wp_user = get_user_by( 'email', $email );
        if ( $wp_user ) {
            $user_id = $wp_user->ID;
        } else {
            $login = 'sel-compte';
            $i = 1;
            while ( username_exists( $login ) ) {
                $login = 'sel-compte-' . $i++;
            }
            $user_id = wp_create_user( $login, wp_generate_password( 24, true, true ), $email );
            if ( is_wp_error( $user_id ) ) {
                self::$compte_sel_message = sprintf( __( 'Erreur création utilisateur : %s', 'seliweb' ), $user_id->get_error_message() );
                self::$compte_sel_error   = true;
                return;
            }
            wp_update_user( array(
                'ID'           => $user_id,
                'display_name' => __( 'Compte SEL', 'seliweb' ),
                'role'         => 'subscriber',
            ) );
        }

        // Mettre à jour ou créer l'enregistrement membre
        $membre_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tm WHERE wp_user_id = %d LIMIT 1", $user_id ) );
        if ( $membre_id ) {
            $wpdb->update( $tm,
                array( 'numero_sel' => 1, 'groupe_id' => $sel_groupe_id ),
                array( 'id' => $membre_id )
            );
        } else {
            $wpdb->insert( $tm, array( 'wp_user_id' => $user_id, 'numero_sel' => 1, 'groupe_id' => $sel_groupe_id ) );
        }

        self::$compte_sel_message = __( 'Compte SEL N°1 créé avec succès.', 'seliweb' );
    }

    // ================================================================
    // MONNAIES
    // ================================================================
    private static function handle_monnaies( $action ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_monnaies';

        if ( $action === 'add_monnaie' ) {
            $est_defaut = isset( $_POST['est_defaut'] ) ? 1 : 0;
            if ( $est_defaut ) {
                $wpdb->update( $table, array( 'est_defaut' => 0 ), array( 'est_defaut' => 1 ) );
            }
            $wpdb->insert( $table, array(
                'nom'        => sanitize_text_field( wp_unslash( $_POST['nom'] ) ),
                'symbole'    => sanitize_text_field( wp_unslash( $_POST['symbole'] ) ),
                'est_defaut' => $est_defaut,
            ) );
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies&updated=1' ) );
            exit;
        }

        if ( $action === 'update_monnaie' ) {
            $id         = intval( $_POST['id'] );
            $est_defaut = isset( $_POST['est_defaut'] ) ? 1 : 0;
            if ( $est_defaut ) {
                $wpdb->update( $table, array( 'est_defaut' => 0 ), array( 'est_defaut' => 1 ) );
            }
            $wpdb->update( $table, array(
                'nom'        => sanitize_text_field( wp_unslash( $_POST['nom'] ) ),
                'symbole'    => sanitize_text_field( wp_unslash( $_POST['symbole'] ) ),
                'est_defaut' => $est_defaut,
            ), array( 'id' => $id ) );
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies&updated=1' ) );
            exit;
        }
    }

    // ================================================================
    // MAILS
    // ================================================================

    // Catalogue statique des emails configurables
    private static function mail_list() {
        return array(
            'reset_password' => array(
                'label' => __( 'Réinitialisation du mot de passe', 'seliweb' ),
                'desc'  => __( 'Envoyé lorsqu\'un utilisateur demande à réinitialiser son mot de passe depuis la page de connexion.', 'seliweb' ),
            ),
        );
    }

    private static function tab_mails() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $slug   = isset( $_GET['mail'] )   ? sanitize_key( $_GET['mail'] )   : '';
        if ( $action === 'edit' && isset( self::mail_list()[ $slug ] ) ) {
            self::form_mails( $slug );
        } else {
            self::liste_mails();
        }
    }

    private static function liste_mails() {
        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Mail enregistré.', 'seliweb' ) . '</p></div>';
        }
        ?>
        <p class="description" style="margin-bottom:16px;">
            <?php esc_html_e( 'Personnalisez l\'expéditeur, le sujet et le contenu des emails envoyés par Seliweb.', 'seliweb' ); ?>
        </p>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e( 'Nom du mail', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Description', 'seliweb' ); ?></th>
                <th style="width:100px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( self::mail_list() as $slug => $info ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $info['label'] ); ?></strong></td>
                    <td><?php echo esc_html( $info['desc'] ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=mails&action=edit&mail=' . $slug ) ); ?>">
                            <?php esc_html_e( 'Modifier', 'seliweb' ); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function form_mails( $slug ) {
        global $wpdb;
        $tp  = $wpdb->prefix . 'seliweb_parametres';
        $cfg = self::mail_cfg();

        $back_url   = admin_url( 'admin.php?page=seliweb_parametres&tab=mails' );
        $mail_list  = self::mail_list();
        $mail_label = $mail_list[ $slug ]['label'] ?? $slug;

        $from_email = $cfg['mail_from_email']    ?? '';
        $from_name  = $cfg['mail_from_name']     ?? '';
        $subject    = $cfg['mail_reset_subject'] ?? '';
        $intro      = $cfg['mail_reset_intro']   ?? '';
        $signature  = $cfg['mail_reset_signature'] ?? '';

        // Valeurs par défaut WordPress pour l'affichage
        $default_from_email = 'wordpress@' . preg_replace( '/^www\./', '', strtolower( $_SERVER['HTTP_HOST'] ?? 'votre-site.fr' ) );
        $default_subject    = sprintf( '[%s] %s', get_bloginfo('name'), __('Réinitialisation du mot de passe', 'seliweb') );
        ?>
        <p><a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Retour à la liste', 'seliweb' ); ?></a></p>
        <?php if ( isset( $_GET['error'] ) && $_GET['error'] === 'bad_email' ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Adresse email expéditeur invalide.', 'seliweb' ); ?></p></div>
        <?php endif; ?>
        <h2><?php echo esc_html( $mail_label ); ?></h2>

        <form method="post">
            <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="save_mail">
            <input type="hidden" name="mail_slug" value="<?php echo esc_attr( $slug ); ?>">

            <h3 style="margin-top:8px;"><?php esc_html_e( 'Expéditeur', 'seliweb' ); ?></h3>
            <p class="description" style="margin-bottom:12px;">
                <?php esc_html_e( 'Ces réglages s\'appliquent à tous les emails envoyés par le site.', 'seliweb' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th><label for="mail_from_name"><?php esc_html_e( 'Nom de l\'expéditeur', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="text" id="mail_from_name" name="mail_from_name" class="regular-text"
                               value="<?php echo esc_attr( $from_name ); ?>"
                               placeholder="<?php echo esc_attr( get_bloginfo('name') ); ?>">
                        <p class="description"><?php printf( esc_html__( 'Par défaut : "%s"', 'seliweb' ), esc_html( get_bloginfo('name') ) ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="mail_from_email"><?php esc_html_e( 'Adresse email de l\'expéditeur', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="email" id="mail_from_email" name="mail_from_email" class="regular-text"
                               value="<?php echo esc_attr( $from_email ); ?>"
                               placeholder="<?php echo esc_attr( $default_from_email ); ?>">
                        <p class="description"><?php printf( esc_html__( 'Par défaut WordPress : "%s"', 'seliweb' ), esc_html( $default_from_email ) ); ?></p>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:24px;"><?php esc_html_e( 'Sujet', 'seliweb' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th><label for="mail_reset_subject"><?php esc_html_e( 'Sujet du mail', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="text" id="mail_reset_subject" name="mail_reset_subject" class="large-text"
                               value="<?php echo esc_attr( $subject ); ?>"
                               placeholder="<?php echo esc_attr( $default_subject ); ?>">
                        <p class="description"><?php printf( esc_html__( 'Par défaut : "%s"', 'seliweb' ), esc_html( $default_subject ) ); ?></p>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:24px;"><?php esc_html_e( 'Corps du mail', 'seliweb' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th><label for="mail_reset_intro"><?php esc_html_e( 'Introduction', 'seliweb' ); ?></label></th>
                    <td>
                        <textarea id="mail_reset_intro" name="mail_reset_intro" rows="3" class="large-text"
                                  placeholder="<?php esc_attr_e( 'Texte ajouté avant le contenu système (facultatif).', 'seliweb' ); ?>"><?php echo esc_textarea( $intro ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Contenu système', 'seliweb' ); ?></th>
                    <td>
                        <div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:12px 16px;font-family:monospace;font-size:12px;line-height:1.7;color:#555;max-width:560px;">
                            <?php esc_html_e( 'Quelqu\'un a demandé la réinitialisation du mot de passe pour le compte suivant :', 'seliweb' ); ?><br><br>
                            <?php printf( esc_html__( 'Titre du site : %s', 'seliweb' ), '<em>' . esc_html( get_bloginfo('name') ) . '</em>' ); ?><br>
                            <?php esc_html_e( 'Identifiant : [identifiant du membre]', 'seliweb' ); ?><br><br>
                            <?php esc_html_e( 'Si ceci est une erreur, ignorez cet e-mail et rien ne se passera.', 'seliweb' ); ?><br><br>
                            <?php esc_html_e( 'Pour renouveler votre mot de passe, cliquez sur le lien suivant :', 'seliweb' ); ?><br>
                            <span style="color:#1d6a4a;"><?php esc_html_e( '[lien de réinitialisation généré automatiquement]', 'seliweb' ); ?></span>
                        </div>
                        <p class="description" style="margin-top:6px;">
                            <?php esc_html_e( 'Ce contenu est généré par WordPress et ne peut pas être modifié.', 'seliweb' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="mail_reset_signature"><?php esc_html_e( 'Signature', 'seliweb' ); ?></label></th>
                    <td>
                        <textarea id="mail_reset_signature" name="mail_reset_signature" rows="4" class="large-text"
                                  placeholder="<?php esc_attr_e( 'Ex. : Cordialement, L\'équipe du SEL, contact@monsel.fr', 'seliweb' ); ?>"><?php echo esc_textarea( $signature ); ?></textarea>
                    </td>
                </tr>
            </table>

            <p>
                <?php submit_button( __( 'Enregistrer', 'seliweb' ), 'primary', 'submit', false ); ?>
                <a href="<?php echo esc_url( $back_url ); ?>" class="button" style="margin-left:8px;"><?php esc_html_e( 'Annuler', 'seliweb' ); ?></a>
            </p>
        </form>
        <?php
    }

    private static function handle_mails( $action ) {
        if ( $action !== 'save_mail' ) return;

        global $wpdb;
        $tp   = $wpdb->prefix . 'seliweb_parametres';
        $slug = sanitize_key( $_POST['mail_slug'] ?? '' );
        if ( ! isset( self::mail_list()[ $slug ] ) ) return;

        $params = array(
            'mail_from_email'      => sanitize_email( wp_unslash( $_POST['mail_from_email'] ?? '' ) ),
            'mail_from_name'       => sanitize_text_field( wp_unslash( $_POST['mail_from_name'] ?? '' ) ),
            'mail_reset_subject'   => sanitize_text_field( wp_unslash( $_POST['mail_reset_subject'] ?? '' ) ),
            'mail_reset_intro'     => sanitize_textarea_field( wp_unslash( $_POST['mail_reset_intro'] ?? '' ) ),
            'mail_reset_signature' => sanitize_textarea_field( wp_unslash( $_POST['mail_reset_signature'] ?? '' ) ),
        );

        // Validation email expéditeur : accepter vide (= défaut WP) ou valide
        if ( $params['mail_from_email'] !== '' && ! is_email( $params['mail_from_email'] ) ) {
            // Renvoyer au formulaire avec un message d'erreur
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=mails&action=edit&mail=' . $slug . '&error=bad_email' ) );
            exit;
        }

        foreach ( $params as $cle => $valeur ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tp WHERE cle=%s LIMIT 1", $cle ) );
            if ( $exists ) {
                $wpdb->update( $tp, array( 'valeur' => $valeur ), array( 'cle' => $cle ) );
            } else {
                $wpdb->insert( $tp, array( 'cle' => $cle, 'valeur' => $valeur ) );
            }
        }

        // Invalider le cache en mémoire
        self::$mail_cfg = null;

        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=mails&updated=1' ) );
        exit;
    }
}
