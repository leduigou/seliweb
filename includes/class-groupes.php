<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seliweb_Groupes {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'handle_post' ) );
        add_action( 'init', array( __CLASS__, 'handle_delete' ) );
    }

    public static function render_tab() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] )     ? intval( $_GET['id'] )           : 0;

        switch ( $action ) {
            case 'new':
            case 'edit':
                self::form( $id );
                break;
            default:
                self::liste();
                break;
        }
    }

    // ----------------------------------------------------------------
    // Traitement POST — via hook init
    // ----------------------------------------------------------------
    public static function handle_post() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_POST['seliweb_nonce'] ) ) return;
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_parametres' ) return;
        if ( ! wp_verify_nonce( $_POST['seliweb_nonce'], 'seliweb_groupes' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;
        $tg  = $wpdb->prefix . 'seliweb_groupes';
        $tgm = $wpdb->prefix . 'seliweb_groupes_monnaies';

        $est_defaut = isset( $_POST['est_defaut'] ) ? 1 : 0;

        $data = array(
            'nom'             => sanitize_text_field( wp_unslash( $_POST['nom'] ) ),
            'limite_annonces' => ( $_POST['limite_annonces'] !== '' ) ? intval( $_POST['limite_annonces'] ) : null,
            'est_defaut'      => $est_defaut,
            'photos_max'      => max( 1, min( 10, intval( $_POST['photos_max'] ?? 1 ) ) ),
        );

        $action = sanitize_key( $_POST['seliweb_action'] );

        // Si ce groupe devient le groupe par défaut,
        // on retire le statut par défaut des autres
        if ( $est_defaut ) {
            $wpdb->update( $tg, array( 'est_defaut' => 0 ), array( 'est_defaut' => 1 ) );
        }

        if ( $action === 'add_groupe' ) {
            $wpdb->insert( $tg, $data );
            $groupe_id = $wpdb->insert_id;
        } elseif ( $action === 'update_groupe' ) {
            $groupe_id = intval( $_POST['id'] );
            $wpdb->update( $tg, $data, array( 'id' => $groupe_id ) );
            $wpdb->delete( $tgm, array( 'groupe_id' => $groupe_id ) );
        } else {
            return;
        }

        $monnaies = isset( $_POST['monnaies'] ) ? array_map( 'intval', $_POST['monnaies'] ) : array();
        foreach ( $monnaies as $monnaie_id ) {
            $wpdb->insert( $tgm, array( 'groupe_id' => $groupe_id, 'monnaie_id' => $monnaie_id ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=groupes&updated=1' ) );
        exit;
    }

    public static function handle_delete() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_parametres' ) return;
        if ( ! isset( $_GET['action'], $_GET['id'] ) || $_GET['action'] !== 'delete' ) return;
        if ( ! check_admin_referer( 'seliweb_delete_groupe_' . intval( $_GET['id'] ) ) ) return;

        global $wpdb;
        $id = intval( $_GET['id'] );
        $wpdb->delete( $wpdb->prefix . 'seliweb_groupes_monnaies', array( 'groupe_id' => $id ) );
        $wpdb->delete( $wpdb->prefix . 'seliweb_groupes',          array( 'id'        => $id ) );

        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=groupes&deleted=1' ) );
        exit;
    }

    // ----------------------------------------------------------------
    // Liste des groupes
    // ----------------------------------------------------------------
    private static function liste() {
        global $wpdb;
        $tg  = $wpdb->prefix . 'seliweb_groupes';
        $tgm = $wpdb->prefix . 'seliweb_groupes_monnaies';
        $tm  = $wpdb->prefix . 'seliweb_monnaies';
        $items = $wpdb->get_results( "SELECT * FROM $tg ORDER BY nom ASC" );

        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Groupe enregistré.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Groupe supprimé.', 'seliweb' ) . '</p></div>';
        ?>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=groupes&action=new' ) ); ?>"
               class="button button-primary"><?php esc_html_e( 'Ajouter un groupe', 'seliweb' ); ?></a>
        </p>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead><tr>
                <th><?php esc_html_e( 'Nom du groupe', 'seliweb' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Par défaut', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Limite annonces', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Photos max', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Monnaies', 'seliweb' ); ?></th>
                <th style="width:140px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $items ) ) : ?>
                <tr><td colspan="7"><em><?php esc_html_e( 'Aucun groupe créé.', 'seliweb' ); ?></em></td></tr>
            <?php else : ?>
                <?php foreach ( $items as $row ) :
                    $monnaies = $wpdb->get_col( $wpdb->prepare(
                        "SELECT m.nom FROM $tm m INNER JOIN $tgm gm ON gm.monnaie_id=m.id WHERE gm.groupe_id=%d", $row->id
                    ) );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $row->nom ); ?></strong></td>
                    <td style="text-align:center;">
                        <?php if ( $row->est_defaut ) : ?>
                            <span style="color:green;font-weight:700;" title="<?php esc_attr_e('Groupe par défaut','seliweb'); ?>">&#10003;</span>
                        <?php else : ?>
                            <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $row->limite_annonces ? intval( $row->limite_annonces ) : '<em>' . esc_html__( 'Illimitée', 'seliweb' ) . '</em>'; ?></td>
                    <td><?php echo intval( $row->photos_max ); ?></td>
                    <td><?php echo ! empty( $monnaies ) ? esc_html( implode( ', ', $monnaies ) ) : '<em>' . esc_html__( 'Aucune', 'seliweb' ) . '</em>'; ?></td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=groupes&action=edit&id=' . $row->id ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=seliweb_parametres&tab=groupes&action=delete&id=' . $row->id ), 'seliweb_delete_groupe_' . $row->id ) ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Supprimer ce groupe ?', 'seliweb' ); ?>')"
                           style="color:#b32d2e;"><?php esc_html_e( 'Supprimer', 'seliweb' ); ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    // ----------------------------------------------------------------
    // Formulaire ajout / modification
    // ----------------------------------------------------------------
    private static function form( $id = 0 ) {
        global $wpdb;
        $tg  = $wpdb->prefix . 'seliweb_groupes';
        $tgm = $wpdb->prefix . 'seliweb_groupes_monnaies';
        $tm  = $wpdb->prefix . 'seliweb_monnaies';

        $item            = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tg WHERE id=%d", $id ) ) : null;
        $toutes_monnaies = $wpdb->get_results( "SELECT * FROM $tm ORDER BY nom ASC" );
        $monnaies_groupe = $id
            ? $wpdb->get_col( $wpdb->prepare( "SELECT monnaie_id FROM $tgm WHERE groupe_id=%d", $id ) )
            : array();
        $is_edit = (bool) $item;

        // Y a-t-il déjà un groupe par défaut ?
        $defaut_existant = $wpdb->get_var( "SELECT id FROM $tg WHERE est_defaut=1 LIMIT 1" );
        ?>
        <form method="post" style="max-width:700px;">
            <?php wp_nonce_field( 'seliweb_groupes', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="<?php echo $is_edit ? 'update_groupe' : 'add_groupe'; ?>">
            <?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo intval( $item->id ); ?>"><?php endif; ?>

            <table class="form-table">

                <tr>
                    <th><label for="nom"><?php esc_html_e( 'Nom du groupe', 'seliweb' ); ?></label></th>
                    <td><input type="text" id="nom" name="nom" class="regular-text"
                               value="<?php echo $item ? esc_attr( $item->nom ) : ''; ?>" required></td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Groupe par défaut', 'seliweb' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="est_defaut" value="1"
                                   <?php checked( $item ? $item->est_defaut : 0 ); ?>>
                            <?php esc_html_e( 'Rattacher automatiquement les nouveaux membres à ce groupe', 'seliweb' ); ?>
                        </label>
                        <?php if ( $defaut_existant && ( ! $item || $defaut_existant != $item->id ) ) : ?>
                            <p class="description" style="color:#b32d2e;">
                                <?php
                                $nom_defaut = $wpdb->get_var( $wpdb->prepare( "SELECT nom FROM $tg WHERE id=%d", $defaut_existant ) );
                                printf( esc_html__( 'Attention : le groupe "%s" est actuellement le groupe par défaut. Cocher cette case le remplacera.', 'seliweb' ), esc_html( $nom_defaut ) );
                                ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th><label for="limite_annonces"><?php esc_html_e( 'Limite d\'annonces', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="number" id="limite_annonces" name="limite_annonces" min="0" class="small-text"
                               value="<?php echo $item && $item->limite_annonces ? intval( $item->limite_annonces ) : ''; ?>">
                        <p class="description"><?php esc_html_e( 'Laisser vide pour illimitée.', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label for="photos_max"><?php esc_html_e( 'Nombre de photos max de l\'annonce', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="number" id="photos_max" name="photos_max" min="1" max="10" class="small-text"
                               value="<?php echo $item ? intval( $item->photos_max ) : 1; ?>">
                        <p class="description"><?php esc_html_e( 'Entre 1 et 10. Nombre maximum de photos qu\'un membre de ce groupe peut ajouter à une annonce.', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Monnaies autorisées', 'seliweb' ); ?></th>
                    <td>
                        <fieldset>
                            <?php foreach ( $toutes_monnaies as $monnaie ) : ?>
                                <label>
                                    <input type="checkbox" name="monnaies[]"
                                           value="<?php echo intval( $monnaie->id ); ?>"
                                           <?php checked( in_array( $monnaie->id, $monnaies_groupe ) ); ?>>
                                    <?php echo esc_html( $monnaie->nom . ( $monnaie->symbole ? ' (' . $monnaie->symbole . ')' : '' ) ); ?>
                                </label><br>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description"><?php esc_html_e( 'Un ou plusieurs choix possibles.', 'seliweb' ); ?></p>
                    </td>
                </tr>

            </table>

            <?php submit_button( $is_edit ? __( 'Mettre à jour', 'seliweb' ) : __( 'Créer le groupe', 'seliweb' ) ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=groupes' ) ); ?>" class="button">
                <?php esc_html_e( 'Annuler', 'seliweb' ); ?>
            </a>
        </form>
        <?php
    }

    // ----------------------------------------------------------------
    // Méthode utilitaire : retourne l'ID du groupe par défaut
    // ----------------------------------------------------------------
    public static function get_groupe_defaut_id() {
        global $wpdb;
        return $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}seliweb_groupes WHERE est_defaut=1 LIMIT 1"
        );
    }
}
