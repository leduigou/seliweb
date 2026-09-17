<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Membres — colonnes d'impression/export communes à l'écran Membres
 * (templates/admin-membres.php) et à l'export CSV (admin_post), pour que
 * les deux utilisent exactement les mêmes libellés et la même liste
 * complète (mêmes filtres que la liste, mais sans pagination).
 */
class Seliweb_Membres {

    public static function init() {
        add_action( 'admin_post_seliweb_membres_csv', array( __CLASS__, 'handle_csv' ) );
    }

    // Colonnes disponibles : clé => libellé + accès à la valeur affichable
    // (déjà résolue en texte : civilité en toutes lettres, groupe par son
    // nom, bloqué en OUI/Non, date formatée).
    public static function column_defs() {
        return array(
            'numero_sel'     => array(
                'label' => __( 'N° SEL', 'seliweb' ),
                'get'   => function ( $m ) { return $m->numero_sel !== null ? (string) $m->numero_sel : ''; },
            ),
            'civilite'       => array(
                'label' => __( 'Civilité', 'seliweb' ),
                'get'   => function ( $m ) {
                    if ( 'Mr' === $m->civilite )  return __( 'M.', 'seliweb' );
                    if ( 'Mme' === $m->civilite ) return __( 'Mme', 'seliweb' );
                    return '';
                },
            ),
            'nom'            => array( 'label' => __( 'Nom', 'seliweb' ),          'get' => function ( $m ) { return $m->nom ?? ''; } ),
            'prenom'         => array( 'label' => __( 'Prénom', 'seliweb' ),       'get' => function ( $m ) { return $m->prenom ?? ''; } ),
            'organisme'      => array( 'label' => __( 'Organisme', 'seliweb' ),    'get' => function ( $m ) { return $m->organisme ?? ''; } ),
            'tel_portable'   => array( 'label' => __( 'Tél. portable', 'seliweb' ), 'get' => function ( $m ) { return $m->tel_portable ?? ''; } ),
            'tel_fixe'       => array( 'label' => __( 'Tél. fixe', 'seliweb' ),    'get' => function ( $m ) { return $m->tel_fixe ?? ''; } ),
            'adresse1'       => array( 'label' => __( 'Adresse 1', 'seliweb' ),    'get' => function ( $m ) { return $m->adresse1 ?? ''; } ),
            'adresse2'       => array( 'label' => __( 'Adresse 2', 'seliweb' ),    'get' => function ( $m ) { return $m->adresse2 ?? ''; } ),
            'ville'          => array( 'label' => __( 'Ville', 'seliweb' ),        'get' => function ( $m ) { return $m->ville ?? ''; } ),
            'code_postal'    => array( 'label' => __( 'Code postal', 'seliweb' ),  'get' => function ( $m ) { return $m->code_postal ?? ''; } ),
            'groupe'         => array( 'label' => __( 'Groupe', 'seliweb' ),       'get' => function ( $m ) { return $m->groupe_nom ?: ''; } ),
            'decouvert_max'  => array(
                'label' => __( 'Découvert max', 'seliweb' ),
                'get'   => function ( $m ) { return $m->decouvert_max !== null ? (string) $m->decouvert_max : ''; },
            ),
            'bloque'         => array(
                'label' => __( 'Bloqué', 'seliweb' ),
                'get'   => function ( $m ) { return $m->bloque ? __( 'OUI', 'seliweb' ) : __( 'Non', 'seliweb' ); },
            ),
            'archive'        => array(
                'label' => __( 'Archivé', 'seliweb' ),
                'get'   => function ( $m ) { return $m->archive ? __( 'OUI', 'seliweb' ) : __( 'Non', 'seliweb' ); },
            ),
            'date_naissance' => array(
                'label' => __( 'Date de naissance', 'seliweb' ),
                'get'   => function ( $m ) { return $m->date_naissance ? mysql2date( 'd/m/Y', $m->date_naissance ) : ''; },
            ),
            'wp_user_id'     => array( 'label' => __( 'ID utilisateur WP', 'seliweb' ), 'get' => function ( $m ) { return (string) $m->wp_user_id; } ),
            'identifiant'    => array( 'label' => __( 'Identifiant', 'seliweb' ),  'get' => function ( $m ) { return $m->user_login ?? ''; } ),
            'email'          => array( 'label' => __( 'Email', 'seliweb' ),        'get' => function ( $m ) { return $m->user_email ?? ''; } ),
        );
    }

    // Liste complète des membres (sans pagination), avec les mêmes filtres
    // et le même tri que l'écran Membres — utilisée par l'impression pleine
    // liste et par l'export CSV, pour qu'elles portent toujours sur le même
    // ensemble que celui affiché à l'écran (mêmes filtres actifs).
    public static function query_membres( $filtre_groupe, $filtre_ville, $filtre_bloque, $orderby_key = 'nom', $order = 'ASC', $filtre_archive = '' ) {
        global $wpdb;
        $tm = $wpdb->prefix . 'seliweb_membres';
        $tg = $wpdb->prefix . 'seliweb_groupes';

        $allowed_orderby = array(
            'id'     => 'm.id',
            'numero' => 'ISNULL(m.numero_sel), m.numero_sel',
            'prenom' => 'um_p.meta_value',
            'nom'    => 'um_n.meta_value',
            'email'  => 'u.user_email',
        );
        $orderby_sql = $allowed_orderby[ $orderby_key ] ?? $allowed_orderby['nom'];
        $order_sql   = 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC';

        $where  = array( '1=1' );
        $values = array();
        if ( $filtre_groupe ) { $where[] = 'm.groupe_id = %d'; $values[] = (int) $filtre_groupe; }
        if ( $filtre_ville )  { $where[] = 'm.ville = %s';     $values[] = $filtre_ville; }
        if ( 'bloques' === $filtre_bloque ) { $where[] = 'm.bloque = 1'; }
        if ( 'actifs'  === $filtre_bloque ) { $where[] = 'm.bloque = 0'; }
        if ( 'archives' === $filtre_archive ) { $where[] = 'm.archive = 1'; }
        if ( 'actifs'   === $filtre_archive ) { $where[] = 'm.archive = 0'; }
        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT m.*, g.nom AS groupe_nom, u.user_login, u.user_email,
                       um_p.meta_value AS prenom, um_n.meta_value AS nom, um_o.meta_value AS organisme
                FROM $tm m
                LEFT JOIN $tg g ON g.id=m.groupe_id
                LEFT JOIN {$wpdb->users} u ON u.ID=m.wp_user_id
                LEFT JOIN {$wpdb->usermeta} um_p ON um_p.user_id=m.wp_user_id AND um_p.meta_key='first_name'
                LEFT JOIN {$wpdb->usermeta} um_n ON um_n.user_id=m.wp_user_id AND um_n.meta_key='last_name'
                LEFT JOIN {$wpdb->usermeta} um_o ON um_o.user_id=m.wp_user_id AND um_o.meta_key='seliweb_organisme'
                WHERE $where_sql ORDER BY $orderby_sql $order_sql";

        return $values ? $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) ) : $wpdb->get_results( $sql );
    }

    // Colonnes demandées (paramètre cols[]), restreintes à celles qui
    // existent réellement ; toutes les colonnes par défaut si aucune n'est
    // reconnue (lien direct sans cols[], anomalie de saisie…).
    public static function requested_cols( $raw ) {
        $defs = self::column_defs();
        $keys = array_map( 'sanitize_key', (array) $raw );
        $keys = array_values( array_intersect( $keys, array_keys( $defs ) ) );
        return $keys ?: array_keys( $defs );
    }

    public static function handle_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'seliweb' ) );
        }
        check_admin_referer( 'seliweb_membres_export' );

        $filtre_groupe = isset( $_GET['filtre_groupe'] ) ? intval( $_GET['filtre_groupe'] ) : 0;
        $filtre_ville  = isset( $_GET['filtre_ville'] ) ? sanitize_text_field( wp_unslash( $_GET['filtre_ville'] ) ) : '';
        $filtre_bloque = isset( $_GET['filtre_bloque'] ) && in_array( $_GET['filtre_bloque'], array( 'bloques', 'actifs' ), true )
            ? $_GET['filtre_bloque'] : '';
        $filtre_archive = isset( $_GET['filtre_archive'] ) && in_array( $_GET['filtre_archive'], array( 'archives', 'actifs' ), true )
            ? $_GET['filtre_archive'] : '';
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'nom';
        $order   = isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'asc';

        $defs    = self::column_defs();
        $cols    = self::requested_cols( $_GET['cols'] ?? array() );
        $membres = self::query_membres( $filtre_groupe, $filtre_ville, $filtre_bloque, $orderby, $order, $filtre_archive );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="membres-' . gmdate( 'Ymd' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // BOM UTF-8 (Excel)

        $entete = array();
        foreach ( $cols as $c ) $entete[] = $defs[ $c ]['label'];
        fputcsv( $out, $entete, ';' );

        foreach ( $membres as $m ) {
            $row = array();
            foreach ( $cols as $c ) $row[] = $defs[ $c ]['get']( $m );
            fputcsv( $out, $row, ';' );
        }

        fclose( $out );
        exit;
    }
}
