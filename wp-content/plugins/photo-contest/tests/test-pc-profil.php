<?php
/**
 * Harness léger : profil (opt-in prochain concours, défaut RGPD).
 * Usage : php wp-content/plugins/photo-contest/tests/test-pc-profil.php
 */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'sdlp.test';
$_SERVER['REQUEST_URI'] = '/';
require_once dirname( __DIR__, 4 ) . '/wp-load.php';

$failures = 0; $tests = 0;
function assertEq( $e, $a, string $l ): void {
    global $failures, $tests; $tests++;
    if ( $e === $a ) { echo "  ✓ $l\n"; }
    else { $failures++; echo "  ✗ $l — attendu " . var_export($e,true) . ", obtenu " . var_export($a,true) . "\n"; }
}
function assertTrue( $c, string $l ): void { assertEq( true, (bool)$c, $l ); }

global $wpdb;
$profiles_t = PC_Database::table( PC_Database::TABLE_PROFILES );

$uid = wp_insert_user( [
    'user_login' => 'pc_test_profil_' . uniqid(),
    'user_pass'  => wp_generate_password(),
    'user_email' => 'profil_' . uniqid() . '@sdlp.test',
    'role'       => 'pc_candidat',
] );

$profile = PC_Profile::get_instance();
$base = [
    'prenom' => 'Jean', 'nom' => 'Martin', 'date_naissance' => '1990-01-01',
    'adresse_rue' => '1 rue X', 'code_postal' => '75000', 'ville' => 'Paris',
    'pays' => 'France', 'telephone' => '0102030405',
];

echo "== save_profile + opt_in_prochain ==\n";
$profile->save_profile( $uid, array_merge( $base, [ 'opt_in_prochain' => '1' ] ) );
assertEq( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT opt_in_prochain FROM {$profiles_t} WHERE user_id=%d", $uid ) ), 'opt-in coché -> 1' );

$profile->save_profile( $uid, array_merge( $base, [ 'opt_in_prochain' => '0' ] ) );
assertEq( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT opt_in_prochain FROM {$profiles_t} WHERE user_id=%d", $uid ) ), 'opt-in décoché -> 0' );

assertEq( 'Martin', $wpdb->get_var( $wpdb->prepare( "SELECT nom FROM {$profiles_t} WHERE user_id=%d", $uid ) ), 'champ texte conservé' );

echo "\n== défaut rgpd_texte ==\n";
assertTrue( PC_Settings::get( 'rgpd_texte', '' ) !== '', 'rgpd_texte par défaut non vide' );

// ── Nettoyage ──
$wpdb->delete( $profiles_t, [ 'user_id' => $uid ] );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $uid );

echo "\n--------------------------------------------\n";
echo "$tests tests · $failures échecs\n";
exit( $failures > 0 ? 1 : 0 );
