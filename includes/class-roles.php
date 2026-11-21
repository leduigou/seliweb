<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rôle « Administrateur Seliweb » : un responsable de l'association qui gère Seliweb sans être
 * administrateur WordPress.
 *
 * - Droits : ceux d'un Éditeur (pages, articles, médias, commentaires), Apparence → Personnaliser
 *   (couleurs du thème, menus, widgets, logo, et aussi titre, slogan, icône et page d'accueil du site,
 *   que WordPress réserve sinon aux administrateurs), tous les écrans Seliweb y compris Paramètres
 *   (capacité seliweb_administrer), et la gestion des comptes WordPress (créer, modifier, changer le
 *   rôle) pour les rôles d'Abonné à Éditeur, plus Administrateur Seliweb (les responsables se cooptent).
 * - Jamais : extensions, thèmes, mises à jour, réglages de WordPress, HTML libre (un script dans
 *   une page pourrait voler la session d'un administrateur).
 * - Verrous (pour tout compte qui n'est pas administrateur WordPress) : les rôles qui donnent
 *   la gestion des comptes ou l'administration (Administrateur…) ne sont pas attribuables — sauf
 *   Administrateur Seliweb par un Administrateur Seliweb —, et les comptes qui les ont ne peuvent
 *   être ni modifiés, ni supprimés, ni changés de rôle : retirer le rôle d'un Administrateur Seliweb
 *   passe par un administrateur WordPress. Sans ces verrous, la gestion des comptes permettrait de se
 *   donner les pleins pouvoirs, d'évincer les autres responsables ou de prendre le compte d'un
 *   administrateur (mot de passe, e-mail).
 *
 * Le rôle est (re)créé à chaque nouvelle version de sa définition (ROLES_VERSION). L'ancien rôle
 * « Trésorier » (trsorier, créé à la main sur certains sites, avec des droits d'administration)
 * est supprimé au passage : ses titulaires deviennent Abonnés, leurs droits Seliweb se règlent
 * dans Paramètres → Gestionnaires.
 */
class Seliweb_Roles {

    const ROLE          = 'seliweb_admin';
    const ROLES_VERSION = '1';
    const OPTION        = 'seliweb_roles_version';
    const ANCIENS_ROLES = array( 'trsorier' ); // Remplacés par Paramètres → Gestionnaires.

    // Droits qui ouvrent la gestion des comptes ou l'administration : un rôle qui en a un n'est
    // attribuable, et un compte qui en a un n'est modifiable, que par un administrateur WordPress.
    const DROITS_SENSIBLES = array( 'manage_options', 'promote_users', 'edit_users', 'delete_users', 'install_plugins', Seliweb_Permissions::CAP_ADMIN );

    // Réglages du Personnaliser que WordPress réserve à manage_options, ouverts aux administrateurs Seliweb.
    // Les autres réglages de WordPress (adresse du site, e-mail d'administration, inscriptions…) restent réservés.
    const REGLAGES_SITE = array( 'blogname', 'blogdescription', 'site_icon', 'show_on_front', 'page_on_front', 'page_for_posts' );

    public static function init() {
        add_action( 'init', array( __CLASS__, 'maj' ) );
        add_action( 'customize_register', array( __CLASS__, 'personnaliser' ), 1000 ); // Après les réglages de WordPress (priorité 10).
        add_filter( 'editable_roles', array( __CLASS__, 'roles_attribuables' ) );
        add_filter( 'map_meta_cap', array( __CLASS__, 'proteger_comptes' ), 10, 4 );
        add_action( 'admin_notices', array( __CLASS__, 'masquer_avis_tiers' ), 0 );
        add_action( 'network_admin_notices', array( __CLASS__, 'masquer_avis_tiers' ), 0 );
    }

    /** Droits du rôle : ceux d'un Éditeur WordPress (sans HTML libre), plus les menus, Seliweb et les comptes. */
    public static function droits() {
        $droits = array(
            'read', 'upload_files', 'moderate_comments', 'manage_categories', 'manage_links',
            'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts',
            'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts',
            'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'publish_pages', 'read_private_pages',
            'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages',
            'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'level_6', 'level_7',
            'edit_theme_options', // Apparence → Menus et Widgets.
            'list_users', 'create_users', 'edit_users', 'promote_users', 'delete_users',
            Seliweb_Permissions::CAP_ADMIN,
        );
        return array_fill_keys( $droits, true );
    }

    /** Crée ou met à jour le rôle, et supprime les anciens rôles, une fois par version. */
    public static function maj() {
        if ( get_option( self::OPTION ) === self::ROLES_VERSION ) {
            return;
        }
        remove_role( self::ROLE );
        add_role( self::ROLE, 'Administrateur Seliweb', self::droits() );

        foreach ( self::ANCIENS_ROLES as $ancien ) {
            if ( ! get_role( $ancien ) ) {
                continue;
            }
            foreach ( get_users( array( 'role' => $ancien, 'fields' => 'all' ) ) as $user ) {
                $user->remove_role( $ancien );
                if ( ! $user->roles ) {
                    $user->add_role( 'subscriber' );
                }
            }
            remove_role( $ancien );
        }
        update_option( self::OPTION, self::ROLES_VERSION );
    }

    /** Titre, slogan, icône et page d'accueil du site : modifiables dans le Personnaliser par un administrateur Seliweb. */
    public static function personnaliser( $wp_customize ) {
        foreach ( self::REGLAGES_SITE as $id ) {
            $reglage = $wp_customize->get_setting( $id );
            if ( $reglage ) {
                $reglage->capability = Seliweb_Permissions::CAP_ADMIN;
            }
        }
    }

    /** Un rôle donne-t-il la gestion des comptes ou l'administration ? */
    public static function role_sensible( $role ) {
        $caps = (array) ( $role['capabilities'] ?? array() );
        foreach ( self::DROITS_SENSIBLES as $droit ) {
            if ( ! empty( $caps[ $droit ] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rôles attribuables : sans les rôles sensibles, sauf pour un administrateur WordPress ;
     * un administrateur Seliweb peut en plus nommer d'autres administrateurs Seliweb.
     */
    public static function roles_attribuables( $roles ) {
        if ( current_user_can( 'manage_options' ) ) {
            return $roles;
        }
        $coopter = current_user_can( Seliweb_Permissions::CAP_ADMIN );
        foreach ( $roles as $cle => $role ) {
            if ( $coopter && self::ROLE === $cle ) {
                continue;
            }
            if ( self::role_sensible( $role ) ) {
                unset( $roles[ $cle ] );
            }
        }
        return $roles;
    }

    /** Compte avec un droit sensible : ni modifié, ni supprimé, ni changé de rôle par un non-administrateur. */
    // Avertissements d'autres extensions qui prennent l'Administrateur
    // Seliweb pour un administrateur WordPress et lui réclament des droits
    // qu'il n'a volontairement pas. Retirés pour lui seul (un administrateur
    // WordPress continue de les voir). Liste à compléter au cas par cas,
    // jamais de filtre global : les autres avis peuvent être utiles.
    const AVIS_TIERS_MASQUES = array(
        // All-in-One WP Migration : « Your current profile role does not
        // have Export/Import capabilities enabled » (is_super_admin() est
        // vrai dès qu'on peut supprimer des comptes). Les droits
        // d'export/import ne doivent pas lui être donnés : une sauvegarde
        // contient toute la base, une importation remplace le site.
        array( 'Ai1wm_Main_Controller', 'missing_role_capability_notice' ),
    );

    public static function masquer_avis_tiers() {
        if ( current_user_can( 'manage_options' ) || ! current_user_can( Seliweb_Permissions::CAP_ADMIN ) ) return;
        global $wp_filter;
        $hook = current_filter();
        if ( empty( $wp_filter[ $hook ] ) ) return;
        foreach ( $wp_filter[ $hook ]->callbacks as $priorite => $callbacks ) {
            foreach ( $callbacks as $cb ) {
                $f = $cb['function'];
                if ( ! is_array( $f ) || count( $f ) !== 2 ) continue;
                $classe = is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0];
                foreach ( self::AVIS_TIERS_MASQUES as $cible ) {
                    if ( $classe === $cible[0] && $f[1] === $cible[1] ) {
                        remove_action( $hook, $f, $priorite );
                    }
                }
            }
        }
    }

    public static function proteger_comptes( $caps, $cap, $user_id, $args ) {
        if ( ! in_array( $cap, array( 'edit_user', 'delete_user', 'promote_user', 'remove_user' ), true ) || empty( $args[0] ) || (int) $args[0] === (int) $user_id ) {
            return $caps;
        }
        if ( user_can( (int) $user_id, 'manage_options' ) ) {
            return $caps;
        }
        $cible = get_userdata( (int) $args[0] );
        if ( ! $cible ) {
            return $caps;
        }
        foreach ( self::DROITS_SENSIBLES as $droit ) {
            if ( $cible->has_cap( $droit ) ) {
                $caps[] = 'do_not_allow';
                break;
            }
        }
        return $caps;
    }
}
