<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seliweb_Cotisations {

    private static $cfg = null;

    public static function init() {
        add_action( 'init',       array( __CLASS__, 'handle_exclu_get' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_admin_post' ) );
    }

    public static function handle_exclu_get() {
        if ( ! is_admin() ) return;
        if ( ( $_GET['page'] ?? '' ) !== 'seliweb_cotisations' ) return;
        if ( ! isset( $_GET['exclu_id'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $id = intval( $_GET['exclu_id'] );
        if ( ! check_admin_referer( 'seliweb_exclu_' . $id ) ) return;

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'seliweb_cotisations',
            array( 'sync_exclu' => 1 ),
            array( 'id' => $id )
        );

        $groupe_id = intval( $_GET['groupe_id'] ?? 0 );
        $exercice  = sanitize_text_field( wp_unslash( $_GET['exercice'] ?? '' ) );
        wp_safe_redirect( admin_url(
            'admin.php?page=seliweb_cotisations&groupe_id=' . $groupe_id
            . '&exercice=' . urlencode( $exercice )
        ) );
        exit;
    }

    // ================================================================
    // CONFIG
    // ================================================================
    private static function cfg() {
        if ( self::$cfg !== null ) return self::$cfg;
        global $wpdb;
        $tp   = $wpdb->prefix . 'seliweb_parametres';
        $rows = $wpdb->get_results(
            "SELECT cle, valeur FROM $tp WHERE cle LIKE 'helloasso\_%' OR cle LIKE 'paheko\_%' OR cle LIKE 'cotisations\_%'"
        );
        self::$cfg = array();
        foreach ( $rows as $r ) self::$cfg[ $r->cle ] = $r->valeur;
        return self::$cfg;
    }

    private static function cfg_save( $params ) {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_parametres';
        foreach ( $params as $cle => $valeur ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tp WHERE cle=%s LIMIT 1", $cle ) );
            if ( $exists ) {
                $wpdb->update( $tp, array( 'valeur' => $valeur ), array( 'cle' => $cle ) );
            } else {
                $wpdb->insert( $tp, array( 'cle' => $cle, 'valeur' => $valeur ) );
            }
        }
        self::$cfg = null;
    }

    public static function cotisations_actif() {
        $cfg = self::cfg();
        return ! empty( $cfg['cotisations_actif'] );
    }

    // Le groupe indiqué est-il soumis à cotisation (Paramètres > Cotisations > Groupes) ?
    public static function groupe_soumis( $groupe_id ) {
        if ( ! $groupe_id ) return false;
        $cfg         = self::cfg();
        $groupes_ids = array_map( 'intval', explode( ',', $cfg['cotisations_groupes'] ?? '' ) );
        return in_array( (int) $groupe_id, $groupes_ids, true );
    }

    // ================================================================
    // HELLOASSO API
    // ================================================================
    private static function ha_base_url() {
        $cfg = self::cfg();
        $url = $cfg['helloasso_campaign_url'] ?? '';
        return ( strpos( $url, 'sandbox' ) !== false )
            ? 'https://api.helloasso-sandbox.com'
            : 'https://api.helloasso.com';
    }

    public static function ha_get_token() {
        $cached = get_transient( 'seliweb_ha_token' );
        if ( $cached ) return $cached;

        $cfg    = self::cfg();
        $id     = trim( $cfg['helloasso_client_id']     ?? '' );
        $secret = trim( $cfg['helloasso_client_secret'] ?? '' );
        if ( ! $id || ! $secret ) return null;

        $response = wp_remote_post(
            self::ha_base_url() . '/oauth2/token',
            array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $id . ':' . $secret ),
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ),
                'body'    => 'grant_type=client_credentials',
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) return null;
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) return null;

        $ttl = max( 60, intval( $data['expires_in'] ?? 1800 ) - 60 );
        set_transient( 'seliweb_ha_token', $data['access_token'], $ttl );
        return $data['access_token'];
    }

    public static function ha_get_orders( $from_date = null ) {
        $cfg  = self::cfg();
        $org  = trim( $cfg['helloasso_org_slug']  ?? '' );
        $form = trim( $cfg['helloasso_form_slug'] ?? '' );
        if ( ! $org || ! $form ) return array();

        $token = self::ha_get_token();
        if ( ! $token ) return array();

        $url = self::ha_base_url() . '/v5/organizations/' . rawurlencode( $org )
             . '/forms/Membership/' . rawurlencode( $form ) . '/orders';
        if ( $from_date ) $url = add_query_arg( 'from', $from_date, $url );

        $response = wp_remote_get( $url, array(
            'headers' => array( 'Authorization' => 'Bearer ' . $token ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) return array();
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['data'] ?? array();
    }

    // ================================================================
    // PAHEKO API
    // ================================================================
    public static function paheko_request( $method, $path, $data = array() ) {
        $cfg          = self::cfg();
        $base         = rtrim( $cfg['paheko_url']         ?? '', '/' );
        $identifiant  = trim( $cfg['paheko_identifiant']  ?? '' );
        $mot_de_passe = trim( $cfg['paheko_mot_de_passe'] ?? '' );
        if ( ! $base || ! $identifiant || ! $mot_de_passe ) return null;

        $args = array(
            'method'  => strtoupper( $method ),
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $identifiant . ':' . $mot_de_passe ),
            ),
            'timeout' => 20,
        );
        if ( $data && strtoupper( $method ) !== 'GET' ) {
            $args['body'] = $data;
        }

        $response = wp_remote_request( $base . '/api/' . ltrim( $path, '/' ), $args );
        if ( is_wp_error( $response ) ) return null;
        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    public static function paheko_find_user_by_email( $email ) {
        $email  = sanitize_email( $email );
        $result = self::paheko_request( 'POST', 'sql', array(
            'sql' => "SELECT id, nom, email FROM users WHERE email='" . esc_sql( $email ) . "' LIMIT 1",
        ) );
        return $result['results'][0] ?? null;
    }

    public static function paheko_create_user( $nom, $email ) {
        $result = self::paheko_request( 'POST', 'user/new', array(
            'nom'   => sanitize_text_field( $nom ),
            'email' => sanitize_email( $email ),
        ) );
        return $result['id'] ?? null;
    }

    public static function paheko_get_years() {
        $result = self::paheko_request( 'GET', 'accounting/years' );
        return is_array( $result ) ? $result : array();
    }

    public static function paheko_get_services() {
        $result = self::paheko_request( 'POST', 'sql', array(
            'sql' => 'SELECT s.id AS service_id, s.label AS service_label, f.id AS fee_id, f.label AS fee_label, f.amount FROM services s JOIN services_fees f ON f.id_service=s.id ORDER BY s.id, f.id',
        ) );
        return $result['results'] ?? array();
    }

    private static function paheko_subscribe( $paheko_user_id, $id_service, $id_fee, $date, $amount_euros ) {
        return self::paheko_request( 'POST', 'user/' . intval( $paheko_user_id ) . '/subscribe', array(
            'id_service' => intval( $id_service ),
            'id_fee'     => intval( $id_fee ),
            'date'       => $date,
            'paid'       => 1,
            'amount'     => $amount_euros,
        ) );
    }

    private static function paheko_create_transaction( $id_year, $label, $date_fr, $amount_euros, $compte_debit = '512', $paheko_user_id = null ) {
        $body = array(
            'id_year' => intval( $id_year ),
            'type'    => 'revenue',
            'label'   => sanitize_text_field( $label ),
            'date'    => $date_fr,
            'amount'  => $amount_euros,
            'debit'   => $compte_debit,
            'credit'  => '756',
        );
        if ( $paheko_user_id ) {
            $body['linked_users'] = json_encode( [ intval( $paheko_user_id ) ] );
        }
        return self::paheko_request( 'POST', 'accounting/transaction', $body );
    }

    // ================================================================
    // ENREGISTREMENT D'UNE COTISATION PAYÉE
    // ================================================================
    // Appelée par Seliweb_Paiements::process_order() lorsqu'un paiement
    // HelloAsso rattaché à un membre correspond à une offre marquée
    // "enregistre une cotisation" (adhésion, renouvellement...). Reste
    // la voie minoritaire : ~80% des cotisations sont encore saisies
    // manuellement par le trésorier via l'onglet Cotisations.
    public static function enregistrer_paiement_cotisation( $wp_user_id, $montant, $date, $helloasso_order_id = '', $email = '', $nom = '' ) {
        global $wpdb;
        $tc = $wpdb->prefix . 'seliweb_cotisations';
        $wpdb->insert( $tc, array(
            'wp_user_id'            => intval( $wp_user_id ),
            'montant'               => intval( $montant ),
            'date_paiement'         => $date,
            'statut'                => 'paye',
            'helloasso_order_id'    => $helloasso_order_id,
            'helloasso_payer_email' => $email,
            'helloasso_payer_nom'   => $nom,
            'paheko_synced'         => 0,
            'created_at'            => current_time( 'mysql' ),
        ) );
        return (int) $wpdb->insert_id;
    }

    private static function sync_to_paheko( $cotisation_id, $email, $nom, $date, $id_year, $id_fee, $id_service ) {
        global $wpdb;
        $tc  = $wpdb->prefix . 'seliweb_cotisations';
        $tr  = $wpdb->prefix . 'seliweb_cotisations_reglements';
        $tmo = $wpdb->prefix . 'seliweb_monnaies';

        // Récupérer uniquement les règlements en monnaie légale
        $reglements = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, mo.est_legale, mo.est_defaut
             FROM $tr r
             LEFT JOIN $tmo mo ON mo.id = r.monnaie_id
             WHERE r.cotisation_id = %d AND mo.est_legale = 1
             ORDER BY r.id",
            $cotisation_id
        ) );

        if ( ! $reglements ) return false;

        // Trouver ou créer le membre dans Paheko
        $paheko_user = $email ? self::paheko_find_user_by_email( $email ) : null;
        if ( $paheko_user ) {
            $paheko_user_id = $paheko_user['id'];
        } else {
            $paheko_user_id = $nom ? self::paheko_create_user( $nom, $email ) : null;
        }
        if ( ! $paheko_user_id ) return false;

        // Inscrire au service (une seule fois, sur le montant total légal)
        if ( $id_service && $id_fee ) {
            $total_euros = array_sum( array_map( fn( $r ) => round( $r->montant / 100, 2 ), $reglements ) );
            self::paheko_subscribe( $paheko_user_id, $id_service, $id_fee, $date, $total_euros );
        }

        // Une écriture comptable par règlement (512 banque ou 530 caisse)
        if ( $id_year ) {
            $date_fr = date( 'd/m/Y', strtotime( $date ) );
            foreach ( $reglements as $rg ) {
                $compte_debit = ( $rg->mode_paiement === 'especes' ) ? '530' : '512';
                $amount_euros = round( $rg->montant / 100, 2 );
                $label        = sprintf( __( 'Cotisation - %s', 'seliweb' ), $nom );
                self::paheko_create_transaction( $id_year, $label, $date_fr, $amount_euros, $compte_debit, $paheko_user_id );
            }
        }

        $wpdb->update( $tc, array(
            'paheko_synced'  => 1,
            'paheko_id_year' => $id_year ?: null,
            'paheko_id_fee'  => $id_fee  ?: null,
        ), array( 'id' => $cotisation_id ) );

        return true;
    }

    // ================================================================
    // FRONTEND — statut cotisation d'un membre
    // ================================================================
    public static function get_cotisation_membre( $wp_user_id ) {
        global $wpdb;
        $tc = $wpdb->prefix . 'seliweb_cotisations';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $tc WHERE wp_user_id=%d AND statut='paye' ORDER BY date_paiement DESC LIMIT 1",
            $wp_user_id
        ) );
    }

    // ================================================================
    // ADMIN — page Cotisations (sous-menu)
    // ================================================================
    public static function handle_admin_post() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_cotisations' ) return;
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Accès refusé.', 'seliweb' ) );

        // Rafraîchissement du cache Paheko (GET, avant tout affichage)
        if ( isset( $_GET['refresh_paheko'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'seliweb_refresh_paheko' ) ) {
            delete_transient( 'seliweb_paheko_years' );
            delete_transient( 'seliweb_paheko_services' );
            $exercice = sanitize_text_field( wp_unslash( $_GET['exercice'] ?? '' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_cotisations&view=sync'
                . ( $exercice ? '&exercice=' . urlencode( $exercice ) : '' )
                . '&refreshed=1' ) );
            exit;
        }

        $action = $_POST['seliweb_action'] ?? '';

        if ( $action === 'save_cotisation' ) {
            if ( ! wp_verify_nonce( $_POST['seliweb_cot_nonce'] ?? '', 'seliweb_cotisation_save' ) ) {
                wp_die( __( 'Nonce invalide.', 'seliweb' ) );
            }
            self::save_cotisation();
        }

        if ( $action === 'sync_paheko' ) {
            if ( ! wp_verify_nonce( $_POST['seliweb_sync_nonce'] ?? '', 'seliweb_sync_paheko' ) ) {
                wp_die( __( 'Nonce invalide.', 'seliweb' ) );
            }
            self::process_sync();
        }
    }

    private static function save_cotisation() {
        global $wpdb;
        $tc = $wpdb->prefix . 'seliweb_cotisations';
        $tr = $wpdb->prefix . 'seliweb_cotisations_reglements';
        $te = $wpdb->prefix . 'seliweb_exercices';

        $cotisation_id = intval( $_POST['cotisation_id'] ?? 0 );
        $wp_user_id    = intval( $_POST['wp_user_id']    ?? 0 );
        $groupe_id     = intval( $_POST['groupe_id']     ?? 0 );
        $exercice      = sanitize_text_field( wp_unslash( $_POST['exercice']      ?? '' ) );
        $libelle       = sanitize_text_field( wp_unslash( $_POST['libelle']       ?? '' ) );
        $date_paiement = sanitize_text_field( wp_unslash( $_POST['date_paiement'] ?? current_time( 'Y-m-d' ) ) );

        if ( ! $wp_user_id ) return;

        if ( $exercice && $date_paiement ) {
            $ex_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT date_debut, date_fin FROM $te WHERE libelle=%s LIMIT 1", $exercice
            ) );
            if ( $ex_row && $ex_row->date_debut && $ex_row->date_fin
                 && ( $date_paiement < $ex_row->date_debut || $date_paiement > $ex_row->date_fin ) ) {
                $rp = array(
                    'page'       => 'seliweb_cotisations',
                    'sel_action' => $cotisation_id ? 'modifier' : 'ajouter',
                    'groupe_id'  => $groupe_id,
                    'exercice'   => $exercice,
                    'err_date'   => 1,
                );
                $rp[ $cotisation_id ? 'id' : 'membre_id' ] = $cotisation_id ?: $wp_user_id;
                wp_safe_redirect( admin_url( 'admin.php?' . http_build_query( $rp ) ) );
                exit;
            }
        }

        $reglements_data = array();
        $montant_total   = 0;
        foreach ( (array) ( $_POST['reglements'] ?? array() ) as $rl ) {
            $montant_raw = floatval( str_replace( ',', '.', $rl['montant'] ?? '0' ) );
            if ( $montant_raw <= 0 ) continue;
            $centimes = intval( round( $montant_raw * 100 ) );
            $montant_total += $centimes;
            $coord = $rl['coordination'] ?? null;
            $reglements_data[] = array(
                'montant'       => $centimes,
                'monnaie_id'    => intval( $rl['monnaie_id'] ?? 0 ),
                'mode_paiement' => sanitize_key( $rl['mode_paiement'] ?? 'especes' ),
                'coordination'  => in_array( $coord, array( 'ET', 'OU' ), true ) ? $coord : null,
            );
        }

        if ( empty( $reglements_data ) ) {
            wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=seliweb_cotisations' ) );
            exit;
        }

        $cot_data = array(
            'wp_user_id'    => $wp_user_id,
            'exercice'      => $exercice,
            'libelle'       => $libelle,
            'montant'       => $montant_total,
            'date_paiement' => $date_paiement,
            'statut'        => 'paye',
        );

        if ( $cotisation_id ) {
            $wpdb->update( $tc, $cot_data, array( 'id' => $cotisation_id ) );
            $wpdb->delete( $tr, array( 'cotisation_id' => $cotisation_id ) );
        } else {
            $cot_data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $tc, $cot_data );
            $cotisation_id = (int) $wpdb->insert_id;
        }

        foreach ( $reglements_data as $rl ) {
            $rl['cotisation_id'] = $cotisation_id;
            $wpdb->insert( $tr, $rl );
        }

        wp_safe_redirect( admin_url(
            'admin.php?page=seliweb_cotisations&groupe_id=' . $groupe_id
            . '&exercice=' . urlencode( $exercice ) . '&saved=1'
        ) );
        exit;
    }

    public static function display_cotisations() {
        $action = sanitize_key( $_GET['sel_action'] ?? '' );
        if ( $action === 'ajouter' || $action === 'modifier' ) {
            self::form_cotisation( $action );
            return;
        }

        $cfg  = self::cfg();
        $view = sanitize_key( $_GET['view'] ?? 'liste' );

        $paheko_actif = ! empty( $cfg['cotisations_paheko_actif'] )
                        && ! empty( $cfg['paheko_url'] )
                        && ! empty( $cfg['paheko_identifiant'] );

        echo '<div class="wrap"><h1>' . esc_html__( 'Cotisations', 'seliweb' ) . '</h1>';

        if ( $paheko_actif ) {
            $base = admin_url( 'admin.php?page=seliweb_cotisations' );
            echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px;">';
            echo '<a href="' . esc_url( $base . '&view=liste' ) . '" class="nav-tab ' . ( $view !== 'sync' ? 'nav-tab-active' : '' ) . '">'
                . esc_html__( 'Cotisations', 'seliweb' ) . '</a>';
            echo '<a href="' . esc_url( $base . '&view=sync' ) . '" class="nav-tab ' . ( $view === 'sync' ? 'nav-tab-active' : '' ) . '">'
                . esc_html__( 'Synchronisation Paheko', 'seliweb' ) . '</a>';
            echo '</nav>';
        }

        if ( $view === 'sync' && $paheko_actif ) {
            self::display_sync( $cfg );
            echo '</div>';
            return;
        }

        global $wpdb;
        $cfg = self::cfg();

        $groupes_ids = array_values( array_filter( array_map( 'intval', explode( ',', $cfg['cotisations_groupes'] ?? '' ) ) ) );

        $tg  = $wpdb->prefix . 'seliweb_groupes';
        $tm  = $wpdb->prefix . 'seliweb_membres';
        $ti  = $wpdb->prefix . 'seliweb_inscriptions';
        $tc  = $wpdb->prefix . 'seliweb_cotisations';
        $tr  = $wpdb->prefix . 'seliweb_cotisations_reglements';
        $tmo = $wpdb->prefix . 'seliweb_monnaies';
        $te  = $wpdb->prefix . 'seliweb_exercices';

        // Exercices depuis la table — défaut = celui dont la date du jour est dans la plage
        $exercices_list = $wpdb->get_results( "SELECT * FROM $te ORDER BY date_debut DESC, id DESC" );
        $today           = current_time( 'Y-m-d' );
        $exercice_defaut = null;
        foreach ( $exercices_list as $ex ) {
            if ( $ex->date_debut && $ex->date_fin
                 && $today >= $ex->date_debut && $today <= $ex->date_fin ) {
                $exercice_defaut = $ex->libelle;
                break;
            }
        }
        if ( ! $exercice_defaut && $exercices_list ) {
            $exercice_defaut = $exercices_list[0]->libelle;
        }
        if ( ! $exercice_defaut ) {
            $exercice_defaut = date( 'Y' );
        }

        // Groupe sélectionné (facultatif — tous les groupes configurés par défaut)
        $groupe_id = intval( $_GET['groupe_id'] ?? 0 );
        if ( ! $groupe_id && count( $groupes_ids ) === 1 ) {
            $groupe_id = $groupes_ids[0];
        }

        // Exercice sélectionné (défaut = exercice couvrant la date du jour)
        $exercice = sanitize_text_field( wp_unslash( $_GET['exercice'] ?? $exercice_defaut ) );

        // Tri des colonnes
        $orderby = in_array( $_GET['orderby'] ?? '', array( 'id', 'numero', 'nom' ) )
            ? sanitize_key( $_GET['orderby'] )
            : '';
        $order   = strtoupper( sanitize_key( $_GET['order'] ?? '' ) ) === 'DESC' ? 'DESC' : 'ASC';

        // Groupe SEL (pour tri automatique par N° de membre)
        $tp_sel  = $wpdb->prefix . 'seliweb_parametres';
        $sel_gid = (int) $wpdb->get_var( "SELECT valeur FROM $tp_sel WHERE cle='sel_groupe_id' LIMIT 1" );
        $is_sel_group = ( $groupe_id > 0 && $groupe_id === $sel_gid );

        // Tri par défaut : N° (ASC) si groupe SEL sélectionné, sinon ID
        if ( ! $orderby ) {
            $orderby = $is_sel_group ? 'numero' : 'id';
        }
        if ( $orderby === 'id' ) {
            $order_sql = "m.id $order";
        } elseif ( $orderby === 'numero' ) {
            $order_sql = "m.numero_sel $order, i.nom ASC, i.prenom ASC";
        } else {
            $order_sql = "i.nom $order, i.prenom $order";
        }

        // Groupes soumis à cotisation
        $groupes = array();
        if ( $groupes_ids ) {
            $in_g    = implode( ',', $groupes_ids );
            $groupes = $wpdb->get_results( "SELECT id, nom FROM $tg WHERE id IN ($in_g) ORDER BY nom" );
        }

        // Pagination — nombre de résultats par page
        $per_page_opts = array( 50, 100, 250 );
        $per_page_raw  = isset( $_GET['per_page'] ) ? intval( $_GET['per_page'] ) : 50;
        $per_page      = in_array( $per_page_raw, $per_page_opts ) ? $per_page_raw : 50;
        $paged         = max( 1, intval( $_GET['paged'] ?? 1 ) );

        // Membres : groupe sélectionné ou tous les groupes configurés
        $membres       = array();
        $cot_par_user  = array();
        $rg_par_cot    = array();
        $total_membres = 0;
        $total_pages   = 1;

        if ( $groupes_ids ) {
            if ( $groupe_id && in_array( $groupe_id, $groupes_ids, true ) ) {
                $where_m = $wpdb->prepare( "m.groupe_id = %d", $groupe_id );
            } else {
                $in_g    = implode( ',', $groupes_ids );
                $where_m = "m.groupe_id IN ($in_g)";
            }

            $total_membres = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $tm m WHERE $where_m"
            );
            $total_pages = max( 1, (int) ceil( $total_membres / $per_page ) );
            $paged       = min( $paged, $total_pages );
            $offset      = ( $paged - 1 ) * $per_page;

            $membres = $wpdb->get_results(
                "SELECT m.id AS membre_id, m.wp_user_id, m.numero_sel,
                        u.display_name, i.nom, i.prenom
                 FROM $tm m
                 LEFT JOIN {$wpdb->users} u ON u.ID = m.wp_user_id
                 LEFT JOIN $ti i ON i.wp_user_id = m.wp_user_id
                 WHERE $where_m
                 ORDER BY $order_sql
                 LIMIT $per_page OFFSET $offset"
            );

            if ( $membres ) {
                $wp_ids = implode( ',', array_map( fn( $m ) => intval( $m->wp_user_id ), $membres ) );
                $cots   = $wpdb->get_results(
                    "SELECT * FROM $tc
                     WHERE wp_user_id IN ($wp_ids)
                       AND exercice = '" . esc_sql( $exercice ) . "'
                       AND statut   = 'paye'"
                );
                foreach ( $cots as $c ) {
                    $cot_par_user[ $c->wp_user_id ] = $c;
                }
                if ( $cots ) {
                    $cot_ids = implode( ',', array_map( fn( $c ) => intval( $c->id ), $cots ) );
                    $rgs     = $wpdb->get_results(
                        "SELECT r.*, mo.symbole, mo.nom AS monnaie_nom
                         FROM $tr r
                         LEFT JOIN $tmo mo ON mo.id = r.monnaie_id
                         WHERE r.cotisation_id IN ($cot_ids)
                         ORDER BY r.cotisation_id, r.id"
                    );
                    foreach ( $rgs as $rg ) {
                        $rg_par_cot[ $rg->cotisation_id ][] = $rg;
                    }
                }
            }
        }

        // Params de base pour la génération d'URL (filtres actifs, sans tri ni page)
        $filter_params = array( 'page' => 'seliweb_cotisations' );
        if ( $groupe_id )      $filter_params['groupe_id'] = $groupe_id;
        if ( $exercice )       $filter_params['exercice']  = $exercice;
        if ( $per_page !== 50 ) $filter_params['per_page'] = $per_page;

        // Liens de tri : reset à la page 1, préserve les filtres
        $base_sort_url = admin_url( 'admin.php?' . http_build_query( $filter_params ) );
        $sort_link = function( $col, $label ) use ( $orderby, $order, $base_sort_url ) {
            $new_order = ( $orderby === $col && $order === 'ASC' ) ? 'DESC' : 'ASC';
            $icon      = $orderby === $col ? ( $order === 'ASC' ? ' ▲' : ' ▼' ) : ' ⇅';
            return '<a href="' . esc_url( add_query_arg( array( 'orderby' => $col, 'order' => $new_order ), $base_sort_url ) ) . '"'
                . ' style="color:inherit;text-decoration:none;white-space:nowrap;">'
                . esc_html( $label ) . $icon . '</a>';
        };

        // URL de page : préserve filtres + tri + per_page, change seulement paged
        $page_base = $filter_params;
        if ( $orderby )         $page_base['orderby'] = $orderby;
        if ( $order !== 'ASC' ) $page_base['order']   = strtolower( $order );
        $page_url_fn = function( $p ) use ( $page_base ) {
            $params = $page_base;
            $params['paged'] = $p;
            return esc_url( admin_url( 'admin.php?' . http_build_query( $params ) ) );
        };

        // Résumé des filtres actifs (pour l'impression)
        $filtres_actifs = array();
        if ( $groupe_id ) {
            foreach ( $groupes as $g ) {
                if ( (int) $g->id === $groupe_id ) {
                    $filtres_actifs[] = sprintf( __( 'Groupe : %s', 'seliweb' ), $g->nom );
                    break;
                }
            }
        }
        if ( $exercice ) { $filtres_actifs[] = sprintf( __( 'Exercice : %s', 'seliweb' ), $exercice ); }
        ?>
        <button type="button" class="page-title-action seliweb-no-print" onclick="window.print()"><?php esc_html_e( 'Imprimer', 'seliweb' ); ?></button>

        <div class="seliweb-print-header">
            <h2><?php esc_html_e( 'Liste des cotisations', 'seliweb' ); ?></h2>
            <p>
                <?php echo $filtres_actifs ? esc_html( implode( ' — ', $filtres_actifs ) ) : esc_html__( 'Aucun filtre appliqué', 'seliweb' ); ?>
            </p>
        </div>

        <?php if ( isset( $_GET['saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cotisation enregistrée.', 'seliweb' ); ?></p></div>
        <?php endif; ?>

        <form method="get" class="seliweb-no-print" style="display:flex;gap:12px;align-items:center;margin:16px 0;flex-wrap:wrap;">
            <input type="hidden" name="page"     value="seliweb_cotisations">
            <input type="hidden" name="orderby"  value="<?php echo esc_attr( $orderby ); ?>">
            <input type="hidden" name="order"    value="<?php echo esc_attr( $order ); ?>">
            <input type="hidden" name="per_page" value="<?php echo intval( $per_page ); ?>">

            <?php if ( count( $groupes ) > 1 ) : ?>
                <select name="groupe_id" onchange="this.form.submit()">
                    <option value=""><?php esc_html_e( '— Tous les groupes —', 'seliweb' ); ?></option>
                    <?php foreach ( $groupes as $g ) : ?>
                        <option value="<?php echo intval( $g->id ); ?>" <?php selected( $groupe_id, $g->id ); ?>>
                            <?php echo esc_html( $g->nom ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ( count( $groupes ) === 1 ) : ?>
                <input type="hidden" name="groupe_id" value="<?php echo intval( $groupes[0]->id ); ?>">
                <strong><?php echo esc_html( $groupes[0]->nom ); ?></strong>
            <?php else : ?>
                <p class="description">
                    <?php esc_html_e( 'Aucun groupe configuré. Paramètres → Cotisations → Groupes soumis à cotisation.', 'seliweb' ); ?>
                </p>
            <?php endif; ?>

            <?php if ( $groupes && $exercices_list ) : ?>
            <select name="exercice" onchange="this.form.submit()">
                <?php foreach ( $exercices_list as $ex ) : ?>
                    <option value="<?php echo esc_attr( $ex->libelle ); ?>" <?php selected( $exercice, $ex->libelle ); ?>>
                        <?php echo esc_html( $ex->libelle ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php elseif ( $groupes ) : ?>
                <em class="description"><?php esc_html_e( 'Aucun exercice défini. Paramètres → Cotisations → Exercices.', 'seliweb' ); ?></em>
            <?php endif; ?>
        </form>

        <?php if ( ! $groupes_ids ) : ?>
            <?php // message déjà affiché dans le filtre ?>
        <?php elseif ( empty( $membres ) ) : ?>
            <p><?php esc_html_e( 'Aucun membre dans ce groupe.', 'seliweb' ); ?></p>
        <?php else :
        // Barre de pagination (closure réutilisée en haut et en bas)
        $render_pagbar = function( $top ) use (
            $paged, $total_pages, $total_membres, $per_page, $per_page_opts,
            $page_url_fn, $filter_params
        ) {
            $debut = $total_membres > 0 ? ( ( $paged - 1 ) * $per_page + 1 ) : 0;
            $fin   = min( $paged * $per_page, $total_membres );
            echo '<div class="seliweb-no-print" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin:8px 0 6px;">';

            // ---- Gauche : recherche (haut seulement) + sélecteur par page + compteur ----
            echo '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
            if ( $top ) {
                echo '<input type="text" id="cot-search"'
                    . ' placeholder="' . esc_attr__( 'Rechercher nom, prénom…', 'seliweb' ) . '"'
                    . ' autocomplete="off"'
                    . ' style="width:220px;padding:5px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;">';
                echo '<button type="button" id="cot-search-clear" title="' . esc_attr__( 'Effacer', 'seliweb' ) . '"'
                    . ' style="display:none;background:none;border:none;cursor:pointer;font-size:16px;color:#999;padding:2px 4px;line-height:1;">✕</button>';
                echo '<span id="cot-search-count" style="font-size:13px;color:#666;"></span>';
            }

            // Sélecteur par page
            echo '<span style="font-size:13px;color:#555;">' . esc_html__( 'Afficher :', 'seliweb' ) . ' ';
            $pp_base = $filter_params;
            unset( $pp_base['per_page'], $pp_base['paged'] );
            foreach ( $per_page_opts as $opt ) {
                $active = $opt === $per_page;
                $pp_base['per_page'] = $opt;
                echo '<a href="' . esc_url( admin_url( 'admin.php?' . http_build_query( $pp_base ) ) ) . '"'
                    . ' style="' . ( $active ? 'font-weight:700;text-decoration:underline;' : '' ) . '">'
                    . intval( $opt ) . '</a>';
                if ( end( $per_page_opts ) !== $opt ) echo ' | ';
            }
            echo ' ' . esc_html__( '/page', 'seliweb' ) . '</span>';

            // Compteur membres + page (toujours affiché pour clarté)
            if ( $total_membres > 0 ) {
                echo '<span style="font-size:13px;color:#555;">'
                    . sprintf( esc_html__( '%d membres', 'seliweb' ), $total_membres )
                    . ' &nbsp;&middot;&nbsp; '
                    . sprintf( esc_html__( 'page %1$d / %2$d', 'seliweb' ), $paged, $total_pages )
                    . '</span>';
            }
            echo '</div>';

            // ---- Droite : boutons navigation ----
            echo '<div style="display:flex;align-items:center;gap:6px;">';
            if ( $paged > 1 ) {
                echo '<a href="' . $page_url_fn( $paged - 1 ) . '" class="button button-secondary">&larr; ' . esc_html__( 'Précédent', 'seliweb' ) . '</a>';
            } else {
                echo '<button class="button button-secondary" disabled>&larr; ' . esc_html__( 'Précédent', 'seliweb' ) . '</button>';
            }
            if ( $paged < $total_pages ) {
                echo '<a href="' . $page_url_fn( $paged + 1 ) . '" class="button button-secondary">' . esc_html__( 'Suivant', 'seliweb' ) . ' &rarr;</a>';
            } else {
                echo '<button class="button button-secondary" disabled>' . esc_html__( 'Suivant', 'seliweb' ) . ' &rarr;</button>';
            }
            echo '</div>';
            echo '</div>';
        };
        $render_pagbar( true ); // barre du haut (avec champ de recherche)
        ?>
        <table id="cot-table" class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th style="width:50px;"><?php echo $sort_link( 'id', __( 'ID', 'seliweb' ) ); ?></th>
                <?php if ( $is_sel_group ) : ?>
                <th style="width:60px;"><?php echo $sort_link( 'numero', __( 'N°', 'seliweb' ) ); ?></th>
                <?php endif; ?>
                <th><?php echo $sort_link( 'nom', __( 'Nom Prénom', 'seliweb' ) ); ?></th>
                <th style="width:180px;"><?php esc_html_e( 'Règlement', 'seliweb' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( 'Paiement', 'seliweb' ); ?></th>
                <?php if ( $paheko_actif ) : ?>
                <th class="seliweb-no-print" style="width:130px;"><?php esc_html_e( 'Sync', 'seliweb' ); ?></th>
                <?php endif; ?>
                <th class="seliweb-no-print" style="width:100px;"><?php esc_html_e( 'Action', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $membres as $m ) :
                $nom_aff = $m->display_name ?: trim( ( $m->prenom ?? '' ) . ' ' . ( $m->nom ?? '' ) );
                $cot     = $cot_par_user[ $m->wp_user_id ] ?? null;
                $rgs     = $cot ? ( $rg_par_cot[ $cot->id ] ?? array() ) : array();
            ?>
            <tr>
                <td><?php echo esc_html( $m->membre_id ); ?></td>
                <?php if ( $is_sel_group ) : ?>
                <td><?php echo esc_html( $m->numero_sel ?: '—' ); ?></td>
                <?php endif; ?>
                <td data-search="nom"><?php echo esc_html( $nom_aff ); ?></td>
                <td>
                    <?php if ( $cot ) :
                        if ( $rgs ) :
                            foreach ( $rgs as $i => $rg ) :
                                if ( $i > 0 && $rg->coordination ) echo ' <em>' . esc_html( $rg->coordination ) . '</em> ';
                                echo esc_html( number_format( $rg->montant / 100, 2, ',', ' ' ) . ' ' . ( $rg->symbole ?: '' ) );
                            endforeach;
                        else :
                            echo esc_html( number_format( $cot->montant / 100, 2, ',', ' ' ) . ' €' );
                        endif;
                    endif; ?>
                </td>
                <td>
                    <?php if ( $cot ) : ?>
                        <span style="color:green;font-weight:600;"><?php esc_html_e( 'Payé', 'seliweb' ); ?></span>
                    <?php else : ?>
                        <span style="color:#999;"><?php esc_html_e( 'Non payé', 'seliweb' ); ?></span>
                    <?php endif; ?>
                </td>
                <?php if ( $paheko_actif ) : ?>
                <td class="seliweb-no-print">
                    <?php if ( $cot ) :
                        if ( $cot->sync_exclu ) : ?>
                            <em style="color:#999;"><?php esc_html_e( 'Aucune action', 'seliweb' ); ?></em>
                        <?php else : ?>
                            <?php if ( $cot->paheko_synced ) : ?>
                                <span style="color:#0073aa;font-weight:600;">Paheko ✓</span>
                            <?php endif; ?>
                            <?php if ( $cot->sel_synced ) : ?>
                                <span style="color:#46b450;font-weight:600;">SEL ✓</span>
                            <?php endif; ?>
                            <?php if ( ! $cot->paheko_synced && ! $cot->sel_synced ) : ?>
                                <span style="color:#dba617;"><?php esc_html_e( 'En attente', 'seliweb' ); ?></span>
                                &nbsp;<a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin.php?page=seliweb_cotisations&exclu_id=' . $cot->id
                                        . ( $groupe_id ? '&groupe_id=' . $groupe_id : '' )
                                        . '&exercice=' . urlencode( $exercice ) ),
                                    'seliweb_exclu_' . $cot->id
                                ) ); ?>" style="font-size:11px;color:#999;" title="<?php esc_attr_e( 'Marquer : aucune action requise', 'seliweb' ); ?>">
                                    <?php esc_html_e( 'Aucune action', 'seliweb' ); ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="seliweb-no-print">
                    <?php if ( $cot ) : ?>
                        <a href="<?php echo esc_url( admin_url(
                            'admin.php?page=seliweb_cotisations&sel_action=modifier&id=' . $cot->id
                            . ( $groupe_id ? '&groupe_id=' . $groupe_id : '' )
                            . '&exercice=' . urlencode( $exercice )
                        ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( admin_url(
                            'admin.php?page=seliweb_cotisations&sel_action=ajouter&membre_id=' . $m->wp_user_id
                            . ( $groupe_id ? '&groupe_id=' . $groupe_id : '' )
                            . '&exercice=' . urlencode( $exercice )
                        ) ); ?>"><?php esc_html_e( 'Ajouter', 'seliweb' ); ?></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p id="cot-no-results" style="display:none;color:#666;font-style:italic;margin-top:4px;">
            <?php esc_html_e( 'Aucun résultat pour cette recherche sur cette page.', 'seliweb' ); ?>
        </p>
        <?php $render_pagbar( false ); // barre du bas (sans champ de recherche) ?>
        <script>
        (function() {
            function normalise(s) {
                return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
            }
            var inp   = document.getElementById('cot-search');
            var clear = document.getElementById('cot-search-clear');
            var count = document.getElementById('cot-search-count');
            var noRes = document.getElementById('cot-no-results');
            var table = document.getElementById('cot-table');
            var pageTotal = table ? table.querySelectorAll('tbody tr').length : 0;

            function doSearch() {
                if (!table || !inp) return;
                var raw   = inp.value;
                var terms = normalise(raw).split(/\s+/).filter(function(t){ return t.length > 0; });
                var rows  = table.querySelectorAll('tbody tr');
                var visible = 0;
                rows.forEach(function(row) {
                    var cell = row.querySelector('[data-search="nom"]') || row.cells[1];
                    var text = normalise(cell ? cell.textContent : '');
                    var match = !terms.length || terms.every(function(t){ return text.indexOf(t) !== -1; });
                    row.style.display = match ? '' : 'none';
                    if (match) visible++;
                });
                if (clear) clear.style.display = raw ? '' : 'none';
                if (noRes) noRes.style.display  = (visible === 0 && terms.length) ? '' : 'none';
                if (count) count.textContent    = terms.length ? visible + ' / ' + pageTotal : '';
            }

            if (inp) {
                inp.addEventListener('input', doSearch);
                if (clear) clear.addEventListener('click', function() {
                    inp.value = ''; doSearch(); inp.focus();
                });
            }
        })();
        </script>
        <?php endif; ?>
        </div>
        <?php
    }

    // ================================================================
    // VUE SYNCHRONISATION PAHEKO
    // ================================================================
    private static function display_sync( $cfg ) {
        global $wpdb;
        $tc  = $wpdb->prefix . 'seliweb_cotisations';
        $tr  = $wpdb->prefix . 'seliweb_cotisations_reglements';
        $tmo = $wpdb->prefix . 'seliweb_monnaies';
        $te  = $wpdb->prefix . 'seliweb_exercices';
        $ti  = $wpdb->prefix . 'seliweb_inscriptions';
        $tm  = $wpdb->prefix . 'seliweb_membres';

        // Exercices Seliweb pour le filtre
        $exercices_list  = $wpdb->get_results( "SELECT * FROM $te ORDER BY date_debut DESC, id DESC" );
        $today           = current_time( 'Y-m-d' );
        $exercice_defaut = null;
        foreach ( $exercices_list as $ex ) {
            if ( $ex->date_debut && $ex->date_fin && $today >= $ex->date_debut && $today <= $ex->date_fin ) {
                $exercice_defaut = $ex->libelle;
                break;
            }
        }
        if ( ! $exercice_defaut && $exercices_list ) {
            $exercice_defaut = $exercices_list[0]->libelle;
        }
        $exercice_filtre = sanitize_text_field( wp_unslash( $_GET['exercice'] ?? $exercice_defaut ?? '' ) );

        // Données Paheko (avec cache transient 10 min)
        $paheko_years    = get_transient( 'seliweb_paheko_years' );
        $paheko_services = get_transient( 'seliweb_paheko_services' );
        if ( false === $paheko_years ) {
            $paheko_years = self::paheko_get_years();
            set_transient( 'seliweb_paheko_years', $paheko_years, 600 );
        }
        if ( false === $paheko_services ) {
            $paheko_services = self::paheko_get_services();
            set_transient( 'seliweb_paheko_services', $paheko_services, 600 );
        }

        // Cotisations non synchronisées ayant au moins un règlement en monnaie légale
        $where_ex = $exercice_filtre
            ? $wpdb->prepare( "AND c.exercice = %s", $exercice_filtre )
            : '';

        $cotisations = $wpdb->get_results(
            "SELECT c.*,
                    COALESCE( i.nom, u.display_name ) AS nom_membre,
                    i.prenom,
                    m.numero_sel,
                    EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}seliweb_cotisations_reglements r
                        JOIN {$wpdb->prefix}seliweb_monnaies mo ON mo.id = r.monnaie_id
                        WHERE r.cotisation_id = c.id AND mo.est_legale = 1
                    ) AS a_reglement_legal,
                    EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}seliweb_cotisations_reglements r
                        JOIN {$wpdb->prefix}seliweb_monnaies mo ON mo.id = r.monnaie_id
                        WHERE r.cotisation_id = c.id AND mo.est_defaut = 1
                    ) AS a_reglement_sel
             FROM $tc c
             LEFT JOIN {$wpdb->users} u ON u.ID = c.wp_user_id
             LEFT JOIN $ti i ON i.wp_user_id = c.wp_user_id
             LEFT JOIN $tm m ON m.wp_user_id = c.wp_user_id
             WHERE c.statut = 'paye'
               AND c.sync_exclu = 0
               AND ( c.paheko_synced = 0 OR c.sel_synced = 0 )
               AND (
                   ( c.paheko_synced = 0 AND EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}seliweb_cotisations_reglements r
                       JOIN {$wpdb->prefix}seliweb_monnaies mo ON mo.id = r.monnaie_id
                       WHERE r.cotisation_id = c.id AND mo.est_legale = 1
                   ) )
                   OR
                   ( c.sel_synced = 0 AND EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}seliweb_cotisations_reglements r
                       JOIN {$wpdb->prefix}seliweb_monnaies mo ON mo.id = r.monnaie_id
                       WHERE r.cotisation_id = c.id AND mo.est_defaut = 1
                   ) )
               )
             $where_ex
             ORDER BY c.date_paiement DESC, c.id DESC"
        );

        // Règlements en batch
        $rg_par_cot = array();
        if ( $cotisations ) {
            $ids = implode( ',', array_map( fn( $c ) => intval( $c->id ), $cotisations ) );
            $rgs = $wpdb->get_results(
                "SELECT r.*, mo.symbole FROM $tr r
                 LEFT JOIN $tmo mo ON mo.id = r.monnaie_id
                 WHERE r.cotisation_id IN ($ids) ORDER BY r.cotisation_id, r.id"
            );
            foreach ( $rgs as $rg ) {
                $rg_par_cot[ $rg->cotisation_id ][] = $rg;
            }
        }

        // Config Paheko par défaut (pré-sélection)
        $default_year = intval( $cfg['paheko_id_year'] ?? 0 );
        $default_fee  = intval( $cfg['paheko_id_fee']  ?? 0 );

        if ( isset( $_GET['refreshed'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Données Paheko actualisées (exercices et tarifs rechargés).', 'seliweb' )
                . '</p></div>';
        }
        if ( isset( $_GET['synced'] ) ) {
            $nb = intval( $_GET['synced'] );
            echo '<div class="notice notice-success is-dismissible"><p>'
                . sprintf( esc_html__( '%d cotisation(s) synchronisée(s) avec Paheko.', 'seliweb' ), $nb )
                . '</p></div>';
        }
        if ( isset( $_GET['sync_errors'] ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html__( 'Certaines synchronisations ont échoué (membre introuvable ou non créé dans Paheko).', 'seliweb' )
                . '</p></div>';
        }
        ?>

        <div style="display:flex;align-items:baseline;gap:16px;flex-wrap:wrap;margin-bottom:12px;">
            <h2 style="margin:0;"><?php esc_html_e( 'Tableau des cotisations à synchroniser', 'seliweb' ); ?></h2>
            <a href="<?php echo esc_url( wp_nonce_url(
                admin_url( 'admin.php?page=seliweb_cotisations&view=sync&refresh_paheko=1'
                    . ( $exercice_filtre ? '&exercice=' . urlencode( $exercice_filtre ) : '' ) ),
                'seliweb_refresh_paheko'
            ) ); ?>" class="button" title="<?php esc_attr_e( 'Recharger les exercices et tarifs depuis Paheko', 'seliweb' ); ?>">
                ↺ <?php esc_html_e( 'Actualiser depuis Paheko', 'seliweb' ); ?>
            </a>
        </div>

        <?php if ( $exercices_list ) : ?>
        <form method="get" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="seliweb_cotisations">
            <input type="hidden" name="view" value="sync">
            <label><strong><?php esc_html_e( 'Exercice Seliweb :', 'seliweb' ); ?></strong></label>
            <select name="exercice" onchange="this.form.submit()">
                <option value=""><?php esc_html_e( '— Tous —', 'seliweb' ); ?></option>
                <?php foreach ( $exercices_list as $ex ) : ?>
                    <option value="<?php echo esc_attr( $ex->libelle ); ?>" <?php selected( $exercice_filtre, $ex->libelle ); ?>>
                        <?php echo esc_html( $ex->libelle ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>

        <?php if ( ! $cotisations ) : ?>
            <p><?php esc_html_e( 'Aucune cotisation en attente de synchronisation.', 'seliweb' ); ?></p>
        <?php else : ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_cotisations&view=sync' . ( $exercice_filtre ? '&exercice=' . urlencode( $exercice_filtre ) : '' ) ) ); ?>">
            <?php wp_nonce_field( 'seliweb_sync_paheko', 'seliweb_sync_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="sync_paheko">

            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th style="width:30px;"><input type="checkbox" id="check_all" title="<?php esc_attr_e( 'Tout sélectionner', 'seliweb' ); ?>"></th>
                    <th style="width:60px;"><?php esc_html_e( 'N°', 'seliweb' ); ?></th>
                    <th><?php esc_html_e( 'Membre', 'seliweb' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Exercice Seliweb', 'seliweb' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Montant', 'seliweb' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Date', 'seliweb' ); ?></th>
                    <th style="width:110px;"><?php esc_html_e( 'À faire', 'seliweb' ); ?></th>
                    <th style="width:120px;"><?php esc_html_e( 'Exercice Paheko', 'seliweb' ); ?></th>
                    <th><?php esc_html_e( 'Tarif Paheko', 'seliweb' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $cotisations as $cot ) :
                    $num     = $cot->numero_sel ?: $cot->wp_user_id;
                    $nom_aff = trim( ( $cot->prenom ?? '' ) . ' ' . ( $cot->nom_membre ?? '' ) )
                               ?: $cot->helloasso_payer_nom
                               ?: '—';
                    $rgs     = $rg_par_cot[ $cot->id ] ?? array();
                    $montant_aff = $rgs
                        ? implode( ' + ', array_map( fn( $r ) => number_format( $r->montant / 100, 2, ',', ' ' ) . ' ' . ( $r->symbole ?: '€' ), $rgs ) )
                        : number_format( $cot->montant / 100, 2, ',', ' ' ) . ' €';
                ?>
                <tr>
                    <td><input type="checkbox" name="cot_ids[]" value="<?php echo intval( $cot->id ); ?>"></td>
                    <td><?php echo esc_html( $num ); ?></td>
                    <td><?php echo esc_html( $nom_aff ); ?></td>
                    <td><?php echo esc_html( $cot->exercice ?: '—' ); ?></td>
                    <td><?php echo esc_html( $montant_aff ); ?></td>
                    <td><?php echo esc_html( $cot->date_paiement ? date_i18n( 'd/m/Y', strtotime( $cot->date_paiement ) ) : '—' ); ?></td>
                    <td style="font-size:12px;line-height:1.6;">
                        <?php if ( $cot->a_reglement_legal && ! $cot->paheko_synced ) : ?>
                            <span style="color:#0073aa;">↗ Paheko</span><br>
                        <?php endif; ?>
                        <?php if ( $cot->a_reglement_sel && ! $cot->sel_synced ) : ?>
                            <span style="color:#46b450;">↗ Transaction SEL</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $paheko_years && $cot->a_reglement_legal && ! $cot->paheko_synced ) : ?>
                        <select name="paheko_year[<?php echo intval( $cot->id ); ?>]" style="max-width:180px;">
                            <option value=""><?php esc_html_e( '— Choisir —', 'seliweb' ); ?></option>
                            <?php foreach ( $paheko_years as $yr ) : ?>
                                <option value="<?php echo intval( $yr['id'] ); ?>" <?php selected( $default_year, $yr['id'] ); ?>>
                                    <?php echo esc_html( $yr['label'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else : ?>
                            <em class="description"><?php esc_html_e( 'Non disponible', 'seliweb' ); ?></em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $paheko_services && $cot->a_reglement_legal && ! $cot->paheko_synced ) : ?>
                        <select name="paheko_fee[<?php echo intval( $cot->id ); ?>]"
                                data-svc-target="paheko_svc_<?php echo intval( $cot->id ); ?>"
                                style="max-width:220px;">
                            <option value="" data-service=""><?php esc_html_e( '— Choisir —', 'seliweb' ); ?></option>
                            <?php
                            $cur_svc = null;
                            foreach ( $paheko_services as $row ) :
                                if ( $cur_svc !== $row['service_label'] ) :
                                    if ( $cur_svc !== null ) echo '</optgroup>';
                                    echo '<optgroup label="' . esc_attr( $row['service_label'] ) . '">';
                                    $cur_svc = $row['service_label'];
                                endif;
                            ?>
                                <option value="<?php echo intval( $row['fee_id'] ); ?>"
                                        data-service="<?php echo intval( $row['service_id'] ); ?>"
                                        <?php selected( $default_fee, $row['fee_id'] ); ?>>
                                    <?php echo esc_html( $row['fee_label'] . ' — ' . number_format( $row['amount'] / 100, 2, ',', ' ' ) . ' €' ); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ( $cur_svc !== null ) echo '</optgroup>'; ?>
                        </select>
                        <input type="hidden" name="paheko_svc[<?php echo intval( $cot->id ); ?>]"
                               id="paheko_svc_<?php echo intval( $cot->id ); ?>"
                               value="<?php echo esc_attr( self::find_service_for_fee( $paheko_services, $default_fee ) ); ?>">
                        <?php else : ?>
                            <em class="description"><?php esc_html_e( 'Non disponible', 'seliweb' ); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:16px;">
                <?php submit_button( __( 'Synchroniser la sélection', 'seliweb' ), 'primary', 'submit', false ); ?>
            </p>
        </form>

        <script>
        (function(){
            document.getElementById('check_all').addEventListener('change', function(){
                document.querySelectorAll('input[name="cot_ids[]"]').forEach(function(cb){ cb.checked = this.checked; }, this);
            });
            document.querySelectorAll('select[data-svc-target]').forEach(function(sel){
                sel.addEventListener('change', function(){
                    var opt = this.options[this.selectedIndex];
                    var tgt = document.getElementById(this.dataset.svcTarget);
                    if (tgt) tgt.value = opt ? (opt.getAttribute('data-service') || '') : '';
                });
            });
        })();
        </script>

        <?php endif; ?>
        <?php
    }

    private static function create_transaction_sel( $cotisation_id, $wp_user_id, $date, $libelle ) {
        global $wpdb;
        $tc = $wpdb->prefix . 'seliweb_cotisations';
        $tr = $wpdb->prefix . 'seliweb_cotisations_reglements';
        $tm = $wpdb->prefix . 'seliweb_membres';
        $tt = $wpdb->prefix . 'seliweb_transactions';
        $te = $wpdb->prefix . 'seliweb_ecritures';

        // Règlements en monnaie SEL (est_defaut = 1)
        $reglements_sel = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.montant FROM $tr r
             LEFT JOIN {$wpdb->prefix}seliweb_monnaies mo ON mo.id = r.monnaie_id
             WHERE r.cotisation_id = %d AND mo.est_defaut = 1
             ORDER BY r.id",
            $cotisation_id
        ) );
        if ( ! $reglements_sel ) return false;

        // ID du membre payeur dans seliweb_membres
        $membre_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $tm WHERE wp_user_id = %d LIMIT 1", $wp_user_id
        ) );
        if ( ! $membre_id ) return false;

        // ID du compte SEL n°1
        $sel_id = $wpdb->get_var( "SELECT id FROM $tm WHERE numero_sel = 1 LIMIT 1" );
        if ( ! $sel_id ) return false;

        $total_sel = array_sum( array_map( fn( $r ) => intval( $r->montant ), $reglements_sel ) );

        // Créer la transaction SEL
        $wpdb->insert( $tt, array(
            'date'       => $date,
            'libelle'    => $libelle,
            'montant'    => $total_sel,
            'created_at' => current_time( 'mysql' ),
        ) );
        $tx_id = (int) $wpdb->insert_id;
        if ( ! $tx_id ) return false;

        // Débit membre (paye sa cotisation en monnaie SEL)
        $wpdb->insert( $te, array( 'transaction_id' => $tx_id, 'membre_id' => $membre_id, 'type' => 'debit' ) );
        // Crédit compte SEL n°1
        $wpdb->insert( $te, array( 'transaction_id' => $tx_id, 'membre_id' => $sel_id, 'type' => 'credit' ) );

        $wpdb->update( $tc, array( 'sel_synced' => 1 ), array( 'id' => $cotisation_id ) );
        return true;
    }

    private static function find_service_for_fee( $services, $fee_id ) {
        foreach ( $services as $row ) {
            if ( intval( $row['fee_id'] ) === intval( $fee_id ) ) {
                return intval( $row['service_id'] );
            }
        }
        return '';
    }

    private static function process_sync() {
        global $wpdb;
        $tc  = $wpdb->prefix . 'seliweb_cotisations';
        $ti  = $wpdb->prefix . 'seliweb_inscriptions';
        $cfg = self::cfg();

        $cot_ids   = array_map( 'intval', (array) ( $_POST['cot_ids'] ?? array() ) );
        $years     = $_POST['paheko_year'] ?? array();
        $fees      = $_POST['paheko_fee']  ?? array();
        $services  = $_POST['paheko_svc']  ?? array();

        $exercice_filtre = sanitize_text_field( wp_unslash( $_GET['exercice'] ?? '' ) );

        if ( ! $cot_ids ) {
            wp_safe_redirect( admin_url( 'admin.php?page=seliweb_cotisations&view=sync'
                . ( $exercice_filtre ? '&exercice=' . urlencode( $exercice_filtre ) : '' ) ) );
            exit;
        }

        $ok     = 0;
        $errors = 0;

        foreach ( $cot_ids as $cot_id ) {
            $cot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tc WHERE id = %d", $cot_id ) );
            if ( ! $cot ) continue;

            $id_year    = intval( $years[ $cot_id ]    ?? 0 );
            $id_fee     = intval( $fees[ $cot_id ]     ?? 0 );
            $id_service = intval( $services[ $cot_id ] ?? 0 );

            // Récupérer email et nom du membre
            $email = $cot->helloasso_payer_email ?: '';
            $nom   = $cot->helloasso_payer_nom   ?: '';

            if ( ! $email && $cot->wp_user_id ) {
                $user  = get_userdata( $cot->wp_user_id );
                $email = $user ? $user->user_email : '';
                $insc  = $wpdb->get_row( $wpdb->prepare(
                    "SELECT nom, prenom FROM $ti WHERE wp_user_id = %d", $cot->wp_user_id
                ) );
                $nom = $insc ? trim( $insc->prenom . ' ' . $insc->nom ) : ( $user ? $user->display_name : '' );
            }

            $date    = $cot->date_paiement ?: current_time( 'Y-m-d' );
            $libelle = sprintf( __( 'Cotisation %s - %s', 'seliweb' ), $cot->exercice ?: '', $nom );

            // Sync Paheko (règlements en monnaie légale)
            $paheko_ok = self::sync_to_paheko( $cot_id, $email, $nom, $date, $id_year, $id_fee, $id_service );

            // Transaction SEL (règlements en monnaie SEL)
            $sel_ok = self::create_transaction_sel( $cot_id, $cot->wp_user_id, $date, $libelle );

            // On compte comme succès si au moins une des deux a réussi
            if ( $paheko_ok || $sel_ok ) {
                $ok++;
            } else {
                $errors++;
            }
        }

        $redirect = admin_url( 'admin.php?page=seliweb_cotisations&view=sync'
            . ( $exercice_filtre ? '&exercice=' . urlencode( $exercice_filtre ) : '' )
            . ( $ok     ? '&synced='      . $ok     : '' )
            . ( $errors ? '&sync_errors=' . $errors  : '' ) );

        wp_safe_redirect( $redirect );
        exit;
    }

    private static function form_cotisation( $action ) {
        global $wpdb;
        $tm  = $wpdb->prefix . 'seliweb_membres';
        $ti  = $wpdb->prefix . 'seliweb_inscriptions';
        $tc  = $wpdb->prefix . 'seliweb_cotisations';
        $tr  = $wpdb->prefix . 'seliweb_cotisations_reglements';
        $tmo = $wpdb->prefix . 'seliweb_monnaies';
        $te  = $wpdb->prefix . 'seliweb_exercices';

        $exercices_list  = $wpdb->get_results( "SELECT * FROM $te ORDER BY date_debut DESC, id DESC" );
        $today           = current_time( 'Y-m-d' );
        $exercice_defaut = null;
        foreach ( $exercices_list as $ex ) {
            if ( $ex->date_debut && $ex->date_fin
                 && $today >= $ex->date_debut && $today <= $ex->date_fin ) {
                $exercice_defaut = $ex->libelle;
                break;
            }
        }
        if ( ! $exercice_defaut && $exercices_list ) {
            $exercice_defaut = $exercices_list[0]->libelle;
        }
        if ( ! $exercice_defaut ) {
            $exercice_defaut = date( 'Y' );
        }

        $cotisation_id = intval( $_GET['id']         ?? 0 );
        $wp_user_id    = intval( $_GET['membre_id']  ?? 0 );
        $groupe_id     = intval( $_GET['groupe_id']  ?? 0 );
        $exercice      = sanitize_text_field( wp_unslash( $_GET['exercice'] ?? $exercice_defaut ) );

        $reglements    = array();
        $libelle       = '';
        $date_paiement = current_time( 'Y-m-d' );

        if ( $action === 'modifier' && $cotisation_id ) {
            $cot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tc WHERE id=%d", $cotisation_id ) );
            if ( $cot ) {
                $wp_user_id    = $cot->wp_user_id;
                $exercice      = $cot->exercice ?: $exercice;
                $libelle       = $cot->libelle ?: '';
                $date_paiement = $cot->date_paiement;
                $reglements    = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM $tr WHERE cotisation_id=%d ORDER BY id", $cotisation_id
                ) );
            }
        }

        $membre = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.id AS membre_id, m.wp_user_id, m.numero_sel,
                    u.display_name, i.nom, i.prenom
             FROM $tm m
             LEFT JOIN {$wpdb->users} u ON u.ID = m.wp_user_id
             LEFT JOIN $ti i ON i.wp_user_id = m.wp_user_id
             WHERE m.wp_user_id = %d", $wp_user_id
        ) );
        if ( ! $membre ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Membre introuvable.', 'seliweb' ) . '</p></div>';
            return;
        }

        $monnaies       = $wpdb->get_results( "SELECT id, nom, symbole FROM $tmo ORDER BY est_defaut DESC, nom" );
        $monnaie_defaut = $wpdb->get_var( "SELECT id FROM $tmo WHERE est_defaut=1 LIMIT 1" ) ?: ( $monnaies[0]->id ?? 0 );

        $num     = $membre->numero_sel ?: $membre->membre_id;
        $nom_aff = $membre->display_name ?: trim( ( $membre->prenom ?? '' ) . ' ' . ( $membre->nom ?? '' ) );

        $r1       = $reglements[0] ?? null;
        $r2       = $reglements[1] ?? null;
        $back_url = admin_url( 'admin.php?page=seliweb_cotisations&groupe_id=' . $groupe_id . '&exercice=' . urlencode( $exercice ) );
        $titre    = $action === 'ajouter' ? __( 'Ajouter une cotisation', 'seliweb' ) : __( 'Modifier la cotisation', 'seliweb' );
        ?>
        <div class="wrap">
        <h1><?php echo esc_html( $titre ); ?></h1>
        <p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Retour à la liste', 'seliweb' ); ?></a></p>

        <?php if ( isset( $_GET['err_date'] ) ) :
            $ex_err = $exercice ? $wpdb->get_row( $wpdb->prepare(
                "SELECT date_debut, date_fin FROM $te WHERE libelle=%s LIMIT 1", $exercice
            ) ) : null;
            $date_err_msg = ( $ex_err && $ex_err->date_debut && $ex_err->date_fin )
                ? sprintf(
                    __( 'La date de cotisation doit être comprise entre le %1$s et le %2$s (exercice « %3$s »).', 'seliweb' ),
                    date_i18n( 'd/m/Y', strtotime( $ex_err->date_debut ) ),
                    date_i18n( 'd/m/Y', strtotime( $ex_err->date_fin ) ),
                    $exercice
                )
                : __( 'La date de cotisation ne correspond pas à la plage de l\'exercice sélectionné.', 'seliweb' );
        ?>
        <div class="notice notice-error"><p><?php echo esc_html( $date_err_msg ); ?></p></div>
        <?php endif; ?>

        <form method="post" style="max-width:620px;margin-top:20px;">
            <?php wp_nonce_field( 'seliweb_cotisation_save', 'seliweb_cot_nonce' ); ?>
            <input type="hidden" name="seliweb_action"  value="save_cotisation">
            <input type="hidden" name="cotisation_id"   value="<?php echo intval( $cotisation_id ); ?>">
            <input type="hidden" name="wp_user_id"      value="<?php echo intval( $wp_user_id ); ?>">
            <input type="hidden" name="groupe_id"       value="<?php echo intval( $groupe_id ); ?>">

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Membre', 'seliweb' ); ?></th>
                    <td><strong><?php echo esc_html( 'N°' . $num . ' — ' . $nom_aff ); ?></strong></td>
                </tr>
                <tr>
                    <th><label for="cot_exercice"><?php esc_html_e( 'Exercice', 'seliweb' ); ?></label></th>
                    <td>
                        <?php if ( $exercices_list ) : ?>
                        <select id="cot_exercice" name="exercice">
                            <?php foreach ( $exercices_list as $ex ) : ?>
                                <option value="<?php echo esc_attr( $ex->libelle ); ?>"
                                        data-debut="<?php echo esc_attr( $ex->date_debut ?: '' ); ?>"
                                        data-fin="<?php echo esc_attr( $ex->date_fin ?: '' ); ?>"
                                        <?php selected( $exercice, $ex->libelle ); ?>>
                                    <?php echo esc_html( $ex->libelle . ( $ex->est_actif ? ' (' . __( 'en cours', 'seliweb' ) . ')' : '' ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else : ?>
                        <input type="text" id="cot_exercice" name="exercice" class="small-text"
                               value="<?php echo esc_attr( $exercice ); ?>" maxlength="20">
                        <p class="description"><?php esc_html_e( 'Aucun exercice défini. Créez-en un dans Paramètres → Cotisations.', 'seliweb' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="cot_libelle"><?php esc_html_e( 'Libellé', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="text" id="cot_libelle" name="libelle" class="regular-text"
                               value="<?php echo esc_attr( $libelle ); ?>"
                               placeholder="<?php esc_attr_e( 'Cotisation 2026', 'seliweb' ); ?>">
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Règlement 1', 'seliweb' ); ?></th>
                    <td><?php self::render_reglement_row( 1, $r1, $monnaies, $monnaie_defaut ); ?></td>
                </tr>
                <tr>
                    <th id="label_r2"<?php echo $r2 ? '' : ' style="display:none;"'; ?>>
                        <?php esc_html_e( 'Règlement 2', 'seliweb' ); ?>
                    </th>
                    <td>
                        <div id="section_r2"<?php echo $r2 ? '' : ' style="display:none;"'; ?>>
                            <?php self::render_reglement_row( 2, $r2, $monnaies, $monnaie_defaut, true ); ?>
                        </div>
                        <a href="#" id="btn_add_r2"<?php echo $r2 ? ' style="display:none;"' : ''; ?>
                           onclick="document.getElementById('section_r2').style.display='';
                                    document.getElementById('label_r2').style.display='';
                                    this.style.display='none';return false;"
                           style="font-size:.9em;">
                            + <?php esc_html_e( 'Ajouter un 2ème règlement (autre monnaie)', 'seliweb' ); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th><label for="cot_date"><?php esc_html_e( 'Date', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="date" id="cot_date" name="date_paiement" required
                               value="<?php echo esc_attr( $date_paiement ); ?>">
                        <div id="cot_date_err" style="display:none;margin-top:4px;color:#b32d2e;font-size:13px;font-weight:600;"></div>
                        <p class="description" id="cot_date_hint" style="margin-top:4px;"></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( $action === 'ajouter' ? __( 'Enregistrer', 'seliweb' ) : __( 'Mettre à jour', 'seliweb' ) ); ?>
        </form>

        <script>
        (function () {
            var sel  = document.getElementById('cot_exercice');
            var dt   = document.getElementById('cot_date');
            var err  = document.getElementById('cot_date_err');
            var hint = document.getElementById('cot_date_hint');
            var form = dt ? dt.closest('form') : null;
            var btn  = form ? form.querySelector('input[type="submit"], button[type="submit"]') : null;

            function fmt(s) {
                if (!s) return '';
                var p = s.split('-');
                return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : s;
            }
            function exDates() {
                var opt = sel && sel.tagName === 'SELECT' ? sel.options[sel.selectedIndex] : null;
                return opt ? { debut: opt.getAttribute('data-debut') || '', fin: opt.getAttribute('data-fin') || '' } : null;
            }
            function check() {
                var ex = exDates();
                if (!ex || !ex.debut || !ex.fin) {
                    if (err)  err.style.display = 'none';
                    if (hint) hint.textContent  = '';
                    if (btn)  btn.disabled = false;
                    return;
                }
                var d  = dt ? dt.value : '';
                var ok = !d || (d >= ex.debut && d <= ex.fin);
                if (hint) hint.textContent = '<?php echo esc_js( __( 'Plage autorisée', 'seliweb' ) ); ?> : ' + fmt(ex.debut) + ' – ' + fmt(ex.fin);
                if (err) {
                    err.style.display = ok ? 'none' : '';
                    if (!ok) err.textContent = '<?php echo esc_js( __( 'La date doit être comprise entre le', 'seliweb' ) ); ?> ' + fmt(ex.debut) + ' <?php echo esc_js( __( 'et le', 'seliweb' ) ); ?> ' + fmt(ex.fin) + '.';
                }
                if (btn) btn.disabled = !ok;
            }
            function sync() {
                var ex = exDates();
                if (dt && ex) { dt.min = ex.debut; dt.max = ex.fin; }
                check();
            }
            if (sel) sel.addEventListener('change', sync);
            if (dt)  dt.addEventListener('change', check);
            sync();
        })();
        </script>
        </div>
        <?php
    }

    private static function render_reglement_row( $num, $r, $monnaies, $monnaie_defaut, $with_coordination = false ) {
        $montant  = $r ? number_format( $r->montant / 100, 2, '.', '' ) : '';
        $monnaie  = $r ? $r->monnaie_id   : $monnaie_defaut;
        $mode     = $r ? $r->mode_paiement : 'especes';
        $coord    = $r ? ( $r->coordination ?? 'ET' ) : 'ET';

        $modes = array(
            'especes'  => __( 'Espèces',  'seliweb' ),
            'cheque'   => __( 'Chèque',   'seliweb' ),
            'virement' => __( 'Virement', 'seliweb' ),
        );
        ?>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <?php if ( $with_coordination ) : ?>
            <select name="reglements[<?php echo $num; ?>][coordination]" style="width:60px;">
                <option value="ET" <?php selected( $coord, 'ET' ); ?>>ET</option>
                <option value="OU" <?php selected( $coord, 'OU' ); ?>>OU</option>
            </select>
            <?php endif; ?>
            <input type="number" name="reglements[<?php echo $num; ?>][montant]"
                   value="<?php echo esc_attr( $montant ); ?>"
                   step="0.01" min="0" placeholder="0,00" style="width:90px;">
            <select name="reglements[<?php echo $num; ?>][monnaie_id]">
                <?php foreach ( $monnaies as $mo ) : ?>
                    <option value="<?php echo intval( $mo->id ); ?>" <?php selected( $monnaie, $mo->id ); ?>>
                        <?php echo esc_html( $mo->nom . ( $mo->symbole ? ' (' . $mo->symbole . ')' : '' ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="reglements[<?php echo $num; ?>][mode_paiement]">
                <?php foreach ( $modes as $val => $label ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $mode, $val ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    // ================================================================
    // ADMIN — onglet Cotisations (paramètres)
    // ================================================================
    public static function tab_cotisations() {
        $subtab = sanitize_key( $_GET['subtab'] ?? 'activation' );
        $base   = admin_url( 'admin.php?page=seliweb_parametres&tab=cotisations' );
        ?>
        <nav class="nav-tab-wrapper" style="margin-bottom:24px;">
            <a href="<?php echo esc_url( $base . '&subtab=activation' ); ?>"
               class="nav-tab <?php echo $subtab === 'activation' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Activation', 'seliweb' ); ?>
            </a>
            <a href="<?php echo esc_url( $base . '&subtab=exercices' ); ?>"
               class="nav-tab <?php echo $subtab === 'exercices' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Exercices', 'seliweb' ); ?>
            </a>
        </nav>
        <?php
        if ( $subtab === 'exercices' ) {
            self::subtab_exercices();
        } else {
            self::subtab_activation();
        }
    }

    private static function subtab_activation() {
        $cfg = self::cfg();

        $actif          = ! empty( $cfg['cotisations_actif'] );
        $groupes_coches = array_filter( array_map( 'intval', explode( ',', $cfg['cotisations_groupes'] ?? '' ) ) );

        global $wpdb;
        $tous_groupes = $wpdb->get_results( "SELECT id, nom FROM {$wpdb->prefix}seliweb_groupes ORDER BY nom" );

        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paramètres enregistrés.', 'seliweb' ) . '</p></div>';
        }
        ?>
        <form method="post">
        <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
        <input type="hidden" name="seliweb_action" value="save_cotisations">

        <h2 style="margin-top:0;"><?php esc_html_e( 'Activation', 'seliweb' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Module cotisations', 'seliweb' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="cotisations_actif" value="1" <?php checked( $actif ); ?>>
                        <?php esc_html_e( 'Activer le module cotisations', 'seliweb' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Affiche la gestion des cotisations dans le menu et le bloc cotisation dans l\'espace membre.', 'seliweb' ); ?></p>
                </td>
            </tr>
        </table>

        <h2 style="margin-top:32px;padding-top:24px;border-top:1px solid #ddd;"><?php esc_html_e( 'Groupes et paramètres', 'seliweb' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Groupes soumis à cotisation', 'seliweb' ); ?></th>
                <td>
                    <?php if ( $tous_groupes ) : ?>
                        <?php foreach ( $tous_groupes as $groupe ) : ?>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="checkbox" name="cotisations_groupes[]"
                                       value="<?php echo intval( $groupe->id ); ?>"
                                       <?php checked( in_array( intval( $groupe->id ), $groupes_coches, true ) ); ?>>
                                <?php echo esc_html( $groupe->nom ); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description" style="margin-top:6px;">
                            <?php esc_html_e( 'Les membres de ces groupes seront considérés comme soumis à cotisation.', 'seliweb' ); ?>
                        </p>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e( 'Aucun groupe défini. Créez des groupes dans l\'onglet Groupes.', 'seliweb' ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Enregistrer', 'seliweb' ) ); ?>
        </form>
        <?php
    }

    // ================================================================
    // ADMIN — onglet API (paramètres) : HelloAsso + Paheko
    // ================================================================
    public static function tab_api() {
        $cfg = self::cfg();

        $ha_actif                = ! empty( $cfg['cotisations_helloasso_actif'] );
        $helloasso_client_id     = $cfg['helloasso_client_id']     ?? '';
        $helloasso_client_secret = $cfg['helloasso_client_secret'] ?? '';
        $helloasso_campaign_url  = $cfg['helloasso_campaign_url']  ?? '';

        $paheko_actif        = ! empty( $cfg['cotisations_paheko_actif'] );
        $paheko_url          = $cfg['paheko_url']          ?? '';
        $paheko_identifiant  = $cfg['paheko_identifiant']  ?? '';
        $paheko_mot_de_passe = $cfg['paheko_mot_de_passe'] ?? '';

        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paramètres enregistrés.', 'seliweb' ) . '</p></div>';
        }
        ?>
        <form method="post">
        <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
        <input type="hidden" name="seliweb_action" value="save_api">

        <h2 style="margin-top:0;display:flex;align-items:center;gap:10px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:inherit;font-weight:inherit;cursor:pointer;">
                <input type="checkbox" name="cotisations_helloasso_actif" id="toggle_ha" value="1" <?php checked( $ha_actif ); ?>>
                <?php esc_html_e( 'Paiement en ligne — HelloAsso', 'seliweb' ); ?>
            </label>
        </h2>
        <div id="section_ha"<?php echo $ha_actif ? '' : ' style="display:none;"'; ?>>
            <p class="description" style="margin-bottom:16px;">
                <?php esc_html_e( 'Connectez votre campagne HelloAsso pour recevoir les paiements en ligne et les enregistrer automatiquement.', 'seliweb' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th><label for="helloasso_campaign_url"><?php esc_html_e( 'URL de la campagne', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="url" id="helloasso_campaign_url" name="helloasso_campaign_url" class="large-text"
                               value="<?php echo esc_attr( $helloasso_campaign_url ); ?>"
                               placeholder="https://www.helloasso.com/associations/mon-sel/adhesions/cotisation-2026">
                        <p class="description"><?php esc_html_e( 'URL complète de votre formulaire d\'adhésion HelloAsso.', 'seliweb' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="helloasso_client_id"><?php esc_html_e( 'Client ID', 'seliweb' ); ?></label></th>
                    <td><input type="text" id="helloasso_client_id" name="helloasso_client_id" class="regular-text"
                               value="<?php echo esc_attr( $helloasso_client_id ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="helloasso_client_secret"><?php esc_html_e( 'Client secret', 'seliweb' ); ?></label></th>
                    <td><input type="password" id="helloasso_client_secret" name="helloasso_client_secret" class="regular-text"
                               value="<?php echo esc_attr( $helloasso_client_secret ); ?>"></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'URL webhook', 'seliweb' ); ?></th>
                    <td>
                        <code><?php echo esc_html( add_query_arg( 'seliweb_helloasso_webhook', '1', home_url( '/' ) ) ); ?></code>
                        <p class="description"><?php esc_html_e( 'À renseigner dans votre tableau de bord HelloAsso pour recevoir les notifications de paiement.', 'seliweb' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <h2 style="margin-top:32px;padding-top:24px;border-top:1px solid #ddd;display:flex;align-items:center;gap:10px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:inherit;font-weight:inherit;cursor:pointer;">
                <input type="checkbox" name="cotisations_paheko_actif" id="toggle_paheko" value="1" <?php checked( $paheko_actif ); ?>>
                <?php esc_html_e( 'Synchronisation comptable — Paheko', 'seliweb' ); ?>
            </label>
        </h2>
        <div id="section_paheko"<?php echo $paheko_actif ? '' : ' style="display:none;"'; ?>>
            <p class="description" style="margin-bottom:16px;">
                <?php esc_html_e( 'Connectez votre instance Paheko pour synchroniser automatiquement les cotisations et les écritures comptables.', 'seliweb' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th><label for="paheko_url"><?php esc_html_e( 'URL Paheko', 'seliweb' ); ?></label></th>
                    <td><input type="url" id="paheko_url" name="paheko_url" class="regular-text"
                               value="<?php echo esc_attr( $paheko_url ); ?>"
                               placeholder="https://monsel.paheko.cloud"></td>
                </tr>
                <tr>
                    <th><label for="paheko_identifiant"><?php esc_html_e( 'Identifiant API', 'seliweb' ); ?></label></th>
                    <td><input type="text" id="paheko_identifiant" name="paheko_identifiant" class="regular-text"
                               value="<?php echo esc_attr( $paheko_identifiant ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="paheko_mot_de_passe"><?php esc_html_e( 'Mot de passe API', 'seliweb' ); ?></label></th>
                    <td><input type="password" id="paheko_mot_de_passe" name="paheko_mot_de_passe" class="regular-text"
                               value="<?php echo esc_attr( $paheko_mot_de_passe ); ?>"></td>
                </tr>
            </table>
        </div>

        <?php submit_button( __( 'Enregistrer', 'seliweb' ) ); ?>
        </form>

        <script>
        (function(){
            function toggle(cb, section) {
                if (!cb || !section) return;
                cb.addEventListener('change', function(){ section.style.display = this.checked ? '' : 'none'; });
            }
            toggle(document.getElementById('toggle_ha'),     document.getElementById('section_ha'));
            toggle(document.getElementById('toggle_paheko'), document.getElementById('section_paheko'));
        })();
        </script>
        <?php
    }

    private static function subtab_exercices() {
        $sel_action = sanitize_key( $_GET['sel_action'] ?? '' );
        $id         = intval( $_GET['id'] ?? 0 );

        if ( $sel_action === 'ajouter' || ( $sel_action === 'modifier' && $id ) ) {
            self::form_exercice( $sel_action, $id );
            return;
        }

        global $wpdb;
        $te        = $wpdb->prefix . 'seliweb_exercices';
        $exercices = $wpdb->get_results( "SELECT * FROM $te ORDER BY date_debut DESC, id DESC" );
        $base      = admin_url( 'admin.php?page=seliweb_parametres&tab=cotisations&subtab=exercices' );

        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Exercice enregistré.', 'seliweb' ) . '</p></div>';
        }
        if ( isset( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Exercice supprimé.', 'seliweb' ) . '</p></div>';
        }
        if ( isset( $_GET['error'] ) && $_GET['error'] === 'libelle' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Le libellé est obligatoire.', 'seliweb' ) . '</p></div>';
        }
        ?>
        <p>
            <a href="<?php echo esc_url( $base . '&sel_action=ajouter' ); ?>" class="button button-primary">
                <?php esc_html_e( 'Ajouter un exercice', 'seliweb' ); ?>
            </a>
        </p>

        <?php if ( $exercices ) : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e( 'Libellé', 'seliweb' ); ?></th>
                <th style="width:130px;"><?php esc_html_e( 'Début', 'seliweb' ); ?></th>
                <th style="width:130px;"><?php esc_html_e( 'Fin', 'seliweb' ); ?></th>
                <th style="width:120px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $exercices as $ex ) : ?>
            <tr>
                <td><strong><?php echo esc_html( $ex->libelle ); ?></strong></td>
                <td><?php echo $ex->date_debut ? esc_html( date_i18n( 'd/m/Y', strtotime( $ex->date_debut ) ) ) : '—'; ?></td>
                <td><?php echo $ex->date_fin   ? esc_html( date_i18n( 'd/m/Y', strtotime( $ex->date_fin   ) ) ) : '—'; ?></td>
                <td>
                    <a href="<?php echo esc_url( $base . '&sel_action=modifier&id=' . $ex->id ); ?>">
                        <?php esc_html_e( 'Modifier', 'seliweb' ); ?>
                    </a>
                    &nbsp;|&nbsp;
                    <a href="<?php echo esc_url( wp_nonce_url(
                        admin_url( 'admin.php?page=seliweb_parametres&tab=cotisations&delete_id=' . $ex->id ),
                        'seliweb_delete_' . $ex->id
                    ) ); ?>"
                       onclick="return confirm('<?php esc_attr_e( 'Supprimer cet exercice ?', 'seliweb' ); ?>')"
                       style="color:#b32d2e;">
                        <?php esc_html_e( 'Supprimer', 'seliweb' ); ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p class="description"><?php esc_html_e( 'Aucun exercice défini.', 'seliweb' ); ?></p>
        <?php endif; ?>
        <?php
    }

    private static function form_exercice( $action, $id = 0 ) {
        global $wpdb;
        $te = $wpdb->prefix . 'seliweb_exercices';
        $tc = $wpdb->prefix . 'seliweb_cotisations';

        $ex      = null;
        $utilise = false;

        if ( $action === 'modifier' && $id ) {
            $ex = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id = %d", $id ) );
            if ( ! $ex ) {
                echo '<p>' . esc_html__( 'Exercice introuvable.', 'seliweb' ) . '</p>';
                return;
            }
            $utilise = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $tc WHERE exercice = %s", $ex->libelle
            ) );
        }

        $back_url = admin_url( 'admin.php?page=seliweb_parametres&tab=cotisations&subtab=exercices' );
        $titre    = $action === 'ajouter' ? __( 'Ajouter un exercice', 'seliweb' ) : __( 'Modifier l\'exercice', 'seliweb' );
        ?>
        <p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Retour à la liste', 'seliweb' ); ?></a></p>
        <h3><?php echo esc_html( $titre ); ?></h3>

        <?php if ( $utilise ) : ?>
        <div class="notice notice-warning inline" style="margin-bottom:16px;"><p>
            <?php esc_html_e( 'Cet exercice est déjà utilisé dans des cotisations. Seul le libellé peut être modifié.', 'seliweb' ); ?>
        </p></div>
        <?php endif; ?>

        <form method="post" style="max-width:520px;margin-top:16px;">
            <?php wp_nonce_field( 'seliweb_parametres', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="save_exercice">
            <input type="hidden" name="ex_id"     value="<?php echo intval( $id ); ?>">
            <input type="hidden" name="ex_action" value="<?php echo esc_attr( $action ); ?>">
            <table class="form-table">
                <tr>
                    <th><label for="ex_libelle"><?php esc_html_e( 'Libellé', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="text" id="ex_libelle" name="ex_libelle" class="regular-text"
                               value="<?php echo esc_attr( $ex->libelle ?? '' ); ?>"
                               placeholder="2026 ou 09/2025 à 06/2026" maxlength="100" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="ex_date_debut"><?php esc_html_e( 'Date de début', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="date" id="ex_date_debut" name="ex_date_debut"
                               value="<?php echo esc_attr( $ex->date_debut ?? '' ); ?>"
                               <?php echo $utilise ? 'disabled' : ''; ?>>
                        <?php if ( $utilise ) : ?>
                        <input type="hidden" name="ex_date_debut" value="<?php echo esc_attr( $ex->date_debut ?? '' ); ?>">
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="ex_date_fin"><?php esc_html_e( 'Date de fin', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="date" id="ex_date_fin" name="ex_date_fin"
                               value="<?php echo esc_attr( $ex->date_fin ?? '' ); ?>"
                               <?php echo $utilise ? 'disabled' : ''; ?>>
                        <?php if ( $utilise ) : ?>
                        <input type="hidden" name="ex_date_fin" value="<?php echo esc_attr( $ex->date_fin ?? '' ); ?>">
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(
                $action === 'ajouter' ? __( 'Ajouter', 'seliweb' ) : __( 'Mettre à jour', 'seliweb' ),
                'primary', 'submit', false
            ); ?>
        </form>
        <?php
    }

    private static function save_exercice() {
        global $wpdb;
        $te = $wpdb->prefix . 'seliweb_exercices';
        $tc = $wpdb->prefix . 'seliweb_cotisations';

        $ex_action  = sanitize_key( wp_unslash( $_POST['ex_action'] ?? 'ajouter' ) );
        $ex_id      = intval( $_POST['ex_id'] ?? 0 );
        $libelle    = sanitize_text_field( wp_unslash( $_POST['ex_libelle']    ?? '' ) );
        $date_debut = sanitize_text_field( wp_unslash( $_POST['ex_date_debut'] ?? '' ) );
        $date_fin   = sanitize_text_field( wp_unslash( $_POST['ex_date_fin']   ?? '' ) );

        $base_redirect = admin_url( 'admin.php?page=seliweb_parametres&tab=cotisations&subtab=exercices' );

        if ( ! $libelle ) {
            wp_safe_redirect( $base_redirect . '&error=libelle' );
            exit;
        }

        if ( $ex_action === 'modifier' && $ex_id ) {
            $ex      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $te WHERE id = %d", $ex_id ) );
            $utilise = $ex && (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $tc WHERE exercice = %s", $ex->libelle
            ) );

            $data = array( 'libelle' => $libelle );
            if ( ! $utilise ) {
                $data['date_debut'] = $date_debut ?: null;
                $data['date_fin']   = $date_fin   ?: null;
            }
            $wpdb->update( $te, $data, array( 'id' => $ex_id ) );
        } else {
            $wpdb->insert( $te, array(
                'libelle'    => $libelle,
                'date_debut' => $date_debut ?: null,
                'date_fin'   => $date_fin   ?: null,
                'est_actif'  => 0,
            ) );
        }

        wp_safe_redirect( $base_redirect . '&updated=1' );
        exit;
    }

    public static function handle_cotisations( $action ) {
        if ( $action === 'save_exercice' ) {
            self::save_exercice();
            return;
        }
        if ( $action !== 'save_cotisations' ) return;

        $groupes_ids = array_filter( array_map( 'intval', (array) ( $_POST['cotisations_groupes'] ?? array() ) ) );

        self::cfg_save( array(
            'cotisations_actif'      => isset( $_POST['cotisations_actif'] )           ? '1' : '0',
            'cotisations_exercice'   => sanitize_text_field( wp_unslash( $_POST['cotisations_exercice']   ?? '' ) ),
            'cotisations_date_debut' => sanitize_text_field( wp_unslash( $_POST['cotisations_date_debut'] ?? '' ) ),
            'cotisations_date_fin'   => sanitize_text_field( wp_unslash( $_POST['cotisations_date_fin']   ?? '' ) ),
            'cotisations_groupes'    => implode( ',', $groupes_ids ),
        ) );

        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=cotisations&updated=1' ) );
        exit;
    }

    // ================================================================
    // ADMIN — traitement POST onglet API (HelloAsso + Paheko)
    // ================================================================
    public static function handle_api( $action ) {
        if ( $action !== 'save_api' ) return;

        $campaign_url = esc_url_raw( wp_unslash( $_POST['helloasso_campaign_url'] ?? '' ) );

        $org_slug  = '';
        $form_slug = '';
        if ( $campaign_url ) {
            if ( preg_match( '~/associations/([^/]+)/[^/]+/([^/?#]+)~', $campaign_url, $m ) ) {
                $org_slug  = $m[1];
                $form_slug = $m[2];
            }
        }

        self::cfg_save( array(
            'cotisations_helloasso_actif' => isset( $_POST['cotisations_helloasso_actif'] ) ? '1' : '0',
            'helloasso_campaign_url'      => $campaign_url,
            'helloasso_client_id'         => sanitize_text_field( wp_unslash( $_POST['helloasso_client_id']     ?? '' ) ),
            'helloasso_client_secret'     => sanitize_text_field( wp_unslash( $_POST['helloasso_client_secret'] ?? '' ) ),
            'helloasso_org_slug'          => sanitize_key( $org_slug ),
            'helloasso_form_slug'         => sanitize_key( $form_slug ),
            'cotisations_paheko_actif'    => isset( $_POST['cotisations_paheko_actif'] )    ? '1' : '0',
            'paheko_url'                  => esc_url_raw( wp_unslash( $_POST['paheko_url']          ?? '' ) ),
            'paheko_identifiant'          => sanitize_text_field( wp_unslash( $_POST['paheko_identifiant']   ?? '' ) ),
            'paheko_mot_de_passe'         => sanitize_text_field( wp_unslash( $_POST['paheko_mot_de_passe']  ?? '' ) ),
        ) );

        delete_transient( 'seliweb_ha_token' );

        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=api&updated=1' ) );
        exit;
    }
}
