<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Droits de back-office Seliweb pour des membres qui ne sont pas
 * administrateurs WordPress (voir Paramètres → Gestionnaires, réservé aux
 * administrateurs). Un seul niveau par module, hiérarchique :
 * lecture < modification < création — la création emporte la suppression
 * (même droit, décision produit). Les administrateurs WP (manage_options)
 * ont toujours tous les droits sur tous les modules, sans configuration.
 *
 * Les réglages (Paramètres, tous onglets) restent réservés aux
 * administrateurs WP dans tous les cas — ce système ne les concerne pas.
 */
class Seliweb_Permissions {

    // Capacité technique donnée dynamiquement (jamais stockée comme un rôle)
    // aux administrateurs et aux membres ayant au moins un droit configuré —
    // c'est elle qui conditionne l'affichage des menus Seliweb dans
    // l'admin, jamais manage_options.
    const CAP = 'seliweb_backend';

    const NIVEAUX = array( 'lecture' => 1, 'modification' => 2, 'creation' => 3 );

    public static function init() {
        add_filter( 'user_has_cap', array( __CLASS__, 'filtrer_cap' ), 10, 4 );
        add_action( 'admin_notices', array( __CLASS__, 'afficher_bandeau_niveau' ) );
    }

    // $_GET['page'] -> clé de module (voir modules() ci-dessus).
    private static function module_de_la_page() {
        $map = array(
            'seliweb'              => 'dashboard',
            'seliweb_annonces'     => 'annonces',
            'seliweb_membres'      => 'membres',
            'seliweb_transactions' => 'transactions',
            'seliweb_cotisations'  => 'cotisations',
            'seliweb_evenements'   => 'evenements',
        );
        return $map[ sanitize_key( $_GET['page'] ?? '' ) ] ?? '';
    }

    // Un gestionnaire ne peut pas voir, à chaque tentative bloquée, un
    // message d'erreur distinct pour chaque bouton masqué ou action
    // refusée — ce bandeau, affiché une fois en haut de l'écran, rappelle
    // simplement son niveau sur le module consulté. Rien pour « création »
    // (tous les droits, comme un administrateur) ni pour « aucun droit »
    // (l'écran affiche déjà son propre refus via verifier_acces()).
    public static function afficher_bandeau_niveau() {
        if ( current_user_can( 'manage_options' ) ) return;
        $module = self::module_de_la_page();
        if ( ! $module ) return;

        $membre_id = self::membre_id_pour( get_current_user_id() );
        $niveau    = self::niveau( $membre_id, $module );

        if ( $niveau === self::NIVEAUX['lecture'] ) {
            $texte = __( 'Vous êtes connecté en mode Lecture sur ce module : vous pouvez consulter, mais pas créer, modifier ou supprimer.', 'seliweb' );
        } elseif ( $niveau === self::NIVEAUX['modification'] ) {
            $texte = __( 'Vous êtes connecté en mode Modification sur ce module : vous pouvez modifier les éléments existants, mais pas en créer ni en supprimer.', 'seliweb' );
        } else {
            return;
        }
        echo '<div class="notice notice-info"><p>' . esc_html( $texte ) . '</p></div>';
    }

    public static function modules() {
        return array(
            'dashboard'    => __( 'Tableau de bord', 'seliweb' ),
            'annonces'     => __( 'Annonces',         'seliweb' ),
            'membres'      => __( 'Membres',          'seliweb' ),
            'transactions' => __( 'Transactions',     'seliweb' ),
            'cotisations'  => __( 'Cotis. Abo.',      'seliweb' ),
            'evenements'   => __( 'Événements',       'seliweb' ),
        );
    }

    public static function filtrer_cap( $allcaps, $caps, $args, $user ) {
        if ( ! in_array( self::CAP, (array) $caps, true ) ) return $allcaps;
        if ( ! empty( $allcaps['manage_options'] ) ) {
            $allcaps[ self::CAP ] = true;
            return $allcaps;
        }
        $membre_id = self::membre_id_pour( (int) $user->ID );
        if ( $membre_id && self::a_un_droit( $membre_id ) ) {
            $allcaps[ self::CAP ] = true;
        }
        return $allcaps;
    }

    public static function membre_id_pour( $wp_user_id ) {
        if ( ! $wp_user_id ) return 0;
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}seliweb_membres WHERE wp_user_id=%d", $wp_user_id
        ) );
    }

    public static function a_un_droit( $membre_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}seliweb_permissions WHERE membre_id=%d", $membre_id
        ) );
    }

    // Niveau (0 à 3) d'un membre sur un module. 0 = aucun droit.
    public static function niveau( $membre_id, $module ) {
        if ( ! $membre_id ) return 0;
        global $wpdb;
        $niveau = $wpdb->get_var( $wpdb->prepare(
            "SELECT niveau FROM {$wpdb->prefix}seliweb_permissions WHERE membre_id=%d AND module=%s",
            $membre_id, $module
        ) );
        return $niveau ? (int) ( self::NIVEAUX[ $niveau ] ?? 0 ) : 0;
    }

    // $action : lire | modifier | creer | supprimer (creer et supprimer
    // exigent le même niveau, « création/suppression » étant un seul droit).
    // Les administrateurs WP ont toujours accès, quel que soit le module.
    public static function peut( $module, $action = 'lire' ) {
        if ( current_user_can( 'manage_options' ) ) return true;
        $membre_id = self::membre_id_pour( get_current_user_id() );
        $niveau    = self::niveau( $membre_id, $module );
        $seuils    = array( 'lire' => 1, 'modifier' => 2, 'creer' => 3, 'supprimer' => 3 );
        return $niveau >= ( $seuils[ $action ] ?? 99 );
    }

    // À appeler en tête d'un écran back-office Seliweb : si l'accès est
    // refusé, affiche le message et renvoie false (l'appelant doit alors
    // faire un simple `return`). Le menu reste visible mais l'écran refuse
    // poliment l'accès — décision produit, pas une erreur WordPress.
    public static function verifier_acces( $module, $action = 'lire' ) {
        if ( self::peut( $module, $action ) ) return true;
        self::afficher_refus();
        return false;
    }

    public static function afficher_refus() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Accès refusé', 'seliweb' ) . '</h1>';
        echo '<p>' . esc_html__( "Vous n'avez pas les droits pour accéder à cette page. Contactez l'administrateur du site si vous pensez qu'il s'agit d'une erreur.", 'seliweb' ) . '</p></div>';
    }

    // Enregistre (ou retire si $niveau vide) le droit d'un membre sur un module.
    public static function enregistrer( $membre_id, $module, $niveau ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seliweb_permissions';
        if ( ! $niveau ) {
            $wpdb->delete( $table, array( 'membre_id' => $membre_id, 'module' => $module ) );
            return;
        }
        $existe = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE membre_id=%d AND module=%s", $membre_id, $module
        ) );
        if ( $existe ) {
            $wpdb->update( $table, array( 'niveau' => $niveau ), array( 'id' => $existe ) );
        } else {
            $wpdb->insert( $table, array( 'membre_id' => $membre_id, 'module' => $module, 'niveau' => $niveau ) );
        }
    }

    // Droits de tous les membres, indexés par membre_id puis module.
    public static function tous_les_droits() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT membre_id, module, niveau FROM {$wpdb->prefix}seliweb_permissions" );
        $out  = array();
        foreach ( $rows as $r ) {
            $out[ (int) $r->membre_id ][ $r->module ] = $r->niveau;
        }
        return $out;
    }
}
