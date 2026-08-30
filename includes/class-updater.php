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
    const PLUGIN_SLUG    = 'seliweb';
    const THEME_SLUG     = 'seliweb-view';

    // Chemin réel du plugin tel qu'installé sur CE site (dossier/fichier.php).
    // Ne pas coder en dur "seliweb/seliweb.php" : si le dossier a été
    // installé sous un autre nom (ex. zip GitHub téléchargé avant la mise
    // en place de cet updater), WordPress ne reconnaît jamais la mise à
    // jour détectée comme concernant l'extension réellement active — les
    // mises à jour ne s'affichent ni ne s'appliquent alors jamais.
    private static function plugin_file() {
        return defined( 'SELIWEB_PLUGIN_FILE' ) ? SELIWEB_PLUGIN_FILE : self::PLUGIN_SLUG . '/seliweb.php';
    }

    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_plugin_update' ) );
        add_filter( 'pre_set_site_transient_update_themes',  array( __CLASS__, 'check_theme_update'  ) );
        add_filter( 'plugins_api',                           array( __CLASS__, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_post_install',                 array( __CLASS__, 'post_install' ), 10, 3 );

        // Le bouton "Vérifier à nouveau" de WordPress vide les transients
        // update_plugins / update_themes — on en profite pour vider aussi
        // notre propre cache de 6h, sinon un "force-check" peut continuer
        // à renvoyer une ancienne réponse GitHub pendant jusqu'à 6h.
        add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'clear_plugin_release_cache' ) );
        add_action( 'delete_site_transient_update_themes',  array( __CLASS__, 'clear_theme_release_cache'  ) );

        // Page "Mises à jour" Seliweb (hors menu, atteinte par le bouton du
        // Tableau de bord) + le traitement de son bouton "vérifier maintenant".
        add_action( 'admin_menu', array( __CLASS__, 'register_updates_page' ), 20 );
        add_action( 'admin_post_seliweb_force_update_check', array( __CLASS__, 'handle_force_check' ) );
    }

    public static function clear_plugin_release_cache() {
        delete_transient( 'seliweb_gh_release_' . self::GH_REPO_PLUGIN );
    }

    public static function clear_theme_release_cache() {
        delete_transient( 'seliweb_gh_release_' . self::GH_REPO_THEME );
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

        // Préférer le zip joint à la release (construit par build-zip.sh, avec le
        // bon nom de dossier à la racine) plutôt que l'archive source générée
        // automatiquement par GitHub (leduigou-seliweb-{hash}/, qui casse la
        // détection de mise à jour et l'installation manuelle — cf. Seliweb_Updater).
        $zip_url = ( ! empty( $body->assets[0]->browser_download_url ) )
            ? $body->assets[0]->browser_download_url
            : ( $body->zipball_url ?? '' );

        $release = array(
            'version'  => ltrim( $body->tag_name, 'v' ),
            'zip_url'  => $zip_url,
            'page_url' => $body->html_url ?? '',
            'notes'    => $body->body     ?? '',
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

        $plugin_file = self::plugin_file();
        if ( version_compare( $release['version'], SELIWEB_VERSION, '>' ) ) {
            $transient->response[ $plugin_file ] = (object) array(
                'slug'        => self::PLUGIN_SLUG,
                'plugin'      => $plugin_file,
                'new_version' => $release['version'],
                'url'         => $release['page_url'],
                'package'     => $release['zip_url'],
            );
        } else {
            // Indiquer explicitement qu'il n'y a pas de mise à jour (évite faux positifs)
            $transient->no_update[ $plugin_file ] = (object) array(
                'slug'        => self::PLUGIN_SLUG,
                'plugin'      => $plugin_file,
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
    // Post-installation : garantit que le dossier porte le nom canonique
    // (seliweb/ ou seliweb-view/).
    //
    // Le zip joint à la release (build-zip.sh) a déjà le bon nom de dossier
    // à la racine : WordPress installe donc directement au bon endroit et il
    // n'y a RIEN à faire. On ne renomme que si le dossier extrait porte un
    // autre nom — cas de l'archive source auto-générée par GitHub
    // (leduigou-<repo>-<hash>/).
    //
    // Important : ne jamais appeler $wp_filesystem->move() quand la source
    // est déjà la destination. move($x, $x, true) supprime d'abord $x puis
    // échoue à le renommer → le dossier (thème ou extension) disparaît.
    // C'est ce qui effaçait le thème/l'extension à chaque mise à jour.
    // ----------------------------------------------------------------
    public static function post_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        // Plugin
        $plugin_file_avant = self::plugin_file();
        if ( ( $hook_extra['plugin'] ?? '' ) === $plugin_file_avant ) {
            $dest = WP_PLUGIN_DIR . '/' . self::PLUGIN_SLUG;
            if ( self::needs_rename( $result['destination'], self::PLUGIN_SLUG ) ) {
                $etait_actif = is_plugin_active( $plugin_file_avant );
                $wp_filesystem->move( $result['destination'], $dest, true );
                $result['destination']        = $dest;
                $result['destination_name']   = self::PLUGIN_SLUG;
                $result['remote_destination']  = $dest;
                // Le dossier vient d'être renommé : réactiver sous le nouveau
                // chemin, pas l'ancien (qui n'existe plus).
                if ( $etait_actif ) {
                    activate_plugin( self::PLUGIN_SLUG . '/seliweb.php' );
                }
            }
            delete_transient( 'seliweb_gh_release_' . self::GH_REPO_PLUGIN );
            return $result;
        }

        // Thème
        if ( ( $hook_extra['theme'] ?? '' ) === self::THEME_SLUG ) {
            $dest = get_theme_root() . '/' . self::THEME_SLUG;
            if ( self::needs_rename( $result['destination'], self::THEME_SLUG ) ) {
                $wp_filesystem->move( $result['destination'], $dest, true );
                $result['destination']        = $dest;
                $result['destination_name']   = self::THEME_SLUG;
                $result['remote_destination']  = $dest;
            }
            delete_transient( 'seliweb_gh_release_' . self::GH_REPO_THEME );
            return $result;
        }

        return $result;
    }

    // Le dossier fraîchement installé doit-il être renommé en nom canonique ?
    // Non si WordPress l'a déjà posé au bon nom (zip de release).
    private static function needs_rename( $destination, $slug ) {
        return basename( untrailingslashit( (string) $destination ) ) !== $slug;
    }

    // ================================================================
    // PAGE "MISES À JOUR" SELIWEB
    //
    // Hors du menu Seliweb (on y accède par le bouton du Tableau de bord).
    // Enregistrée avec un parent vide : la page reste atteignable par son
    // URL admin.php?page=seliweb_updates sans apparaître dans aucun menu.
    // ================================================================
    public static function updates_page_url() {
        return admin_url( 'admin.php?page=seliweb_updates' );
    }

    public static function register_updates_page() {
        add_submenu_page(
            '', // pas de parent -> page routable mais absente du menu
            __( 'Mises à jour Seliweb', 'seliweb' ),
            __( 'Mises à jour Seliweb', 'seliweb' ),
            'manage_options',
            'seliweb_updates',
            array( __CLASS__, 'render_updates_page' )
        );
    }

    public static function handle_force_check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'seliweb' ) );
        }
        check_admin_referer( 'seliweb_force_update_check' );

        // Vide les transients WP (ce qui, via nos hooks delete_site_transient_*,
        // vide aussi notre cache GitHub de 6 h), puis relance la détection.
        delete_site_transient( 'update_plugins' );
        delete_site_transient( 'update_themes' );
        wp_update_plugins();
        wp_update_themes();

        wp_safe_redirect( add_query_arg( 'checked', '1', self::updates_page_url() ) );
        exit;
    }

    public static function render_updates_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_GET['checked'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Vérification effectuée.', 'seliweb' ) . '</p></div>';
        }

        $plugin_release = self::get_release( self::GH_REPO_PLUGIN );
        $theme_release  = self::get_release( self::GH_REPO_THEME );
        $theme_obj      = wp_get_theme( self::THEME_SLUG );

        $lignes = array(
            array(
                'nom'       => __( 'Extension Seliweb', 'seliweb' ),
                'installee' => defined( 'SELIWEB_VERSION' ) ? SELIWEB_VERSION : '?',
                'dispo'     => $plugin_release ? $plugin_release['version'] : null,
                'page'      => $plugin_release['page_url'] ?? '',
            ),
            array(
                'nom'       => __( 'Thème Seliweb View', 'seliweb' ),
                'installee' => $theme_obj->exists() ? $theme_obj->get( 'Version' ) : '—',
                'dispo'     => $theme_release ? $theme_release['version'] : null,
                'page'      => $theme_release['page_url'] ?? '',
            ),
        );
        $maj_dispo = false;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Mises à jour Seliweb', 'seliweb' ); ?></h1>

            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb' ) ); ?>">
                    &larr; <?php esc_html_e( 'Retour au tableau de bord Seliweb', 'seliweb' ); ?>
                </a>
            </p>

            <table class="widefat striped" style="max-width:760px;margin-top:16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Composant', 'seliweb' ); ?></th>
                        <th><?php esc_html_e( 'Version installée', 'seliweb' ); ?></th>
                        <th><?php esc_html_e( 'Dernière version', 'seliweb' ); ?></th>
                        <th><?php esc_html_e( 'État', 'seliweb' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $lignes as $l ) :
                    $a_jour = ( $l['dispo'] && '?' !== $l['installee'] && '—' !== $l['installee'] )
                        ? ! version_compare( $l['dispo'], $l['installee'], '>' )
                        : null;
                    if ( false === $a_jour ) { $maj_dispo = true; }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $l['nom'] ); ?></strong></td>
                        <td><?php echo esc_html( $l['installee'] ); ?></td>
                        <td>
                            <?php if ( $l['dispo'] ) : ?>
                                <?php echo $l['page']
                                    ? '<a href="' . esc_url( $l['page'] ) . '" target="_blank" rel="noopener">' . esc_html( $l['dispo'] ) . '</a>'
                                    : esc_html( $l['dispo'] ); ?>
                            <?php else : ?>
                                <em><?php esc_html_e( 'GitHub injoignable', 'seliweb' ); ?></em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            if ( null === $a_jour ) {
                                echo '&mdash;';
                            } elseif ( false === $a_jour ) {
                                echo '<span style="color:#b32d2e;font-weight:600;">'
                                    . esc_html__( 'Mise à jour disponible', 'seliweb' ) . '</span>';
                            } else {
                                echo '<span style="color:#1d6a4a;">'
                                    . esc_html__( 'À jour', 'seliweb' ) . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <a href="<?php echo esc_url( wp_nonce_url(
                        admin_url( 'admin-post.php?action=seliweb_force_update_check' ),
                        'seliweb_force_update_check'
                    ) ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Rechercher les mises à jour maintenant', 'seliweb' ); ?>
                </a>
                <?php if ( $maj_dispo ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>" class="button">
                        <?php esc_html_e( 'Aller à l\'écran de mise à jour de WordPress', 'seliweb' ); ?>
                    </a>
                <?php endif; ?>
            </p>

            <?php if ( ! $plugin_release || ! $theme_release ) : ?>
                <p class="description" style="margin-top:12px;max-width:760px;color:#b32d2e;">
                    <?php esc_html_e( "GitHub n'a pas répondu pour l'un des composants. L'API GitHub est limitée à 60 requêtes par heure et par adresse IP : patientez quelques minutes avant de réessayer.", 'seliweb' ); ?>
                </p>
            <?php endif; ?>

            <p class="description" style="margin-top:12px;max-width:760px;">
                <?php esc_html_e( "La détection des mises à jour est mise en cache 6 h ; ce bouton force un contrôle immédiat. Quand une mise à jour est disponible, elle s'applique depuis l'écran Mises à jour de WordPress.", 'seliweb' ); ?>
            </p>
        </div>
        <?php
    }
}
