# Paiement à l'inscription (avant jury) — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Faire payer chaque photo (frais de participation, prix fixe) avant la date de clôture des dépôts pour qu'elle soit examinée par le jury, avec paiement en panier groupé et un caddy dans le profil ; supprimer le paiement post-jury et le forfait d'inscription.

**Architecture:** La source de vérité « payée » est `wp_pc_payments` (ligne `paiement_recu` par photo). Aucune bascule de statut photo au paiement : la photo reste `en_attente` et devient simplement « payée » (EXISTS). Le jury ne voit que les photos payées. À la clôture jury, `retenue → au_catalogue` (sans 2e paiement). Comparaisons de dates ancrées sur `wp_timezone()` (cf. feature phases).

**Tech Stack:** PHP 8.1+, WordPress, Stripe Checkout (`stripe/stripe-php`), harness de test maison (`tests/test-pc-*.php`), Fluent CRM (dégradation gracieuse).

**Spec :** `docs/superpowers/specs/2026-06-12-paiement-avant-jury-design.md`

**Dépendance :** branche `feature/paiement-avant-jury` partant de `feature/phases-par-dates` (utilise `is_depot_actif()` / `date_fermeture_depot`). À rebaser sur `main` après merge de la PR #2.

---

## Structure des fichiers

| Fichier | Rôle dans ce plan | Tâches |
|---------|-------------------|--------|
| `includes/class-pc-payments.php` | caddy, panier, webhook, clôture, relances, nettoyage | 1,3,5,6,11 |
| `includes/class-pc-database.php` | index `idx_photo_statut` | 1 |
| `includes/class-pc-jury.php` | filtre « payée » | 2 |
| `includes/class-pc-catalogue.php` | alimentation sur `au_catalogue` | 4 |
| `includes/class-pc-settings.php` | réglages relances, retrait email_batch | 6,11 |
| `includes/class-pc-registration.php` | machine à états sans forfait | 7 |
| `includes/class-pc-fluent-crm.php` | retrait des `case` morts | 10 |
| `includes/class-pc-shortcodes.php` | retrait `participation_demandee`, badge data | 9,11 |
| `templates/profile.php` | retrait étape forfait, caddy | 8 |
| `templates/gallery.php` | (badges via JS) | 9 |
| `templates/payment.php` | suppression | 11 |
| `public/js/pc-profile.js` | déclenchement session panier | 8 |
| `public/js/pc-gallery.js` | rendu badge payée | 9 |
| `admin/class-pc-admin.php` | réglages relances, retrait email_batch | 6,11 |
| `admin/class-pc-cloture-admin.php` | récap sans volet financier | 5 |
| `photo-contest.php` | alias statuts morts, hook batch | 11 |
| `tests/test-pc-caddy.php` | nouveau harness | 1,2,3,5 |

---

## Task 1 : Caddy & index (TDD)

**Files:**
- Create: `wp-content/plugins/photo-contest/tests/test-pc-caddy.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-database.php`

- [ ] **Step 1 : Écrire le harness (échoue)**

Créer `wp-content/plugins/photo-contest/tests/test-pc-caddy.php` :

```php
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

// ── Nettoyage ──
$wpdb->delete( $payments_t, [ 'user_id' => $uid ] );
$wpdb->delete( $photos_t,   [ 'user_id' => $uid ] );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $uid );

echo "\n--------------------------------------------\n";
echo "$tests tests · $failures échecs\n";
exit( $failures > 0 ? 1 : 0 );
```

- [ ] **Step 2 : Lancer — échoue**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php`
Expected : FAIL — `Call to undefined method PC_Payments::photo_est_payee()`.

- [ ] **Step 3 : Implémenter les méthodes caddy**

Dans `includes/class-pc-payments.php`, ajouter ces deux méthodes publiques (juste avant `get_cloture_stats()` ou à la fin de la classe) :

```php
    /**
     * Une photo est « payée » s'il existe une ligne paiement_recu pour son id.
     */
    public function photo_est_payee( int $photo_id ): bool {
        global $wpdb;
        $t = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$t} WHERE photo_id = %d AND statut_paiement = 'paiement_recu' LIMIT 1",
            $photo_id
        ) );
    }

    /**
     * Récapitulatif de paiement (« caddy ») pour un candidat, sur ses photos en_attente.
     *
     * @return array{prix_unitaire_cts:int,nb_payees:int,nb_non_payees:int,montant_paye_cts:int,montant_du_cts:int,total_cts:int}
     */
    public function get_caddy( int $user_id ): array {
        global $wpdb;
        $photos_t   = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $payments_t = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $prix       = (int) PC_Settings::get( 'montant_participation_cts', 1500 );

        $nb_payees = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$photos_t} p
             WHERE p.user_id = %d AND p.statut = 'en_attente'
               AND EXISTS ( SELECT 1 FROM {$payments_t} pay
                            WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu' )",
            $user_id
        ) );
        $nb_non_payees = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$photos_t} p
             WHERE p.user_id = %d AND p.statut = 'en_attente'
               AND NOT EXISTS ( SELECT 1 FROM {$payments_t} pay
                                WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu' )",
            $user_id
        ) );

        return [
            'prix_unitaire_cts' => $prix,
            'nb_payees'         => $nb_payees,
            'nb_non_payees'     => $nb_non_payees,
            'montant_paye_cts'  => $nb_payees * $prix,
            'montant_du_cts'    => $nb_non_payees * $prix,
            'total_cts'         => ( $nb_payees + $nb_non_payees ) * $prix,
        ];
    }
```

- [ ] **Step 4 : Ajouter l'index BDD**

Dans `includes/class-pc-database.php`, méthode d'upgrade `maybe_upgrade()` (qui contient déjà l'ajout d'index `idx_payment_token` vers la ligne 404), ajouter après ce bloc :

```php
        if ( ! in_array( 'idx_photo_statut', $existing_indexes, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_photo_statut (photo_id, statut_paiement)" );
        }
```

> Vérifier le nom de la variable `$existing_indexes` dans la méthode et réutiliser le
> même pattern que `idx_payment_token`. Si la liste des index est relue par
> `SHOW INDEX`, s'assurer que la nouvelle clé y est cherchée de la même façon.

- [ ] **Step 5 : Lancer — passe**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php`
Expected : PASS — `XX tests · 0 échecs`.

Run : `wp eval 'PC_Database::maybe_upgrade();'` puis
`wp db query "SHOW INDEX FROM wp_pc_payments WHERE Key_name='idx_photo_statut'"`
Expected : l'index existe.

- [ ] **Step 6 : Commit**

```bash
git add wp-content/plugins/photo-contest/tests/test-pc-caddy.php wp-content/plugins/photo-contest/includes/class-pc-payments.php wp-content/plugins/photo-contest/includes/class-pc-database.php
git commit -m "feat(paiement): helpers caddy (photo_est_payee, get_caddy) + index idx_photo_statut

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2 : Le jury ne voit que les photos payées (TDD)

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-jury.php:25-39`
- Modify: `wp-content/plugins/photo-contest/tests/test-pc-caddy.php` (ajout d'assertions)

- [ ] **Step 1 : Ajouter le test (échoue)**

Dans `tests/test-pc-caddy.php`, avant le bloc « Nettoyage », ajouter :

```php
echo "\n== get_photos_pour_jury exclut les non payées ==\n";
// p2 non payée, p1 & p3 payées (cf. plus haut)
$jury_ids = array_map( fn($r) => (int) $r['id'], PC_Jury::get_instance()->get_photos_pour_jury( $uid ) );
assertTrue(  in_array( $p1, $jury_ids, true ), 'photo payée p1 visible jury' );
assertTrue(  in_array( $p3, $jury_ids, true ), 'photo payée p3 visible jury' );
assertFalse( in_array( $p2, $jury_ids, true ), 'photo non payée p2 exclue du jury' );
```

- [ ] **Step 2 : Lancer — échoue**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php`
Expected : FAIL sur « photo non payée p2 exclue du jury » (p2 encore visible).

- [ ] **Step 3 : Ajouter le filtre EXISTS**

Dans `includes/class-pc-jury.php`, méthode `get_photos_pour_jury()`, modifier le `WHERE`.

Remplacer :

```php
        $cat_where = $category_id > 0 ? 'AND p.category_id = %d' : '';
        $sql = "SELECT p.id, p.largeur_px, p.hauteur_px, p.ratio_type, p.taille_octets, p.statut, p.ordre_affichage,
                       p.category_id, c.nom AS nom_categorie,
                       v.decision AS mon_vote, v.commentaire AS mon_commentaire
                FROM {$tp} p
                LEFT JOIN {$tv} v ON v.photo_id = p.id AND v.jury_user_id = %d
                LEFT JOIN {$tc} c ON c.id = p.category_id
                WHERE p.statut IN ('en_attente','en_examen') {$cat_where}
                ORDER BY p.ordre_affichage ASC";
```

par :

```php
        $tpay      = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $cat_where = $category_id > 0 ? 'AND p.category_id = %d' : '';
        $sql = "SELECT p.id, p.largeur_px, p.hauteur_px, p.ratio_type, p.taille_octets, p.statut, p.ordre_affichage,
                       p.category_id, c.nom AS nom_categorie,
                       v.decision AS mon_vote, v.commentaire AS mon_commentaire
                FROM {$tp} p
                LEFT JOIN {$tv} v ON v.photo_id = p.id AND v.jury_user_id = %d
                LEFT JOIN {$tc} c ON c.id = p.category_id
                WHERE p.statut IN ('en_attente','en_examen')
                  AND EXISTS ( SELECT 1 FROM {$tpay} pay
                               WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu' )
                  {$cat_where}
                ORDER BY p.ordre_affichage ASC";
```

- [ ] **Step 4 : Lancer — passe**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php`
Expected : PASS, 0 échecs.

- [ ] **Step 5 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-jury.php wp-content/plugins/photo-contest/tests/test-pc-caddy.php
git commit -m "feat(paiement): le jury n'examine que les photos payées (EXISTS paiement_recu)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3 : Paiement panier groupé + webhook (TDD)

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php`
- Modify: `wp-content/plugins/photo-contest/tests/test-pc-caddy.php`

- [ ] **Step 1 : Ajouter le test de création des lignes panier (échoue)**

Dans `tests/test-pc-caddy.php`, avant le « Nettoyage », ajouter :

```php
echo "\n== creer_lignes_panier ==\n";
// État courant : p1, p3 payées ; p2 non payée. On ajoute p4, p5 non payées.
$p4 = $mk_photo(); $p5 = $mk_photo();
$token = $pay->creer_lignes_panier( $uid );
assertTrue( is_string( $token ) && strlen( $token ) === 36, 'token UUID renvoyé' );
$lignes = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$payments_t} WHERE payment_token = %s AND statut_paiement = 'en_attente'", $token ) );
assertEq( 3, $lignes, '3 lignes en_attente (p2, p4, p5) sous le token' );
// Aucune ligne pour une photo déjà payée
$dup = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$payments_t} WHERE payment_token = %s AND photo_id = %d", $token, $p1 ) );
assertEq( 0, $dup, 'pas de ligne pour une photo déjà payée' );
// Rien à payer -> chaîne vide
$wpdb->update( $payments_t, [ 'statut_paiement' => 'paiement_recu' ],
    [ 'payment_token' => $token ] );
assertEq( '', $pay->creer_lignes_panier( $uid ), 'plus rien à payer -> vide' );
```

- [ ] **Step 2 : Lancer — échoue**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php`
Expected : FAIL — `Call to undefined method PC_Payments::creer_lignes_panier()`.

- [ ] **Step 3 : Implémenter `creer_lignes_panier()` + l'action AJAX**

Dans `includes/class-pc-payments.php`, ajouter la méthode :

```php
    /**
     * Crée une ligne de paiement `en_attente` (prix unitaire) pour chaque photo en_attente
     * non encore payée du candidat, sous un même payment_token. Renvoie le token, ou ''
     * s'il n'y a rien à payer.
     */
    public function creer_lignes_panier( int $user_id ): string {
        global $wpdb;
        $photos_t   = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $payments_t = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $prix       = (int) PC_Settings::get( 'montant_participation_cts', 1500 );
        $devise     = strtoupper( (string) PC_Settings::get( 'devise', 'EUR' ) );

        $photo_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.id FROM {$photos_t} p
             WHERE p.user_id = %d AND p.statut = 'en_attente'
               AND NOT EXISTS ( SELECT 1 FROM {$payments_t} pay
                                WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu' )",
            $user_id
        ) );
        if ( empty( $photo_ids ) ) {
            return '';
        }

        $token = wp_generate_uuid4();
        foreach ( $photo_ids as $pid ) {
            // Nettoyer une éventuelle ligne en_attente orpheline (panier abandonné)
            $wpdb->delete( $payments_t, [ 'photo_id' => (int) $pid, 'statut_paiement' => 'en_attente' ] );
            $wpdb->insert( $payments_t, [
                'photo_id'         => (int) $pid,
                'user_id'          => $user_id,
                'montant_centimes' => $prix,
                'devise'           => $devise,
                'statut_paiement'  => 'en_attente',
                'methode'          => 'stripe_checkout',
                'payment_token'    => $token,
            ] );
        }
        return $token;
    }
```

Ajouter l'action AJAX dans le constructeur `__construct()` (à côté des autres `wp_ajax_`) :

```php
        add_action( 'wp_ajax_pc_create_caddy_session', [ $this, 'ajax_create_caddy_session' ] );
```

Et la méthode :

```php
    /**
     * AJAX : crée les lignes panier pour le candidat courant et renvoie l'URL
     * de l'endpoint /?pc_pay=<token> (qui ouvrira Stripe Checkout, Elementor-safe).
     */
    public function ajax_create_caddy_session(): void {
        check_ajax_referer( 'pc_profile_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! current_user_can( 'pc_pay_participation' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }
        if ( ! PC_Settings::is_depot_actif() ) {
            wp_send_json_error( [ 'message' => __( 'Le dépôt est clôturé : paiement impossible.', PC_TEXT_DOMAIN ) ] );
        }
        if ( ! $this->is_configured() ) {
            wp_send_json_error( [ 'message' => __( 'Stripe n\'est pas configuré.', PC_TEXT_DOMAIN ) ] );
        }

        $token = $this->creer_lignes_panier( get_current_user_id() );
        if ( $token === '' ) {
            wp_send_json_error( [ 'message' => __( 'Aucune photo à payer.', PC_TEXT_DOMAIN ) ] );
        }

        wp_send_json_success( [ 'url' => home_url( '/?pc_pay=' . rawurlencode( $token ) ) ] );
    }
```

- [ ] **Step 4 : Webhook — ne plus basculer le statut photo**

Dans `on_token_checkout_completed()` (`class-pc-payments.php`), supprimer l'appel
`update_statut`. Remplacer le corps de la boucle `foreach` :

```php
        foreach ( $rows as $row ) {
            $wpdb->update(
                $table,
                [
                    'statut_paiement'   => 'paiement_recu',
                    'reference_externe' => $reference,
                    'methode'           => 'stripe_checkout',
                ],
                [ 'id' => (int) $row->id ]
            );

            // Déclenche Fluent CRM + création entrée catalogue
            $photos->update_statut( (int) $row->photo_id, 'paiement_recu' );
        }
```

par :

```php
        $tag_paye = (int) PC_Settings::get( 'fluent_tag_paye', 0 );
        $first_user = 0;
        foreach ( $rows as $row ) {
            $wpdb->update(
                $table,
                [
                    'statut_paiement'   => 'paiement_recu',
                    'reference_externe' => $reference,
                    'methode'           => 'stripe_checkout',
                ],
                [ 'id' => (int) $row->id ]
            );
            // NE PAS changer le statut jury de la photo : elle reste en_attente, juste « payée ».
            $first_user = (int) $row->user_id;
        }

        // Pose le tag « payé » Fluent CRM une fois pour le candidat (dégradation gracieuse).
        if ( $first_user > 0 && $tag_paye > 0 ) {
            do_action( 'pc_paiement_panier_recu', $first_user, $tag_paye );
        }
```

> Note : `$rows` doit exposer `user_id`. Adapter le `SELECT` de `on_token_checkout_completed`
> en `SELECT id, photo_id, user_id FROM ...`. La variable `$photos` (instance PC_Photos)
> devient inutilisée → la retirer si plus référencée.

- [ ] **Step 5 : Lancer — passe**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php`
Expected : PASS, 0 échecs.
Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-payments.php`
Expected : `No syntax errors detected`.

- [ ] **Step 6 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-payments.php wp-content/plugins/photo-contest/tests/test-pc-caddy.php
git commit -m "feat(paiement): panier groupé (creer_lignes_panier + ajax) ; webhook ne change plus le statut photo

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4 : Alimentation catalogue sur `au_catalogue`

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-catalogue.php:31-33`

- [ ] **Step 1 : Déplacer le déclencheur**

Dans `class-pc-catalogue.php`, remplacer :

```php
    public function on_statut_changed( int $photo_id, string $nouveau, string $ancien ): void {
        if ( $nouveau === 'paiement_recu' ) $this->creer_entree_si_absente( $photo_id );
    }
```

par :

```php
    public function on_statut_changed( int $photo_id, string $nouveau, string $ancien ): void {
        if ( $nouveau === 'au_catalogue' ) $this->creer_entree_si_absente( $photo_id );
    }
```

> `creer_entree_si_absente()` appelle déjà `update_statut( $photo_id, 'au_catalogue' )` en
> fin de méthode : c'est désormais redondant puisque le statut est déjà `au_catalogue`.
> `update_statut` est idempotent (ne fait rien si le statut est inchangé) — vérifier
> dans `PC_Photos::update_statut` qu'un no-op ne re-déclenche pas `pc_photo_statut_changed`
> (sinon retirer cette dernière ligne de `creer_entree_si_absente`).

- [ ] **Step 2 : Vérifier le commentaire de tête**

Mettre à jour le commentaire ligne 7 : `Alimentation auto quand statut → "au_catalogue"`.

- [ ] **Step 3 : Vérifier**

Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-catalogue.php`
Expected : `No syntax errors detected`.

- [ ] **Step 4 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-catalogue.php
git commit -m "feat(paiement): le catalogue s'alimente sur au_catalogue (et non paiement_recu)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5 : Réécriture de la clôture délibération (TDD)

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php` (`execute_cloture`, `get_cloture_stats`)
- Modify: `wp-content/plugins/photo-contest/admin/class-pc-cloture-admin.php` (récap sans financier)
- Modify: `wp-content/plugins/photo-contest/tests/test-pc-caddy.php`

- [ ] **Step 1 : Ajouter le test de clôture (échoue)**

Dans `tests/test-pc-caddy.php`, avant le « Nettoyage », ajouter :

```php
echo "\n== execute_cloture : retenue -> au_catalogue, restantes -> refusee, aucun paiement créé ==\n";
// Prépare : p4 retenue, p5 reste en_attente (payées toutes deux pour le réalisme)
$mk_paid( $p4 ); $mk_paid( $p5 );
$wpdb->update( $photos_t, [ 'statut' => 'retenue' ],    [ 'id' => $p4 ] );
$wpdb->update( $photos_t, [ 'statut' => 'en_attente' ], [ 'id' => $p5 ] );
PC_Settings::set( 'cloture_effectuee_at', 0 );
PC_Settings::set( 'cloture_en_cours', 0 );
$paiements_avant = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$payments_t}" );

PC_Payments::execute_cloture();

assertEq( 'au_catalogue', $wpdb->get_var( $wpdb->prepare( "SELECT statut FROM {$photos_t} WHERE id=%d", $p4 ) ), 'retenue -> au_catalogue' );
assertEq( 'refusee',      $wpdb->get_var( $wpdb->prepare( "SELECT statut FROM {$photos_t} WHERE id=%d", $p5 ) ), 'en_attente restante -> refusee' );
$paiements_apres = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$payments_t}" );
assertEq( $paiements_avant, $paiements_apres, 'aucune ligne de paiement créée à la clôture' );
```

> Le test manipule plusieurs photos partagées avec les blocs précédents : le placer
> APRÈS tous les autres blocs (juste avant le nettoyage). Réinitialiser `cloture_effectuee_at`
> à 0 en fin de test pour ne pas polluer l'install : `PC_Settings::set('cloture_effectuee_at',0);`.

- [ ] **Step 2 : Lancer — échoue**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php`
Expected : FAIL (l'ancienne `execute_cloture` bascule en `participation_demandee`, crée des paiements).

- [ ] **Step 3 : Réécrire `execute_cloture()`**

Dans `class-pc-payments.php`, remplacer toute la méthode `execute_cloture()` par :

```php
    /**
     * Clôture de la délibération du jury (modèle paiement-avant-jury).
     *
     * 1. Verrou anti-double-clic (cloture_en_cours)
     * 2. Photos payées encore en délibération (en_attente/en_examen) -> refusee
     * 3. Photos retenue -> au_catalogue (déclenche l'alimentation catalogue)
     * 4. Flags : jury_actif=false, catalogue_actif=true, cloture_effectuee_at=time()
     * 5. do_action( 'pc_cloture_jury_effectuee' )
     *
     * Aucune création de paiement (le paiement a lieu avant le jury).
     *
     * @return array{refused_count?:int, au_catalogue_count?:int, error?:string}
     */
    public static function execute_cloture(): array {
        global $wpdb;
        $photos_t = PC_Database::table( PC_Database::TABLE_PHOTOS );

        // 1. Verrou
        $lock_at = (int) PC_Settings::get( 'cloture_en_cours', 0 );
        if ( $lock_at && ( time() - $lock_at ) < 300 ) {
            return [ 'error' => 'cloture_en_cours' ];
        }
        PC_Settings::set( 'cloture_en_cours', time() );

        // 2. Refus des photos non départagées
        $refused = (int) $wpdb->query(
            "UPDATE {$photos_t} SET statut = 'refusee' WHERE statut IN ('en_attente','en_examen')"
        );

        // 3. retenue -> au_catalogue (via update_statut pour déclencher l'alimentation catalogue)
        $retenue_ids = $wpdb->get_col( "SELECT id FROM {$photos_t} WHERE statut = 'retenue'" );
        $photos = PC_Photos::get_instance();
        foreach ( $retenue_ids as $pid ) {
            $photos->update_statut( (int) $pid, 'au_catalogue' );
        }

        // 4. Flags
        PC_Settings::set( 'jury_actif',           false );
        PC_Settings::set( 'catalogue_actif',      true );
        PC_Settings::set( 'cloture_en_cours',     0 );
        PC_Settings::set( 'cloture_effectuee_at', time() );

        // 5. Trigger
        do_action( 'pc_cloture_jury_effectuee' );

        return [
            'refused_count'     => $refused,
            'au_catalogue_count'=> count( $retenue_ids ),
        ];
    }
```

- [ ] **Step 4 : Adapter `get_cloture_stats()`**

Remplacer `get_cloture_stats()` par (sans volet financier) :

```php
    public static function get_cloture_stats(): array {
        global $wpdb;
        $photos = PC_Database::table( PC_Database::TABLE_PHOTOS );

        return [
            'en_delibration_a_refuser' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$photos} WHERE statut IN ('en_attente','en_examen')" ),
            'photos_retenues'          => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$photos} WHERE statut = 'retenue'" ),
        ];
    }
```

- [ ] **Step 5 : Adapter la page admin Clôture**

Dans `admin/class-pc-cloture-admin.php`, méthode `render_page()` : retirer les lignes du
récap financier (« Montant unitaire », « Total à encaisser », « Candidats à notifier »)
et la puce « recevront un email de paiement ». Remplacer le tableau récap par :

```php
                <table class="widefat striped" style="max-width:600px">
                    <tr>
                        <th><?php esc_html_e( 'Photos en délibération à refuser', PC_TEXT_DOMAIN ); ?></th>
                        <td><?php echo (int) $stats['en_delibration_a_refuser']; ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Photos retenues → catalogue', PC_TEXT_DOMAIN ); ?></th>
                        <td><?php echo (int) $stats['photos_retenues']; ?></td>
                    </tr>
                </table>
```

Et la liste d'avertissement (`pc-cloture-warning`) :

```php
                <ul>
                    <li><?php esc_html_e( 'Toutes les photos en délibération basculeront en « refusée ».', PC_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Les photos retenues passeront « au catalogue ».', PC_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Le flag jury_actif sera mis à 0 (votes verrouillés).', PC_TEXT_DOMAIN ); ?></li>
                </ul>
```

Retirer la variable `$fmt_eur` devenue inutile.

- [ ] **Step 6 : Lancer + lint**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php` → PASS.
Run : `php -l wp-content/plugins/photo-contest/admin/class-pc-cloture-admin.php` → OK.

- [ ] **Step 7 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-payments.php wp-content/plugins/photo-contest/admin/class-pc-cloture-admin.php wp-content/plugins/photo-contest/tests/test-pc-caddy.php
git commit -m "feat(paiement): clôture sans paiement, retenue -> au_catalogue ; récap admin sans volet financier

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6 : Relances J-10 / J-5 (TDD partiel)

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-settings.php` (`$defaults`)
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php` (`cron_relances_quotidiennes`)
- Modify: `wp-content/plugins/photo-contest/admin/class-pc-admin.php` (champs relances)
- Modify: `wp-content/plugins/photo-contest/tests/test-pc-caddy.php`

- [ ] **Step 1 : Réglages**

Dans `class-pc-settings.php`, dans `$defaults`, remplacer :

```php
        'relance_jours'                => 5,
        'relance_max'                  => 2,
```

par :

```php
        'relance_offset_1'             => 10,   // 1re relance : J-10 avant clôture dépôt (0 = off)
        'relance_offset_2'             => 5,    // 2e relance  : J-5 avant clôture dépôt (0 = off)
```

- [ ] **Step 2 : Ajouter le helper de ciblage + test (échoue)**

Dans `tests/test-pc-caddy.php`, avant « Nettoyage », ajouter :

```php
echo "\n== candidats_avec_photos_non_payees ==\n";
// Reconstituer un état simple : nouvelle photo non payée pour $uid
$p6 = $mk_photo();
$cibles = PC_Payments::get_instance()->candidats_avec_photos_non_payees();
assertTrue( in_array( $uid, array_map( 'intval', $cibles ), true ), 'candidat avec photo non payée ciblé' );
```

Implémenter dans `class-pc-payments.php` :

```php
    /**
     * Liste des user_id ayant au moins une photo en_attente non payée.
     * Sert au ciblage des relances avant clôture.
     *
     * @return int[]
     */
    public function candidats_avec_photos_non_payees(): array {
        global $wpdb;
        $photos_t   = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $payments_t = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        return array_map( 'intval', $wpdb->get_col(
            "SELECT DISTINCT p.user_id FROM {$photos_t} p
             WHERE p.statut = 'en_attente'
               AND NOT EXISTS ( SELECT 1 FROM {$payments_t} pay
                                WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu' )"
        ) );
    }
```

- [ ] **Step 3 : Lancer — passe le helper**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php` → PASS.

- [ ] **Step 4 : Réécrire `cron_relances_quotidiennes()`**

Remplacer toute la méthode `cron_relances_quotidiennes()` par :

```php
    /**
     * Cron quotidien : relances de paiement avant la clôture des dépôts.
     *
     * Envoie une relance aux candidats ayant ≥1 photo non payée, lorsqu'il reste
     * exactement relance_offset_1 (J-10) ou relance_offset_2 (J-5) jours avant
     * date_fermeture_depot. Calcul des jours côté PHP, ancré sur wp_timezone().
     * Anti-doublon : option par offset et par date de clôture.
     */
    public function cron_relances_quotidiennes(): void {
        $fermeture = (string) PC_Settings::get( 'date_fermeture_depot', '' );
        if ( $fermeture === '' ) {
            return;
        }
        try {
            $tz      = wp_timezone();
            $end     = ( new DateTimeImmutable( $fermeture, $tz ) )->setTime( 0, 0 );
            $today   = ( new DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 );
        } catch ( Exception $e ) {
            return;
        }
        $jours_restants = (int) $today->diff( $end )->format( '%r%a' );
        if ( $jours_restants < 0 ) {
            return; // clôture passée
        }

        $offsets = [
            1 => (int) PC_Settings::get( 'relance_offset_1', 10 ),
            2 => (int) PC_Settings::get( 'relance_offset_2', 5 ),
        ];

        foreach ( $offsets as $rang => $offset ) {
            if ( $offset <= 0 || $jours_restants !== $offset ) {
                continue;
            }
            $flag = 'pc_relance_sent_' . $rang . '_' . $end->format( 'Ymd' );
            if ( get_option( $flag ) ) {
                continue; // déjà envoyée pour cette édition
            }

            $prix = (int) PC_Settings::get( 'montant_participation_cts', 1500 );
            $profil_url = get_permalink( get_option( 'pc_page_profil' ) ) ?: home_url( '/' );

            foreach ( $this->candidats_avec_photos_non_payees() as $user_id ) {
                $caddy = $this->get_caddy( $user_id );
                if ( $caddy['nb_non_payees'] <= 0 ) {
                    continue;
                }
                // Hook consommé par PC_Fluent_CRM. Dégradation gracieuse si absent.
                do_action( 'pc_relance_paiement', $user_id, [
                    'nb_non_payees'  => $caddy['nb_non_payees'],
                    'montant_du_cts' => $caddy['montant_du_cts'],
                    'date_cloture'   => $end->format( 'Y-m-d' ),
                    'profil_url'     => $profil_url,
                    'rang'           => $rang,
                ] );
            }

            update_option( $flag, time(), false );
        }
    }
```

- [ ] **Step 5 : Champs admin relances**

Dans `admin/class-pc-admin.php`, section « Relances impayés (Phase 2) », remplacer les
deux champs `relance_jours` / `relance_max` par `relance_offset_1` / `relance_offset_2` :

```php
            <tr>
                <th><label for="relance_offset_1"><?php esc_html_e( '1re relance (jours avant clôture)', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="number" min="0" max="60" id="relance_offset_1" name="pc_settings[relance_offset_1]"
                           value="<?php echo esc_attr( $s['relance_offset_1'] ?? 10 ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( '0 = désactivée. Défaut : 10 (J-10).', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="relance_offset_2"><?php esc_html_e( '2e relance (jours avant clôture)', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="number" min="0" max="60" id="relance_offset_2" name="pc_settings[relance_offset_2]"
                           value="<?php echo esc_attr( $s['relance_offset_2'] ?? 5 ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( '0 = désactivée. Défaut : 5 (J-5).', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
```

Dans `save_settings()`, remplacer les bornes :

```php
        $clean['relance_jours']                = max( 0, min( 30,  (int) ( $data['relance_jours'] ?? 5 ) ) );
        $clean['relance_max']                  = max( 0, min( 5,   (int) ( $data['relance_max'] ?? 2 ) ) );
```

par :

```php
        $clean['relance_offset_1']             = max( 0, min( 60, (int) ( $data['relance_offset_1'] ?? 10 ) ) );
        $clean['relance_offset_2']             = max( 0, min( 60, (int) ( $data['relance_offset_2'] ?? 5 ) ) );
```

- [ ] **Step 6 : Lancer + lint**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php` → PASS.
Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-payments.php` → OK.
Run : `php -l wp-content/plugins/photo-contest/admin/class-pc-admin.php` → OK.

- [ ] **Step 7 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-settings.php wp-content/plugins/photo-contest/includes/class-pc-payments.php wp-content/plugins/photo-contest/admin/class-pc-admin.php wp-content/plugins/photo-contest/tests/test-pc-caddy.php
git commit -m "feat(paiement): relances J-10/J-5 avant clôture dépôt (réglages relance_offset_1/2)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7 : Onboarding sans forfait d'inscription

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-registration.php:247-271`

- [ ] **Step 1 : Simplifier la machine à états**

Dans `class-pc-registration.php`, `get_etape()`, retirer l'étape forfait. Remplacer :

```php
        if ( ! $profile )                              return 'email_non_verifie';
        if ( ! $profile['email_verifie'] )             return 'email_non_verifie';
        if ( ! $profile['profil_complet'] )            return 'profil_incomplet';
        if ( ! $profile['reglement_accepte'] )         return 'reglement_non_accepte';
        if ( ! $profile['paiement_inscription_recu'] ) return 'paiement_requis';
        return 'complet';
```

par :

```php
        if ( ! $profile )                      return 'email_non_verifie';
        if ( ! $profile['email_verifie'] )     return 'email_non_verifie';
        if ( ! $profile['profil_complet'] )    return 'profil_incomplet';
        if ( ! $profile['reglement_accepte'] ) return 'reglement_non_accepte';
        return 'complet';
```

`peut_deposer()` reste `=== 'complet'` (désormais = règlement accepté).

- [ ] **Step 2 : Retirer le handler de paiement inscription**

Supprimer la méthode `on_inscription_paiement_recu()` et, dans le constructeur, la ligne :

```php
        add_action( 'pc_inscription_paiement_recu', [ $this, 'on_inscription_paiement_recu' ] );
```

- [ ] **Step 3 : Retirer la branche inscription du webhook**

Dans `class-pc-payments.php`, `on_checkout_completed()`, supprimer le bloc :

```php
        // Paiement d'inscription (avant dépôt photos)
        if ( $type === 'inscription' ) {
            do_action( 'pc_inscription_paiement_recu', $user_id );
            return;
        }
```

- [ ] **Step 4 : Vérifier**

Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-registration.php` → OK.
Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-payments.php` → OK.
Run : `wp eval 'echo PC_Registration::get_etape( 0 );'`
Expected : pas d'erreur (renvoie un état valide).

- [ ] **Step 5 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-registration.php wp-content/plugins/photo-contest/includes/class-pc-payments.php
git commit -m "feat(paiement): suppression du forfait d'inscription (dépôt libre après règlement)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8 : Profil — caddy à la place de l'étape forfait

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php` (`render_profile`, passer le caddy au template)
- Modify: `wp-content/plugins/photo-contest/templates/profile.php`
- Modify: `wp-content/plugins/photo-contest/public/js/pc-profile.js`

- [ ] **Step 1 : Passer le caddy au template**

Dans `class-pc-shortcodes.php`, `render_profile()` (les DEUX branches : admin ≈ ligne 105
et candidat ≈ ligne 134), après le calcul de `$etape`, ajouter :

```php
        $caddy = PC_Payments::get_instance()->get_caddy( $user_id );
        $depot_actif_now = PC_Settings::is_depot_actif();
```

et inclure ces variables dans le `compact()`/`extract()` qui alimente `profile.php`
(repérer comment les variables sont transmises au template ; suivre le même mécanisme que
`$etape`, `$montant`). Ajouter au `wp_localize_script( 'pc-profile', 'pcProfileConfig', ... )`
la clé nonce déjà présente `nonceSave => wp_create_nonce('pc_profile_nonce')` (réutilisée
par l'action `pc_create_caddy_session`).

- [ ] **Step 2 : Indicateur d'étapes à 2 entrées**

Dans `templates/profile.php`, remplacer le tableau `$etapes` :

```php
    $etapes = [
      ['profil',    __( 'Profil', PC_TEXT_DOMAIN )],
      ['reglement', __( 'Règlement', PC_TEXT_DOMAIN )],
      ['paiement',  __( 'Participation', PC_TEXT_DOMAIN )],
    ];
    $ordre = ['email_non_verifie'=>0,'profil_incomplet'=>1,'reglement_non_accepte'=>2,'paiement_requis'=>3,'complet'=>4];
```

par :

```php
    $etapes = [
      ['profil',    __( 'Profil', PC_TEXT_DOMAIN )],
      ['reglement', __( 'Règlement', PC_TEXT_DOMAIN )],
    ];
    $ordre = ['email_non_verifie'=>0,'profil_incomplet'=>1,'reglement_non_accepte'=>2,'complet'=>3];
```

- [ ] **Step 3 : Remplacer la section forfait par le caddy**

Dans `templates/profile.php`, remplacer toute la section « Étape 3 : Paiement inscription »
(le bloc `<?php if ( in_array( $etape, [ 'paiement_requis', 'complet' ], true ) ) : ?> …
</section> <?php endif; ?>`, lignes ~193-225) par :

```php
    <!-- ── Caddy : paiement des photos ──────────────────────────────── -->
    <?php if ( $etape === 'complet' ) :
      $prix_fmt = number_format( $caddy['prix_unitaire_cts'] / 100, 2, ',', ' ' ) . ' €';
      $du_fmt   = number_format( $caddy['montant_du_cts']    / 100, 2, ',', ' ' ) . ' €';
      $paye_fmt = number_format( $caddy['montant_paye_cts']  / 100, 2, ',', ' ' ) . ' €';
    ?>
    <section class="pcp-section" id="pcp-section-caddy">
      <h2 class="pcp-section__titre"><?php esc_html_e( 'Paiement de mes photos', PC_TEXT_DOMAIN ); ?></h2>
      <p class="pcp-paiement-desc">
        <?php printf(
          esc_html__( 'Chaque photo coûte %s. Une photo doit être payée avant la clôture des dépôts pour être examinée par le jury.', PC_TEXT_DOMAIN ),
          '<strong>' . esc_html( $prix_fmt ) . '</strong>'
        ); ?>
      </p>
      <table class="pcp-caddy">
        <tr><th><?php esc_html_e( 'Photos payées', PC_TEXT_DOMAIN ); ?></th>
            <td><?php echo (int) $caddy['nb_payees']; ?> — <?php echo esc_html( $paye_fmt ); ?></td></tr>
        <tr><th><?php esc_html_e( 'Reste à payer', PC_TEXT_DOMAIN ); ?></th>
            <td><strong><?php echo (int) $caddy['nb_non_payees']; ?> — <?php echo esc_html( $du_fmt ); ?></strong></td></tr>
      </table>

      <div id="pcp-caddy-msg"></div>

      <?php if ( ! $depot_actif_now ) : ?>
        <div class="pcp-notice pcp-notice--info">
          <?php esc_html_e( 'Le dépôt est clôturé : le paiement n\'est plus possible.', PC_TEXT_DOMAIN ); ?>
        </div>
      <?php elseif ( $caddy['nb_non_payees'] > 0 ) : ?>
        <button class="pcp-btn pcp-btn--gold" id="pcp-btn-caddy">
          <span class="pcp-btn__txt"><?php printf( esc_html__( 'Payer mes %1$d photo(s) — %2$s', PC_TEXT_DOMAIN ), (int) $caddy['nb_non_payees'], esc_html( $du_fmt ) ); ?></span>
          <span class="pcp-btn__spinner"></span>
        </button>
      <?php else : ?>
        <div class="pcp-notice pcp-notice--succes">
          <?php esc_html_e( 'Toutes vos photos déposées sont payées.', PC_TEXT_DOMAIN ); ?>
        </div>
      <?php endif; ?>

      <a href="<?php echo esc_url( $espace_url ); ?>" class="pcp-btn pcp-btn--outline" style="margin-top:14px;display:inline-block;">
        <?php esc_html_e( 'Accéder à mon espace galerie →', PC_TEXT_DOMAIN ); ?>
      </a>
    </section>
    <?php endif; ?>
```

- [ ] **Step 4 : JS — déclencher la session panier**

Dans `public/js/pc-profile.js`, repérer le handler du bouton d'inscription
(`#pcp-btn-paiement`, action `pc_create_inscription_session`). Le remplacer par un handler
sur `#pcp-btn-caddy` appelant l'action `pc_create_caddy_session` :

```js
  var btnCaddy = document.getElementById('pcp-btn-caddy');
  if (btnCaddy) {
    btnCaddy.addEventListener('click', function () {
      btnCaddy.disabled = true;
      var body = new URLSearchParams();
      body.append('action', 'pc_create_caddy_session');
      body.append('nonce', pcProfileConfig.nonceSave);
      fetch(pcProfileConfig.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.success && res.data && res.data.url) {
            window.location.href = res.data.url;
          } else {
            btnCaddy.disabled = false;
            var msg = document.getElementById('pcp-caddy-msg');
            if (msg) { msg.textContent = (res && res.data && res.data.message) ? res.data.message : 'Erreur'; }
          }
        })
        .catch(function () { btnCaddy.disabled = false; });
    });
  }
```

> Conserver le reste de `pc-profile.js` (profil, règlement). Retirer uniquement l'ancien
> handler `#pcp-btn-paiement` / `pc_create_inscription_session`.

- [ ] **Step 5 : Vérification manuelle**

1. `php -l` sur `class-pc-shortcodes.php` → OK.
2. Se connecter comme candidat règlement accepté, ouvrir `/mon-profil/` : la section
   « Paiement de mes photos » affiche le caddy ; le bouton « Payer mes N photos » apparaît
   si des photos non payées existent et que le dépôt est actif.
3. Clic → redirection Stripe Checkout (mode test).

- [ ] **Step 6 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php wp-content/plugins/photo-contest/templates/profile.php wp-content/plugins/photo-contest/public/js/pc-profile.js
git commit -m "feat(paiement): caddy au profil (remplace l'étape forfait) + déclenchement session panier

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 9 : Badges « payée / non payée » dans la galerie

**Files:**
- Modify: le fournisseur de données photos de la galerie (endpoint AJAX qui alimente `#pc-gallery-sections`)
- Modify: `wp-content/plugins/photo-contest/public/js/pc-gallery.js`

- [ ] **Step 1 : Localiser le fournisseur de données**

Run : `grep -rn "url_thumb\|ordre_affichage\|wp_send_json" wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php wp-content/plugins/photo-contest/includes/class-pc-photos.php`
Identifier la méthode qui construit le tableau des photos du candidat envoyé au JS (champ
`url_thumb`, `statut`, `category_id`, etc.).

- [ ] **Step 2 : Ajouter le flag `paye` à chaque photo**

Dans cette méthode, pour chaque photo du tableau de sortie, ajouter le champ :

```php
            'paye' => PC_Payments::get_instance()->photo_est_payee( (int) $photo_id ),
```

(adapter `$photo_id` au nom réel de la variable d'itération). Pour éviter N requêtes,
si la liste est volumineuse, précharger les ids payés en un seul SELECT :

```php
        // En tête de la méthode, après avoir la liste des photos du candidat :
        $payes = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pay.photo_id FROM " . PC_Database::table( PC_Database::TABLE_PAYMENTS ) . " pay
             JOIN " . PC_Database::table( PC_Database::TABLE_PHOTOS ) . " p ON p.id = pay.photo_id
             WHERE p.user_id = %d AND pay.statut_paiement = 'paiement_recu'",
            $user_id
        ) ) );
        // puis par photo : 'paye' => in_array( (int) $photo_id, $payes, true ),
```

- [ ] **Step 3 : Afficher le badge en JS**

Dans `public/js/pc-gallery.js`, là où chaque carte photo est construite (rendu d'une
vignette), ajouter un badge selon `photo.paye` et l'état du dépôt. Exposer d'abord l'état
du dépôt au JS : dans la localisation du script galerie (config JS de la galerie), ajouter
`depotActif: PC_Settings::is_depot_actif()` si absent (vérifier — `depotActif` est déjà
exposé via `pc-gallery` localize d'après `class-pc-shortcodes.php:71`).

Code du badge (adapter au gabarit de carte existant) :

```js
    var badge = document.createElement('span');
    badge.className = 'pc-pay-badge ' + (photo.paye ? 'pc-pay-badge--ok' : 'pc-pay-badge--ko');
    if (photo.paye) {
      badge.textContent = 'payée';
    } else {
      badge.textContent = pcGalleryConfig.depotActif ? 'non payée' : 'non payée — non examinée';
    }
    card.appendChild(badge); // 'card' = élément racine de la vignette
```

- [ ] **Step 4 : Style minimal du badge**

Dans `public/css/pc-gallery.css`, ajouter :

```css
.pc-pay-badge { position:absolute; top:6px; left:6px; font-size:10px; font-weight:600;
  padding:2px 6px; border-radius:3px; text-transform:uppercase; letter-spacing:.04em; }
.pc-pay-badge--ok { background:#1f7a34; color:#fff; }
.pc-pay-badge--ko { background:#8a1f1f; color:#fff; }
```

- [ ] **Step 5 : Vérification manuelle**

Galerie candidat : une photo payée porte le badge vert « payée », une non payée le badge
rouge ; après clôture du dépôt, le badge non payé indique « non payée — non examinée ».

- [ ] **Step 6 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes wp-content/plugins/photo-contest/public/js/pc-gallery.js wp-content/plugins/photo-contest/public/css/pc-gallery.css
git commit -m "feat(paiement): badges payée / non payée — non examinée dans la galerie

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 10 : Nettoyage Fluent CRM

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-fluent-crm.php`

- [ ] **Step 1 : Retirer les `case` morts**

Dans `on_statut_change()`, supprimer les `case 'participation_demandee':` et
`case 'paiement_recu':` (lignes ~167-184). Conserver `retenue`, `refusee`, `au_catalogue`.

- [ ] **Step 2 : Brancher le tag « payé » sur le nouveau hook**

Ajouter, dans le constructeur (`__construct`), l'écoute du hook posé par le webhook panier
(Task 3) :

```php
        add_action( 'pc_paiement_panier_recu', [ $this, 'on_paiement_panier_recu' ], 10, 2 );
```

et la méthode :

```php
    /**
     * Webhook panier : pose le tag « payé » Fluent CRM pour le candidat.
     */
    public function on_paiement_panier_recu( int $user_id, int $tag_id ): void {
        if ( $tag_id > 0 ) {
            $this->add_tag( $user_id, $tag_id );
        }
        $this->fire_automation( $user_id, 'pc_paiement_confirme', [] );
    }
```

> Vérifier la signature réelle de `add_tag()` et `fire_automation()` dans la classe et
> respecter leur dégradation gracieuse (Fluent CRM absent → no-op).

- [ ] **Step 3 : Vérifier**

Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-fluent-crm.php` → OK.
Run : `grep -n "participation_demandee\|case 'paiement_recu'" wp-content/plugins/photo-contest/includes/class-pc-fluent-crm.php`
Expected : aucune occurrence.

- [ ] **Step 4 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-fluent-crm.php
git commit -m "chore(paiement): retire les cas Fluent CRM morts, branche le tag payé sur le panier

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 11 : Suppression du code legacy

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-settings.php`
- Modify: `wp-content/plugins/photo-contest/admin/class-pc-admin.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php`
- Modify: `wp-content/plugins/photo-contest/photo-contest.php`
- Delete: `wp-content/plugins/photo-contest/templates/payment.php`

- [ ] **Step 1 : Supprimer les méthodes/handlers de paiement legacy**

Dans `class-pc-payments.php`, supprimer :
- `creer_session_inscription()` et `ajax_create_inscription_session()` + leur `add_action( 'wp_ajax_pc_create_inscription_session', ... )`.
- `ajax_create_session()` + son `add_action( 'wp_ajax_pc_create_checkout_session', ... )`.
- `cron_send_batch()` + son `add_action( 'pc_send_payment_email_batch', ... )` et les
  re-planifications `wp_schedule_single_event( ..., 'pc_send_payment_email_batch' )`.
- La branche mono-photo « Paiement impression (conservé pour compatibilité) » dans
  `on_checkout_completed()` (lignes ~477-491) — n'est plus utilisée.

- [ ] **Step 2 : Réglages email_batch**

Dans `class-pc-settings.php`, supprimer de `$defaults` :

```php
        'email_batch_size'             => 20,
        'email_batch_interval_minutes' => 5,
```

Dans `admin/class-pc-admin.php`, supprimer la section « Envoi d'emails (Phase 2) »
(champs `email_batch_size` / `email_batch_interval_minutes`) et, dans `save_settings()`,
les deux lignes `$clean['email_batch_size'] = ...` et
`$clean['email_batch_interval_minutes'] = ...`.

- [ ] **Step 3 : Statut `participation_demandee` côté gallery/shortcodes**

Dans `class-pc-shortcodes.php:213`, retirer le bloc
`if ( $photo['statut'] === 'participation_demandee' ) { … }` (afficher un bouton de
paiement post-jury). Vérifier le contexte et retirer le code mort sans casser l'affichage
de la liste candidat.

- [ ] **Step 4 : Alias statuts morts + hook batch dans le bootstrap**

Dans `photo-contest.php` :
- Dans `PC_STATUTS_PHOTO`, retirer les deux alias internes morts :
  ```php
      'participation_demandee' => 'Retenue',      // alias interne — même affichage que retenue
      'paiement_recu'          => 'Au catalogue', // alias interne
  ```
- Conserver `wp_clear_scheduled_hook( 'pc_send_payment_email_batch' )` à la désactivation
  (nettoie un éventuel cron résiduel) — laisser tel quel.

- [ ] **Step 5 : Supprimer le template obsolète**

```bash
git rm wp-content/plugins/photo-contest/templates/payment.php
```

> Avant suppression : `grep -rn "templates/payment.php\|payment.php" wp-content/plugins/photo-contest`
> pour confirmer qu'aucun `include`/`require` ne le référence (l'ancien flux passait par
> `handle_payment_page()` — vérifier que cette méthode est aussi retirée si elle n'inclut
> plus que payment.php ; sinon adapter).

- [ ] **Step 6 : Audit final + lint**

Run : `grep -rn "participation_demandee\|creer_session_inscription\|ajax_create_session\|pc_send_payment_email_batch\|email_batch\|cron_send_batch\|pc_create_inscription_session" wp-content/plugins/photo-contest --include=*.php`
Expected : plus aucune occurrence active (hors `wp_clear_scheduled_hook` de désactivation
et l'ENUM `paiement_recu` de la table paiements qui, lui, reste).

Run : `for f in includes/class-pc-payments.php includes/class-pc-settings.php admin/class-pc-admin.php includes/class-pc-shortcodes.php photo-contest.php; do php -l "wp-content/plugins/photo-contest/$f"; done`
Expected : `No syntax errors detected` partout.

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php` → PASS.

- [ ] **Step 7 : Commit**

```bash
git add -A wp-content/plugins/photo-contest
git commit -m "chore(paiement): suppression du code de paiement post-jury legacy (sessions, batch, payment.php)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 12 : Vérification d'intégration

**Files:** aucune modification (vérification).

- [ ] **Step 1 : Suite de tests + lint global**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-caddy.php` → PASS.
Run : `php wp-content/plugins/photo-contest/tests/test-pc-phases.php` → PASS (non-régression).
Run : `php wp-content/plugins/photo-contest/tests/test-pc-categories.php` → PASS.

- [ ] **Step 2 : Parcours manuel (mode test Stripe)**

Scénario, via le subagent `test-runner` si possible :
1. Candidat : règlement accepté → accès galerie (sans forfait).
2. Dépôt de 2 photos → caddy profil affiche « 2 à payer ».
3. « Payer mes 2 photos » → Stripe Checkout test → retour → caddy « 0 à payer », galerie 2 badges « payée ».
4. Forcer la clôture du dépôt (date passée) → 3e photo non payée affichée « non payée — non examinée ».
5. Activer jury (forçage) → le jury ne voit que les 2 payées.
6. Retenir 1 photo, clôturer la délibération → photo retenue « au catalogue », l'autre « refusée », aucune ligne de paiement créée.
7. Vérifier l'entrée catalogue créée pour la photo « au catalogue ».

- [ ] **Step 3 : Webhook Stripe live (manuel)**

Vérifier la réception de `checkout.session.completed` (token panier) → toutes les lignes du
token passent `paiement_recu`, sans changement de statut photo. Cf. mémoire « tests Stripe
live à faire ».

---

## Auto-revue du plan (effectuée)

**Couverture de la spec :**
- Source de vérité EXISTS + index → Task 1 ✓
- Caddy (`get_caddy`, `photo_est_payee`) → Task 1 ✓
- Jury payées uniquement → Task 2 ✓
- Panier groupé + webhook sans bascule statut → Task 3 ✓
- Catalogue alimenté sur au_catalogue → Task 4 ✓
- Clôture sans paiement, retenue→au_catalogue, stats/admin → Task 5 ✓
- Relances J-10/J-5 (réglages offsets, ciblage, anti-doublon, wp_timezone) → Task 6 ✓
- Suppression forfait + machine à états + webhook inscription → Task 7 ✓
- Caddy profil + JS → Task 8 ✓
- Badges galerie (data + JS + CSS) → Task 9 ✓
- Fluent CRM nettoyage + tag payé sur panier → Task 10 ✓
- Suppression legacy (sessions, batch, email_batch, payment.php, alias morts) → Task 11 ✓
- Vérification + Stripe live → Task 12 ✓

**Placeholders :** les tâches 8/9/11 contiennent des instructions d'ancrage (« repérer »,
« adapter ») là où le code cible est généré par du JS ou n'a pas pu être lu intégralement
au moment de la rédaction ; chaque cas fournit le contrat exact (nom de champ, signature,
hook) et une commande `grep` de localisation. Aucun « TODO » fonctionnel laissé.

**Cohérence des types :** `photo_est_payee(int):bool`, `get_caddy(int):array{...}`,
`creer_lignes_panier(int):string`, `candidats_avec_photos_non_payees():int[]`,
`execute_cloture():array`, hooks `pc_paiement_panier_recu(user_id,tag_id)` /
`pc_relance_paiement(user_id, array)` — noms et signatures cohérents entre Task 1, 3, 5, 6,
8, 10.

**Dépendances inter-tâches :** 1 → (2,3) ; 3 → (8 webhook tag, 10) ; 5 dépend de 4
(au_catalogue) ; 7 → 8 (machine à états). Ordre du plan respecté.
