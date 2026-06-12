# Pilotage des phases du concours par dates — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Piloter l'ouverture des phases du concours par dates — clôture du dépôt → ouverture auto du jury, clôture manuelle de la délibération → ouverture auto du catalogue — avec un forçage manuel prioritaire pour les tests.

**Architecture:** Toute la logique de phase est centralisée dans `PC_Settings` sous forme de méthodes calculées à la volée (`is_depot_actif()`, `is_jury_actif()`), sans WP-Cron. Les comparaisons de dates sont ancrées sur `wp_timezone()` (jamais l'horloge serveur), pour un comportement identique sur Laragon (dev) et O2Switch (prod). L'admin expose deux champs `datetime-local`. La clôture manuelle existante pose en plus `catalogue_actif=true`.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, harness de test maison (pas de PHPUnit) calqué sur `tests/test-pc-categories.php`.

**Spec de référence :** `docs/superpowers/specs/2026-06-12-phases-par-dates-design.md`

---

## Structure des fichiers

| Fichier | Responsabilité | Action |
|---------|----------------|--------|
| `includes/class-pc-settings.php` | Logique de phase + parsing des dates ancré sur `wp_timezone()` | Modifier |
| `tests/test-pc-phases.php` | Harness de test de la logique de phase | Créer |
| `includes/class-pc-shortcodes.php` | Gate de la phase jury (lecture) | Modifier (1 ligne) |
| `includes/class-pc-payments.php` | Clôture manuelle → ouverture catalogue | Modifier (1 ligne + commentaire) |
| `admin/class-pc-admin.php` | Champs calendrier + libellé jury + normalisation à la sauvegarde | Modifier |

---

## Task 1 : Logique de phase dans `PC_Settings` (TDD)

C'est le cœur de la fonctionnalité. On écrit d'abord le harness de test, on vérifie qu'il échoue, puis on implémente les méthodes.

**Files:**
- Create: `wp-content/plugins/photo-contest/tests/test-pc-phases.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-settings.php`

- [ ] **Step 1 : Écrire le harness de test (qui échoue)**

Créer `wp-content/plugins/photo-contest/tests/test-pc-phases.php` :

```php
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
```

- [ ] **Step 2 : Lancer le test pour vérifier qu'il échoue**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-phases.php`
Expected : FAIL — erreur fatale `Call to undefined method PC_Settings::normalize_stored_date()` (la méthode n'existe pas encore).

- [ ] **Step 3 : Implémenter les méthodes dans `PC_Settings`**

Dans `includes/class-pc-settings.php`, **remplacer entièrement** la méthode `is_depot_actif()` existante (lignes ~118-135) par le bloc ci-dessous (refactor + 3 nouvelles méthodes) :

```php
    /**
     * Vérifie si le dépôt de photos est actuellement actif.
     * Interrupteur maître `depot_actif` ET fenêtre de dates [ouverture ; clôture].
     * Comparaisons ancrées sur wp_timezone() (voir date_to_ts) — jamais l'horloge serveur.
     */
    public static function is_depot_actif(): bool {
        if ( ! self::get( 'depot_actif' ) ) {
            return false;
        }
        $now       = time();
        $ouverture = self::date_to_ts( self::get( 'date_ouverture' ) );
        $fermeture = self::date_to_ts( self::get( 'date_fermeture_depot' ) );

        if ( $ouverture !== null && $now < $ouverture ) {
            return false; // pas encore ouvert
        }
        if ( $fermeture !== null && $now > $fermeture ) {
            return false; // clôturé
        }
        return true;
    }

    /**
     * Vérifie si la phase jury (délibération) est actuellement ouverte.
     *
     * Ordre de priorité :
     *  1. Case `jury_actif` cochée -> true (forçage manuel, prioritaire sur dates ET clôture ; usage test)
     *  2. Clôture effectuée (cloture_effectuee_at > 0) -> false (chemin automatique verrouillé)
     *  3. time() >= date_fermeture_depot -> true (ouverture automatique)
     *  4. sinon false
     */
    public static function is_jury_actif(): bool {
        if ( self::get( 'jury_actif' ) ) {
            return true;
        }
        if ( (int) self::get( 'cloture_effectuee_at', 0 ) > 0 ) {
            return false;
        }
        $fermeture = self::date_to_ts( self::get( 'date_fermeture_depot' ) );
        return $fermeture !== null && time() >= $fermeture;
    }

    /**
     * Normalise une saisie de date (ex. datetime-local "Y-m-d\TH:i") en "Y-m-d H:i:s",
     * interprétée comme heure murale du fuseau du site. Chaîne vide si vide ou invalide.
     * Utilisé à la sauvegarde des réglages admin.
     */
    public static function normalize_stored_date( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }
        try {
            return ( new DateTimeImmutable( $value, wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    /**
     * Convertit une date stockée (heure murale du fuseau du site, sans fuseau explicite)
     * en timestamp epoch UTC, comparable à time(). null si vide/invalide.
     *
     * CRITIQUE : on ancre sur wp_timezone() (Réglages -> Général), jamais sur strtotime()/date()
     * nus ni l'horloge serveur. Laragon (dev) et O2Switch (prod) n'ont pas le même fuseau système ;
     * cet ancrage garantit que la deadline se déclenche au même instant réel partout.
     */
    private static function date_to_ts( mixed $value ): ?int {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( $value === '' ) {
            return null;
        }
        try {
            return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
        } catch ( Exception $e ) {
            return null;
        }
    }
```

- [ ] **Step 4 : Lancer le test pour vérifier qu'il passe**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-phases.php`
Expected : PASS — `XX tests · 0 échecs`, code de sortie 0.

- [ ] **Step 5 : Commit**

```bash
git add wp-content/plugins/photo-contest/tests/test-pc-phases.php wp-content/plugins/photo-contest/includes/class-pc-settings.php
git commit -m "feat(phases): logique is_jury_actif + dates ancrées sur wp_timezone()

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2 : Brancher le gate jury sur `is_jury_actif()`

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php:163`

- [ ] **Step 1 : Remplacer la lecture directe du flag**

Dans `render_jury()`, remplacer :

```php
        if ( ! PC_Settings::get( 'jury_actif', false ) )
            return '<p class="pc-notice">' . esc_html__( 'La phase de délibération n\'est pas encore ouverte.', 'photo-contest' ) . '</p>';
```

par :

```php
        if ( ! PC_Settings::is_jury_actif() )
            return '<p class="pc-notice">' . esc_html__( 'La phase de délibération n\'est pas encore ouverte.', 'photo-contest' ) . '</p>';
```

- [ ] **Step 2 : Vérifier qu'il ne reste aucune autre lecture brute du flag jury**

Run : `grep -rn "get( *'jury_actif'" wp-content/plugins/photo-contest`
Expected : aucune occurrence en *lecture de gate* (seules subsistent les écritures dans `class-pc-payments.php` et la case admin). La ligne du shortcode doit désormais appeler `is_jury_actif()`.

> Note : la logique de décision est déjà couverte par les tests de la Task 1. Ce changement est purement le câblage du point de lecture.

- [ ] **Step 3 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php
git commit -m "feat(phases): le gate jury utilise is_jury_actif() (ouverture auto à la clôture du dépôt)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3 : Ouverture auto du catalogue à la clôture de la délibération

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php` (`execute_cloture()`, étape 4, ≈ ligne 690 ; commentaire de tête ≈ ligne 633)

- [ ] **Step 1 : Ajouter l'activation du catalogue à la bascule des flags**

Dans `execute_cloture()`, remplacer le bloc « 4. Bascule des flags » :

```php
        // 4. Bascule des flags
        PC_Settings::set( 'jury_actif',           false );
        PC_Settings::set( 'cloture_en_cours',     0 );
        PC_Settings::set( 'cloture_effectuee_at', time() );
```

par :

```php
        // 4. Bascule des flags
        PC_Settings::set( 'jury_actif',           false );
        PC_Settings::set( 'catalogue_actif',      true );   // ouverture auto du catalogue
        PC_Settings::set( 'cloture_en_cours',     0 );
        PC_Settings::set( 'cloture_effectuee_at', time() );
```

- [ ] **Step 2 : Mettre à jour le commentaire de tête de la méthode**

Dans le bloc de doc de `execute_cloture()`, remplacer la ligne :

```php
     *  4. Bascule des flags (jury_actif=false, cloture_effectuee_at=time())
```

par :

```php
     *  4. Bascule des flags (jury_actif=false, catalogue_actif=true, cloture_effectuee_at=time())
```

- [ ] **Step 3 : Vérifier la cohérence du code (lecture)**

Run : `grep -n "catalogue_actif" wp-content/plugins/photo-contest/includes/class-pc-payments.php`
Expected : la nouvelle ligne `PC_Settings::set( 'catalogue_actif', true );` apparaît dans `execute_cloture()`.

Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-payments.php`
Expected : `No syntax errors detected`.

- [ ] **Step 4 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-payments.php
git commit -m "feat(phases): la clôture de la délibération ouvre automatiquement le catalogue

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4 : Champs calendrier dans l'admin + sauvegarde

**Files:**
- Modify: `wp-content/plugins/photo-contest/admin/class-pc-admin.php` (`render_settings()` et `save_settings()`)

- [ ] **Step 1 : Ajouter la section « Calendrier du concours » dans `render_settings()`**

Repérer, dans `render_settings()`, la fin du tableau des cases à cocher et le début de la section Photos :

```php
        echo '</table>';

        // ── Photos ──────────────────────────────────────────────────────
        ?>
```

Remplacer ce passage par (insertion de la section calendrier entre les deux) :

```php
        echo '</table>';

        // ── Calendrier du concours ──────────────────────────────────────
        $fmt_input = static function ( string $stored ): string {
            $stored = trim( $stored );
            if ( $stored === '' ) {
                return '';
            }
            try {
                return ( new DateTimeImmutable( $stored, wp_timezone() ) )->format( 'Y-m-d\TH:i' );
            } catch ( Exception $e ) {
                return '';
            }
        };
        ?>
        <h2 style="margin-top:24px"><?php esc_html_e( 'Calendrier du concours', PC_TEXT_DOMAIN ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="date_ouverture"><?php esc_html_e( 'Ouverture du dépôt', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="datetime-local" id="date_ouverture" name="pc_settings[date_ouverture]"
                           value="<?php echo esc_attr( $fmt_input( $s['date_ouverture'] ?? '' ) ); ?>">
                    <p class="description"><?php esc_html_e( 'Date et heure d\'ouverture du dépôt. Vide = aucune contrainte. Heure du fuseau du site (Réglages → Général).', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="date_fermeture_depot"><?php esc_html_e( 'Clôture du dépôt', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="datetime-local" id="date_fermeture_depot" name="pc_settings[date_fermeture_depot]"
                           value="<?php echo esc_attr( $fmt_input( $s['date_fermeture_depot'] ?? '' ) ); ?>">
                    <p class="description"><?php esc_html_e( 'Au-delà de cette date : le dépôt ferme et la phase jury s\'ouvre automatiquement.', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
        </table>

        <?php
        // ── Photos ──────────────────────────────────────────────────────
        ?>
```

- [ ] **Step 2 : Ré-étiqueter la case jury et ajouter les descriptions des cases**

Remplacer le bloc des cases à cocher :

```php
        // Checkboxes activations
        $checkboxes = [
            'depot_actif'     => __( 'Dépôt de photos actif', PC_TEXT_DOMAIN ),
            'jury_actif'      => __( 'Phase jury active', PC_TEXT_DOMAIN ),
            'catalogue_actif' => __( 'Catalogue actif', PC_TEXT_DOMAIN ),
        ];
        foreach ( $checkboxes as $key => $label ) {
            printf( '<tr><th>%s</th><td><input type="checkbox" name="pc_settings[%s]" value="1" %s></td></tr>',
                esc_html( $label ), esc_attr( $key ), checked( ! empty( $s[ $key ] ), true, false )
            );
        }
```

par :

```php
        // Checkboxes activations
        $checkboxes = [
            'depot_actif'     => __( 'Dépôt de photos actif', PC_TEXT_DOMAIN ),
            'jury_actif'      => __( 'Forcer l\'ouverture du jury (sinon auto à la clôture du dépôt)', PC_TEXT_DOMAIN ),
            'catalogue_actif' => __( 'Forcer l\'affichage du catalogue (sinon auto à la clôture de la délibération)', PC_TEXT_DOMAIN ),
        ];
        $cb_desc = [
            'jury_actif'      => __( 'Coché = jury ouvert immédiatement, quelles que soient les dates et l\'état de clôture (utile en test). Décoché = ouverture automatique dès la date de clôture du dépôt.', PC_TEXT_DOMAIN ),
            'catalogue_actif' => __( 'Coché = catalogue visible immédiatement (utile en test). Sinon activé automatiquement lors de la clôture de la délibération.', PC_TEXT_DOMAIN ),
        ];
        foreach ( $checkboxes as $key => $label ) {
            $desc = isset( $cb_desc[ $key ] )
                ? '<p class="description">' . esc_html( $cb_desc[ $key ] ) . '</p>'
                : '';
            printf( '<tr><th>%s</th><td><input type="checkbox" name="pc_settings[%s]" value="1" %s>%s</td></tr>',
                esc_html( $label ), esc_attr( $key ), checked( ! empty( $s[ $key ] ), true, false ), $desc
            );
        }
```

- [ ] **Step 3 : Normaliser les dates à la sauvegarde dans `save_settings()`**

Dans `save_settings()`, juste après le bloc qui borne les réglages Phase 2 (la ligne `$clean['poids_max_mo'] = ...;`) et **avant** `PC_Settings::set( $clean );`, insérer :

```php
        // Dates calendrier : normalisées en heure murale du fuseau du site (Y-m-d H:i:s).
        foreach ( [ 'date_ouverture', 'date_fermeture_depot' ] as $dk ) {
            $clean[ $dk ] = PC_Settings::normalize_stored_date( (string) ( $data[ $dk ] ?? '' ) );
        }
```

- [ ] **Step 4 : Vérifier la syntaxe**

Run : `php -l wp-content/plugins/photo-contest/admin/class-pc-admin.php`
Expected : `No syntax errors detected`.

- [ ] **Step 5 : Vérification manuelle de bout en bout**

1. Ouvrir `wp-admin → Concours Photo → Paramètres`. La section « Calendrier du concours » affiche deux sélecteurs date+heure ; la case jury est ré-étiquetée avec sa description.
2. Saisir une clôture du dépôt à une date/heure passée, **décocher** « Forcer l'ouverture du jury », enregistrer.
3. Vérifier le format stocké :

   Run : `wp option get pc_settings --format=json | php -r "$o=json_decode(stream_get_contents(STDIN),true); echo $o['date_fermeture_depot'].PHP_EOL;"`
   Expected : une date au format `Y-m-d H:i:s` (ex. `2026-05-01 23:59:00`), correspondant à l'heure saisie en heure de Paris.
4. Vérifier la bascule de phase :

   Run : `wp eval 'var_dump( PC_Settings::is_depot_actif(), PC_Settings::is_jury_actif() );'`
   Expected : `bool(false)` (dépôt fermé) puis `bool(true)` (jury ouvert automatiquement).

- [ ] **Step 6 : Commit**

```bash
git add wp-content/plugins/photo-contest/admin/class-pc-admin.php
git commit -m "feat(admin): champs date ouverture/clôture du dépôt + libellés de forçage des phases

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Auto-revue du plan (effectuée)

**Couverture de la spec :**
- Méthode `is_jury_actif()` avec ordre de priorité → Task 1 ✓
- Refactor `is_depot_actif()` (fix comparaison de chaînes) → Task 1 ✓
- Helper `date_to_ts()` ancré sur `wp_timezone()` → Task 1 ✓
- Convention timezone (indépendance serveur Laragon/O2Switch) → Task 1, test dédié ✓
- Section « Calendrier du concours » (2 champs datetime-local) → Task 4 ✓
- Ré-étiquetage case `jury_actif` + descriptions → Task 4 ✓
- Normalisation des dates à la sauvegarde (`normalize_stored_date`) → Task 1 (méthode) + Task 4 (appel) ✓
- Affichage `datetime-local` sans décalage de fuseau → Task 4 (`$fmt_input`) ✓
- Gate jury → `is_jury_actif()` (shortcode:163) → Task 2 ✓
- `execute_cloture()` pose `catalogue_actif=true` + commentaire → Task 3 ✓
- Tableau de décision (override / verrou / auto) → couvert par les assertions Task 1 ✓
- Cas limites (pas de date, override post-clôture, ouverture future) → assertions Task 1 ✓

**Scan placeholders :** aucun TBD/TODO ; tout le code est fourni intégralement.

**Cohérence des types :** `date_to_ts(): ?int` (privée), `is_jury_actif(): bool`, `is_depot_actif(): bool`, `normalize_stored_date(string): string` — noms et signatures identiques entre Task 1 (définition), Task 2/3 (appels indirects) et Task 4 (appel à `normalize_stored_date`). Cohérent.

**Hors scope confirmé :** pas de WP-Cron, pas de 3e date catalogue, pas de notification, `date_annonce_resultats` non exposé.
