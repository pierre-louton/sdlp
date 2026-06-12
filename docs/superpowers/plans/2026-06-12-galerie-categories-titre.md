# Galerie : déplacement de catégorie + garde-fou titre — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre au candidat de déplacer une photo entre catégories par glisser-déposer (avec contrôle de quota et verrou dépôt), et empêcher que son prénom/nom figure dans le titre d'une photo (anonymat jury).

**Architecture:** Deux features backend testables dans `PC_Photos` (`changer_categorie`, `titre_contient_nom`) exposées par AJAX, plus l'extension du drag-and-drop existant dans `pc-gallery.js`. Comparaisons de quota/dépôt revalidées côté serveur ; détection du nom insensible casse/accents en mot entier.

**Tech Stack:** PHP 8.1+, WordPress, harness de test maison (`tests/test-pc-*.php`), JS vanilla (`pc-gallery.js`).

**Spec :** `docs/superpowers/specs/2026-06-12-galerie-categories-titre-design.md`
**Dépendances :** `is_depot_actif()` (PR #2), part de `feature/paiement-avant-jury` (PR #3, pour `pc-gallery.js`).

---

## Structure des fichiers

| Fichier | Rôle | Tâches |
|---------|------|--------|
| `includes/class-pc-photos.php` | `titre_contient_nom`, garde édition + EXIF, `changer_categorie`, `ajax_change_category` | 1, 2 |
| `includes/class-pc-shortcodes.php` | nonce `pc_category_nonce` dans la config JS galerie | 2 |
| `public/js/pc-gallery.js` | drop cross-section (changement catégorie) + gestion erreur titre | 3 |
| `tests/test-pc-galerie.php` | harness (nouveau) | 1, 2 |

---

## Task 1 : Garde-fou nom dans le titre (TDD)

**Files:**
- Create: `wp-content/plugins/photo-contest/tests/test-pc-galerie.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-photos.php`

- [ ] **Step 1 : Écrire le harness (échoue)**

Créer `wp-content/plugins/photo-contest/tests/test-pc-galerie.php` :

```php
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
    if ( $e === $a ) { echo "  ✓ $l\n"; }
    else { $failures++; echo "  ✗ $l — attendu " . var_export($e,true) . ", obtenu " . var_export($a,true) . "\n"; }
}
function assertTrue( $c, string $l ): void { assertEq( true, (bool)$c, $l ); }
function assertFalse( $c, string $l ): void { assertEq( false, (bool)$c, $l ); }

global $wpdb;
$photos_t = PC_Database::table( PC_Database::TABLE_PHOTOS );

// Dépôt actif pour les tests de catégorie (Task 2)
PC_Settings::set( [ 'depot_actif' => true, 'date_ouverture' => '', 'date_fermeture_depot' => '', 'quota_photos' => 5 ] );

// Candidat de test + profil prénom/nom
$uid = wp_insert_user( [
    'user_login' => 'pc_test_gal_' . uniqid(),
    'user_pass'  => wp_generate_password(),
    'user_email' => 'gal_' . uniqid() . '@sdlp.test',
    'role'       => 'pc_candidat',
] );
$profiles_t = PC_Database::table( PC_Database::TABLE_PROFILES );
$wpdb->replace( $profiles_t, [ 'user_id' => $uid, 'prenom' => 'Jean', 'nom' => 'Martin' ] );

$photos = PC_Photos::get_instance();

echo "== titre_contient_nom ==\n";
assertTrue(  $photos->titre_contient_nom( 'Le rêve de Jean', $uid ),       'prénom en mot entier -> true' );
assertTrue(  $photos->titre_contient_nom( 'Portrait MARTIN au crépuscule', $uid ), 'nom (casse) -> true' );
assertTrue(  $photos->titre_contient_nom( 'Chez jéan', $uid ),             'accent ignoré (jéan ~ jean) -> true' );
assertFalse( $photos->titre_contient_nom( 'Une martingale gagnante', $uid ),'sous-chaîne Martin dans Martingale -> false' );
assertFalse( $photos->titre_contient_nom( 'Coucher de soleil', $uid ),     'sans le nom -> false' );
assertFalse( $photos->titre_contient_nom( '', $uid ),                      'titre vide -> false' );

// ── Nettoyage (le bloc Task 2 ajoutera ses propres fixtures avant ce nettoyage) ──
$wpdb->delete( $photos_t,   [ 'user_id' => $uid ] );
$wpdb->delete( $profiles_t, [ 'user_id' => $uid ] );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $uid );

echo "\n--------------------------------------------\n";
echo "$tests tests · $failures échecs\n";
exit( $failures > 0 ? 1 : 0 );
```

> Vérifier le nom réel de la constante de table profils (`PC_Database::TABLE_PROFILES`) et
> les colonnes `prenom`/`nom` (cf. `class-pc-database.php`). Adapter si nécessaire.

- [ ] **Step 2 : Lancer — échoue**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-galerie.php`
Expected : FAIL — `Call to undefined method PC_Photos::titre_contient_nom()`.

- [ ] **Step 3 : Implémenter le helper + la normalisation dans `class-pc-photos.php`**

Ajouter dans la classe `PC_Photos` :

```php
    /**
     * Vrai si le titre contient le prénom ou le nom du candidat (mot entier,
     * insensible à la casse et aux accents). Termes vides ou < 2 caractères ignorés.
     */
    public function titre_contient_nom( string $titre, int $user_id ): bool {
        $titre_norm = $this->normaliser_pour_comparaison( $titre );
        if ( $titre_norm === '' ) {
            return false;
        }
        $profile = PC_Profile::get_instance()->get_profile( $user_id ) ?: [];
        foreach ( [ $profile['prenom'] ?? '', $profile['nom'] ?? '' ] as $terme ) {
            $terme_norm = $this->normaliser_pour_comparaison( (string) $terme );
            if ( mb_strlen( $terme_norm ) < 2 ) {
                continue;
            }
            if ( preg_match( '/\b' . preg_quote( $terme_norm, '/' ) . '\b/u', $titre_norm ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Minuscule + suppression des accents, pour comparaison tolérante.
     */
    private function normaliser_pour_comparaison( string $s ): string {
        return trim( mb_strtolower( remove_accents( $s ) ) );
    }
```

> Vérifier que `PC_Profile::get_instance()->get_profile( $user_id )` renvoie bien un tableau
> avec `prenom`/`nom` (c'est ce qu'utilise `templates/profile.php`). Sinon, lire le profil
> directement en base.

- [ ] **Step 4 : Lancer — passe**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-galerie.php`
Expected : PASS — `6 tests · 0 échecs`.

- [ ] **Step 5 : Garde à l'édition du titre — `ajax_update_titre()`**

Dans `ajax_update_titre()`, après la vérification d'appartenance de la photo
(`$photo = $this->get_photo( $photo_id, $user_id ); if ( ! $photo ) {...}`) et **avant**
le `$wpdb->update(...)`, insérer :

```php
        if ( $titre !== '' && $this->titre_contient_nom( $titre, $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Le titre ne doit pas contenir votre nom ni votre prénom.', PC_TEXT_DOMAIN ) ] );
        }
```

- [ ] **Step 6 : Garde à l'import EXIF — `upload_photo()`**

Dans `upload_photo()`, juste après la ligne
`$titre = $this->extract_titre_from_exif( $chemin_dest, $nom_fichier );` (≈ ligne 275),
insérer (le `$user_id` est le 1er paramètre de `upload_photo`) :

```php
        // Anonymat jury : ne jamais importer un titre EXIF contenant le nom du candidat.
        if ( $titre !== '' && $this->titre_contient_nom( $titre, $user_id ) ) {
            $titre = '';
        }
```

> Vérifier le nom exact du paramètre user id dans la signature de `upload_photo()` et
> l'utiliser.

- [ ] **Step 7 : Lancer + lint**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-galerie.php` → 0 échecs.
Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-photos.php` → No syntax errors.

- [ ] **Step 8 : Commit**

```bash
git add wp-content/plugins/photo-contest/tests/test-pc-galerie.php wp-content/plugins/photo-contest/includes/class-pc-photos.php
git commit -m "feat(galerie): garde-fou titre — interdit le prénom/nom du candidat (édition + import EXIF)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2 : Déplacement de catégorie (TDD)

**Files:**
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-photos.php`
- Modify: `wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php`
- Modify: `wp-content/plugins/photo-contest/tests/test-pc-galerie.php`

- [ ] **Step 1 : Ajouter le test (échoue)**

Dans `tests/test-pc-galerie.php`, **avant** le bloc « ── Nettoyage ── », insérer :

```php
echo "\n== changer_categorie ==\n";
// Deux catégories de test
$catA = PC_Categories::create( 'Cat A ' . uniqid() );
$catB = PC_Categories::create( 'Cat B ' . uniqid() );
// Une photo du candidat dans catA
$wpdb->insert( $photos_t, [
    'user_id' => $uid, 'category_id' => $catA, 'titre' => 'X',
    'nom_fichier' => 'x.jpg', 'chemin_fichier' => 'x.jpg',
    'largeur_px' => 900, 'hauteur_px' => 600, 'ratio_type' => '3_2',
    'taille_octets' => 100, 'statut' => 'en_attente', 'ordre_affichage' => 1,
] );
$pid = (int) $wpdb->insert_id;

// Déplacement OK vers catB
$r = $photos->changer_categorie( $pid, $catB, $uid );
assertTrue( $r['success'], 'déplacement vers catégorie avec place -> success' );
assertEq( $catB, (int) $wpdb->get_var( $wpdb->prepare( "SELECT category_id FROM {$photos_t} WHERE id=%d", $pid ) ), 'category_id mis à jour en base' );

// Photo d'un autre candidat -> refus
$r = $photos->changer_categorie( $pid, $catA, $uid + 999999 );
assertFalse( $r['success'], 'photo d\'un autre candidat -> refus' );

// Catégorie inexistante -> refus
$r = $photos->changer_categorie( $pid, 0, $uid );
assertFalse( $r['success'], 'catégorie 0 (inexistante/Non classées) -> refus' );

// Quota plein -> refus : quota_photos=1, catA déjà 1 photo (remettre pid dans catA d'abord vide)
PC_Settings::set( 'quota_photos', 1 );
$wpdb->insert( $photos_t, [
    'user_id' => $uid, 'category_id' => $catB, 'titre' => 'Y',
    'nom_fichier' => 'y.jpg', 'chemin_fichier' => 'y.jpg',
    'largeur_px' => 900, 'hauteur_px' => 600, 'ratio_type' => '3_2',
    'taille_octets' => 100, 'statut' => 'en_attente', 'ordre_affichage' => 2,
] );
$pid2 = (int) $wpdb->insert_id; // catB a maintenant 2 photos (pid + pid2), quota=1
$r = $photos->changer_categorie( $pid2, $catA, $uid ); // catA vide, OK malgré quota 1 ? catA a 0 -> OK
assertTrue( $r['success'], 'déplacement vers catégorie vide (quota 1) -> success' );
$r = $photos->changer_categorie( $pid, $catA, $uid ); // catA a maintenant 1 (pid2), quota 1 -> plein
assertFalse( $r['success'], 'catégorie cible pleine (quota atteint) -> refus' );
PC_Settings::set( 'quota_photos', 5 );

// Dépôt clôturé -> refus
PC_Settings::set( 'date_fermeture_depot', '2000-01-01 00:00:00' );
$r = $photos->changer_categorie( $pid, $catB, $uid );
assertFalse( $r['success'], 'dépôt clôturé -> refus' );
PC_Settings::set( 'date_fermeture_depot', '' );

// Nettoyage catégories de test
PC_Categories::delete( $catA );
PC_Categories::delete( $catB );
```

> `PC_Categories::create`/`delete` existent (cf. `tests/test-pc-categories.php`). `delete`
> refuse si la catégorie contient des photos : le `$wpdb->delete( $photos_t, ['user_id'=>$uid] )`
> du bloc Nettoyage s'exécutant APRÈS, déplacer les `PC_Categories::delete()` ci-dessus
> APRÈS la suppression des photos si `delete` renvoie une WP_Error (sinon laisser ici et
> ignorer l'erreur). Le plus sûr : supprimer d'abord les photos du candidat, puis les
> catégories. Réordonne ce bloc/le Nettoyage en conséquence.

- [ ] **Step 2 : Lancer — échoue**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-galerie.php`
Expected : FAIL — `Call to undefined method PC_Photos::changer_categorie()`.

- [ ] **Step 3 : Implémenter `changer_categorie()` + l'endpoint AJAX**

Dans `class-pc-photos.php`, ajouter :

```php
    /**
     * Déplace une photo du candidat vers une autre catégorie.
     * Revalide tout côté serveur : appartenance, dépôt actif, catégorie active, quota.
     *
     * @return array{success:bool, message?:string, category_id?:int}
     */
    public function changer_categorie( int $photo_id, int $category_id, int $user_id ): array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PHOTOS );

        $photo = $this->get_photo( $photo_id, $user_id );
        if ( ! $photo ) {
            return [ 'success' => false, 'message' => __( 'Photo introuvable.', PC_TEXT_DOMAIN ) ];
        }
        if ( ! PC_Settings::is_depot_actif() ) {
            return [ 'success' => false, 'message' => __( 'Le dépôt est clôturé.', PC_TEXT_DOMAIN ) ];
        }
        $cat = $category_id > 0 ? PC_Categories::get( $category_id ) : null;
        if ( ! $cat || empty( $cat['actif'] ) ) {
            return [ 'success' => false, 'message' => __( 'Catégorie invalide.', PC_TEXT_DOMAIN ) ];
        }
        if ( (int) $photo['category_id'] === $category_id ) {
            return [ 'success' => true, 'category_id' => $category_id ]; // déjà là
        }

        $quota = (int) PC_Settings::get( 'quota_photos', 5 );
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND category_id = %d",
            $user_id, $category_id
        ) );
        if ( $quota > 0 && $count >= $quota ) {
            return [ 'success' => false, 'message' => __( 'Cette catégorie est complète.', PC_TEXT_DOMAIN ) ];
        }

        $max_ordre = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(ordre_affichage) FROM {$table} WHERE user_id = %d AND category_id = %d",
            $user_id, $category_id
        ) );
        $wpdb->update(
            $table,
            [ 'category_id' => $category_id, 'ordre_affichage' => $max_ordre + 1 ],
            [ 'id' => $photo_id, 'user_id' => $user_id ]
        );

        return [ 'success' => true, 'category_id' => $category_id ];
    }

    /**
     * AJAX : déplacement de catégorie par glisser-déposer.
     */
    public function ajax_change_category(): void {
        check_ajax_referer( 'pc_category_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_view_own_photos' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }
        $photo_id    = (int) ( $_POST['photo_id'] ?? 0 );
        $category_id = (int) ( $_POST['category_id'] ?? 0 );
        $res = $this->changer_categorie( $photo_id, $category_id, get_current_user_id() );
        if ( ! empty( $res['success'] ) ) {
            wp_send_json_success( $res );
        }
        wp_send_json_error( $res );
    }
```

Dans le constructeur de `PC_Photos` (`__construct`, à côté des autres `wp_ajax_`), ajouter :

```php
        add_action( 'wp_ajax_pc_change_category', [ $this, 'ajax_change_category' ] );
```

> Vérifier que `PC_Categories::get()` renvoie une clé `actif` (cf. `tests/test-pc-categories.php`).

- [ ] **Step 4 : Lancer — passe**

Run : `php wp-content/plugins/photo-contest/tests/test-pc-galerie.php`
Expected : PASS — 0 échecs.

- [ ] **Step 5 : Exposer le nonce `pc_category_nonce` au JS — `class-pc-shortcodes.php`**

Dans `render_gallery()` (la méthode qui localise la config JS de la galerie via
`wp_localize_script` — repérer l'objet contenant `nonceReorder` / `pc_reorder` /
`quotaMax` / `depotActif`), ajouter une clé :

```php
            'nonceCategory' => wp_create_nonce( 'pc_category_nonce' ),
```

> Lire la méthode pour identifier le tableau de config exact et y insérer la clé sans
> casser les autres. Noter le nom de l'objet JS (ex. `pcGalleryConfig`/`CFG`).

- [ ] **Step 6 : Lint + tests**

Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-photos.php` → OK.
Run : `php -l wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php` → OK.
Run : `php wp-content/plugins/photo-contest/tests/test-pc-galerie.php` → 0 échecs.

- [ ] **Step 7 : Commit**

```bash
git add wp-content/plugins/photo-contest/includes/class-pc-photos.php wp-content/plugins/photo-contest/includes/class-pc-shortcodes.php wp-content/plugins/photo-contest/tests/test-pc-galerie.php
git commit -m "feat(galerie): changer_categorie + endpoint AJAX (quota + dépôt actif revalidés serveur)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3 : Drag-drop cross-section + gestion erreur titre (JS)

**Files:**
- Modify: `wp-content/plugins/photo-contest/public/js/pc-gallery.js`

Contexte DOM (constaté) : sections `.pc-gallery-section` avec `dataset.catId` ; grilles
`.pc-gallery-section__grid.pc-grid` avec `data-cat-id` ; cartes `.pc-card[data-id]`
`draggable`. La config JS est `CFG` (`window.pcGalleryConfig`), `CFG.ajaxUrl`,
`CFG.nonceCategory` (ajouté en Task 2). `initDragDrop()` gère le réordonnancement intra-grille ;
`sauvegarderTitre(photoId, input, txt)` gère l'édition de titre.

- [ ] **Step 1 : Mémoriser la catégorie source au dragstart**

Dans `initDragDrop()`, dans le handler `dragstart`, après `state.dragSrcId = carte.dataset.id;`,
ajouter la mémorisation de la grille/catégorie d'origine :

```php
          state.dragSrcCat = grid.dataset.catId;
```

(`grid` est la variable de la grille courante dans la boucle `forEach(grid => ...)`.)

- [ ] **Step 2 : Ajouter le drop cross-section au niveau de chaque grille**

Toujours dans `initDragDrop()`, après la boucle `cartes.forEach(...)` (mais dans le
`forEach(grid => ...)`), ajouter des handlers sur la **grille** elle-même pour accepter une
carte venant d'une autre catégorie :

```js
      grid.addEventListener('dragover', e => {
        if (state.dragSrcCat && state.dragSrcCat !== grid.dataset.catId) {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          grid.classList.add('pc-grid--drop-target');
        }
      });
      grid.addEventListener('dragleave', () => grid.classList.remove('pc-grid--drop-target'));
      grid.addEventListener('drop', e => {
        grid.classList.remove('pc-grid--drop-target');
        const destCat = grid.dataset.catId;
        if (!state.dragSrcId || !state.dragSrcCat || state.dragSrcCat === destCat) return;
        e.preventDefault();
        const photoId = state.dragSrcId;
        const body = new URLSearchParams({
          action: 'pc_change_category',
          nonce: CFG.nonceCategory,
          photo_id: photoId,
          category_id: destCat,
        });
        fetch(CFG.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' })
          .then(r => r.json())
          .then(res => {
            if (res && res.success) {
              chargerPhotos(); // recharge l'état serveur (sections + compteurs quota)
              toast('Photo déplacée.', 'succes');
            } else {
              toast((res && res.data && res.data.message) ? res.data.message : 'Déplacement impossible.', 'erreur');
            }
          })
          .catch(() => toast('Erreur réseau.', 'erreur'));
        state.dragSrcId = null;
        state.dragSrcCat = null;
      });
```

> `chargerPhotos()` est la fonction existante qui (re)charge les photos via `pc_get_photos`
> et re-rend la galerie (vérifier son nom exact en début de fichier). `toast(msg, type)`
> existe déjà. Vérifier les valeurs de `type` acceptées (`'succes'`/`'erreur'`/`'info'` —
> aligner sur l'usage existant).

- [ ] **Step 3 : Déclarer les nouveaux champs d'état**

En haut du fichier, dans l'objet `state` (qui contient déjà `dragSrcId`), s'assurer que
`dragSrcCat` existe (l'ajouter à l'initialisation, ex. `dragSrcCat: null,`). Si `state` est
défini sans `dragSrcId` explicite (assigné à la volée), ce n'est pas bloquant — l'ajout est
optionnel mais plus propre.

- [ ] **Step 4 : Gestion de l'erreur d'édition de titre**

Repérer `sauvegarderTitre(photoId, input, txt)` (appelée au `blur` du champ titre). Lire son
implémentation. Adapter la réponse `fetch` pour gérer le refus serveur (titre contenant le
nom) :

```js
        .then(res => {
          if (res && res.success) {
            txt.textContent = res.data.titre || 'Sans titre';
            input.style.display = 'none';
            txt.style.display = '';
          } else {
            // Refus (ex. nom interdit) : garder le champ ouvert + message
            toast((res && res.data && res.data.message) ? res.data.message : 'Titre refusé.', 'erreur');
            input.focus();
          }
        })
```

> Adapter aux noms de variables réels dans `sauvegarderTitre` (le nom du champ input, du
> span txt, et la structure actuelle du `.then`). L'objectif : sur `success:false`, ne pas
> écrire le titre, afficher le message, laisser l'utilisateur corriger.

- [ ] **Step 5 : Style de la cible de drop (CSS)**

Dans `public/css/pc-gallery.css`, ajouter :

```css
.pc-grid--drop-target { outline:2px dashed #4da876; outline-offset:4px; background:rgba(77,168,118,.06); }
```

- [ ] **Step 6 : Vérification manuelle**

1. Galerie candidat (dépôt actif) : glisser une photo d'une catégorie vers une autre →
   la photo change de section, les compteurs `x / quota` se mettent à jour.
2. Glisser vers une catégorie déjà au quota → toast « Cette catégorie est complète. », la
   photo reste en place.
3. Réordonnancement intra-catégorie : toujours fonctionnel (drag dans la même grille).
4. Éditer un titre en y mettant son prénom/nom → toast d'erreur, le champ reste ouvert, le
   titre n'est pas enregistré.
5. (si possible) Forcer `is_depot_actif` à faux → le déplacement est refusé.

- [ ] **Step 7 : Commit**

```bash
git add wp-content/plugins/photo-contest/public/js/pc-gallery.js wp-content/plugins/photo-contest/public/css/pc-gallery.css
git commit -m "feat(galerie): glisser-déposer entre catégories + message si titre refusé

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Auto-revue du plan (effectuée)

**Couverture de la spec :**
- `titre_contient_nom` (mot entier, accents, casse) → Task 1 ✓
- Refus à l'édition + vidage à l'import EXIF → Task 1 (Steps 5-6) ✓
- `changer_categorie` (appartenance, dépôt actif, catégorie active, quota) → Task 2 ✓
- Endpoint `pc_change_category` + nonce `pc_category_nonce` → Task 2 ✓
- Drag-drop cross-section + distinction réordonnancement intra-section → Task 3 ✓
- Gestion erreur titre côté JS → Task 3 (Step 4) ✓
- Tests harness (titre + catégorie, fixtures nettoyées) → Task 1 & 2 ✓

**Placeholders :** les étapes JS (Task 3) et l'exposition du nonce (Task 2 Step 5)
contiennent des instructions d'ancrage (« repérer », « adapter ») car `sauvegarderTitre` /
la config localisée n'ont pas été lues intégralement ; chaque cas fournit le contrat exact
(noms d'actions, nonce, structure DOM `data-cat-id`) et le code à intégrer. Aucun « TODO »
fonctionnel.

**Cohérence des types :** `titre_contient_nom(string,int):bool`,
`normaliser_pour_comparaison(string):string` (privée), `changer_categorie(int,int,int):array{success,...}`,
action AJAX `pc_change_category` + nonce `pc_category_nonce`, état JS `dragSrcCat` —
cohérents entre Task 1, 2, 3.
