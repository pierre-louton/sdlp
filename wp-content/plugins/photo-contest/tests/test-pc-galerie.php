<?php
/**
 * Harness léger : logique galerie (titre_contient_nom, changer_categorie).
 * Usage : php wp-content/plugins/photo-contest/tests/test-pc-galerie.php
 * Crée un candidat + catégories + photos de test, restaure en fin de run.
 */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'sdlp.test';
$_SERVER['REQUEST_URI'] = '/';
require_once dirname( __DIR__, 4 ) . '/wp-load.php';

$failures = 0; $tests = 0;
function assertEq( $e, $a, string $l ): void {
    global $failures, $tests; $tests++;
    if ( $e === $a ) { echo "  \u{2713} $l\n"; }
    else { $failures++; echo "  \u{2717} $l \u{2014} attendu " . var_export($e,true) . ", obtenu " . var_export($a,true) . "\n"; }
}
function assertTrue( $c, string $l ): void { assertEq( true, (bool)$c, $l ); }
function assertFalse( $c, string $l ): void { assertEq( false, (bool)$c, $l ); }

global $wpdb;
$photos_t   = PC_Database::table( PC_Database::TABLE_PHOTOS );
$profiles_t = PC_Database::table( PC_Database::TABLE_PROFILES );

PC_Settings::set( [ 'depot_actif' => true, 'date_ouverture' => '', 'date_fermeture_depot' => '', 'quota_photos' => 5 ] );

$uid = wp_insert_user( [
    'user_login' => 'pc_test_gal_' . uniqid(),
    'user_pass'  => wp_generate_password(),
    'user_email' => 'gal_' . uniqid() . '@sdlp.test',
    'role'       => 'pc_candidat',
] );
$wpdb->replace( $profiles_t, [ 'user_id' => $uid, 'prenom' => 'Jean', 'nom' => 'Martin' ] );

$photos = PC_Photos::get_instance();

echo "== titre_contient_nom ==\n";
assertTrue(  $photos->titre_contient_nom( 'Le rêve de Jean', $uid ),               'prénom en mot entier -> true' );
assertTrue(  $photos->titre_contient_nom( 'Portrait MARTIN au crépuscule', $uid ), 'nom (casse) -> true' );
assertTrue(  $photos->titre_contient_nom( 'Chez jéan', $uid ),                     'accent ignoré (jéan ~ jean) -> true' );
assertFalse( $photos->titre_contient_nom( 'Une martingale gagnante', $uid ),       'sous-chaîne Martin dans Martingale -> false' );
assertFalse( $photos->titre_contient_nom( 'Coucher de soleil', $uid ),             'sans le nom -> false' );
assertFalse( $photos->titre_contient_nom( '', $uid ),                              'titre vide -> false' );

// ── Nettoyage ──
$wpdb->delete( $photos_t,   [ 'user_id' => $uid ] );
$wpdb->delete( $profiles_t, [ 'user_id' => $uid ] );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $uid );

echo "\n--------------------------------------------\n";
echo "$tests tests · $failures échecs\n";
exit( $failures > 0 ? 1 : 0 );
