<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Branche le mécanisme de mise à jour natif de WordPress sur les dépôts GitHub.
 * Gère le plugin (leduigou/seliweb) et le thème (leduigou/Seliweb-view).
 */
class Seliweb_Updater {

    const GH_USER        = 'leduigou';
    const GH_REPO_PLUGIN = 'seliweb';
    const GH_REPO_THEME  = 'Seliweb-view';
    const PLUGIN_FILE    = 'seliweb/seliweb.php';
    const PLUGIN_SLUG    = 'seliweb';
    const THEME_SLUG     = 'seliweb-view';

    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_plugin_update' ) );
        add_filter( 'pre_set_site_transient_update_themes',  array( __CLASS__, 'check_theme_update'  ) );
        add_filter( 'plugins_api',                           array( __CLASS__, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_post_install',                 array( __CLASS__, 'post_install' ), 10, 3 );
    }

    // ----------------------------------------------------------------
    // Appel API GitHub — récupère la dernière release (avec cache 6 h)
    // ----------------------------------------------------------------
    private static function get_release( $repo ) {
        $transient_key = 'seliweb_gh_release_' . $repo;
        $cached = get_transient( $transient_key );
        if ( $cached !== false ) return $cached;

        $url      = 'https://api.github.com/repos/' . self::GH_USER . '/' . $repo . '/releases/latest';
        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            ),
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // En cas d'erreur, on ne cache pas pour réessayer au prochain cycle WP
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $body->tag_name ) ) return false;

        $release = array(
            'version'  => ltrim( $body->tag_name, 'v' ),
            'zip_url'  => $body->zipball_url ?? '',
            'page_url' => $body->html_url    ?? '',
            'notes'    => $body->body        ?? '',
        );

        set_transient( $transient_key, $release, 6 * HOUR_IN_SECONDS );
        return $release;
    }

    // ----------------------------------------------------------------
    // Plugin — injection dans le transient update_plugins
    // ----------------------------------------------------------------
    public static function check_plugin_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = self::get_release( self::GH_REPO_PLUGIN );
        if ( ! $release ) return $transient;

        if ( version_compare( $release['version'], SELIWEB_VERSION, '>' ) ) {
            $transient->response[ self::PLUGIN_FILE ] = (object) array(
                'slug'        => self::PLUGIN_SLUG,
                'plugin'      => self::PLUGIN_FILE,
                'new_version' => $release['version'],
                'url'         => $release['page_url'],
                'package'     => $release['zip_url'],
            );
        } else {
            // Indiquer explicitement qu'il n'y a pas de mise à jour (évite faux positifs)
            $transient->no_update[ self::PLUGIN_FILE ] = (object) array(
                'slug'        => self::PLUGIN_SLUG,
                'plugin'      => self::PLUGIN_FILE,
                'new_version' => $release['version'],
                'url'         => $release['page_url'],
                'package'     => '',
            );
        }

        return $transient;
    }

    // ----------------------------------------------------------------
    // Thème — injection dans le transient update_themes
    // ----------------------------------------------------------------
    public static function check_theme_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = self::get_release( self::GH_REPO_THEME );
        if ( ! $release ) return $transient;

        $theme          = wp_get_theme( self::THEME_SLUG );
        $current_version = $theme->get('Version');

        if ( version_compare( $release['version'], $current_version, '>' ) ) {
            $transient->response[ self::THEME_SLUG ] = array(
                'theme'       => self::THEME_SLUG,
                'new_version' => $release['version'],
                'url'         => $release['page_url'],
                'package'     => $release['zip_url'],
            );
        }

        return $transient;
    }

    // ----------------------------------------------------------------
    // Popup "Voir les détails" du plugin dans l'écran Extensions
    // ----------------------------------------------------------------
    public static function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ( $args->slug ?? '' ) !== self::PLUGIN_SLUG ) return $result;

        $release = self::get_release( self::GH_REPO_PLUGIN );
        if ( ! $release ) return $result;

        return (object) array(
            'name'          => 'Seliweb',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://github.com/' . self::GH_USER . '">Philippe Le Duigou</a>',
            'homepage'      => 'https://github.com/' . self::GH_USER . '/' . self::GH_REPO_PLUGIN,
            'requires'      => '6.0',
            'tested'        => get_bloginfo('version'),
            'sections'      => array(
                'description' => 'Plugin de gestion d\'un SEL (Système d\'Échange Local).',
                'changelog'   => '<pre>' . esc_html( $release['notes'] ) . '</pre>',
            ),
            'download_link' => $release['zip_url'],
        );
    }

    // ----------------------------------------------------------------
    // Post-installation : renomme le dossier extrait (GitHub l'appelle
    // leduigou-seliweb-{hash}/) en nom canonique (seliweb/ ou seliweb-view/)
    // ----------------------------------------------------------------
    public static function post_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        // Plugin
        if ( ( $hook_extra['plugin'] ?? '' ) === self::PLUGIN_FILE ) {
            $dest = WP_PLUGIN_DIR . '/' . self::PLUGIN_SLUG;
            $wp_filesystem->move( $result['destination'], $dest, true );
            $result['destination'] = $dest;
            delete_transient( 'seliweb_gh_release_' . self::GH_REPO_PLUGIN );
            if ( is_plugin_active( self::PLUGIN_FILE ) ) {
                activate_plugin( self::PLUGIN_FILE );
            }
            return $result;
        }

        // Thème
        if ( ( $hook_extra['theme'] ?? '' ) === self::THEME_SLUG ) {
            $dest = get_theme_root() . '/' . self::THEME_SLUG;
            $wp_filesystem->move( $result['destination'], $dest, true );
            $result['destination'] = $dest;
            delete_transient( 'seliweb_gh_release_' . self::GH_REPO_THEME );
            return $result;
        }

        return $result;
    }
}
