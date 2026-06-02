<?php
/**
 * Harness léger pour tester PC_Categories sans framework.
 * Usage : php wp-content/plugins/photo-contest/tests/test-pc-categories.php
 * À lancer après modification de PC_Categories.
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

function assertTrue( $cond, string $label ): void {
    assertEq( true, (bool) $cond, $label );
}

echo "== PC_Categories::get_all (only_active=false) ==\n";
$all = PC_Categories::get_all( false );
assertTrue( is_array( $all ),                'retourne un tableau' );
assertTrue( count( $all ) >= 1,              'au moins 1 catégorie (la default)' );
assertTrue( isset( $all[0]['id'], $all[0]['nom'], $all[0]['actif'] ), 'structure ligne complète' );

echo "\n== PC_Categories::create / update / toggle / delete ==\n";
$new_id = PC_Categories::create( 'Test catégorie ' . uniqid() );
assertTrue( $new_id > 0, 'create retourne un ID > 0' );

$row = PC_Categories::get( $new_id );
assertEq( 1, (int) $row['actif'], 'créée active par défaut' );

assertTrue( PC_Categories::update( $new_id, 'Renommée' ),  'update OK' );
$row = PC_Categories::get( $new_id );
assertEq( 'Renommée', $row['nom'], 'nom mis à jour' );

assertTrue( PC_Categories::toggle( $new_id ),              'toggle OK' );
$row = PC_Categories::get( $new_id );
assertEq( 0, (int) $row['actif'], 'inactive après toggle' );

assertEq( 0, PC_Categories::count_photos( $new_id ),       'aucune photo dans la nouvelle cat' );

$res = PC_Categories::delete( $new_id );
assertTrue( $res === true, 'delete OK quand 0 photos' );
assertEq( null, PC_Categories::get( $new_id ),             'plus en BDD après delete' );

echo "\n== PC_Categories::delete refuse si photos ==\n";
$default = PC_Categories::get_all( false )[0];
$count   = PC_Categories::count_photos( (int) $default['id'] );
if ( $count > 0 ) {
    $res = PC_Categories::delete( (int) $default['id'] );
    assertTrue( $res instanceof WP_Error, 'delete retourne WP_Error si photos existent' );
}

echo "\n--------------------------------------------\n";
echo "$tests tests · $failures échecs\n";
exit( $failures > 0 ? 1 : 0 );
