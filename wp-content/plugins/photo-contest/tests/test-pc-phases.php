<?php
/**
 * Harness léger pour tester la logique de phase de PC_Settings.
 * Usage : php wp-content/plugins/photo-contest/tests/test-pc-phases.php
 * À lancer après modification de PC_Settings (dates, is_depot_actif, is_jury_actif).
 *
 * Sauvegarde et restaure pc_settings + timezone_string : sans effet de bord persistant.
 */
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'sdlp.test';
$_SERVER['REQUEST_URI'] = '/';
require_once dirname( __DIR__, 4 ) . '/wp-load.php';

$failures = 0;
$tests    = 0;

function assertEq( $expected, $actual, string $label ): void {
    global $failures, $tests;
    $tests++;
    if ( $expected === $actual ) {
        echo "  ✓ $label\n";
    } else {
        $failures++;
        echo "  ✗ $label — attendu " . var_export( $expected, true ) . ", obtenu " . var_export( $actual, true ) . "\n";
    }
}
function assertTrue( $cond, string $label ): void { assertEq( true, (bool) $cond, $label ); }
function assertFalse( $cond, string $label ): void { assertEq( false, (bool) $cond, $label ); }

// ── Sauvegarde de l'état pour restauration en fin de run ────────────────
$backup_settings = get_option( 'pc_settings', [] );
$backup_tzstring = get_option( 'timezone_string', '' );
$backup_default  = date_default_timezone_get();

// Fuseau du site = Europe/Paris pour toute la durée des tests.
update_option( 'timezone_string', 'Europe/Paris' );

// Helper : positionner un jeu de réglages de phase propre.
$set_phase = static function ( array $over = [] ): void {
    PC_Settings::set( array_merge( [
        'depot_actif'          => true,
        'jury_actif'           => false,
        'catalogue_actif'      => false,
        'date_ouverture'       => '',
        'date_fermeture_depot' => '',
        'cloture_effectuee_at' => 0,
    ], $over ) );
};

echo "== PC_Settings::normalize_stored_date ==\n";
assertEq( '', PC_Settings::normalize_stored_date( '' ),                       'chaîne vide -> vide' );
assertEq( '2026-09-30 23:59:00', PC_Settings::normalize_stored_date( '2026-09-30T23:59' ), 'datetime-local -> Y-m-d H:i:s' );
assertEq( '', PC_Settings::normalize_stored_date( 'pas-une-date' ),           'invalide -> vide' );

echo "\n== Ancrage sur wp_timezone() (indépendant du fuseau serveur) ==\n";
$ref = new ReflectionMethod( PC_Settings::class, 'date_to_ts' );
$ref->setAccessible( true );
$expected_ts = ( new DateTimeImmutable( '2026-09-30 23:59:00', new DateTimeZone( 'Europe/Paris' ) ) )->getTimestamp();
date_default_timezone_set( 'America/New_York' ); // simule un serveur à un autre fuseau
$ts_ny = $ref->invoke( null, '2026-09-30 23:59:00' );
date_default_timezone_set( 'UTC' );
$ts_utc = $ref->invoke( null, '2026-09-30 23:59:00' );
assertEq( $expected_ts, $ts_ny,  'même instant peu importe le fuseau serveur (NY)' );
assertEq( $expected_ts, $ts_utc, 'même instant peu importe le fuseau serveur (UTC)' );
assertEq( null, $ref->invoke( null, '' ), 'date vide -> null' );
date_default_timezone_set( $backup_default );

echo "\n== PC_Settings::is_depot_actif ==\n";
$set_phase();
assertTrue(  PC_Settings::is_depot_actif(), 'actif si depot_actif et aucune date' );
$set_phase( [ 'depot_actif' => false ] );
assertFalse( PC_Settings::is_depot_actif(), 'inactif si interrupteur maître coupé' );
$set_phase( [ 'date_ouverture' => '2099-01-01 00:00:00' ] );
assertFalse( PC_Settings::is_depot_actif(), 'inactif si ouverture dans le futur' );
$set_phase( [ 'date_ouverture' => '2000-01-01 00:00:00', 'date_fermeture_depot' => '2099-01-01 00:00:00' ] );
assertTrue(  PC_Settings::is_depot_actif(), 'actif entre ouverture passée et clôture future' );
$set_phase( [ 'date_fermeture_depot' => '2000-01-01 00:00:00' ] );
assertFalse( PC_Settings::is_depot_actif(), 'inactif si clôture dépassée' );

echo "\n== PC_Settings::is_jury_actif ==\n";
$set_phase();
assertFalse( PC_Settings::is_jury_actif(), 'inactif par défaut (aucune date, pas de forçage)' );
$set_phase( [ 'date_fermeture_depot' => '2099-01-01 00:00:00' ] );
assertFalse( PC_Settings::is_jury_actif(), 'inactif si clôture dépôt future' );
$set_phase( [ 'date_fermeture_depot' => '2000-01-01 00:00:00' ] );
assertTrue(  PC_Settings::is_jury_actif(), 'actif auto si clôture dépôt dépassée' );
$set_phase( [ 'date_fermeture_depot' => '2000-01-01 00:00:00', 'cloture_effectuee_at' => time() ] );
assertFalse( PC_Settings::is_jury_actif(), 'verrouillé après clôture (chemin auto)' );
$set_phase( [ 'jury_actif' => true, 'cloture_effectuee_at' => time(), 'date_fermeture_depot' => '2000-01-01 00:00:00' ] );
assertTrue(  PC_Settings::is_jury_actif(), 'forçage manuel prioritaire même après clôture' );
$set_phase( [ 'jury_actif' => true, 'date_fermeture_depot' => '2099-01-01 00:00:00' ] );
assertTrue(  PC_Settings::is_jury_actif(), 'forçage manuel prioritaire sur date future' );

// ── Restauration ────────────────────────────────────────────────────────
update_option( 'pc_settings', $backup_settings );
update_option( 'timezone_string', $backup_tzstring );

echo "\n--------------------------------------------\n";
echo "$tests tests · $failures échecs\n";
exit( $failures > 0 ? 1 : 0 );
