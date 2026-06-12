# Profil : mention RGPD + opt-in prochain concours — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Afficher une mention RGPD (texte éditable) dans le profil candidat et y ajouter une case à cocher « être prévenu du prochain concours » dont le consentement est stocké.

**Architecture:** Réglage texte `rgpd_texte` (affiché via `wp_kses_post`), colonne booléenne `opt_in_prochain` dans `wp_pc_profiles` enregistrée par `PC_Profile::save_profile`, sans synchronisation Fluent CRM. La case décochée est transmise explicitement par le JS (`opt_in_prochain=0/1`).

**Tech Stack:** PHP 8.1+, WordPress, harness de test maison, JS vanilla (`pc-profile.js`).

**Spec :** `docs/superpowers/specs/2026-06-12-profil-rgpd-optin-design.md`
**Dépendances :** part de `feature/paiement-avant-jury` (PR #3, pour `templates/profile.php`).

---

## Structure des fichiers

| Fichier | Rôle | Tâches |
|---------|------|--------|
| `includes/class-pc-settings.php` | défaut `rgpd_texte` | 1 |
| `includes/class-pc-database.php` | colonne `opt_in_prochain` + upgrade | 1 |
| `includes/class-pc-profile.php` | `save_profile` enregistre l'opt-in | 1 |
| `tests/test-pc-profil.php` | harness (nouveau) | 1 |
| `admin/class-pc-admin.php` | champ textarea `rgpd_texte` | 2 |
| `templates/profile.php` | mention RGPD + case opt-in | 3 |
| `public/js/pc-profile.js` | envoi explicite `opt_in_prochain` | 3 |

---

## Task 1 : Backend opt-in + défaut RGPD (TDD)

**Files:**
- Create: `wp-content/plugins/photo-contest/tests/test-pc-profil.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-settings.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-database.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-profile.php`

- [ ] **Step 1 : Écrire le harness (échoue)**

Créer `wp-content/plugins/photo-contest/tests/test-pc-profil.php` :

```php
<?php
/**
 * Harness léger : profil (opt-in prochain concours, défaut RGPD).
 * Usage : php wp-content/plugins/photo-contest/tests/test-pc-profil.php
 * Crée un candidat de test, restaure en fin de run.
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

// Les champs texte restent enregistrés malgré l'opt-in
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
```

- [ ] **Step 2 : Lancer — échoue**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-profil.php`
Expected : FAIL — colonne `opt_in_prochain` inconnue (erreur SQL) ou assertion opt-in à 0/1
échoue, et/ou `rgpd_texte` vide.

- [ ] **Step 3 : Ajouter le défaut `rgpd_texte`**

Dans `includes/class-pc-settings.php`, dans `$defaults` (section « Concours »), ajouter :

```php
        'rgpd_texte'                => 'Les informations recueillies dans ce formulaire sont enregistrées par le Photo Club Pavillonnais et utilisées uniquement pour la gestion de votre participation au concours photo (inscription, délibération du jury, paiement, catalogue). Elles ne sont ni cédées à des tiers, ni exploitées à d\'autres fins. Conformément au RGPD, vous disposez d\'un droit d\'accès, de rectification et d\'effacement de vos données en écrivant à contact@photo-club-pavillonnais.fr.',
```

- [ ] **Step 4 : Ajouter la colonne `opt_in_prochain`**

Dans `includes/class-pc-database.php`, dans le `CREATE TABLE {$profiles}` (méthode
`create_tables`), ajouter la colonne après `reglement_date` :

```php
            opt_in_prochain TINYINT(1)      NOT NULL DEFAULT 0,
```

Puis, pour les installations existantes, ajouter une migration idempotente sur le **même
modèle** que `add_payments_phase2_columns()` (vérification `information_schema` + `ALTER`).
Ajouter cette méthode privée :

```php
    /**
     * Sous-projet 3 : colonne d'opt-in « prochain concours » sur la table profils.
     * Idempotent — vérifie la colonne avant ALTER.
     */
    private static function add_profiles_phase3_columns(): void {
        global $wpdb;
        $table = self::table( self::TABLE_PROFILES );
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
            $table, 'opt_in_prochain'
        ) );
        if ( ! $exists ) {
            $result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN opt_in_prochain TINYINT(1) NOT NULL DEFAULT 0" );
            if ( false === $result ) {
                error_log( '[PC_Database::add_profiles_phase3_columns] ALTER failed : ' . $wpdb->last_error );
            }
        }
    }
```

Et l'appeler depuis `create_tables()`, à côté de l'appel existant à
`self::add_payments_phase2_columns();` (repérer cet appel et ajouter
`self::add_profiles_phase3_columns();` juste après).

- [ ] **Step 5 : Enregistrer l'opt-in dans `save_profile()`**

Dans `includes/class-pc-profile.php`, méthode `save_profile()`, après la boucle de
sanitisation des `$allowed_fields` et **avant** le `if ( empty( $sanitized ) )`, insérer :

```php
        // Opt-in « prochain concours » : booléen transmis explicitement (0/1) par le formulaire.
        if ( array_key_exists( 'opt_in_prochain', $data ) ) {
            $sanitized['opt_in_prochain'] = ! empty( $data['opt_in_prochain'] ) ? 1 : 0;
        }
```

- [ ] **Step 6 : Appliquer la migration en dev + lancer le test**

Run : `wp eval 'PC_Database::maybe_upgrade();'` (si `wp` indisponible :
`php -r "define('WP_USE_THEMES',false); \$_SERVER['HTTP_HOST']='sdlp.test'; require 'wp-load.php'; PC_Database::create_tables(); echo 'ok';"`)
Expected : la colonne `opt_in_prochain` existe (pas d'erreur).

Run : `php wp-content/plugins/photo-contest/tests/test-pc-profil.php`
Expected : PASS — `4 tests · 0 échecs`.

- [ ] **Step 7 : Lint + commit**

Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-database.php` → OK.
Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-profile.php` → OK.

```bash
git add wp-content/plugins/photo-contest/tests/test-pc-profil.php wp-content/plugins/photo-contest/includes/class-pc-settings.php wp-content/plugins/photo-contest/includes/class-pc-database.php wp-content/plugins/photo-contest/includes/class-pc-profile.php
git commit -m "feat(profil): opt-in prochain concours (colonne + save_profile) + défaut rgpd_texte

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2 : Champ admin « Mention RGPD »

**Files:**
- Modify: `wp-content/plugins/photo-contest/admin/class-pc-admin.php`

- [ ] **Step 1 : Ajouter le textarea dans `render_settings()`**

Dans `render_settings()`, dans la section « Photos » ou juste après le tableau principal
(repérer un emplacement logique côté « Concours »), ajouter un bloc :

```php
        ?>
        <h2 style="margin-top:24px"><?php esc_html_e( 'Données personnelles (RGPD)', PC_TEXT_DOMAIN ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="rgpd_texte"><?php esc_html_e( 'Mention RGPD (profil candidat)', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <textarea id="rgpd_texte" name="pc_settings[rgpd_texte]" rows="5" class="large-text"><?php echo esc_textarea( $s['rgpd_texte'] ?? '' ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Texte affiché dans le profil du candidat sous son adresse email.', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
        </table>
        <?php
```

> `$s` est le tableau des réglages (`PC_Settings::all()`) déjà utilisé dans `render_settings`.
> Insérer ce bloc dans le `<form>`, avant le bouton submit final.

- [ ] **Step 2 : Sanitiser à la sauvegarde dans `save_settings()`**

Dans `save_settings()`, après les bornes des autres réglages et **avant** `PC_Settings::set( $clean )`,
ajouter :

```php
        $clean['rgpd_texte'] = isset( $data['rgpd_texte'] ) ? sanitize_textarea_field( $data['rgpd_texte'] ) : ( $s['rgpd_texte'] ?? '' );
```

> Le tableau brut posté est `$data = $_POST['pc_settings']`. La boucle générique de
> sanitisation passe déjà chaque champ par `sanitize_text_field`, ce qui aplatirait les
> retours à la ligne du textarea ; cet override le re-sanitise proprement en préservant les
> sauts de ligne. Vérifier que `$s` (réglages actuels) est disponible dans `save_settings`
> (sinon lire `PC_Settings::get( 'rgpd_texte', '' )` pour le fallback).

- [ ] **Step 3 : Lint + vérification**

Run : `php -l wp-content/plugins/photo-contest/admin/class-pc-admin.php` → No syntax errors.
Vérif manuelle : `wp-admin → Concours Photo → Paramètres` affiche le textarea « Mention RGPD »
pré-rempli avec le défaut ; modifier + enregistrer conserve le texte (avec sauts de ligne).

- [ ] **Step 4 : Commit**

```bash
git add wp-content/plugins/photo-contest/admin/class-pc-admin.php
git commit -m "feat(profil): champ admin Mention RGPD (rgpd_texte)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3 : Affichage profil (mention RGPD + case opt-in) + JS

**Files:**
- Modify: `wp-content/plugins/photo-contest/templates/profile.php`
- Modify: `wp-content/plugins/photo-contest/public/js/pc-profile.js`

Contexte : la section « Informations personnelles » contient le formulaire `#pcp-form-profil`
avec, vers la fin, un bloc `.pcp-email-info` affichant l'email, suivi du bouton submit
`#pcp-btn-save`. Le profil est inclus dans la portée de `render_profile()` (variables `$profile`,
`$user`, `$etape` directement disponibles).

- [ ] **Step 1 : Mention RGPD + case opt-in dans `templates/profile.php`**

Repérer le bloc email :

```php
        <div class="pcp-email-info">
          <span class="pcp-field__lbl"><?php esc_html_e( 'Email', PC_TEXT_DOMAIN ); ?></span>
          <span class="pcp-email-val"><?php echo esc_html( $user->user_email ); ?></span>
        </div>
```

Insérer **juste après** ce bloc (toujours dans le `<form id="pcp-form-profil">`) :

```php
        <label class="pcp-optin">
          <input type="checkbox" id="pcp-optin" name="opt_in_prochain" value="1"
                 <?php checked( ! empty( $profile['opt_in_prochain'] ) ); ?>>
          <span><?php esc_html_e( 'Je souhaite être prévenu(e) du prochain concours à cette adresse email.', PC_TEXT_DOMAIN ); ?></span>
        </label>

        <?php $rgpd = PC_Settings::get( 'rgpd_texte', '' ); if ( $rgpd !== '' ) : ?>
          <p class="pcp-rgpd"><?php echo wp_kses_post( $rgpd ); ?></p>
        <?php endif; ?>
```

- [ ] **Step 2 : Style minimal (optionnel mais propre)**

Dans `public/css/pc-profile.css` (s'il existe ; sinon ignorer cette étape), ajouter :

```css
.pcp-optin { display:flex; gap:8px; align-items:flex-start; margin:14px 0 6px; font-size:14px; }
.pcp-rgpd { font-size:12px; line-height:1.5; color:#6b6b6b; margin:6px 0 0; }
```

> Vérifier l'existence de `public/css/pc-profile.css` (`ls`). S'il n'existe pas, sauter cette
> étape (le rendu reste fonctionnel sans style dédié).

- [ ] **Step 3 : Envoi explicite de `opt_in_prochain` dans `public/js/pc-profile.js`**

Dans le handler de soumission de `#pcp-form-profil`, après la construction de
`const fd = new FormData(formProfil);` et les `fd.append('action', ...)` / `fd.append('nonce', ...)`,
ajouter (FormData n'inclut pas une case décochée — on force donc la valeur 0/1) :

```js
      const optin = document.getElementById('pcp-optin');
      fd.set('opt_in_prochain', optin && optin.checked ? '1' : '0');
```

- [ ] **Step 4 : Vérification manuelle**

1. `php -l wp-content/plugins/photo-contest/templates/profile.php` → No syntax errors.
2. Profil candidat : la mention RGPD s'affiche sous l'email ; la case opt-in reflète l'état
   stocké.
3. Cocher la case + enregistrer → recharger : la case reste cochée
   (`SELECT opt_in_prochain FROM wp_pc_profiles WHERE user_id=…` = 1).
4. Décocher + enregistrer → la valeur passe à 0 (vérifie que le décoché est bien transmis).

- [ ] **Step 5 : Commit**

```bash
git add wp-content/plugins/photo-contest/templates/profile.php wp-content/plugins/photo-contest/public/js/pc-profile.js wp-content/plugins/photo-contest/public/css/pc-profile.css
git commit -m "feat(profil): affiche la mention RGPD + case opt-in prochain concours (JS envoie 0/1)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

> Si `pc-profile.css` n'a pas été modifié (inexistant), ne pas l'inclure dans le `git add`.

---

## Auto-revue du plan (effectuée)

**Couverture de la spec :**
- Réglage `rgpd_texte` + défaut conforme (email contact) → Task 1 (Step 3) ✓
- Champ admin éditable `rgpd_texte` → Task 2 ✓
- Affichage RGPD dans le profil (`wp_kses_post`) → Task 3 (Step 1) ✓
- Colonne `opt_in_prochain` + upgrade idempotent → Task 1 (Step 4) ✓
- `save_profile` enregistre l'opt-in (0/1) → Task 1 (Step 5) ✓
- Case à cocher près de l'email, pré-cochée selon le stock → Task 3 (Step 1) ✓
- JS envoie explicitement `opt_in_prochain` (gère le décoché) → Task 3 (Step 3) ✓
- Tests (opt-in 1 puis 0, champs texte conservés, défaut RGPD non vide) → Task 1 ✓

**Placeholders :** les étapes admin (Task 2) et template/JS (Task 3) fournissent le code
exact ; les seules instructions d'ancrage (« repérer ») pointent vers des marqueurs précis
(`.pcp-email-info`, `add_payments_phase2_columns`, `FormData`). Aucun « TODO » fonctionnel.

**Cohérence des types :** clé/colonne `opt_in_prochain` (string '0'/'1' en POST → int 0/1 en
base), réglage `rgpd_texte` (string), méthode `add_profiles_phase3_columns()` — cohérents
entre Task 1, 2, 3.
