# Phase 1 — Catégories du concours — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduire la notion de catégorie dans le concours SDLP. Chaque photo appartient à exactement une catégorie. Le quota max de photos s'applique par catégorie.

**Architecture:** Nouvelle table `wp_pc_categories`, nouvelle colonne `category_id` sur `wp_pc_photos`, nouvelle classe statique `PC_Categories`, nouvelle page admin de gestion, adaptations des shortcodes Galerie/Jury/Catalogue pour grouper et filtrer par catégorie.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, MariaDB 11.4, vanilla JS, Elementor Free (côté front).

**Référence spec :** `docs/superpowers/specs/2026-06-02-categories-et-paiement-a-la-photo-design.md` sections 3, 4, 5, 6, 8, 10, 12.1

---

## Pré-requis

### File structure (à créer / modifier)

- **Create** : `wp-content/plugins/photo-contest/includes/class-pc-categories.php`
- **Create** : `wp-content/plugins/photo-contest/admin/class-pc-categories-admin.php`
- **Create** : `wp-content/plugins/photo-contest/admin/css/pc-categories-admin.css`
- **Create** : `wp-content/plugins/photo-contest/admin/js/pc-categories-admin.js`
- **Create** : `wp-content/plugins/photo-contest/tests/test-pc-categories.php` (harness léger sans framework)
- **Modify** : `wp-content/plugins/photo-contest/photo-contest.php` (autoload + bump version)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-database.php` (migration tables + colonne + ligne par défaut)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-photos.php` (validation category_id, requêtes filtrées)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php` (réponses AJAX gallery enrichies, jury)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-jury.php` (filtre category_id + nom catégorie dans réponses)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-catalogue.php` (groupement + exports)
- **Modify** : `wp-content/plugins/photo-contest/admin/class-pc-admin.php` (sous-menu Catégories + label quota_photos)
- **Modify** : `wp-content/plugins/photo-contest/templates/gallery.php` (sections par catégorie)
- **Modify** : `wp-content/plugins/photo-contest/templates/jury.php` (badge + filtre)
- **Modify** : `wp-content/plugins/photo-contest/templates/catalogue.php` (groupement)
- **Modify** : `wp-content/plugins/photo-contest/public/js/pc-gallery.js` (rendu groupé, upload avec category_id)
- **Modify** : `wp-content/plugins/photo-contest/public/js/pc-jury.js` (filtre catégorie)
- **Modify** : `wp-content/plugins/photo-contest/public/js/pc-catalogue.js` (ordre par section catégorie, exports)

### Avant de commencer

- [ ] **S1.1: Initialiser un repo git si pas déjà fait**

```bash
cd C:\laragon\www\sdlp
git status 2>&1 | head -1
```

Si réponse "fatal: not a git repository", continuer :

```bash
git init
echo "wp-content/uploads/" > .gitignore
echo "wp-content/cache/" >> .gitignore
echo "wp-content/upgrade/" >> .gitignore
echo "wp-config.php" >> .gitignore
echo "_pc_*.php" >> .gitignore
git add wp-content/plugins/photo-contest/ docs/ .gitignore
git commit -m "chore: initial commit before Phase 1 categories"
```

Si déjà un repo, vérifier que le working tree est propre :

```bash
git status
```

Expected : "working tree clean" ou commits préalables logiques.

- [ ] **S1.2: Vérifier l'état BDD initial**

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT 'photos_total' AS m, COUNT(*) AS n FROM wp_pc_photos
UNION SELECT 'tables_categories', COUNT(*) FROM information_schema.tables WHERE table_name='wp_pc_categories' AND table_schema=DATABASE()
UNION SELECT 'columns_category_id', COUNT(*) FROM information_schema.columns WHERE table_name='wp_pc_photos' AND column_name='category_id' AND table_schema=DATABASE();"
```

Expected (avant migration) :
```
photos_total          | <quelconque>
tables_categories     | 0
columns_category_id   | 0
```

Note ce point de départ pour valider la migration plus tard.

---

## Task 1: Bump version plugin

**Files:**
- Modify: `wp-content/plugins/photo-contest/photo-contest.php` (header + constante `PC_VERSION`)

- [ ] **Step 1: Identifier la version actuelle**

Lire `photo-contest.php` lignes 1-30. La version est dans le header WP et dans `define( 'PC_VERSION', ... )`.

- [ ] **Step 2: Bumper à `2.0.0-phase1`**

Modifier le header :
```php
 * Version: 2.0.0-phase1
```

Modifier la constante :
```php
define( 'PC_VERSION', '2.0.0-phase1' );
```

- [ ] **Step 3: Vérifier**

```bash
grep -E "(Version:|PC_VERSION)" wp-content/plugins/photo-contest/photo-contest.php
```

Expected : deux lignes avec `2.0.0-phase1`.

- [ ] **Step 4: Commit (si repo git)**

```bash
git add wp-content/plugins/photo-contest/photo-contest.php
git commit -m "chore(phase1): bump version to 2.0.0-phase1"
```

---

## Task 2: Constante TABLE_CATEGORIES dans PC_Database

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-database.php` (ajout constante)

- [ ] **Step 1: Lire la classe pour repérer les constantes existantes**

```bash
grep -n "const TABLE_" wp-content/plugins/photo-contest/includes/class-pc-database.php
```

- [ ] **Step 2: Ajouter la constante TABLE_CATEGORIES**

Ajouter dans la classe `PC_Database`, à proximité des autres `const TABLE_*` :

```php
public const TABLE_CATEGORIES = 'pc_categories';
```

- [ ] **Step 3: Vérifier**

```bash
grep -n "TABLE_CATEGORIES" wp-content/plugins/photo-contest/includes/class-pc-database.php
```

Expected : ligne ajoutée.

- [ ] **Step 4: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-database.php
git commit -m "feat(phase1): add TABLE_CATEGORIES constant"
```

---

## Task 3: Migration BDD — création table catégories

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-database.php` (méthode `create_tables` ou équivalente)

- [ ] **Step 1: Repérer où les tables sont créées**

```bash
grep -n "CREATE TABLE" wp-content/plugins/photo-contest/includes/class-pc-database.php
grep -n "dbDelta" wp-content/plugins/photo-contest/includes/class-pc-database.php
```

- [ ] **Step 2: Ajouter le SQL de création de wp_pc_categories**

Dans la méthode `create_tables()` (ou équivalent), ajouter avant `dbDelta` :

```php
$charset_collate = $wpdb->get_charset_collate();
$table_categories = self::table( self::TABLE_CATEGORIES );

$sql_categories = "CREATE TABLE {$table_categories} (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nom           VARCHAR(150)    NOT NULL,
    actif         TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_actif (actif)
) {$charset_collate};";

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( $sql_categories );
```

Note : `dbDelta` est idempotent, il gère le `IF NOT EXISTS` implicitement.

- [ ] **Step 3: Tester la création via un script utilitaire**

Créer un fichier temporaire `_pc_migrate.php` à la racine :

```php
<?php
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'sdlp.test';
$_SERVER['REQUEST_URI'] = '/';
require_once __DIR__ . '/wp-load.php';
PC_Database::create_tables();
global $wpdb;
$t = $wpdb->prefix . 'pc_categories';
$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$t}'" );
echo $exists ? "OK table $t existe\n" : "FAIL\n";
$wpdb->query( "DESCRIBE {$t}" );
print_r( $wpdb->last_result ?: [] );
```

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" /c/laragon/www/sdlp/_pc_migrate.php
rm /c/laragon/www/sdlp/_pc_migrate.php
```

Expected : "OK table wp_pc_categories existe" + description avec colonnes id, nom, actif, created_at, updated_at.

- [ ] **Step 4: Vérifier en SQL direct**

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "DESCRIBE wp_pc_categories;"
```

Expected : 5 colonnes, avec idx_actif.

- [ ] **Step 5: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-database.php
git commit -m "feat(phase1): add wp_pc_categories table creation in create_tables"
```

---

## Task 4: Migration BDD — colonne category_id sur wp_pc_photos

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-database.php` (méthode `maybe_upgrade`)

- [ ] **Step 1: Repérer la méthode `maybe_upgrade()`**

```bash
grep -n "maybe_upgrade\|pc_db_version" wp-content/plugins/photo-contest/includes/class-pc-database.php
```

Si la méthode existe : ajouter une étape de migration dans la branche correspondant à la version 2.0.0. Si elle n'existe pas, créer la méthode.

- [ ] **Step 2: Ajouter la méthode `add_category_column_to_photos`**

Ajouter cette méthode privée statique dans `PC_Database` :

```php
private static function add_category_column_to_photos(): void {
    global $wpdb;
    $table = self::table( self::TABLE_PHOTOS );

    $col = $wpdb->get_var( $wpdb->prepare(
        "SELECT COLUMN_NAME FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = %s
           AND column_name = 'category_id'",
        $table
    ) );

    if ( ! $col ) {
        $wpdb->query( "ALTER TABLE {$table}
            ADD COLUMN category_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id,
            ADD KEY idx_category (category_id),
            ADD KEY idx_user_category (user_id, category_id)" );
    }
}
```

- [ ] **Step 3: Appeler depuis `maybe_upgrade()` ou `create_tables()`**

Ajouter l'appel à la fin de `create_tables()` (le plus simple, garanti d'être déclenché par activation et migration auto) :

```php
self::add_category_column_to_photos();
```

- [ ] **Step 4: Tester via script utilitaire**

```php
// _pc_migrate2.php
<?php
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'sdlp.test';
$_SERVER['REQUEST_URI'] = '/';
require_once __DIR__ . '/wp-load.php';
PC_Database::create_tables();
global $wpdb;
$t = $wpdb->prefix . 'pc_photos';
$rows = $wpdb->get_results( "DESCRIBE {$t}" );
foreach ( $rows as $r ) {
    if ( $r->Field === 'category_id' ) {
        echo "OK colonne category_id type={$r->Type} default={$r->Default}\n";
    }
}
```

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" /c/laragon/www/sdlp/_pc_migrate2.php
rm /c/laragon/www/sdlp/_pc_migrate2.php
```

Expected : "OK colonne category_id type=bigint unsigned default=0".

- [ ] **Step 5: Vérifier les index**

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "SHOW INDEX FROM wp_pc_photos WHERE Key_name IN ('idx_category', 'idx_user_category');"
```

Expected : 2 lignes (un par index).

- [ ] **Step 6: Vérifier l'idempotence**

Re-lancer le script (le créer puis le supprimer) :

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
echo '<?php define("WP_USE_THEMES",false); $_SERVER["HTTP_HOST"]="sdlp.test"; $_SERVER["REQUEST_URI"]="/"; require_once __DIR__ . "/wp-load.php"; PC_Database::create_tables(); echo "OK\n";' > /c/laragon/www/sdlp/_pc_idem.php
"$PHP" /c/laragon/www/sdlp/_pc_idem.php
rm /c/laragon/www/sdlp/_pc_idem.php
```

Expected : "OK" sans erreur SQL.

- [ ] **Step 7: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-database.php
git commit -m "feat(phase1): add category_id column to wp_pc_photos (idempotent)"
```

---

## Task 5: Insertion de la catégorie par défaut + migration des photos legacy

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-database.php` (méthode `seed_default_category`)

- [ ] **Step 1: Ajouter la méthode privée `seed_default_category`**

```php
private static function seed_default_category(): int {
    global $wpdb;
    $table = self::table( self::TABLE_CATEGORIES );

    $existing = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1" );
    if ( $existing > 0 ) {
        return $existing;
    }

    $wpdb->insert( $table, [
        'nom'   => 'Photo Club Pavillonnais',
        'actif' => 1,
    ] );
    return (int) $wpdb->insert_id;
}

private static function migrate_legacy_photos_to_default_category(): void {
    global $wpdb;
    $photos_table = self::table( self::TABLE_PHOTOS );

    $orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$photos_table} WHERE category_id = 0" );
    if ( $orphans === 0 ) {
        return;
    }

    $default_id = self::seed_default_category();
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$photos_table} SET category_id = %d WHERE category_id = 0",
        $default_id
    ) );
}
```

- [ ] **Step 2: Appeler à la fin de `create_tables()`**

Après l'appel à `add_category_column_to_photos()` :

```php
self::seed_default_category();
self::migrate_legacy_photos_to_default_category();
```

- [ ] **Step 3: Tester sur état actuel**

Créer `_pc_migrate3.php` :

```php
<?php
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'sdlp.test';
$_SERVER['REQUEST_URI'] = '/';
require_once __DIR__ . '/wp-load.php';
PC_Database::create_tables();
global $wpdb;
$cat = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}pc_categories ORDER BY id ASC LIMIT 1" );
echo "Default cat: id={$cat->id} nom={$cat->nom} actif={$cat->actif}\n";
$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pc_photos" );
$with    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pc_photos WHERE category_id={$cat->id}" );
$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pc_photos WHERE category_id=0" );
echo "Photos: total=$total dans_default=$with orphelines=$orphans\n";
```

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" /c/laragon/www/sdlp/_pc_migrate3.php
rm /c/laragon/www/sdlp/_pc_migrate3.php
```

Expected (8 photos en local) :
```
Default cat: id=1 nom=Photo Club Pavillonnais actif=1
Photos: total=8 dans_default=8 orphelines=0
```

- [ ] **Step 4: Vérifier l'idempotence**

Re-lancer le script. Expected : mêmes chiffres (pas de doublon de catégorie, pas de modification supplémentaire).

- [ ] **Step 5: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-database.php
git commit -m "feat(phase1): seed default category + migrate legacy photos"
```

---

## Task 6: Harness de test pour PC_Categories

**Files:**
- Create: `wp-content/plugins/photo-contest/tests/test-pc-categories.php`

- [ ] **Step 1: Créer le répertoire et le fichier de tests**

```bash
mkdir -p /c/laragon/www/sdlp/wp-content/plugins/photo-contest/tests
```

- [ ] **Step 2: Écrire le harness de test**

Créer `wp-content/plugins/photo-contest/tests/test-pc-categories.php` :

```php
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
```

- [ ] **Step 3: Lancer le test (échouera car PC_Categories n'existe pas encore)**

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" /c/laragon/www/sdlp/wp-content/plugins/photo-contest/tests/test-pc-categories.php 2>&1 | tail -10
```

Expected : erreur "Class 'PC_Categories' not found" (normal — on n'a pas encore créé la classe).

- [ ] **Step 4: Commit**

```bash
git add wp-content/plugins/photo-contest/tests/test-pc-categories.php
git commit -m "test(phase1): add PC_Categories harness (will fail until class exists)"
```

---

## Task 7: Implémenter PC_Categories — squelette

**Files:**
- Create: `wp-content/plugins/photo-contest/includes/class-pc-categories.php`
- Modify: `wp-content/plugins/photo-contest/photo-contest.php` (require + chargement)

- [ ] **Step 1: Créer la classe PC_Categories**

Créer `wp-content/plugins/photo-contest/includes/class-pc-categories.php` :

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gestion des catégories du concours.
 * Méthodes statiques pures, pas d'état d'instance.
 */
class PC_Categories {

    public static function get_all( bool $only_active = false ): array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $where = $only_active ? 'WHERE actif = 1' : '';
        $rows  = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id ASC", ARRAY_A );
        return $rows ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function create( string $nom ): int {
        global $wpdb;
        $nom = sanitize_text_field( $nom );
        if ( $nom === '' ) {
            return 0;
        }
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $ok    = $wpdb->insert( $table, [ 'nom' => $nom, 'actif' => 1 ] );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function update( int $id, string $nom ): bool {
        global $wpdb;
        $nom = sanitize_text_field( $nom );
        if ( $nom === '' || $id <= 0 ) {
            return false;
        }
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $wpdb->update( $table, [ 'nom' => $nom ], [ 'id' => $id ] );
        return $wpdb->last_error === '';
    }

    public static function toggle( int $id ): bool {
        global $wpdb;
        if ( $id <= 0 ) {
            return false;
        }
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET actif = 1 - actif WHERE id = %d",
            $id
        ) );
        return $wpdb->last_error === '';
    }

    /**
     * @return bool|WP_Error true si supprimée, WP_Error si bloquée
     */
    public static function delete( int $id ) {
        global $wpdb;
        if ( $id <= 0 ) {
            return new WP_Error( 'pc_invalid_id', __( 'ID invalide.', PC_TEXT_DOMAIN ) );
        }
        $count = self::count_photos( $id );
        if ( $count > 0 ) {
            return new WP_Error(
                'pc_category_has_photos',
                sprintf(
                    /* translators: %d = nombre de photos */
                    __( 'Suppression refusée : %d photos sont rattachées à cette catégorie.', PC_TEXT_DOMAIN ),
                    $count
                )
            );
        }
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $wpdb->delete( $table, [ 'id' => $id ] );
        return $wpdb->last_error === '';
    }

    public static function count_photos( int $id ): int {
        global $wpdb;
        $table_photos = PC_Database::table( PC_Database::TABLE_PHOTOS );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_photos} WHERE category_id = %d",
            $id
        ) );
    }
}
```

- [ ] **Step 2: Charger la classe dans le bootstrap**

Dans `photo-contest.php`, chercher la zone d'inclusions `require_once .../includes/class-pc-*.php` et ajouter :

```php
require_once PC_PLUGIN_DIR . 'includes/class-pc-categories.php';
```

À placer **après** `class-pc-database.php` (dépendance) et avant les classes qui pourraient l'utiliser.

- [ ] **Step 3: Lancer les tests**

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" /c/laragon/www/sdlp/wp-content/plugins/photo-contest/tests/test-pc-categories.php
```

Expected :
```
== PC_Categories::get_all (only_active=false) ==
  ✓ retourne un tableau
  ✓ au moins 1 catégorie (la default)
  ✓ structure ligne complète

== PC_Categories::create / update / toggle / delete ==
  ✓ create retourne un ID > 0
  ✓ créée active par défaut
  ✓ update OK
  ✓ nom mis à jour
  ✓ toggle OK
  ✓ inactive après toggle
  ✓ aucune photo dans la nouvelle cat
  ✓ delete OK quand 0 photos
  ✓ plus en BDD après delete

== PC_Categories::delete refuse si photos ==
  ✓ delete retourne WP_Error si photos existent

--------------------------------------------
13 tests · 0 échecs
```

- [ ] **Step 4: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-categories.php wp-content/plugins/photo-contest/photo-contest.php
git commit -m "feat(phase1): implement PC_Categories static class with CRUD + count_photos"
```

---

## Task 8: Page admin Catégories — sous-menu et template

**Files:**
- Create: `wp-content/plugins/photo-contest/admin/class-pc-categories-admin.php`
- Create: `wp-content/plugins/photo-contest/admin/css/pc-categories-admin.css`
- Create: `wp-content/plugins/photo-contest/admin/js/pc-categories-admin.js`
- Modify: `wp-content/plugins/photo-contest/photo-contest.php` (require + instanciation)

- [ ] **Step 1: Créer la classe admin**

Créer `wp-content/plugins/photo-contest/admin/class-pc-categories-admin.php` :

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Page wp-admin "Concours Photo > Catégories"
 */
class PC_Categories_Admin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',                  [ $this, 'register_menu' ], 30 );
        add_action( 'admin_enqueue_scripts',       [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_pc_add_category',     [ $this, 'ajax_add' ] );
        add_action( 'wp_ajax_pc_update_category',  [ $this, 'ajax_update' ] );
        add_action( 'wp_ajax_pc_toggle_category',  [ $this, 'ajax_toggle' ] );
        add_action( 'wp_ajax_pc_delete_category',  [ $this, 'ajax_delete' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'pc-settings',                                 // parent (slug menu principal)
            __( 'Catégories', PC_TEXT_DOMAIN ),
            __( 'Catégories', PC_TEXT_DOMAIN ),
            'pc_manage_contest_settings',
            'pc-categories',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'pc-categories' ) === false ) {
            return;
        }
        wp_enqueue_style(  'pc-categories-admin', PC_PLUGIN_URL . 'admin/css/pc-categories-admin.css', [], PC_VERSION );
        wp_enqueue_script( 'pc-categories-admin', PC_PLUGIN_URL . 'admin/js/pc-categories-admin.js', [], PC_VERSION, true );
        wp_localize_script( 'pc-categories-admin', 'pcCategoriesAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'pc_categories_nonce' ),
            'i18n'    => [
                'confirm_delete' => __( 'Supprimer cette catégorie ?', PC_TEXT_DOMAIN ),
                'name_required'  => __( 'Le nom est obligatoire.', PC_TEXT_DOMAIN ),
            ],
        ] );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) {
            wp_die( __( 'Accès refusé.', PC_TEXT_DOMAIN ) );
        }
        $categories = PC_Categories::get_all( false );
        ?>
        <div class="wrap pc-categories-admin">
            <h1><?php esc_html_e( 'Catégories du concours', PC_TEXT_DOMAIN ); ?></h1>
            <p><?php esc_html_e( 'Une photo appartient à exactement une catégorie. Le quota max de photos s\'applique par catégorie.', PC_TEXT_DOMAIN ); ?></p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Nom', PC_TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'Actif', PC_TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'Photos', PC_TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'Actions', PC_TEXT_DOMAIN ); ?></th>
                    </tr>
                </thead>
                <tbody id="pc-cat-tbody">
                <?php foreach ( $categories as $cat ) : ?>
                    <?php $nb = PC_Categories::count_photos( (int) $cat['id'] ); ?>
                    <tr data-cat-id="<?php echo (int) $cat['id']; ?>">
                        <td>
                            <input type="text" class="pc-cat-nom" value="<?php echo esc_attr( $cat['nom'] ); ?>" data-original="<?php echo esc_attr( $cat['nom'] ); ?>">
                        </td>
                        <td>
                            <button type="button" class="button pc-cat-toggle">
                                <?php echo $cat['actif'] ? '✓ ' . esc_html__( 'Actif', PC_TEXT_DOMAIN ) : esc_html__( 'Inactif', PC_TEXT_DOMAIN ); ?>
                            </button>
                        </td>
                        <td><?php echo (int) $nb; ?></td>
                        <td>
                            <button type="button" class="button pc-cat-save"><?php esc_html_e( 'Enregistrer', PC_TEXT_DOMAIN ); ?></button>
                            <?php if ( $nb === 0 ) : ?>
                                <button type="button" class="button button-link-delete pc-cat-delete"><?php esc_html_e( 'Supprimer', PC_TEXT_DOMAIN ); ?></button>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e( '(suppression bloquée tant qu\'il y a des photos)', PC_TEXT_DOMAIN ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:24px"><?php esc_html_e( 'Ajouter une catégorie', PC_TEXT_DOMAIN ); ?></h2>
            <p>
                <input type="text" id="pc-cat-new-nom" placeholder="<?php esc_attr_e( 'Nom de la nouvelle catégorie', PC_TEXT_DOMAIN ); ?>" style="width:300px">
                <button type="button" class="button button-primary" id="pc-cat-add"><?php esc_html_e( 'Ajouter', PC_TEXT_DOMAIN ); ?></button>
            </p>

            <div id="pc-cat-msg" style="margin-top:12px"></div>
        </div>
        <?php
    }

    public function ajax_add(): void {
        check_ajax_referer( 'pc_categories_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }
        $nom = isset( $_POST['nom'] ) ? sanitize_text_field( wp_unslash( $_POST['nom'] ) ) : '';
        if ( $nom === '' ) {
            wp_send_json_error( [ 'message' => __( 'Le nom est obligatoire.', PC_TEXT_DOMAIN ) ] );
        }
        $id = PC_Categories::create( $nom );
        if ( $id === 0 ) {
            wp_send_json_error( [ 'message' => __( 'Création impossible.', PC_TEXT_DOMAIN ) ] );
        }
        wp_send_json_success( [ 'id' => $id, 'nom' => $nom, 'actif' => 1, 'nb_photos' => 0 ] );
    }

    public function ajax_update(): void {
        check_ajax_referer( 'pc_categories_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }
        $id  = isset( $_POST['id'] )  ? absint( $_POST['id'] ) : 0;
        $nom = isset( $_POST['nom'] ) ? sanitize_text_field( wp_unslash( $_POST['nom'] ) ) : '';
        if ( ! PC_Categories::update( $id, $nom ) ) {
            wp_send_json_error( [ 'message' => __( 'Mise à jour impossible.', PC_TEXT_DOMAIN ) ] );
        }
        wp_send_json_success( [ 'id' => $id, 'nom' => $nom ] );
    }

    public function ajax_toggle(): void {
        check_ajax_referer( 'pc_categories_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! PC_Categories::toggle( $id ) ) {
            wp_send_json_error( [ 'message' => __( 'Toggle impossible.', PC_TEXT_DOMAIN ) ] );
        }
        $cat = PC_Categories::get( $id );
        wp_send_json_success( [ 'id' => $id, 'actif' => (int) $cat['actif'] ] );
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'pc_categories_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }
        $id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $res = PC_Categories::delete( $id );
        if ( $res instanceof WP_Error ) {
            wp_send_json_error( [ 'message' => $res->get_error_message() ] );
        }
        if ( ! $res ) {
            wp_send_json_error( [ 'message' => __( 'Suppression impossible.', PC_TEXT_DOMAIN ) ] );
        }
        wp_send_json_success( [ 'id' => $id ] );
    }
}
```

- [ ] **Step 2: Créer le CSS minimal**

Créer `wp-content/plugins/photo-contest/admin/css/pc-categories-admin.css` :

```css
.pc-categories-admin .pc-cat-nom { width: 260px; }
.pc-categories-admin .pc-cat-toggle { min-width: 80px; }
.pc-categories-admin .description { color: #888; font-size: 12px; }
#pc-cat-msg.pc-msg-success { color: #2c7a3b; font-weight: 500; }
#pc-cat-msg.pc-msg-error   { color: #b03030; font-weight: 500; }
```

- [ ] **Step 3: Créer le JS**

Créer `wp-content/plugins/photo-contest/admin/js/pc-categories-admin.js` :

```javascript
(function () {
  'use strict';
  const CFG  = window.pcCategoriesAdmin || {};
  const I18N = CFG.i18n || {};
  const tbody = document.getElementById('pc-cat-tbody');
  const msg   = document.getElementById('pc-cat-msg');

  function setMsg(type, text) {
    if (!msg) return;
    msg.className = type ? ('pc-msg-' + type) : '';
    msg.textContent = text || '';
  }

  function post(action, data) {
    const body = new URLSearchParams(Object.assign({ action, nonce: CFG.nonce }, data));
    return fetch(CFG.ajaxUrl, { method: 'POST', body }).then(r => r.json());
  }

  document.getElementById('pc-cat-add')?.addEventListener('click', () => {
    const input = document.getElementById('pc-cat-new-nom');
    const nom = (input.value || '').trim();
    if (!nom) { setMsg('error', I18N.name_required); return; }
    post('pc_add_category', { nom }).then(res => {
      if (res.success) {
        setMsg('success', 'Ajoutée');
        location.reload();
      } else {
        setMsg('error', res.data?.message || 'Erreur');
      }
    });
  });

  tbody?.addEventListener('click', e => {
    const tr = e.target.closest('tr[data-cat-id]');
    if (!tr) return;
    const id = tr.dataset.catId;

    if (e.target.classList.contains('pc-cat-save')) {
      const input = tr.querySelector('.pc-cat-nom');
      const nom = (input.value || '').trim();
      if (!nom) { setMsg('error', I18N.name_required); return; }
      post('pc_update_category', { id, nom }).then(res => {
        if (res.success) {
          input.dataset.original = nom;
          setMsg('success', 'Mis à jour');
        } else {
          setMsg('error', res.data?.message || 'Erreur');
        }
      });
    }

    if (e.target.classList.contains('pc-cat-toggle')) {
      post('pc_toggle_category', { id }).then(res => {
        if (res.success) location.reload();
        else setMsg('error', res.data?.message || 'Erreur');
      });
    }

    if (e.target.classList.contains('pc-cat-delete')) {
      if (!confirm(I18N.confirm_delete)) return;
      post('pc_delete_category', { id }).then(res => {
        if (res.success) location.reload();
        else setMsg('error', res.data?.message || 'Erreur');
      });
    }
  });
})();
```

- [ ] **Step 4: Charger la classe admin dans le bootstrap**

Dans `photo-contest.php`, après les autres includes admin :

```php
if ( is_admin() ) {
    require_once PC_PLUGIN_DIR . 'admin/class-pc-categories-admin.php';
    PC_Categories_Admin::get_instance();
}
```

- [ ] **Step 5: Vérification manuelle**

Connexion wp-admin → menu « Concours Photo > Catégories » doit apparaître. Page rendue avec la catégorie « Photo Club Pavillonnais » et le nombre de photos rattachées (8 en local).

Tests manuels :
- Ajouter une catégorie « Test » → ligne apparaît, redirection
- Renommer en « Test renommée » → message succès
- Toggle → bascule visible
- Tenter supprimer la catégorie default (qui a 8 photos) → bouton absent (remplacé par message)
- Supprimer la catégorie « Test » (0 photos) → ligne disparaît

- [ ] **Step 6: Commit**

```bash
git add wp-content/plugins/photo-contest/admin/class-pc-categories-admin.php \
        wp-content/plugins/photo-contest/admin/css/pc-categories-admin.css \
        wp-content/plugins/photo-contest/admin/js/pc-categories-admin.js \
        wp-content/plugins/photo-contest/photo-contest.php
git commit -m "feat(phase1): add Categories admin page with CRUD AJAX"
```

---

## Task 9: Adapter le label `quota_photos` dans page Paramètres

**Files:**
- Modify: `wp-content/plugins/photo-contest/admin/class-pc-admin.php` (ou wherever quota_photos label vit)

- [ ] **Step 1: Localiser la chaîne**

```bash
grep -rn "quota_photos\|Quota max" wp-content/plugins/photo-contest/admin/ wp-content/plugins/photo-contest/includes/
```

- [ ] **Step 2: Mettre à jour le label**

Identifier le label actuel (ex. « Quota max de photos par candidat ») et le remplacer par :

```php
__( 'Quota max de photos par catégorie (et par candidat)', PC_TEXT_DOMAIN )
```

Ajouter une description sous le champ :

```php
__( 'Ce nombre s\'applique à chaque catégorie. Avec un quota de 5 et 3 catégories, un candidat peut déposer jusqu\'à 15 photos.', PC_TEXT_DOMAIN )
```

- [ ] **Step 3: Vérifier dans wp-admin**

`Concours Photo > Paramètres` → champ quota photos avec nouveau label et nouvelle description.

- [ ] **Step 4: Commit**

```bash
git add wp-content/plugins/photo-contest/admin/class-pc-admin.php
git commit -m "feat(phase1): update quota_photos label to clarify per-category semantics"
```

---

## Task 10: PC_Photos — validation category_id à l'upload

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-photos.php` (méthode `ajax_upload` ou équivalent)

- [ ] **Step 1: Localiser la logique d'upload**

```bash
grep -n "ajax_upload\|wp_ajax_pc_upload\|category_id" wp-content/plugins/photo-contest/includes/class-pc-photos.php
```

- [ ] **Step 2: Ajouter la validation category_id**

Dans la méthode `ajax_upload()` (juste après le check du nonce et de la session candidat) :

```php
$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
if ( $category_id <= 0 ) {
    wp_send_json_error( [ 'message' => __( 'Catégorie manquante.', PC_TEXT_DOMAIN ) ] );
}
$category = PC_Categories::get( $category_id );
if ( ! $category || (int) $category['actif'] !== 1 ) {
    wp_send_json_error( [ 'message' => __( 'Catégorie invalide ou inactive.', PC_TEXT_DOMAIN ) ] );
}

// Quota par catégorie
global $wpdb;
$user_id = get_current_user_id();
$count   = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM " . PC_Database::table( PC_Database::TABLE_PHOTOS ) . "
     WHERE user_id = %d AND category_id = %d",
    $user_id, $category_id
) );
$quota = (int) PC_Settings::get( 'quota_photos', 5 );
if ( $count >= $quota ) {
    wp_send_json_error( [
        'message' => sprintf(
            /* translators: %1$d = quota, %2$s = catégorie */
            __( 'Quota atteint (%1$d) pour la catégorie « %2$s ».', PC_TEXT_DOMAIN ),
            $quota, $category['nom']
        ),
    ] );
}
```

- [ ] **Step 3: Inclure category_id dans l'INSERT**

Identifier l'`$wpdb->insert( ... )` final et ajouter la colonne :

```php
'category_id' => $category_id,
```

- [ ] **Step 4: Retourner category_id dans la réponse JSON**

Avant le `wp_send_json_success`, enrichir la donnée retournée pour que le JS sache où afficher la vignette.

- [ ] **Step 5: Test manuel**

Via le navigateur, se connecter en candidat. Au prochain test de l'upload (qui sera fait en Task 13), vérifier que sans category_id l'upload échoue.

Pour test rapide en console maintenant :

```javascript
// dans la console du navigateur, sur /mon-espace-candidat/
const fd = new FormData();
fd.append('action', 'pc_upload_photo');
fd.append('nonce', pcGalleryConfig.nonceUpload);
// pas de category_id volontairement
fetch(pcGalleryConfig.ajaxUrl, { method: 'POST', body: fd })
  .then(r => r.json()).then(console.log);
```

Expected : `success: false, data: { message: "Catégorie manquante." }`.

- [ ] **Step 6: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-photos.php
git commit -m "feat(phase1): validate category_id on upload + per-category quota"
```

---

## Task 11: PC_Shortcodes — réponse pc_get_photos groupée par catégorie

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php` (méthode `ajax_get_photos`)

- [ ] **Step 1: Localiser la méthode**

```bash
grep -n "ajax_get_photos" wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php
```

Ouvrir le bloc complet (cherche `public function ajax_get_photos`).

- [ ] **Step 2: Réécrire pour grouper par catégorie**

Remplacer le contenu de la méthode par :

```php
public function ajax_get_photos(): void {
    check_ajax_referer( 'pc_get_photos_nonce', 'nonce' );
    if ( ! is_user_logged_in() || ! PC_Roles::is_candidat() ) {
        wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
    }

    $user_id    = get_current_user_id();
    $photos     = PC_Photos::get_instance()->get_user_photos( $user_id );
    $quota      = (int) PC_Settings::get( 'quota_photos', 5 );
    $categories = PC_Categories::get_all( true ); // only_active=true

    // Enrichir chaque photo avec URLs
    $photos = array_map( function ( $photo ) use ( $user_id ) {
        $photo['url_thumb'] = $this->get_photo_url( (int) $photo['id'], 'thumb' );
        $photo['url_full']  = $this->get_photo_url( (int) $photo['id'], 'full' );

        if ( $photo['statut'] === 'participation_demandee' ) {
            $token = wp_create_nonce( "pc_payment_{$user_id}_{$photo['id']}" );
            $base  = get_permalink( get_option( 'pc_page_espace_candidat' ) ) ?: home_url( '/' );
            $photo['url_paiement'] = add_query_arg( [
                'pc_action' => 'paiement',
                'photo'     => $photo['id'],
                'token'     => $token,
            ], $base );
        } else {
            $photo['url_paiement'] = '';
        }
        return $photo;
    }, $photos );

    // Grouper photos par catégorie
    $by_category = [];
    foreach ( $photos as $p ) {
        $cid = (int) ( $p['category_id'] ?? 0 );
        $by_category[ $cid ][] = $p;
    }

    // Préparer la structure de réponse
    $sections = [];
    foreach ( $categories as $cat ) {
        $cid = (int) $cat['id'];
        $sections[] = [
            'id'     => $cid,
            'nom'    => $cat['nom'],
            'quota'  => $quota,
            'photos' => $by_category[ $cid ] ?? [],
        ];
        unset( $by_category[ $cid ] );
    }

    // Section "Non classées" si reste des photos avec category_id absent ou inactif
    $orphelines = [];
    foreach ( $by_category as $cid => $list ) {
        $orphelines = array_merge( $orphelines, $list );
    }
    if ( ! empty( $orphelines ) ) {
        $sections[] = [
            'id'     => 0,
            'nom'    => __( 'Non classées', PC_TEXT_DOMAIN ),
            'quota'  => 0, // pas d'upload possible
            'photos' => $orphelines,
        ];
    }

    // Stats statuts (inchangé)
    $stats = [];
    foreach ( $photos as $p ) {
        $stats[ $p['statut'] ] = ( $stats[ $p['statut'] ] ?? 0 ) + 1;
    }

    wp_send_json_success( [
        'categories'    => $sections,
        'quota_max'     => $quota,
        'quota_utilise' => count( $photos ),
        'stats_statuts' => $stats,
    ] );
}
```

- [ ] **Step 3: Vérifier que la méthode get_user_photos retourne category_id**

```bash
grep -n "get_user_photos" wp-content/plugins/photo-contest/includes/class-pc-photos.php
```

Si la méthode fait un `SELECT *`, c'est OK. Si c'est `SELECT id, titre, ...`, ajouter `category_id` aux colonnes sélectionnées.

- [ ] **Step 4: Test manuel via cURL ou navigateur connecté**

```javascript
// console navigateur sur /mon-espace-candidat/ (candidat connecté)
fetch(pcGalleryConfig.ajaxUrl, {
  method: 'POST',
  body: new URLSearchParams({ action: 'pc_get_photos', nonce: pcGalleryConfig.nonceGet })
}).then(r => r.json()).then(d => console.log(d.data.categories));
```

Expected : tableau de sections, chacune avec `id`, `nom`, `quota`, `photos`.

- [ ] **Step 5: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php
git commit -m "feat(phase1): group photos by category in ajax_get_photos response"
```

---

## Task 12: Template gallery.php — sections par catégorie

**Files:**
- Modify: `wp-content/plugins/photo-contest/templates/gallery.php`

- [ ] **Step 1: Lire le template actuel**

```bash
cat wp-content/plugins/photo-contest/templates/gallery.php | head -100
```

Identifier l'emplacement du conteneur `#pc-gallery-grid` ou équivalent où les photos sont rendues.

- [ ] **Step 2: Remplacer par un conteneur de sections**

Remplacer le bloc unique de grille par un conteneur qui sera peuplé par JS avec une section par catégorie :

```html
<div id="pc-gallery-sections" class="pc-gallery-sections">
    <!-- Sections injectées par pc-gallery.js -->
    <p class="pc-gallery-loading"><?php esc_html_e( 'Chargement…', PC_TEXT_DOMAIN ); ?></p>
</div>
```

- [ ] **Step 3: Conserver le formulaire d'upload mais le rendre invisible**

Le formulaire d'upload sera désormais invoqué par section (un bouton « + Ajouter » par section ouvre le formulaire pré-rempli avec `category_id`). Garder le formulaire dans le DOM mais caché avec `style="display:none"` ; le JS le clonera dans chaque section.

- [ ] **Step 4: Test visuel**

Ouvrir `/mon-espace-candidat/` connecté en candidat. Voir « Chargement… » (la grille JS n'a pas encore été migrée — c'est l'objet de la Task 13).

- [ ] **Step 5: Commit**

```bash
git add wp-content/plugins/photo-contest/templates/gallery.php
git commit -m "feat(phase1): restructure gallery template into section container"
```

---

## Task 13: pc-gallery.js — rendu groupé + upload par section

**Files:**
- Modify: `wp-content/plugins/photo-contest/public/js/pc-gallery.js`

- [ ] **Step 1: Lire le JS actuel**

```bash
cat wp-content/plugins/photo-contest/public/js/pc-gallery.js | head -120
```

Identifier la fonction qui rend les photos (typiquement `renderPhotos` ou équivalent).

- [ ] **Step 2: Remplacer la fonction de rendu**

Refondre la fonction de rendu pour itérer sur `data.categories` au lieu d'un tableau plat :

```javascript
function renderSections(data) {
  const root = document.getElementById('pc-gallery-sections');
  if (!root) return;
  root.innerHTML = '';

  data.categories.forEach(section => {
    const div = document.createElement('section');
    div.className = 'pc-gallery-section';
    div.dataset.catId = section.id;

    const filled  = section.photos.length;
    const quotaMax = section.quota || 0;
    const canAdd  = section.id > 0 && filled < quotaMax;

    div.innerHTML = `
      <header class="pc-gallery-section__header">
        <h2>${escapeHtml(section.nom)}</h2>
        <span class="pc-gallery-section__count">${filled} / ${quotaMax}</span>
      </header>
      <div class="pc-gallery-section__photos"></div>
      ${canAdd ? `<button class="pc-add-btn" data-cat-id="${section.id}">+ Ajouter une photo</button>`
               : `<p class="pc-add-disabled">Quota atteint</p>`}
    `;

    const photosWrap = div.querySelector('.pc-gallery-section__photos');
    section.photos.forEach(p => photosWrap.appendChild(buildPhotoVignette(p)));

    root.appendChild(div);
  });
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
```

Le `buildPhotoVignette(p)` existe déjà (vérifier le nom exact), réutiliser tel quel — il génère le HTML d'une vignette à partir d'un objet photo.

- [ ] **Step 3: Brancher le bouton « + Ajouter » sur l'upload existant**

Au début de l'init du script, ajouter un listener sur le root des sections :

```javascript
document.getElementById('pc-gallery-sections')?.addEventListener('click', e => {
  if (e.target.classList.contains('pc-add-btn')) {
    const catId = e.target.dataset.catId;
    openUploadForCategory(parseInt(catId, 10));
  }
});

function openUploadForCategory(catId) {
  // Récupère le formulaire d'upload caché, met à jour le hidden category_id, le rend visible
  const form = document.getElementById('pc-upload-form');
  let catInput = form.querySelector('input[name="category_id"]');
  if (!catInput) {
    catInput = document.createElement('input');
    catInput.type = 'hidden';
    catInput.name = 'category_id';
    form.appendChild(catInput);
  }
  catInput.value = catId;
  form.style.display = 'block';
  form.scrollIntoView({ behavior: 'smooth' });
}
```

- [ ] **Step 4: Ajouter category_id dans le submit existant**

Identifier le `fetch(pcGalleryConfig.ajaxUrl, ...)` du submit d'upload. Vérifier que `FormData(form)` inclut bien le hidden `category_id` (oui par défaut).

Après le succès de l'upload, relancer `loadPhotos()` (ou équivalent) pour repeupler les sections.

- [ ] **Step 5: Bump du cache buster**

Dans `class-pc-shortcodes.php`, méthode `enqueue_gallery_assets()` :

```php
wp_enqueue_script( 'pc-gallery', PC_PLUGIN_URL . 'public/js/pc-gallery.js', [], PC_VERSION . '.5', true );
```

(passer de `.4` à `.5`)

Idem pour le CSS :

```php
wp_enqueue_style( 'pc-gallery', PC_PLUGIN_URL . 'public/css/pc-gallery.css', [], PC_VERSION . '.5' );
```

- [ ] **Step 6: Test manuel**

Connecté en candidat sur `/mon-espace-candidat/` :
- La galerie affiche une section « Photo Club Pavillonnais » avec les 8 photos legacy.
- Le bouton « + Ajouter une photo » apparaît si quota non atteint.
- Cliquer le bouton fait apparaître le formulaire d'upload.
- Uploader une nouvelle photo réussit, la vignette s'ajoute à la bonne section.

Créer une 2e catégorie depuis wp-admin (« Test »), recharger la galerie : 2 sections visibles, la section « Test » est vide avec quota plein disponible.

- [ ] **Step 7: Commit**

```bash
git add wp-content/plugins/photo-contest/public/js/pc-gallery.js \
        wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php
git commit -m "feat(phase1): render gallery as sections per category, upload per section"
```

---

## Task 14: CSS gallery — styles des sections

**Files:**
- Modify: `wp-content/plugins/photo-contest/public/css/pc-gallery.css`

- [ ] **Step 1: Ajouter les styles de section**

À la fin de `pc-gallery.css` :

```css
.pc-gallery-section {
  margin-bottom: 32px;
  padding-bottom: 24px;
  border-bottom: 1px solid #2a2b2e;
}
.pc-gallery-section:last-child { border-bottom: none; }

.pc-gallery-section__header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 16px;
}
.pc-gallery-section__header h2 {
  font-family: 'DM Mono', monospace;
  font-size: 13px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #c49a3c;
  margin: 0;
}
.pc-gallery-section__count {
  font-family: 'DM Mono', monospace;
  font-size: 12px;
  color: #888480;
}

.pc-gallery-section__photos {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.pc-add-btn {
  background: #c49a3c;
  color: #0e0f10;
  border: none;
  border-radius: 6px;
  padding: 8px 16px;
  font-family: 'DM Mono', monospace;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.pc-add-btn:hover { opacity: 0.85; }

.pc-add-disabled {
  color: #888480;
  font-style: italic;
  font-size: 13px;
}

.pc-gallery-loading {
  color: #888480;
  text-align: center;
  padding: 40px;
}
```

- [ ] **Step 2: Test visuel**

Recharger `/mon-espace-candidat/` (Ctrl+F5). Les sections doivent maintenant être stylées avec la palette laboratoire sombre cohérente.

- [ ] **Step 3: Commit**

```bash
git add wp-content/plugins/photo-contest/public/css/pc-gallery.css
git commit -m "style(phase1): add gallery section styles"
```

---

## Task 15: Espace jury — affichage du filtre catégorie + badge

**Files:**
- Modify: `wp-content/plugins/photo-contest/templates/jury.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-jury.php` (méthode `get_photos_pour_jury` ou `ajax_get_photos`)
- Modify: `wp-content/plugins/photo-contest/public/js/pc-jury.js`

- [ ] **Step 1: Lire le template jury actuel**

```bash
cat wp-content/plugins/photo-contest/templates/jury.php | head -80
```

- [ ] **Step 2: Ajouter une barre de filtres avant la grille**

Dans `templates/jury.php`, juste avant le conteneur de photos :

```php
<?php $categories = PC_Categories::get_all( false ); ?>
<?php if ( count( $categories ) > 1 ) : ?>
<nav class="pc-jury-filter">
    <button type="button" class="pc-jury-filter__btn active" data-cat-id="0">
        <?php esc_html_e( 'Toutes', PC_TEXT_DOMAIN ); ?>
    </button>
    <?php foreach ( $categories as $cat ) : ?>
        <button type="button" class="pc-jury-filter__btn" data-cat-id="<?php echo (int) $cat['id']; ?>">
            <?php echo esc_html( $cat['nom'] ); ?>
        </button>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
```

- [ ] **Step 3: Modifier get_photos_pour_jury pour accepter category_id**

Dans `class-pc-jury.php`, méthode qui retourne les photos :

```php
public function get_photos_pour_jury( int $jury_user_id, int $category_id = 0 ): array {
    global $wpdb;
    $table_photos = PC_Database::table( PC_Database::TABLE_PHOTOS );
    $table_cats   = PC_Database::table( PC_Database::TABLE_CATEGORIES );

    $where = "WHERE p.statut IN ('en_attente', 'en_cours_examen')";
    $args  = [];
    if ( $category_id > 0 ) {
        $where .= " AND p.category_id = %d";
        $args[] = $category_id;
    }

    $sql = "SELECT p.id, p.titre, p.category_id, c.nom AS nom_categorie
            FROM {$table_photos} p
            LEFT JOIN {$table_cats} c ON c.id = p.category_id
            {$where}
            ORDER BY p.id ASC";

    return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
}
```

(garder la signature backward compat avec `category_id=0` par défaut)

- [ ] **Step 4: Adapter l'endpoint AJAX du jury**

L'endpoint AJAX `pc_jury_get` (ou équivalent) doit lire un paramètre `category_id` du POST et le passer à `get_photos_pour_jury`.

- [ ] **Step 5: Adapter le JS jury**

Dans `pc-jury.js`, ajouter le listener sur les boutons de filtre :

```javascript
document.querySelectorAll('.pc-jury-filter__btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.pc-jury-filter__btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const catId = parseInt(btn.dataset.catId, 10);
    loadPhotos(catId); // adapter selon le nom réel de la fonction
  });
});
```

Et dans le fetch existant :

```javascript
const body = new URLSearchParams({
  action: 'pc_jury_get_photos',
  nonce: CFG.nonceGet,
  category_id: categoryId || 0,
});
```

Dans le rendu de chaque vignette, ajouter le badge :

```javascript
vignette.innerHTML += `<span class="pc-jury-badge">${escapeHtml(photo.nom_categorie || '')}</span>`;
```

- [ ] **Step 6: CSS jury**

Dans `public/css/pc-jury.css`, ajouter :

```css
.pc-jury-filter {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.pc-jury-filter__btn {
  background: transparent;
  border: 1px solid #2a2b2e;
  color: #e8e6e1;
  padding: 6px 14px;
  font-family: 'DM Mono', monospace;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  cursor: pointer;
  border-radius: 4px;
}
.pc-jury-filter__btn.active {
  background: #c49a3c;
  color: #0e0f10;
  border-color: #c49a3c;
}

.pc-jury-badge {
  display: block;
  margin-top: 4px;
  font-family: 'DM Mono', monospace;
  font-size: 10px;
  color: #888480;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
```

- [ ] **Step 7: Bump cache buster jury**

Dans `class-pc-shortcodes.php`, méthode `enqueue_jury_assets()` : passer `.2` à `.3` sur CSS et JS.

- [ ] **Step 8: Test manuel**

Se connecter en juré (rôle `jurymembre`) → `/espace-jury/`. Barre de filtres visible avec « Toutes » + « Photo Club Pavillonnais » + « Test ». Cliquer une catégorie filtre les photos. Chaque vignette affiche le nom de la catégorie en badge sous le numéro.

- [ ] **Step 9: Commit**

```bash
git add wp-content/plugins/photo-contest/templates/jury.php \
        wp-content/plugins/photo-contest/includes/class-pc-jury.php \
        wp-content/plugins/photo-contest/public/js/pc-jury.js \
        wp-content/plugins/photo-contest/public/css/pc-jury.css \
        wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php
git commit -m "feat(phase1): add category filter and badge in jury view"
```

---

## Task 16: Catalogue — groupement par catégorie

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-catalogue.php`
- Modify: `wp-content/plugins/photo-contest/templates/catalogue.php`
- Modify: `wp-content/plugins/photo-contest/public/js/pc-catalogue.js`

- [ ] **Step 1: Repérer la méthode de lecture du catalogue**

```bash
grep -n "ajax_catalogue_get\|catalogue_items\|get_items" wp-content/plugins/photo-contest/includes/class-pc-catalogue.php
```

- [ ] **Step 2: Réécrire la lecture pour grouper par catégorie**

Dans la méthode de chargement, faire un JOIN avec photos et catégories :

```php
$sql = "SELECT ci.*, p.titre, p.category_id, c.nom AS nom_categorie
        FROM {$table_items} ci
        JOIN {$table_photos} p ON p.id = ci.photo_id
        LEFT JOIN {$table_cats} c ON c.id = p.category_id
        WHERE ci.edition = %s
        ORDER BY p.category_id ASC, ci.ordre ASC";

$rows = $wpdb->get_results( $wpdb->prepare( $sql, $edition ), ARRAY_A );

// Grouper par catégorie
$by_cat = [];
foreach ( $rows as $r ) {
    $by_cat[ (int) $r['category_id'] ][] = $r;
}

$sections = [];
foreach ( PC_Categories::get_all( false ) as $cat ) {
    $cid = (int) $cat['id'];
    if ( ! empty( $by_cat[ $cid ] ) ) {
        $sections[] = [
            'id'     => $cid,
            'nom'    => $cat['nom'],
            'photos' => $by_cat[ $cid ],
        ];
    }
}
```

Retourner `$sections` dans la réponse JSON.

- [ ] **Step 3: Adapter le template catalogue.php**

Réorganiser le rendu : pour chaque section, afficher un sous-titre puis la grille triable. Le JS catalogue devra gérer le drag-and-drop **par section** uniquement (pas inter-sections).

- [ ] **Step 4: Adapter pc-catalogue.js**

Le drag-and-drop existant doit être contraint à la section parente. Identifier la lib utilisée (Sortable.js ou custom). Modifier pour `containers` séparés.

- [ ] **Step 5: Adapter `pc_catalogue_save_order` (endpoint AJAX)**

Le payload reçu désormais sous forme :

```json
{
  "edition": "SDLP 2026",
  "ordres": [
    { "category_id": 1, "photo_ids": [12, 47, 88] },
    { "category_id": 2, "photo_ids": [33, 9, 17] }
  ]
}
```

Adapter le backend pour itérer sur ces sections.

- [ ] **Step 6: Adapter les exports**

**CSV** : ajouter la colonne `categorie` après `candidat`. Modifier `PC_Catalogue::export_csv()` :

```php
$header = [ 'ordre', 'titre', 'candidat', 'categorie', 'edition' ];
foreach ( $rows as $i => $r ) {
    fputcsv( $fh, [
        $i + 1,
        $r['titre'],
        $r['display_name'],
        $r['nom_categorie'] ?? '',
        $edition,
    ] );
}
```

**JSON** : retourner directement `$sections` comme nouvelle structure.

**PDF (mPDF)** : avant chaque section, ajouter une page de séparation :

```php
foreach ( $sections as $section ) {
    $mpdf->AddPage();
    $mpdf->WriteHTML(
        '<h1 style="text-align:center;font-size:48pt;margin-top:30%;">'
        . esc_html( $section['nom'] )
        . '</h1>'
    );
    foreach ( $section['photos'] as $photo ) {
        $mpdf->AddPage();
        // ... rendu photo existant
    }
}
```

- [ ] **Step 7: Bump cache buster catalogue**

Dans `class-pc-shortcodes.php`, méthode `enqueue_catalogue_assets()`. Bump `.2` à `.3`.

- [ ] **Step 8: Test manuel**

Avec rôle `pc_catalogue_editor` sur `/catalogue/` :
- Photos affichées par section catégorie
- Drag-and-drop fonctionne **à l'intérieur** d'une section, pas entre
- Export CSV : ouvrir le fichier, vérifier la colonne `categorie`
- Export JSON : valider la structure `categories: [...]`
- Export PDF : vérifier les pages de séparation entre catégories

- [ ] **Step 9: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-catalogue.php \
        wp-content/plugins/photo-contest/templates/catalogue.php \
        wp-content/plugins/photo-contest/public/js/pc-catalogue.js \
        wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php
git commit -m "feat(phase1): catalogue grouped by category in UI and exports"
```

---

## Task 17: Tests de régression complets

**Files:** (aucun, scénarios manuels)

- [ ] **Step 1: Lancer le harness PC_Categories**

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" /c/laragon/www/sdlp/wp-content/plugins/photo-contest/tests/test-pc-categories.php
```

Expected : 13 tests, 0 échecs.

- [ ] **Step 2: Vérifier la BDD**

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 <<'SQL'
SELECT 'orphans' AS m, COUNT(*) AS n FROM wp_pc_photos WHERE category_id = 0
UNION SELECT 'total_categories', COUNT(*) FROM wp_pc_categories
UNION SELECT 'total_photos',     COUNT(*) FROM wp_pc_photos
UNION SELECT 'photos_in_default', COUNT(*) FROM wp_pc_photos WHERE category_id = 1;
SQL
```

Expected (au moins) : `orphans=0`, `photos_in_default >= 8` (toutes les photos legacy migrées).

- [ ] **Step 3: Parcours admin complet**

- [ ] Aller à `Concours Photo > Catégories`
- [ ] Créer « Catégorie A » → apparaît active, 0 photos
- [ ] Toggle « Catégorie A » inactif → bouton bascule, label change
- [ ] Toggle à nouveau → ré-active
- [ ] Renommer « Catégorie A » en « Faune urbaine » → succès message
- [ ] Tenter supprimer « Photo Club Pavillonnais » (8 photos) → bouton absent
- [ ] Supprimer « Faune urbaine » (0 photos) → ligne disparaît avec confirm

- [ ] **Step 4: Parcours candidat complet**

- [ ] Recréer une catégorie « Faune urbaine » dans l'admin
- [ ] Se connecter en candidat (toto / Test1234!) → `/mon-espace-candidat/`
- [ ] Vérifier : 2 sections « Photo Club Pavillonnais » (avec photos) et « Faune urbaine » (vide)
- [ ] Uploader une photo dans « Faune urbaine » → vignette apparaît dans la bonne section
- [ ] Vérifier en BDD :
  ```bash
  MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
  "$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
  SELECT id, user_id, category_id, titre FROM wp_pc_photos ORDER BY id DESC LIMIT 3;"
  ```
  Expected : la dernière photo a `category_id` = ID de Faune urbaine.

- [ ] Atteindre le quota (uploader 5 photos dans « Faune urbaine » si quota=5) → 6e upload refusé avec message « Quota atteint (5) pour la catégorie « Faune urbaine ». »

- [ ] **Step 5: Parcours jury**

- [ ] Activer le jury (`pc_settings.jury_actif = 1` si nécessaire)
- [ ] Se connecter en juré → `/espace-jury/`
- [ ] Barre de filtres visible
- [ ] Clic « Faune urbaine » → seules ces photos s'affichent
- [ ] Vérifier le badge catégorie sous chaque numéro de photo
- [ ] Anonymisation : aucun nom de candidat visible
- [ ] Voter sur une photo → comportement inchangé (le statut bascule comme avant)

- [ ] **Step 6: Parcours catalogue (si applicable)**

Si des photos ont déjà le statut `au_catalogue` :
- [ ] Se connecter en `pc_catalogue_editor` → `/catalogue/`
- [ ] Photos rendues groupées par catégorie
- [ ] Export PDF/CSV/JSON contient bien le groupement

- [ ] **Step 7: Régression — workflow inscription/profil/règlement non cassé**

- [ ] Créer un nouveau candidat (`/inscription-photographe/`)
- [ ] Vérifier email → profil → règlement → accès galerie
- [ ] Vérifier que le formulaire d'inscription, le profil avec PDF du règlement et la galerie initiale (vide, prête à recevoir) fonctionnent comme avant

- [ ] **Step 8: Commit du tag de fin de Phase 1**

```bash
git tag -a v2.0.0-phase1 -m "Phase 1 - Categories du concours - tests OK"
git status
```

---

## Self-Review

- [ ] **Coverage** : Toutes les sections 3 à 6 et 12.1 de la spec sont couvertes par les Tasks 2 à 16.
- [ ] **Pas de placeholder** : aucun TBD/TODO.
- [ ] **Type consistency** : `PC_Categories::get_all(bool)`, `PC_Categories::get(int): ?array`, `PC_Categories::delete(int): bool|WP_Error` — utilisés cohéremment dans la classe admin, les endpoints AJAX et le harness de test.
- [ ] **Migration idempotente** : `add_category_column_to_photos` check via `information_schema.columns`, `seed_default_category` check ligne préexistante, `migrate_legacy_photos_to_default_category` check `category_id=0`.
- [ ] **Sécurité** : `check_ajax_referer` + `current_user_can( 'pc_manage_contest_settings' )` sur les 4 endpoints admin, `sanitize_text_field` sur les inputs, `esc_*` sur les outputs.
- [ ] **Backward compat** : section « Non classées » dans gallery si photo orpheline, anonymisation préservée côté jury, exports catalogue continuent à fonctionner.

---

## Hand-off pour Phase 2

Une fois cette Phase 1 validée et taggée, le candidat peut déjà déposer des photos par catégorie. La cascade auto vers `participation_demandee` (et donc le paiement individuel actuel) reste **inchangée** : un vote jury « retenue » envoie toujours l'email avec lien Stripe individuel comme aujourd'hui. C'est la Phase 2 qui supprimera cette cascade et introduira le paiement groupé à la clôture.

Le passage à Phase 2 se fait via le plan `docs/superpowers/plans/2026-06-02-phase2-paiement-a-la-photo.md`.
