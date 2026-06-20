<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) return;

global $wpdb;
$tt = $wpdb->prefix . 'seliweb_transactions';
$te = $wpdb->prefix . 'seliweb_ecritures';
$tm = $wpdb->prefix . 'seliweb_membres';

$sel      = Seliweb_Transactions::get_sel_info();
$page_url = admin_url( 'admin.php?page=seliweb_transactions' );

if ( ! $sel['actif'] || ! $sel['groupe_id'] ) { ?>
<div class="wrap">
    <h1><?php esc_html_e( 'Transactions', 'seliweb' ); ?></h1>
    <p><?php esc_html_e( 'Le module SEL doit être activé avec un groupe SEL défini dans Paramètres → SEL.', 'seliweb' ); ?></p>
</div>
<?php return; }

$membres_sel = Seliweb_Transactions::get_sel_membres( $sel['groupe_id'] );
$monnaie     = $sel['monnaie_id'] ? Seliweb_Transactions::get_monnaie( $sel['monnaie_id'] ) : null;
$symbole_mon = $monnaie ? ( $monnaie->symbole ?: $monnaie->nom ) : '';

// Erreur et action forcée provenant du traitement POST (hook admin_init)
$error  = Seliweb_Transactions::get_form_error();
$action = Seliweb_Transactions::get_force_action() ?: ( isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '' );
$txn_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
if ( isset( $_POST['transaction_id'] ) ) $txn_id = intval( $_POST['transaction_id'] );

// Données pour pré-remplissage formulaire modifier
$txn_edit       = null;
$txn_edit_debit = 0;
$txn_edit_cred  = 0;
if ( $action === 'modifier' && $txn_id ) {
    $txn_edit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tt WHERE id=%d", $txn_id ) );
    if ( $txn_edit ) {
        $txn_edit_debit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT membre_id FROM $te WHERE transaction_id=%d AND type='debit'",  $txn_id ) );
        $txn_edit_cred  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT membre_id FROM $te WHERE transaction_id=%d AND type='credit'", $txn_id ) );
    }
}
?>
<div class="wrap">

<?php if ( isset( $_GET['added'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Transaction ajoutée.', 'seliweb' ); ?></p></div>
<?php elseif ( isset( $_GET['updated'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Transaction modifiée.', 'seliweb' ); ?></p></div>
<?php endif; ?>

<?php if ( $error ) : ?>
    <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
<?php endif; ?>

<?php
// ================================================================
// Formulaire Ajouter / Modifier
// ================================================================
if ( $action === 'ajouter' || $action === 'modifier' ) :
    $is_edit    = ( $action === 'modifier' && $txn_edit );
    $form_url   = $page_url . ( $is_edit ? '&action=modifier&id=' . intval( $txn_id ) : '&action=ajouter' );
    $form_title = $is_edit ? __( 'Modifier la transaction', 'seliweb' ) : __( 'Ajouter une transaction', 'seliweb' );

    // Pré-remplissage : en cas d'erreur POST, utiliser $_POST ; sinon données existantes
    $f_debit   = $error ? intval( $_POST['membre_debit_id']  ?? 0 )        : ( $is_edit ? $txn_edit_debit          : 0 );
    $f_credit  = $error ? intval( $_POST['membre_credit_id'] ?? 0 )        : ( $is_edit ? $txn_edit_cred           : 0 );
    $f_montant = $error ? intval( $_POST['montant']          ?? 0 )        : ( $is_edit ? intval( $txn_edit->montant ) : 0 );
    $f_libelle = $error ? sanitize_text_field( wp_unslash( $_POST['libelle'] ?? '' ) ) : ( $is_edit ? $txn_edit->libelle : '' );
    $f_date    = $error ? sanitize_text_field( wp_unslash( $_POST['date']    ?? '' ) ) : ( $is_edit ? $txn_edit->date    : date( 'Y-m-d' ) );

    $ac_membres = array_map( function( $mb ) {
        return array( 'id' => intval( $mb->id ), 'label' => Seliweb_Transactions::membre_label( $mb ) );
    }, $membres_sel );
    $label_debit = $label_credit = '';
    foreach ( $membres_sel as $mb ) {
        if ( $f_debit  && intval( $mb->id ) === $f_debit  ) $label_debit  = Seliweb_Transactions::membre_label( $mb );
        if ( $f_credit && intval( $mb->id ) === $f_credit ) $label_credit = Seliweb_Transactions::membre_label( $mb );
    }
?>
    <style>
    .swv-ac-wrap{position:relative;display:inline-block;min-width:280px;}
    .swv-ac-input{width:100%;padding:4px 6px;font-size:14px;border:1px solid #8c8f94;border-radius:3px;box-sizing:border-box;}
    .swv-ac-input:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;outline:none;}
    .swv-ac-list{position:absolute;top:100%;left:0;right:0;z-index:9999;background:#fff;border:1px solid #8c8f94;border-top:0;list-style:none;margin:0;padding:0;max-height:220px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.18);}
    .swv-ac-list li{padding:7px 10px;cursor:pointer;font-size:13px;border-bottom:1px solid #f6f7f7;}
    .swv-ac-list li:hover{background:#f0f6ff;}
    </style>

    <h1>
        <?php echo esc_html( $form_title ); ?>
        <a href="<?php echo esc_url( $page_url ); ?>" class="page-title-action"><?php esc_html_e( '← Retour', 'seliweb' ); ?></a>
    </h1>

    <form method="post" action="<?php echo esc_url( $form_url ); ?>">
        <?php if ( $is_edit ) : ?>
            <?php wp_nonce_field( 'seliweb_transaction_edit_' . intval( $txn_id ) ); ?>
            <input type="hidden" name="transaction_id" value="<?php echo intval( $txn_id ); ?>">
        <?php else : ?>
            <?php wp_nonce_field( 'seliweb_transaction_add' ); ?>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Membre débité', 'seliweb' ); ?></th>
                <td>
                    <div class="swv-ac-wrap" id="swv_wrap_debit"
                         data-value="<?php echo esc_attr( $f_debit ); ?>"
                         data-label="<?php echo esc_attr( $label_debit ); ?>"></div>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Membre crédité', 'seliweb' ); ?></th>
                <td>
                    <div class="swv-ac-wrap" id="swv_wrap_credit"
                         data-value="<?php echo esc_attr( $f_credit ); ?>"
                         data-label="<?php echo esc_attr( $label_credit ); ?>"></div>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Montant', 'seliweb' ); ?></th>
                <td>
                    <input type="number" name="montant" min="1" step="1" required
                           class="small-text"
                           value="<?php echo $f_montant > 0 ? intval( $f_montant ) : ''; ?>">
                    <?php if ( $symbole_mon ) echo ' <strong>' . esc_html( $symbole_mon ) . '</strong>'; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Libellé', 'seliweb' ); ?></th>
                <td>
                    <input type="text" name="libelle" class="regular-text" required
                           value="<?php echo esc_attr( $f_libelle ); ?>">
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Date', 'seliweb' ); ?></th>
                <td>
                    <input type="date" name="date" required value="<?php echo esc_attr( $f_date ); ?>">
                </td>
            </tr>
        </table>

        <?php if ( $is_edit ) : ?>
            <input type="submit" name="seliweb_modifier_transaction" class="button button-primary"
                   value="<?php esc_attr_e( 'Enregistrer les modifications', 'seliweb' ); ?>">
        <?php else : ?>
            <input type="submit" name="seliweb_ajouter_transaction" class="button button-primary"
                   value="<?php esc_attr_e( 'Valider la transaction', 'seliweb' ); ?>">
        <?php endif; ?>
        <a href="<?php echo esc_url( $page_url ); ?>" class="button" style="margin-left:8px;">
            <?php esc_html_e( 'Annuler', 'seliweb' ); ?>
        </a>
    </form>
    <script>
    function swvAc(wrapId, hiddenName, items, placeholder) {
        var wrap = document.getElementById(wrapId);
        if (!wrap) return;
        var txt = document.createElement('input');
        txt.type = 'text'; txt.placeholder = placeholder || ''; txt.autocomplete = 'off'; txt.className = 'swv-ac-input';
        var hid = document.createElement('input');
        hid.type = 'hidden'; hid.name = hiddenName; hid.value = wrap.dataset.value || '';
        var list = document.createElement('ul');
        list.className = 'swv-ac-list'; list.hidden = true;
        if (wrap.dataset.value && wrap.dataset.label) txt.value = wrap.dataset.label;
        wrap.appendChild(txt); wrap.appendChild(hid); wrap.appendChild(list);
        function render(matches) {
            list.innerHTML = '';
            if (!matches.length) { list.hidden = true; return; }
            matches.slice(0, 15).forEach(function(item) {
                var li = document.createElement('li');
                li.textContent = item.label;
                li.addEventListener('mousedown', function(e) { e.preventDefault(); txt.value = item.label; hid.value = item.id; list.hidden = true; });
                list.appendChild(li);
            });
            list.hidden = false;
        }
        txt.addEventListener('input', function() {
            hid.value = '';
            var q = this.value.toLowerCase().trim();
            if (!q) { list.hidden = true; return; }
            render(items.filter(function(i) { return i.label.toLowerCase().indexOf(q) !== -1; }));
        });
        txt.addEventListener('focus', function() {
            var q = this.value.toLowerCase().trim();
            if (q) render(items.filter(function(i) { return i.label.toLowerCase().indexOf(q) !== -1; }));
        });
        txt.addEventListener('blur', function() { setTimeout(function() { list.hidden = true; }, 200); });
    }
    var swvMembres = <?php echo wp_json_encode( $ac_membres ); ?>;
    var swvPh = '<?php echo esc_js( __( 'Taper un nom ou un N°…', 'seliweb' ) ); ?>';
    swvAc('swv_wrap_debit',  'membre_debit_id',  swvMembres, swvPh);
    swvAc('swv_wrap_credit', 'membre_credit_id', swvMembres, swvPh);
    document.querySelector('form').addEventListener('submit', function(e) {
        if (!document.querySelector('[name="membre_debit_id"]').value)
            { e.preventDefault(); alert('<?php echo esc_js( __( 'Veuillez choisir le membre débité.', 'seliweb' ) ); ?>'); return; }
        if (!document.querySelector('[name="membre_credit_id"]').value)
            { e.preventDefault(); alert('<?php echo esc_js( __( 'Veuillez choisir le membre crédité.', 'seliweb' ) ); ?>'); }
    });
    </script>

<?php else :
// ================================================================
// Liste des écritures
// ================================================================

$f_membre = isset( $_GET['f_membre'] ) ? intval( $_GET['f_membre'] )           : 0;
$f_date   = isset( $_GET['f_date'] )   ? sanitize_text_field( $_GET['f_date'] ) : '';
$par_page = isset( $_GET['par_page'] ) ? intval( $_GET['par_page'] )           : 50;
$par_page = in_array( $par_page, array( 50, 100, 250 ), true ) ? $par_page : 50;
$page_cur = max( 1, intval( $_GET['sel_page'] ?? 1 ) );

$allowed_orderby = array(
    'date'   => 't.date, t.id',
    'numero' => 'ISNULL(m.numero_sel), m.numero_sel',
    'nom'    => 'um_ln.meta_value, um_fn.meta_value',
);
$orderby_key = ( isset( $_GET['orderby'] ) && array_key_exists( $_GET['orderby'], $allowed_orderby ) )
    ? sanitize_key( $_GET['orderby'] ) : 'date';
$order       = ( isset( $_GET['order'] ) && strtolower( $_GET['order'] ) === 'asc' ) ? 'ASC' : 'DESC';
$order_sql   = $allowed_orderby[ $orderby_key ] . ' ' . $order . ', e.type ASC';

$where     = array( '1=1' );
$where_val = array();
if ( $f_membre ) {
    $where[]     = 'e.membre_id = %d';
    $where_val[] = $f_membre;
}
if ( $f_date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f_date ) ) {
    $where[]     = 't.date = %s';
    $where_val[] = $f_date;
}
$where_sql = implode( ' AND ', $where );

$count_sql = "SELECT COUNT(*) FROM $te e JOIN $tt t ON t.id = e.transaction_id WHERE $where_sql";
$total     = (int) ( $where_val ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$where_val ) ) : $wpdb->get_var( $count_sql ) );
$nb_pages  = max( 1, (int) ceil( $total / $par_page ) );
$offset    = ( $page_cur - 1 ) * $par_page;

$data_sql = "SELECT e.id AS ecriture_id, t.id AS txn_id, t.date, t.libelle, t.montant, e.type,
                    m.numero_sel,
                    um_fn.meta_value AS prenom, um_ln.meta_value AS nom
             FROM $te e
             JOIN $tt t ON t.id = e.transaction_id
             JOIN $tm m ON m.id = e.membre_id
             JOIN {$wpdb->users} u ON u.ID = m.wp_user_id
             LEFT JOIN {$wpdb->usermeta} um_fn ON um_fn.user_id = u.ID AND um_fn.meta_key = 'first_name'
             LEFT JOIN {$wpdb->usermeta} um_ln ON um_ln.user_id = u.ID AND um_ln.meta_key = 'last_name'
             WHERE $where_sql
             ORDER BY $order_sql
             LIMIT %d OFFSET %d";

$data_vals = array_merge( $where_val, array( $par_page, $offset ) );
$ecritures = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_vals ) );

$mk_url = function( $p ) use ( $f_membre, $f_date, $par_page, $orderby_key, $order ) {
    return esc_url( add_query_arg( array_filter( array(
        'page'     => 'seliweb_transactions',
        'f_membre' => $f_membre ?: null,
        'f_date'   => $f_date   ?: null,
        'par_page' => $par_page !== 50 ? $par_page : null,
        'orderby'  => $orderby_key !== 'date' ? $orderby_key : null,
        'order'    => $order !== 'DESC' ? strtolower( $order ) : null,
        'sel_page' => $p,
    ) ), admin_url( 'admin.php' ) ) );
};

$sort_url = function( $col ) use ( $f_membre, $f_date, $par_page, $orderby_key, $order ) {
    $new_order = ( $orderby_key === $col && $order === 'DESC' ) ? 'asc' : 'desc';
    return esc_url( add_query_arg( array_filter( array(
        'page'     => 'seliweb_transactions',
        'f_membre' => $f_membre ?: null,
        'f_date'   => $f_date   ?: null,
        'par_page' => $par_page !== 50 ? $par_page : null,
        'orderby'  => $col,
        'order'    => $new_order,
    ) ), admin_url( 'admin.php' ) ) );
};

$sort_icon = function( $col ) use ( $orderby_key, $order ) {
    if ( $orderby_key !== $col ) return '';
    return ' ' . ( $order === 'ASC' ? '▲' : '▼' );
};
?>
    <h1>
        <?php esc_html_e( 'Transactions', 'seliweb' ); ?>
        <a href="<?php echo esc_url( add_query_arg( 'action', 'ajouter', $page_url ) ); ?>" class="page-title-action">
            + <?php esc_html_e( 'Ajouter une transaction', 'seliweb' ); ?>
        </a>
    </h1>

    <!-- Filtres -->
    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
          style="margin:16px 0;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="page" value="seliweb_transactions">

        <div>
            <label><strong><?php esc_html_e( 'Membre', 'seliweb' ); ?></strong><br>
            <select name="f_membre">
                <option value=""><?php esc_html_e( '— Tous —', 'seliweb' ); ?></option>
                <?php foreach ( $membres_sel as $mb ) : ?>
                    <option value="<?php echo intval( $mb->id ); ?>" <?php selected( $f_membre, $mb->id ); ?>>
                        <?php echo esc_html( Seliweb_Transactions::membre_label( $mb ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select></label>
        </div>

        <div>
            <label><strong><?php esc_html_e( 'Date', 'seliweb' ); ?></strong><br>
            <input type="date" name="f_date" value="<?php echo esc_attr( $f_date ); ?>"></label>
        </div>

        <div>
            <label><strong><?php esc_html_e( 'Par page', 'seliweb' ); ?></strong><br>
            <select name="par_page">
                <?php foreach ( array( 50, 100, 250 ) as $pp ) : ?>
                    <option value="<?php echo $pp; ?>" <?php selected( $par_page, $pp ); ?>><?php echo $pp; ?></option>
                <?php endforeach; ?>
            </select></label>
        </div>

        <div style="display:flex;gap:4px;">
            <input type="submit" class="button" value="<?php esc_attr_e( 'Filtrer', 'seliweb' ); ?>">
            <a href="<?php echo esc_url( $page_url ); ?>" class="button"><?php esc_html_e( 'Réinitialiser', 'seliweb' ); ?></a>
        </div>
    </form>

    <!-- Pagination haut -->
    <?php if ( $nb_pages > 1 ) : ?>
    <div style="margin-bottom:12px;">
        <?php if ( $page_cur > 1 ) : ?>
            <a href="<?php echo $mk_url( $page_cur - 1 ); ?>" class="button button-small">&laquo; <?php esc_html_e( 'Précédent', 'seliweb' ); ?></a>
        <?php endif; ?>
        <span style="margin:0 8px;">
            <?php printf( esc_html__( 'Page %d sur %d — %d écriture(s)', 'seliweb' ), $page_cur, $nb_pages, $total ); ?>
        </span>
        <?php if ( $page_cur < $nb_pages ) : ?>
            <a href="<?php echo $mk_url( $page_cur + 1 ); ?>" class="button button-small"><?php esc_html_e( 'Suivant', 'seliweb' ); ?> &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Tableau -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:50px;"><?php esc_html_e( 'ID', 'seliweb' ); ?></th>
                <th style="width:88px;">
                    <a href="<?php echo $sort_url('date'); ?>" style="text-decoration:none;color:inherit;white-space:nowrap;">
                        <?php esc_html_e( 'Date', 'seliweb' ); echo $sort_icon('date'); ?>
                    </a>
                </th>
                <th><?php esc_html_e( 'Libellé', 'seliweb' ); ?></th>
                <th style="width:76px;text-align:right;"><?php esc_html_e( 'Débit', 'seliweb' ); ?></th>
                <th style="width:76px;text-align:right;"><?php esc_html_e( 'Crédit', 'seliweb' ); ?></th>
                <th style="width:60px;text-align:center;">
                    <a href="<?php echo $sort_url('numero'); ?>" style="text-decoration:none;color:inherit;white-space:nowrap;">
                        <?php esc_html_e( 'N° Mbr', 'seliweb' ); echo $sort_icon('numero'); ?>
                    </a>
                </th>
                <th>
                    <a href="<?php echo $sort_url('nom'); ?>" style="text-decoration:none;color:inherit;white-space:nowrap;">
                        <?php esc_html_e( 'Prénom Nom', 'seliweb' ); echo $sort_icon('nom'); ?>
                    </a>
                </th>
                <th style="width:72px;"><?php esc_html_e( 'Action', 'seliweb' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $ecritures ) ) : ?>
            <tr><td colspan="8" style="text-align:center;padding:20px;">
                <?php esc_html_e( 'Aucune écriture trouvée.', 'seliweb' ); ?>
            </td></tr>
        <?php else : ?>
            <?php foreach ( $ecritures as $e ) :
                $is_debit   = ( $e->type === 'debit' );
                $nom_prenom = intval( $e->numero_sel ) === 1
                    ? __( 'Compte du SEL', 'seliweb' )
                    : trim( ( $e->prenom ?? '' ) . ' ' . ( $e->nom ?? '' ) );
                $edit_url   = add_query_arg( array( 'action' => 'modifier', 'id' => intval( $e->txn_id ) ), $page_url );
                $montant_fmt = intval( $e->montant ) . ( $symbole_mon ? ' ' . $symbole_mon : '' );
            ?>
            <tr>
                <td><?php echo intval( $e->txn_id ); ?></td>
                <td><?php echo esc_html( $e->date ); ?></td>
                <td><?php echo esc_html( $e->libelle ); ?></td>
                <td style="text-align:right;color:#c0392b;font-weight:500;">
                    <?php echo $is_debit ? esc_html( $montant_fmt ) : ''; ?>
                </td>
                <td style="text-align:right;color:#27ae60;font-weight:500;">
                    <?php echo ! $is_debit ? esc_html( $montant_fmt ) : ''; ?>
                </td>
                <td style="text-align:center;"><?php echo esc_html( $e->numero_sel ); ?></td>
                <td><?php echo esc_html( $nom_prenom ); ?></td>
                <td><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Modifier', 'seliweb' ); ?></a></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination bas -->
    <?php if ( $nb_pages > 1 ) : ?>
    <div style="margin-top:12px;">
        <?php if ( $page_cur > 1 ) : ?>
            <a href="<?php echo $mk_url( $page_cur - 1 ); ?>" class="button">&laquo; <?php esc_html_e( 'Précédent', 'seliweb' ); ?></a>
        <?php endif; ?>
        <span style="margin:0 8px;">
            <?php printf( esc_html__( 'Page %d sur %d', 'seliweb' ), $page_cur, $nb_pages ); ?>
        </span>
        <?php if ( $page_cur < $nb_pages ) : ?>
            <a href="<?php echo $mk_url( $page_cur + 1 ); ?>" class="button"><?php esc_html_e( 'Suivant', 'seliweb' ); ?> &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>
</div>
