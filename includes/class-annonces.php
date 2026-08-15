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
        echo '<div style="display:flex; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:4px;">';
        echo '<h1 style="margin:0;">' . esc_html__( 'Annonces', 'seliweb' );
        if ( $action === 'list' ) {
            echo ' <a href="' . esc_url( admin_url( 'admin.php?page=seliweb_annonces&action=new' ) ) . '" class="page-title-action">'
               . esc_html__( 'Ajouter une annonce', 'seliweb' ) . '</a>';
        }
        echo '</h1>';

        if ( $action === 'list' ) {
            global $wpdb;
            $tm = $wpdb->prefix . 'seliweb_membres';
            $membres_filtre = $wpdb->get_results(
                "SELECT m.id, u.display_name FROM $tm m LEFT JOIN {$wpdb->users} u ON u.ID=m.wp_user_id ORDER BY u.display_name"
            );
            $membre_id_filtre = isset( $_GET['membre_id'] ) ? intval( $_GET['membre_id'] ) : 0;

            echo '<div style="display:flex; align-items:center; gap:8px;">';
            echo '<span style="font-size:23px; font-weight:400; line-height:1.4;">' . esc_html__( 'Membre', 'seliweb' ) . '</span>';
            echo '<form method="get" style="margin:0;">';
            echo '<input type="hidden" name="page" value="seliweb_annonces">';
            if ( ! empty( $_GET['orderby'] ) ) echo '<input type="hidden" name="orderby" value="' . esc_attr( sanitize_key( $_GET['orderby'] ) ) . '">';
            if ( ! empty( $_GET['order'] ) )   echo '<input type="hidden" name="order"   value="' . esc_attr( sanitize_key( $_GET['order'] ) ) . '">';
            echo '<select name="membre_id" onchange="this.form.submit()">';
            echo '<option value="0">' . esc_html__( 'Tous les membres', 'seliweb' ) . '</option>';
            foreach ( $membres_filtre as $m ) {
                printf( '<option value="%d"%s>%s</option>',
                    intval( $m->id ),
                    $m->id == $membre_id_filtre ? ' selected' : '',
                    esc_html( $m->display_name )
                );
            }
            echo '</select></form></div>';
        }
        echo '</div>';

        // Notices d'erreur affichées dans le formulaire
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'no_rubrique' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Vous devez choisir une rubrique.', 'seliweb' ) . '</p></div>';
        }
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'bad_date' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( "Veuillez corriger la date d'expiration ou laisser le champ vide.", 'seliweb' ) . '</p></div>';
        }
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'photo_bad_format' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Format d\'image non pris en charge. Formats acceptés : JPG, PNG, GIF, WEBP.', 'seliweb' ) . '</p></div>';
        }
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'photo_too_large' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Le fichier est trop volumineux (5 Mo maximum).', 'seliweb' ) . '</p></div>';
        }
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'photo_upload_error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( "Erreur lors de l'envoi de l'image, merci de réessayer.", 'seliweb' ) . '</p></div>';
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

        // Plafond de photos = celui du groupe du membre sélectionné (1 par défaut)
        $membre_id  = ! empty( $_POST['membre_id'] ) ? intval( $_POST['membre_id'] ) : self::get_or_create_membre( get_current_user_id() );
        $photos_max = 1;
        $groupe_id_membre = $wpdb->get_var( $wpdb->prepare( "SELECT groupe_id FROM {$wpdb->prefix}seliweb_membres WHERE id=%d", $membre_id ) );
        if ( $groupe_id_membre ) {
            $photos_max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT photos_max FROM {$wpdb->prefix}seliweb_groupes WHERE id=%d", $groupe_id_membre ) ) ?: 1;
        }

        if ( $action === 'add_annonce' ) {
            $data['membre_id']     = $membre_id;
            $data['date_creation'] = current_time( 'mysql' );
            $data = array_filter( $data, function($v){ return $v !== null; } );
            $wpdb->insert( $ta, $data );
            $annonce_id = $wpdb->insert_id;
            self::save_prix_from_post( $annonce_id, $_POST );

            $photo_err = self::save_annonce_photos( $annonce_id, $photos_max );
            if ( $photo_err ) {
                wp_safe_redirect( add_query_arg( array( 'page' => 'seliweb_annonces', 'action' => 'edit', 'id' => $annonce_id, 'error' => $photo_err ), admin_url('admin.php') ) );
                exit;
            }
            self::notify_membres( $annonce_id );

        } elseif ( $action === 'update_annonce' ) {
            $annonce_id = intval( $_POST['id'] );
            // Ne pas filtrer statut_id et date_expiration (null = vider le champ)
            $wpdb->update( $ta, $data, array( 'id' => $annonce_id ) );
            $wpdb->delete( $tap, array( 'annonce_id' => $annonce_id ) );
            self::save_prix_from_post( $annonce_id, $_POST );

            $photo_err = self::save_annonce_photos( $annonce_id, $photos_max );
            if ( $photo_err ) {
                wp_safe_redirect( add_query_arg( array( 'page' => 'seliweb_annonces', 'action' => 'edit', 'id' => $annonce_id, 'error' => $photo_err ), admin_url('admin.php') ) );
                exit;
            }
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
    // Upload photo via médiathèque WP, avec contrôle format/taille.
    // Retourne [ url|null, code_erreur|null ] — un fichier absent n'est
    // pas une erreur (les deux valeurs sont alors null).
    // ----------------------------------------------------------------
    const PHOTO_EXTENSIONS_AUTORISEES = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
    const PHOTO_TAILLE_MAX            = 5 * MB_IN_BYTES;

    public static function handle_photo_upload( $field ) {
        if ( empty( $_FILES[ $field ]['name'] ) ) {
            return array( null, null );
        }

        $file = $_FILES[ $field ];

        if ( in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
            return array( null, 'photo_too_large' );
        }
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return array( null, 'photo_upload_error' );
        }
        if ( $file['size'] > self::PHOTO_TAILLE_MAX ) {
            return array( null, 'photo_too_large' );
        }

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, self::PHOTO_EXTENSIONS_AUTORISEES, true ) ) {
            return array( null, 'photo_bad_format' );
        }
        // Le type MIME réel doit correspondre — une extension renommée ne suffit pas.
        $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        if ( empty( $filetype['ext'] ) || ! in_array( strtolower( $filetype['ext'] ), self::PHOTO_EXTENSIONS_AUTORISEES, true ) ) {
            return array( null, 'photo_bad_format' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload( $field, 0 );
        if ( is_wp_error( $attachment_id ) ) {
            return array( null, 'photo_upload_error' );
        }
        return array( wp_get_attachment_url( $attachment_id ), null );
    }

    // ----------------------------------------------------------------
    // Sauvegarde des photos d'une annonce (création ET modification,
    // frontend ET backend) : suppression des photos cochées, upload des
    // nouvelles dans la limite du plafond du groupe, résolution de la
    // photo principale choisie (photo_principale = "existing_{id}",
    // "new_{slot}" ou "rubrique"). $_FILES attendu : photo_new_1..10.
    // Retourne un code d'erreur (format/taille), ou null si OK.
    // ----------------------------------------------------------------
    public static function save_annonce_photos( $annonce_id, $photos_max ) {
        global $wpdb;
        $tap = $wpdb->prefix . 'seliweb_annonces_photos';
        $ta  = $wpdb->prefix . 'seliweb_annonces';

        // 1. Suppressions demandées
        $a_supprimer = array_map( 'intval', (array) ( $_POST['supprimer_photo'] ?? array() ) );
        foreach ( $a_supprimer as $pid ) {
            $wpdb->delete( $tap, array( 'id' => $pid, 'annonce_id' => $annonce_id ) );
        }

        // 2. État courant après suppressions
        $existantes    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $tap WHERE annonce_id=%d", $annonce_id ) );
        $ordre_suivant = count( $existantes );
        $nouvelles_ids = array(); // slot => id nouvellement créé

        // 3. Nouveaux uploads, dans la limite du plafond du groupe
        for ( $i = 1; $i <= 10; $i++ ) {
            if ( ( count( $existantes ) + count( $nouvelles_ids ) ) >= $photos_max ) break;
            $field = 'photo_new_' . $i;
            if ( empty( $_FILES[ $field ]['name'] ) ) continue;
            list( $url, $err ) = self::handle_photo_upload( $field );
            if ( $err ) return $err;
            if ( $url ) {
                $wpdb->insert( $tap, array( 'annonce_id' => $annonce_id, 'url' => $url, 'ordre' => $ordre_suivant++ ) );
                $nouvelles_ids[ $i ] = $wpdb->insert_id;
            }
        }

        // 4. Résolution de la photo principale choisie
        $choix         = sanitize_text_field( $_POST['photo_principale'] ?? '' );
        $principale_id = null;
        if ( strpos( $choix, 'existing_' ) === 0 ) {
            $cid = intval( substr( $choix, 9 ) );
            $ok  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tap WHERE id=%d AND annonce_id=%d", $cid, $annonce_id ) );
            if ( $ok ) $principale_id = (int) $ok;
        } elseif ( strpos( $choix, 'new_' ) === 0 ) {
            $slot = intval( substr( $choix, 4 ) );
            if ( isset( $nouvelles_ids[ $slot ] ) ) $principale_id = $nouvelles_ids[ $slot ];
        }
        // Si aucun choix valide (ou "rubrique" explicite), retomber sur la
        // première photo disponible ; sinon aucune (image de rubrique).
        if ( ! $principale_id && $choix !== 'rubrique' ) {
            $premiere = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $tap WHERE annonce_id=%d ORDER BY ordre ASC, id ASC LIMIT 1", $annonce_id
            ) );
            $principale_id = $premiere ? (int) $premiere : null;
        }

        $wpdb->update( $ta, array( 'photo_principale_id' => $principale_id ), array( 'id' => $annonce_id ) );
        return null;
    }

    // ----------------------------------------------------------------
    // Sauvegarde des prix — structure groupée prix[N][montant|monnaie_id|coordination]
    // ----------------------------------------------------------------
    public static function save_prix_from_post( $annonce_id, $post ) {
        global $wpdb;
        $tap = $wpdb->prefix . 'seliweb_annonces_prix';
        if ( empty( $post['prix'] ) ) return;

        // Vérifier si la colonne coordination existe (migration progressive)
        $cols      = $wpdb->get_col( "DESCRIBE $tap", 0 );
        $has_coord = in_array( 'coordination', $cols, true );

        $used  = array();
        $first = true;
        foreach ( (array) $post['prix'] as $line ) {
            $montant    = intval( $line['montant'] ?? 0 );
            $monnaie_id = intval( $line['monnaie_id'] ?? 0 );
            if ( $montant <= 0 || $monnaie_id === 0 ) continue;
            if ( in_array( $monnaie_id, $used ) ) continue;
            $used[] = $monnaie_id;

            $row = array(
                'annonce_id' => $annonce_id,
                'monnaie_id' => $monnaie_id,
                'prix'       => $montant,
            );

            if ( $has_coord && ! $first ) {
                $coord_raw           = strtoupper( sanitize_text_field( $line['coordination'] ?? 'OU' ) );
                $row['coordination'] = in_array( $coord_raw, array( 'ET', 'OU' ) ) ? $coord_raw : 'OU';
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
        $tp = $wpdb->prefix . 'seliweb_parametres';

        $annonce = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id=%d", $annonce_id ) );
        if ( ! $annonce ) return;

        // Groupe de l'annonceur : seuls les membres ayant coché ce groupe
        // dans leurs notifications reçoivent le mail.
        $groupe_id = $wpdb->get_var( $wpdb->prepare( "SELECT groupe_id FROM $tm WHERE id=%d", $annonce->membre_id ) );
        if ( ! $groupe_id ) return;

        $membres = $wpdb->get_results( $wpdb->prepare(
            "SELECT wp_user_id FROM $tm WHERE notif_groupes IS NOT NULL AND FIND_IN_SET(%d, notif_groupes)",
            $groupe_id
        ) );
        if ( empty( $membres ) ) return;

        // Charger la config mail (clés mail_annonce_*)
        $rows = $wpdb->get_results( "SELECT cle, valeur FROM $tp WHERE cle LIKE 'mail\_annonce\_%'" );
        $cfg  = array();
        foreach ( $rows as $r ) $cfg[ $r->cle ] = $r->valeur;

        $url   = home_url( '/?seliweb_annonce=' . $annonce_id );
        $titre = $annonce->titre;

        $sujet_tpl = $cfg['mail_annonce_subject'] ?? sprintf( '[%s] %s', get_bloginfo('name'), __( 'Nouvelle annonce : {titre}', 'seliweb' ) );
        $sujet     = str_replace( '{titre}', $titre, $sujet_tpl );

        $corps = sprintf( __( "Une nouvelle annonce a été publiée :\n\n%s\n\nVoir : %s", 'seliweb' ), $titre, $url );

        $intro      = trim( $cfg['mail_annonce_intro']      ?? '' );
        $sig        = trim( $cfg['mail_annonce_signature']  ?? '' );
        $from_email = trim( $cfg['mail_annonce_from_email'] ?? '' );
        $from_name  = trim( $cfg['mail_annonce_from_name']  ?? '' );
        if ( $intro ) $corps = $intro . "\n\n" . $corps;
        if ( $sig )   $corps = $corps . "\n" . $sig;

        $headers = array();
        if ( $from_email && is_email( $from_email ) ) {
            $headers[] = 'From: ' . ( $from_name ? $from_name . ' <' . $from_email . '>' : $from_email );
        }

        foreach ( $membres as $m ) {
            $user = get_userdata( $m->wp_user_id );
            if ( $user && $user->user_email ) wp_mail( $user->user_email, $sujet, $corps, $headers );
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
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'photo_bad_format' )  echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Format d\'image non pris en charge. Formats acceptés : JPG, PNG, GIF, WEBP.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'photo_too_large' )   echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Le fichier est trop volumineux (5 Mo maximum).', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'photo_upload_error' ) echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( "Erreur lors de l'envoi de l'image, merci de réessayer.", 'seliweb' ) . '</p></div>';

        // Tri par colonne
        $allowed_orderby = [
            'id'         => 'a.id',
            'categorie'  => 'c.nom',
            'rubrique'   => 'r.nom',
            'membre'     => 'u.display_name',
            'statut'     => 's.nom',
            'expiration' => 'a.date_expiration',
        ];
        $orderby_key = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : '';
        $order_dir   = ( isset( $_GET['order'] ) && strtolower( $_GET['order'] ) === 'desc' ) ? 'DESC' : 'ASC';
        if ( isset( $allowed_orderby[ $orderby_key ] ) ) {
            $order_sql = $allowed_orderby[ $orderby_key ];
        } else {
            $order_sql = 'a.date_creation';
            $order_dir = 'DESC';
        }

        // Filtre par membre
        $membre_id_filtre = isset( $_GET['membre_id'] ) ? intval( $_GET['membre_id'] ) : 0;
        $where = $membre_id_filtre > 0 ? $wpdb->prepare( 'WHERE a.membre_id = %d', $membre_id_filtre ) : '';

        // Pagination
        $per_page    = 40;
        $total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ta a $where" );
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        $page        = isset( $_GET['paged'] ) ? max( 1, min( $total_pages, intval( $_GET['paged'] ) ) ) : 1;
        $offset      = ( $page - 1 ) * $per_page;

        $items = $wpdb->get_results(
            "SELECT a.*, c.nom AS cat_nom, r.nom AS rub_nom, s.nom AS statut_nom, u.display_name AS membre_nom
             FROM $ta a
             LEFT JOIN $tc c ON c.id=a.categorie_id
             LEFT JOIN $tr r ON r.id=a.rubrique_id
             LEFT JOIN $ts s ON s.id=a.statut_id
             LEFT JOIN $tm m ON m.id=a.membre_id
             LEFT JOIN {$wpdb->users} u ON u.ID=m.wp_user_id
             $where
             ORDER BY $order_sql $order_dir
             LIMIT $per_page OFFSET $offset"
        );

        // Construit l'URL de base en préservant filtre membre + tri
        $base_params = [ 'page' => 'seliweb_annonces' ];
        if ( $membre_id_filtre ) $base_params['membre_id'] = $membre_id_filtre;
        if ( $orderby_key )      $base_params['orderby']   = $orderby_key;
        if ( $orderby_key )      $base_params['order']     = strtolower( $order_dir );
        $base_url = admin_url( 'admin.php?' . http_build_query( $base_params ) );

        // URL d'une page (préserve filtre + tri, repart à la page demandée)
        $page_url = function( $p ) use ( $base_params ) {
            $params = $base_params;
            $params['paged'] = $p;
            return esc_url( admin_url( 'admin.php?' . http_build_query( $params ) ) );
        };

        // URL de tri (repart toujours à la page 1)
        $col_url = function( $col ) use ( $base_url, $orderby_key, $order_dir ) {
            $new_dir = ( $orderby_key === $col && $order_dir === 'ASC' ) ? 'desc' : 'asc';
            return esc_url( $base_url . '&orderby=' . $col . '&order=' . $new_dir );
        };
        $col_arrow = function( $col ) use ( $orderby_key, $order_dir ) {
            if ( $orderby_key !== $col ) return ' <span style="color:#bbb; font-size:10px;">&#8597;</span>';
            return $order_dir === 'ASC'
                ? ' <span style="font-size:10px;">&#8593;</span>'
                : ' <span style="font-size:10px;">&#8595;</span>';
        };

        // Barre de pagination
        $pagination = function() use ( $page, $total_pages, $page_url ) {
            echo '<div style="display:flex; align-items:center; gap:10px; margin:12px 0 4px;">';
            echo '<strong style="font-size:13px;">' . sprintf( esc_html__( 'Page %d / %d', 'seliweb' ), $page, $total_pages ) . '</strong>';
            if ( $page > 1 ) {
                echo '<a href="' . $page_url( $page - 1 ) . '" class="button button-secondary">&#8592; ' . esc_html__( 'Précédent', 'seliweb' ) . '</a>';
            } else {
                echo '<button class="button button-secondary" disabled>&#8592; ' . esc_html__( 'Précédent', 'seliweb' ) . '</button>';
            }
            if ( $page < $total_pages ) {
                echo '<a href="' . $page_url( $page + 1 ) . '" class="button button-secondary">' . esc_html__( 'Suivant', 'seliweb' ) . ' &#8594;</a>';
            } else {
                echo '<button class="button button-secondary" disabled>' . esc_html__( 'Suivant', 'seliweb' ) . ' &#8594;</button>';
            }
            echo '</div>';
        };

        $pagination();
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th style="width:40px;"><a href="<?php echo $col_url( 'id' ); ?>" style="text-decoration:none; color:inherit; white-space:nowrap;">ID<?php echo $col_arrow( 'id' ); ?></a></th>
                <th><?php esc_html_e( 'Titre', 'seliweb' ); ?></th>
                <th><a href="<?php echo $col_url( 'categorie' ); ?>" style="text-decoration:none; color:inherit; white-space:nowrap;"><?php esc_html_e( 'Catégorie', 'seliweb' ); ?><?php echo $col_arrow( 'categorie' ); ?></a></th>
                <th><a href="<?php echo $col_url( 'rubrique' ); ?>" style="text-decoration:none; color:inherit; white-space:nowrap;"><?php esc_html_e( 'Rubrique', 'seliweb' ); ?><?php echo $col_arrow( 'rubrique' ); ?></a></th>
                <th><a href="<?php echo $col_url( 'membre' ); ?>" style="text-decoration:none; color:inherit; white-space:nowrap;"><?php esc_html_e( 'Membre', 'seliweb' ); ?><?php echo $col_arrow( 'membre' ); ?></a></th>
                <th><a href="<?php echo $col_url( 'statut' ); ?>" style="text-decoration:none; color:inherit; white-space:nowrap;"><?php esc_html_e( 'Statut', 'seliweb' ); ?><?php echo $col_arrow( 'statut' ); ?></a></th>
                <th><a href="<?php echo $col_url( 'expiration' ); ?>" style="text-decoration:none; color:inherit; white-space:nowrap;"><?php esc_html_e( 'Expiration', 'seliweb' ); ?><?php echo $col_arrow( 'expiration' ); ?></a></th>
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
        $pagination();
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
            "SELECT m.id, m.groupe_id, g.limite_annonces AS groupe_limite, g.photos_max AS groupe_photos_max,
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
                'monnaies'   => $mon_ids,
                'nb_ann'     => $nb_ann,
                'limite'     => (int)$limite_eff,
                'photosMax'  => $ml->groupe_photos_max ? (int) $ml->groupe_photos_max : 1,
            );
        }

        // Lignes de prix à afficher : existantes ou 1 ligne vide
        $prix_lignes = ! empty( $prix_map ) ? $prix_map : array( '' => '' );

        // Photos existantes de l'annonce (édition) + plafond du groupe du membre sélectionné
        $tap_photos       = $wpdb->prefix . 'seliweb_annonces_photos';
        $photos_existantes = $item
            ? $wpdb->get_results( $wpdb->prepare( "SELECT id, url FROM $tap_photos WHERE annonce_id=%d ORDER BY ordre ASC, id ASC", $item->id ) )
            : array();
        $photos_max_init = isset( $membres_data[ $membre_sel_id ] ) ? $membres_data[ $membre_sel_id ]['photosMax'] : 1;

        // Image de chaque rubrique, pour l'aperçu JS de l'option "Utiliser l'image de la rubrique"
        $rubrique_images = array();
        foreach ( $rubriques as $rub ) {
            $rubrique_images[ $rub->id ] = $rub->image ?: '';
        }
        ?>
        <form id="seliweb-admin-form-annonce" method="post" enctype="multipart/form-data" style="max-width:750px;">
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
                        <select id="rubrique_id" name="rubrique_id" onchange="selAdmUpdateRubriqueImage(this.value)">
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
                        <p class="description"><?php esc_html_e( 'Ne pas remplir si annonce permanente', 'seliweb' ); ?></p>
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
                            $coord_map = array();
                            if ( $id ) {
                                foreach ( $wpdb->get_results( $wpdb->prepare(
                                    "SELECT monnaie_id, coordination FROM {$wpdb->prefix}seliweb_annonces_prix WHERE annonce_id=%d ORDER BY id ASC", $id
                                ) ) as $pc ) {
                                    $coord_map[ $pc->monnaie_id ] = $pc->coordination;
                                }
                            }
                            $adm_n = 0;
                            foreach ( $prix_lignes as $mon_id => $montant ) :
                                $coord_val = $coord_map[ $mon_id ] ?? 'OU';
                            ?>
                            <div class="seliweb-prix-row" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                <?php if ( $adm_n === 0 ) : ?>
                                    <span style="display:inline-block;width:60px;"></span>
                                <?php else : ?>
                                    <select name="prix[<?php echo $adm_n; ?>][coordination]" style="width:60px;">
                                        <option value="OU" <?php selected($coord_val,'OU'); ?>>OU</option>
                                        <option value="ET" <?php selected($coord_val,'ET'); ?>>ET</option>
                                    </select>
                                <?php endif; ?>
                                <input type="number" name="prix[<?php echo $adm_n; ?>][montant]"
                                       value="<?php echo esc_attr( $montant ); ?>"
                                       min="1" step="1" style="width:100px;"
                                       placeholder="<?php esc_attr_e( 'Montant', 'seliweb' ); ?>">
                                <select name="prix[<?php echo $adm_n; ?>][monnaie_id]" class="adm-prix-select">
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
                            <?php $adm_n++; endforeach; ?>
                        </div>
                        <button type="button" class="button" onclick="selAdmAddPrix()">
                            <?php esc_html_e( '+ Ajouter une monnaie', 'seliweb' ); ?>
                        </button>
                        <p class="description"><?php esc_html_e( 'Chaque ligne doit avoir une monnaie différente.', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <!-- Photos -->
                <tr>
                    <th><?php esc_html_e( 'Photos', 'seliweb' ); ?></th>
                    <td>
                        <p class="description" id="adm_photos_info">
                            <?php printf( esc_html__( 'Photos actuelles : %1$d / %2$d (selon le groupe du membre).', 'seliweb' ), count( $photos_existantes ), $photos_max_init ); ?>
                        </p>

                        <p style="margin-top:8px;">
                            <label>
                                <input type="radio" name="photo_principale" value="rubrique"
                                       <?php checked( ! $item || $item->photo_principale_id === null ); ?>>
                                <?php esc_html_e( 'Utiliser l\'image de la rubrique', 'seliweb' ); ?>
                            </label>
                            <img id="adm_rubrique_apercu" src="<?php echo esc_url( $item && $item->rubrique_id ? ( $rubrique_images[ $item->rubrique_id ] ?? '' ) : '' ); ?>"
                                 style="max-height:40px;vertical-align:middle;margin-left:8px;border-radius:3px;border:1px solid #ddd;<?php echo ( $item && $item->rubrique_id && ! empty( $rubrique_images[ $item->rubrique_id ] ) ) ? '' : 'display:none;'; ?>">
                        </p>
                        <p class="description"><?php esc_html_e( 'Formats acceptés : JPG, PNG, GIF, WEBP — 5 Mo maximum.', 'seliweb' ); ?></p>

                        <?php if ( $photos_existantes ) : ?>
                            <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
                                <?php foreach ( $photos_existantes as $p ) : ?>
                                    <div style="text-align:center;">
                                        <img src="<?php echo esc_url( $p->url ); ?>" style="max-height:80px;display:block;border-radius:3px;border:1px solid #ddd;">
                                        <label style="display:block;font-size:11px;margin-top:2px;">
                                            <input type="radio" name="photo_principale" value="existing_<?php echo intval( $p->id ); ?>"
                                                   <?php checked( $item && (int) $item->photo_principale_id === (int) $p->id ); ?>>
                                            <?php esc_html_e( 'Principale', 'seliweb' ); ?>
                                        </label>
                                        <label style="display:block;font-size:11px;color:#b32d2e;">
                                            <input type="checkbox" name="supprimer_photo[]" value="<?php echo intval( $p->id ); ?>">
                                            <?php esc_html_e( 'Supprimer', 'seliweb' ); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div id="adm_photos_slots">
                            <?php for ( $i = 1; $i <= 10; $i++ ) : ?>
                                <div class="seliweb-photo-slot" data-slot="<?php echo $i; ?>"
                                     style="<?php echo $i <= max( 0, $photos_max_init - count( $photos_existantes ) ) ? '' : 'display:none;'; ?>margin-bottom:8px;">
                                    <input type="file" name="photo_new_<?php echo $i; ?>" accept="image/jpeg,image/png,image/gif,image/webp">
                                    <label style="font-size:11px;">
                                        <input type="radio" name="photo_principale" value="new_<?php echo $i; ?>">
                                        <?php esc_html_e( 'Principale', 'seliweb' ); ?>
                                    </label>
                                </div>
                            <?php endfor; ?>
                        </div>
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
        var prixAdmNextIdx = <?php echo count( $prix_lignes ); ?>;

        // Données par membre : monnaies autorisées, limite, nb annonces, plafond photos
        var selAdmMembresData = <?php echo wp_json_encode($membres_data); ?>;

        // Image de chaque rubrique (aperçu de l'option "Utiliser l'image de la rubrique")
        var selAdmRubriqueImages = <?php echo wp_json_encode( $rubrique_images ); ?>;
        var selAdmPhotosExistantes = <?php echo count( $photos_existantes ); ?>;

        function selAdmUpdateRubriqueImage(rubriqueId) {
            var img = document.getElementById('adm_rubrique_apercu');
            var url = selAdmRubriqueImages[rubriqueId] || '';
            if (url) { img.src = url; img.style.display = ''; }
            else { img.style.display = 'none'; }
        }

        function selAdmUpdatePhotoSlots(photosMax) {
            var restant = Math.max(0, photosMax - selAdmPhotosExistantes);
            document.getElementById('adm_photos_info').textContent =
                <?php echo wp_json_encode( __( 'Photos actuelles : ', 'seliweb' ) ); ?> + selAdmPhotosExistantes + ' / ' + photosMax
                + <?php echo wp_json_encode( ' (' . __( 'selon le groupe du membre', 'seliweb' ) . ').' ); ?>;
            document.querySelectorAll('#adm_photos_slots .seliweb-photo-slot').forEach(function(slot){
                slot.style.display = (parseInt(slot.dataset.slot, 10) <= restant) ? '' : 'none';
            });
        }

        // Toutes les monnaies indexées par id
        var selAdmMonnaiesById = {};
        selAdmMonnaies.forEach(function(m){ selAdmMonnaiesById[m.id] = m; });

        // Monnaies actuellement autorisées (filtrées selon le groupe du membre sélectionné)
        var selAdmMonnaiesFiltrees = selAdmMonnaies.slice();

        function selAdmChangeMembre(membreId) {
            var info = document.getElementById('adm_membre_info');
            if (!membreId || !selAdmMembresData[membreId]) {
                info.textContent = '';
                selAdmMonnaiesFiltrees = selAdmMonnaies;
            selAdmResetPrix(selAdmMonnaies); // toutes monnaies si pas de membre
                selAdmUpdatePhotoSlots(1);
                return;
            }
            var d = selAdmMembresData[membreId];
            selAdmUpdatePhotoSlots(d.photosMax);

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
            selAdmMonnaiesFiltrees = monnaiesAutorisees;
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
            var available = selAdmMonnaiesFiltrees.filter(function(m){ return usedIds.indexOf(String(m.id)) === -1; });
            if (available.length === 0) {
                alert(<?php echo wp_json_encode( __( 'Toutes les monnaies sont déjà utilisées.', 'seliweb' ) ); ?>);
                return;
            }
            var idx = prixAdmNextIdx++;
            var opts = '<option value=""><?php esc_attr_e('— Monnaie —','seliweb'); ?></option>';
            selAdmMonnaiesFiltrees.forEach(function(m){ opts += '<option value="'+m.id+'">'+m.label+'</option>'; });
            var row = document.createElement('div');
            row.className = 'seliweb-prix-row';
            row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
            row.innerHTML = '<select name="prix['+idx+'][coordination]" style="width:60px;"><option value="OU">OU</option><option value="ET">ET</option></select>'
                          + '<input type="number" name="prix['+idx+'][montant]" min="1" step="1" style="width:100px;" placeholder="Montant">'
                          + '<select name="prix['+idx+'][monnaie_id]" class="adm-prix-select">'+opts+'</select>'
                          + '<button type="button" class="button" onclick="this.closest(\'.seliweb-prix-row\').remove()">✕</button>';
            document.getElementById('adm_prix_container').appendChild(row);
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
                    r.nom AS rub_nom, r.image AS rub_image, s.nom AS statut_nom, s.slug AS statut_slug,
                    m.ville, u.display_name AS membre_nom,
                    ap.url AS photo_affichee
             FROM $ta a
             LEFT JOIN $tc c ON c.id=a.categorie_id
             LEFT JOIN $tr r ON r.id=a.rubrique_id
             LEFT JOIN $ts s ON s.id=a.statut_id
             LEFT JOIN $tm m ON m.id=a.membre_id
             LEFT JOIN {$wpdb->users} u ON u.ID=m.wp_user_id
             LEFT JOIN {$wpdb->prefix}seliweb_annonces_photos ap ON ap.id=a.photo_principale_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY a.date_creation DESC",
            ...$values
        );
        return $wpdb->get_results( $sql );
    }

    public static function get_prix( $annonce_id ) {
        global $wpdb;
        $annonce_id = intval( $annonce_id );
        if ( $annonce_id <= 0 ) return array();

        // Vérifier si la colonne coordination existe (migration progressive) — mis en cache statique
        static $has_coord = null;
        if ( $has_coord === null ) {
            $cols      = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}seliweb_annonces_prix", 0 );
            $has_coord = in_array( 'coordination', $cols, true );
        }

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
