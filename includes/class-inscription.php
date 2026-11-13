<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Formulaire public commun à l'inscription [seliweb_inscription] et à la
 * demande d'adhésion [seliweb_demande_adhesion].
 *
 * Même formulaire, même contrôle de l'adresse e-mail par un code à
 * 4 chiffres, mêmes réglages (Paramètres → Inscription, un sous-onglet par
 * mode : groupe par défaut, titre et texte du consentement). Seule
 * différence, une fois le code validé :
 *  - inscription : le compte est créé tout de suite ;
 *  - adhésion    : la demande est enregistrée et attend la validation
 *                  d'un administrateur (Membres → Demandes d'adhésion).
 *
 * Les préférences d'affichage des coordonnées ne sont pas demandées : tout
 * est visible par défaut, le membre les règle ensuite dans Mon compte.
 */
class Seliweb_Inscription {

    const MODES = array( 'inscription', 'adhesion' );

    // Envois de code autorisés par heure et par adresse IP.
    const LIMITE_CODES_HEURE = 5;

    // ----------------------------------------------------------------
    // Réglages d'un mode (Paramètres → Inscription).
    // Groupe par défaut : inscription = groupe marqué « par défaut » ;
    // adhésion = groupe pré-choisi sur chaque nouvelle demande (0 = Aucun).
    // Tant que les réglages propres à l'adhésion n'ont jamais été
    // enregistrés, elle reprend le consentement de l'inscription.
    // ----------------------------------------------------------------
    public static function reglages( $mode ) {
        global $wpdb;
        $tp   = $wpdb->prefix . 'seliweb_parametres';
        $rows = $wpdb->get_results(
            "SELECT cle, valeur FROM $tp WHERE cle IN ('inscription_consentement_titre','inscription_consentement_texte','adhesion_consentement_titre','adhesion_consentement_texte','adhesion_groupe_defaut','adhesion_ouverte')",
            OBJECT_K
        );
        $val = function( $cle ) use ( $rows ) {
            return isset( $rows[ $cle ] ) ? (string) $rows[ $cle ]->valeur : null;
        };

        if ( $mode === 'adhesion' ) {
            $titre = $val( 'adhesion_consentement_titre' );
            $texte = $val( 'adhesion_consentement_texte' );
            return array(
                'ouverte'            => (bool) $val( 'adhesion_ouverte' ),
                'groupe_id'          => (int) $val( 'adhesion_groupe_defaut' ),
                'consentement_titre' => $titre ?? (string) $val( 'inscription_consentement_titre' ),
                'consentement_texte' => $texte ?? (string) $val( 'inscription_consentement_texte' ),
            );
        }
        return array(
            'ouverte'            => (bool) get_option( 'users_can_register' ),
            'groupe_id'          => (int) Seliweb_Groupes::get_groupe_defaut_id(),
            'consentement_titre' => (string) $val( 'inscription_consentement_titre' ),
            'consentement_texte' => (string) $val( 'inscription_consentement_texte' ),
        );
    }

    public static function adhesion_ouverte() {
        $r = self::reglages( 'adhesion' );
        return $r['ouverte'];
    }

    // Point d'entrée proposé à un visiteur (lien sous le formulaire de
    // connexion, page S'inscrire fermée) : l'inscription si elle est
    // ouverte, sinon la demande d'adhésion si elle l'est, sinon rien.
    // Retourne array( 'url' => …, 'label' => … ) ou null.
    public static function lien_entree() {
        if ( get_option( 'users_can_register' ) ) {
            $url = self::url_page( 'seliweb_inscription' );
            return $url ? array( 'url' => $url, 'label' => __( "S'inscrire", 'seliweb' ) ) : null;
        }
        if ( self::adhesion_ouverte() ) {
            $url = self::url_page( 'seliweb_demande_adhesion' );
            return $url ? array( 'url' => $url, 'label' => __( 'Demander à adhérer', 'seliweb' ) ) : null;
        }
        return null;
    }

    // ----------------------------------------------------------------
    // Formulaire public (les deux shortcodes)
    // ----------------------------------------------------------------
    public static function formulaire( $mode ) {
        $mode     = in_array( $mode, self::MODES, true ) ? $mode : 'inscription';
        $adhesion = ( $mode === 'adhesion' );

        if ( is_admin() || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
            return '<p><em>' . esc_html( $adhesion
                ? __( "Formulaire de demande d'adhésion Seliweb (prévisualisation désactivée).", 'seliweb' )
                : __( "Formulaire d'inscription Seliweb (prévisualisation désactivée).", 'seliweb' ) ) . '</em></p>';
        }
        if ( is_user_logged_in() && ! is_preview() ) {
            $url = self::url_page( 'seliweb_mon_compte' );
            if ( $url && ! headers_sent() ) { wp_safe_redirect( $url ); exit; }
            return '<div class="seliweb-wrap"><div class="seliweb-notice seliweb-notice-info">'
                . esc_html__( 'Vous êtes déjà connecté avec un compte membre.', 'seliweb' )
                . '</div></div>';
        }
        $reglages = self::reglages( $mode );
        if ( ! $reglages['ouverte'] ) {
            return self::message_ferme( $adhesion );
        }

        $consentement_texte = $reglages['consentement_texte'];
        $consentement_titre = $reglages['consentement_titre'] ?: __( 'ces conditions', 'seliweb' );

        $erreurs       = array();
        $succes        = false;
        $attente_code  = false;
        $token         = '';
        $email_attente = '';

        // --- Étape 2 : vérification du code reçu par e-mail ---
        if ( isset( $_POST['seliweb_code_nonce'] )
             && wp_verify_nonce( $_POST['seliweb_code_nonce'], 'seliweb_inscription_code' ) ) {

            $token      = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
            $code_saisi = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
            $pending    = $token ? get_transient( 'seliweb_inscr_pending_' . $token ) : false;

            if ( ! $pending || ( $pending['mode'] ?? 'inscription' ) !== $mode ) {
                $erreurs[] = $adhesion
                    ? __( 'Session expirée, veuillez recommencer votre demande.', 'seliweb' )
                    : __( 'Session expirée, veuillez recommencer votre inscription.', 'seliweb' );
            } elseif ( hash_equals( (string) $pending['code'], $code_saisi ) ) {
                delete_transient( 'seliweb_inscr_pending_' . $token );
                $result = $adhesion
                    ? self::enregistrer_demande( $pending['data'], $reglages['groupe_id'] )
                    : self::creer_membre( $pending['data'], $reglages['groupe_id'] );
                if ( is_wp_error( $result ) ) {
                    $erreurs[] = $result->get_error_message();
                } else {
                    $succes = true;
                }
            } else {
                $pending['tentatives'] = ( $pending['tentatives'] ?? 0 ) + 1;
                if ( $pending['tentatives'] >= 5 ) {
                    delete_transient( 'seliweb_inscr_pending_' . $token );
                    $erreurs[] = $adhesion
                        ? __( 'Trop de tentatives incorrectes. Veuillez recommencer votre demande.', 'seliweb' )
                        : __( 'Trop de tentatives incorrectes. Veuillez recommencer votre inscription.', 'seliweb' );
                } else {
                    set_transient( 'seliweb_inscr_pending_' . $token, $pending, 15 * MINUTE_IN_SECONDS );
                    $attente_code  = true;
                    $email_attente = $pending['data']['email'];
                    $erreurs[]     = sprintf( __( 'Code incorrect (%d tentative(s) restante(s)).', 'seliweb' ), 5 - $pending['tentatives'] );
                }
            }

        // --- Étape 1 : formulaire ---
        } elseif ( isset( $_POST['seliweb_inscription_nonce'] )
                   && wp_verify_nonce( $_POST['seliweb_inscription_nonce'], 'seliweb_inscription_' . $mode ) ) {

            if ( ! empty( $_POST['site_web'] ) ) {
                // Robot ayant rempli le champ piège : on fait comme si tout allait bien.
                $succes = true;
            } else {
                $resultat = self::valider( $mode, $consentement_texte );
                if ( is_array( $resultat ) && isset( $resultat['erreurs'] ) ) {
                    $erreurs = $resultat['erreurs'];
                } else {
                    $token = wp_generate_password( 24, false, false );
                    $code  = str_pad( (string) random_int( 0, 9999 ), 4, '0', STR_PAD_LEFT );
                    set_transient( 'seliweb_inscr_pending_' . $token, array(
                        'mode'       => $mode,
                        'code'       => $code,
                        'tentatives' => 0,
                        'data'       => $resultat,
                    ), 15 * MINUTE_IN_SECONDS );
                    self::envoyer_code( $mode, $resultat['email'], $code );
                    $attente_code  = true;
                    $email_attente = $resultat['email'];
                }
            }
        }

        $login_url = self::url_page( 'seliweb_login' );

        ob_start(); ?>
        <div class="seliweb-wrap">

        <?php if ( $succes ) : ?>
            <div class="seliweb-notice seliweb-notice-ok">
                <?php if ( $adhesion ) : ?>
                    <?php esc_html_e( "Votre demande d'adhésion a bien été envoyée. Elle va être examinée par un administrateur : dès qu'elle sera acceptée, vous recevrez un mail pour choisir votre mot de passe et vous connecter.", 'seliweb' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Inscription réussie ! Un mot de passe vous a été envoyé par email. Vous pouvez maintenant vous connecter.', 'seliweb' ); ?>
                    <?php if ( $login_url ) : ?>
                        <br><a href="<?php echo esc_url( $login_url ); ?>" style="font-weight:600;"><?php esc_html_e( 'Se connecter', 'seliweb' ); ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php else : ?>

            <?php if ( $erreurs ) : ?>
                <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:12px 16px;border-radius:4px;margin-bottom:20px;">
                    <ul style="margin:0;padding-left:18px;">
                        <?php foreach ( $erreurs as $e ) : ?>
                            <li style="color:#b32d2e;"><?php echo esc_html( $e ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ( $attente_code ) : ?>

                <div class="seliweb-login-box" style="max-width:420px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:28px;">
                    <h2 style="font-size:1.15rem;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #e0e0e0;color:var(--color-primary,#1d6a4a);">
                        <?php esc_html_e( 'Vérification de votre email', 'seliweb' ); ?>
                    </h2>
                    <p style="font-size:.9rem;color:#555;margin-bottom:16px;">
                        <?php printf(
                            $adhesion
                                ? esc_html__( 'Un code à 4 chiffres a été envoyé à %s. Saisissez-le ci-dessous pour envoyer votre demande d\'adhésion.', 'seliweb' )
                                : esc_html__( 'Un code à 4 chiffres a été envoyé à %s. Saisissez-le ci-dessous pour finaliser votre inscription.', 'seliweb' ),
                            '<strong>' . esc_html( $email_attente ) . '</strong>'
                        ); ?>
                    </p>
                    <form method="post">
                        <?php wp_nonce_field( 'seliweb_inscription_code', 'seliweb_code_nonce' ); ?>
                        <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
                        <input type="text" name="code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4"
                               autocomplete="one-time-code" required autofocus
                               style="width:100%;font-size:1.4rem;letter-spacing:.3em;text-align:center;padding:10px;border:1px solid #ccc;border-radius:4px;margin-bottom:14px;box-sizing:border-box;">
                        <button type="submit" class="seliweb-btn" style="width:100%;"><?php esc_html_e( 'Valider', 'seliweb' ); ?></button>
                    </form>
                </div>

            <?php else :
                $v = function( $k ) {
                    return esc_attr( wp_unslash( $_POST[ $k ] ?? '' ) );
                };
                $civ = $_POST['civilite'] ?? '';
                ?>

                <div class="seliweb-login-box" style="max-width:560px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:28px;">
                    <h2 style="font-size:1.15rem;margin-bottom:20px;padding-bottom:10px;border-bottom:2px solid #e0e0e0;color:var(--color-primary,#1d6a4a);">
                        <?php echo esc_html( $adhesion ? __( "Demande d'adhésion", 'seliweb' ) : __( 'Créer un compte', 'seliweb' ) ); ?>
                    </h2>
                    <form method="post">
                        <?php wp_nonce_field( 'seliweb_inscription_' . $mode, 'seliweb_inscription_nonce' ); ?>
                        <?php // Champ piège anti-robots : invisible pour un humain, doit rester vide. ?>
                        <div style="position:absolute;left:-9999px;" aria-hidden="true">
                            <input type="text" name="site_web" value="" tabindex="-1" autocomplete="off">
                        </div>
                        <style>
                        .sel-ins-table { width:100%; border-collapse:collapse; }
                        .sel-ins-table td { padding:5px 8px 5px 0; vertical-align:middle; }
                        .sel-ins-table td:first-child { width:175px; text-align:right; padding-right:14px; font-size:14px; font-weight:500; color:#333; white-space:nowrap; }
                        .sel-ins-table input[type="text"], .sel-ins-table input[type="email"], .sel-ins-table input[type="tel"], .sel-ins-table input[type="date"] { width:100%; padding:7px 10px; border:1px solid #ccc; border-radius:4px; font-size:14px; box-sizing:border-box; }
                        .sel-ins-table input:focus { outline:none; border-color:#1d6a4a; }
                        .sel-ins-section td { padding-top:14px !important; border-top:1px solid #e0e0e0; font-weight:600; color:#1d6a4a !important; font-size:13px !important; text-transform:uppercase; letter-spacing:.04em; text-align:left !important; }
                        .sel-ins-hint { display:block; font-style:italic; font-size:12px; color:#888; margin-top:3px; }
                        .sel-ins-req { color:#b32d2e; }
                        .sel-ins-full { text-align:left !important; white-space:normal !important; width:auto !important; }
                        @media(max-width:520px){
                            .sel-ins-table td:first-child { width:auto; text-align:left; display:block; padding-bottom:2px; }
                            .sel-ins-table td:last-child { display:block; padding-left:0; }
                        }
                        </style>
                        <table class="sel-ins-table">
                            <tr class="sel-ins-section"><td colspan="2"><?php esc_html_e( 'Identité', 'seliweb' ); ?></td></tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e( 'Civilité', 'seliweb' ); ?></td>
                                <td>
                                    <label style="margin-right:18px;"><input type="radio" name="civilite" value="Mr" <?php checked( $civ, 'Mr' ); ?> required> <?php esc_html_e( 'M.', 'seliweb' ); ?></label>
                                    <label><input type="radio" name="civilite" value="Mme" <?php checked( $civ, 'Mme' ); ?>> <?php esc_html_e( 'Mme', 'seliweb' ); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e( 'Identifiant', 'seliweb' ); ?></td>
                                <td><input type="text" name="identifiant" value="<?php echo $v( 'identifiant' ); ?>" required>
                                    <span class="sel-ins-hint"><?php esc_html_e( 'Ne pourra plus être modifié ensuite.', 'seliweb' ); ?></span></td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e( 'Nom', 'seliweb' ); ?></td>
                                <td><input type="text" name="nom" value="<?php echo $v( 'nom' ); ?>" required></td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e( 'Prénom', 'seliweb' ); ?></td>
                                <td><input type="text" name="prenom" value="<?php echo $v( 'prenom' ); ?>" required></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Organisme', 'seliweb' ); ?></td>
                                <td><input type="text" name="organisme" value="<?php echo $v( 'organisme' ); ?>">
                                    <span class="sel-ins-hint"><?php esc_html_e( "Ne renseigner que si vous agissez au nom d'une personne morale.", 'seliweb' ); ?></span></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Date de naissance', 'seliweb' ); ?></td>
                                <td><input type="date" name="date_naissance" value="<?php echo $v( 'date_naissance' ); ?>"></td>
                            </tr>

                            <tr class="sel-ins-section"><td colspan="2"><?php esc_html_e( 'Contact', 'seliweb' ); ?></td></tr>
                            <tr>
                                <td><?php esc_html_e( 'Tél. portable', 'seliweb' ); ?></td>
                                <td><input type="tel" name="tel_portable" value="<?php echo $v( 'tel_portable' ); ?>"></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Tél. fixe', 'seliweb' ); ?></td>
                                <td><input type="tel" name="tel_fixe" value="<?php echo $v( 'tel_fixe' ); ?>"></td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e( 'E-mail', 'seliweb' ); ?></td>
                                <td><input type="email" name="user_email" value="<?php echo $v( 'user_email' ); ?>" required autocomplete="email"></td>
                            </tr>

                            <tr class="sel-ins-section"><td colspan="2"><?php esc_html_e( 'Adresse', 'seliweb' ); ?></td></tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e( 'Adresse 1', 'seliweb' ); ?></td>
                                <td><input type="text" name="adresse1" value="<?php echo $v( 'adresse1' ); ?>" required></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Adresse 2', 'seliweb' ); ?></td>
                                <td><input type="text" name="adresse2" value="<?php echo $v( 'adresse2' ); ?>"></td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e( 'Ville', 'seliweb' ); ?></td>
                                <td><input type="text" name="ville" value="<?php echo $v( 'ville' ); ?>" required></td>
                            </tr>
                            <tr>
                                <td><span class="sel-ins-req">*</span> <?php esc_html_e( 'Code postal', 'seliweb' ); ?></td>
                                <td><input type="text" name="code_postal" maxlength="10" value="<?php echo $v( 'code_postal' ); ?>" required></td>
                            </tr>

                            <?php if ( $consentement_texte ) : ?>
                            <tr>
                                <td colspan="2" class="sel-ins-full" style="padding-top:8px;">
                                    <p style="font-weight:600;margin:0 0 8px;">
                                        <?php /* translators: %s: titre du consentement */ printf( esc_html__( 'Acceptez-vous %s ?', 'seliweb' ), esc_html( $consentement_titre ) ); ?>
                                    </p>
                                    <div style="max-height:160px;overflow-y:auto;border:1px solid #ccc;border-radius:4px;padding:10px 12px;background:#fafafa;font-size:13px;line-height:1.5;color:#333;margin-bottom:10px;">
                                        <?php echo nl2br( esc_html( $consentement_texte ) ); ?>
                                    </div>
                                    <label style="display:flex;align-items:flex-start;gap:8px;font-size:14px;cursor:pointer;">
                                        <input type="checkbox" name="consentement" value="1" required style="margin-top:2px;">
                                        <span><?php /* translators: %s: titre du consentement */ printf( esc_html__( "J'ai lu et j'accepte %s.", 'seliweb' ), esc_html( $consentement_titre ) ); ?></span>
                                    </label>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <tr>
                                <td></td>
                                <td>
                                    <p style="font-size:.82rem;color:#888;margin:8px 0 14px;"><span class="sel-ins-req">*</span> <?php esc_html_e( 'Champs obligatoires', 'seliweb' ); ?></p>
                                    <button type="submit" class="seliweb-btn"><?php echo esc_html( $adhesion ? __( 'Envoyer ma demande', 'seliweb' ) : __( "S'inscrire", 'seliweb' ) ); ?></button>
                                </td>
                            </tr>
                        </table>
                    </form>

                    <?php if ( $login_url ) : ?>
                    <p style="margin-top:16px;padding-top:14px;border-top:1px solid #e0e0e0;font-size:.9rem;color:#555;">
                        <?php esc_html_e( 'Déjà un compte ?', 'seliweb' ); ?>
                        <a href="<?php echo esc_url( $login_url ); ?>" style="font-weight:600;"><?php esc_html_e( 'Se connecter', 'seliweb' ); ?></a>
                    </p>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // Page fermée. Inscriptions fermées mais demandes d'adhésion ouvertes :
    // on oriente le visiteur vers la demande d'adhésion.
    private static function message_ferme( $adhesion ) {
        $html = '<div class="seliweb-wrap"><div class="seliweb-login-box" style="max-width:520px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:24px 28px;">';
        $lien = $adhesion ? null : self::lien_entree();
        if ( $lien ) {
            $html .= '<p style="margin:0 0 16px;">' . esc_html__( 'Les inscriptions directes ne sont pas ouvertes. Pour devenir membre, remplissez la demande d\'adhésion : elle sera examinée par un administrateur.', 'seliweb' ) . '</p>'
                . '<a href="' . esc_url( $lien['url'] ) . '" class="seliweb-btn">' . esc_html__( "Remplir la demande d'adhésion", 'seliweb' ) . '</a>';
        } else {
            $html .= '<p style="margin:0;">' . esc_html( $adhesion
                ? __( "Les demandes d'adhésion sont actuellement fermées.", 'seliweb' )
                : __( 'Les inscriptions sont actuellement fermées.', 'seliweb' ) ) . '</p>';
        }
        return $html . '</div></div>';
    }

    // Valide l'étape 1. Retourne les données nettoyées, ou array('erreurs' => …).
    private static function valider( $mode, $consentement_texte ) {
        global $wpdb;
        $td = $wpdb->prefix . 'seliweb_demandes_adhesion';

        $d = array(
            'civilite'     => in_array( $_POST['civilite'] ?? '', array( 'Mr', 'Mme' ), true ) ? $_POST['civilite'] : '',
            'identifiant'  => sanitize_user( wp_unslash( $_POST['identifiant'] ?? '' ), true ),
            'nom'          => sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ),
            'prenom'       => sanitize_text_field( wp_unslash( $_POST['prenom'] ?? '' ) ),
            'organisme'    => sanitize_text_field( wp_unslash( $_POST['organisme'] ?? '' ) ),
            'tel_portable' => sanitize_text_field( wp_unslash( $_POST['tel_portable'] ?? '' ) ),
            'tel_fixe'     => sanitize_text_field( wp_unslash( $_POST['tel_fixe'] ?? '' ) ),
            'email'        => sanitize_email( wp_unslash( $_POST['user_email'] ?? '' ) ),
            'adresse1'     => sanitize_text_field( wp_unslash( $_POST['adresse1'] ?? '' ) ),
            'adresse2'     => sanitize_text_field( wp_unslash( $_POST['adresse2'] ?? '' ) ),
            'ville'        => sanitize_text_field( wp_unslash( $_POST['ville'] ?? '' ) ),
            'code_postal'  => sanitize_text_field( wp_unslash( $_POST['code_postal'] ?? '' ) ),
        );
        $date_brute          = sanitize_text_field( wp_unslash( $_POST['date_naissance'] ?? '' ) );
        $d['date_naissance'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_brute ) ? $date_brute : null;

        $e = array();
        if ( ! $d['civilite'] ) $e[] = __( 'La civilité est obligatoire.', 'seliweb' );
        if ( ! $d['identifiant'] ) {
            $e[] = __( "L'identifiant est obligatoire.", 'seliweb' );
        } elseif ( username_exists( $d['identifiant'] )
                   || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $td WHERE identifiant=%s AND statut='attente' LIMIT 1", $d['identifiant'] ) ) ) {
            $e[] = __( 'Cet identifiant est déjà utilisé.', 'seliweb' );
        }
        if ( ! $d['nom'] )    $e[] = __( 'Le nom est obligatoire.', 'seliweb' );
        if ( ! $d['prenom'] ) $e[] = __( 'Le prénom est obligatoire.', 'seliweb' );
        if ( ! is_email( $d['email'] ) ) {
            $e[] = __( "L'email est invalide.", 'seliweb' );
        } elseif ( email_exists( $d['email'] ) ) {
            $e[] = __( 'Cette adresse email est déjà utilisée.', 'seliweb' );
        } elseif ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $td WHERE email=%s AND statut='attente' LIMIT 1", $d['email'] ) ) ) {
            $e[] = __( "Une demande d'adhésion est déjà en attente pour cette adresse email.", 'seliweb' );
        }
        if ( ! $d['adresse1'] )    $e[] = __( "L'adresse est obligatoire.", 'seliweb' );
        if ( ! $d['ville'] )       $e[] = __( 'La ville est obligatoire.', 'seliweb' );
        if ( ! $d['code_postal'] ) $e[] = __( 'Le code postal est obligatoire.', 'seliweb' );
        if ( $date_brute && ! $d['date_naissance'] ) $e[] = __( 'La date de naissance est invalide.', 'seliweb' );
        if ( $consentement_texte && empty( $_POST['consentement'] ) ) {
            $e[] = $mode === 'adhesion'
                ? __( 'Vous devez accepter les conditions pour envoyer votre demande.', 'seliweb' )
                : __( 'Vous devez accepter les conditions pour vous inscrire.', 'seliweb' );
        }
        if ( $e ) return array( 'erreurs' => $e );

        // Anti-abus : nombre d'envois de code limité par heure et par adresse IP.
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( $ip ) {
            $cle = 'seliweb_inscr_rl_' . md5( $ip );
            $nb  = (int) get_transient( $cle );
            if ( $nb >= self::LIMITE_CODES_HEURE ) {
                return array( 'erreurs' => array( __( 'Trop de tentatives depuis cette connexion. Merci de réessayer plus tard.', 'seliweb' ) ) );
            }
            set_transient( $cle, $nb + 1, HOUR_IN_SECONDS );
        }

        // Préférences d'affichage : tout visible, réglable ensuite dans Mon compte.
        $d['show_email']         = 1;
        $d['show_tel_portable']  = 1;
        $d['show_tel_fixe']      = 1;
        $d['show_adresse']       = 1;
        // Copie exacte du texte accepté, pour la traçabilité du consentement.
        $d['consentement_texte'] = $consentement_texte;
        $d['ip']                 = $ip;
        return $d;
    }

    // Mail « Code de vérification » (Paramètres → Mails).
    private static function envoyer_code( $mode, $email, $code ) {
        global $wpdb;
        $cfg = array();
        foreach ( $wpdb->get_results( "SELECT cle, valeur FROM {$wpdb->prefix}seliweb_parametres WHERE cle LIKE 'mail\_verif\_%'" ) as $r ) {
            $cfg[ $r->cle ] = $r->valeur;
        }
        $from_email = trim( $cfg['mail_verif_from_email'] ?? '' );
        $from_name  = trim( $cfg['mail_verif_from_name'] ?? '' );
        $sujet      = trim( $cfg['mail_verif_subject'] ?? '' )
            ?: sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Code de vérification', 'seliweb' ) );
        $intro      = trim( $cfg['mail_verif_intro'] ?? '' );
        $sig        = trim( $cfg['mail_verif_signature'] ?? '' );

        $corps = sprintf(
            $mode === 'adhesion'
                ? __( "Bonjour,\n\nVoici votre code de vérification pour envoyer votre demande d'adhésion : %s\n\nCe code est valable 15 minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.", 'seliweb' )
                : __( "Bonjour,\n\nVoici votre code de vérification pour finaliser votre inscription : %s\n\nCe code est valable 15 minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.", 'seliweb' ),
            $code
        );
        $corps = trim( ( $intro ? $intro . "\n\n" : '' ) . $corps . ( $sig ? "\n\n" . $sig : '' ) );

        $headers = array();
        if ( $from_email && is_email( $from_email ) ) {
            $headers[] = 'From: ' . ( $from_name ? $from_name . ' <' . $from_email . '>' : $from_email );
        }
        wp_mail( $email, $sujet, $corps, $headers );
    }

    // Adhésion : la demande attend la validation d'un administrateur.
    private static function enregistrer_demande( $d, $groupe_id ) {
        global $wpdb;
        $td = $wpdb->prefix . 'seliweb_demandes_adhesion';
        // Contrôle refait : une autre demande a pu arriver pendant la saisie du code.
        if ( username_exists( $d['identifiant'] )
             || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $td WHERE (identifiant=%s OR email=%s) AND statut='attente' LIMIT 1", $d['identifiant'], $d['email'] ) )
             || email_exists( $d['email'] ) ) {
            return new WP_Error( 'seliweb_adhesion', __( 'Cet identifiant ou cette adresse email est déjà utilisé.', 'seliweb' ) );
        }
        $groupe_id = (int) $groupe_id;
        if ( $groupe_id && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}seliweb_groupes WHERE id=%d", $groupe_id ) ) ) {
            $groupe_id = 0;
        }
        $wpdb->insert( $td, array(
            'statut'             => 'attente',
            'civilite'           => $d['civilite'],
            'identifiant'        => $d['identifiant'],
            'nom'                => $d['nom'],
            'prenom'             => $d['prenom'],
            'organisme'          => $d['organisme'],
            'date_naissance'     => $d['date_naissance'],
            'tel_portable'       => $d['tel_portable'],
            'tel_fixe'           => $d['tel_fixe'],
            'email'              => $d['email'],
            'adresse1'           => $d['adresse1'],
            'adresse2'           => $d['adresse2'],
            'ville'              => $d['ville'],
            'code_postal'        => $d['code_postal'],
            'groupe_id'          => $groupe_id ?: null,
            'show_email'         => $d['show_email'],
            'show_tel_portable'  => $d['show_tel_portable'],
            'show_tel_fixe'      => $d['show_tel_fixe'],
            'show_adresse'       => $d['show_adresse'],
            'consentement_texte' => $d['consentement_texte'] ?: null,
            'ip'                 => $d['ip'] ?: null,
            'date_demande'       => current_time( 'mysql' ),
        ) );
        if ( ! $wpdb->insert_id ) {
            return new WP_Error( 'seliweb_adhesion', __( "Votre demande n'a pas pu être enregistrée. Merci de réessayer.", 'seliweb' ) );
        }
        Seliweb_Adhesions::prevenir_admin( $d );
        return (int) $wpdb->insert_id;
    }

    // ----------------------------------------------------------------
    // Création du compte WordPress et du membre Seliweb — commune à
    // l'inscription (code validé) et à l'acceptation d'une demande
    // d'adhésion. $d : tableau des champs du formulaire. Envoie le mail
    // WordPress « identifiant + lien pour définir le mot de passe »
    // (Paramètres → Mails → Détails de connexion, ou Adhésion acceptée).
    // Retourne array( user_id, membre_id ), ou un WP_Error.
    // ----------------------------------------------------------------
    public static function creer_membre( $d, $groupe_id, $adhesion = false ) {
        global $wpdb;
        $tm        = $wpdb->prefix . 'seliweb_membres';
        $groupe_id = (int) $groupe_id;

        // Le membre choisira son mot de passe via le lien reçu par mail.
        $user_id = wp_create_user( $d['identifiant'], wp_generate_password(), $d['email'] );
        if ( is_wp_error( $user_id ) ) return $user_id;

        update_user_meta( $user_id, 'show_admin_bar_front', 'false' );
        wp_update_user( array(
            'ID'           => $user_id,
            'first_name'   => $d['prenom'],
            'last_name'    => $d['nom'],
            'display_name' => $d['prenom'] . ' ' . $d['nom'],
        ) );
        update_user_meta( $user_id, 'seliweb_organisme', $d['organisme'] );

        $numero_sel = null;
        $sel_groupe = (int) $wpdb->get_var( "SELECT valeur FROM {$wpdb->prefix}seliweb_parametres WHERE cle='sel_groupe_id' LIMIT 1" );
        if ( $groupe_id && $sel_groupe > 0 && $groupe_id === $sel_groupe ) {
            $max        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT GREATEST(COALESCE(MAX(numero_sel),1),1) FROM $tm WHERE groupe_id=%d", $sel_groupe ) );
            $numero_sel = $max + 1;
        }

        $membre = array(
            'groupe_id'         => $groupe_id ?: null,
            // Notifications : abonné par défaut aux annonces de son groupe.
            'notif_groupes'     => $groupe_id ? (string) $groupe_id : null,
            'civilite'          => $d['civilite'],
            'tel_portable'      => $d['tel_portable'],
            'tel_fixe'          => $d['tel_fixe'],
            'adresse1'          => $d['adresse1'],
            'adresse2'          => $d['adresse2'],
            'ville'             => $d['ville'],
            'code_postal'       => $d['code_postal'],
            'show_email'        => (int) ( $d['show_email'] ?? 1 ),
            'show_tel_portable' => (int) ( $d['show_tel_portable'] ?? 1 ),
            'show_tel_fixe'     => (int) ( $d['show_tel_fixe'] ?? 1 ),
            'show_adresse'      => (int) ( $d['show_adresse'] ?? 1 ),
            'numero_sel'        => $numero_sel,
            'date_naissance'    => $d['date_naissance'] ?? null,
        );
        // La ligne membre est normalement déjà créée par le hook user_register
        // (rattachement au groupe par défaut) : on la complète.
        $membre_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tm WHERE wp_user_id=%d", $user_id ) );
        if ( $membre_id ) {
            $wpdb->update( $tm, $membre, array( 'id' => $membre_id ) );
        } else {
            $wpdb->insert( $tm, array_merge( $membre, array( 'wp_user_id' => $user_id ) ) );
            $membre_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( $wpdb->prefix . 'seliweb_inscriptions', array(
            'wp_user_id'   => $user_id,
            'civilite'     => $d['civilite'],
            'nom'          => $d['nom'],
            'prenom'       => $d['prenom'],
            'organisme'    => $d['organisme'],
            'tel_portable' => $d['tel_portable'],
            'tel_fixe'     => $d['tel_fixe'],
            'adresse1'     => $d['adresse1'],
            'adresse2'     => $d['adresse2'],
            'ville'        => $d['ville'],
            'code_postal'  => $d['code_postal'],
        ) );

        if ( class_exists( 'Seliweb_Consentements' ) ) {
            Seliweb_Consentements::enregistrer( $user_id, $adhesion ? 'adhesion' : 'inscription', null, $d['consentement_texte'] ?? '' );
        }

        Seliweb_Adhesions::$envoi_acceptation = (bool) $adhesion;
        wp_new_user_notification( $user_id, null, 'user' );
        Seliweb_Adhesions::$envoi_acceptation = false;

        return array( $user_id, $membre_id );
    }

    private static function url_page( $shortcode ) {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like( '[' . $shortcode ) . '%'
        ) );
        return $id ? get_permalink( $id ) : '';
    }
}
