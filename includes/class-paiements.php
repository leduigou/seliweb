<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Seliweb_Paiements {

    public static function init() {
        add_action( 'init', array( __CLASS__, 'handle_post' ) );
        add_action( 'init', array( __CLASS__, 'handle_delete' ) );
        add_action( 'init', array( __CLASS__, 'handle_webhook' ) );
        add_action( 'init', array( __CLASS__, 'handle_rattachement_post' ) );
        add_action( 'init', array( __CLASS__, 'handle_sync_offres_post' ) );
    }

    public static function render_tab() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $id     = isset( $_GET['id'] )     ? intval( $_GET['id'] )           : 0;

        switch ( $action ) {
            case 'new':
            case 'edit':
                self::form( $id );
                break;
            default:
                self::liste();
                break;
        }
    }

    // ----------------------------------------------------------------
    // Traitement POST — via hook init
    // ----------------------------------------------------------------
    public static function handle_post() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_POST['seliweb_nonce'] ) ) return;
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_parametres' ) return;
        if ( ! wp_verify_nonce( $_POST['seliweb_nonce'], 'seliweb_paiements' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements_offres';

        $enregistre_cotisation = isset( $_POST['enregistre_cotisation'] ) ? 1 : 0;
        $exercice              = sanitize_text_field( wp_unslash( $_POST['exercice'] ?? '' ) );
        $action                = sanitize_key( $_POST['seliweb_action'] );

        if ( $enregistre_cotisation && ! $exercice ) {
            $redir_action = $action === 'add_paiement' ? 'new' : 'edit';
            wp_safe_redirect( admin_url(
                'admin.php?page=seliweb_parametres&tab=paiements&action=' . $redir_action
                . '&id=' . intval( $_POST['id'] ?? 0 ) . '&error=exercice_requis'
            ) );
            exit;
        }

        $data = array(
            'nom'                   => sanitize_text_field( wp_unslash( $_POST['nom'] ) ),
            'description'           => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'tarif'                 => sanitize_text_field( wp_unslash( $_POST['tarif'] ?? '' ) ),
            'groupe_depart_id'      => ! empty( $_POST['groupe_depart_id'] )  ? intval( $_POST['groupe_depart_id'] )  : null,
            'groupe_arrivee_id'     => ! empty( $_POST['groupe_arrivee_id'] ) ? intval( $_POST['groupe_arrivee_id'] ) : null,
            'enregistre_cotisation' => $enregistre_cotisation,
            'exercice'              => $enregistre_cotisation ? $exercice : null,
            'compte_paheko_banque'  => ! $enregistre_cotisation ? sanitize_text_field( wp_unslash( $_POST['compte_paheko_banque']  ?? '' ) ) ?: null : null,
            'compte_paheko_recette' => ! $enregistre_cotisation ? sanitize_text_field( wp_unslash( $_POST['compte_paheko_recette'] ?? '' ) ) ?: null : null,
            'helloasso_url'         => esc_url_raw( wp_unslash( $_POST['helloasso_url'] ?? '' ) ),
        );

        if ( $action === 'add_paiement' ) {
            $wpdb->insert( $tp, $data );
        } elseif ( $action === 'update_paiement' ) {
            $wpdb->update( $tp, $data, array( 'id' => intval( $_POST['id'] ) ) );
        } else {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=paiements&updated=1' ) );
        exit;
    }

    public static function handle_delete() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_parametres' ) return;
        if ( ! isset( $_GET['action'], $_GET['id'] ) || $_GET['action'] !== 'delete' ) return;
        if ( ! isset( $_GET['tab'] ) || $_GET['tab'] !== 'paiements' ) return;
        if ( ! check_admin_referer( 'seliweb_delete_paiement_' . intval( $_GET['id'] ) ) ) return;

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'seliweb_paiements_offres', array( 'id' => intval( $_GET['id'] ) ) );

        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_parametres&tab=paiements&deleted=1' ) );
        exit;
    }

    // ----------------------------------------------------------------
    // Liste des paiements configurés
    // ----------------------------------------------------------------
    private static function liste() {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements_offres';
        $tg = $wpdb->prefix . 'seliweb_groupes';
        $items = $wpdb->get_results(
            "SELECT p.*, gd.nom AS depart_nom, ga.nom AS arrivee_nom
             FROM $tp p
             LEFT JOIN $tg gd ON gd.id = p.groupe_depart_id
             LEFT JOIN $tg ga ON ga.id = p.groupe_arrivee_id
             ORDER BY p.nom ASC"
        );

        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paiement enregistré.', 'seliweb' ) . '</p></div>';
        if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paiement supprimé.', 'seliweb' ) . '</p></div>';
        ?>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=paiements&action=new' ) ); ?>"
               class="button button-primary"><?php esc_html_e( 'Ajouter un paiement', 'seliweb' ); ?></a>
        </p>
        <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
            <thead><tr>
                <th><?php esc_html_e( 'Nom', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Groupe de départ', 'seliweb' ); ?></th>
                <th><?php esc_html_e( "Groupe d'arrivée", 'seliweb' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Tarif', 'seliweb' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Cotisation', 'seliweb' ); ?></th>
                <th style="width:140px;"><?php esc_html_e( 'Actions', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php if ( empty( $items ) ) : ?>
                <tr><td colspan="6"><em><?php esc_html_e( 'Aucun paiement configuré.', 'seliweb' ); ?></em></td></tr>
            <?php else : ?>
                <?php foreach ( $items as $row ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $row->nom ); ?></strong></td>
                    <td><?php echo $row->depart_nom ? esc_html( $row->depart_nom ) : '<em>' . esc_html__( 'Tous les groupes', 'seliweb' ) . '</em>'; ?></td>
                    <td><?php echo $row->arrivee_nom ? esc_html( $row->arrivee_nom ) : '<em>' . esc_html__( 'Aucun changement', 'seliweb' ) . '</em>'; ?></td>
                    <td><?php echo esc_html( $row->tarif ); ?></td>
                    <td style="text-align:center;">
                        <?php if ( $row->enregistre_cotisation ) : ?>
                            <span style="color:green;font-weight:700;" title="<?php esc_attr_e( 'Enregistre une cotisation', 'seliweb' ); ?>">&#10003;</span>
                            <?php if ( $row->exercice ) : ?>
                                <br><span style="font-size:11px;color:#888;"><?php echo esc_html( $row->exercice ); ?></span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span style="color:#ccc;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=paiements&action=edit&id=' . $row->id ) ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=seliweb_parametres&tab=paiements&action=delete&id=' . $row->id ), 'seliweb_delete_paiement_' . $row->id ) ); ?>"
                           onclick="return confirm('<?php esc_attr_e( 'Supprimer ce paiement ?', 'seliweb' ); ?>')"
                           style="color:#b32d2e;"><?php esc_html_e( 'Supprimer', 'seliweb' ); ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    // ----------------------------------------------------------------
    // Formulaire ajout / modification
    // ----------------------------------------------------------------
    private static function form( $id = 0 ) {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements_offres';
        $tg = $wpdb->prefix . 'seliweb_groupes';
        $te = $wpdb->prefix . 'seliweb_exercices';

        $item          = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tp WHERE id=%d", $id ) ) : null;
        $tous_groupes  = $wpdb->get_results( "SELECT id, nom FROM $tg ORDER BY nom ASC" );
        $tous_exercices = $wpdb->get_results( "SELECT libelle FROM $te ORDER BY date_debut DESC, id DESC" );
        $is_edit       = (bool) $item;

        $comptes_banque = get_transient( 'seliweb_paheko_comptes_5' );
        if ( false === $comptes_banque && class_exists( 'Seliweb_Cotisations' ) ) {
            $comptes_banque = Seliweb_Cotisations::paheko_get_comptes( '5' );
            set_transient( 'seliweb_paheko_comptes_5', $comptes_banque, 600 );
        }
        $comptes_recette = get_transient( 'seliweb_paheko_comptes_7' );
        if ( false === $comptes_recette && class_exists( 'Seliweb_Cotisations' ) ) {
            $comptes_recette = Seliweb_Cotisations::paheko_get_comptes( '7' );
            set_transient( 'seliweb_paheko_comptes_7', $comptes_recette, 600 );
        }
        $comptes_banque  = $comptes_banque  ?: array();
        $comptes_recette = $comptes_recette ?: array();

        if ( isset( $_GET['error'] ) && $_GET['error'] === 'exercice_requis' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( "L'exercice est obligatoire lorsque le paiement enregistre une cotisation.", 'seliweb' ) . '</p></div>';
        }
        ?>
        <form method="post" style="max-width:700px;">
            <?php wp_nonce_field( 'seliweb_paiements', 'seliweb_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="<?php echo $is_edit ? 'update_paiement' : 'add_paiement'; ?>">
            <?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo intval( $item->id ); ?>"><?php endif; ?>

            <table class="form-table">

                <tr>
                    <th><label for="nom"><?php esc_html_e( 'Nom', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="text" id="nom" name="nom" class="regular-text"
                               value="<?php echo $item ? esc_attr( $item->nom ) : ''; ?>" required>
                        <p class="description"><?php esc_html_e( 'Titre affiché dans Mon compte, ex. : "Adhésion au SEL".', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label for="description"><?php esc_html_e( 'Description', 'seliweb' ); ?></label></th>
                    <td>
                        <textarea id="description" name="description" class="large-text" rows="4"><?php echo $item ? esc_textarea( $item->description ) : ''; ?></textarea>
                        <p class="description"><?php esc_html_e( 'Texte explicatif affiché dans Mon compte.', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label for="tarif"><?php esc_html_e( 'Tarif', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="text" id="tarif" name="tarif" class="regular-text"
                               value="<?php echo $item ? esc_attr( $item->tarif ) : ''; ?>" placeholder="<?php esc_attr_e( 'Ex. : 20 €/an', 'seliweb' ); ?>">
                        <p class="description"><?php esc_html_e( 'Texte libre affiché à titre indicatif — le montant réel est celui réglé sur HelloAsso.', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label for="groupe_depart_id"><?php esc_html_e( 'Groupe de départ', 'seliweb' ); ?></label></th>
                    <td>
                        <select id="groupe_depart_id" name="groupe_depart_id">
                            <option value=""><?php esc_html_e( '— Tous les groupes —', 'seliweb' ); ?></option>
                            <?php foreach ( $tous_groupes as $g ) : ?>
                                <option value="<?php echo intval( $g->id ); ?>" <?php selected( $item ? $item->groupe_depart_id : '', $g->id ); ?>>
                                    <?php echo esc_html( $g->nom ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Ce paiement n\'apparaîtra dans Mon compte que pour les membres de ce groupe. Laisser sur "Tous les groupes" pour le rendre accessible à tous.', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><label for="groupe_arrivee_id"><?php esc_html_e( "Groupe d'arrivée", 'seliweb' ); ?></label></th>
                    <td>
                        <select id="groupe_arrivee_id" name="groupe_arrivee_id">
                            <option value=""><?php esc_html_e( '— Aucun changement —', 'seliweb' ); ?></option>
                            <?php foreach ( $tous_groupes as $g ) : ?>
                                <option value="<?php echo intval( $g->id ); ?>" <?php selected( $item ? $item->groupe_arrivee_id : '', $g->id ); ?>>
                                    <?php echo esc_html( $g->nom ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Groupe attribué au membre une fois le paiement effectué. Laisser sur "Aucun changement" pour un renouvellement ou un don.', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Cotisation', 'seliweb' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enregistre_cotisation" id="enregistre_cotisation" value="1"
                                   <?php checked( $item ? $item->enregistre_cotisation : 0 ); ?>>
                            <?php esc_html_e( 'Ce paiement enregistre une cotisation', 'seliweb' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'À cocher pour une adhésion ou un renouvellement de cotisation. Laisser décoché pour une offre (ex. passage au groupe Annonceurs) ou un don.', 'seliweb' ); ?></p>
                    </td>
                </tr>

                <tr id="row_exercice" style="<?php echo ( $item && $item->enregistre_cotisation ) ? '' : 'display:none;'; ?>">
                    <th><label for="exercice"><?php esc_html_e( 'Exercice concerné', 'seliweb' ); ?></label></th>
                    <td>
                        <?php if ( $tous_exercices ) : ?>
                            <select id="exercice" name="exercice">
                                <option value=""><?php esc_html_e( '— Choisir —', 'seliweb' ); ?></option>
                                <?php foreach ( $tous_exercices as $ex ) : ?>
                                    <option value="<?php echo esc_attr( $ex->libelle ); ?>" <?php selected( $item ? $item->exercice : '', $ex->libelle ); ?>>
                                        <?php echo esc_html( $ex->libelle ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Le formulaire HelloAsso saisi ci-dessous correspond à cet exercice de cotisation.', 'seliweb' ); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e( 'Aucun exercice défini. Paramètres > Cotisations > Exercices.', 'seliweb' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr id="row_compte_banque" style="<?php echo ( $item && $item->enregistre_cotisation ) ? 'display:none;' : ''; ?>">
                    <th><label for="compte_paheko_banque"><?php esc_html_e( 'Compte banque (Paheko)', 'seliweb' ); ?></label></th>
                    <td>
                        <?php if ( $comptes_banque ) : ?>
                            <select id="compte_paheko_banque" name="compte_paheko_banque">
                                <option value=""><?php esc_html_e( '— Choisir —', 'seliweb' ); ?></option>
                                <?php foreach ( $comptes_banque as $c ) : ?>
                                    <option value="<?php echo esc_attr( $c['code'] ); ?>" <?php selected( $item ? $item->compte_paheko_banque : '', $c['code'] ); ?>>
                                        <?php echo esc_html( $c['code'] . ' — ' . $c['label'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Compte sur lequel HelloAsso reverse ce paiement (ex. banque, ou compte "à recevoir" si versement différé).', 'seliweb' ); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e( 'Aucun compte trouvé — vérifiez la connexion Paheko dans Paramètres > API.', 'seliweb' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr id="row_compte_recette" style="<?php echo ( $item && $item->enregistre_cotisation ) ? 'display:none;' : ''; ?>">
                    <th><label for="compte_paheko_recette"><?php esc_html_e( 'Compte de recette (Paheko)', 'seliweb' ); ?></label></th>
                    <td>
                        <?php if ( $comptes_recette ) : ?>
                            <select id="compte_paheko_recette" name="compte_paheko_recette">
                                <option value=""><?php esc_html_e( '— Choisir —', 'seliweb' ); ?></option>
                                <?php foreach ( $comptes_recette as $c ) : ?>
                                    <option value="<?php echo esc_attr( $c['code'] ); ?>" <?php selected( $item ? $item->compte_paheko_recette : '', $c['code'] ); ?>>
                                        <?php echo esc_html( $c['code'] . ' — ' . $c['label'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Compte de produit crédité lors de la synchronisation comptable de ce paiement.', 'seliweb' ); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e( 'Aucun compte trouvé — vérifiez la connexion Paheko dans Paramètres > API.', 'seliweb' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th><label for="helloasso_url"><?php esc_html_e( 'Lien vers le formulaire HelloAsso', 'seliweb' ); ?></label></th>
                    <td>
                        <input type="url" id="helloasso_url" name="helloasso_url" class="large-text"
                               value="<?php echo $item ? esc_attr( $item->helloasso_url ) : ''; ?>"
                               placeholder="https://www.helloasso.com/associations/mon-sel/adhesions/adhesion-2026">
                    </td>
                </tr>

            </table>

            <?php submit_button( $is_edit ? __( 'Mettre à jour', 'seliweb' ) : __( 'Ajouter', 'seliweb' ) ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_parametres&tab=paiements' ) ); ?>" class="button">
                <?php esc_html_e( 'Annuler', 'seliweb' ); ?>
            </a>
        </form>

        <script>
        (function(){
            var cb  = document.getElementById('enregistre_cotisation');
            var rowExercice = document.getElementById('row_exercice');
            var rowBanque   = document.getElementById('row_compte_banque');
            var rowRecette  = document.getElementById('row_compte_recette');
            if ( cb ) {
                cb.addEventListener('change', function(){
                    if ( rowExercice ) rowExercice.style.display = this.checked ? '' : 'none';
                    if ( rowBanque )   rowBanque.style.display   = this.checked ? 'none' : '';
                    if ( rowRecette )  rowRecette.style.display  = this.checked ? 'none' : '';
                });
            }
        })();
        </script>
        <?php
    }

    // ----------------------------------------------------------------
    // Méthode utilitaire — paiements accessibles à un membre selon son groupe
    // ----------------------------------------------------------------
    public static function get_offres_pour_membre( $groupe_id ) {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements_offres';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $tp WHERE groupe_depart_id IS NULL OR groupe_depart_id = %d ORDER BY nom ASC",
            intval( $groupe_id )
        ) );
    }

    // ----------------------------------------------------------------
    // Méthode utilitaire — historique des paiements (hors cotisations,
    // déjà affichées dans l'onglet "Mes cotisations") d'un membre
    // ----------------------------------------------------------------
    public static function get_historique_paiements_membre( $wp_user_id ) {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements';
        $to = $wpdb->prefix . 'seliweb_paiements_offres';
        $tg = $wpdb->prefix . 'seliweb_groupes';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.date_paiement, p.montant, o.nom AS offre_nom,
                    gd.nom AS depart_nom, ga.nom AS arrivee_nom
             FROM $tp p
             INNER JOIN $to o ON o.id = p.offre_id
             LEFT JOIN  $tg gd ON gd.id = o.groupe_depart_id
             LEFT JOIN  $tg ga ON ga.id = o.groupe_arrivee_id
             WHERE p.statut = 'rattache'
               AND p.wp_user_id = %d
               AND o.enregistre_cotisation = 0
             ORDER BY p.date_paiement DESC, p.id DESC",
            intval( $wp_user_id )
        ) );
    }

    // ================================================================
    // WEBHOOK HELLOASSO  (?seliweb_helloasso_webhook=1)
    // ================================================================
    public static function handle_webhook() {
        if ( ! isset( $_GET['seliweb_helloasso_webhook'] ) ) return;

        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            http_response_code( 405 );
            exit( 'Method Not Allowed' );
        }

        $body = file_get_contents( 'php://input' );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            http_response_code( 400 );
            exit( 'Bad Request' );
        }

        $event = $data['eventType'] ?? '';
        if ( $event === 'Order' && ! empty( $data['data'] ) ) {
            self::process_order( $data['data'] );
        }

        http_response_code( 200 );
        exit( 'OK' );
    }

    // Extrait le dernier segment d'une URL de formulaire HelloAsso
    // (…/adhesions/adhesion-2026 → adhesion-2026), pour retrouver l'offre
    // correspondante à partir du form_slug reçu dans le paiement.
    private static function extract_form_slug( $url ) {
        if ( preg_match( '~/associations/[^/]+/[^/]+/([^/?#]+)~', (string) $url, $m ) ) {
            return $m[1];
        }
        return '';
    }

    private static function find_offre_par_form_slug( $form_slug ) {
        if ( ! $form_slug ) return null;
        global $wpdb;
        $tp     = $wpdb->prefix . 'seliweb_paiements_offres';
        $offres = $wpdb->get_results( "SELECT * FROM $tp WHERE helloasso_url IS NOT NULL AND helloasso_url != ''" );
        foreach ( $offres as $offre ) {
            if ( self::extract_form_slug( $offre->helloasso_url ) === $form_slug ) {
                return $offre;
            }
        }
        return null;
    }

    // ================================================================
    // TRAITEMENT D'UN PAIEMENT
    // ================================================================
    // Journalise systématiquement le paiement reçu. Applique les actions
    // de l'offre (cotisation, changement de groupe) uniquement si le
    // paiement a pu être rattaché à la fois à une offre connue (via le
    // formulaire HelloAsso) et à un membre (via l'email du payeur) —
    // sinon il reste "en_attente" pour un rattachement manuel.
    public static function process_order( $order ) {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements';

        $ha_order_id = (string) ( $order['id'] ?? '' );
        if ( ! $ha_order_id ) return;

        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tp WHERE helloasso_order_id=%s LIMIT 1", $ha_order_id ) ) ) {
            return; // déjà traité
        }

        $payer        = $order['payer'] ?? array();
        $email        = sanitize_email( $payer['email'] ?? '' );
        $prenom       = sanitize_text_field( $payer['firstName'] ?? '' );
        $nom_famille  = sanitize_text_field( $payer['lastName']  ?? '' );
        $nom          = trim( $prenom . ' ' . $nom_famille );
        $amount_cents = intval( $order['amount']['total'] ?? 0 );
        $date         = substr( $order['date'] ?? current_time( 'Y-m-d' ), 0, 10 );

        // Le nom du champ exact (formSlug au niveau de la commande, ou dans
        // le premier article) dépend de la structure réelle envoyée par
        // HelloAsso — à confirmer avec un paiement réel une fois le webhook
        // exposé publiquement. Reste sans effet bloquant si absent : le
        // paiement est alors simplement journalisé en attente.
        $form_slug = sanitize_text_field( $order['formSlug'] ?? ( $order['items'][0]['formSlug'] ?? '' ) );
        $offre     = self::find_offre_par_form_slug( $form_slug );

        $wp_user    = $email ? get_user_by( 'email', $email ) : null;
        $wp_user_id = $wp_user ? $wp_user->ID : 0;

        $matched       = $offre && $wp_user_id;
        $cotisation_id = $matched ? self::apply_offre_actions( $offre, $wp_user_id, $amount_cents, $date, $ha_order_id, $email, $nom ) : null;

        $wpdb->insert( $tp, array(
            'offre_id'            => $offre ? $offre->id : null,
            'wp_user_id'          => $wp_user_id ?: null,
            'cotisation_id'       => $cotisation_id,
            'montant'             => $amount_cents,
            'date_paiement'       => $date,
            'statut'              => $matched ? 'rattache' : 'en_attente',
            'helloasso_order_id'  => $ha_order_id,
            'helloasso_form_slug' => $form_slug,
            'payer_email'         => $email,
            'payer_nom'           => $nom,
            'created_at'          => current_time( 'mysql' ),
        ) );
    }

    // ----------------------------------------------------------------
    // Applique les actions d'une offre à un membre identifié : enregistre
    // une cotisation si l'offre le prévoit, puis change son groupe si un
    // groupe d'arrivée est configuré. Utilisée par le rattachement
    // automatique (webhook) comme par le rattachement manuel.
    // Retourne l'id de la cotisation créée, ou null si aucune.
    // ----------------------------------------------------------------
    private static function apply_offre_actions( $offre, $wp_user_id, $montant, $date, $ha_order_id = '', $email = '', $nom = '' ) {
        global $wpdb;
        $cotisation_id = null;

        if ( $offre->enregistre_cotisation && class_exists( 'Seliweb_Cotisations' ) ) {
            $cotisation_id = Seliweb_Cotisations::enregistrer_paiement_cotisation(
                $wp_user_id, $montant, $date, $ha_order_id, $email, $nom, $offre->exercice, $offre->nom
            );
        }
        if ( $offre->groupe_arrivee_id ) {
            $wpdb->update(
                $wpdb->prefix . 'seliweb_membres',
                array( 'groupe_id' => intval( $offre->groupe_arrivee_id ) ),
                array( 'wp_user_id' => $wp_user_id )
            );
        }

        return $cotisation_id;
    }

    // ================================================================
    // ADMIN — vue "Paiements à rattacher" (page Cotisations, view=a_rattacher)
    // ================================================================
    public static function render_view_a_rattacher( $section = 'cotisations' ) {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements';
        $to = $wpdb->prefix . 'seliweb_paiements_offres';

        if ( isset( $_GET['rattache'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paiement rattaché.', 'seliweb' ) . '</p></div>';
        }

        $paiements = $wpdb->get_results(
            "SELECT p.*, o.nom AS offre_nom
             FROM $tp p
             LEFT JOIN $to o ON o.id = p.offre_id
             WHERE p.statut = 'en_attente'
             ORDER BY p.created_at DESC"
        );

        $membres = $wpdb->get_results(
            "SELECT m.wp_user_id, u.display_name
             FROM {$wpdb->prefix}seliweb_membres m
             INNER JOIN {$wpdb->users} u ON u.ID = m.wp_user_id
             ORDER BY u.display_name ASC"
        );
        $offres = $wpdb->get_results( "SELECT id, nom FROM $to ORDER BY nom ASC" );

        if ( empty( $paiements ) ) {
            echo '<p class="description">' . esc_html__( 'Aucun paiement en attente de rattachement.', 'seliweb' ) . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th style="width:90px;"><?php esc_html_e( 'Date', 'seliweb' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Montant', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Payeur', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Offre reconnue', 'seliweb' ); ?></th>
                <th style="width:320px;"><?php esc_html_e( 'Rattacher à', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $paiements as $p ) : ?>
                <tr>
                    <td><?php echo esc_html( $p->date_paiement ); ?></td>
                    <td><?php echo esc_html( number_format( $p->montant / 100, 2, ',', ' ' ) ); ?> €</td>
                    <td>
                        <?php echo esc_html( $p->payer_nom ); ?><br>
                        <span style="color:#888;font-size:12px;"><?php echo esc_html( $p->payer_email ); ?></span>
                    </td>
                    <td>
                        <?php echo $p->offre_nom ? esc_html( $p->offre_nom ) : '<em style="color:#b32d2e;">' . esc_html__( 'Inconnue', 'seliweb' ) . '</em>'; ?>
                        <?php if ( $p->helloasso_form_slug ) : ?>
                            <br><span style="color:#888;font-size:11px;"><?php echo esc_html( $p->helloasso_form_slug ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                            <?php wp_nonce_field( 'seliweb_rattacher_paiement', 'seliweb_nonce' ); ?>
                            <input type="hidden" name="seliweb_action" value="rattacher_paiement">
                            <input type="hidden" name="paiement_id" value="<?php echo intval( $p->id ); ?>">
                            <input type="hidden" name="section" value="<?php echo esc_attr( $section ); ?>">
                            <select name="wp_user_id" required style="max-width:150px;">
                                <option value=""><?php esc_html_e( '— Membre —', 'seliweb' ); ?></option>
                                <?php foreach ( $membres as $m ) : ?>
                                    <option value="<?php echo intval( $m->wp_user_id ); ?>" <?php selected( $p->wp_user_id, $m->wp_user_id ); ?>>
                                        <?php echo esc_html( $m->display_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="offre_id" style="max-width:130px;">
                                <option value=""><?php esc_html_e( '— Aucune —', 'seliweb' ); ?></option>
                                <?php foreach ( $offres as $o ) : ?>
                                    <option value="<?php echo intval( $o->id ); ?>" <?php selected( $p->offre_id, $o->id ); ?>>
                                        <?php echo esc_html( $o->nom ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Rattacher', 'seliweb' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function handle_rattachement_post() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_POST['seliweb_nonce'], $_POST['seliweb_action'] ) ) return;
        if ( $_POST['seliweb_action'] !== 'rattacher_paiement' ) return;
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_cotisations' ) return;
        if ( ! wp_verify_nonce( $_POST['seliweb_nonce'], 'seliweb_rattacher_paiement' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements';
        $to = $wpdb->prefix . 'seliweb_paiements_offres';

        $paiement_id = intval( $_POST['paiement_id'] );
        $wp_user_id  = intval( $_POST['wp_user_id'] );
        $offre_id    = ! empty( $_POST['offre_id'] ) ? intval( $_POST['offre_id'] ) : null;
        if ( ! $paiement_id || ! $wp_user_id ) return;

        $paiement = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tp WHERE id=%d AND statut='en_attente'", $paiement_id ) );
        if ( ! $paiement ) return;

        $cotisation_id = null;
        if ( $offre_id ) {
            $offre = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $to WHERE id=%d", $offre_id ) );
            if ( $offre ) {
                $cotisation_id = self::apply_offre_actions(
                    $offre, $wp_user_id, $paiement->montant, $paiement->date_paiement,
                    $paiement->helloasso_order_id, $paiement->payer_email, $paiement->payer_nom
                );
            }
        }

        $wpdb->update( $tp, array(
            'wp_user_id'    => $wp_user_id,
            'offre_id'      => $offre_id,
            'cotisation_id' => $cotisation_id,
            'statut'        => 'rattache',
        ), array( 'id' => $paiement_id ) );

        $section = in_array( $_POST['section'] ?? '', array( 'cotisations', 'offres' ), true ) ? $_POST['section'] : 'cotisations';
        wp_safe_redirect( admin_url( 'admin.php?page=seliweb_cotisations&section=' . $section . '&view=a_rattacher&rattache=1' ) );
        exit;
    }

    // ================================================================
    // ADMIN — vue "Offres > Synchronisation Paheko"
    // ================================================================
    public static function render_view_offres_sync() {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements';
        $to = $wpdb->prefix . 'seliweb_paiements_offres';

        if ( isset( $_GET['synced'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . sprintf( esc_html__( '%d paiement(s) synchronisé(s) avec Paheko.', 'seliweb' ), intval( $_GET['synced'] ) )
                . '</p></div>';
        }
        if ( isset( $_GET['sync_errors'] ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html__( 'Certaines synchronisations ont échoué (membre introuvable ou non créé dans Paheko).', 'seliweb' )
                . '</p></div>';
        }

        $paiements = $wpdb->get_results(
            "SELECT p.*, o.nom AS offre_nom, o.compte_paheko_banque, o.compte_paheko_recette, u.display_name
             FROM $tp p
             INNER JOIN $to o ON o.id = p.offre_id
             LEFT JOIN  {$wpdb->users} u ON u.ID = p.wp_user_id
             WHERE p.statut = 'rattache'
               AND o.enregistre_cotisation = 0
               AND p.paheko_synced = 0
             ORDER BY p.date_paiement DESC, p.id DESC"
        );

        if ( ! $paiements ) {
            echo '<p>' . esc_html__( 'Aucun paiement en attente de synchronisation.', 'seliweb' ) . '</p>';
            return;
        }

        $paheko_years = get_transient( 'seliweb_paheko_years' );
        if ( false === $paheko_years && class_exists( 'Seliweb_Cotisations' ) ) {
            $paheko_years = Seliweb_Cotisations::paheko_get_years();
            set_transient( 'seliweb_paheko_years', $paheko_years, 600 );
        }
        $paheko_years = $paheko_years ?: array();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_cotisations&section=offres&view=sync_offres' ) ); ?>">
            <?php wp_nonce_field( 'seliweb_sync_offres', 'seliweb_sync_nonce' ); ?>
            <input type="hidden" name="seliweb_action" value="sync_offres">

            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th style="width:30px;"><input type="checkbox" id="check_all_offres" title="<?php esc_attr_e( 'Tout sélectionner', 'seliweb' ); ?>"></th>
                    <th><?php esc_html_e( 'Membre', 'seliweb' ); ?></th>
                    <th><?php esc_html_e( 'Offre', 'seliweb' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Montant', 'seliweb' ); ?></th>
                    <th style="width:80px;"><?php esc_html_e( 'Date', 'seliweb' ); ?></th>
                    <th style="width:140px;"><?php esc_html_e( 'Comptes (banque / recette)', 'seliweb' ); ?></th>
                    <th style="width:160px;"><?php esc_html_e( 'Exercice Paheko', 'seliweb' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $paiements as $p ) : ?>
                    <tr>
                        <td><input type="checkbox" name="paiement_ids[]" value="<?php echo intval( $p->id ); ?>"></td>
                        <td><?php echo esc_html( $p->display_name ?: $p->payer_nom ); ?></td>
                        <td><?php echo esc_html( $p->offre_nom ); ?></td>
                        <td><?php echo esc_html( number_format( $p->montant / 100, 2, ',', ' ' ) ); ?> €</td>
                        <td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $p->date_paiement ) ) ); ?></td>
                        <td style="color:#888;">
                            <?php if ( $p->compte_paheko_banque && $p->compte_paheko_recette ) : ?>
                                <?php echo esc_html( $p->compte_paheko_banque . ' / ' . $p->compte_paheko_recette ); ?>
                            <?php else : ?>
                                <em style="color:#b32d2e;"><?php esc_html_e( 'Non configurés sur l\'offre', 'seliweb' ); ?></em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $paheko_years ) : ?>
                                <select name="paheko_year[<?php echo intval( $p->id ); ?>]" style="max-width:150px;">
                                    <option value=""><?php esc_html_e( '— Choisir —', 'seliweb' ); ?></option>
                                    <?php foreach ( $paheko_years as $yr ) : ?>
                                        <option value="<?php echo intval( $yr['id'] ); ?>"><?php echo esc_html( $yr['label'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else : ?>
                                <em class="description"><?php esc_html_e( 'Non disponible', 'seliweb' ); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:16px;">
                <?php submit_button( __( 'Synchroniser la sélection', 'seliweb' ), 'primary', 'submit', false ); ?>
            </p>
        </form>

        <script>
        (function(){
            var all = document.getElementById('check_all_offres');
            if ( all ) {
                all.addEventListener('change', function(){
                    document.querySelectorAll('input[name="paiement_ids[]"]').forEach(function(cb){ cb.checked = this.checked; }, this);
                });
            }
        })();
        </script>
        <?php
    }

    public static function handle_sync_offres_post() {
        if ( ! is_admin() ) return;
        if ( ! isset( $_POST['seliweb_action'] ) || $_POST['seliweb_action'] !== 'sync_offres' ) return;
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'seliweb_cotisations' ) return;
        if ( ! wp_verify_nonce( $_POST['seliweb_sync_nonce'] ?? '', 'seliweb_sync_offres' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! class_exists( 'Seliweb_Cotisations' ) ) return;

        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements';
        $to = $wpdb->prefix . 'seliweb_paiements_offres';

        $ids   = array_map( 'intval', (array) ( $_POST['paiement_ids'] ?? array() ) );
        $years = $_POST['paheko_year'] ?? array();

        $ok = 0; $errors = 0;

        foreach ( $ids as $paiement_id ) {
            $p = $wpdb->get_row( $wpdb->prepare(
                "SELECT p.*, o.compte_paheko_banque, o.compte_paheko_recette
                 FROM $tp p INNER JOIN $to o ON o.id = p.offre_id
                 WHERE p.id=%d AND p.statut='rattache' AND p.paheko_synced=0",
                $paiement_id
            ) );
            $id_year = intval( $years[ $paiement_id ] ?? 0 );
            if ( ! $p || ! $id_year || ! $p->compte_paheko_banque || ! $p->compte_paheko_recette ) {
                $errors++;
                continue;
            }

            $email = $p->payer_email;
            $nom   = $p->payer_nom;
            if ( ! $email && $p->wp_user_id ) {
                $user  = get_userdata( $p->wp_user_id );
                $email = $user ? $user->user_email : '';
                $nom   = $user ? $user->display_name : $nom;
            }

            $paheko_user = $email ? Seliweb_Cotisations::paheko_find_user_by_email( $email ) : null;
            $paheko_user_id = $paheko_user ? $paheko_user['id'] : ( $nom ? Seliweb_Cotisations::paheko_create_user( $nom, $email ) : null );
            if ( ! $paheko_user_id ) { $errors++; continue; }

            $date_fr = date( 'd/m/Y', strtotime( $p->date_paiement ) );
            $label   = sanitize_text_field( $nom );
            $result  = Seliweb_Cotisations::paheko_create_transaction(
                $id_year, $label, $date_fr, round( $p->montant / 100, 2 ),
                $p->compte_paheko_banque, $paheko_user_id, $p->compte_paheko_recette
            );

            if ( $result ) {
                $wpdb->update( $tp, array( 'paheko_synced' => 1, 'paheko_id_year' => $id_year ), array( 'id' => $paiement_id ) );
                $ok++;
            } else {
                $errors++;
            }
        }

        $redirect = admin_url( 'admin.php?page=seliweb_cotisations&section=offres&view=sync_offres'
            . ( $ok     ? '&synced='      . $ok     : '' )
            . ( $errors ? '&sync_errors=' . $errors  : '' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    // ================================================================
    // ADMIN — vue "Offres > Paiements" (paiements déjà rattachés, hors cotisation)
    // ================================================================
    public static function render_view_offres_liste() {
        global $wpdb;
        $tp = $wpdb->prefix . 'seliweb_paiements';
        $to = $wpdb->prefix . 'seliweb_paiements_offres';
        $tg = $wpdb->prefix . 'seliweb_groupes';

        $paiements = $wpdb->get_results(
            "SELECT p.*, o.nom AS offre_nom, u.display_name,
                    gd.nom AS depart_nom, ga.nom AS arrivee_nom
             FROM $tp p
             INNER JOIN $to o ON o.id = p.offre_id
             LEFT JOIN  {$wpdb->users} u ON u.ID = p.wp_user_id
             LEFT JOIN  $tg gd ON gd.id = o.groupe_depart_id
             LEFT JOIN  $tg ga ON ga.id = o.groupe_arrivee_id
             WHERE p.statut = 'rattache'
               AND o.enregistre_cotisation = 0
             ORDER BY p.date_paiement DESC, p.id DESC"
        );

        if ( empty( $paiements ) ) {
            echo '<p class="description">' . esc_html__( 'Aucun paiement enregistré pour le moment.', 'seliweb' ) . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th style="width:90px;"><?php esc_html_e( 'Date', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Membre', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Offre', 'seliweb' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Montant', 'seliweb' ); ?></th>
                <th><?php esc_html_e( 'Transition de groupe', 'seliweb' ); ?></th>
                <th style="width:110px;"><?php esc_html_e( 'Sync Paheko', 'seliweb' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $paiements as $p ) :
                $transition = '—';
                if ( $p->depart_nom && $p->arrivee_nom ) {
                    $transition = esc_html( $p->depart_nom ) . ' &rarr; ' . esc_html( $p->arrivee_nom );
                } elseif ( $p->arrivee_nom ) {
                    $transition = '&rarr; ' . esc_html( $p->arrivee_nom );
                }
            ?>
                <tr>
                    <td><?php echo esc_html( $p->date_paiement ); ?></td>
                    <td><?php echo esc_html( $p->display_name ?: $p->payer_nom ); ?></td>
                    <td><?php echo esc_html( $p->offre_nom ); ?></td>
                    <td><?php echo esc_html( number_format( $p->montant / 100, 2, ',', ' ' ) ); ?> €</td>
                    <td><?php echo $transition; ?></td>
                    <td>
                        <?php if ( ! empty( $p->paheko_synced ) ) : ?>
                            <span style="color:green;font-weight:600;"><?php esc_html_e( 'Synchronisé', 'seliweb' ); ?></span>
                        <?php else : ?>
                            <span style="color:#dba617;"><?php esc_html_e( 'En attente', 'seliweb' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
