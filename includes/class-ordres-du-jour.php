<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ordres du jour du C.A. — onglet « Ordres du jour » du menu
 * C.A./Événements (page seliweb_evenements, &tab=odj).
 *
 * Un ordre du jour : date, titre, auteur (le membre connecté qui le crée),
 * note explicative (1000 caractères maximum), fichier joint facultatif.
 * Statut « à traiter » → « traité », avec la date du C.A. obligatoire.
 *
 * Droits (module « evenements », Paramètres → Gestionnaires) :
 *  - lecture      : consulter, imprimer, ouvrir les fichiers joints ;
 *  - modification : + modifier, cocher « traité » ;
 *  - création     : + créer.
 * Pas de suppression depuis l'écran : un ordre du jour se passe en
 * « traité » (décision produit, pour éviter les suppressions par erreur).
 *
 * Les fichiers joints sont rangés dans uploads/seliweb-odj/ sous un nom
 * aléatoire et ne sont servis qu'à travers admin-post.php (droit de
 * lecture vérifié).
 *
 * Table : seliweb_ordres_du_jour.
 */
class Seliweb_Ordres_Du_Jour {

    const NOTE_MAX = 1000;

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
        add_action( 'admin_post_seliweb_odj_fichier', array( __CLASS__, 'servir_fichier' ) );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seliweb_ordres_du_jour';
    }

    private static function base_url( $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => 'seliweb_evenements', 'tab' => 'odj' ), $args ), admin_url( 'admin.php' ) );
    }

    private static function dossier() {
        $up  = wp_upload_dir();
        $dir = trailingslashit( $up['basedir'] ) . 'seliweb-odj';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        // Pas d'accès direct (Apache) ni de listing ; sous nginx, le nom
        // aléatoire du fichier reste la protection.
        if ( ! file_exists( $dir . '/.htaccess' ) ) {
            @file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
        }
        if ( ! file_exists( $dir . '/index.php' ) ) {
            @file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
        }
        return $dir;
    }

    private static function nb_par_statut() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT statut, COUNT(*) nb FROM " . self::table() . " GROUP BY statut", OBJECT_K );
        $a = isset( $rows['a_traiter'] ) ? (int) $rows['a_traiter']->nb : 0;
        $t = isset( $rows['traite'] ) ? (int) $rows['traite']->nb : 0;
        return array( 'a_traiter' => $a, 'traite' => $t, 'tous' => $a + $t );
    }

    // ================================================================
    // ÉCRANS
    // ================================================================
    public static function display() {
        $action = sanitize_key( $_GET['action'] ?? '' );
        if ( 'new' === $action || 'edit' === $action ) {
            self::formulaire( (int) ( $_GET['id'] ?? 0 ) );
        } else {
            self::liste();
        }
    }

    private static function liste() {
        global $wpdb;
        $t = self::table();

        $msgs = array(
            'enregistre' => __( 'Ordre du jour enregistré.', 'seliweb' ),
            'traite'     => __( 'Ordre du jour marqué comme traité.', 'seliweb' ),
            'rouvert'    => __( 'Ordre du jour remis « à traiter ».', 'seliweb' ),
        );
        $msg = sanitize_key( $_GET['msg'] ?? '' );
        if ( isset( $msgs[ $msg ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msgs[ $msg ] ) . '</p></div>';
        }
        if ( ( $_GET['erreur'] ?? '' ) === 'date_ca' ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Saisissez la date du C.A. pour enregistrer cet ordre du jour comme traité.', 'seliweb' ) . '</p></div>';
        }

        $filtre = sanitize_key( $_GET['f'] ?? 'a_traiter' );
        if ( ! in_array( $filtre, array( 'a_traiter', 'traite', 'tous' ), true ) ) $filtre = 'a_traiter';

        // Tri par en-tête de colonne.
        $colonnes_tri = array(
            'date'    => 'o.date_odj',
            'titre'   => 'o.titre',
            'auteur'  => 'o.auteur_nom',
            'date_ca' => 'o.date_ca',
        );
        $orderby = sanitize_key( $_GET['orderby'] ?? 'date' );
        if ( ! isset( $colonnes_tri[ $orderby ] ) ) $orderby = 'date';
        $order = strtolower( $_GET['order'] ?? '' ) === 'desc' ? 'desc' : 'asc';

        $where = 'tous' === $filtre ? '1=1' : $wpdb->prepare( 'o.statut=%s', $filtre );
        $items = $wpdb->get_results(
            "SELECT o.* FROM $t o WHERE $where ORDER BY {$colonnes_tri[ $orderby ]} " . strtoupper( $order ) . ', o.id ' . strtoupper( $order )
        );
        $nb = self::nb_par_statut();

        $peut_creer    = Seliweb_Permissions::peut( 'evenements', 'creer' );
        $peut_modifier = Seliweb_Permissions::peut( 'evenements', 'modifier' );
        $fmt           = 'd/m/Y';

        $filtres = array(
            'a_traiter' => __( 'À traiter', 'seliweb' ),
            'traite'    => __( 'Traités', 'seliweb' ),
            'tous'      => __( 'Tous', 'seliweb' ),
        );

        $th_tri = function( $cle, $label, $style = '' ) use ( $orderby, $order, $filtre ) {
            $actif   = ( $orderby === $cle );
            $suivant = ( $actif && 'asc' === $order ) ? 'desc' : 'asc';
            $url     = self::base_url( array( 'f' => $filtre, 'orderby' => $cle, 'order' => $suivant ) );
            $classe  = $actif ? 'sorted ' . $order : 'sortable asc';
            echo '<th scope="col" class="manage-column ' . esc_attr( $classe ) . '"' . ( $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>'
                . '<a href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a></th>';
        };
        ?>
        <?php if ( $peut_creer ) : ?>
            <a href="<?php echo esc_url( self::base_url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action seliweb-no-print" style="margin:0 0 8px;display:inline-block;"><?php esc_html_e( 'Ajouter un ordre du jour', 'seliweb' ); ?></a>
        <?php endif; ?>

        <div class="seliweb-no-print" style="display:flex;flex-wrap:wrap;align-items:center;gap:16px;margin:8px 0;">
            <ul class="subsubsub" style="margin:0;">
                <?php $i = 0; foreach ( $filtres as $cle => $label ) : $i++; ?>
                    <li><a href="<?php echo esc_url( self::base_url( array( 'f' => $cle, 'orderby' => $orderby, 'order' => $order ) ) ); ?>" class="<?php echo $filtre === $cle ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) $nb[ $cle ]; ?>)</span></a><?php echo $i < count( $filtres ) ? ' |' : ''; ?></li>
                <?php endforeach; ?>
            </ul>
            <span style="margin-left:auto;display:flex;align-items:center;gap:10px;">
                <label><input type="checkbox" id="odj-avec-notes"> <?php esc_html_e( 'Imprimer avec les notes', 'seliweb' ); ?></label>
                <button type="button" class="button" onclick="odjImprimer()"><span class="dashicons dashicons-printer" style="vertical-align:text-bottom;"></span> <?php esc_html_e( 'Imprimer la liste', 'seliweb' ); ?></button>
            </span>
        </div>

        <table class="wp-list-table widefat fixed striped seliweb-no-print" style="margin-top:8px;">
            <thead><tr>
                <?php $th_tri( 'date', __( 'Date', 'seliweb' ), 'width:95px;' ); ?>
                <?php $th_tri( 'titre', __( 'Titre', 'seliweb' ) ); ?>
                <?php $th_tri( 'auteur', __( 'Auteur', 'seliweb' ), 'width:150px;' ); ?>
                <th><?php esc_html_e( 'Note explicative', 'seliweb' ); ?></th>
                <th style="width:140px;"><?php esc_html_e( 'Fichier', 'seliweb' ); ?></th>
                <?php $th_tri( 'date_ca', __( 'Date du C.A.', 'seliweb' ), 'width:110px;' ); ?>
                <th style="width:200px;"><?php esc_html_e( 'Traité', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( ! $items ) : ?>
                <tr><td colspan="7"><em><?php esc_html_e( 'Aucun ordre du jour.', 'seliweb' ); ?></em></td></tr>
            <?php else : foreach ( $items as $o ) :
                $oid    = (int) $o->id;
                $traite = ( 'traite' === $o->statut );
                ?>
                <tr>
                    <td><?php echo esc_html( mysql2date( $fmt, $o->date_odj ) ); ?></td>
                    <td>
                        <strong><?php echo esc_html( $o->titre ); ?></strong>
                        <div class="row-actions" style="position:static;">
                            <?php if ( $peut_modifier ) : ?>
                                <a href="<?php echo esc_url( self::base_url( array( 'action' => 'edit', 'id' => $oid ) ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?php echo esc_html( $o->auteur_nom ); ?></td>
                    <td style="font-size:12px;white-space:pre-line;"><?php echo esc_html( $o->note ); ?></td>
                    <td><?php if ( $o->fichier_nom ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=seliweb_odj_fichier&id=' . $oid ) ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-paperclip" style="font-size:16px;"></span> <?php echo esc_html( $o->fichier_nom ); ?></a>
                    <?php else : ?>—<?php endif; ?></td>
                    <td><?php echo $o->date_ca ? esc_html( mysql2date( $fmt, $o->date_ca ) ) : '—'; ?></td>
                    <td>
                        <?php if ( $peut_modifier ) : ?>
                            <form method="post" style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;" onsubmit="return odjValider(this);">
                                <?php wp_nonce_field( 'seliweb_odj_statut_' . $oid, 'seliweb_odj_nonce' ); ?>
                                <input type="hidden" name="odj_action" value="statut">
                                <input type="hidden" name="id" value="<?php echo $oid; ?>">
                                <input type="hidden" name="etait_traite" value="<?php echo $traite ? 1 : 0; ?>">
                                <label title="<?php esc_attr_e( 'Traité', 'seliweb' ); ?>">
                                    <input type="checkbox" name="traite" value="1" <?php checked( $traite ); ?> onchange="odjCocher(this);">
                                </label>
                                <span class="odj-date-ca" style="<?php echo $traite ? '' : 'display:none;'; ?>">
                                    <input type="date" name="date_ca" value="<?php echo esc_attr( $o->date_ca ); ?>" style="width:130px;" aria-label="<?php esc_attr_e( 'Date du C.A.', 'seliweb' ); ?>">
                                </span>
                                <button type="submit" class="button button-small odj-ok" style="display:none;"><?php esc_html_e( 'Enregistrer', 'seliweb' ); ?></button>
                            </form>
                        <?php else : ?>
                            <?php echo $traite ? '<span class="dashicons dashicons-yes" style="color:#1d6a4a;"></span>' : ''; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php // Version imprimable : date, auteur, titre (+ note si demandé). ?>
        <div class="odj-print">
            <h2 style="margin:0 0 4px;"><?php echo esc_html( sprintf( __( 'Ordres du jour du C.A. — %s', 'seliweb' ), $filtres[ $filtre ] ) ); ?></h2>
            <p style="margin:0 0 14px;color:#333;"><?php echo esc_html( get_bloginfo( 'name' ) . ' — ' . sprintf( __( 'imprimé le %s', 'seliweb' ), wp_date( $fmt ) ) ); ?></p>
            <table class="odj-print-table">
                <thead><tr>
                    <th style="width:80px;"><?php esc_html_e( 'Date', 'seliweb' ); ?></th>
                    <th style="width:150px;"><?php esc_html_e( 'Auteur', 'seliweb' ); ?></th>
                    <th><?php esc_html_e( 'Titre', 'seliweb' ); ?></th>
                    <?php if ( 'a_traiter' !== $filtre ) : ?><th style="width:90px;"><?php esc_html_e( 'Date du C.A.', 'seliweb' ); ?></th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ( $items as $o ) : ?>
                    <tr>
                        <td><?php echo esc_html( mysql2date( $fmt, $o->date_odj ) ); ?></td>
                        <td><?php echo esc_html( $o->auteur_nom ); ?></td>
                        <td><strong><?php echo esc_html( $o->titre ); ?></strong>
                            <?php if ( $o->note ) : ?><div class="odj-print-note"><?php echo nl2br( esc_html( $o->note ) ); ?></div><?php endif; ?>
                            <?php if ( $o->fichier_nom ) : ?><div class="odj-print-note" style="font-style:italic;"><?php echo esc_html( sprintf( __( 'Fichier joint : %s', 'seliweb' ), $o->fichier_nom ) ); ?></div><?php endif; ?>
                        </td>
                        <?php if ( 'a_traiter' !== $filtre ) : ?><td><?php echo $o->date_ca ? esc_html( mysql2date( $fmt, $o->date_ca ) ) : '—'; ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <style>
        .odj-print { display:none; }
        @media print {
            .odj-print { display:block; }
            .odj-print-table { width:100%; border-collapse:collapse; font-size:12px; }
            .odj-print-table th, .odj-print-table td { border:1px solid #999; padding:5px 7px; text-align:left; vertical-align:top; }
            .odj-print-table th { background:#eee; }
            .odj-print-note { display:none; margin-top:4px; font-size:11px; color:#333; }
            body.odj-avec-notes .odj-print-note { display:block; }
            .wrap > h1, .wrap > .nav-tab-wrapper, .wrap > hr { display:none !important; }
        }
        </style>
        <script>
        function odjImprimer() {
            document.body.classList.toggle('odj-avec-notes', document.getElementById('odj-avec-notes').checked);
            window.print();
        }
        function odjCocher(cb) {
            var f = cb.form;
            f.querySelector('.odj-date-ca').style.display = cb.checked ? '' : 'none';
            f.querySelector('.odj-ok').style.display = '';
            if (cb.checked) { f.querySelector('input[name="date_ca"]').focus(); }
        }
        function odjValider(f) {
            var coche = f.querySelector('input[name="traite"]').checked;
            if (coche && !f.querySelector('input[name="date_ca"]').value) {
                alert(<?php echo wp_json_encode( __( 'Saisissez la date du C.A. pour enregistrer cet ordre du jour comme traité.', 'seliweb' ) ); ?>);
                return false;
            }
            if (!coche && f.querySelector('input[name="etait_traite"]').value === '1') {
                return confirm(<?php echo wp_json_encode( __( 'Remettre cet ordre du jour « à traiter » ? La date du C.A. sera effacée.', 'seliweb' ) ); ?>);
            }
            return true;
        }
        </script>
        <?php
    }

    private static function formulaire( $id ) {
        global $wpdb;
        $o = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id=%d", $id ) ) : null;
        $droit = $o ? 'modifier' : 'creer';
        if ( ! Seliweb_Permissions::peut( 'evenements', $droit ) ) {
            echo '<p>' . esc_html__( "Vous n'avez pas les droits pour cette action.", 'seliweb' ) . '</p>';
            return;
        }
        if ( $id && ! $o ) {
            echo '<p>' . esc_html__( 'Ordre du jour introuvable.', 'seliweb' ) . '</p>';
            return;
        }

        $erreurs = array(
            'champs'  => __( 'La date et le titre sont obligatoires.', 'seliweb' ),
            'note'    => sprintf( __( 'La note explicative est limitée à %d caractères.', 'seliweb' ), self::NOTE_MAX ),
            'fichier' => __( "Le fichier n'a pas pu être joint (type non autorisé ou fichier trop lourd).", 'seliweb' ),
            'date_ca' => __( 'Saisissez la date du C.A. pour enregistrer cet ordre du jour comme traité.', 'seliweb' ),
        );
        $err = sanitize_key( $_GET['erreur'] ?? '' );
        if ( isset( $erreurs[ $err ] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $erreurs[ $err ] ) . '</p></div>';
        }

        $auteur = $o ? $o->auteur_nom : wp_get_current_user()->display_name;
        $note   = $o ? (string) $o->note : '';
        ?>
        <h2><?php echo esc_html( $o ? __( "Modifier l'ordre du jour", 'seliweb' ) : __( 'Nouvel ordre du jour', 'seliweb' ) ); ?></h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'seliweb_odj_enregistrer', 'seliweb_odj_nonce' ); ?>
            <input type="hidden" name="odj_action" value="enregistrer">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
            <table class="form-table">
                <tr>
                    <th><label for="odj-date"><?php esc_html_e( 'Date', 'seliweb' ); ?> <span style="color:#b32d2e;">*</span></label></th>
                    <td><input type="date" id="odj-date" name="date_odj" required value="<?php echo esc_attr( $o ? $o->date_odj : wp_date( 'Y-m-d' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="odj-titre"><?php esc_html_e( 'Titre', 'seliweb' ); ?> <span style="color:#b32d2e;">*</span></label></th>
                    <td><input type="text" id="odj-titre" name="titre" class="large-text" maxlength="255" required value="<?php echo esc_attr( $o ? $o->titre : '' ); ?>"></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Auteur', 'seliweb' ); ?></th>
                    <td><strong><?php echo esc_html( $auteur ); ?></strong>
                        <?php if ( ! $o ) : ?><p class="description"><?php esc_html_e( "L'auteur est la personne connectée qui crée l'ordre du jour.", 'seliweb' ); ?></p><?php endif; ?></td>
                </tr>
                <tr>
                    <th><label for="odj-note"><?php esc_html_e( 'Note explicative', 'seliweb' ); ?></label></th>
                    <td>
                        <textarea id="odj-note" name="note" class="large-text" rows="6" maxlength="<?php echo (int) self::NOTE_MAX; ?>"
                                  oninput="document.getElementById('odj-note-nb').textContent=this.value.length;"><?php echo esc_textarea( $note ); ?></textarea>
                        <p class="description"><span id="odj-note-nb"><?php echo (int) mb_strlen( $note ); ?></span> / <?php echo (int) self::NOTE_MAX; ?> <?php esc_html_e( 'caractères', 'seliweb' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="odj-fichier"><?php esc_html_e( 'Fichier joint', 'seliweb' ); ?></label></th>
                    <td>
                        <?php if ( $o && $o->fichier_nom ) : ?>
                            <p style="margin-top:0;">
                                <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=seliweb_odj_fichier&id=' . (int) $o->id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $o->fichier_nom ); ?></a>
                                &nbsp; <label><input type="checkbox" name="retirer_fichier" value="1"> <?php esc_html_e( 'Retirer ce fichier', 'seliweb' ); ?></label>
                            </p>
                        <?php endif; ?>
                        <input type="file" id="odj-fichier" name="fichier">
                        <p class="description"><?php printf(
                            esc_html__( 'Facultatif. Taille maximale : %s. Un nouveau fichier remplace le précédent.', 'seliweb' ),
                            esc_html( size_format( wp_max_upload_size() ) )
                        ); ?></p>
                    </td>
                </tr>
                <?php if ( $o ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Statut', 'seliweb' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="traite" value="1" <?php checked( 'traite', $o->statut ); ?>> <?php esc_html_e( 'Traité', 'seliweb' ); ?></label>
                        &nbsp; <label><?php esc_html_e( 'Date du C.A.', 'seliweb' ); ?> <input type="date" name="date_ca" value="<?php echo esc_attr( $o->date_ca ); ?>"></label>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            <?php submit_button( __( 'Enregistrer', 'seliweb' ), 'primary', 'submit', false ); ?>
            &nbsp; <a href="<?php echo esc_url( self::base_url() ); ?>" class="button"><?php esc_html_e( 'Annuler', 'seliweb' ); ?></a>
        </form>
        <?php
    }

    // ================================================================
    // TRAITEMENTS
    // ================================================================
    public static function handle_post() {
        if ( ( $_GET['page'] ?? '' ) !== 'seliweb_evenements' || ( $_GET['tab'] ?? '' ) !== 'odj' ) return;

        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) return;
        $action = sanitize_key( $_POST['odj_action'] ?? '' );

        if ( 'statut' === $action ) {
            $id = (int) ( $_POST['id'] ?? 0 );
            check_admin_referer( 'seliweb_odj_statut_' . $id, 'seliweb_odj_nonce' );
            if ( ! Seliweb_Permissions::peut( 'evenements', 'modifier' ) ) wp_die( esc_html__( 'Accès refusé.', 'seliweb' ) );
            $retour = wp_get_referer() ?: self::base_url();
            $retour = remove_query_arg( array( 'msg', 'erreur' ), $retour );
            if ( ! empty( $_POST['traite'] ) ) {
                $date_ca = self::date_valide( $_POST['date_ca'] ?? '' );
                if ( ! $date_ca ) {
                    wp_safe_redirect( add_query_arg( 'erreur', 'date_ca', $retour ) );
                    exit;
                }
                self::maj( $id, array( 'statut' => 'traite', 'date_ca' => $date_ca ) );
                wp_safe_redirect( add_query_arg( 'msg', 'traite', $retour ) );
            } else {
                self::maj( $id, array( 'statut' => 'a_traiter', 'date_ca' => null ) );
                wp_safe_redirect( add_query_arg( 'msg', 'rouvert', $retour ) );
            }
            exit;
        }

        if ( 'enregistrer' === $action ) {
            check_admin_referer( 'seliweb_odj_enregistrer', 'seliweb_odj_nonce' );
            global $wpdb;
            $id = (int) ( $_POST['id'] ?? 0 );
            $o  = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id=%d", $id ) ) : null;
            if ( $id && ! $o ) wp_die( esc_html__( 'Ordre du jour introuvable.', 'seliweb' ) );
            if ( ! Seliweb_Permissions::peut( 'evenements', $o ? 'modifier' : 'creer' ) ) wp_die( esc_html__( 'Accès refusé.', 'seliweb' ) );

            $retour_form = self::base_url( $o ? array( 'action' => 'edit', 'id' => $id ) : array( 'action' => 'new' ) );
            $date  = self::date_valide( $_POST['date_odj'] ?? '' );
            $titre = sanitize_text_field( wp_unslash( $_POST['titre'] ?? '' ) );
            $note  = sanitize_textarea_field( str_replace( "\r\n", "\n", wp_unslash( $_POST['note'] ?? '' ) ) );
            if ( ! $date || '' === $titre ) {
                wp_safe_redirect( add_query_arg( 'erreur', 'champs', $retour_form ) );
                exit;
            }
            if ( mb_strlen( $note ) > self::NOTE_MAX ) {
                wp_safe_redirect( add_query_arg( 'erreur', 'note', $retour_form ) );
                exit;
            }

            $data = array(
                'date_odj'    => $date,
                'titre'       => mb_substr( $titre, 0, 255 ),
                'note'        => $note,
                'date_modif'  => current_time( 'mysql' ),
            );

            if ( $o ) {
                if ( ! empty( $_POST['traite'] ) ) {
                    $date_ca = self::date_valide( $_POST['date_ca'] ?? '' );
                    if ( ! $date_ca ) {
                        wp_safe_redirect( add_query_arg( 'erreur', 'date_ca', $retour_form ) );
                        exit;
                    }
                    $data['statut']  = 'traite';
                    $data['date_ca'] = $date_ca;
                } else {
                    $data['statut']  = 'a_traiter';
                    $data['date_ca'] = null;
                }
            }

            // Fichier joint.
            $nouveau = null;
            if ( ! empty( $_FILES['fichier']['name'] ) ) {
                $nouveau = self::recevoir_fichier( $_FILES['fichier'] );
                if ( ! $nouveau ) {
                    wp_safe_redirect( add_query_arg( 'erreur', 'fichier', $retour_form ) );
                    exit;
                }
            }
            if ( $nouveau || ( $o && ! empty( $_POST['retirer_fichier'] ) ) ) {
                if ( $o && $o->fichier_chemin ) self::effacer_fichier( $o->fichier_chemin );
                $data['fichier_chemin'] = $nouveau ? $nouveau['chemin'] : null;
                $data['fichier_nom']    = $nouveau ? $nouveau['nom'] : null;
            }

            if ( $o ) {
                $wpdb->update( self::table(), $data, array( 'id' => $id ) );
                $filtre = $data['statut'];
            } else {
                $user = wp_get_current_user();
                $data['auteur_id']     = (int) $user->ID;
                $data['auteur_nom']    = $user->display_name;
                $data['statut']        = 'a_traiter';
                $data['date_creation'] = current_time( 'mysql' );
                $wpdb->insert( self::table(), $data );
                $filtre = 'a_traiter';
            }
            wp_safe_redirect( self::base_url( array( 'f' => $filtre, 'msg' => 'enregistre' ) ) );
            exit;
        }
    }

    private static function maj( $id, $data ) {
        global $wpdb;
        $data['date_modif'] = current_time( 'mysql' );
        $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
    }

    private static function date_valide( $brut ) {
        $brut = sanitize_text_field( wp_unslash( (string) $brut ) );
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $brut, $m ) ) return '';
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $brut : '';
    }

    // Retourne array( chemin, nom ) ou null. Types autorisés : ceux de
    // WordPress (PDF, documents bureautiques, images…).
    private static function recevoir_fichier( $f ) {
        if ( ! empty( $f['error'] ) || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) return null;
        if ( (int) $f['size'] > wp_max_upload_size() ) return null;
        $nom   = sanitize_file_name( wp_unslash( $f['name'] ) );
        $check = wp_check_filetype_and_ext( $f['tmp_name'], $nom );
        if ( empty( $check['ext'] ) || empty( $check['type'] ) ) return null;
        if ( ! empty( $check['proper_filename'] ) ) $nom = $check['proper_filename'];

        $chemin = wp_generate_password( 20, false, false ) . '.' . $check['ext'];
        if ( ! @move_uploaded_file( $f['tmp_name'], self::dossier() . '/' . $chemin ) ) return null;
        return array( 'chemin' => $chemin, 'nom' => $nom );
    }

    private static function effacer_fichier( $chemin ) {
        $chemin = basename( (string) $chemin );
        if ( ! $chemin ) return;
        $plein = self::dossier() . '/' . $chemin;
        if ( is_file( $plein ) ) wp_delete_file( $plein );
    }

    public static function servir_fichier() {
        if ( ! Seliweb_Permissions::peut( 'evenements', 'lire' ) ) wp_die( esc_html__( 'Accès refusé.', 'seliweb' ), '', array( 'response' => 403 ) );
        global $wpdb;
        $o = $wpdb->get_row( $wpdb->prepare( "SELECT fichier_chemin, fichier_nom FROM " . self::table() . " WHERE id=%d", (int) ( $_GET['id'] ?? 0 ) ) );
        $plein = $o && $o->fichier_chemin ? self::dossier() . '/' . basename( $o->fichier_chemin ) : '';
        if ( ! $plein || ! is_file( $plein ) ) wp_die( esc_html__( 'Fichier introuvable.', 'seliweb' ), '', array( 'response' => 404 ) );

        $type = wp_check_filetype( $plein );
        nocache_headers();
        header( 'Content-Type: ' . ( $type['type'] ?: 'application/octet-stream' ) );
        header( 'Content-Disposition: inline; filename="' . str_replace( '"', '', $o->fichier_nom ) . '"' );
        header( 'Content-Length: ' . filesize( $plein ) );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $plein );
        exit;
    }
}
