<?php
/**
 * Test du rôle « Administrateur Seliweb » et de ses verrous (includes/class-roles.php).
 *
 *   php tests/test-roles.php "/chemin/du/site/WordPress"
 *
 * Crée des comptes fictifs (préfixe swtest_), vérifie chaque droit et chaque verrou, puis les supprime
 * (fiche membre Seliweb comprise). Vérifie aussi la suppression de l'ancien rôle « Trésorier ».
 * Affiche OK / ÉCHEC par point ; code de sortie 1 en cas d'échec.
 * Sur un site Local, ajouter : -d mysqli.default_socket=<socket MySQL du site>.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$racine = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( ! is_file( $racine . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage : php tests/test-roles.php /chemin/du/site/WordPress\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require $racine . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( 'Seliweb_Roles' ) ) {
	fwrite( STDERR, "Seliweb (avec Seliweb_Roles) n'est pas actif sur ce site.\n" );
	exit( 2 );
}

$echecs = 0;
function verifier( $libelle, $condition ) {
	global $echecs;
	echo ( $condition ? '  OK      ' : '  ÉCHEC   ' ) . $libelle . "\n";
	if ( ! $condition ) {
		$echecs++;
	}
}

$crees = array();
function compte( $login, $role ) {
	global $crees;
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.invalid', 'role' => $role ) );
	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, "Création de $login impossible : " . $id->get_error_message() . "\n" );
		exit( 2 );
	}
	$crees[] = (int) $id;
	return (int) $id;
}

function nettoyer() {
	global $crees, $wpdb;
	wp_set_current_user( 0 );
	foreach ( $crees as $id ) {
		// Seliweb refuse de supprimer un compte qui a une fiche membre : on retire d'abord la fiche (comptes fictifs, sans données).
		$wpdb->delete( $wpdb->prefix . 'seliweb_membres', array( 'wp_user_id' => $id ) );
		foreach ( get_posts( array( 'author' => $id, 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
			wp_delete_post( $p->ID, true );
		}
		wp_delete_user( $id );
	}
}

// Ancien rôle Trésorier, recréé pour tester sa suppression.
remove_role( 'trsorier' );
add_role( 'trsorier', 'Trésorier', array( 'read' => true, 'manage_options' => true, 'edit_users' => true, 'promote_users' => true ) );

$admin_wp = compte( 'swtest_admin_wp', 'administrator' );
$admin_sw = compte( 'swtest_admin_seliweb', Seliweb_Roles::ROLE );
$admin_sw2 = compte( 'swtest_admin_seliweb_2', Seliweb_Roles::ROLE );
$editeur  = compte( 'swtest_editeur', 'editor' );
$abonne   = compte( 'swtest_abonne', 'subscriber' );
$tresorier = compte( 'swtest_tresorier', 'trsorier' );

echo "\n1. Suppression de l’ancien rôle Trésorier\n";
delete_option( Seliweb_Roles::OPTION );
Seliweb_Roles::maj();
clean_user_cache( $tresorier );
$t = get_userdata( $tresorier );
verifier( 'Rôle Trésorier supprimé', ! get_role( 'trsorier' ) );
verifier( 'Son titulaire devient Abonné', $t && $t->roles === array( 'subscriber' ) );
verifier( 'Son titulaire n’a plus l’administration Seliweb', ! Seliweb_Permissions::est_admin( $t ) );
verifier( 'Son titulaire n’a plus manage_options', ! user_can( $t, 'manage_options' ) );

echo "\n2. Rôle Administrateur Seliweb\n";
$role = get_role( Seliweb_Roles::ROLE );
verifier( 'Le rôle existe', (bool) $role );
foreach ( array( 'manage_options', 'install_plugins', 'activate_plugins', 'update_plugins', 'edit_plugins', 'switch_themes', 'edit_themes', 'update_core', 'unfiltered_html', 'export', 'import' ) as $d ) {
	verifier( "N’a pas : $d", ! user_can( $admin_sw, $d ) );
}
foreach ( array( Seliweb_Permissions::CAP_ADMIN, Seliweb_Permissions::CAP, 'list_users', 'create_users', 'edit_users', 'promote_users', 'edit_pages', 'edit_others_posts', 'publish_pages', 'upload_files', 'edit_theme_options' ) as $d ) {
	verifier( "A : $d", user_can( $admin_sw, $d ) );
}

echo "\n3. Accès aux écrans Seliweb\n";
wp_set_current_user( $admin_sw );
verifier( 'Administrateur Seliweb : administration Seliweb (Paramètres compris)', Seliweb_Permissions::est_admin() );
foreach ( array_keys( Seliweb_Permissions::modules() ) as $m ) {
	verifier( "Administrateur Seliweb : création sur le module $m", Seliweb_Permissions::peut( $m, 'creer' ) );
}
wp_set_current_user( $admin_wp );
verifier( 'Administrateur WordPress : administration Seliweb', Seliweb_Permissions::est_admin() );
wp_set_current_user( $abonne );
verifier( 'Abonné sans droit Gestionnaires : pas d’administration Seliweb', ! Seliweb_Permissions::est_admin() );
verifier( 'Abonné sans droit Gestionnaires : pas de back-office Seliweb', ! current_user_can( Seliweb_Permissions::CAP ) );

echo "\n4. Rôles attribuables\n";
wp_set_current_user( $admin_sw );
$roles = get_editable_roles();
foreach ( array( 'subscriber', 'contributor', 'author', 'editor' ) as $r ) {
	verifier( "Administrateur Seliweb peut attribuer : $r", isset( $roles[ $r ] ) );
}
verifier( 'Administrateur Seliweb ne peut pas attribuer : Administrateur', ! isset( $roles['administrator'] ) );
verifier( 'Administrateur Seliweb ne peut pas attribuer : Administrateur Seliweb', ! isset( $roles[ Seliweb_Roles::ROLE ] ) );
wp_set_current_user( $admin_wp );
$roles = get_editable_roles();
verifier( 'Administrateur WordPress peut attribuer : Administrateur Seliweb', isset( $roles[ Seliweb_Roles::ROLE ] ) );
verifier( 'Administrateur WordPress peut attribuer : Administrateur', isset( $roles['administrator'] ) );

echo "\n5. Comptes protégés\n";
foreach ( array( 'edit_user' => 'modifier', 'delete_user' => 'supprimer', 'promote_user' => 'changer le rôle' ) as $cap => $l ) {
	verifier( "Administrateur Seliweb ne peut pas $l : Administrateur WordPress", ! user_can( $admin_sw, $cap, $admin_wp ) );
	verifier( "Administrateur Seliweb ne peut pas $l : autre Administrateur Seliweb", ! user_can( $admin_sw, $cap, $admin_sw2 ) );
}
verifier( 'Administrateur Seliweb peut modifier un Éditeur', user_can( $admin_sw, 'edit_user', $editeur ) );
verifier( 'Administrateur Seliweb peut changer le rôle d’un Abonné', user_can( $admin_sw, 'promote_user', $abonne ) );
verifier( 'Administrateur Seliweb peut modifier son propre compte', user_can( $admin_sw, 'edit_user', $admin_sw ) );
verifier( 'Administrateur WordPress peut modifier un Administrateur Seliweb', user_can( $admin_wp, 'edit_user', $admin_sw ) );

nettoyer();
echo "\n" . ( $echecs ? "$echecs ÉCHEC(S)\n" : "Tout est OK.\n" );
exit( $echecs ? 1 : 0 );
