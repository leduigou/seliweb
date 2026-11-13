<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Onglet Membres → Demandes d'adhésion. Inclus depuis admin-membres.php
// (accès en lecture déjà vérifié).

global $wpdb;
$td = Seliweb_Adhesions::table();
$tg = $wpdb->prefix . 'seliweb_groupes';

$notice_ok  = '';
$notice_err = '';

if ( isset( $_POST['seliweb_demandes_nonce'] )
     && wp_verify_nonce( $_POST['seliweb_demandes_nonce'], 'seliweb_demandes' )
     && ! empty( $_POST['demande_id'] ) ) {

    $demande_id = intval( $_POST['demande_id'] );
    $groupe_id  = ! empty( $_POST['groupe_id'] ) ? intval( $_POST['groupe_id'] ) : 0;

    if ( ! Seliweb_Permissions::peut( 'membres', 'modifier' ) ) {
        $notice_err = __( "Vous n'avez pas les droits pour traiter les demandes d'adhésion.", 'seliweb' );
    } else {
        Seliweb_Adhesions::changer_groupe( $demande_id, $groupe_id );

        if ( ! empty( $_POST['accepter'] ) ) {
            if ( ! Seliweb_Permissions::peut( 'membres', 'creer' ) ) {
                $notice_err = __( "Vous n'avez pas les droits pour créer un membre.", 'seliweb' );
            } elseif ( ! $groupe_id ) {
                $notice_err = __( "Veuillez choisir un groupe avant d'accepter la demande.", 'seliweb' );
            } else {
                $res = Seliweb_Adhesions::accepter( $demande_id );
                if ( is_wp_error( $res ) ) {
                    $notice_err = $res->get_error_message();
                } else {
                    $notice_ok = __( 'Demande acceptée : le membre a été créé et a reçu un mail pour choisir son mot de passe.', 'seliweb' );
                }
            }
        } elseif ( ! empty( $_POST['refuser'] ) ) {
            Seliweb_Adhesions::refuser( $demande_id );
            $notice_ok = __( 'Demande refusée.', 'seliweb' );
        }
    }
}

$filtre_statut = isset( $_GET['filtre_statut'] ) && in_array( $_GET['filtre_statut'], array( 'refusee', 'toutes' ), true )
    ? $_GET['filtre_statut'] : 'attente';

$where = $filtre_statut === 'toutes' ? '1=1' : $wpdb->prepare( 'd.statut=%s', $filtre_statut );
$demandes = $wpdb->get_results(
    "SELECT d.* FROM $td d WHERE $where ORDER BY d.date_demande DESC"
);
$groupes = $wpdb->get_results( "SELECT id, nom FROM $tg ORDER BY nom ASC" );
$fmt_date = get_option( 'date_format' );
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Membres', 'seliweb' ); ?></h1>
    <?php Seliweb_Adhesions::onglets( 'demandes' ); ?>

    <?php if ( $notice_ok ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice_ok ); ?></p></div>
    <?php endif; ?>
    <?php if ( $notice_err ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $notice_err ); ?></p></div>
    <?php endif; ?>

    <p class="description" style="margin:8px 0 0;">
        <?php esc_html_e( "Demandes déposées depuis la page « Demande d'adhésion » du site. Choisissez le groupe, puis cochez Accepter (le membre est créé et reçoit un mail pour choisir son mot de passe) ou Refuser.", 'seliweb' ); ?>
    </p>

    <form method="get" style="margin:16px 0;display:flex;gap:10px;align-items:flex-end;">
        <input type="hidden" name="page" value="seliweb_membres">
        <input type="hidden" name="tab" value="demandes">
        <div>
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;"><?php esc_html_e( 'Afficher', 'seliweb' ); ?></label>
            <select name="filtre_statut" onchange="this.form.submit()">
                <option value="attente" <?php selected( $filtre_statut, 'attente' ); ?>><?php esc_html_e( 'En attente', 'seliweb' ); ?></option>
                <option value="refusee" <?php selected( $filtre_statut, 'refusee' ); ?>><?php esc_html_e( 'Refusées', 'seliweb' ); ?></option>
                <option value="toutes"  <?php selected( $filtre_statut, 'toutes' ); ?>><?php esc_html_e( 'Toutes', 'seliweb' ); ?></option>
            </select>
        </div>
        <span style="font-size:13px;color:#555;align-self:center;"><?php printf( esc_html__( '%d demande(s)', 'seliweb' ), count( $demandes ) ); ?></span>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th style="width:40px;"><?php esc_html_e( 'ID', 'seliweb' ); ?></th>
            <th style="width:80px;"><?php esc_html_e( 'Date', 'seliweb' ); ?></th>
            <th style="width:100px;"><?php esc_html_e( 'Prénom', 'seliweb' ); ?></th>
            <th style="width:100px;"><?php esc_html_e( 'Nom', 'seliweb' ); ?></th>
            <th style="width:200px;"><?php esc_html_e( 'Email', 'seliweb' ); ?></th>
            <th style="width:100px;"><?php esc_html_e( 'Tél.', 'seliweb' ); ?></th>
            <th style="width:100px;"><?php esc_html_e( 'Ville', 'seliweb' ); ?></th>
            <th><?php esc_html_e( 'Groupe', 'seliweb' ); ?></th>
            <th style="width:60px;"><?php esc_html_e( 'Détail', 'seliweb' ); ?></th>
            <th style="width:70px;text-align:center;"><?php esc_html_e( 'Accepter', 'seliweb' ); ?></th>
            <th style="width:70px;text-align:center;"><?php esc_html_e( 'Refuser', 'seliweb' ); ?></th>
        </tr></thead>
        <tbody>
        <?php if ( ! $demandes ) : ?>
            <tr><td colspan="11"><em><?php esc_html_e( 'Aucune demande.', 'seliweb' ); ?></em></td></tr>
        <?php else : foreach ( $demandes as $d ) :
            $did      = (int) $d->id;
            $acceptee = $d->statut === 'acceptee';
            $refusee  = $d->statut === 'refusee';
            ?>
            <tr <?php echo $refusee ? 'style="opacity:.6;"' : ''; ?>>
                <td style="color:#888;font-size:12px;"><?php echo $did; ?></td>
                <td style="font-size:12px;"><?php echo esc_html( mysql2date( $fmt_date, $d->date_demande ) ); ?></td>
                <td><?php echo esc_html( $d->prenom ); ?></td>
                <td>
                    <strong><?php echo esc_html( $d->nom ); ?></strong>
                    <?php if ( $d->organisme ) : ?><br><em style="font-size:12px;color:#888;"><?php echo esc_html( $d->organisme ); ?></em><?php endif; ?>
                    <?php if ( $refusee ) : ?><br><span style="font-size:11px;color:#b32d2e;font-weight:600;"><?php esc_html_e( 'Refusée', 'seliweb' ); ?></span><?php endif; ?>
                    <?php if ( $acceptee ) : ?><br><span style="font-size:11px;color:#1d6a4a;font-weight:600;"><?php esc_html_e( 'Acceptée', 'seliweb' ); ?></span><?php endif; ?>
                </td>
                <td><?php echo esc_html( $d->email ); ?></td>
                <td>
                    <?php if ( $d->tel_portable ) echo esc_html( $d->tel_portable ) . '<br>'; ?>
                    <?php if ( $d->tel_fixe ) echo '<span style="font-size:12px;color:#888;">' . esc_html( $d->tel_fixe ) . '</span>'; ?>
                    <?php if ( ! $d->tel_portable && ! $d->tel_fixe ) echo '—'; ?>
                </td>
                <td><?php echo esc_html( $d->ville ?: '—' ); ?></td>
                <td>
                    <form method="post" id="adh-form-<?php echo $did; ?>" style="display:inline;">
                        <?php wp_nonce_field( 'seliweb_demandes', 'seliweb_demandes_nonce' ); ?>
                        <input type="hidden" name="demande_id" value="<?php echo $did; ?>">
                        <select name="groupe_id" id="adh-groupe-<?php echo $did; ?>" onchange="this.form.submit()" style="max-width:160px;" <?php disabled( $acceptee ); ?>>
                            <option value=""><?php esc_html_e( '— Aucun —', 'seliweb' ); ?></option>
                            <?php foreach ( $groupes as $g ) : ?>
                                <option value="<?php echo (int) $g->id; ?>" <?php selected( (int) $d->groupe_id, (int) $g->id ); ?>><?php echo esc_html( $g->nom ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td><a href="#" onclick="var r=document.getElementById('adh-detail-<?php echo $did; ?>');r.style.display=r.style.display==='none'?'':'none';return false;"><?php esc_html_e( 'Voir', 'seliweb' ); ?></a></td>
                <td style="text-align:center;">
                    <?php if ( $acceptee ) : ?>
                        <?php if ( $d->membre_id ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seliweb_membres&action=edit&id=' . (int) $d->membre_id ) ); ?>"><?php esc_html_e( 'Membre', 'seliweb' ); ?></a>
                        <?php else : ?>✓<?php endif; ?>
                    <?php else : ?>
                        <input type="checkbox" name="accepter" value="1" form="adh-form-<?php echo $did; ?>"
                               data-id="<?php echo $did; ?>" data-refusee="<?php echo $refusee ? '1' : '0'; ?>"
                               onclick="adhAccepter(this, event)"
                               title="<?php esc_attr_e( 'Accepter la demande et créer le membre', 'seliweb' ); ?>">
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <?php if ( $refusee ) : ?>
                        <input type="checkbox" checked disabled title="<?php esc_attr_e( 'Demande refusée', 'seliweb' ); ?>">
                    <?php elseif ( ! $acceptee ) : ?>
                        <input type="checkbox" name="refuser" value="1" form="adh-form-<?php echo $did; ?>"
                               onclick="adhRefuser(this, event)"
                               title="<?php esc_attr_e( 'Refuser la demande', 'seliweb' ); ?>">
                    <?php endif; ?>
                </td>
            </tr>
            <tr id="adh-detail-<?php echo $did; ?>" style="display:none;">
                <td colspan="11" style="background:#f6f7f7;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:4px 24px;font-size:13px;padding:6px 4px;">
                        <div><strong><?php esc_html_e( 'Civilité', 'seliweb' ); ?> :</strong> <?php echo esc_html( $d->civilite === 'Mr' ? 'M.' : ( $d->civilite ?: '—' ) ); ?></div>
                        <div><strong><?php esc_html_e( 'Identifiant', 'seliweb' ); ?> :</strong> <?php echo esc_html( $d->identifiant ); ?></div>
                        <div><strong><?php esc_html_e( 'Date de naissance', 'seliweb' ); ?> :</strong> <?php echo $d->date_naissance ? esc_html( mysql2date( $fmt_date, $d->date_naissance ) ) : '—'; ?></div>
                        <div><strong><?php esc_html_e( 'Adresse', 'seliweb' ); ?> :</strong> <?php echo esc_html( trim( $d->adresse1 . ' ' . $d->adresse2 ) ); ?></div>
                        <div><strong><?php esc_html_e( 'Code postal / Ville', 'seliweb' ); ?> :</strong> <?php echo esc_html( trim( $d->code_postal . ' ' . $d->ville ) ); ?></div>
                        <div><strong><?php esc_html_e( 'Conditions acceptées', 'seliweb' ); ?> :</strong> <?php echo $d->consentement_texte ? esc_html__( 'oui', 'seliweb' ) : '—'; ?></div>
                        <?php if ( $d->date_traitement ) : ?>
                            <div><strong><?php esc_html_e( 'Traitée le', 'seliweb' ); ?> :</strong> <?php echo esc_html( mysql2date( $fmt_date, $d->date_traitement ) ); ?></div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<script>
function adhAccepter(cb, e) {
    if (!cb.checked) return;
    var id = cb.dataset.id;
    if (!document.getElementById('adh-groupe-' + id).value) {
        e.preventDefault();
        alert(<?php echo wp_json_encode( __( 'Veuillez choisir un groupe avant d\'accepter cette demande.', 'seliweb' ) ); ?>);
        return;
    }
    var msg = cb.dataset.refusee === '1'
        ? <?php echo wp_json_encode( __( 'Attention, vous allez activer un membre qui a déjà été refusé, êtes-vous sûr ?', 'seliweb' ) ); ?>
        : <?php echo wp_json_encode( __( 'Accepter cette demande et créer le membre ?', 'seliweb' ) ); ?>;
    if (!confirm(msg)) { e.preventDefault(); return; }
    cb.form.submit();
}
function adhRefuser(cb, e) {
    if (!cb.checked) return;
    if (!confirm(<?php echo wp_json_encode( __( 'Refuser cette demande d\'adhésion ?', 'seliweb' ) ); ?>)) { e.preventDefault(); return; }
    cb.form.submit();
}
</script>
