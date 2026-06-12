<?php
/**
 * Harness léger pour la logique caddy/paiement de PC_Payments.
 * Usage : php wp-content/plugins/photo-contest/tests/test-pc-caddy.php
 * Crée un candidat + des photos de test, restaure en fin de run.
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
function assertFalse( $c, string $l ): void { assertEq( false, (bool)$c, $l ); }

global $wpdb;
$photos_t   = PC_Database::table( PC_Database::TABLE_PHOTOS );
$payments_t = PC_Database::table( PC_Database::TABLE_PAYMENTS );

PC_Settings::set( 'montant_participation_cts', 1500 );

// Candidat de test
$uid = wp_insert_user( [
    'user_login' => 'pc_test_caddy_' . uniqid(),
    'user_pass'  => wp_generate_password(),
    'user_email' => 'caddy_' . uniqid() . '@sdlp.test',
    'role'       => 'pc_candidat',
] );

// Helper : insérer une photo en_attente, renvoyer son id
$mk_photo = static function () use ( $wpdb, $photos_t, $uid ): int {
    $wpdb->insert( $photos_t, [
        'user_id' => $uid, 'category_id' => 1, 'titre' => 'Test',
        'nom_fichier' => 'x.jpg', 'chemin_fichier' => 'x.jpg',
        'largeur_px' => 900, 'hauteur_px' => 600, 'ratio_type' => '3_2',
        'taille_octets' => 100, 'statut' => 'en_attente',
    ] );
    return (int) $wpdb->insert_id;
};
// Helper : marquer une photo payée (ligne paiement_recu)
$mk_paid = static function ( int $photo_id ) use ( $wpdb, $payments_t, $uid ): void {
    $wpdb->insert( $payments_t, [
        'photo_id' => $photo_id, 'user_id' => $uid, 'montant_centimes' => 1500,
        'devise' => 'EUR', 'statut_paiement' => 'paiement_recu', 'methode' => 'test',
    ] );
};

$pay = PC_Payments::get_instance();

echo "== photo_est_payee ==\n";
$p1 = $mk_photo();
assertFalse( $pay->photo_est_payee( $p1 ), 'non payée sans ligne' );
$mk_paid( $p1 );
assertTrue( $pay->photo_est_payee( $p1 ), 'payée avec paiement_recu' );

echo "\n== get_caddy ==\n";
$p2 = $mk_photo(); // non payée
$p3 = $mk_photo(); $mk_paid( $p3 ); // payée
$c = $pay->get_caddy( $uid );
assertEq( 1500, $c['prix_unitaire_cts'], 'prix unitaire' );
assertEq( 2,    $c['nb_payees'],         '2 payées (p1, p3)' );
assertEq( 1,    $c['nb_non_payees'],     '1 non payée (p2)' );
assertEq( 3000, $c['montant_paye_cts'],  'montant payé' );
assertEq( 1500, $c['montant_du_cts'],    'montant dû' );
assertEq( 4500, $c['total_cts'],         'total' );

echo "\n== get_photos_pour_jury exclut les non payées ==\n";
// p2 non payée, p1 & p3 payées (cf. plus haut)
$jury_ids = array_map( fn($r) => (int) $r['id'], PC_Jury::get_instance()->get_photos_pour_jury( $uid ) );
assertTrue(  in_array( $p1, $jury_ids, true ), 'photo payée p1 visible jury' );
assertTrue(  in_array( $p3, $jury_ids, true ), 'photo payée p3 visible jury' );
assertFalse( in_array( $p2, $jury_ids, true ), 'photo non payée p2 exclue du jury' );

echo "\n== creer_lignes_panier ==\n";
// État courant : p1, p3 payées ; p2 non payée. On ajoute p4, p5 non payées.
$p4 = $mk_photo(); $p5 = $mk_photo();
$token = $pay->creer_lignes_panier( $uid );
assertTrue( is_string( $token ) && strlen( $token ) === 36, 'token UUID renvoyé' );
$lignes = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$payments_t} WHERE payment_token = %s AND statut_paiement = 'en_attente'", $token ) );
assertEq( 3, $lignes, '3 lignes en_attente (p2, p4, p5) sous le token' );
$dup = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$payments_t} WHERE payment_token = %s AND photo_id = %d", $token, $p1 ) );
assertEq( 0, $dup, 'pas de ligne pour une photo déjà payée' );
$wpdb->update( $payments_t, [ 'statut_paiement' => 'paiement_recu' ], [ 'payment_token' => $token ] );
assertEq( '', $pay->creer_lignes_panier( $uid ), 'plus rien à payer -> vide' );

// ── Nettoyage ──
$wpdb->delete( $payments_t, [ 'user_id' => $uid ] );
$wpdb->delete( $photos_t,   [ 'user_id' => $uid ] );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $uid );

echo "\n--------------------------------------------\n";
echo "$tests tests · $failures échecs\n";
exit( $failures > 0 ? 1 : 0 );
