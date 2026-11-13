<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Demandes d'adhésion : formulaire public [seliweb_demande_adhesion]
 * (utilisable en SEL ouvert comme fermé, commun avec l'inscription : voir
 * Seliweb_Inscription) et traitement par un administrateur (onglet
 * Membres → Demandes d'adhésion).
 *
 * Statuts : 'attente' → 'acceptee' (le membre est créé) ou 'refusee'.
 * Une demande refusée peut encore être acceptée plus tard.
 */
class Seliweb_Adhesions {

    const STATUTS = array( 'attente', 'refusee', 'acceptee' );

    // Vrai pendant l'envoi du mail WordPress de création de compte qui suit
    // une acceptation : Seliweb_Parametres::filter_inscription_email()
    // applique alors les réglages du mail « Adhésion acceptée ».
    public static $envoi_acceptation = false;

    public static function init() {
        add_shortcode( 'seliweb_demande_adhesion', array( __CLASS__, 'shortcode' ) );
    }

    // Formulaire public : commun avec l'inscription (voir Seliweb_Inscription).
    public static function shortcode() {
        return Seliweb_Inscription::formulaire( 'adhesion' );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seliweb_demandes_adhesion';
    }

    public static function nb_en_attente() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE statut='attente'" );
    }

    // Onglets de l'écran Membres (admin).
    public static function onglets( $actif ) {
        $nb = self::nb_en_attente();
        $onglets = array(
            'membres'  => array( __( 'Membres', 'seliweb' ), admin_url( 'admin.php?page=seliweb_membres' ) ),
            'demandes' => array( __( "Demandes d'adhésion", 'seliweb' ), admin_url( 'admin.php?page=seliweb_membres&tab=demandes' ) ),
        );
        echo '<nav class="nav-tab-wrapper seliweb-no-print" style="margin-bottom:12px;">';
        foreach ( $onglets as $cle => $o ) {
            echo '<a href="' . esc_url( $o[1] ) . '" class="nav-tab' . ( $cle === $actif ? ' nav-tab-active' : '' ) . '">' . esc_html( $o[0] );
            if ( $cle === 'demandes' && $nb ) {
                echo ' <span class="awaiting-mod" style="display:inline-block;min-width:18px;padding:0 6px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;text-align:center;">' . (int) $nb . '</span>';
            }
            echo '</a>';
        }
        echo '</nav>';
    }

    // Mail « Demande d'adhésion reçue » (Paramètres → Mails).
    public static function prevenir_admin( $d ) {
        global $wpdb;
        $cfg = array();
        foreach ( $wpdb->get_results( "SELECT cle, valeur FROM {$wpdb->prefix}seliweb_parametres WHERE cle LIKE 'mail\_demadh\_%'" ) as $r ) {
            $cfg[ $r->cle ] = $r->valeur;
        }
        $to = trim( $cfg['mail_demadh_to_email'] ?? '' );
        if ( ! is_email( $to ) ) $to = get_option( 'admin_email' );

        $sujet = trim( $cfg['mail_demadh_subject'] ?? '' )
            ?: sprintf( '[%s] %s', get_bloginfo( 'name' ), __( "Nouvelle demande d'adhésion", 'seliweb' ) );

        $corps = sprintf(
            "%s\n\n%s %s %s\n%s %s\n%s %s\n\n%s\n%s",
            __( "Une nouvelle demande d'adhésion a été déposée sur le site.", 'seliweb' ),
            __( 'Nom :', 'seliweb' ), $d['prenom'], $d['nom'],
            __( 'E-mail :', 'seliweb' ), $d['email'],
            __( 'Ville :', 'seliweb' ), trim( $d['code_postal'] . ' ' . $d['ville'] ),
            __( 'Pour la traiter :', 'seliweb' ),
            admin_url( 'admin.php?page=seliweb_membres&tab=demandes' )
        );
        $intro = trim( $cfg['mail_demadh_intro'] ?? '' );
        $sig   = trim( $cfg['mail_demadh_signature'] ?? '' );
        $corps = trim( ( $intro ? $intro . "\n\n" : '' ) . $corps . ( $sig ? "\n\n" . $sig : '' ) );

        $headers    = array();
        $from_email = trim( $cfg['mail_demadh_from_email'] ?? '' );
        $from_name  = trim( $cfg['mail_demadh_from_name'] ?? '' );
        if ( $from_email && is_email( $from_email ) ) {
            $headers[] = 'From: ' . ( $from_name ? $from_name . ' <' . $from_email . '>' : $from_email );
        }
        wp_mail( $to, $sujet, $corps, $headers );
    }

    // ----------------------------------------------------------------
    // Traitement administrateur
    // ----------------------------------------------------------------
    public static function changer_groupe( $id, $groupe_id ) {
        global $wpdb;
        $wpdb->update( self::table(), array( 'groupe_id' => $groupe_id ?: null ), array( 'id' => $id ) );
    }

    public static function refuser( $id ) {
        global $wpdb;
        $wpdb->update( self::table(),
            array( 'statut' => 'refusee', 'date_traitement' => current_time( 'mysql' ) ),
            array( 'id' => $id, 'statut' => 'attente' )
        );
    }

    // Crée le compte WordPress et le membre Seliweb à partir de la demande.
    // Retourne l'id du membre, ou un WP_Error.
    public static function accepter( $id ) {
        global $wpdb;
        $t = self::table();
        $d = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id=%d", $id ) );
        if ( ! $d || $d->statut === 'acceptee' ) {
            return new WP_Error( 'seliweb_adhesion', __( 'Demande introuvable ou déjà acceptée.', 'seliweb' ) );
        }
        if ( ! $d->groupe_id ) {
            return new WP_Error( 'seliweb_adhesion', __( 'Choisissez un groupe avant d\'accepter la demande.', 'seliweb' ) );
        }
        if ( username_exists( $d->identifiant ) ) {
            return new WP_Error( 'seliweb_adhesion', sprintf( __( "L'identifiant « %s » est déjà utilisé par un autre compte.", 'seliweb' ), $d->identifiant ) );
        }
        if ( email_exists( $d->email ) ) {
            return new WP_Error( 'seliweb_adhesion', sprintf( __( "L'adresse %s est déjà utilisée par un autre compte.", 'seliweb' ), $d->email ) );
        }

        // Création du compte + mail « Adhésion acceptée » (code commun avec l'inscription).
        $cree = Seliweb_Inscription::creer_membre( (array) $d, (int) $d->groupe_id, true );
        if ( is_wp_error( $cree ) ) return $cree;
        $membre_id = (int) $cree[1];

        $wpdb->update( $t, array(
            'statut'          => 'acceptee',
            'date_traitement' => current_time( 'mysql' ),
            'membre_id'       => $membre_id,
        ), array( 'id' => $id ) );

        return $membre_id;
    }
}
