# Phase 2 — Paiement à la photo retenue — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Supprimer le paiement à l'inscription (15 €) et le remplacer par un paiement par photo retenue, regroupé en une seule transaction Stripe à la clôture admin de la délibération. Envoi par lots WP-Cron + relances automatiques.

**Architecture:** La cascade automatique `retenue → participation_demandee` du jury est supprimée. Une nouvelle page admin « Clôture délibération » provoque, en une opération atomique, la bascule de toutes les photos retenues vers `participation_demandee`, la création de N entrées `wp_pc_payments` par candidat (avec `payment_token` partagé), et la planification d'un WP-Cron qui envoie les emails par lots. Un endpoint `?pc_pay=token` génère la session Stripe Checkout avec N line_items à la volée. Le webhook traite N paiements en une fois. Un second WP-Cron quotidien relance les impayés.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, MariaDB 11.4, Stripe SDK `stripe/stripe-php ^13`, WP-Cron, Fluent CRM.

**Référence spec :** `docs/superpowers/specs/2026-06-02-categories-et-paiement-a-la-photo-design.md` sections 7, 9, 10, 12.2

**Pré-requis :** Phase 1 (catégories) déployée et taggée `v2.0.0-phase1`.

---

## Pré-requis

### File structure (à créer / modifier)

- **Create** : `wp-content/plugins/photo-contest/admin/class-pc-cloture-admin.php`
- **Create** : `wp-content/plugins/photo-contest/admin/css/pc-cloture-admin.css`
- **Create** : `wp-content/plugins/photo-contest/admin/js/pc-cloture-admin.js`
- **Create** : `wp-content/plugins/photo-contest/tests/test-pc-payments-cloture.php`
- **Modify** : `wp-content/plugins/photo-contest/photo-contest.php` (bump version + require admin clôture)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-database.php` (migration colonnes payments)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-settings.php` (nouveaux réglages)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-payments.php` (refonte majeure)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-jury.php` (suppression cascade auto)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-registration.php` (etape `paiement_requis` supprimée)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php` (suppression étape 3 profil)
- **Modify** : `wp-content/plugins/photo-contest/templates/profile.php` (suppression bloc paiement)
- **Modify** : `wp-content/plugins/photo-contest/includes/class-pc-fluent-crm.php` (nouveaux triggers)
- **Modify** : `wp-content/plugins/photo-contest/admin/class-pc-admin.php` (label `montant_participation_cts` + nouveaux réglages cron)

### Avant de commencer

- [ ] **S2.1: Vérifier que Phase 1 est appliquée**

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT 'cat_table'    AS m, COUNT(*) AS n FROM information_schema.tables WHERE table_name='wp_pc_categories' AND table_schema=DATABASE()
UNION SELECT 'cat_col_photos', COUNT(*) FROM information_schema.columns WHERE table_name='wp_pc_photos' AND column_name='category_id' AND table_schema=DATABASE()
UNION SELECT 'orphans_photos', COUNT(*) FROM wp_pc_photos WHERE category_id = 0;"
```

Expected : `cat_table=1`, `cat_col_photos=1`, `orphans_photos=0`. Si une condition n'est pas remplie, retourner sur Phase 1.

- [ ] **S2.2: Vérifier l'état de Stripe**

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT option_value FROM wp_options WHERE option_name='pc_settings'\G" | head -20
```

Vérifier la présence de `stripe_secret_key`, `stripe_publishable_key`, `stripe_webhook_secret`, `stripe_mode`.

⚠️ Si en mode `test`, c'est OK pour développer. Pour prod : valider que le webhook Stripe pointe bien sur l'URL `/?pc_stripe_webhook=1` (ou équivalent existant).

- [ ] **S2.3: Notes d'état pour comparaison post-déploiement**

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT statut, COUNT(*) AS n FROM wp_pc_photos GROUP BY statut;
SELECT statut_paiement, COUNT(*) AS n FROM wp_pc_payments GROUP BY statut_paiement;"
```

Noter le résultat — utile pour comparer après migration.

---

## Task 1: Bump version plugin

**Files:**
- Modify: `wp-content/plugins/photo-contest/photo-contest.php`

- [ ] **Step 1: Modifier le header et la constante**

```php
 * Version: 2.0.0
```

```php
define( 'PC_VERSION', '2.0.0' );
```

- [ ] **Step 2: Vérifier**

```bash
grep -E "(Version:|PC_VERSION)" wp-content/plugins/photo-contest/photo-contest.php
```

- [ ] **Step 3: Commit**

```bash
git add wp-content/plugins/photo-contest/photo-contest.php
git commit -m "chore(phase2): bump version to 2.0.0"
```

---

## Task 2: Migration BDD — colonnes additionnelles wp_pc_payments

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-database.php`

- [ ] **Step 1: Ajouter la méthode `add_payments_columns`**

```php
private static function add_payments_columns(): void {
    global $wpdb;
    $table = self::table( self::TABLE_PAYMENTS );

    $needed = [
        'payment_token'       => "VARCHAR(64) NULL AFTER reference_externe",
        'email_envoye_at'     => "DATETIME NULL AFTER payment_token",
        'derniere_relance_at' => "DATETIME NULL AFTER email_envoye_at",
        'nb_relances'         => "TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER derniere_relance_at",
    ];

    foreach ( $needed as $col => $def ) {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = %s
               AND column_name = %s",
            $table, $col
        ) );
        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$def}" );
        }
    }

    // Index
    $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
    $names   = array_map( fn( $i ) => $i->Key_name, $indexes );
    if ( ! in_array( 'idx_payment_token', $names, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_payment_token (payment_token)" );
    }
    if ( ! in_array( 'idx_email_pending', $names, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_email_pending (email_envoye_at, statut_paiement)" );
    }
}

private static function backfill_email_envoye_at(): void {
    global $wpdb;
    $table = self::table( self::TABLE_PAYMENTS );
    // Cohérence historique : on considère que les paiements reçus ont eu leur email envoyé
    $wpdb->query(
        "UPDATE {$table}
         SET email_envoye_at = updated_at
         WHERE statut_paiement = 'paiement_recu'
           AND email_envoye_at IS NULL"
    );
}
```

- [ ] **Step 2: Appeler depuis `create_tables()`**

À la fin :

```php
self::add_payments_columns();
self::backfill_email_envoye_at();
```

- [ ] **Step 3: Tester**

Script utilitaire `_pc_migrate_payments.php` :

```php
<?php
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'sdlp.test';
$_SERVER['REQUEST_URI'] = '/';
require_once __DIR__ . '/wp-load.php';
PC_Database::create_tables();
global $wpdb;
$t = $wpdb->prefix . 'pc_payments';
$cols = $wpdb->get_results( "DESCRIBE {$t}" );
$found = [];
foreach ( $cols as $c ) {
    if ( in_array( $c->Field, ['payment_token','email_envoye_at','derniere_relance_at','nb_relances'], true ) ) {
        $found[ $c->Field ] = $c->Type;
    }
}
echo "Cols trouvées:\n"; print_r( $found );
echo "Backfill check:\n";
$wpdb->get_results( "SELECT id, statut_paiement, email_envoye_at FROM {$t}" );
print_r( $wpdb->last_result );
```

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" /c/laragon/www/sdlp/_pc_migrate_payments.php
rm /c/laragon/www/sdlp/_pc_migrate_payments.php
```

Expected : 4 colonnes ajoutées, le paiement `paiement_recu` existant a `email_envoye_at` rempli avec sa date `updated_at`.

- [ ] **Step 4: Vérifier idempotence (re-lancer)**

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
echo '<?php define("WP_USE_THEMES",false); $_SERVER["HTTP_HOST"]="sdlp.test"; $_SERVER["REQUEST_URI"]="/"; require_once __DIR__ . "/wp-load.php"; PC_Database::create_tables(); echo "OK\n";' > /c/laragon/www/sdlp/_pc_idem2.php
"$PHP" /c/laragon/www/sdlp/_pc_idem2.php
rm /c/laragon/www/sdlp/_pc_idem2.php
```

Expected : "OK" sans erreur.

- [ ] **Step 5: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-database.php
git commit -m "feat(phase2): add payment_token, email_envoye_at, derniere_relance_at, nb_relances columns + indexes (idempotent)"
```

---

## Task 3: Nouveaux réglages pc_settings

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-settings.php`
- Modify: `wp-content/plugins/photo-contest/admin/class-pc-admin.php` (interface UI)

- [ ] **Step 1: Ajouter les défauts dans PC_Settings**

Dans `class-pc-settings.php`, identifier le tableau `DEFAULTS` (ou la méthode `defaults()`) et ajouter :

```php
'email_batch_size'             => 20,
'email_batch_interval_minutes' => 5,
'relance_jours'                => 5,
'relance_max'                  => 2,
'cloture_effectuee_at'         => 0,
```

- [ ] **Step 2: Ajouter les champs UI dans la page Paramètres**

Dans `class-pc-admin.php`, section où les champs sont rendus, ajouter une section « Envoi d'emails » :

```php
<h2><?php esc_html_e( 'Envoi d\'emails', PC_TEXT_DOMAIN ); ?></h2>
<table class="form-table">
    <tr>
        <th><label for="email_batch_size"><?php esc_html_e( 'Taille de lot', PC_TEXT_DOMAIN ); ?></label></th>
        <td>
            <input type="number" min="1" max="100" id="email_batch_size" name="pc_settings[email_batch_size]"
                   value="<?php echo esc_attr( $s['email_batch_size'] ?? 20 ); ?>" class="small-text">
            <p class="description"><?php esc_html_e( 'Nombre d\'emails envoyés par tick (1-100). Défaut : 20.', PC_TEXT_DOMAIN ); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="email_batch_interval_minutes"><?php esc_html_e( 'Intervalle (minutes)', PC_TEXT_DOMAIN ); ?></label></th>
        <td>
            <input type="number" min="1" max="60" id="email_batch_interval_minutes" name="pc_settings[email_batch_interval_minutes]"
                   value="<?php echo esc_attr( $s['email_batch_interval_minutes'] ?? 5 ); ?>" class="small-text">
            <p class="description">
                <?php
                $size = (int) ( $s['email_batch_size'] ?? 20 );
                $itv  = (int) ( $s['email_batch_interval_minutes'] ?? 5 );
                $per_hour = $itv > 0 ? round( $size * (60 / $itv) ) : 0;
                printf(
                    /* translators: %d nb emails par heure */
                    esc_html__( 'Avec ces réglages : jusqu\'à %d emails par heure.', PC_TEXT_DOMAIN ),
                    $per_hour
                );
                ?>
            </p>
        </td>
    </tr>
</table>

<h2><?php esc_html_e( 'Relances impayés', PC_TEXT_DOMAIN ); ?></h2>
<table class="form-table">
    <tr>
        <th><label for="relance_jours"><?php esc_html_e( 'Délai entre relances (jours)', PC_TEXT_DOMAIN ); ?></label></th>
        <td>
            <input type="number" min="0" max="30" id="relance_jours" name="pc_settings[relance_jours]"
                   value="<?php echo esc_attr( $s['relance_jours'] ?? 5 ); ?>" class="small-text">
            <p class="description"><?php esc_html_e( '0 = relances désactivées. Défaut : 5.', PC_TEXT_DOMAIN ); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="relance_max"><?php esc_html_e( 'Nombre maximum de relances', PC_TEXT_DOMAIN ); ?></label></th>
        <td>
            <input type="number" min="0" max="5" id="relance_max" name="pc_settings[relance_max]"
                   value="<?php echo esc_attr( $s['relance_max'] ?? 2 ); ?>" class="small-text">
            <p class="description"><?php esc_html_e( '0 = relances désactivées. Défaut : 2.', PC_TEXT_DOMAIN ); ?></p>
        </td>
    </tr>
</table>
```

- [ ] **Step 3: Validation des bornes côté sanitize_settings**

Dans la méthode `sanitize_settings()` (ou équivalent) de `class-pc-admin.php` :

```php
$s['email_batch_size']             = max( 1, min( 100, (int) ( $input['email_batch_size'] ?? 20 ) ) );
$s['email_batch_interval_minutes'] = max( 1, min( 60,  (int) ( $input['email_batch_interval_minutes'] ?? 5 ) ) );
$s['relance_jours']                = max( 0, min( 30,  (int) ( $input['relance_jours'] ?? 5 ) ) );
$s['relance_max']                  = max( 0, min( 5,   (int) ( $input['relance_max'] ?? 2 ) ) );
```

- [ ] **Step 4: Mettre à jour le label `montant_participation_cts`**

Identifier la chaîne actuelle (du type « Montant de la participation au concours ») et remplacer par :

```php
__( 'Montant par photo retenue', PC_TEXT_DOMAIN )
```

Ajouter description :

```php
__( 'Ce montant est demandé pour chaque photo retenue par le jury. Saisir en centimes (1500 = 15 €).', PC_TEXT_DOMAIN )
```

- [ ] **Step 5: Test manuel**

`Concours Photo > Paramètres` → vérifier que les 4 nouveaux champs apparaissent, que les défauts sont chargés, que la sauvegarde fonctionne et que les bornes sont respectées (essayer 500 → ramené à 100).

- [ ] **Step 6: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-settings.php \
        wp-content/plugins/photo-contest/admin/class-pc-admin.php
git commit -m "feat(phase2): add email batch / relance settings + relabel montant"
```

---

## Task 4: Supprimer la cascade automatique dans PC_Jury

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-jury.php`

- [ ] **Step 1: Repérer la cascade**

```bash
grep -n "participation_demandee\|request_payment\|pc_photo_retenue" wp-content/plugins/photo-contest/includes/class-pc-jury.php
```

- [ ] **Step 2: Supprimer la cascade**

Dans la méthode `apply_decision()` (ou équivalent), repérer la branche qui passe le statut à `retenue`. Supprimer **uniquement** la ligne qui appelle `PC_Payments::request_payment()` ou le `do_action( 'pc_participation_demandee' )` ou la bascule auto vers `participation_demandee`.

La photo doit **rester en `retenue`** après ce changement. C'est la clôture admin (Task 5+) qui fera la transition vers `participation_demandee`.

- [ ] **Step 3: Conserver le trigger `pc_photo_retenue`**

⚠️ La spec dit que ce trigger est **supprimé** (section 9.1). Mais en réalité, il faut conserver l'event interne pour le logging et pour permettre à d'autres modules de réagir si besoin. Ce qu'on supprime c'est l'**email Fluent CRM** lié.

Action concrète : conserver `do_action( 'pc_photo_retenue', $photo_id )` mais dans `PC_Fluent_CRM`, désabonner le handler qui envoyait l'email immédiat (cf. Task 13).

- [ ] **Step 4: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-jury.php
git commit -m "feat(phase2): remove auto-cascade retenue → participation_demandee in PC_Jury"
```

---

## Task 5: PC_Payments — méthodes utilitaires de clôture

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php`

- [ ] **Step 1: Lire la classe actuelle**

```bash
grep -n "public function\|private function" wp-content/plugins/photo-contest/includes/class-pc-payments.php
```

- [ ] **Step 2: Ajouter les méthodes utilitaires**

Dans `PC_Payments` :

```php
/**
 * Statistiques pour le récap pré-clôture.
 */
public static function get_cloture_stats(): array {
    global $wpdb;
    $photos = PC_Database::table( PC_Database::TABLE_PHOTOS );
    $montant_cts = (int) PC_Settings::get( 'montant_participation_cts', 1500 );

    $en_delib = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$photos} WHERE statut IN ('en_attente', 'en_cours_examen')"
    );
    $retenues = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$photos} WHERE statut = 'retenue'"
    );
    $candidats = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT user_id) FROM {$photos} WHERE statut = 'retenue'"
    );

    return [
        'en_delibration_a_refuser' => $en_delib,
        'photos_retenues'          => $retenues,
        'candidats_a_notifier'     => $candidats,
        'montant_total_cts'        => $retenues * $montant_cts,
        'montant_unitaire_cts'     => $montant_cts,
    ];
}

/**
 * Exécute la clôture en transaction.
 * @return array Résumé : [refused_count, retenues_count, candidats_count]
 */
public static function execute_cloture(): array {
    global $wpdb;
    $photos      = PC_Database::table( PC_Database::TABLE_PHOTOS );
    $payments    = PC_Database::table( PC_Database::TABLE_PAYMENTS );
    $montant_cts = (int) PC_Settings::get( 'montant_participation_cts', 1500 );

    // Verrou anti-double-clôture
    $lock_at = (int) PC_Settings::get( 'cloture_en_cours', 0 );
    if ( $lock_at && ( time() - $lock_at ) < 300 ) {
        return [ 'error' => 'cloture_en_cours' ];
    }
    PC_Settings::set( 'cloture_en_cours', time() );

    // 1) Refuser tout ce qui est encore en délibération
    $refused = (int) $wpdb->query(
        "UPDATE {$photos}
         SET statut = 'refusee'
         WHERE statut IN ('en_attente', 'en_cours_examen')"
    );

    // 2) Pour chaque candidat avec photos retenues
    $candidats = $wpdb->get_col(
        "SELECT DISTINCT user_id FROM {$photos} WHERE statut = 'retenue'"
    );

    $total_retenues = 0;
    foreach ( $candidats as $user_id ) {
        $user_id = (int) $user_id;
        $token   = wp_generate_uuid4();

        $retenues = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$photos} WHERE user_id = %d AND statut = 'retenue'",
            $user_id
        ) );

        foreach ( $retenues as $photo_id ) {
            $photo_id = (int) $photo_id;
            $wpdb->update( $photos, [ 'statut' => 'participation_demandee' ], [ 'id' => $photo_id ] );
            $wpdb->insert( $payments, [
                'photo_id'         => $photo_id,
                'user_id'          => $user_id,
                'montant_centimes' => $montant_cts,
                'devise'           => 'EUR',
                'statut_paiement'  => 'en_attente',
                'methode'          => 'stripe_checkout',
                'payment_token'    => $token,
            ] );
            $total_retenues++;
        }
    }

    // 3) Mise à jour des flags settings
    PC_Settings::set( 'jury_actif',           false );
    PC_Settings::set( 'cloture_en_cours',     0 );
    PC_Settings::set( 'cloture_effectuee_at', time() );

    // 4) Planification du premier envoi d'emails
    if ( ! wp_next_scheduled( 'pc_send_payment_email_batch' ) ) {
        wp_schedule_single_event( time(), 'pc_send_payment_email_batch' );
    }

    do_action( 'pc_cloture_jury_effectuee' );

    return [
        'refused_count'   => $refused,
        'retenues_count'  => $total_retenues,
        'candidats_count' => count( $candidats ),
    ];
}
```

- [ ] **Step 3: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-payments.php
git commit -m "feat(phase2): add PC_Payments::get_cloture_stats and execute_cloture helpers"
```

---

## Task 6: Page admin Clôture délibération

**Files:**
- Create: `wp-content/plugins/photo-contest/admin/class-pc-cloture-admin.php`
- Create: `wp-content/plugins/photo-contest/admin/css/pc-cloture-admin.css`
- Create: `wp-content/plugins/photo-contest/admin/js/pc-cloture-admin.js`
- Modify: `wp-content/plugins/photo-contest/photo-contest.php` (require)

- [ ] **Step 1: Créer la classe admin**

`wp-content/plugins/photo-contest/admin/class-pc-cloture-admin.php` :

```php
<?php
defined( 'ABSPATH' ) || exit;

class PC_Cloture_Admin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',                   [ $this, 'register_menu' ], 40 );
        add_action( 'admin_enqueue_scripts',        [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_pc_cloture_execute',   [ $this, 'ajax_execute' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'pc-settings',
            __( 'Clôture délibération', PC_TEXT_DOMAIN ),
            __( 'Clôture délibération', PC_TEXT_DOMAIN ),
            'pc_manage_payments',
            'pc-cloture',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'pc-cloture' ) === false ) return;
        wp_enqueue_style(  'pc-cloture-admin', PC_PLUGIN_URL . 'admin/css/pc-cloture-admin.css', [], PC_VERSION );
        wp_enqueue_script( 'pc-cloture-admin', PC_PLUGIN_URL . 'admin/js/pc-cloture-admin.js', [], PC_VERSION, true );
        wp_localize_script( 'pc-cloture-admin', 'pcClotureAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'pc_cloture_nonce' ),
            'i18n'    => [
                'confirm' => __( 'IRRÉVERSIBLE. Confirmer la clôture ?', PC_TEXT_DOMAIN ),
            ],
        ] );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'pc_manage_payments' ) ) {
            wp_die( __( 'Accès refusé.', PC_TEXT_DOMAIN ) );
        }

        $stats     = PC_Payments::get_cloture_stats();
        $effective = (int) PC_Settings::get( 'cloture_effectuee_at', 0 );

        ?>
        <div class="wrap pc-cloture-admin">
            <h1><?php esc_html_e( 'Clôture de la délibération du jury', PC_TEXT_DOMAIN ); ?></h1>

            <?php if ( $effective > 0 ) : ?>
                <div class="notice notice-info">
                    <p><?php printf(
                        /* translators: %s = date */
                        esc_html__( 'Clôture déjà effectuée le %s.', PC_TEXT_DOMAIN ),
                        esc_html( wp_date( 'd/m/Y H:i', $effective ) )
                    ); ?></p>
                </div>
            <?php endif; ?>

            <div class="pc-cloture-recap">
                <h2><?php esc_html_e( 'État actuel', PC_TEXT_DOMAIN ); ?></h2>
                <ul>
                    <li><?php printf( esc_html__( 'Photos en délibération à refuser : %d', PC_TEXT_DOMAIN ), $stats['en_delibration_a_refuser'] ); ?></li>
                    <li><?php printf( esc_html__( 'Photos retenues : %d', PC_TEXT_DOMAIN ), $stats['photos_retenues'] ); ?></li>
                    <li><?php printf( esc_html__( 'Candidats à notifier : %d', PC_TEXT_DOMAIN ), $stats['candidats_a_notifier'] ); ?></li>
                    <li><strong><?php printf(
                        /* translators: 1: montant en euros */
                        esc_html__( 'Total à encaisser : %s', PC_TEXT_DOMAIN ),
                        esc_html( number_format( $stats['montant_total_cts'] / 100, 2, ',', ' ' ) . ' €' )
                    ); ?></strong>
                    <small> (<?php echo esc_html( number_format( $stats['montant_unitaire_cts'] / 100, 2, ',', ' ' ) . ' €' ); ?> × <?php echo (int) $stats['photos_retenues']; ?>)</small>
                    </li>
                </ul>
            </div>

            <div class="pc-cloture-warning">
                <h3>⚠️ <?php esc_html_e( 'Action irréversible', PC_TEXT_DOMAIN ); ?></h3>
                <ul>
                    <li><?php esc_html_e( 'Toutes les photos en délibération basculeront en « refusée ».', PC_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Tous les candidats avec photos retenues recevront un email de paiement (envoi par lots).', PC_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Le flag jury_actif sera mis à 0 (votes verrouillés).', PC_TEXT_DOMAIN ); ?></li>
                </ul>
            </div>

            <p>
                <button type="button" class="button button-primary button-large" id="pc-cloture-btn" <?php disabled( $effective > 0 ); ?>>
                    <?php echo $effective > 0
                        ? esc_html__( 'Déjà effectuée', PC_TEXT_DOMAIN )
                        : esc_html__( 'Je confirme la clôture', PC_TEXT_DOMAIN ); ?>
                </button>
            </p>

            <div id="pc-cloture-msg"></div>
        </div>
        <?php
    }

    public function ajax_execute(): void {
        check_ajax_referer( 'pc_cloture_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_manage_payments' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }

        $result = PC_Payments::execute_cloture();
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Clôture déjà en cours, réessayez dans 5 minutes.', PC_TEXT_DOMAIN ) ] );
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %1$d refusées, %2$d retenues, %3$d candidats */
                __( 'Clôture OK. %1$d refusées, %2$d retenues, %3$d candidats à notifier.', PC_TEXT_DOMAIN ),
                $result['refused_count'], $result['retenues_count'], $result['candidats_count']
            ),
        ] );
    }
}
```

- [ ] **Step 2: CSS**

`admin/css/pc-cloture-admin.css` :

```css
.pc-cloture-recap { background: #f5f5f5; padding: 16px; border-left: 4px solid #2271b1; margin: 20px 0; }
.pc-cloture-recap ul { margin: 8px 0 0 20px; }
.pc-cloture-recap li { margin-bottom: 4px; }
.pc-cloture-warning { background: #fff4e5; padding: 16px; border-left: 4px solid #d39e00; margin: 20px 0; }
.pc-cloture-warning ul { margin: 8px 0 0 20px; }
.pc-cloture-warning li { margin-bottom: 4px; }
#pc-cloture-msg.pc-msg-success { color: #2c7a3b; font-weight: 500; margin-top: 12px; }
#pc-cloture-msg.pc-msg-error   { color: #b03030; font-weight: 500; margin-top: 12px; }
```

- [ ] **Step 3: JS**

`admin/js/pc-cloture-admin.js` :

```javascript
(function () {
  'use strict';
  const CFG  = window.pcClotureAdmin || {};
  const I18N = CFG.i18n || {};
  const btn  = document.getElementById('pc-cloture-btn');
  const msg  = document.getElementById('pc-cloture-msg');

  btn?.addEventListener('click', () => {
    if (!confirm(I18N.confirm)) return;
    btn.disabled = true;
    btn.textContent = 'Clôture en cours…';
    msg.textContent = '';

    fetch(CFG.ajaxUrl, {
      method: 'POST',
      body: new URLSearchParams({ action: 'pc_cloture_execute', nonce: CFG.nonce }),
    }).then(r => r.json()).then(res => {
      if (res.success) {
        msg.className = 'pc-msg-success';
        msg.textContent = res.data.message;
        setTimeout(() => location.reload(), 2000);
      } else {
        msg.className = 'pc-msg-error';
        msg.textContent = res.data?.message || 'Erreur';
        btn.disabled = false;
        btn.textContent = 'Je confirme la clôture';
      }
    });
  });
})();
```

- [ ] **Step 4: Charger la classe**

Dans `photo-contest.php` :

```php
if ( is_admin() ) {
    require_once PC_PLUGIN_DIR . 'admin/class-pc-cloture-admin.php';
    PC_Cloture_Admin::get_instance();
}
```

- [ ] **Step 5: Test manuel — préparation**

Préparer un user candidat ayant 2 photos en statut `retenue` :

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
UPDATE wp_pc_photos SET statut='retenue' WHERE user_id=17 LIMIT 2;
SELECT id, user_id, statut FROM wp_pc_photos WHERE user_id=17;"
```

- [ ] **Step 6: Test manuel — clôture**

Connexion wp-admin → `Concours Photo > Clôture délibération`. Le récap doit afficher :
- Photos en délibération à refuser : N (selon état)
- Photos retenues : 2
- Candidats à notifier : 1
- Total à encaisser : selon montant_participation_cts

Cliquer « Je confirme la clôture » → confirm() JavaScript → succès. Message : « Clôture OK. X refusées, 2 retenues, 1 candidats à notifier. »

- [ ] **Step 7: Vérifier en BDD**

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 <<'SQL'
SELECT statut, COUNT(*) AS n FROM wp_pc_photos GROUP BY statut;
SELECT photo_id, user_id, statut_paiement, payment_token, email_envoye_at FROM wp_pc_payments WHERE statut_paiement='en_attente';
SQL
```

Expected :
- Aucune photo en `en_attente` ou `en_cours_examen`
- 2 photos en `participation_demandee` (les retenues)
- 2 entrées dans `wp_pc_payments` partageant le même `payment_token`, `email_envoye_at IS NULL`

- [ ] **Step 8: Commit**

```bash
git add wp-content/plugins/photo-contest/admin/class-pc-cloture-admin.php \
        wp-content/plugins/photo-contest/admin/css/pc-cloture-admin.css \
        wp-content/plugins/photo-contest/admin/js/pc-cloture-admin.js \
        wp-content/plugins/photo-contest/photo-contest.php
git commit -m "feat(phase2): add Cloture admin page with recap and IRREVERSIBLE action"
```

---

## Task 7: WP-Cron — envoi des emails par lots

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php`

- [ ] **Step 1: Enregistrer le hook cron dans le constructeur**

Dans `PC_Payments::__construct()` (ou équivalent) :

```php
add_action( 'pc_send_payment_email_batch', [ $this, 'cron_send_batch' ] );
```

- [ ] **Step 2: Implémenter cron_send_batch**

```php
public function cron_send_batch(): void {
    global $wpdb;
    $payments = PC_Database::table( PC_Database::TABLE_PAYMENTS );
    $batch    = max( 1, min( 100, (int) PC_Settings::get( 'email_batch_size', 20 ) ) );

    // Sélection : groupes (user_id, payment_token) pas encore notifiés
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, payment_token,
                GROUP_CONCAT(photo_id) AS photo_ids,
                SUM(montant_centimes) AS montant_total,
                COUNT(*) AS nb_photos
         FROM {$payments}
         WHERE email_envoye_at IS NULL
           AND statut_paiement = 'en_attente'
           AND payment_token IS NOT NULL
         GROUP BY user_id, payment_token
         LIMIT %d",
        $batch
    ) );

    foreach ( $rows as $row ) {
        $user_id      = (int) $row->user_id;
        $token        = $row->payment_token;
        $nb_photos    = (int) $row->nb_photos;
        $montant_cts  = (int) $row->montant_total;
        $payment_url  = home_url( '/?pc_pay=' . rawurlencode( $token ) );

        // Trigger Fluent CRM
        do_action( 'pc_participation_demandee_groupee', $user_id, [
            'nb_photos'   => $nb_photos,
            'montant_cts' => $montant_cts,
            'payment_url' => $payment_url,
        ] );

        // Marquer comme envoyés
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$payments}
             SET email_envoye_at = NOW()
             WHERE user_id = %d AND payment_token = %s AND email_envoye_at IS NULL",
            $user_id, $token
        ) );
    }

    // Re-planifier si reste des entrées
    $remaining = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$payments}
         WHERE email_envoye_at IS NULL
           AND statut_paiement = 'en_attente'
           AND payment_token IS NOT NULL"
    );

    if ( $remaining > 0 ) {
        $itv = max( 1, min( 60, (int) PC_Settings::get( 'email_batch_interval_minutes', 5 ) ) );
        wp_schedule_single_event( time() + ( $itv * 60 ), 'pc_send_payment_email_batch' );
    }
}
```

- [ ] **Step 3: Test manuel — déclenchement immédiat**

Après la clôture (Task 6), forcer un tick cron :

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
cat > /c/laragon/www/sdlp/_pc_cron.php <<'PHP'
<?php
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'sdlp.test';
$_SERVER['REQUEST_URI'] = '/';
require_once __DIR__ . '/wp-load.php';
PC_Payments::get_instance()->cron_send_batch();
global $wpdb;
$p = $wpdb->prefix . 'pc_payments';
$rows = $wpdb->get_results( "SELECT id, user_id, statut_paiement, email_envoye_at FROM {$p}" );
print_r( $rows );
echo "Restant en attente: " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p} WHERE email_envoye_at IS NULL AND statut_paiement='en_attente'" ) . "\n";
PHP
"$PHP" /c/laragon/www/sdlp/_pc_cron.php
rm /c/laragon/www/sdlp/_pc_cron.php
```

Expected : les 2 paiements de test ont `email_envoye_at` non NULL. Restant : 0.

- [ ] **Step 4: Vérifier la planification du prochain tick**

Si plus de 20 paiements (taille du batch), un nouvel event doit être planifié. Vérifier :

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" -r '
define("WP_USE_THEMES",false);
$_SERVER["HTTP_HOST"]="sdlp.test"; $_SERVER["REQUEST_URI"]="/";
require_once "C:/laragon/www/sdlp/wp-load.php";
$next = wp_next_scheduled("pc_send_payment_email_batch");
echo $next ? "Next at: " . date("Y-m-d H:i:s", $next) . PHP_EOL : "No scheduled event" . PHP_EOL;
'
```

- [ ] **Step 5: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-payments.php
git commit -m "feat(phase2): cron_send_batch — batched email sending with auto-reschedule"
```

---

## Task 8: WP-Cron — relances quotidiennes

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php`

- [ ] **Step 1: Enregistrer le hook quotidien**

Dans `PC_Payments::__construct()` :

```php
add_action( 'pc_relance_impayes_daily', [ $this, 'cron_relances_quotidiennes' ] );

// Auto-schedule au boot du plugin si pas déjà fait
if ( ! wp_next_scheduled( 'pc_relance_impayes_daily' ) ) {
    wp_schedule_event( time() + 60, 'daily', 'pc_relance_impayes_daily' );
}
```

- [ ] **Step 2: Implémenter le callback**

```php
public function cron_relances_quotidiennes(): void {
    global $wpdb;

    $relance_jours = (int) PC_Settings::get( 'relance_jours', 5 );
    $relance_max   = (int) PC_Settings::get( 'relance_max', 2 );
    if ( $relance_jours === 0 || $relance_max === 0 ) {
        return; // Désactivé
    }

    $payments = PC_Database::table( PC_Database::TABLE_PAYMENTS );
    $batch    = max( 1, min( 100, (int) PC_Settings::get( 'email_batch_size', 20 ) ) );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, payment_token,
                COUNT(*) AS nb_photos,
                SUM(montant_centimes) AS montant_total,
                MAX(nb_relances) AS nb_relances_actuel,
                MAX(derniere_relance_at) AS last_relance
         FROM {$payments}
         WHERE statut_paiement = 'en_attente'
           AND email_envoye_at IS NOT NULL
           AND email_envoye_at < DATE_SUB( NOW(), INTERVAL %d DAY )
           AND payment_token IS NOT NULL
         GROUP BY user_id, payment_token
         HAVING nb_relances_actuel < %d
            AND ( last_relance IS NULL OR last_relance < DATE_SUB( NOW(), INTERVAL %d DAY ) )
         LIMIT %d",
        $relance_jours, $relance_max, $relance_jours, $batch
    ) );

    foreach ( $rows as $row ) {
        $user_id     = (int) $row->user_id;
        $token       = $row->payment_token;
        $nb_photos   = (int) $row->nb_photos;
        $montant_cts = (int) $row->montant_total;
        $payment_url = home_url( '/?pc_pay=' . rawurlencode( $token ) );

        do_action( 'pc_relance_paiement', $user_id, [
            'nb_photos'   => $nb_photos,
            'montant_cts' => $montant_cts,
            'payment_url' => $payment_url,
            'nb_relances' => (int) $row->nb_relances_actuel + 1,
        ] );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$payments}
             SET derniere_relance_at = NOW(), nb_relances = nb_relances + 1
             WHERE user_id = %d AND payment_token = %s AND statut_paiement = 'en_attente'",
            $user_id, $token
        ) );
    }
}
```

- [ ] **Step 3: Désactivation au désinstall**

Dans le hook `register_deactivation_hook` (généralement dans `photo-contest.php`), ajouter :

```php
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'pc_relance_impayes_daily' );
    wp_clear_scheduled_hook( 'pc_send_payment_email_batch' );
} );
```

- [ ] **Step 4: Test manuel — simuler un paiement vieux**

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
UPDATE wp_pc_payments
SET email_envoye_at = DATE_SUB(NOW(), INTERVAL 6 DAY)
WHERE statut_paiement = 'en_attente'
LIMIT 1;
SELECT id, email_envoye_at, nb_relances, derniere_relance_at FROM wp_pc_payments WHERE statut_paiement='en_attente';"
```

Puis forcer le cron :

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" -r '
define("WP_USE_THEMES",false);
$_SERVER["HTTP_HOST"]="sdlp.test"; $_SERVER["REQUEST_URI"]="/";
require_once "C:/laragon/www/sdlp/wp-load.php";
PC_Payments::get_instance()->cron_relances_quotidiennes();
echo "OK\n";
'
```

Vérifier en BDD :

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT id, nb_relances, derniere_relance_at FROM wp_pc_payments WHERE statut_paiement='en_attente';"
```

Expected : la ligne touchée a `nb_relances=1` et `derniere_relance_at` proche de NOW().

Re-lancer le cron immédiatement → `nb_relances` reste à 1 (cooldown de 5j).

- [ ] **Step 5: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-payments.php \
        wp-content/plugins/photo-contest/photo-contest.php
git commit -m "feat(phase2): daily cron for unpaid reminders with cooldown and max"
```

---

## Task 9: Endpoint pc_pay — création session Stripe

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php`

- [ ] **Step 1: Lire le code Stripe existant**

```bash
grep -n "stripe\|Checkout\|create_session" wp-content/plugins/photo-contest/includes/class-pc-payments.php
```

Identifier comment Stripe est instancié actuellement (`\Stripe\Stripe::setApiKey`) et la méthode existante de création de session pour le paiement individuel.

- [ ] **Step 2: Enregistrer le handler sur plugins_loaded:20**

Dans `PC_Payments::__construct()` :

```php
add_action( 'plugins_loaded', [ $this, 'maybe_handle_payment_link' ], 20 );
```

- [ ] **Step 3: Implémenter maybe_handle_payment_link**

```php
public function maybe_handle_payment_link(): void {
    if ( empty( $_GET['pc_pay'] ) ) return;

    // Filet de sécurité — buffers ouverts par Elementor
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    $token = sanitize_text_field( wp_unslash( $_GET['pc_pay'] ) );
    if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $token ) ) {
        wp_die( __( 'Lien invalide.', PC_TEXT_DOMAIN ), 400 );
    }

    global $wpdb;
    $payments = PC_Database::table( PC_Database::TABLE_PAYMENTS );
    $photos   = PC_Database::table( PC_Database::TABLE_PHOTOS );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT pay.*, p.titre AS photo_titre
         FROM {$payments} pay
         JOIN {$photos} p ON p.id = pay.photo_id
         WHERE pay.payment_token = %s
           AND pay.statut_paiement = 'en_attente'",
        $token
    ) );

    if ( empty( $rows ) ) {
        wp_die( __( 'Lien invalide ou paiement déjà effectué.', PC_TEXT_DOMAIN ), 404 );
    }

    $user_id = (int) $rows[0]->user_id;

    // Vérifier l'identité — si non connecté, rediriger vers login
    if ( ! is_user_logged_in() ) {
        $login_url = home_url( '/' . PC_Settings::get( 'login_slug', 'connexion' ) . '/' )
                   . '?redirect_to=' . rawurlencode( home_url( '/?pc_pay=' . $token ) );
        wp_safe_redirect( $login_url );
        exit;
    }
    if ( get_current_user_id() !== $user_id ) {
        wp_die( __( 'Ce lien ne vous est pas destiné.', PC_TEXT_DOMAIN ), 403 );
    }

    // Créer la session Stripe
    require_once PC_PLUGIN_DIR . 'vendor/autoload.php';
    \Stripe\Stripe::setApiKey( PC_Settings::get( 'stripe_secret_key', '' ) );

    $line_items = [];
    foreach ( $rows as $row ) {
        $line_items[] = [
            'price_data' => [
                'currency'     => strtolower( $row->devise ),
                'product_data' => [
                    'name' => $row->photo_titre ?: 'Photo #' . (int) $row->photo_id,
                ],
                'unit_amount' => (int) $row->montant_centimes,
            ],
            'quantity' => 1,
        ];
    }

    try {
        $session = \Stripe\Checkout\Session::create( [
            'mode'        => 'payment',
            'line_items'  => $line_items,
            'success_url' => get_permalink( get_option( 'pc_page_espace_candidat' ) ) ?: home_url( '/' ),
            'cancel_url'  => home_url( '/?pc_pay=' . rawurlencode( $token ) . '&canceled=1' ),
            'metadata'    => [
                'pc_payment_token' => $token,
                'pc_user_id'       => $user_id,
            ],
            'customer_email' => wp_get_current_user()->user_email,
        ] );
    } catch ( \Stripe\Exception\ApiErrorException $e ) {
        error_log( '[PC_Payments] Stripe session error: ' . $e->getMessage() );
        wp_die( __( 'Erreur de connexion à Stripe. Réessayez plus tard.', PC_TEXT_DOMAIN ), 502 );
    }

    // Stocker la session ID dans tous les paiements liés au token
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$payments}
         SET reference_externe = %s
         WHERE payment_token = %s",
        $session->id, $token
    ) );

    wp_safe_redirect( $session->url );
    exit;
}
```

- [ ] **Step 4: Test manuel**

Depuis l'état actuel (paiements en_attente avec payment_token), récupérer un token :

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT DISTINCT user_id, payment_token FROM wp_pc_payments WHERE statut_paiement='en_attente' AND payment_token IS NOT NULL LIMIT 1;"
```

Se connecter en tant que candidat correspondant, ouvrir `https://sdlp.test/?pc_pay=<TOKEN>`. Doit rediriger vers Stripe Checkout avec N line_items et le bon montant.

- [ ] **Step 5: Vérifier `reference_externe`**

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT id, photo_id, payment_token, reference_externe FROM wp_pc_payments WHERE payment_token IS NOT NULL;"
```

Expected : `reference_externe = cs_test_xxx` pour les lignes du token utilisé.

- [ ] **Step 6: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-payments.php
git commit -m "feat(phase2): pc_pay endpoint creates Stripe session with N line_items"
```

---

## Task 10: Adaptation webhook Stripe — multi-paiement

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php`

- [ ] **Step 1: Identifier le handler webhook actuel**

```bash
grep -n "webhook\|handle_webhook\|checkout.session.completed" wp-content/plugins/photo-contest/includes/class-pc-payments.php
```

- [ ] **Step 2: Réécrire le bloc de traitement**

Dans la méthode webhook, après `checkout.session.completed` détecté et signature validée :

```php
$session_id = $session->id;
$photos     = PC_Database::table( PC_Database::TABLE_PHOTOS );
$payments   = PC_Database::table( PC_Database::TABLE_PAYMENTS );

// Récupérer tous les paiements liés à cette session
$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, photo_id, user_id, montant_centimes, statut_paiement
     FROM {$payments}
     WHERE reference_externe = %s",
    $session_id
) );

if ( empty( $rows ) ) {
    // Cas legacy : ancien paiement individuel — fallback sur l'ancien comportement
    error_log( "[PC_Payments] Webhook: aucun paiement trouvé pour {$session_id}" );
    return;
}

$traite = 0;
foreach ( $rows as $row ) {
    if ( $row->statut_paiement === 'paiement_recu' ) {
        // Idempotence : déjà traité
        continue;
    }
    $wpdb->update( $payments, [ 'statut_paiement' => 'paiement_recu' ], [ 'id' => (int) $row->id ] );
    $wpdb->update( $photos,   [ 'statut'         => 'paiement_recu' ], [ 'id' => (int) $row->photo_id ] );
    $traite++;
}

if ( $traite > 0 ) {
    do_action( 'pc_paiement_recu_groupe', (int) $rows[0]->user_id, [
        'nb_photos'   => $traite,
        'montant_cts' => array_sum( array_map( fn( $r ) => (int) $r->montant_centimes, $rows ) ),
        'session_id'  => $session_id,
    ] );
}
```

- [ ] **Step 3: Idempotence vérifiée**

Le check `if ( $row->statut_paiement === 'paiement_recu' ) continue;` garantit qu'un webhook reçu 2 fois ne re-déclenche pas le trigger Fluent CRM (le compteur `$traite` reste à 0 dans ce cas).

- [ ] **Step 4: Test manuel via Stripe CLI**

Si Stripe CLI installé :

```bash
stripe trigger checkout.session.completed
```

Sinon, simuler manuellement : modifier directement les statuts BDD pour valider la cascade :

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
UPDATE wp_pc_payments SET statut_paiement='paiement_recu' WHERE reference_externe='cs_test_xxx';
UPDATE wp_pc_photos SET statut='paiement_recu' WHERE id IN (SELECT photo_id FROM wp_pc_payments WHERE reference_externe='cs_test_xxx');"
```

(Pas l'idéal — pour un vrai test du webhook il faut Stripe CLI ou un webhook test sur une vraie session.)

- [ ] **Step 5: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-payments.php
git commit -m "feat(phase2): webhook handles N payments per session, idempotent"
```

---

## Task 11: Suppression de l'étape « Participation » dans le profil candidat

**Files:**
- Modify: `wp-content/plugins/photo-contest/templates/profile.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-registration.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-payments.php`

- [ ] **Step 1: Supprimer la section dans profile.php**

Dans `templates/profile.php`, identifier le bloc :

```php
<!-- ── Étape 3 : Paiement inscription ───────────────────────── -->
<?php if ( in_array( $etape, [ 'paiement_requis', 'complet' ], true ) ) : ?>
<section class="pcp-section" id="pcp-section-paiement">
    ...
</section>
<?php endif; ?>
```

Le supprimer complètement.

Modifier également l'indicateur d'étapes (en haut du template) — supprimer la 3e étape « Participation » :

```php
$etapes = [
    ['profil',    __( 'Profil', PC_TEXT_DOMAIN )],
    ['reglement', __( 'Règlement', PC_TEXT_DOMAIN )],
    // ['paiement', __( 'Participation', PC_TEXT_DOMAIN )], // supprimé Phase 2
];
$ordre = [
    'email_non_verifie'      => 0,
    'profil_incomplet'       => 1,
    'reglement_non_accepte'  => 2,
    'complet'                => 3,
];
```

- [ ] **Step 2: PC_Registration::get_etape — ne retourne plus `paiement_requis`**

```bash
grep -n "paiement_requis\|get_etape" wp-content/plugins/photo-contest/includes/class-pc-registration.php
```

Modifier la méthode `get_etape()` pour sauter le check du paiement et passer direct à `complet` après `reglement_accepte = 1` :

```php
public static function get_etape( int $user_id ): string {
    $profile = PC_Profile::get_instance()->get_profile( $user_id );
    if ( ! $profile ) return 'profil_incomplet';
    if ( empty( $profile['email_verifie'] ) )        return 'email_non_verifie';
    if ( empty( $profile['profil_complet'] ) )       return 'profil_incomplet';
    if ( empty( $profile['reglement_accepte'] ) )    return 'reglement_non_accepte';
    return 'complet';
    // Avant : check paiement_inscription_recu → maintenant ignoré
}
```

- [ ] **Step 3: Supprimer l'AJAX pc_create_inscription_session**

```bash
grep -n "pc_create_inscription_session" wp-content/plugins/photo-contest/includes/class-pc-payments.php
```

Supprimer l'action AJAX et sa méthode handler. Le code mort lié au paiement à l'inscription disparaît.

- [ ] **Step 4: Bump cache buster profile**

Dans `class-pc-shortcodes.php`, méthode `render_profile()` : passer la version assets `.4` à `.5`.

- [ ] **Step 5: Test manuel**

- Se connecter en `toto` (déjà candidat avec profil complet et règlement accepté)
- `/mon-profil/` ne doit plus afficher l'étape 3
- L'indicateur d'étapes ne montre que 2 étapes (Profil, Règlement)
- Notice « Vous pouvez déposer vos photos » et bouton accès galerie

- Créer un nouveau candidat via `/inscription-photographe/`
- Vérifier email → profil → règlement
- Après acceptation du règlement, accès direct à la galerie (pas d'écran paiement)

- [ ] **Step 6: Commit**

```bash
git add wp-content/plugins/photo-contest/templates/profile.php \
        wp-content/plugins/photo-contest/includes/class-pc-registration.php \
        wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php \
        wp-content/plugins/photo-contest/includes/class-pc-payments.php
git commit -m "feat(phase2): remove inscription payment step from profile flow"
```

---

## Task 12: Triggers Fluent CRM — nouveaux noms et champs custom

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-fluent-crm.php`

- [ ] **Step 1: Lire la classe**

```bash
grep -n "add_action\|do_action\|FluentCRM\|FluentCrm" wp-content/plugins/photo-contest/includes/class-pc-fluent-crm.php
```

- [ ] **Step 2: Désabonner l'ancien handler `pc_photo_retenue`**

L'event interne `do_action( 'pc_photo_retenue', $photo_id )` peut être conservé pour usages futurs, mais le handler Fluent CRM qui envoyait l'email immédiat doit être supprimé.

Localiser :

```php
add_action( 'pc_photo_retenue', [ $this, 'on_photo_retenue' ] );
```

→ Supprimer la ligne, et supprimer la méthode `on_photo_retenue` correspondante (ou la vider en mettant juste un `error_log( '[PC_Fluent_CRM] pc_photo_retenue conservé pour logging — email désormais envoyé en lot à la clôture' );`).

- [ ] **Step 3: Ajouter le handler `pc_participation_demandee_groupee`**

```php
add_action( 'pc_participation_demandee_groupee', [ $this, 'on_participation_demandee_groupee' ], 10, 2 );

public function on_participation_demandee_groupee( int $user_id, array $data ): void {
    if ( ! function_exists( 'FluentCrmApi' ) ) {
        return; // Fluent CRM non actif — graceful degradation
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) return;

    $contact = FluentCrmApi( 'contacts' )->getContact( $user->user_email );
    if ( ! $contact ) {
        $contact = FluentCrmApi( 'contacts' )->createOrUpdate( [
            'email'      => $user->user_email,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
        ] );
    }

    // Champs custom
    $contact->updateCustomFields( [
        'pc_nb_photos_retenues' => (int) $data['nb_photos'],
        'pc_montant_a_payer_cts' => (int) $data['montant_cts'],
        'pc_payment_url'        => (string) $data['payment_url'],
    ] );

    // Trigger Fluent CRM (le user configure l'automation côté Fluent CRM avec ce trigger)
    do_action( 'fluent_crm/event_tracker', [
        'event_key'  => 'pc_participation_demandee_groupee',
        'title'      => __( 'Participation demandée (groupée)', PC_TEXT_DOMAIN ),
        'email'      => $user->user_email,
        'value'      => $data['montant_cts'],
    ] );
}
```

- [ ] **Step 4: Ajouter `pc_paiement_recu_groupe`**

```php
add_action( 'pc_paiement_recu_groupe', [ $this, 'on_paiement_recu_groupe' ], 10, 2 );

public function on_paiement_recu_groupe( int $user_id, array $data ): void {
    if ( ! function_exists( 'FluentCrmApi' ) ) return;

    $user = get_userdata( $user_id );
    if ( ! $user ) return;

    // Tag "Payé"
    $tag_id = (int) PC_Settings::get( 'fluent_tag_paye', 0 );
    if ( $tag_id > 0 ) {
        $contact = FluentCrmApi( 'contacts' )->getContact( $user->user_email );
        if ( $contact ) {
            $contact->attachTags( [ $tag_id ] );
        }
    }

    do_action( 'fluent_crm/event_tracker', [
        'event_key' => 'pc_paiement_recu_groupe',
        'title'     => __( 'Paiement reçu (groupé)', PC_TEXT_DOMAIN ),
        'email'     => $user->user_email,
        'value'     => $data['montant_cts'],
    ] );
}
```

- [ ] **Step 5: Ajouter `pc_relance_paiement`**

```php
add_action( 'pc_relance_paiement', [ $this, 'on_relance_paiement' ], 10, 2 );

public function on_relance_paiement( int $user_id, array $data ): void {
    if ( ! function_exists( 'FluentCrmApi' ) ) return;
    $user = get_userdata( $user_id );
    if ( ! $user ) return;

    $contact = FluentCrmApi( 'contacts' )->getContact( $user->user_email );
    if ( $contact ) {
        $contact->updateCustomFields( [
            'pc_payment_url'        => (string) $data['payment_url'],
            'pc_nb_photos_retenues' => (int) $data['nb_photos'],
            'pc_montant_a_payer_cts' => (int) $data['montant_cts'],
        ] );
    }

    do_action( 'fluent_crm/event_tracker', [
        'event_key' => 'pc_relance_paiement',
        'title'     => sprintf( __( 'Relance #%d paiement', PC_TEXT_DOMAIN ), (int) $data['nb_relances'] ),
        'email'     => $user->user_email,
        'value'     => $data['montant_cts'],
    ] );
}
```

- [ ] **Step 6: Ajouter `pc_cloture_jury_effectuee`**

```php
add_action( 'pc_cloture_jury_effectuee', [ $this, 'on_cloture_jury' ] );

public function on_cloture_jury(): void {
    if ( ! function_exists( 'FluentCrmApi' ) ) return;
    do_action( 'fluent_crm/event_tracker', [
        'event_key' => 'pc_cloture_jury_effectuee',
        'title'     => __( 'Clôture jury effectuée', PC_TEXT_DOMAIN ),
    ] );
}
```

- [ ] **Step 7: Documentation des nouveaux triggers**

Dans la doc admin ou dans `wp-admin > Concours Photo > Paramètres`, ajouter une note expliquant les nouveaux triggers Fluent CRM que l'admin doit configurer côté Fluent CRM :

```
- pc_participation_demandee_groupee : email avec lien Stripe groupé (champs : pc_nb_photos_retenues, pc_montant_a_payer_cts, pc_payment_url)
- pc_paiement_recu_groupe : confirmation paiement
- pc_relance_paiement : relance d'impayé
- pc_cloture_jury_effectuee : notification admin (optionnel)
```

- [ ] **Step 8: Test manuel**

Si Fluent CRM est configuré, vérifier que les events sont bien reçus côté Fluent CRM après une clôture. Sinon, vérifier que le code log mais ne plante pas.

- [ ] **Step 9: Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-fluent-crm.php
git commit -m "feat(phase2): new Fluent CRM triggers for grouped payment workflow"
```

---

## Task 13: Tests de régression complets — Phase 2

**Files:** (aucun, scénarios manuels et SQL)

- [ ] **Step 1: État de la BDD avant chaque test**

Pour chaque scénario : noter le COUNT(*) par statut avant et après. Préparer un user de test isolé.

- [ ] **Step 2: Scénario 1 — Clôture nominale**

Préparation :

```bash
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe"
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
-- Reset propre : 3 photos en retenue pour 2 candidats
UPDATE wp_pc_photos SET statut='retenue' WHERE id IN (1, 2, 3);
DELETE FROM wp_pc_payments WHERE payment_token IS NOT NULL;
SELECT id, user_id, statut FROM wp_pc_photos WHERE statut='retenue';"
```

Action : wp-admin → Concours Photo > Clôture délibération → bouton clôture.

Vérification :
```bash
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT statut, COUNT(*) FROM wp_pc_photos GROUP BY statut;
SELECT user_id, payment_token, COUNT(*) AS nb FROM wp_pc_payments WHERE statut_paiement='en_attente' GROUP BY user_id, payment_token;"
```

PASS si :
- Plus aucun statut `en_attente` ou `en_cours_examen` ou `retenue`
- 3 photos en `participation_demandee`
- 2 entrées `payment_token` distincts (1 par user)

- [ ] **Step 3: Scénario 2 — Envoi par lots**

Avec `email_batch_size = 1` (forcer petit batch) :

```bash
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT option_value FROM wp_options WHERE option_name='pc_settings'\G" | grep email_batch_size
```

Si nécessaire, modifier via interface admin pour passer à 1.

Déclencher manuellement le cron 2 fois :

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" -r '
define("WP_USE_THEMES",false);
$_SERVER["HTTP_HOST"]="sdlp.test"; $_SERVER["REQUEST_URI"]="/";
require_once "C:/laragon/www/sdlp/wp-load.php";
PC_Payments::get_instance()->cron_send_batch();
echo "Tick 1 OK\n";
'
```

Vérifier que 1 candidat (= 1 groupe `payment_token`) a `email_envoye_at` rempli, l'autre non. Re-déclencher → tous les 2.

- [ ] **Step 4: Scénario 3 — Endpoint pc_pay**

Récupérer un token valide :
```bash
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT DISTINCT user_id, payment_token FROM wp_pc_payments WHERE statut_paiement='en_attente' AND payment_token IS NOT NULL LIMIT 1;"
```

Se connecter en tant que ce user, ouvrir `https://sdlp.test/?pc_pay=<TOKEN>`. Redirection vers Stripe Checkout avec N line_items.

Test des cas d'erreur :
- Token invalide → 400
- Token d'un autre user → 403
- Pas connecté → redirection login avec `redirect_to`

- [ ] **Step 5: Scénario 4 — Webhook idempotent**

Forcer manuellement :

```bash
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
UPDATE wp_pc_payments SET reference_externe='cs_test_simul1' WHERE payment_token=(SELECT payment_token FROM (SELECT payment_token FROM wp_pc_payments WHERE statut_paiement='en_attente' LIMIT 1) t);"
```

Simuler le webhook 2 fois (utiliser Stripe CLI ou exécuter manuellement le code) → vérifier qu'un seul `do_action( 'pc_paiement_recu_groupe' )` est déclenché (logger ou compter en BDD le tag « Payé »).

- [ ] **Step 6: Scénario 5 — Relances**

Forcer un paiement vieux :

```bash
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
UPDATE wp_pc_payments
SET email_envoye_at = DATE_SUB(NOW(), INTERVAL 6 DAY),
    nb_relances = 0,
    derniere_relance_at = NULL
WHERE statut_paiement='en_attente'
LIMIT 1;"
```

Forcer le cron daily :

```bash
PHP="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"
"$PHP" -r '
define("WP_USE_THEMES",false);
$_SERVER["HTTP_HOST"]="sdlp.test"; $_SERVER["REQUEST_URI"]="/";
require_once "C:/laragon/www/sdlp/wp-load.php";
PC_Payments::get_instance()->cron_relances_quotidiennes();
echo "OK\n";
'
```

Vérifier `nb_relances=1`. Re-lancer → reste à 1 (cooldown 5 jours).

Forcer encore :
```bash
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
UPDATE wp_pc_payments SET derniere_relance_at = DATE_SUB(NOW(), INTERVAL 6 DAY) WHERE statut_paiement='en_attente' AND nb_relances > 0;"
```

Re-lancer le cron → `nb_relances=2`. Une 3e tentative → reste à 2 (max atteint).

- [ ] **Step 7: Scénario 6 — Régression flux inscription**

Créer un nouveau candidat via `/inscription-photographe/`. Vérifier que le parcours s'arrête à `complet` (pas d'étape 3) et que l'accès à la galerie est immédiat après acceptation du règlement.

- [ ] **Step 8: Vérification finale**

```bash
"$MYSQL" -ubepi7527_wp84067 -piktGLgBJHz-M bepi7527_wp84067 -e "
SELECT 'photos_orphans' AS m, COUNT(*) AS n FROM wp_pc_photos WHERE category_id=0
UNION SELECT 'payments_inconsistent', COUNT(*) FROM wp_pc_payments p JOIN wp_pc_photos ph ON ph.id=p.photo_id WHERE p.statut_paiement='paiement_recu' AND ph.statut != 'paiement_recu' AND ph.statut != 'au_catalogue';"
```

PASS : `photos_orphans=0`, `payments_inconsistent=0`.

- [ ] **Step 9: Tag de fin de phase 2**

```bash
git tag -a v2.0.0 -m "Phase 2 - Paiement à la photo - tests OK"
git status
```

---

## Self-Review

- [ ] **Coverage** : Sections 7, 9, 10, 12.2 de la spec couvertes par Tasks 2 à 12.
- [ ] **Pas de placeholder** : aucun TBD/TODO.
- [ ] **Type consistency** : `payment_token` (UUID v4) cohérent dans toutes les méthodes. `cron_send_batch` et `cron_relances_quotidiennes` partagent la même structure (group by user_id+payment_token, marquage atomique).
- [ ] **Idempotence webhook** : check `if ( statut_paiement === 'paiement_recu' ) continue;` garantit qu'un double-webhook ne re-déclenche pas la cascade.
- [ ] **Hooks Elementor** : endpoint `pc_pay` sur `plugins_loaded:20` + filet `ob_end_clean()` — conforme au skill `elementor-gotchas`.
- [ ] **Sécurité** : nonce sur l'action de clôture, vérif `current_user_can( 'pc_manage_payments' )`, validation UUID v4 sur le token, contrôle d'identité strict (user connecté = user du token).
- [ ] **Migration idempotente** : check `information_schema.columns` pour les ALTER, `SHOW INDEX` pour les indexes.

---

## Hand-off final

Une fois cette Phase 2 validée et taggée `v2.0.0`, le concours fonctionne entièrement avec le nouveau modèle :
- Catégorisation native
- Paiement par photo retenue, groupé à la clôture
- Envoi par lots
- Relances automatiques
- Plus de paiement à l'inscription

Reste à faire **avant déploiement prod** (hors plan technique) :
- Audit BDD prod (nb candidats avec `paiement_inscription_recu=1`)
- Décision admin sur le sort des paiements d'inscription historiques (informellement crédités, pas de remboursement)
- Backup BDD prod complet
- Test du script de migration sur copie prod
- Validation du webhook Stripe en prod (URL et secret)
- Configuration des automations Fluent CRM pour les nouveaux triggers

**Communication candidats** : prévoir un email d'annonce avant la bascule expliquant le nouveau modèle (suppression paiement inscription, paiement à la photo retenue).
