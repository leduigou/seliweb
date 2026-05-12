<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seliweb_Parametres {

    // ----------------------------------------------------------------
    // Hook init : traitement des suppressions GET avant tout affichage
    // ----------------------------------------------------------------
    public static function init() {
        add_action( 'init', array( __CLASS__, 'handle_get_actions' ) );
    }

    public static function handle_get_actions() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_parametres' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'categories';

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
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'categories';
        $tabs = array(
            'categories' => __( 'Catégories',  'seliweb' ),
            'rubriques'  => __( 'Rubriques',   'seliweb' ),
            'statuts'    => __( 'Statuts',     'seliweb' ),
            'monnaies'   => __( 'Monnaies',    'seliweb' ),
        );

        self::handle_post( $tab );

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
            case 'rubriques': self::tab_rubriques(); break;
            case 'statuts':   self::tab_statuts();   break;
            case 'monnaies':  self::tab_monnaies();  break;
            default:          self::tab_categories(); break;
        }

        echo '</div></div>';
    }

    // ----------------------------------------------------------------
    // Traitement POST
    // ----------------------------------------------------------------
    private static function handle_post( $tab ) {
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
            case 'categories': self::handle_categories( $action ); break;
            case 'rubriques':  self::handle_rubriques( $action );  break;
            case 'statuts':    self::handle_statuts( $action );    break;
            case 'monnaies':   self::handle_monnaies( $action );   break;
        }
    }

    // ================================================================
    // CATÉGORIES
    // ================================================================
    private static function tab_categories() {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_categories';
        $items = $wpdb->get_results( "SELECT * FROM $table ORDER BY id ASC" );
        $edit  = isset( $_GET['edit_id'] ) ? intval( $_GET['edit_id'] ) : 0;
        $item  = $edit ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $edit ) ) : null;
        ?>
        <h2><?php esc_html_e( 'Catégories d\'annonces', 'seliweb' ); ?></h2>
        <?php if ( isset($_GET['error']) && $_GET['error'] === 'has_rubriques' ) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php esc_html_e( "Suppression impossible : des rubriques sont rattachées à cette catégorie. Supprimez d'abord les rubriques correspondantes.", 'seliweb' ); ?></p>
            </div>
        <?php endif; ?>
        <?php if ( isset($_GET['deleted']) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Catégorie supprimée.', 'seliweb' ); ?></p></div>
        <?php endif; ?>
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
            <?php submit_button( $item ? __( 'Mettre à jour', 'seliweb' ) : __( 'Ajouter', 'seliweb' ) ); ?>
            <?php if ( $item ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=categories' ) ); ?>" class="button"><?php esc_html_e( 'Annuler', 'seliweb' ); ?></a>
            <?php endif; ?>
        </form>
        <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
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
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=categories&edit_id=' . $row->id ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
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

    private static function handle_categories( $action ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_categories';
        if ( $action === 'add_categorie' ) {
            $nom = sanitize_text_field( wp_unslash( $_POST['nom'] ) );
            $wpdb->insert( $table, array( 'nom' => $nom, 'slug' => sanitize_title( $nom ) ) );
        }
        if ( $action === 'update_categorie' ) {
            $id  = intval( $_POST['id'] );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT modifiable FROM $table WHERE id=%d", $id ) );
            if ( $row && $row->modifiable ) {
                $nom = sanitize_text_field( wp_unslash( $_POST['nom'] ) );
                $wpdb->update( $table, array( 'nom' => $nom, 'slug' => sanitize_title( $nom ) ), array( 'id' => $id ) );
            }
        }
        if ( isset( $_GET['delete_id'] ) && check_admin_referer( 'seliweb_delete_' . intval( $_GET['delete_id'] ) ) ) {
            $id  = intval( $_GET['delete_id'] );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT supprimable FROM $table WHERE id=%d", $id ) );
            if ( $row && $row->supprimable ) $wpdb->delete( $table, array( 'id' => $id ) );
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=categories' ) );
            exit;
        }
    }

    // ================================================================
    // RUBRIQUES
    // ================================================================
    private static function tab_rubriques() {
        global $wpdb;
        $table_r = $wpdb->prefix . 'seliweb_rubriques';
        $table_c = $wpdb->prefix . 'seliweb_categories';
        $items      = $wpdb->get_results( "SELECT r.*, c.nom AS cat_nom FROM $table_r r LEFT JOIN $table_c c ON r.categorie_id=c.id ORDER BY r.categorie_id, r.nom" );
        $categories = $wpdb->get_results( "SELECT * FROM $table_c ORDER BY nom" );
        $edit       = isset( $_GET['edit_id'] ) ? intval( $_GET['edit_id'] ) : 0;
        $item       = $edit ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_r WHERE id=%d", $edit ) ) : null;
        ?>
        <h2><?php esc_html_e( 'Rubriques', 'seliweb' ); ?></h2>
        <?php if ( isset($_GET['error']) && $_GET['error'] === 'has_annonces' ) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php esc_html_e( "Suppression impossible : des annonces sont rattachées à cette rubrique. Modifiez d'abord ces annonces.", 'seliweb' ); ?></p>
            </div>
        <?php endif; ?>
        <?php if ( isset($_GET['deleted']) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rubrique supprimée.', 'seliweb' ); ?></p></div>
        <?php endif; ?>
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
            <?php submit_button( $item ? __( 'Mettre à jour', 'seliweb' ) : __( 'Ajouter', 'seliweb' ) ); ?>
            <?php if ( $item ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=rubriques' ) ); ?>" class="button"><?php esc_html_e( 'Annuler', 'seliweb' ); ?></a>
            <?php endif; ?>
        </form>
        <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
            <thead><tr>
                <th><?php esc_html_e( 'Catégorie', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Rubrique', 'seliweb' ); ?></th>
                <th style="width:150px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $items as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row->cat_nom ); ?></td>
                    <td><?php echo esc_html( $row->nom ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=rubriques&edit_id=' . $row->id ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=seliweb_parametres&tab=rubriques&delete_id=' . $row->id ), 'seliweb_delete_' . $row->id ) ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Supprimer ?', 'seliweb' ); ?>')"
                           style="color:#b32d2e;"><?php esc_html_e( 'Supprimer', 'seliweb' ); ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
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
        }
        if ( $action === 'update_rubrique' ) {
            $wpdb->update( $table, array(
                'categorie_id' => intval( $_POST['categorie_id'] ),
                'nom'          => sanitize_text_field( wp_unslash( $_POST['nom'] ) ),
            ), array( 'id' => intval( $_POST['id'] ) ) );
        }
        if ( isset( $_GET['delete_id'] ) && check_admin_referer( 'seliweb_delete_' . intval( $_GET['delete_id'] ) ) ) {
            $wpdb->delete( $table, array( 'id' => intval( $_GET['delete_id'] ) ) );
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=rubriques' ) );
            exit;
        }
    }

    // ================================================================
    // STATUTS
    // ================================================================
    private static function tab_statuts() {
        global $wpdb;
        $table         = $wpdb->prefix . 'seliweb_statuts';
        $items         = $wpdb->get_results( "SELECT * FROM $table ORDER BY id ASC" );
        $slugs_systeme = array('urgent','repondu','expire');
        ?>
        <h2><?php esc_html_e( "Statuts d'annonce", 'seliweb' ); ?></h2>
        <p class="description" style="margin-bottom:12px;">
            <?php esc_html_e( 'Les statuts Urgent, Répondu et Expiré sont des statuts système non modifiables. Vous pouvez ajouter vos propres statuts.', 'seliweb' ); ?>
        </p>

        <form method="post">
            <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="add_statut">
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Nouveau statut', 'seliweb' ); ?></th>
                    <td>
                        <input type="text" name="nom" class="regular-text"
                               placeholder="<?php esc_attr_e( 'Nom du statut', 'seliweb' ); ?>" required>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Ajouter', 'seliweb' ) ); ?>
        </form>

        <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
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

    private static function handle_statuts( $action ) {
        global $wpdb;
        $table         = $wpdb->prefix . 'seliweb_statuts';
        $slugs_systeme = array('urgent','repondu','expire');

        if ( $action === 'add_statut' ) {
            $nom  = sanitize_text_field( wp_unslash( $_POST['nom'] ) );
            $slug = sanitize_title( $nom );
            $existe = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE slug=%s LIMIT 1", $slug
            ) );
            if ( ! $existe && $nom ) {
                $wpdb->insert( $table, array(
                    'nom'         => $nom,
                    'slug'        => $slug,
                    'modifiable'  => 1,
                    'supprimable' => 1,
                ) );
            }
        }

        // Suppression : interdite pour les statuts système (vérification par slug)
        if ( isset( $_GET['delete_id'] ) && check_admin_referer( 'seliweb_delete_' . intval( $_GET['delete_id'] ) ) ) {
            $id  = intval( $_GET['delete_id'] );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT slug FROM $table WHERE id=%d", $id ) );
            if ( $row && ! in_array( $row->slug, $slugs_systeme, true ) ) {
                $wpdb->delete( $table, array( 'id' => $id ) );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=statuts' ) );
            exit;
        }
    }

    private static function tab_monnaies() {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_monnaies';
        $items = $wpdb->get_results( "SELECT * FROM $table ORDER BY est_defaut DESC, nom ASC" );
        $edit  = isset( $_GET['edit_id'] ) ? intval( $_GET['edit_id'] ) : 0;
        $item  = $edit ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $edit ) ) : null;

        // Monnaie par défaut actuelle
        $defaut_id = $wpdb->get_var( "SELECT id FROM $table WHERE est_defaut=1 LIMIT 1" );
        ?>
        <h2><?php esc_html_e( 'Monnaies', 'seliweb' ); ?></h2>

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
            <?php submit_button( $item ? __( 'Mettre à jour', 'seliweb' ) : __( 'Ajouter', 'seliweb' ) ); ?>
            <?php if ( $item ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies' ) ); ?>"
                   class="button"><?php esc_html_e( 'Annuler', 'seliweb' ); ?></a>
            <?php endif; ?>
        </form>

        <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
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
                            <span style="color:green;font-weight:700;" title="<?php esc_attr_e('Monnaie par défaut','seliweb'); ?>">&#10003;</span>
                        <?php else : ?>
                            <a href="<?php echo esc_url( wp_nonce_url(
                                admin_url('admin.php?page=seliweb_parametres&tab=monnaies&set_defaut='.$row->id),
                                'seliweb_defaut_'.$row->id
                            ) ); ?>" style="font-size:11px;"><?php esc_html_e('Définir','seliweb'); ?></a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies&edit_id=' . $row->id ) ); ?>">
                            <?php esc_html_e( 'Modifier', 'seliweb' ); ?>
                        </a>
                        <?php if ( ! $row->est_defaut ) : ?>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url( wp_nonce_url(
                                admin_url('admin.php?page=seliweb_parametres&tab=monnaies&delete_id='.$row->id),
                                'seliweb_delete_'.$row->id
                            ) ); ?>"
                               onclick="return confirm('<?php esc_attr_e('Supprimer cette monnaie ?','seliweb'); ?>')"
                               style="color:#b32d2e;"><?php esc_html_e('Supprimer','seliweb'); ?></a>
                        <?php else : ?>
                            &nbsp;<em style="color:#888;font-size:12px;"><?php esc_html_e('(par défaut — non supprimable)','seliweb'); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function handle_monnaies( $action ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_monnaies';

        // Définir une monnaie comme défaut via lien direct
        if ( isset( $_GET['set_defaut'] ) && check_admin_referer( 'seliweb_defaut_' . intval( $_GET['set_defaut'] ) ) ) {
            $id = intval( $_GET['set_defaut'] );
            $wpdb->update( $table, array( 'est_defaut' => 0 ), array( 'est_defaut' => 1 ) );
            $wpdb->update( $table, array( 'est_defaut' => 1 ), array( 'id' => $id ) );
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies' ) );
            exit;
        }

        if ( $action === 'add_monnaie' ) {
            $est_defaut = isset( $_POST['est_defaut'] ) ? 1 : 0;
            // Si nouvelle monnaie par défaut, retirer l'ancien défaut
            if ( $est_defaut ) {
                $wpdb->update( $table, array( 'est_defaut' => 0 ), array( 'est_defaut' => 1 ) );
            }
            $wpdb->insert( $table, array(
                'nom'        => sanitize_text_field( wp_unslash( $_POST['nom'] ) ),
                'symbole'    => sanitize_text_field( wp_unslash( $_POST['symbole'] ) ),
                'est_defaut' => $est_defaut,
            ) );
        }

        if ( $action === 'update_monnaie' ) {
            $id         = intval( $_POST['id'] );
            $est_defaut = isset( $_POST['est_defaut'] ) ? 1 : 0;
            // Si on définit ce monnaie comme défaut, retirer l'ancien
            if ( $est_defaut ) {
                $wpdb->update( $table, array( 'est_defaut' => 0 ), array( 'est_defaut' => 1 ) );
            }
            $wpdb->update( $table, array(
                'nom'        => sanitize_text_field( wp_unslash( $_POST['nom'] ) ),
                'symbole'    => sanitize_text_field( wp_unslash( $_POST['symbole'] ) ),
                'est_defaut' => $est_defaut,
            ), array( 'id' => $id ) );
        }

        if ( isset( $_GET['delete_id'] ) && check_admin_referer( 'seliweb_delete_' . intval( $_GET['delete_id'] ) ) ) {
            $id  = intval( $_GET['delete_id'] );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT est_defaut FROM $table WHERE id=%d", $id ) );
            // Ne pas supprimer la monnaie par défaut
            if ( $row && ! $row->est_defaut ) {
                $wpdb->delete( $table, array( 'id' => $id ) );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=monnaies' ) );
            exit;
        }
    }
}
