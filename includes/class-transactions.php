<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Seliweb_Transactions {

    private static $form_error   = '';
    private static $force_action = '';

    // ================================================================
    // Initialisation
    // ================================================================
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
        add_action( 'init',       array( __CLASS__, 'handle_frontend_post' ) );
    }

    public static function display() {
        include SELIWEB_DIR . 'templates/admin-transactions.php';
    }

    // ================================================================
    // POST frontend (hook init) — création de transaction par un membre
    // Étape 1 : validation → stockage transient → synthèse
    // Étape 2 : confirmation → enregistrement
    // ================================================================
    public static function handle_frontend_post() {
        if ( is_admin() ) return;
        if ( ! is_user_logged_in() ) return;

        $wp_user_id = get_current_user_id();

        // --- Étape 1 : validation du formulaire de saisie ---
        if ( isset( $_POST['seliweb_nonce_txn_creer'] ) ) {
            if ( ! wp_verify_nonce( $_POST['seliweb_nonce_txn_creer'], 'seliweb_txn_creer_' . $wp_user_id ) ) return;

            global $wpdb;
            $tm = $wpdb->prefix . 'seliweb_membres';

            $membre = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $tm WHERE wp_user_id=%d LIMIT 1", $wp_user_id
            ) );
            if ( ! $membre ) return;

            $sel = self::get_sel_info();
            if ( ! $sel['actif'] || (int) $membre->groupe_id !== $sel['groupe_id'] ) return;

            $debit_id  = intval( $membre->id );
            $credit_id = intval( $_POST['membre_credit_id'] ?? 0 );
            $montant   = intval( $_POST['montant']           ?? 0 );
            $libelle   = sanitize_text_field( wp_unslash( $_POST['libelle'] ?? '' ) );
            $date_txn  = sanitize_text_field( wp_unslash( $_POST['date']    ?? '' ) );

            $redir_err = function( $msg ) {
                wp_safe_redirect( add_query_arg( array(
                    'sel_action'  => 'creer_transaction',
                    'sel_txn_err' => rawurlencode( $msg ),
                ), get_permalink() ) );
                exit;
            };

            $error = self::validate_fields( $debit_id, $credit_id, $montant, $libelle, $date_txn );
            if ( $error ) { $redir_err( $error ); }

            if ( self::is_compte_sel( $credit_id ) ) {
                $redir_err( __( "Le compte du SEL N°1 est réservé à l'administrateur.", 'seliweb' ) );
            }

            $err_debit = '';
            if ( ! self::can_debit( $debit_id, $montant, $sel, $err_debit ) ) { $redir_err( $err_debit ); }

            // Stockage temporaire (10 min) avant confirmation
            set_transient( 'seliweb_pending_txn_' . $wp_user_id, array(
                'debit_id'  => $debit_id,
                'credit_id' => $credit_id,
                'montant'   => $montant,
                'libelle'   => $libelle,
                'date'      => $date_txn,
            ), 600 );

            wp_safe_redirect( add_query_arg( 'sel_action', 'confirmer_transaction', get_permalink() ) );
            exit;
        }

        // --- Étape 2 : confirmation → enregistrement ---
        if ( isset( $_POST['seliweb_nonce_txn_confirmer'] ) ) {
            if ( ! wp_verify_nonce( $_POST['seliweb_nonce_txn_confirmer'], 'seliweb_txn_confirmer_' . $wp_user_id ) ) return;

            $pending = get_transient( 'seliweb_pending_txn_' . $wp_user_id );
            if ( ! $pending ) {
                wp_safe_redirect( add_query_arg( array(
                    'sel_action'  => 'creer_transaction',
                    'sel_txn_err' => rawurlencode( __( 'Session expirée. Veuillez recommencer.', 'seliweb' ) ),
                ), get_permalink() ) );
                exit;
            }
            delete_transient( 'seliweb_pending_txn_' . $wp_user_id );

            global $wpdb;
            $tt = $wpdb->prefix . 'seliweb_transactions';
            $te = $wpdb->prefix . 'seliweb_ecritures';

            // Re-vérifier le solde (peut avoir changé entre les deux étapes)
            $sel       = self::get_sel_info();
            $err_debit = '';
            if ( ! self::can_debit( $pending['debit_id'], $pending['montant'], $sel, $err_debit ) ) {
                wp_safe_redirect( add_query_arg( array(
                    'sel_action'  => 'creer_transaction',
                    'sel_txn_err' => rawurlencode( $err_debit ),
                ), get_permalink() ) );
                exit;
            }

            $wpdb->insert( $tt, array(
                'date'       => $pending['date'],
                'libelle'    => $pending['libelle'],
                'montant'    => $pending['montant'],
                'created_at' => current_time( 'mysql' ),
            ) );
            $new_id = $wpdb->insert_id;
            $wpdb->insert( $te, array( 'transaction_id' => $new_id, 'membre_id' => $pending['debit_id'],  'type' => 'debit'  ) );
            $wpdb->insert( $te, array( 'transaction_id' => $new_id, 'membre_id' => $pending['credit_id'], 'type' => 'credit' ) );

            wp_safe_redirect( add_query_arg( array(
                'sel_action'    => 'transactions',
                'sel_txn_added' => '1',
            ), get_permalink() ) );
            exit;
        }
    }

    // ================================================================
    // Traitement des POST (avant tout affichage)
    // ================================================================
    public static function handle_post() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_transactions' ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( empty( $_POST ) ) return;

        if ( isset( $_POST['seliweb_ajouter_transaction'] ) ) {
            check_admin_referer( 'seliweb_transaction_add' );
            $error = self::process_add();
            if ( $error ) {
                self::$form_error   = $error;
                self::$force_action = 'ajouter';
            }
            return;
        }

        if ( isset( $_POST['seliweb_modifier_transaction'] ) ) {
            $edit_id = intval( $_POST['transaction_id'] ?? 0 );
            check_admin_referer( 'seliweb_transaction_edit_' . $edit_id );
            $error = self::process_edit( $edit_id );
            if ( $error ) {
                self::$form_error   = $error;
                self::$force_action = 'modifier';
            }
        }
    }

    private static function process_add() {
        global $wpdb;
        $tt = $wpdb->prefix . 'seliweb_transactions';
        $te = $wpdb->prefix . 'seliweb_ecritures';

        $debit_id  = intval( $_POST['membre_debit_id']  ?? 0 );
        $credit_id = intval( $_POST['membre_credit_id'] ?? 0 );
        $montant   = intval( $_POST['montant']           ?? 0 );
        $libelle   = sanitize_text_field( wp_unslash( $_POST['libelle'] ?? '' ) );
        $date_txn  = sanitize_text_field( wp_unslash( $_POST['date']    ?? '' ) );

        $error = self::validate_fields( $debit_id, $credit_id, $montant, $libelle, $date_txn );
        if ( $error ) return $error;

        $sel = self::get_sel_info();
        if ( ! self::can_debit( $debit_id, $montant, $sel, $error ) ) return $error;

        $wpdb->insert( $tt, array(
            'date'       => $date_txn,
            'libelle'    => $libelle,
            'montant'    => $montant,
            'created_at' => current_time( 'mysql' ),
        ) );
        $new_id = $wpdb->insert_id;
        $wpdb->insert( $te, array( 'transaction_id' => $new_id, 'membre_id' => $debit_id,  'type' => 'debit'  ) );
        $wpdb->insert( $te, array( 'transaction_id' => $new_id, 'membre_id' => $credit_id, 'type' => 'credit' ) );

        wp_safe_redirect( add_query_arg( array( 'page' => 'seliweb_transactions', 'added' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function process_edit( $edit_id ) {
        global $wpdb;
        $tt = $wpdb->prefix . 'seliweb_transactions';
        $te = $wpdb->prefix . 'seliweb_ecritures';

        if ( ! $edit_id ) return __( 'Transaction introuvable.', 'seliweb' );

        $debit_id  = intval( $_POST['membre_debit_id']  ?? 0 );
        $credit_id = intval( $_POST['membre_credit_id'] ?? 0 );
        $montant   = intval( $_POST['montant']           ?? 0 );
        $libelle   = sanitize_text_field( wp_unslash( $_POST['libelle'] ?? '' ) );
        $date_txn  = sanitize_text_field( wp_unslash( $_POST['date']    ?? '' ) );

        $error = self::validate_fields( $debit_id, $credit_id, $montant, $libelle, $date_txn );
        if ( $error ) return $error;

        $old_txn = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tt WHERE id=%d", $edit_id ) );
        if ( ! $old_txn ) return __( 'Transaction introuvable.', 'seliweb' );

        $sel = self::get_sel_info();
        if ( ! self::can_debit( $debit_id, $montant, $sel, $error ) ) return $error;

        $wpdb->update( $tt,
            array( 'date' => $date_txn, 'libelle' => $libelle, 'montant' => $montant, 'modified_at' => current_time( 'mysql' ) ),
            array( 'id'   => $edit_id )
        );
        $wpdb->update( $te, array( 'membre_id' => $debit_id ),  array( 'transaction_id' => $edit_id, 'type' => 'debit'  ) );
        $wpdb->update( $te, array( 'membre_id' => $credit_id ), array( 'transaction_id' => $edit_id, 'type' => 'credit' ) );

        wp_safe_redirect( add_query_arg( array( 'page' => 'seliweb_transactions', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function validate_fields( $debit_id, $credit_id, $montant, $libelle, $date_txn ) {
        $sel   = self::get_sel_info();
        $mbrs  = self::get_sel_membres( $sel['groupe_id'] );
        $ids   = array_map( function( $m ) { return intval( $m->id ); }, $mbrs );

        if ( ! $debit_id || ! $credit_id ) {
            return __( 'Veuillez sélectionner les deux membres.', 'seliweb' );
        }
        if ( $debit_id === $credit_id ) {
            return __( 'Le membre débité et le membre crédité doivent être différents.', 'seliweb' );
        }
        if ( $montant < 1 ) {
            return __( 'Le montant doit être un entier supérieur à zéro.', 'seliweb' );
        }
        if ( empty( $libelle ) ) {
            return __( 'Le libellé est obligatoire.', 'seliweb' );
        }
        if ( empty( $date_txn ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_txn ) ) {
            return __( 'La date est invalide.', 'seliweb' );
        }
        if ( ! in_array( $debit_id, $ids, true ) || ! in_array( $credit_id, $ids, true ) ) {
            return __( 'Membre introuvable dans le groupe SEL.', 'seliweb' );
        }
        return '';
    }

    // ================================================================
    // Accesseurs pour le template
    // ================================================================
    public static function get_form_error()   { return self::$form_error; }
    public static function get_force_action() { return self::$force_action; }

    // ================================================================
    // Helpers
    // ================================================================
    public static function sel_actif() {
        global $wpdb;
        $tp  = $wpdb->prefix . 'seliweb_parametres';
        $val = $wpdb->get_var( "SELECT valeur FROM $tp WHERE cle='sel_actif' LIMIT 1" );
        return ! empty( $val );
    }

    public static function get_sel_info() {
        global $wpdb;
        $tp   = $wpdb->prefix . 'seliweb_parametres';
        $rows = $wpdb->get_results( "SELECT cle, valeur FROM $tp WHERE cle LIKE 'sel_%'" );
        $s    = array();
        foreach ( $rows as $r ) {
            $s[ $r->cle ] = $r->valeur;
        }
        return array(
            'actif'              => ! empty( $s['sel_actif'] ),
            'groupe_id'          => isset( $s['sel_groupe_id'] )          ? intval( $s['sel_groupe_id'] )    : 0,
            'monnaie_id'         => isset( $s['sel_monnaie_id'] )         ? intval( $s['sel_monnaie_id'] )   : 0,
            'decouvert_possible' => ! empty( $s['sel_decouvert_possible'] ),
            'decouvert_max'      => isset( $s['sel_decouvert_max'] )      ? intval( $s['sel_decouvert_max'] ) : 0,
        );
    }

    public static function get_monnaie( $monnaie_id ) {
        global $wpdb;
        $tm = $wpdb->prefix . 'seliweb_monnaies';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tm WHERE id=%d", $monnaie_id ) );
    }

    public static function get_sel_membres( $groupe_id ) {
        global $wpdb;
        $tm = $wpdb->prefix . 'seliweb_membres';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id, m.numero_sel, m.wp_user_id, m.decouvert_max,
                    um_fn.meta_value AS prenom,
                    um_ln.meta_value AS nom
             FROM $tm m
             JOIN {$wpdb->users} u ON u.ID = m.wp_user_id
             LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = u.ID AND um_fn.meta_key = 'first_name'
             LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = u.ID AND um_ln.meta_key = 'last_name'
             WHERE m.groupe_id = %d OR m.numero_sel = 1
             ORDER BY m.numero_sel ASC, m.id ASC",
            $groupe_id
        ) );
    }

    public static function get_balance( $membre_id ) {
        global $wpdb;
        $te = $wpdb->prefix . 'seliweb_ecritures';
        $tt = $wpdb->prefix . 'seliweb_transactions';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN e.type='credit' THEN t.montant ELSE -t.montant END), 0)
             FROM $te e
             JOIN $tt t ON t.id = e.transaction_id
             WHERE e.membre_id = %d",
            $membre_id
        ) );
    }

    public static function is_compte_sel( $membre_id ) {
        global $wpdb;
        $tm  = $wpdb->prefix . 'seliweb_membres';
        $num = $wpdb->get_var( $wpdb->prepare( "SELECT numero_sel FROM $tm WHERE id=%d", $membre_id ) );
        return intval( $num ) === 1;
    }

    public static function can_debit( $membre_id, $montant, $sel_info, &$error ) {
        if ( self::is_compte_sel( $membre_id ) ) return true;

        global $wpdb;
        $balance     = self::get_balance( $membre_id );
        $new_balance = $balance - $montant;

        if ( ! $sel_info['decouvert_possible'] ) {
            if ( $new_balance < 0 ) {
                $error = sprintf(
                    __( 'Solde insuffisant. Solde actuel : %d, montant demandé : %d.', 'seliweb' ),
                    $balance, $montant
                );
                return false;
            }
        } else {
            $tm  = $wpdb->prefix . 'seliweb_membres';
            $dm  = $wpdb->get_var( $wpdb->prepare( "SELECT decouvert_max FROM $tm WHERE id=%d", $membre_id ) );
            $max = ( $dm !== null ) ? intval( $dm ) : $sel_info['decouvert_max'];
            if ( $new_balance < -$max ) {
                $error = sprintf(
                    __( 'Découvert maximum dépassé. Solde actuel : %d, découvert autorisé : %d, montant demandé : %d.', 'seliweb' ),
                    $balance, $max, $montant
                );
                return false;
            }
        }
        return true;
    }

    public static function membre_label( $m ) {
        if ( intval( $m->numero_sel ) === 1 ) {
            return 'N°1 — ' . __( 'Compte du SEL', 'seliweb' );
        }
        $nom = trim( ( $m->prenom ?? '' ) . ' ' . ( $m->nom ?? '' ) );
        return 'N°' . intval( $m->numero_sel ) . ( $nom ? ' — ' . $nom : '' );
    }
}
