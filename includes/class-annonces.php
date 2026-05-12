<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seliweb_Annonces {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'handle_post' ) );
        add_action( 'init', array( __CLASS__, 'handle_delete' ) );
    }

    public static function display() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] )     ? intval( $_GET['id'] )           : 0;

        self::check_expired();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Annonces', 'seliweb' );
        if ( $action === 'list' ) {
            echo ' <a href="' . esc_url( admin_url( 'admin.php?page=seliweb_annonces&action=new' ) ) . '" class="page-title-action">'
               . esc_html__( 'Ajouter une annonce', 'seliweb' ) . '</a>';
        }
        echo '</h1>';

        // Notices d'erreur affichées dans le formulaire
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'no_rubrique' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Vous devez choisir une rubrique.', 'seliweb' ) . '</p></div>';
        }
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'bad_date' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( "Veuillez corriger la date d'expiration ou laisser le champ vide.", 'seliweb' ) . '</p></div>';
        }

        switch ( $action ) {
            case 'new':
            case 'edit':
                self::form( $id );
                break;
            default:
                self::liste();
                break;
        }
        echo '</div>';
    }

    public static function check_expired() {
        global $wpdb;
        $ta = $wpdb->prefix . 'seliweb_annonces';
        $ts = $wpdb->prefix . 'seliweb_statuts';
        $statut_expire  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ts WHERE slug=%s LIMIT 1", 'expire' ) );
        $statut_repondu = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ts WHERE slug=%s LIMIT 1", 'repondu' ) );
        if ( $statut_expire ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE $ta SET statut_id=%d WHERE date_expiration IS NOT NULL AND date_expiration < CURDATE() AND (statut_id != %d OR statut_id IS NULL)",
                $statut_expire, $statut_repondu ?? 0
            ) );
        }
    }

    // ----------------------------------------------------------------
    // Traitement POST admin — via hook init
    // ----------------------------------------------------------------
    public static function handle_post() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_POST['seliweb_nonce'] ) ) return;
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_annonces' ) return;
        if ( ! wp_verify_nonce( $_POST['seliweb_nonce'], 'seliweb_annonces' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;
        $ta  = $wpdb->prefix . 'seliweb_annonces';
        $tap = $wpdb->prefix . 'seliweb_annonces_prix';

        $action = sanitize_key( $_POST['seliweb_action'] );

        $categorie_id    = intval( $_POST['categorie_id'] );
        $rubrique_id     = ! empty( $_POST['rubrique_id'] ) ? intval( $_POST['rubrique_id'] ) : null;
        $statut_id       = ! empty( $_POST['statut_id'] ) ? intval( $_POST['statut_id'] ) : null;
        $date_expiration = ! empty( $_POST['date_expiration'] ) ? sanitize_text_field( $_POST['date_expiration'] ) : null;

        // Validation : rubrique obligatoire si catégorie choisie
        if ( $categorie_id && ! $rubrique_id ) {
            wp_safe_redirect( add_query_arg( array(
                'page'          => 'seliweb_annonces',
                'action'        => $action === 'add_annonce' ? 'new' : 'edit',
                'id'            => intval( $_POST['id'] ?? 0 ),
                'error'         => 'no_rubrique',
            ), admin_url('admin.php') ) );
            exit;
        }

        // Validation : date expiration incohérente
        // Validation : date passée sans statut Expiré
        if ( $date_expiration && $date_expiration < date('Y-m-d') ) {
            global $wpdb;
            $slug_statut = $statut_id ? $wpdb->get_var( $wpdb->prepare(
                "SELECT slug FROM {$wpdb->prefix}seliweb_statuts WHERE id=%d", $statut_id
            ) ) : '';
            if ( $slug_statut !== 'expire' ) {
                wp_safe_redirect( add_query_arg( array(
                    'page'   => 'seliweb_annonces',
                    'action' => $action === 'add_annonce' ? 'new' : 'edit',
                    'id'     => intval( $_POST['id'] ?? 0 ),
                    'error'  => 'bad_date',
                ), admin_url('admin.php') ) );
                exit;
            }
        }

        $data = array(
            'categorie_id'    => $categorie_id,
            'rubrique_id'     => $rubrique_id,
            'type_annonce'    => in_array( $_POST['type_annonce'] ?? '', array( 'offre', 'demande' ) ) ? $_POST['type_annonce'] : null,
            'titre'           => sanitize_text_field( wp_unslash( $_POST['titre'] ?? '' ) ),
            'texte'           => sanitize_textarea_field( wp_unslash( substr( $_POST['texte'] ?? '', 0, 1000 ) ) ),
            'statut_id'       => $statut_id,
            'date_expiration' => $date_expiration,
            'est_don'         => isset( $_POST['est_don'] ) ? 1 : 0,
        );

        // Gestion photos
        $photo1 = self::handle_upload( 'photo1' );
        $photo2 = self::handle_upload( 'photo2' );

        if ( $action === 'add_annonce' ) {
            // En backend, le membre est choisi dans le formulaire
            $membre_id = ! empty($_POST['membre_id']) ? intval($_POST['membre_id']) : self::get_or_create_membre(get_current_user_id());
            $data['membre_id']     = $membre_id;
            $data['date_creation'] = current_time( 'mysql' );
            if ( $photo1 ) $data['photo1'] = $photo1;
            if ( $photo2 ) $data['photo2'] = $photo2;
            $data = array_filter( $data, function($v){ return $v !== null; } );
            $wpdb->insert( $ta, $data );
            $annonce_id = $wpdb->insert_id;
            self::save_prix_from_post( $annonce_id, $_POST );
            self::notify_membres( $annonce_id );

        } elseif ( $action === 'update_annonce' ) {
            $annonce_id = intval( $_POST['id'] );
            if ( $photo1 ) $data['photo1'] = $photo1;
            if ( $photo2 ) $data['photo2'] = $photo2;
            // Ne pas filtrer statut_id et date_expiration (null = vider le champ)
            // On retire uniquement les photos non envoyées
            $wpdb->update( $ta, $data, array( 'id' => $annonce_id ) );
            $wpdb->delete( $tap, array( 'annonce_id' => $annonce_id ) );
            self::save_prix_from_post( $annonce_id, $_POST );
        }

        // Alerte si statut Expiré
        global $wpdb;
        $slug_saved = $statut_id ? $wpdb->get_var( $wpdb->prepare(
            "SELECT slug FROM {$wpdb->prefix}seliweb_statuts WHERE id=%d", $statut_id
        ) ) : '';
        $redirect_args = array('page' => 'seliweb_annonces', 'updated' => '1');
        if ( $slug_saved === 'expire' ) $redirect_args['warn_expire'] = '1';
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url('admin.php') ) );
        exit;
    }

    public static function handle_delete() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_annonces' ) return;
        if ( ! isset( $_GET['action'], $_GET['id'] ) || $_GET['action'] !== 'delete' ) return;
        if ( ! check_admin_referer( 'seliweb_delete_annonce_' . intval( $_GET['id'] ) ) ) return;
        global $wpdb;
        $id = intval( $_GET['id'] );
        $wpdb->delete( $wpdb->prefix . 'seliweb_annonces_prix', array( 'annonce_id' => $id ) );
        $wpdb->delete( $wpdb->prefix . 'seliweb_annonces',      array( 'id'         => $id ) );
        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_annonces&deleted=1' ) );
        exit;
    }

    // ----------------------------------------------------------------
    // Upload photo via médiathèque WP
    // ----------------------------------------------------------------
    private static function handle_upload( $field ) {
        if ( empty( $_FILES[ $field ]['name'] ) || $_FILES[ $field ]['error'] !== UPLOAD_ERR_OK ) {
            return null;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload( $field, 0 );
        if ( is_wp_error( $attachment_id ) ) {
            return null;
        }
        return wp_get_attachment_url( $attachment_id );
    }

    // ----------------------------------------------------------------
    // Sauvegarde des prix (tableaux parallèles prix_montant[] / prix_monnaie[])
    // ----------------------------------------------------------------
    public static function save_prix_from_post( $annonce_id, $post ) {
        global $wpdb;
        $tap = $wpdb->prefix . 'seliweb_annonces_prix';
        if ( empty( $post['prix_montant'] ) ) return;

        // Vérifier si la colonne coordination existe (migration progressive)
        $cols      = $wpdb->get_col( "DESCRIBE $tap", 0 );
        $has_coord = in_array( 'coordination', $cols, true );

        $montants      = (array) $post['prix_montant'];
        $monnaies      = (array) ( $post['prix_monnaie']     ?? array() );
        $coordinations = (array) ( $post['prix_coordination'] ?? array() );
        $used          = array();
        $first         = true;
        foreach ( $montants as $i => $montant ) {
            $montant    = sanitize_text_field( wp_unslash( $montant ) );
            $monnaie_id = intval( $monnaies[ $i ] ?? 0 );
            if ( $montant === '' || $monnaie_id === 0 ) continue;
            if ( in_array( $monnaie_id, $used ) ) continue;
            $used[] = $monnaie_id;

            $row = array(
                'annonce_id' => $annonce_id,
                'monnaie_id' => $monnaie_id,
                'prix'       => $montant,
            );

            // N'ajouter coordination que si la colonne existe
            if ( $has_coord && ! $first ) {
                $coord_raw    = strtoupper( sanitize_text_field( $coordinations[ $i ] ?? 'OU' ) );
                $row['coordination'] = in_array( $coord_raw, array('ET','OU') ) ? $coord_raw : 'OU';
            }

            $wpdb->insert( $tap, $row );
            $first = false;
        }
    }

    // ----------------------------------------------------------------
    // Notification mail membres
    // ----------------------------------------------------------------
    public static function notify_membres( $annonce_id ) {
        global $wpdb;
        $ta = $wpdb->prefix . 'seliweb_annonces';
        $tm = $wpdb->prefix . 'seliweb_membres';
        $annonce = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id=%d", $annonce_id ) );
        $membres = $wpdb->get_results( "SELECT wp_user_id FROM $tm WHERE notif_annonces=1" );
        if ( ! $annonce || empty( $membres ) ) return;
        $sujet = sprintf( __( '[Seliweb] Nouvelle annonce : %s', 'seliweb' ), $annonce->titre );
        $url   = home_url( '/?seliweb_annonce=' . $annonce_id );
        $corps = sprintf( __( "Une nouvelle annonce a été publiée :\n\n%s\n\nVoir : %s", 'seliweb' ), $annonce->titre, $url );
        foreach ( $membres as $m ) {
            $user = get_userdata( $m->wp_user_id );
            if ( $user && $user->user_email ) wp_mail( $user->user_email, $sujet, $corps );
        }
    }

    public static function get_or_create_membre( $wp_user_id ) {
        global $wpdb;
        $tm = $wpdb->prefix . 'seliweb_membres';
        $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tm WHERE wp_user_id=%d", $wp_user_id ) );
        if ( ! $id ) {
            $wpdb->insert( $tm, array( 'wp_user_id' => $wp_user_id ) );
            $id = $wpdb->insert_id;
        }
        return $id;
    }

    // ----------------------------------------------------------------
    // Liste admin
    // ----------------------------------------------------------------
    private static function liste() {
        global $wpdb;
        $ta = $wpdb->prefix . 'seliweb_annonces';
        $tc = $wpdb->prefix . 'seliweb_categories';
        $tr = $wpdb->prefix . 'seliweb_rubriques';
        $ts = $wpdb->prefix . 'seliweb_statuts';
        $tm = $wpdb->prefix . 'seliweb_membres';

        if ( isset( $_GET['updated'] ) )    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Annonce enregistrée.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['deleted'] ) )    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Annonce supprimée.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['warn_expire'] ) ) echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( "Cette annonce ne sera pas visible car le statut est Expiré.", 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'no_rubrique' ) echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Vous devez choisir une rubrique.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'bad_date' )    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( "Veuillez corriger la date d'expiration ou laisser le champ vide.", 'seliweb' ) . '</p></div>';

        $items = $wpdb->get_results(
            "SELECT a.*, c.nom AS cat_nom, r.nom AS rub_nom, s.nom AS statut_nom, u.display_name AS membre_nom
             FROM $ta a
             LEFT JOIN $tc c ON c.id=a.categorie_id
             LEFT JOIN $tr r ON r.id=a.rubrique_id
             LEFT JOIN $ts s ON s.id=a.statut_id
             LEFT JOIN $tm m ON m.id=a.membre_id
             LEFT JOIN {$wpdb->users} u ON u.ID=m.wp_user_id
             ORDER BY a.date_creation DESC"
        );
        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
            <thead><tr>
                <th style="width:40px;">ID</th>
                <th><?php esc_html_e( 'Titre', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Catégorie', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Rubrique', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Membre', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Statut', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Expiration', 'seliweb' ); ?></th>
                <th style="width:120px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $items ) ) : ?>
                <tr><td colspan="8"><em><?php esc_html_e( 'Aucune annonce.', 'seliweb' ); ?></em></td></tr>
            <?php else : ?>
                <?php foreach ( $items as $row ) : ?>
                <tr>
                    <td><?php echo intval( $row->id ); ?></td>
                    <td><strong><?php echo esc_html( $row->titre ); ?></strong><?php if ( $row->type_annonce ) echo '<br><em>' . esc_html( ucfirst( $row->type_annonce ) ) . '</em>'; ?></td>
                    <td><?php echo esc_html( $row->cat_nom ); ?></td>
                    <td><?php echo esc_html( $row->rub_nom ?: '—' ); ?></td>
                    <td><?php echo esc_html( $row->membre_nom ); ?></td>
                    <td><?php echo esc_html( $row->statut_nom ); ?></td>
                    <td><?php echo $row->date_expiration ? esc_html( $row->date_expiration ) : '—'; ?></td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_annonces&action=edit&id=' . $row->id ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=seliweb_annonces&action=delete&id=' . $row->id ), 'seliweb_delete_annonce_' . $row->id ) ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Supprimer ?', 'seliweb' ); ?>')"
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
    // Formulaire admin — identique front avec monnaies en select
    // ----------------------------------------------------------------
    private static function form( $id = 0 ) {
        global $wpdb;
        $ta  = $wpdb->prefix . 'seliweb_annonces';
        $tap = $wpdb->prefix . 'seliweb_annonces_prix';
        $tc  = $wpdb->prefix . 'seliweb_categories';
        $tr  = $wpdb->prefix . 'seliweb_rubriques';
        $ts  = $wpdb->prefix . 'seliweb_statuts';
        $tmon = $wpdb->prefix . 'seliweb_monnaies';

        $item       = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id=%d", $id ) ) : null;
        $categories = $wpdb->get_results( "SELECT * FROM $tc ORDER BY nom ASC" );
        $rubriques  = $wpdb->get_results( "SELECT * FROM $tr ORDER BY categorie_id, nom ASC" );
        $statuts    = $wpdb->get_results( "SELECT * FROM $ts ORDER BY id ASC" );
        $monnaies   = $wpdb->get_results( "SELECT * FROM $tmon ORDER BY nom ASC" );

        // Prix existants indexés par monnaie_id
        $prix_map = array();
        if ( $id ) {
            foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tap WHERE annonce_id=%d", $id ) ) as $p ) {
                $prix_map[ $p->monnaie_id ] = $p->prix;
            }
        }

        // Membres pour la liste déroulante
        $tm_table = $wpdb->prefix . 'seliweb_membres';
        $tg_table = $wpdb->prefix . 'seliweb_groupes';
        $membres_liste = $wpdb->get_results(
            "SELECT m.id, m.groupe_id, g.limite_annonces AS groupe_limite,
                    u.display_name, u.ID AS wp_user_id
             FROM {$wpdb->prefix}seliweb_membres m
             LEFT JOIN {$wpdb->prefix}seliweb_groupes g ON g.id=m.groupe_id
             LEFT JOIN {$wpdb->users} u ON u.ID=m.wp_user_id
             ORDER BY u.display_name ASC"
        );

        // Membre actuellement rattaché à cette annonce (en modification)
        $membre_sel_id = $item ? intval($item->membre_id) : 0;

        // Pour chaque membre : récupérer ses monnaies autorisées (groupe) et son nb d'annonces
        $membres_data = array();
        foreach ($membres_liste as $ml) {
            $mon_ids = array();
            if ($ml->groupe_id) {
                $mon_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT monnaie_id FROM {$wpdb->prefix}seliweb_groupes_monnaies WHERE groupe_id=%d",
                    $ml->groupe_id
                ));
            }
            $nb_ann = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}seliweb_annonces WHERE membre_id=%d", $ml->id
            ));
            $limite_eff = $ml->groupe_limite;
            $membres_data[$ml->id] = array(
                'monnaies' => $mon_ids,
                'nb_ann'   => $nb_ann,
                'limite'   => (int)$limite_eff,
            );
        }

        // Lignes de prix à afficher : existantes ou 1 ligne vide
        $prix_lignes = ! empty( $prix_map ) ? $prix_map : array( '' => '' );
        ?>
        <form method="post" enctype="multipart/form-data" style="max-width:750px;">
            <?php wp_nonce_field( 'seliweb_annonces', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="<?php echo $item ? 'update_annonce' : 'add_annonce'; ?>">
            <?php if ( $item ) : ?><input type="hidden" name="id" value="<?php echo intval( $item->id ); ?>"><?php endif; ?>

            <table class="form-table">

                <!-- MEMBRE : sélection en premier -->
                <tr>
                    <th><label for="membre_id"><?php esc_html_e( 'Membre', 'seliweb' ); ?></label></th>
                    <td>
                        <select id="membre_id" name="membre_id" required
                                onchange="selAdmChangeMembre(this.value)">
                            <option value=""><?php esc_html_e( '— Choisir un membre —', 'seliweb' ); ?></option>
                            <?php foreach ( $membres_liste as $ml ) : ?>
                                <option value="<?php echo intval($ml->id); ?>"
                                        <?php selected($membre_sel_id, $ml->id); ?>>
                                    <?php echo esc_html($ml->display_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description" id="adm_membre_info" style="margin-top:4px;"></p>
                    </td>
                </tr>

                <tr>
                    <th><label for="titre"><?php esc_html_e( 'Titre', 'seliweb' ); ?></label></th>
                    <td><input type="text" id="titre" name="titre" class="regular-text"
                               value="<?php echo $item ? esc_attr( $item->titre ) : ''; ?>" required></td>
                </tr>
                <tr>
                    <th><label for="categorie_id"><?php esc_html_e( 'Catégorie', 'seliweb' ); ?></label></th>
                    <td>
                        <select id="categorie_id" name="categorie_id" required
                                onchange="selAdmRub(this.value); selAdmType(this.value)">
                            <option value=""><?php esc_html_e( '— Choisir —', 'seliweb' ); ?></option>
                            <?php foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo intval( $cat->id ); ?>"
                                        data-slug="<?php echo esc_attr( $cat->slug ); ?>"
                                        <?php selected( $item ? $item->categorie_id : '', $cat->id ); ?>>
                                    <?php echo esc_html( $cat->nom ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr id="row_type" style="<?php echo ( $item && $item->type_annonce ) ? '' : 'display:none'; ?>">
                    <th><?php esc_html_e( 'Type', 'seliweb' ); ?></th>
                    <td>
                        <label><input type="radio" name="type_annonce" value="offre"
                                      <?php checked( $item ? $item->type_annonce : '', 'offre' ); ?>>
                            <?php esc_html_e( 'Offre', 'seliweb' ); ?></label>
                        &nbsp;&nbsp;
                        <label><input type="radio" name="type_annonce" value="demande"
                                      <?php checked( $item ? $item->type_annonce : '', 'demande' ); ?>>
                            <?php esc_html_e( 'Demande', 'seliweb' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th><label for="rubrique_id"><?php esc_html_e( 'Rubrique', 'seliweb' ); ?></label></th>
                    <td>
                        <select id="rubrique_id" name="rubrique_id">
                            <option value=""><?php esc_html_e( '— Choisir —', 'seliweb' ); ?></option>
                            <?php foreach ( $rubriques as $rub ) : ?>
                                <option value="<?php echo intval( $rub->id ); ?>"
                                        data-categorie="<?php echo intval( $rub->categorie_id ); ?>"
                                        <?php selected( $item ? $item->rubrique_id : '', $rub->id ); ?>>
                                    <?php echo esc_html( $rub->nom ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="texte"><?php esc_html_e( 'Texte', 'seliweb' ); ?></label></th>
                    <td>
                        <textarea id="texte" name="texte" rows="6" class="large-text"
                                  maxlength="1000"><?php echo $item ? esc_textarea( $item->texte ) : ''; ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th><label for="statut_id"><?php esc_html_e( 'Statut', 'seliweb' ); ?></label></th>
                    <td>
                        <select id="statut_id" name="statut_id">
                            <option value=""><?php esc_html_e( '— Aucun —', 'seliweb' ); ?></option>
                            <?php foreach ( $statuts as $st ) : ?>
                                <option value="<?php echo intval( $st->id ); ?>"
                                        <?php selected( $item ? $item->statut_id : '', $st->id ); ?>>
                                    <?php echo esc_html( $st->nom ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="date_expiration"><?php esc_html_e( 'Date d\'expiration', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="date" id="date_expiration" name="date_expiration"
                               value="<?php echo $item ? esc_attr( $item->date_expiration ) : ''; ?>">
                        <p class="description"><?php esc_html_e( 'Laisser vide pour sans limite.', 'seliweb' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Don', 'seliweb' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="est_don" value="1"
                                   onchange="selAdmTogglePrix(this.checked)"
                                   <?php checked( $item ? $item->est_don : 0 ); ?>>
                            <?php esc_html_e( 'Je fais un don', 'seliweb' ); ?>
                        </label>
                    </td>
                </tr>

                <!-- PRIX avec select monnaie -->
                <tr id="row_prix" <?php echo ( $item && $item->est_don ) ? 'style="display:none"' : ''; ?>>
                    <th><?php esc_html_e( 'Prix', 'seliweb' ); ?></th>
                    <td>
                        <div id="adm_prix_container">
                            <?php
                            $adm_is_first = true;
                            // Récupérer les coordinations existantes
                            $coord_map = array();
                            if ( $id ) {
                                foreach ( $wpdb->get_results( $wpdb->prepare(
                                    "SELECT monnaie_id, coordination FROM {$wpdb->prefix}seliweb_annonces_prix WHERE annonce_id=%d ORDER BY id ASC", $id
                                ) ) as $pc ) {
                                    $coord_map[ $pc->monnaie_id ] = $pc->coordination;
                                }
                            }
                            foreach ( $prix_lignes as $mon_id => $montant ) :
                                $coord_val = $coord_map[ $mon_id ] ?? 'OU';
                            ?>
                            <div class="seliweb-prix-row" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                <!-- Sélecteur ET/OU : masqué sur la 1ère ligne, visible sur les suivantes -->
                                <select name="prix_coordination[]"
                                        style="<?php echo $adm_is_first ? 'visibility:hidden;width:60px;' : 'width:60px;'; ?>">
                                    <option value="OU" <?php selected($coord_val,'OU'); ?>>OU</option>
                                    <option value="ET" <?php selected($coord_val,'ET'); ?>>ET</option>
                                </select>
                                <input type="text" name="prix_montant[]"
                                       value="<?php echo esc_attr( $montant ); ?>"
                                       maxlength="10" style="width:100px;"
                                       placeholder="<?php esc_attr_e( 'Montant', 'seliweb' ); ?>">
                                <select name="prix_monnaie[]" class="adm-prix-select">
                                    <option value=""><?php esc_html_e( '— Monnaie —', 'seliweb' ); ?></option>
                                    <?php foreach ( $monnaies as $mon ) : ?>
                                        <option value="<?php echo intval( $mon->id ); ?>"
                                                <?php selected( intval( $mon->id ), intval( $mon_id ) ); ?>>
                                            <?php echo esc_html( $mon->nom . ( $mon->symbole ? ' (' . $mon->symbole . ')' : '' ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button" onclick="this.closest('.seliweb-prix-row').remove()"
                                        title="<?php esc_attr_e( 'Supprimer', 'seliweb' ); ?>">✕</button>
                            </div>
                            <?php $adm_is_first = false; endforeach; ?>
                        </div>
                        <button type="button" class="button" onclick="selAdmAddPrix()">
                            <?php esc_html_e( '+ Ajouter une monnaie', 'seliweb' ); ?>
                        </button>
                        <p class="description"><?php esc_html_e( 'Chaque ligne doit avoir une monnaie différente.', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <!-- Photo 1 obligatoire en création -->
                <tr>
                    <th><label for="photo1"><?php esc_html_e( 'Photo 1', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="file" id="photo1" name="photo1" accept="image/*"
                               <?php echo ! $item ? 'required' : ''; ?>>
                        <?php if ( $item && $item->photo1 ) : ?>
                            <br><img src="<?php echo esc_url( $item->photo1 ); ?>"
                                     style="max-height:80px;margin-top:6px;border-radius:3px;border:1px solid #ddd;">
                        <?php elseif ( ! $item ) : ?>
                            <p class="description"><?php esc_html_e( 'Obligatoire.', 'seliweb' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="photo2"><?php esc_html_e( 'Photo 2', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="file" id="photo2" name="photo2" accept="image/*">
                        <?php if ( $item && $item->photo2 ) : ?>
                            <br><img src="<?php echo esc_url( $item->photo2 ); ?>"
                                     style="max-height:80px;margin-top:6px;border-radius:3px;border:1px solid #ddd;">
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button( $item ? __( 'Mettre à jour', 'seliweb' ) : __( 'Créer l\'annonce', 'seliweb' ) ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_annonces' ) ); ?>" class="button">
                <?php esc_html_e( 'Annuler', 'seliweb' ); ?>
            </a>
        </form>

        <script>
        // Toutes les monnaies disponibles
        var selAdmMonnaies = <?php echo wp_json_encode( array_map( function($m) {
            return array( 'id' => $m->id, 'label' => $m->nom . ( $m->symbole ? ' (' . $m->symbole . ')' : '' ) );
        }, $monnaies ) ); ?>;

        // Données par membre : monnaies autorisées, limite, nb annonces
        var selAdmMembresData = <?php echo wp_json_encode($membres_data); ?>;

        // Toutes les monnaies indexées par id
        var selAdmMonnaiesById = {};
        selAdmMonnaies.forEach(function(m){ selAdmMonnaiesById[m.id] = m; });

        function selAdmChangeMembre(membreId) {
            var info = document.getElementById('adm_membre_info');
            if (!membreId || !selAdmMembresData[membreId]) {
                info.textContent = '';
                selAdmResetPrix(selAdmMonnaies); // toutes monnaies si pas de membre
                return;
            }
            var d = selAdmMembresData[membreId];

            // Afficher info limite
            var msg = '';
            if (d.limite > 0) {
                msg = d.nb_ann + ' / ' + d.limite + ' annonce(s)';
                if (d.nb_ann >= d.limite) msg += ' — ⚠ Limite atteinte';
            } else {
                msg = d.nb_ann + ' annonce(s) publiée(s) — sans limite';
            }
            info.textContent = msg;
            info.style.color = (d.limite > 0 && d.nb_ann >= d.limite) ? '#b32d2e' : '#555';

            // Filtrer les monnaies selon le groupe du membre
            var monnaiesAutorisees = d.monnaies.length > 0
                ? selAdmMonnaies.filter(function(m){ return d.monnaies.indexOf(String(m.id)) !== -1 || d.monnaies.indexOf(m.id) !== -1; })
                : selAdmMonnaies;
            selAdmResetPrix(monnaiesAutorisees);
        }

        function selAdmResetPrix(monnaiesAutorisees) {
            // Mettre à jour les selects monnaie existants
            document.querySelectorAll('#adm_prix_container .adm-prix-select').forEach(function(sel) {
                var currentVal = sel.value;
                var opts = '<option value=""><?php esc_attr_e("— Monnaie —","seliweb"); ?></option>';
                monnaiesAutorisees.forEach(function(m){
                    opts += '<option value="'+m.id+'"'+(String(m.id)===String(currentVal)?' selected':'')+'>'+m.label+'</option>';
                });
                sel.innerHTML = opts;
            });
        }

        // Init au chargement si membre déjà sélectionné
        document.addEventListener('DOMContentLoaded', function(){
            var memSel = document.getElementById('membre_id');
            if (memSel && memSel.value) selAdmChangeMembre(memSel.value);
        });

        function selAdmRub(catId) {
            var opts = document.querySelectorAll('#rubrique_id option[data-categorie]');
            opts.forEach(function(o){
                o.style.display = (!catId || o.dataset.categorie == catId) ? '' : 'none';
            });
            // Ne pas réinitialiser si on est en mode édition et que la rubrique est déjà sélectionnée
            var sel = document.getElementById('rubrique_id');
            var current = sel.value;
            if ( current ) {
                var currentOpt = sel.querySelector('option[value="'+current+'"]');
                if ( currentOpt && currentOpt.style.display === 'none' ) {
                    sel.value = '';
                }
            }
        }
        function selAdmType(catId) {
            var sel   = document.getElementById('categorie_id');
            var opt   = sel ? sel.options[sel.selectedIndex] : null;
            var isAnn = opt && opt.dataset.slug === 'annonces';
            document.getElementById('row_type').style.display = (catId && isAnn) ? '' : 'none';
        }
        function selAdmTogglePrix(isDon) {
            document.getElementById('row_prix').style.display = isDon ? 'none' : '';
        }
        function selAdmUsedIds() {
            var ids = [];
            document.querySelectorAll('#adm_prix_container .adm-prix-select').forEach(function(s){
                if (s.value) ids.push(s.value);
            });
            return ids;
        }
        function selAdmAddPrix() {
            var usedIds = selAdmUsedIds();
            var available = selAdmMonnaies.filter(function(m){ return usedIds.indexOf(String(m.id)) === -1; });
            if (available.length === 0) {
                alert(<?php echo wp_json_encode( __( 'Toutes les monnaies sont déjà utilisées.', 'seliweb' ) ); ?>);
                return;
            }
            var opts = '<option value=""><?php esc_attr_e('— Monnaie —','seliweb'); ?></option>';
            selAdmMonnaies.forEach(function(m){ opts += '<option value="'+m.id+'">'+m.label+'</option>'; });
            var row = document.createElement('div');
            row.className = 'seliweb-prix-row';
            row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
            row.innerHTML = '<select name="prix_coordination[]" style="width:60px;"><option value="OU">OU</option><option value="ET">ET</option></select>'
                          + '<input type="text" name="prix_montant[]" maxlength="10" style="width:100px;" placeholder="Montant">'
                          + '<select name="prix_monnaie[]" class="adm-prix-select">'+opts+'</select>'
                          + '<button type="button" class="button" onclick="this.closest(\'.seliweb-prix-row\').remove()">✕</button>';
            document.getElementById('adm_prix_container').appendChild(row);
            // Vérif doublon
            row.querySelector('.adm-prix-select').addEventListener('change', function(){
                var used = selAdmUsedIds();
                var dups = used.filter(function(id,i){ return used.indexOf(id) !== i; });
                if (dups.length > 0) { alert(<?php echo wp_json_encode( __('Cette monnaie est déjà utilisée.','seliweb') ); ?>); this.value=''; }
            });
        }
        document.addEventListener('DOMContentLoaded', function(){
            var cat = document.getElementById('categorie_id');
            if (cat && cat.value) {
                selAdmRub(cat.value);
                selAdmType(cat.value);
            }
        });
        </script>
        <?php
    }

    // ----------------------------------------------------------------
    // Méthodes publiques utilitaires
    // ----------------------------------------------------------------
    public static function get_annonces_publiques( $filters = array() ) {
        global $wpdb;
        $ta = $wpdb->prefix . 'seliweb_annonces';
        $tc = $wpdb->prefix . 'seliweb_categories';
        $tr = $wpdb->prefix . 'seliweb_rubriques';
        $ts = $wpdb->prefix . 'seliweb_statuts';
        $tm = $wpdb->prefix . 'seliweb_membres';

        $statut_expire = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ts WHERE slug=%s LIMIT 1", 'expire' ) );
        $where  = array( "( a.statut_id != %d OR a.statut_id IS NULL )" );
        $values = array( $statut_expire ?? 0 );

        if ( ! empty( $filters['categorie_id'] ) ) { $where[] = "a.categorie_id = %d"; $values[] = intval( $filters['categorie_id'] ); }
        if ( ! empty( $filters['type_annonce'] ) ) { $where[] = "a.type_annonce = %s"; $values[] = sanitize_key( $filters['type_annonce'] ); }
        if ( ! empty( $filters['rubrique_id'] ) )  { $where[] = "a.rubrique_id = %d";  $values[] = intval( $filters['rubrique_id'] ); }
        if ( ! empty( $filters['ville'] ) )         { $where[] = "m.ville = %s";        $values[] = sanitize_text_field( $filters['ville'] ); }

        $sql = $wpdb->prepare(
            "SELECT a.*, c.nom AS cat_nom, c.slug AS cat_slug,
                    r.nom AS rub_nom, s.nom AS statut_nom, s.slug AS statut_slug,
                    m.ville, u.display_name AS membre_nom
             FROM $ta a
             LEFT JOIN $tc c ON c.id=a.categorie_id
             LEFT JOIN $tr r ON r.id=a.rubrique_id
             LEFT JOIN $ts s ON s.id=a.statut_id
             LEFT JOIN $tm m ON m.id=a.membre_id
             LEFT JOIN {$wpdb->users} u ON u.ID=m.wp_user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY a.date_creation DESC",
            ...$values
        );
        return $wpdb->get_results( $sql );
    }

    public static function get_prix( $annonce_id ) {
        global $wpdb;

        // Vérifier si la colonne coordination existe (migration progressive)
        $cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}seliweb_annonces_prix", 0 );
        $has_coord = in_array( 'coordination', $cols, true );

        $select = $has_coord
            ? "SELECT p.prix, p.coordination, m.nom, m.symbole"
            : "SELECT p.prix, NULL AS coordination, m.nom, m.symbole";

        return $wpdb->get_results( $wpdb->prepare(
            "$select
             FROM {$wpdb->prefix}seliweb_annonces_prix p
             LEFT JOIN {$wpdb->prefix}seliweb_monnaies m ON m.id=p.monnaie_id
             WHERE p.annonce_id=%d
             ORDER BY p.id ASC", $annonce_id
        ) );
    }

    public static function get_villes() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT ville FROM {$wpdb->prefix}seliweb_membres
             WHERE ville != '' AND ville IS NOT NULL ORDER BY ville ASC"
        );
    }
}
