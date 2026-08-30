<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Formulaire de contact public — page « Contact » / shortcode [seliweb_contact].
 *
 * Accessible sans être connecté. Envoie un e-mail à l'adresse configurée
 * dans Réglages Seliweb > Mails > « Formulaire de contact » (clés
 * mail_contactsite_*). Anti-spam sans service tiers : pot de miel +
 * horodatage signé (rejet des envois trop rapides).
 */
class Seliweb_Contact {

    const NONCE = 'seliweb_contact_send';

    public static function init() {
        add_shortcode( 'seliweb_contact', array( __CLASS__, 'render' ) );
        add_action( 'init', array( __CLASS__, 'handle_post' ) );
    }

    // Config mail (clés mail_contactsite_*).
    private static function cfg() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT cle, valeur FROM {$wpdb->prefix}seliweb_parametres WHERE cle LIKE 'mail\\_contactsite\\_%'"
        );
        $cfg = array();
        foreach ( $rows as $r ) {
            $cfg[ $r->cle ] = $r->valeur;
        }
        return $cfg;
    }

    // Jeton d'horodatage signé pour le champ caché du formulaire.
    private static function make_token() {
        $ts = time();
        return $ts . '.' . wp_hash( $ts . '|' . self::NONCE, 'nonce' );
    }

    private static function token_age( $token ) {
        $parts = explode( '.', (string) $token, 2 );
        if ( count( $parts ) !== 2 ) {
            return false;
        }
        list( $ts, $sig ) = $parts;
        if ( ! hash_equals( wp_hash( $ts . '|' . self::NONCE, 'nonce' ), $sig ) ) {
            return false;
        }
        return time() - (int) $ts;
    }

    // ----------------------------------------------------------------
    // Traitement de l'envoi — hook init (avant l'envoi des en-têtes)
    // ----------------------------------------------------------------
    public static function handle_post() {
        if ( is_admin() ) return;
        if ( ! isset( $_POST['seliweb_contact_envoyer'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['seliweb_contact_nonce'] ?? '', self::NONCE ) ) return;

        // Page où renvoyer : celle d'où vient le formulaire (champ _wp_http_referer
        // ajouté par wp_nonce_field). wp_get_referer() ne convient pas ici : il
        // renvoie false quand le référent est la page courante (le cas normal).
        $retour = wp_validate_redirect( wp_get_raw_referer(), '' );
        if ( ! $retour ) {
            $ids    = get_option( 'seliweb_page_ids', array() );
            $retour = ! empty( $ids['seliweb_contact'] )
                ? get_permalink( (int) $ids['seliweb_contact'] )
                : home_url( '/' );
        }

        // Anti-spam : pot de miel rempli, ou envoi trop rapide / jeton invalide.
        $age = self::token_age( wp_unslash( $_POST['seliweb_ts'] ?? '' ) );
        if ( ! empty( $_POST['seliweb_site_url'] ) || $age === false || $age < 3 || $age > 10800 ) {
            // On fait comme si c'était parti, sans rien envoyer.
            wp_safe_redirect( add_query_arg( 'contact', 'ok', $retour ) );
            exit;
        }

        $nom     = sanitize_text_field( wp_unslash( $_POST['contact_nom']       ?? '' ) );
        $email   = sanitize_email( wp_unslash( $_POST['contact_email']          ?? '' ) );
        $sujet   = sanitize_text_field( wp_unslash( $_POST['contact_sujet']     ?? '' ) );
        $message = sanitize_textarea_field( wp_unslash( $_POST['contact_message'] ?? '' ) );

        if ( ! $nom || ! is_email( $email ) || ! $message ) {
            wp_safe_redirect( add_query_arg( 'contact', 'erreur', $retour ) );
            exit;
        }

        // Seul réglage : l'adresse de destination (Réglages > Mails > Formulaire
        // de contact). Tout le reste vient du formulaire.
        $to = trim( self::cfg()['mail_contactsite_to_email'] ?? '' );
        if ( ! $to || ! is_email( $to ) ) {
            $to = get_option( 'admin_email' );
        }

        $objet = $sujet !== ''
            ? $sujet
            : sprintf( __( 'Message de %s via le formulaire de contact', 'seliweb' ), $nom );

        $corps = sprintf(
            "%s %s\n%s %s\n%s %s\n\n%s\n%s",
            __( 'Nom :', 'seliweb' ),     $nom,
            __( 'E-mail :', 'seliweb' ),  $email,
            __( 'Sujet :', 'seliweb' ),   $sujet !== '' ? $sujet : __( '(non précisé)', 'seliweb' ),
            __( 'Message :', 'seliweb' ), $message
        );

        // From : laissé au défaut WordPress (pour la délivrabilité, on n'envoie
        // pas "au nom de" l'adresse du visiteur). Le visiteur est en Reply-To :
        // il suffit de répondre au mail pour lui écrire.
        $headers = array( 'Reply-To: ' . $nom . ' <' . $email . '>' );

        wp_mail( $to, $objet, $corps, $headers );

        wp_safe_redirect( add_query_arg( 'contact', 'ok', $retour ) );
        exit;
    }

    // ----------------------------------------------------------------
    // Shortcode [seliweb_contact]
    // ----------------------------------------------------------------
    public static function render( $atts ) {
        if ( is_admin() || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
            return '<p><em>' . esc_html__( 'Formulaire de contact Seliweb (prévisualisation désactivée).', 'seliweb' ) . '</em></p>';
        }

        $etat = isset( $_GET['contact'] ) ? sanitize_key( $_GET['contact'] ) : '';

        ob_start();
        ?>
        <div class="seliweb-wrap">

            <?php if ( $etat === 'ok' ) : ?>
                <div class="seliweb-notice seliweb-notice-ok">
                    <?php esc_html_e( 'Merci, votre message a bien été envoyé. Nous vous répondrons dès que possible.', 'seliweb' ); ?>
                </div>
            <?php elseif ( $etat === 'erreur' ) : ?>
                <div class="seliweb-notice" style="background:#fff5f5;border-left:4px solid #b32d2e;padding:10px 14px;border-radius:4px;margin-bottom:16px;color:#b32d2e;">
                    <?php esc_html_e( 'Veuillez indiquer votre nom, une adresse e-mail valide et un message.', 'seliweb' ); ?>
                </div>
            <?php endif; ?>

            <div class="seliweb-login-box" style="max-width:560px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:28px;">
                <form method="post" action="">
                    <?php wp_nonce_field( self::NONCE, 'seliweb_contact_nonce' ); ?>
                    <input type="hidden" name="seliweb_ts" value="<?php echo esc_attr( self::make_token() ); ?>">
                    <div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">
                        <label><?php esc_html_e( 'Ne pas remplir ce champ', 'seliweb' ); ?>
                            <input type="text" name="seliweb_site_url" value="" tabindex="-1" autocomplete="off">
                        </label>
                    </div>

                    <div class="seliweb-field">
                        <label for="sel_c_nom"><?php esc_html_e( 'Votre nom', 'seliweb' ); ?></label>
                        <input type="text" id="sel_c_nom" name="contact_nom" class="seliweb-input" required>
                    </div>
                    <div class="seliweb-field">
                        <label for="sel_c_email"><?php esc_html_e( 'Votre e-mail', 'seliweb' ); ?></label>
                        <input type="email" id="sel_c_email" name="contact_email" class="seliweb-input" required autocomplete="email">
                    </div>
                    <div class="seliweb-field">
                        <label for="sel_c_sujet"><?php esc_html_e( 'Sujet', 'seliweb' ); ?></label>
                        <input type="text" id="sel_c_sujet" name="contact_sujet" class="seliweb-input">
                    </div>
                    <div class="seliweb-field">
                        <label for="sel_c_message"><?php esc_html_e( 'Message', 'seliweb' ); ?></label>
                        <textarea id="sel_c_message" name="contact_message" class="seliweb-input" rows="6" required></textarea>
                    </div>
                    <div style="margin-top:12px;">
                        <button type="submit" name="seliweb_contact_envoyer" value="1" class="seliweb-btn">
                            <?php esc_html_e( 'Envoyer', 'seliweb' ); ?>
                        </button>
                    </div>
                </form>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}
