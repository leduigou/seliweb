<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Événements — Phase 1 : création en back-office + liste publique.
 *
 * Visibilité et inscription sont indépendantes :
 *  - « visible par tous » : l'événement apparaît sur la page publique
 *    (titre + présentation au minimum ; lieu/adresse et horaires optionnels) ;
 *  - « groupes » (liste d'IDs) : détermineront QUI peut s'inscrire (phase 2).
 *    Un événement peut être public ET réservé à des groupes pour l'inscription.
 *
 * Table : seliweb_evenements.
 */
class Seliweb_Evenements {

    public static function init() {
        add_shortcode( 'seliweb_evenements', array( __CLASS__, 'shortcode_liste' ) );
        add_action( 'init', array( __CLASS__, 'handle_post' ) );
        add_action( 'init', array( __CLASS__, 'handle_delete' ) );
        add_action( 'init', array( __CLASS__, 'handle_inscription' ) );
        add_action( 'admin_post_seliweb_evt_csv', array( __CLASS__, 'handle_csv' ) );
    }

    private static function table_inscr() {
        global $wpdb;
        return $wpdb->prefix . 'seliweb_evenement_inscriptions';
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seliweb_evenements';
    }

    // Normalise une valeur d'input datetime-local (« 2026-09-15T18:30 » ou
    // « 2026-09-15 18:30 ») en « Y-m-d H:i:s ». Chaîne vide si invalide.
    private static function parse_datetime( $raw ) {
        $raw = trim( (string) wp_unslash( $raw ) );
        if ( $raw === '' ) return '';
        if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})/', $raw, $m ) ) {
            return $m[1] . ' ' . $m[2] . ':' . $m[3] . ':00';
        }
        return '';
    }

    // « Y-m-d H:i:s » -> « Y-m-dTH:i » pour re-remplir un champ datetime-local.
    private static function to_input( $mysql ) {
        if ( ! $mysql || $mysql === '0000-00-00 00:00:00' ) return '';
        return substr( str_replace( ' ', 'T', $mysql ), 0, 16 );
    }

    private static function groupes_ids( $evt ) {
        return ( $evt && $evt->groupes !== null && $evt->groupes !== '' )
            ? array_map( 'intval', explode( ',', $evt->groupes ) )
            : array();
    }

    // Seuil « à venir / passé » : minuit du jour courant. Un événement
    // programmé plus tôt aujourd'hui reste « à venir » jusqu'à la fin de
    // la journée (sinon il « disparaît » des listes en cours de journée).
    private static function cutoff_jour() {
        return current_time( 'Y-m-d' ) . ' 00:00:00';
    }

    // ---- Questions personnalisées (phase 3) -------------------------
    const Q_TYPES = array( 'texte', 'nombre', 'oui_non', 'choix', 'horaire' );

    private static function table_questions() {
        global $wpdb;
        return $wpdb->prefix . 'seliweb_evenement_questions';
    }

    private static function table_reponses() {
        global $wpdb;
        return $wpdb->prefix . 'seliweb_evenement_reponses';
    }

    public static function type_label( $t ) {
        $l = array(
            'texte'   => __( 'Texte libre', 'seliweb' ),
            'nombre'  => __( 'Nombre', 'seliweb' ),
            'oui_non' => __( 'Oui / Non', 'seliweb' ),
            'choix'   => __( 'Choix dans une liste', 'seliweb' ),
            'horaire' => __( 'Plage horaire (de… à…)', 'seliweb' ),
        );
        return $l[ $t ] ?? $t;
    }

    // Questions d'un événement, dans l'ordre d'affichage.
    public static function get_questions( $evt_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table_questions() . " WHERE evenement_id=%d ORDER BY ordre ASC, id ASC",
            (int) $evt_id
        ) );
    }

    // Options d'une question de type « choix » (tableau de chaînes).
    private static function question_options( $q ) {
        if ( empty( $q->options ) ) return array();
        $o = json_decode( $q->options, true );
        return is_array( $o ) ? $o : array();
    }

    // Valide les réponses postées ($_POST['reponse'][question_id]) au regard des
    // questions de l'événement. Renvoie array( question_id => valeur ) prête à
    // stocker, ou false si une réponse obligatoire manque.
    private static function valider_reponses( $questions, $raw ) {
        $raw = (array) $raw;
        $out = array();

        foreach ( $questions as $q ) {
            $v = $raw[ $q->id ] ?? '';

            switch ( $q->type ) {
                case 'horaire':
                    $de  = sanitize_text_field( wp_unslash( is_array( $v ) ? ( $v['de'] ?? '' ) : '' ) );
                    $a   = sanitize_text_field( wp_unslash( is_array( $v ) ? ( $v['a'] ?? '' ) : '' ) );
                    $val = ( $de !== '' || $a !== '' ) ? trim( $de . ' → ' . $a ) : '';
                    break;
                case 'oui_non':
                    $val = in_array( $v, array( 'oui', 'non' ), true ) ? $v : '';
                    break;
                case 'choix':
                    $opts = self::question_options( $q );
                    $val  = in_array( $v, $opts, true ) ? $v : '';
                    break;
                case 'nombre':
                    $v   = trim( (string) wp_unslash( $v ) );
                    $val = ( $v === '' || ! is_numeric( $v ) ) ? '' : (string) ( 0 + $v );
                    break;
                default:
                    $val = sanitize_text_field( wp_unslash( is_array( $v ) ? '' : $v ) );
            }

            if ( $q->obligatoire && $val === '' ) {
                return false;
            }
            if ( $val !== '' ) {
                $out[ (int) $q->id ] = $val;
            }
        }
        return $out;
    }

    // Réponses d'un membre pour un événement : array( libelle => valeur ).
    public static function get_reponses( $evt_id, $membre_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.libelle, r.valeur
               FROM " . self::table_reponses() . " r
               INNER JOIN " . self::table_questions() . " q ON q.id = r.question_id
               INNER JOIN " . self::table_inscr() . " i ON i.id = r.inscription_id
              WHERE i.evenement_id = %d AND i.membre_id = %d
              ORDER BY q.ordre ASC, q.id ASC",
            (int) $evt_id, (int) $membre_id
        ) );
        $out = array();
        foreach ( $rows as $r ) {
            $out[ $r->libelle ] = $r->valeur;
        }
        return $out;
    }

    // Champ de saisie d'une question, dans le formulaire d'inscription (front).
    private static function render_question_field( $q ) {
        $name = 'reponse[' . (int) $q->id . ']';
        $req  = $q->obligatoire ? ' required' : '';
        echo '<p class="seliweb-field seliweb-evt-q">';
        echo '<label class="seliweb-evt-q-label">' . esc_html( $q->libelle );
        if ( $q->obligatoire ) echo ' <span class="seliweb-req">*</span>';
        echo '</label>';

        switch ( $q->type ) {
            case 'nombre':
                echo '<input type="number" min="0" step="1" name="' . esc_attr( $name ) . '" class="seliweb-input"' . $req . '>';
                break;

            case 'oui_non':
                echo '<span class="seliweb-evt-q-radios">';
                echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="oui"' . $req . '> ' . esc_html__( 'Oui', 'seliweb' ) . '</label> ';
                echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="non"' . $req . '> ' . esc_html__( 'Non', 'seliweb' ) . '</label>';
                echo '</span>';
                break;

            case 'choix':
                echo '<select name="' . esc_attr( $name ) . '" class="seliweb-input"' . $req . '>';
                echo '<option value="">' . esc_html__( '— Choisir —', 'seliweb' ) . '</option>';
                foreach ( self::question_options( $q ) as $o ) {
                    echo '<option value="' . esc_attr( $o ) . '">' . esc_html( $o ) . '</option>';
                }
                echo '</select>';
                break;

            case 'horaire':
                echo '<span class="seliweb-evt-q-horaire">';
                echo esc_html__( 'de', 'seliweb' ) . ' <input type="time" name="' . esc_attr( $name ) . '[de]"' . $req . '> ';
                echo esc_html__( 'à', 'seliweb' ) . ' <input type="time" name="' . esc_attr( $name ) . '[a]"' . $req . '>';
                echo '</span>';
                break;

            default:
                echo '<input type="text" name="' . esc_attr( $name ) . '" class="seliweb-input"' . $req . '>';
        }
        echo '</p>';
    }

    // Enregistre / met à jour / supprime les questions d'un événement à partir
    // de $_POST['questions'] (tableau ordonné selon l'ordre du DOM).
    private static function save_questions( $evt_id, $raw ) {
        global $wpdb;
        $evt_id = (int) $evt_id;
        $tq     = self::table_questions();
        $gardes = array();
        $ordre  = 0;

        foreach ( (array) $raw as $q ) {
            $libelle = sanitize_text_field( wp_unslash( $q['libelle'] ?? '' ) );
            if ( $libelle === '' ) continue;

            $type = in_array( $q['type'] ?? '', self::Q_TYPES, true ) ? $q['type'] : 'texte';
            $opts = null;
            if ( 'choix' === $type ) {
                $lignes = preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $q['options'] ?? '' ) );
                $lignes = array_values( array_filter( array_map(
                    static function ( $l ) { return sanitize_text_field( trim( $l ) ); },
                    $lignes
                ), 'strlen' ) );
                $opts = $lignes ? wp_json_encode( $lignes ) : null;
            }

            $data = array(
                'evenement_id' => $evt_id,
                'ordre'        => $ordre++,
                'libelle'      => $libelle,
                'type'         => $type,
                'obligatoire'  => empty( $q['obligatoire'] ) ? 0 : 1,
                'options'      => $opts,
            );

            $qid = (int) ( $q['id'] ?? 0 );
            if ( $qid && $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $tq WHERE id=%d AND evenement_id=%d", $qid, $evt_id
            ) ) ) {
                $wpdb->update( $tq, $data, array( 'id' => $qid ) );
                $gardes[] = $qid;
            } else {
                $wpdb->insert( $tq, $data );
                $gardes[] = (int) $wpdb->insert_id;
            }
        }

        // Supprimer les questions retirées + leurs réponses.
        $existantes = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $tq WHERE evenement_id=%d", $evt_id
        ) ) );
        $a_suppr = array_diff( $existantes, $gardes );
        if ( $a_suppr ) {
            $in = implode( ',', array_map( 'intval', $a_suppr ) );
            $wpdb->query( "DELETE FROM " . self::table_reponses() . " WHERE question_id IN ($in)" );
            $wpdb->query( "DELETE FROM $tq WHERE id IN ($in)" );
        }
    }

    // ================================================================
    // BACK-OFFICE
    // ================================================================
    public static function display() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__( 'Événements', 'seliweb' ) . '</h1>';

        if ( 'new' === $action || 'edit' === $action ) {
            self::form_admin( $id );
        } elseif ( 'synthese' === $action ) {
            self::synthese( $id );
        } else {
            echo ' <a href="' . esc_url( admin_url( 'admin.php?page=seliweb_evenements&action=new' ) ) . '" class="page-title-action">'
               . esc_html__( 'Ajouter', 'seliweb' ) . '</a><hr class="wp-header-end">';
            self::liste_admin();
        }
        echo '</div>';
    }

    private static function liste_admin() {
        global $wpdb;
        $t = self::table();

        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Événement enregistré.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Événement supprimé.', 'seliweb' ) . '</p></div>';

        $filtre = isset( $_GET['f'] ) && $_GET['f'] === 'passes' ? 'passes' : 'a_venir';
        $bascule = self::cutoff_jour();
        $cond   = 'passes' === $filtre
            ? $wpdb->prepare( "COALESCE(date_fin, date_debut) < %s", $bascule )
            : $wpdb->prepare( "COALESCE(date_fin, date_debut) >= %s", $bascule );
        $order  = 'passes' === $filtre ? 'DESC' : 'ASC';
        $items  = $wpdb->get_results( "SELECT * FROM $t WHERE $cond ORDER BY date_debut $order" );

        $nb_venir  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE COALESCE(date_fin, date_debut) >= %s", $bascule ) );
        $nb_passes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE COALESCE(date_fin, date_debut) <  %s", $bascule ) );

        $groupes_noms = $wpdb->get_results( "SELECT id, nom FROM {$wpdb->prefix}seliweb_groupes", OBJECT_K );
        $base = admin_url( 'admin.php?page=seliweb_evenements' );
        ?>
        <ul class="subsubsub">
            <li><a href="<?php echo esc_url( $base ); ?>" class="<?php echo 'a_venir' === $filtre ? 'current' : ''; ?>"><?php esc_html_e( 'À venir', 'seliweb' ); ?> <span class="count">(<?php echo $nb_venir; ?>)</span></a> |</li>
            <li><a href="<?php echo esc_url( add_query_arg( 'f', 'passes', $base ) ); ?>" class="<?php echo 'passes' === $filtre ? 'current' : ''; ?>"><?php esc_html_e( 'Passés', 'seliweb' ); ?> <span class="count">(<?php echo $nb_passes; ?>)</span></a></li>
        </ul>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead><tr>
                <th><?php esc_html_e( 'Titre', 'seliweb' ); ?></th>
                <th style="width:150px;"><?php esc_html_e( 'Date', 'seliweb' ); ?></th>
                <th style="width:110px;"><?php esc_html_e( 'Visibilité', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Groupes concernés', 'seliweb' ); ?></th>
                <th style="width:120px;"><?php esc_html_e( 'Inscription', 'seliweb' ); ?></th>
                <th style="width:85px;"><?php esc_html_e( 'Statut', 'seliweb' ); ?></th>
                <th style="width:120px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( ! $items ) : ?>
                <tr><td colspan="7"><em>
                    <?php if ( 'a_venir' === $filtre && $nb_passes ) : ?>
                        <?php printf(
                            esc_html__( 'Aucun événement à venir. %d événement(s) passé(s) — voir l\'onglet « Passés ».', 'seliweb' ),
                            $nb_passes
                        ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Aucun événement.', 'seliweb' ); ?>
                    <?php endif; ?>
                </em></td></tr>
            <?php else : foreach ( $items as $e ) :
                $gids = self::groupes_ids( $e );
                $gnoms = array();
                foreach ( $gids as $gid ) { if ( isset( $groupes_noms[ $gid ] ) ) $gnoms[] = $groupes_noms[ $gid ]->nom; }
                ?>
                <tr>
                    <td><strong><a href="<?php echo esc_url( add_query_arg( array( 'action' => 'edit', 'id' => $e->id ), $base ) ); ?>"><?php echo esc_html( $e->titre ); ?></a></strong></td>
                    <td><?php echo esc_html( mysql2date( 'j M Y — G\hi', $e->date_debut ) ); ?></td>
                    <td><?php echo $e->visible_par_tous ? esc_html__( 'Publique', 'seliweb' ) : esc_html__( 'Membres seulement', 'seliweb' ); ?></td>
                    <td><?php echo $gnoms
                        ? esc_html( implode( ', ', $gnoms ) )
                        : '<span style="color:#888;">' . esc_html__( 'Aucun', 'seliweb' ) . '</span>'; ?></td>
                    <td>
                        <?php if ( $e->inscription_requise ) :
                            $nb_q = count( self::get_questions( (int) $e->id ) );
                            $synthese_url = add_query_arg( array( 'action' => 'synthese', 'id' => $e->id ), $base ); ?>
                            <a href="<?php echo esc_url( $synthese_url ); ?>"><strong><?php echo (int) self::nb_inscrits( (int) $e->id ); ?></strong> <?php esc_html_e( 'inscrit(s)', 'seliweb' ); ?></a>
                            <?php if ( $nb_q ) : ?><br><span style="color:#666;font-size:11px;"><?php printf( esc_html( _n( '%d question', '%d questions', $nb_q, 'seliweb' ) ), $nb_q ); ?></span><?php endif; ?>
                            <?php if ( ! $gids ) : ?><br><span style="color:#b32d2e;font-size:11px;"><?php esc_html_e( '⚠ aucun groupe', 'seliweb' ); ?></span><?php endif; ?>
                        <?php else : ?>
                            <span style="color:#888;"><?php esc_html_e( 'Non', 'seliweb' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo 'publie' === $e->statut
                        ? '<span style="color:#1d6a4a;font-weight:600;">' . esc_html__( 'Publié', 'seliweb' ) . '</span>'
                        : '<span style="color:#b26a00;">' . esc_html__( 'Brouillon', 'seliweb' ) . '</span>'; ?></td>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( array( 'action' => 'edit', 'id' => $e->id ), $base ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
                        <?php if ( $e->inscription_requise ) : ?>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url( add_query_arg( array( 'action' => 'synthese', 'id' => $e->id ), $base ) ); ?>"><?php esc_html_e( 'Synthèse', 'seliweb' ); ?></a>
                        <?php endif; ?>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'id' => $e->id ), $base ), 'seliweb_delete_evt_' . $e->id ) ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Supprimer cet événement ?', 'seliweb' ); ?>')"
                           style="color:#b32d2e;"><?php esc_html_e( 'Supprimer', 'seliweb' ); ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function form_admin( $id ) {
        global $wpdb;
        $e       = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id=%d", $id ) ) : null;
        $is_edit = (bool) $e;
        $groupes = $wpdb->get_results( "SELECT id, nom FROM {$wpdb->prefix}seliweb_groupes ORDER BY nom ASC" );
        $g_actifs = self::groupes_ids( $e );
        $img_id  = $e ? (int) $e->image_id : 0;
        $img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';

        $err = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';
        if ( 'champs' === $err ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Le titre et la date de début sont obligatoires.', 'seliweb' ) . '</p></div>';
        } elseif ( 'groupes' === $err ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Un événement avec inscription doit cibler au moins un groupe. Cochez les groupes concernés (tous, pour ouvrir à l\'ensemble des adhérents).', 'seliweb' ) . '</p></div>';
        }
        ?>
        <h2><?php echo $is_edit ? esc_html__( 'Modifier l\'événement', 'seliweb' ) : esc_html__( 'Nouvel événement', 'seliweb' ); ?></h2>
        <form method="post" style="max-width:760px;">
            <?php wp_nonce_field( 'seliweb_evenement', 'seliweb_evt_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="<?php echo $is_edit ? 'update' : 'create'; ?>">
            <?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $e->id; ?>"><?php endif; ?>

            <table class="form-table">
                <tr>
                    <th><label for="evt_titre"><?php esc_html_e( 'Titre', 'seliweb' ); ?> <span style="color:#b32d2e;">*</span></label></th>
                    <td><input type="text" id="evt_titre" name="titre" class="large-text" required
                               value="<?php echo esc_attr( $e->titre ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="evt_presentation"><?php esc_html_e( 'Présentation', 'seliweb' ); ?></label></th>
                    <td><textarea id="evt_presentation" name="presentation" rows="6" class="large-text"><?php echo esc_textarea( $e->presentation ?? '' ); ?></textarea></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Image', 'seliweb' ); ?></th>
                    <td>
                        <input type="hidden" id="evt_image_id" name="image_id" value="<?php echo (int) $img_id; ?>">
                        <div id="evt_image_apercu" style="margin-bottom:8px;">
                            <?php if ( $img_url ) : ?><img src="<?php echo esc_url( $img_url ); ?>" style="max-width:240px;height:auto;border:1px solid #ccc;"><?php endif; ?>
                        </div>
                        <button type="button" class="button" id="evt_image_choisir"><?php esc_html_e( 'Choisir une image', 'seliweb' ); ?></button>
                        <button type="button" class="button" id="evt_image_retirer" <?php echo $img_url ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Retirer', 'seliweb' ); ?></button>
                    </td>
                </tr>
                <tr>
                    <th><label for="evt_debut"><?php esc_html_e( 'Début', 'seliweb' ); ?> <span style="color:#b32d2e;">*</span></label></th>
                    <td><input type="datetime-local" id="evt_debut" name="date_debut" required
                               value="<?php echo esc_attr( self::to_input( $e->date_debut ?? '' ) ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="evt_fin"><?php esc_html_e( 'Fin', 'seliweb' ); ?></label></th>
                    <td><input type="datetime-local" id="evt_fin" name="date_fin"
                               value="<?php echo esc_attr( self::to_input( $e->date_fin ?? '' ) ); ?>">
                        <p class="description"><?php esc_html_e( 'Facultatif.', 'seliweb' ); ?></p></td>
                </tr>
                <tr>
                    <th><label for="evt_horaires"><?php esc_html_e( 'Horaires (texte)', 'seliweb' ); ?></label></th>
                    <td><input type="text" id="evt_horaires" name="horaires" class="regular-text"
                               value="<?php echo esc_attr( $e->horaires ?? '' ); ?>"
                               placeholder="<?php esc_attr_e( 'Ex. : accueil dès 9h, repas à 12h30', 'seliweb' ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="evt_lieu"><?php esc_html_e( 'Lieu', 'seliweb' ); ?></label></th>
                    <td><input type="text" id="evt_lieu" name="lieu" class="regular-text" value="<?php echo esc_attr( $e->lieu ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="evt_adresse"><?php esc_html_e( 'Adresse', 'seliweb' ); ?></label></th>
                    <td><input type="text" id="evt_adresse" name="adresse" class="large-text" value="<?php echo esc_attr( $e->adresse ?? '' ); ?>"></td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Visibilité publique', 'seliweb' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="visible_par_tous" id="evt_vpt" value="1" <?php checked( $e->visible_par_tous ?? 0 ); ?>>
                            <?php esc_html_e( 'Afficher cet événement sur la page publique des événements', 'seliweb' ); ?></label>
                        <div id="evt_vpt_options" style="margin-top:10px;padding-left:24px;<?php echo ( $e && $e->visible_par_tous ) ? '' : 'display:none;'; ?>">
                            <label><input type="checkbox" name="afficher_lieu" value="1" <?php checked( $e->afficher_lieu ?? 1 ); ?>>
                                <?php esc_html_e( 'Montrer le lieu et l\'adresse', 'seliweb' ); ?></label><br>
                            <label><input type="checkbox" name="afficher_horaires" value="1" <?php checked( $e->afficher_horaires ?? 1 ); ?>>
                                <?php esc_html_e( 'Montrer les horaires', 'seliweb' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Le titre et la présentation sont toujours affichés.', 'seliweb' ); ?></p>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Inscription', 'seliweb' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="inscription_requise" value="1" <?php checked( $e->inscription_requise ?? 0 ); ?>>
                            <?php esc_html_e( 'Les adhérents peuvent s\'inscrire à cet événement', 'seliweb' ); ?></label>
                        <p class="description"><?php esc_html_e( 'L\'inscription se fait depuis l\'espace « Mon compte », toujours connecté.', 'seliweb' ); ?></p>
                        <?php if ( $is_edit ) : ?>
                            <p class="description"><strong><?php printf( esc_html__( 'Inscrits : %d', 'seliweb' ), self::nb_inscrits( (int) $e->id ) ); ?></strong></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="evt_org"><?php esc_html_e( 'E-mail de l\'organisateur', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="email" id="evt_org" name="organisateur_email" class="regular-text"
                               value="<?php echo esc_attr( $e->organisateur_email ?? '' ); ?>">
                        <p class="description"><?php esc_html_e( 'Reçoit un e-mail à chaque inscription / désinscription. À défaut : adresse de Réglages → Mails → « Inscription à un événement », sinon l\'administrateur.', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Groupes concernés', 'seliweb' ); ?></th>
                    <td>
                        <?php if ( ! $groupes ) : ?>
                            <em><?php esc_html_e( 'Aucun groupe défini.', 'seliweb' ); ?></em>
                        <?php else : ?>
                            <fieldset>
                                <?php foreach ( $groupes as $g ) : ?>
                                    <label><input type="checkbox" name="groupes[]" value="<?php echo (int) $g->id; ?>"
                                           <?php checked( in_array( (int) $g->id, $g_actifs, true ) ); ?>>
                                        <?php echo esc_html( $g->nom ); ?></label><br>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description"><?php esc_html_e( 'Détermine quels adhérents voient l\'événement dans « Mon compte » et peuvent s\'y inscrire. Pour ouvrir à tous les adhérents, cochez tous les groupes.', 'seliweb' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Questions à l\'inscription', 'seliweb' ); ?></th>
                    <td>
                        <?php $questions = $is_edit ? self::get_questions( (int) $e->id ) : array(); ?>
                        <div id="evt-questions">
                            <?php foreach ( $questions as $i => $q ) : ?>
                                <?php self::render_question_builder_row( $i, $q ); ?>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" id="evt-q-ajouter"><?php esc_html_e( 'Ajouter une question', 'seliweb' ); ?></button>
                        <p class="description"><?php esc_html_e( 'Posées à l\'adhérent au moment de son inscription, depuis « Mon compte ». Sans effet si l\'inscription n\'est pas activée ci-dessus.', 'seliweb' ); ?></p>
                        <script type="text/html" id="evt-q-modele">
                            <?php self::render_question_builder_row( '__INDEX__', null ); ?>
                        </script>
                    </td>
                </tr>

                <tr>
                    <th><label for="evt_statut"><?php esc_html_e( 'Statut', 'seliweb' ); ?></label></th>
                    <td>
                        <select id="evt_statut" name="statut">
                            <option value="brouillon" <?php selected( $e->statut ?? 'brouillon', 'brouillon' ); ?>><?php esc_html_e( 'Brouillon', 'seliweb' ); ?></option>
                            <option value="publie" <?php selected( $e->statut ?? '', 'publie' ); ?>><?php esc_html_e( 'Publié', 'seliweb' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Un brouillon n\'apparaît nulle part côté public.', 'seliweb' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( $is_edit ? __( 'Mettre à jour', 'seliweb' ) : __( 'Créer', 'seliweb' ) ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_evenements' ) ); ?>" class="button"><?php esc_html_e( 'Annuler', 'seliweb' ); ?></a>
        </form>

        <script>
        jQuery(function($){
            $('#evt_vpt').on('change', function(){ $('#evt_vpt_options').toggle( this.checked ); });

            // --- Constructeur de questions -------------------------------
            var qIndex = <?php echo (int) count( $questions ); ?>;
            function majOptions( $row ) {
                var choix = $row.find('.evt-q-type').val() === 'choix';
                $row.find('.evt-q-options').toggle( choix );
            }
            $('#evt-q-ajouter').on('click', function(){
                var html = $('#evt-q-modele').html().replace(/__INDEX__/g, 'n' + qIndex++);
                var $row = $($.parseHTML(html)).filter('.evt-q-row');
                $('#evt-questions').append( $row );
                majOptions( $row );
            });
            $('#evt-questions').on('change', '.evt-q-type', function(){
                majOptions( $(this).closest('.evt-q-row') );
            });
            $('#evt-questions').on('click', '.evt-q-suppr', function(){
                $(this).closest('.evt-q-row').remove();
            });
            $('#evt-questions').on('click', '.evt-q-monter', function(){
                var $r = $(this).closest('.evt-q-row'), $p = $r.prev('.evt-q-row');
                if ( $p.length ) $r.insertBefore( $p );
            });
            $('#evt-questions').on('click', '.evt-q-descendre', function(){
                var $r = $(this).closest('.evt-q-row'), $n = $r.next('.evt-q-row');
                if ( $n.length ) $r.insertAfter( $n );
            });
            $('#evt-questions .evt-q-row').each(function(){ majOptions( $(this) ); });

            var frame;
            $('#evt_image_choisir').on('click', function(e){
                e.preventDefault();
                if ( frame ) { frame.open(); return; }
                frame = wp.media({ title: '<?php echo esc_js( __( 'Image de l\'événement', 'seliweb' ) ); ?>', multiple: false });
                frame.on('select', function(){
                    var a = frame.state().get('selection').first().toJSON();
                    var url = ( a.sizes && a.sizes.medium ) ? a.sizes.medium.url : a.url;
                    $('#evt_image_id').val( a.id );
                    $('#evt_image_apercu').html('<img src="'+url+'" style="max-width:240px;height:auto;border:1px solid #ccc;">');
                    $('#evt_image_retirer').show();
                });
                frame.open();
            });
            $('#evt_image_retirer').on('click', function(e){
                e.preventDefault();
                $('#evt_image_id').val(''); $('#evt_image_apercu').empty(); $(this).hide();
            });
        });
        </script>
        <?php
    }

    // Une ligne du constructeur de questions (édition back-office).
    // $index : entier (questions existantes) ou « __INDEX__ » / « nN » (modèle JS).
    private static function render_question_builder_row( $index, $q ) {
        $n     = 'questions[' . $index . ']';
        $type  = $q ? $q->type : 'texte';
        $opts  = $q ? implode( "\n", self::question_options( $q ) ) : '';
        ?>
        <div class="evt-q-row" style="border:1px solid #dcdcde;border-radius:4px;padding:10px 12px;margin-bottom:8px;background:#fff;">
            <input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo $q ? (int) $q->id : 0; ?>">
            <p style="margin:0 0 6px;">
                <input type="text" name="<?php echo esc_attr( $n ); ?>[libelle]" class="regular-text" style="width:60%;"
                       placeholder="<?php esc_attr_e( 'Intitulé de la question', 'seliweb' ); ?>"
                       value="<?php echo esc_attr( $q ? $q->libelle : '' ); ?>">
                <button type="button" class="button-link evt-q-monter" title="<?php esc_attr_e( 'Monter', 'seliweb' ); ?>" style="text-decoration:none;">&#9650;</button>
                <button type="button" class="button-link evt-q-descendre" title="<?php esc_attr_e( 'Descendre', 'seliweb' ); ?>" style="text-decoration:none;">&#9660;</button>
                <button type="button" class="button-link evt-q-suppr" style="color:#b32d2e;"><?php esc_html_e( 'Supprimer', 'seliweb' ); ?></button>
            </p>
            <p style="margin:0;">
                <select name="<?php echo esc_attr( $n ); ?>[type]" class="evt-q-type">
                    <?php foreach ( self::Q_TYPES as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( self::type_label( $t ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="margin-left:10px;">
                    <input type="checkbox" name="<?php echo esc_attr( $n ); ?>[obligatoire]" value="1" <?php checked( $q ? $q->obligatoire : 0 ); ?>>
                    <?php esc_html_e( 'Réponse obligatoire', 'seliweb' ); ?>
                </label>
            </p>
            <p class="evt-q-options" style="margin:6px 0 0;<?php echo 'choix' === $type ? '' : 'display:none;'; ?>">
                <textarea name="<?php echo esc_attr( $n ); ?>[options]" rows="3" class="large-text"
                          placeholder="<?php esc_attr_e( 'Une option par ligne', 'seliweb' ); ?>"><?php echo esc_textarea( $opts ); ?></textarea>
            </p>
        </div>
        <?php
    }

    // ----------------------------------------------------------------
    // Traitement POST / suppression — hook init
    // ----------------------------------------------------------------
    public static function handle_post() {
        if ( ! is_admin() ) return;
        if ( ( $_GET['page'] ?? '' ) !== 'seliweb_evenements' ) return;
        if ( ! isset( $_POST['seliweb_evt_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['seliweb_evt_nonce'], 'seliweb_evenement' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;

        $vpt   = isset( $_POST['visible_par_tous'] ) ? 1 : 0;
        $gids  = isset( $_POST['groupes'] ) ? array_map( 'intval', (array) $_POST['groupes'] ) : array();
        $gids  = array_values( array_filter( array_unique( $gids ) ) );

        $titre  = sanitize_text_field( wp_unslash( $_POST['titre'] ?? '' ) );
        $debut  = self::parse_datetime( $_POST['date_debut'] ?? '' );
        $id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $insc   = isset( $_POST['inscription_requise'] );

        $erreur = '';
        if ( $titre === '' || $debut === '' ) {
            $erreur = 'champs';
        } elseif ( $insc && ! $gids ) {
            $erreur = 'groupes';
        }
        if ( $erreur ) {
            wp_safe_redirect( add_query_arg(
                array( 'page' => 'seliweb_evenements', 'action' => $id ? 'edit' : 'new', 'id' => $id ?: null, 'error' => $erreur ),
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        $data = array(
            'titre'               => $titre,
            'presentation'        => sanitize_textarea_field( wp_unslash( $_POST['presentation'] ?? '' ) ),
            'lieu'                => sanitize_text_field( wp_unslash( $_POST['lieu'] ?? '' ) ) ?: null,
            'adresse'             => sanitize_text_field( wp_unslash( $_POST['adresse'] ?? '' ) ) ?: null,
            'date_debut'          => $debut,
            'date_fin'            => self::parse_datetime( $_POST['date_fin'] ?? '' ) ?: null,
            'horaires'            => sanitize_text_field( wp_unslash( $_POST['horaires'] ?? '' ) ) ?: null,
            'image_id'            => ( (int) ( $_POST['image_id'] ?? 0 ) ) ?: null,
            'visible_par_tous'    => $vpt,
            'afficher_lieu'       => ( $vpt && isset( $_POST['afficher_lieu'] ) ) ? 1 : 0,
            'afficher_horaires'   => ( $vpt && isset( $_POST['afficher_horaires'] ) ) ? 1 : 0,
            'inscription_requise' => isset( $_POST['inscription_requise'] ) ? 1 : 0,
            'groupes'             => $gids ? implode( ',', $gids ) : null,
            'organisateur_email'  => ( sanitize_email( wp_unslash( $_POST['organisateur_email'] ?? '' ) ) ) ?: null,
            'statut'              => in_array( $_POST['statut'] ?? '', array( 'brouillon', 'publie' ), true ) ? $_POST['statut'] : 'brouillon',
        );

        if ( 'update' === sanitize_key( $_POST['seliweb_action'] ?? '' ) && $id ) {
            $wpdb->update( self::table(), $data, array( 'id' => $id ) );
            $evt_id = $id;
        } else {
            $data['date_creation'] = current_time( 'mysql' );
            $wpdb->insert( self::table(), $data );
            $evt_id = (int) $wpdb->insert_id;
        }

        self::save_questions( $evt_id, $_POST['questions'] ?? array() );

        // Rejoint l'onglet où l'événement apparaît (sinon il « disparaît » de la
        // liste « À venir » quand sa date est déjà passée).
        $fin_effective = self::parse_datetime( $_POST['date_fin'] ?? '' ) ?: $debut;
        $args = array( 'page' => 'seliweb_evenements', 'updated' => 1 );
        if ( $fin_effective < self::cutoff_jour() ) {
            $args['f'] = 'passes';
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_delete() {
        if ( ! is_admin() ) return;
        if ( ( $_GET['page'] ?? '' ) !== 'seliweb_evenements' ) return;
        if ( ( $_GET['action'] ?? '' ) !== 'delete' || empty( $_GET['id'] ) ) return;

        $id = (int) $_GET['id'];
        if ( ! check_admin_referer( 'seliweb_delete_evt_' . $id ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;
        $inscr_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . self::table_inscr() . " WHERE evenement_id=%d", $id
        ) ) );
        if ( $inscr_ids ) {
            $in = implode( ',', $inscr_ids );
            $wpdb->query( "DELETE FROM " . self::table_reponses() . " WHERE inscription_id IN ($in)" );
        }
        $wpdb->delete( self::table_inscr(), array( 'evenement_id' => $id ) );
        $wpdb->delete( self::table_questions(), array( 'evenement_id' => $id ) );
        $wpdb->delete( self::table(), array( 'id' => $id ) );
        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_evenements&deleted=1' ) );
        exit;
    }

    // ================================================================
    // SYNTHÈSE DES INSCRIPTIONS (phase 4)
    // ================================================================

    // Rassemble l'événement, ses questions (ordonnées) et une ligne par
    // inscription : nom, e-mail, date, et réponses indexées par question_id.
    private static function synthese_data( $evt_id ) {
        global $wpdb;
        $evt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id=%d", (int) $evt_id ) );
        if ( ! $evt ) return null;

        $questions = self::get_questions( $evt_id );

        $inscr = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.id, i.membre_id, i.date_inscription, m.wp_user_id
               FROM " . self::table_inscr() . " i
               LEFT JOIN {$wpdb->prefix}seliweb_membres m ON m.id = i.membre_id
              WHERE i.evenement_id = %d
              ORDER BY i.date_inscription ASC, i.id ASC",
            (int) $evt_id
        ) );

        $rep_map = array();
        if ( $inscr ) {
            $ids = implode( ',', array_map( static function ( $r ) { return (int) $r->id; }, $inscr ) );
            $rows = $wpdb->get_results( "SELECT inscription_id, question_id, valeur FROM " . self::table_reponses() . " WHERE inscription_id IN ($ids)" );
            foreach ( $rows as $r ) {
                $rep_map[ (int) $r->inscription_id ][ (int) $r->question_id ] = $r->valeur;
            }
        }

        $lignes = array();
        foreach ( $inscr as $r ) {
            $u   = $r->wp_user_id ? get_userdata( (int) $r->wp_user_id ) : null;
            $nom = $u ? ( trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name ) : sprintf( __( 'Membre #%d', 'seliweb' ), (int) $r->membre_id );
            $lignes[] = array(
                'nom'      => $nom,
                'email'    => $u ? $u->user_email : '',
                'date'     => $r->date_inscription,
                'reponses' => $rep_map[ (int) $r->id ] ?? array(),
            );
        }

        return array( 'evt' => $evt, 'questions' => $questions, 'lignes' => $lignes );
    }

    // Ligne de compilation pour une question (somme des nombres, décompte des
    // oui/non et des choix, nombre de réponses sinon).
    private static function synthese_totaux( $q, $lignes ) {
        $vals = array();
        foreach ( $lignes as $l ) {
            $v = $l['reponses'][ (int) $q->id ] ?? '';
            if ( $v !== '' ) $vals[] = $v;
        }
        if ( ! $vals ) return '';

        if ( 'nombre' === $q->type ) {
            $somme = 0;
            foreach ( $vals as $v ) $somme += (float) $v;
            return sprintf( __( 'Total : %s', 'seliweb' ), rtrim( rtrim( number_format( $somme, 2, ',', ' ' ), '0' ), ',' ) );
        }
        if ( in_array( $q->type, array( 'oui_non', 'choix' ), true ) ) {
            $cnt = array_count_values( $vals );
            arsort( $cnt );
            $parts = array();
            foreach ( $cnt as $k => $n ) $parts[] = $k . ' × ' . $n;
            return implode( ' · ', $parts );
        }
        return sprintf( _n( '%d réponse', '%d réponses', count( $vals ), 'seliweb' ), count( $vals ) );
    }

    private static function synthese( $id ) {
        $base = admin_url( 'admin.php?page=seliweb_evenements' );
        $data = self::synthese_data( $id );

        if ( ! $data ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Événement introuvable.', 'seliweb' ) . '</p></div>';
            echo '<p><a href="' . esc_url( $base ) . '" class="button">&larr; ' . esc_html__( 'Retour à la liste', 'seliweb' ) . '</a></p>';
            return;
        }

        $evt = $data['evt']; $questions = $data['questions']; $lignes = $data['lignes'];
        $csv_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=seliweb_evt_csv&id=' . (int) $evt->id ),
            'seliweb_evt_csv_' . (int) $evt->id
        );
        ?>
        <style>
            @media print {
                #adminmenumain, #wpadminbar, #wpfooter, .seliweb-synthese-actions,
                .wp-header-end, .page-title-action, .notice { display:none !important; }
                #wpcontent, #wpbody-content { margin-left:0 !important; padding:0 !important; }
            }
            .seliweb-synthese-table th, .seliweb-synthese-table td { vertical-align:top; }
            .seliweb-synthese-table tfoot td { background:#f6f7f7; font-size:12px; }
        </style>

        <h2 style="margin-top:1em;"><?php echo esc_html( $evt->titre ); ?></h2>
        <p>
            <?php echo esc_html( self::format_dates( $evt->date_debut, $evt->date_fin ) ); ?>
            &nbsp;—&nbsp;
            <strong><?php printf( esc_html( _n( '%d inscrit', '%d inscrits', count( $lignes ), 'seliweb' ) ), count( $lignes ) ); ?></strong>
        </p>

        <p class="seliweb-synthese-actions">
            <a href="<?php echo esc_url( $base ); ?>" class="button">&larr; <?php esc_html_e( 'Retour à la liste', 'seliweb' ); ?></a>
            <a href="<?php echo esc_url( add_query_arg( array( 'action' => 'edit', 'id' => $evt->id ), $base ) ); ?>" class="button"><?php esc_html_e( 'Modifier l\'événement', 'seliweb' ); ?></a>
            <button type="button" class="button" onclick="window.print();return false;"><?php esc_html_e( 'Imprimer', 'seliweb' ); ?></button>
            <a href="<?php echo esc_url( $csv_url ); ?>" class="button button-primary"><?php esc_html_e( 'Exporter en CSV', 'seliweb' ); ?></a>
        </p>

        <?php if ( ! $lignes ) : ?>
            <p><em><?php esc_html_e( 'Aucune inscription pour le moment.', 'seliweb' ); ?></em></p>
        <?php else : ?>
            <table class="wp-list-table widefat striped seliweb-synthese-table">
                <thead><tr>
                    <th style="width:180px;"><?php esc_html_e( 'Membre', 'seliweb' ); ?></th>
                    <th style="width:210px;"><?php esc_html_e( 'E-mail', 'seliweb' ); ?></th>
                    <th style="width:130px;"><?php esc_html_e( 'Inscrit le', 'seliweb' ); ?></th>
                    <?php foreach ( $questions as $q ) : ?>
                        <th><?php echo esc_html( $q->libelle ); ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ( $lignes as $l ) : ?>
                        <tr>
                            <td><?php echo esc_html( $l['nom'] ); ?></td>
                            <td><?php echo esc_html( $l['email'] ); ?></td>
                            <td><?php echo esc_html( mysql2date( 'j M Y G\hi', $l['date'] ) ); ?></td>
                            <?php foreach ( $questions as $q ) : ?>
                                <td><?php echo esc_html( $l['reponses'][ (int) $q->id ] ?? '—' ); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ( $questions ) : ?>
                    <tfoot><tr>
                        <td colspan="3"><strong><?php esc_html_e( 'Compilation', 'seliweb' ); ?></strong></td>
                        <?php foreach ( $questions as $q ) : ?>
                            <td><?php echo esc_html( self::synthese_totaux( $q, $lignes ) ); ?></td>
                        <?php endforeach; ?>
                    </tr></tfoot>
                <?php endif; ?>
            </table>
        <?php endif; ?>
        <?php
    }

    public static function handle_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'seliweb' ) );
        }
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( 'seliweb_evt_csv_' . $id );

        $data = self::synthese_data( $id );
        if ( ! $data ) {
            wp_die( esc_html__( 'Événement introuvable.', 'seliweb' ) );
        }

        $evt = $data['evt']; $questions = $data['questions']; $lignes = $data['lignes'];
        $slug = sanitize_title( $evt->titre ) ?: 'evenement';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="inscriptions-' . $slug . '-' . gmdate( 'Ymd' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // BOM UTF-8 (Excel)

        $entete = array( __( 'Membre', 'seliweb' ), __( 'E-mail', 'seliweb' ), __( 'Inscrit le', 'seliweb' ) );
        foreach ( $questions as $q ) $entete[] = $q->libelle;
        fputcsv( $out, $entete, ';' );

        foreach ( $lignes as $l ) {
            $row = array( $l['nom'], $l['email'], mysql2date( 'Y-m-d H:i', $l['date'] ) );
            foreach ( $questions as $q ) $row[] = $l['reponses'][ (int) $q->id ] ?? '';
            fputcsv( $out, $row, ';' );
        }

        if ( $questions ) {
            $tot = array( __( 'Compilation', 'seliweb' ), '', '' );
            foreach ( $questions as $q ) $tot[] = self::synthese_totaux( $q, $lignes );
            fputcsv( $out, $tot, ';' );
        }

        fclose( $out );
        exit;
    }

    // ================================================================
    // FRONT — shortcode [seliweb_evenements]
    // ================================================================
    public static function shortcode_liste( $atts ) {
        if ( is_admin() || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
            return '<p><em>' . esc_html__( 'Liste des événements Seliweb (prévisualisation désactivée).', 'seliweb' ) . '</em></p>';
        }

        global $wpdb;
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE statut = 'publie' AND visible_par_tous = 1
               AND COALESCE(date_fin, date_debut) >= %s
             ORDER BY date_debut ASC",
            self::cutoff_jour()
        ) );

        ob_start();
        echo '<div class="seliweb-wrap seliweb-evenements">';
        if ( ! $items ) {
            echo '<p class="seliweb-evt-vide">' . esc_html__( 'Aucun événement à venir pour le moment.', 'seliweb' ) . '</p>';
        } else {
            foreach ( $items as $e ) {
                self::render_carte_publique( $e );
            }
        }
        echo '</div>';
        return ob_get_clean();
    }

    // Noms des groupes concernés par un événement (tableau).
    private static function groupes_noms( $evt ) {
        $ids = self::groupes_ids( $evt );
        if ( ! $ids ) return array();
        global $wpdb;
        $in = implode( ',', array_map( 'intval', $ids ) );
        return $wpdb->get_col( "SELECT nom FROM {$wpdb->prefix}seliweb_groupes WHERE id IN ($in) ORDER BY nom ASC" );
    }

    private static function render_carte_publique( $e ) {
        $img   = $e->image_id ? wp_get_attachment_image_url( (int) $e->image_id, 'medium' ) : '';
        $gnoms = self::groupes_noms( $e );
        ?>
        <article class="seliweb-evt-carte">
            <?php if ( $img ) : ?>
                <div class="seliweb-evt-image"><img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $e->titre ); ?>"></div>
            <?php endif; ?>
            <div class="seliweb-evt-corps">
                <h3 class="seliweb-evt-titre"><?php echo esc_html( $e->titre ); ?></h3>
                <p class="seliweb-evt-date"><?php echo esc_html( self::format_dates( $e->date_debut, $e->date_fin ) ); ?></p>
                <?php if ( $gnoms ) : ?>
                    <p class="seliweb-evt-groupes">
                        <?php printf( esc_html__( 'Concerne : %s', 'seliweb' ), esc_html( implode( ', ', $gnoms ) ) ); ?>
                    </p>
                <?php endif; ?>
                <?php if ( $e->afficher_horaires && $e->horaires ) : ?>
                    <p class="seliweb-evt-horaires"><?php echo esc_html( $e->horaires ); ?></p>
                <?php endif; ?>
                <?php if ( $e->afficher_lieu && ( $e->lieu || $e->adresse ) ) :
                    $lieu = trim( $e->lieu . ( $e->lieu && $e->adresse ? ' — ' : '' ) . $e->adresse ); ?>
                    <p class="seliweb-evt-lieu"><?php echo esc_html( $lieu ); ?></p>
                <?php endif; ?>
                <?php if ( $e->presentation ) : ?>
                    <div class="seliweb-evt-presentation"><?php echo wpautop( esc_html( $e->presentation ) ); ?></div>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    private static function format_dates( $debut, $fin ) {
        $jour_d = ucfirst( mysql2date( 'l j F Y', $debut ) );
        $out    = $jour_d . ' — ' . mysql2date( 'G\hi', $debut );
        if ( $fin && substr( $fin, 0, 10 ) !== substr( $debut, 0, 10 ) ) {
            $out .= '  →  ' . ucfirst( mysql2date( 'l j F Y', $fin ) );
        }
        return $out;
    }

    // ================================================================
    // INSCRIPTIONS AUX ÉVÉNEMENTS (adhérents connectés)
    // ================================================================

    // Événements publiés « à venir » concernant le groupe du membre :
    // ceux qui ciblent son groupe, ou qui ne ciblent aucun groupe (= tous).
    public static function get_pour_membre( $groupe_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE statut = 'publie'
               AND COALESCE(date_fin, date_debut) >= %s
               AND FIND_IN_SET(%d, groupes)
             ORDER BY date_debut ASC",
            self::cutoff_jour(), (int) $groupe_id
        ) );
    }

    // Événements passés auxquels le membre était inscrit.
    public static function get_passes_inscrit( $membre_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT e.* FROM " . self::table() . " e
             INNER JOIN " . self::table_inscr() . " i ON i.evenement_id = e.id
             WHERE i.membre_id = %d AND COALESCE(e.date_fin, e.date_debut) < %s
             ORDER BY e.date_debut DESC",
            (int) $membre_id, self::cutoff_jour()
        ) );
    }

    public static function est_inscrit( $evt_id, $membre_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . self::table_inscr() . " WHERE evenement_id=%d AND membre_id=%d",
            (int) $evt_id, (int) $membre_id
        ) );
    }

    public static function nb_inscrits( $evt_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_inscr() . " WHERE evenement_id=%d", (int) $evt_id
        ) );
    }

    // Le membre (de ce groupe) est-il autorisé à s'inscrire à cet événement ?
    // Il faut que son groupe soit explicitement dans la liste des groupes
    // concernés — un événement sans groupe n'ouvre l'inscription à personne
    // (pour ouvrir à tous, l'admin coche tous les groupes).
    public static function peut_s_inscrire( $evt, $groupe_id ) {
        return $evt && $evt->inscription_requise
            && in_array( (int) $groupe_id, self::groupes_ids( $evt ), true );
    }

    public static function handle_inscription() {
        if ( is_admin() ) return;
        if ( ! isset( $_POST['seliweb_evt_action'] ) ) return;
        if ( ! is_user_logged_in() ) return;

        $evt_id = (int) ( $_POST['evenement_id'] ?? 0 );
        if ( ! wp_verify_nonce( $_POST['seliweb_evt_i_nonce'] ?? '', 'seliweb_evt_i_' . $evt_id ) ) return;

        global $wpdb;
        $membre = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, groupe_id FROM {$wpdb->prefix}seliweb_membres WHERE wp_user_id=%d",
            get_current_user_id()
        ) );
        $evt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id=%d", $evt_id ) );

        $retour = wp_validate_redirect( wp_get_raw_referer(), '' ) ?: home_url( '/' );

        if ( $membre && $evt && 'publie' === $evt->statut ) {
            $act    = sanitize_key( $_POST['seliweb_evt_action'] );
            $ouvert = $evt->date_debut > current_time( 'mysql' );

            if ( 'inscrire' === $act
                && $ouvert
                && self::peut_s_inscrire( $evt, $membre->groupe_id )
                && ! self::est_inscrit( $evt_id, $membre->id ) ) {

                $questions = self::get_questions( $evt_id );
                $reponses  = self::valider_reponses( $questions, $_POST['reponse'] ?? array() );

                if ( false === $reponses ) {
                    $retour = add_query_arg( 'evt', 'reponses', $retour );
                } else {
                    $wpdb->insert( self::table_inscr(), array(
                        'evenement_id'     => $evt_id,
                        'membre_id'        => (int) $membre->id,
                        'date_inscription' => current_time( 'mysql' ),
                    ) );
                    $inscr_id = (int) $wpdb->insert_id;
                    foreach ( $reponses as $qid => $val ) {
                        $wpdb->insert( self::table_reponses(), array(
                            'inscription_id' => $inscr_id,
                            'question_id'    => (int) $qid,
                            'valeur'         => $val,
                        ) );
                    }
                    self::notifier_organisateur( $evt, (int) $membre->id, false );
                    $retour = add_query_arg( 'evt', 'inscrit', $retour );
                }

            } elseif ( 'desinscrire' === $act && self::est_inscrit( $evt_id, $membre->id ) ) {
                $inscr_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM " . self::table_inscr() . " WHERE evenement_id=%d AND membre_id=%d",
                    $evt_id, (int) $membre->id
                ) );
                if ( $inscr_id ) {
                    $wpdb->delete( self::table_reponses(), array( 'inscription_id' => $inscr_id ) );
                }
                $wpdb->delete( self::table_inscr(), array( 'evenement_id' => $evt_id, 'membre_id' => (int) $membre->id ) );
                self::notifier_organisateur( $evt, (int) $membre->id, true );
                $retour = add_query_arg( 'evt', 'desinscrit', $retour );
            }
        }

        wp_safe_redirect( $retour );
        exit;
    }

    private static function notifier_organisateur( $evt, $membre_id, $desinscription ) {
        global $wpdb;

        $to = trim( (string) $evt->organisateur_email );
        if ( ! $to || ! is_email( $to ) ) {
            $fallback = $wpdb->get_var( "SELECT valeur FROM {$wpdb->prefix}seliweb_parametres WHERE cle='mail_inscrevt_to_email' LIMIT 1" );
            $to = ( $fallback && is_email( trim( $fallback ) ) ) ? trim( $fallback ) : get_option( 'admin_email' );
        }

        $wp_user_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}seliweb_membres WHERE id=%d", (int) $membre_id
        ) );
        $u     = $wp_user_id ? get_userdata( $wp_user_id ) : null;
        $nom   = $u ? ( trim( $u->first_name . ' ' . $u->last_name ) ?: $u->display_name ) : ( 'Membre #' . (int) $membre_id );
        $email = $u ? $u->user_email : '';

        $sujet = sprintf(
            $desinscription
                ? __( '[%1$s] Désinscription — %2$s', 'seliweb' )
                : __( '[%1$s] Nouvelle inscription — %2$s', 'seliweb' ),
            get_bloginfo( 'name' ), $evt->titre
        );

        $corps = implode( "\n", array(
            $desinscription
                ? __( 'Une personne s\'est désinscrite d\'un événement.', 'seliweb' )
                : __( 'Une personne s\'est inscrite à un événement.', 'seliweb' ),
            '',
            __( 'Événement :', 'seliweb' ) . ' ' . $evt->titre,
            __( 'Date :', 'seliweb' ) . ' ' . mysql2date( 'j F Y G\hi', $evt->date_debut ),
            '',
            __( 'Membre :', 'seliweb' ) . ' ' . $nom,
            __( 'E-mail :', 'seliweb' ) . ' ' . $email,
            '',
            sprintf( __( 'Inscrits à présent : %d', 'seliweb' ), self::nb_inscrits( (int) $evt->id ) ),
        ) );

        $headers = ( $email && is_email( $email ) ) ? array( 'Reply-To: ' . $nom . ' <' . $email . '>' ) : array();
        wp_mail( $to, $sujet, $corps, $headers );
    }

    // ----------------------------------------------------------------
    // Onglet « Événements » de Mon compte
    // ----------------------------------------------------------------
    public static function render_mon_compte( $membre_id, $groupe_id ) {
        $membre_id = (int) $membre_id;
        $a_venir   = self::get_pour_membre( $groupe_id );
        $passes    = self::get_passes_inscrit( $membre_id );

        $etat = isset( $_GET['evt'] ) ? sanitize_key( $_GET['evt'] ) : '';
        if ( 'inscrit' === $etat ) {
            echo '<div class="seliweb-notice seliweb-notice-ok">' . esc_html__( 'Votre inscription est enregistrée.', 'seliweb' ) . '</div>';
        } elseif ( 'desinscrit' === $etat ) {
            echo '<div class="seliweb-notice seliweb-notice-ok">' . esc_html__( 'Vous êtes désinscrit de cet événement.', 'seliweb' ) . '</div>';
        } elseif ( 'reponses' === $etat ) {
            echo '<div class="seliweb-notice seliweb-notice-error">' . esc_html__( 'Merci de répondre aux questions obligatoires avant de valider votre inscription.', 'seliweb' ) . '</div>';
        }

        echo '<h3>' . esc_html__( 'Événements à venir', 'seliweb' ) . '</h3>';
        if ( ! $a_venir ) {
            echo '<p class="seliweb-empty">' . esc_html__( 'Aucun événement à venir pour votre groupe.', 'seliweb' ) . '</p>';
        } else {
            foreach ( $a_venir as $e ) {
                self::render_mc_carte( $e, $membre_id, (int) $groupe_id );
            }
        }

        if ( $passes ) {
            echo '<h3 style="margin-top:28px;">' . esc_html__( 'Événements passés auxquels vous étiez inscrit', 'seliweb' ) . '</h3>';
            echo '<ul class="seliweb-evt-mc-passes">';
            foreach ( $passes as $e ) {
                echo '<li>' . esc_html( mysql2date( 'j M Y', $e->date_debut ) . ' — ' . $e->titre ) . '</li>';
            }
            echo '</ul>';
        }
    }

    private static function render_mc_carte( $e, $membre_id, $groupe_id ) {
        $inscrit   = self::est_inscrit( $e->id, $membre_id );
        $peut      = self::peut_s_inscrire( $e, $groupe_id );
        $ouvert    = $e->date_debut > current_time( 'mysql' );
        $questions = $e->inscription_requise ? self::get_questions( $e->id ) : array();
        ?>
        <div class="seliweb-evt-mc">
            <div class="seliweb-evt-mc-tete">
                <strong><?php echo esc_html( $e->titre ); ?></strong>
                <span class="seliweb-evt-mc-date"><?php echo esc_html( self::format_dates( $e->date_debut, $e->date_fin ) ); ?></span>
            </div>
            <?php if ( $e->horaires ) : ?><p class="seliweb-evt-mc-info"><?php echo esc_html( $e->horaires ); ?></p><?php endif; ?>
            <?php if ( $e->lieu || $e->adresse ) : ?>
                <p class="seliweb-evt-mc-info"><?php echo esc_html( trim( $e->lieu . ( $e->lieu && $e->adresse ? ' — ' : '' ) . $e->adresse ) ); ?></p>
            <?php endif; ?>
            <?php if ( $e->presentation ) : ?><div class="seliweb-evt-mc-pres"><?php echo wpautop( esc_html( $e->presentation ) ); ?></div><?php endif; ?>

            <?php if ( $e->inscription_requise ) : ?>
                <div class="seliweb-evt-mc-inscription">
                    <?php if ( $inscrit ) : ?>
                        <span class="seliweb-evt-mc-ok">&#10003; <?php esc_html_e( 'Vous êtes inscrit', 'seliweb' ); ?></span>
                        <?php
                        $mes_reponses = $questions ? self::get_reponses( $e->id, $membre_id ) : array();
                        if ( $mes_reponses ) : ?>
                            <ul class="seliweb-evt-mc-reponses">
                                <?php foreach ( $mes_reponses as $lib => $val ) : ?>
                                    <li><span class="seliweb-evt-mc-rq"><?php echo esc_html( $lib ); ?> :</span> <?php echo esc_html( $val ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ( $ouvert ) : ?>
                            <form method="post" style="display:inline;margin-left:8px;">
                                <?php wp_nonce_field( 'seliweb_evt_i_' . $e->id, 'seliweb_evt_i_nonce' ); ?>
                                <input type="hidden" name="evenement_id" value="<?php echo (int) $e->id; ?>">
                                <button type="submit" name="seliweb_evt_action" value="desinscrire" class="seliweb-btn seliweb-btn-secondary seliweb-btn-sm">
                                    <?php esc_html_e( 'Se désinscrire', 'seliweb' ); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ( ! $ouvert ) : ?>
                        <span class="seliweb-evt-mc-clos"><?php esc_html_e( 'Inscriptions closes', 'seliweb' ); ?></span>
                    <?php elseif ( $peut ) : ?>
                        <form method="post" class="seliweb-evt-mc-form">
                            <?php wp_nonce_field( 'seliweb_evt_i_' . $e->id, 'seliweb_evt_i_nonce' ); ?>
                            <input type="hidden" name="evenement_id" value="<?php echo (int) $e->id; ?>">
                            <?php if ( $questions ) : ?>
                                <div class="seliweb-evt-mc-questions">
                                    <?php foreach ( $questions as $q ) self::render_question_field( $q ); ?>
                                </div>
                            <?php endif; ?>
                            <button type="submit" name="seliweb_evt_action" value="inscrire" class="seliweb-btn seliweb-btn-sm">
                                <?php echo $questions ? esc_html__( 'Valider mon inscription', 'seliweb' ) : esc_html__( "S'inscrire", 'seliweb' ); ?>
                            </button>
                        </form>
                    <?php else : ?>
                        <span class="seliweb-evt-mc-clos"><?php esc_html_e( 'Inscription réservée à un autre groupe', 'seliweb' ); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
