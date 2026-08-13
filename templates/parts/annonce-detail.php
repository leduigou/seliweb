<?php
/**
 * template-parts/annonce-detail.php
 * Reçoit : $args['annonce_id'], $args['groupe_visiteur']
 */
if ( ! class_exists( 'Seliweb_Annonces' ) ) return;

global $wpdb;
$annonce_id      = intval( $args['annonce_id'] ?? 0 );
$groupe_visiteur = $args['groupe_visiteur'] ?? null;

$ta     = $wpdb->prefix . 'seliweb_annonces';
$tc     = $wpdb->prefix . 'seliweb_categories';
$tr     = $wpdb->prefix . 'seliweb_rubriques';
$ts     = $wpdb->prefix . 'seliweb_statuts';
$tm     = $wpdb->prefix . 'seliweb_membres';
$tp_sel = $wpdb->prefix . 'seliweb_parametres';

$detail           = $wpdb->get_row( $wpdb->prepare( "SELECT a.* FROM $ta a WHERE a.id=%d", $annonce_id ) );
$membre           = null;
$is_sel_annonceur = false;

if ( $detail ) {
    $cat_row = $wpdb->get_row( $wpdb->prepare( "SELECT nom, slug FROM $tc WHERE id=%d", intval( $detail->categorie_id ) ) );
    $detail->cat_nom    = $cat_row ? $cat_row->nom  : '';
    $detail->cat_slug   = $cat_row ? $cat_row->slug : '';
    $detail->rub_nom    = $wpdb->get_var( $wpdb->prepare( "SELECT nom FROM $tr WHERE id=%d", intval( $detail->rubrique_id ) ) );
    $st_row = $detail->statut_id ? $wpdb->get_row( $wpdb->prepare( "SELECT slug, nom FROM $ts WHERE id=%d", intval( $detail->statut_id ) ) ) : null;
    $detail->statut_slug = $st_row ? $st_row->slug : null;
    $detail->statut_nom  = $st_row ? $st_row->nom  : null;

    $membre = $wpdb->get_row( $wpdb->prepare(
        "SELECT tel_portable, tel_fixe, adresse1, adresse2, ville, code_postal,
                show_email, show_tel_portable, show_tel_fixe, show_adresse,
                groupe_id, numero_sel, wp_user_id
         FROM $tm WHERE id=%d",
        intval( $detail->membre_id )
    ) );
    if ( $membre ) {
        $detail->tel_portable      = $membre->tel_portable;
        $detail->tel_fixe          = $membre->tel_fixe;
        $detail->ville             = $membre->ville;
        $detail->code_postal       = $membre->code_postal;
        $detail->show_email        = (int) $membre->show_email;
        $detail->show_tel_portable = (int) $membre->show_tel_portable;
        $detail->show_tel_fixe     = (int) $membre->show_tel_fixe;
        $detail->show_adresse      = (int) $membre->show_adresse;
        $detail->groupe_id         = (int) $membre->groupe_id;
        $detail->numero_sel        = $membre->numero_sel;
        $uid = (int) $membre->wp_user_id;
        $detail->prenom    = get_user_meta( $uid, 'first_name',        true );
        $detail->nom       = get_user_meta( $uid, 'last_name',         true );
        $detail->organisme = get_user_meta( $uid, 'seliweb_organisme', true );
    }
    $sel_actif        = (bool) $wpdb->get_var( "SELECT valeur FROM $tp_sel WHERE cle='sel_actif' LIMIT 1" );
    $sel_gid          = (int)  $wpdb->get_var( "SELECT valeur FROM $tp_sel WHERE cle='sel_groupe_id' LIMIT 1" );
    $is_sel_annonceur = $membre && $sel_actif && $sel_gid > 0 && isset( $detail->groupe_id ) && $detail->groupe_id === $sel_gid;

    $detail->user_email = $wpdb->get_var( $wpdb->prepare(
        "SELECT user_email FROM {$wpdb->users} WHERE ID=(SELECT wp_user_id FROM $tm WHERE id=%d)",
        intval( $detail->membre_id )
    ) );
}

// Config mail signalement (pour le formulaire de signalement)
$signal_cfg  = array();
$tp_sig_rows = $wpdb->get_results( "SELECT cle, valeur FROM $tp_sel WHERE cle LIKE 'mail\_signal\_%'" );
foreach ( $tp_sig_rows as $r ) $signal_cfg[ $r->cle ] = $r->valeur;
$signal_avertissement = trim( $signal_cfg['mail_signal_avertissement'] ?? '' )
    ?: __( "Toute annonce qui présente des caractéristiques contraires à la loi telles que pornographie, racisme, incitation à la haine, à la xénophobie, à la violence, vantant des produits illicites, incitant à l'intrusion dans des systèmes informatiques, atteinte aux droits d'auteur, etc. n'a pas sa place sur ce site.", 'seliweb-view' );
$signal_prompt = trim( $signal_cfg['mail_signal_prompt'] ?? '' )
    ?: __( "Vous allez signaler une annonce au webmaster de ce site. Merci d'en préciser les raisons.", 'seliweb-view' );

$detail_ok = $detail && ( $detail->statut_slug !== 'expire' );

if ( ! $detail_ok ) {
    echo '<main id="swv-main"><div class="swv-single-wrap"><p class="swv-annonces-empty">'
        . esc_html__( 'Annonce introuvable ou expirée.', 'seliweb-view' )
        . '</p></div></main>';
    return;
}

$prix      = Seliweb_Annonces::get_prix( $annonce_id );
$page_url  = swv_annonces_page_url();
$retour_page = max( 1, intval( $_GET['sel_page'] ?? 1 ) );
$retour_url  = $retour_page > 1 ? add_query_arg( 'sel_page', $retour_page, $page_url ) : $page_url;
?>

<main id="swv-main">
    <div class="swv-main-inner">
        <div class="swv-detail-wrap">

            <a href="<?php echo esc_url( $retour_url ); ?>" class="swv-detail-back">
                &larr; <?php esc_html_e( 'Retour aux annonces', 'seliweb-view' ); ?>
            </a>

            <h1 style="font-family:var(--font-title);margin-bottom:12px;">
                <?php echo esc_html( $detail->titre ); ?>
            </h1>

            <div class="swv-card-tags" style="margin-bottom:16px;">
                <span class="swv-tag swv-tag-cat"><?php echo esc_html( $detail->cat_nom ); ?></span>
                <?php if ( $detail->cat_slug === 'annonces' && $detail->type_annonce ) : ?>
                    <span class="swv-tag swv-tag-type"><?php echo esc_html( ucfirst( $detail->type_annonce ) ); ?></span>
                <?php endif; ?>
                <?php if ( $detail->rub_nom ) : ?>
                    <span class="swv-tag swv-tag-rubrique"><?php echo esc_html( $detail->rub_nom ); ?></span>
                <?php endif; ?>
                <?php if ( $detail->statut_slug && $detail->statut_slug !== 'expire' ) : ?>
                    <span class="swv-card-statut"><?php echo esc_html( $detail->statut_nom ); ?></span>
                <?php endif; ?>
            </div>

            <?php if ( $detail->photo1 || $detail->photo2 ) : ?>
            <div class="swv-detail-photos">
                <?php if ( $detail->photo1 ) echo '<img src="' . esc_url( $detail->photo1 ) . '" alt="">'; ?>
                <?php if ( $detail->photo2 ) echo '<img src="' . esc_url( $detail->photo2 ) . '" alt="">'; ?>
            </div>
            <?php endif; ?>

            <div class="swv-detail-texte">
                <?php echo nl2br( esc_html( $detail->texte ) ); ?>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;gap:12px;">
                <div>
                    <?php if ( $detail->est_don ) : ?>
                        <p style="font-weight:700;color:var(--color-primary);margin:0;">
                            <?php esc_html_e( 'Don', 'seliweb-view' ); ?>
                        </p>
                    <?php elseif ( ! empty( $prix ) ) : ?>
                        <div style="font-weight:700;color:var(--color-primary-dk);">
                            <?php foreach ( $prix as $idx_p => $p ) : ?>
                                <?php if ( $idx_p > 0 ) : ?>
                                    <span style="margin:0 6px;font-weight:400;"><?php echo esc_html( $p->coordination ?: 'OU' ); ?></span>
                                <?php endif; ?>
                                <span><?php echo esc_html( $p->prix . ' ' . ( $p->symbole ?: $p->nom ) ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <a href="#swv-signalement" id="swv-signaler-lien"
                   style="font-size:.8rem;color:#999;text-decoration:none;border:1px solid #ddd;border-radius:4px;padding:3px 10px;white-space:nowrap;flex-shrink:0;cursor:pointer;"
                   title="<?php esc_attr_e( 'Signaler une annonce inappropriée', 'seliweb-view' ); ?>">
                    <?php esc_html_e( 'Signaler cette annonce', 'seliweb-view' ); ?>
                </a>
            </div>

            <!-- Contact -->
            <div class="swv-detail-contact">
                <h4><?php esc_html_e( 'Contacter l\'annonceur', 'seliweb-view' ); ?></h4>

                <?php if ( $membre && ( $detail->code_postal || $detail->ville ) ) : ?>
                    <p><?php esc_html_e( 'Localisation :', 'seliweb-view' ); ?>
                        <?php echo esc_html( trim( $detail->code_postal . ' ' . $detail->ville ) ); ?>
                    </p>
                <?php endif; ?>

                <?php if ( ! is_user_logged_in() ) : ?>
                    <p><em><?php printf(
                        wp_kses( __( '<a href="%s">Connectez-vous</a> pour contacter l\'annonceur.', 'seliweb-view' ), array( 'a' => array( 'href' => array() ) ) ),
                        esc_url( swv_login_page_url( get_permalink() ) )
                    ); ?></em></p>

                <?php elseif ( ! $groupe_visiteur ) : ?>
                    <p><em><?php esc_html_e( 'Vous n\'êtes rattaché à aucun groupe.', 'seliweb-view' ); ?></em></p>

                <?php else : ?>

                    <?php if ( isset( $_GET['seliweb_message_envoye'] ) ) : ?>
                        <div style="background:#edfaef;border-left:4px solid #1d6a4a;padding:10px 14px;margin-bottom:12px;border-radius:4px;font-size:.9rem;">
                            <?php esc_html_e( 'Votre message a été envoyé.', 'seliweb-view' ); ?>
                        </div>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url( $page_url ); ?>" style="margin-bottom:12px;">
                            <?php wp_nonce_field( 'seliweb_contact_' . $annonce_id, 'seliweb_contact_nonce' ); ?>
                            <input type="hidden" name="annonce_id" value="<?php echo intval( $annonce_id ); ?>">
                            <textarea name="message" rows="4" required
                                      style="width:100%;max-width:480px;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:.9rem;margin-bottom:8px;display:block;"
                                      placeholder="<?php esc_attr_e( 'Votre message…', 'seliweb-view' ); ?>"></textarea>
                            <button type="submit" name="seliweb_envoyer_message"
                                    style="padding:8px 18px;background:var(--color-primary);color:#fff;border:none;border-radius:4px;cursor:pointer;font-family:inherit;">
                                <?php esc_html_e( 'Envoyer un message', 'seliweb-view' ); ?>
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ( $is_sel_annonceur ) : ?>

                        <?php if ( $detail->prenom || $detail->nom ) : ?>
                            <p><?php esc_html_e( 'Annonceur :', 'seliweb-view' ); ?>
                                <?php echo esc_html( trim( $detail->prenom . ' ' . $detail->nom ) ); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ( ! empty( $detail->show_adresse ) && ! empty( $detail->organisme ) ) : ?>
                            <p><?php esc_html_e( 'Organisme :', 'seliweb-view' ); ?>
                                <?php echo esc_html( $detail->organisme ); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ( $detail->numero_sel ) : ?>
                            <p><?php esc_html_e( 'N° adhérent :', 'seliweb-view' ); ?>
                                <?php echo esc_html( $detail->numero_sel ); ?>
                            </p>
                        <?php endif; ?>

                    <?php else : ?>

                        <?php if ( ! empty( $detail->show_adresse ) && ! empty( $detail->organisme ) ) : ?>
                            <p><?php esc_html_e( 'Organisme :', 'seliweb-view' ); ?>
                                <?php echo esc_html( $detail->organisme ); ?>
                            </p>
                        <?php endif; ?>

                    <?php endif; ?>

                    <?php if ( ! empty( $detail->show_email ) && $detail->user_email ) : ?>
                        <p><?php esc_html_e( 'Email :', 'seliweb-view' ); ?>
                            <a href="mailto:<?php echo esc_attr( $detail->user_email ); ?>"><?php echo esc_html( $detail->user_email ); ?></a>
                        </p>
                    <?php endif; ?>
                    <?php if ( ! empty( $detail->show_tel_portable ) && ! empty( $detail->tel_portable ) ) : ?>
                        <p><?php esc_html_e( 'Tél. portable :', 'seliweb-view' ); ?>
                            <?php echo esc_html( $detail->tel_portable ); ?>
                        </p>
                    <?php endif; ?>
                    <?php if ( ! empty( $detail->show_tel_fixe ) && ! empty( $detail->tel_fixe ) ) : ?>
                        <p><?php esc_html_e( 'Tél. fixe :', 'seliweb-view' ); ?>
                            <?php echo esc_html( $detail->tel_fixe ); ?>
                        </p>
                    <?php endif; ?>

                <?php endif; ?>
            </div>

            <!-- Signalement -->
            <?php $signal_envoye = isset( $_GET['seliweb_signal_envoye'] ); ?>
            <div id="swv-signalement" style="<?php echo $signal_envoye ? '' : 'display:none;'; ?>margin-top:24px;border-top:1px solid #e0e0e0;padding-top:20px;">
                <h4 style="margin-top:0;"><?php esc_html_e( 'Signaler cette annonce', 'seliweb-view' ); ?></h4>

                <?php if ( $signal_envoye ) : ?>
                    <div style="background:#edfaef;border-left:4px solid #1d6a4a;padding:10px 14px;border-radius:4px;font-size:.9rem;">
                        <?php esc_html_e( 'Votre signalement a été transmis au webmaster. Merci.', 'seliweb-view' ); ?>
                    </div>
                <?php else : ?>
                    <div style="background:#fff8e1;border-left:4px solid #f0a500;padding:10px 14px;margin-bottom:16px;border-radius:4px;font-size:.85rem;line-height:1.6;">
                        <?php echo nl2br( esc_html( $signal_avertissement ) ); ?>
                    </div>

                    <p style="font-size:.9rem;margin-bottom:8px;"><?php echo esc_html( $signal_prompt ); ?></p>

                    <p style="font-size:.85rem;color:#555;margin-bottom:4px;">
                        <?php printf( esc_html__( 'Annonce n° %d', 'seliweb-view' ), $annonce_id ); ?>
                    </p>
                    <?php if ( is_user_logged_in() ) :
                        $sig_user   = wp_get_current_user();
                        $sig_prenom = get_user_meta( $sig_user->ID, 'first_name', true );
                        $sig_nom    = get_user_meta( $sig_user->ID, 'last_name',  true );
                    ?>
                    <p style="font-size:.85rem;color:#555;margin-bottom:12px;">
                        <?php printf(
                            esc_html__( 'Déclarant : %s (ID %d)', 'seliweb-view' ),
                            esc_html( trim( $sig_prenom . ' ' . $sig_nom ) ?: $sig_user->display_name ),
                            $sig_user->ID
                        ); ?>
                    </p>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( $page_url ); ?>">
                        <?php wp_nonce_field( 'seliweb_signal_' . $annonce_id, 'seliweb_signal_nonce' ); ?>
                        <input type="hidden" name="annonce_id" value="<?php echo intval( $annonce_id ); ?>">
                        <textarea name="raison" rows="4" required
                                  style="width:100%;max-width:480px;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:.9rem;margin-bottom:8px;display:block;"
                                  placeholder="<?php esc_attr_e( 'Décrivez en quoi cette annonce vous semble inappropriée…', 'seliweb-view' ); ?>"></textarea>
                        <button type="submit" name="seliweb_signaler_annonce"
                                style="padding:8px 18px;background:#c0392b;color:#fff;border:none;border-radius:4px;cursor:pointer;font-family:inherit;">
                            <?php esc_html_e( 'Envoyer le signalement', 'seliweb-view' ); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

        </div>

        <aside id="swv-sidebar">
            <?php if ( is_active_sidebar( 'swv-sidebar' ) ) dynamic_sidebar( 'swv-sidebar' ); ?>
        </aside>
    </div>
</main>
<script>
(function(){
    var lien = document.getElementById('swv-signaler-lien');
    var div  = document.getElementById('swv-signalement');
    if ( lien && div ) {
        lien.addEventListener('click', function(e){
            e.preventDefault();
            div.style.display = div.style.display === 'none' ? '' : 'none';
            if ( div.style.display !== 'none' ) div.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }
})();
</script>
