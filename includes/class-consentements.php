<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Traçabilité des consentements (inscription, abonnements — table commune
 * pour tout futur besoin similaire). Aucune rétroactivité : l'historique ne
 * démarre qu'à la mise en place de cette fonctionnalité, pas de consentement
 * reconstitué pour les comptes déjà existants.
 */
class Seliweb_Consentements {

    public static function init() {
        add_action( 'admin_post_seliweb_consentements_csv', array( __CLASS__, 'handle_csv' ) );
    }

    // Enregistre un consentement. $texte est la copie exacte du texte accepté
    // au moment du consentement (jamais un renvoi vers le réglage actuel, qui
    // peut changer ensuite). Ignoré silencieusement si $texte est vide — pas
    // de consentement à tracer s'il n'y avait rien à accepter.
    public static function enregistrer( $wp_user_id, $type, $reference_id, $texte ) {
        $texte = trim( (string) $texte );
        if ( ! $texte ) return;
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'seliweb_consentements', array(
            'wp_user_id'        => (int) $wp_user_id,
            'type'              => sanitize_key( $type ),
            'reference_id'      => $reference_id ? (int) $reference_id : null,
            'texte'             => $texte,
            'date_consentement' => current_time( 'mysql' ),
        ) );
    }

    // Historique d'un membre (par wp_user_id), du plus récent au plus ancien.
    public static function pour_membre( $wp_user_id ) {
        global $wpdb;
        $tc = $wpdb->prefix . 'seliweb_consentements';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $tc WHERE wp_user_id=%d ORDER BY date_consentement DESC, id DESC",
            (int) $wp_user_id
        ) );
    }

    public static function type_label( $type ) {
        $labels = array(
            'inscription' => __( 'Inscription', 'seliweb' ),
            'abonnement'  => __( 'Abonnement', 'seliweb' ),
        );
        return $labels[ $type ] ?? $type;
    }

    public static function handle_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'seliweb' ) );
        }
        check_admin_referer( 'seliweb_consentements_export' );

        global $wpdb;
        $tc = $wpdb->prefix . 'seliweb_consentements';
        $rows = $wpdb->get_results(
            "SELECT c.*, u.user_login, u.user_email
             FROM $tc c
             LEFT JOIN {$wpdb->users} u ON u.ID = c.wp_user_id
             ORDER BY c.date_consentement DESC, c.id DESC"
        );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="consentements-' . gmdate( 'Ymd' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // BOM UTF-8 (Excel)

        fputcsv( $out, array(
            __( 'Date', 'seliweb' ), __( 'Identifiant', 'seliweb' ), __( 'Email', 'seliweb' ),
            __( 'Type', 'seliweb' ), __( 'Référence', 'seliweb' ), __( 'Texte accepté', 'seliweb' ),
        ), ';' );

        foreach ( $rows as $r ) {
            fputcsv( $out, array(
                $r->date_consentement,
                $r->user_login ?? '',
                $r->user_email ?? '',
                self::type_label( $r->type ),
                $r->reference_id ?: '',
                $r->texte,
            ), ';' );
        }

        fclose( $out );
        exit;
    }
}
