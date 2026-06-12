# Galerie candidat : déplacement de catégorie + garde-fou titre — Spec de conception

> Spec de conception — 2026-06-12
> Plugin : Photo Contest Manager (SDLP)
> Sous-projet 2/3 de la refonte du parcours candidat (1 = paiement avant jury, 3 = profil RGPD/opt-in).

## Dépendances

- Utilise `PC_Settings::is_depot_actif()` (feature « phases », PR #2).
- Part de `feature/paiement-avant-jury` (PR #3) car modifie `pc-gallery.js` déjà touché par les badges de paiement. À rebaser sur `main` après merge des PR #2 et #3.

## Objectif

Améliorer l'espace candidat (`/mon-espace-candidat/`, `[photo_contest_gallery]`) :

1. **Déplacement de catégorie par glisser-déposer** : le candidat range une photo dans une
   autre catégorie en la glissant d'une section à l'autre.
2. **Garde-fou sur le titre** : le titre d'une photo (éditable par le candidat) ne doit pas
   contenir son prénom ni son nom — pour préserver l'anonymat lors de la délibération du jury.

## État de l'existant

- La galerie affiche les catégories en **sections** (`renderGalerie` dans `pc-gallery.js`),
  chacune avec un **quota** propre (`section.quota`).
- Le **drag-and-drop existe déjà mais uniquement en réordonnancement intra-section**
  (`initDragDrop`, `sauvegarderOrdre` → `wp_ajax_pc_reorder_photos`). Aucun déplacement
  entre catégories.
- L'**édition du titre** existe : `PC_Photos::ajax_update_titre()` (`wp_ajax_pc_update_titre`,
  nonce `pc_titre_nonce`, `sanitize_text_field`) — **sans** contrôle du nom.
- Le **titre est importé de l'EXIF/IPTC** à l'upload (`upload_photo` / `ajax_upload`).
- Données candidat : `PC_Profile` / table profiles fournissent `prenom` et `nom`.
- Le rendu d'une carte est `creerCarte(photo)` ; les données photo viennent de
  `ajax_get_photos` (`PC_Shortcodes`), qui renvoie `categories[] { id, nom, quota, photos[] }`.

## Décisions de conception (validées en brainstorming)

1. **Déplacement de catégorie** : refusé si la catégorie cible a atteint son quota (pour ce
   candidat) ; autorisé **uniquement tant que `is_depot_actif()`** (catégories verrouillées
   après clôture du dépôt).
2. **Garde-fou titre** : détection du **prénom et du nom** du profil, **insensible à la casse
   et aux accents**, en **mot entier** (évite les faux positifs type « Martin » dans
   « Martingale »). À l'édition → **refus** avec message clair. À l'import EXIF → titre
   **vidé** silencieusement (un refus n'a pas de sens pour un import automatique).

## Composants et changements

### Feature 1 — Déplacement de catégorie

**`includes/class-pc-photos.php`**

Méthode testable (logique pure, sans `$_POST`) :

```php
/**
 * Déplace une photo du candidat vers une autre catégorie.
 *
 * @return array{success:bool, message?:string, category_id?:int}
 */
public function changer_categorie( int $photo_id, int $category_id, int $user_id ): array
```

Règles, dans l'ordre :
- Photo introuvable / n'appartient pas à `$user_id` → `success:false`.
- `! PC_Settings::is_depot_actif()` → `success:false`, message « Le dépôt est clôturé. ».
- Catégorie cible inexistante ou inactive (`PC_Categories::get`) → `success:false`.
- Si déjà dans cette catégorie → `success:true` (no-op).
- Quota : `COUNT` des photos de `$user_id` dans `$category_id` ≥ `quota` de la catégorie →
  `success:false`, message « Cette catégorie est complète. ».
- Sinon : `UPDATE category_id`, `ordre_affichage = (MAX ordre de la cible) + 1`. `success:true`.

Endpoint AJAX :

```php
add_action( 'wp_ajax_pc_change_category', [ $this, 'ajax_change_category' ] );

public function ajax_change_category(): void {
    check_ajax_referer( 'pc_category_nonce', 'nonce' );
    if ( ! current_user_can( 'pc_view_own_photos' ) ) { /* error */ }
    $photo_id    = (int) ( $_POST['photo_id'] ?? 0 );
    $category_id = (int) ( $_POST['category_id'] ?? 0 );
    $res = $this->changer_categorie( $photo_id, $category_id, get_current_user_id() );
    $res['success'] ? wp_send_json_success( $res ) : wp_send_json_error( $res );
}
```

Le nonce `pc_category_nonce` est ajouté à la config JS localisée de la galerie
(là où `pc_upload_nonce`, `pc_titre_nonce`, etc. sont déjà exposés).

**`public/js/pc-gallery.js`**

Étendre `initDragDrop()` (ou ajouter un handler) pour permettre le **drop d'une carte sur la
grille d'une autre section** :
- `dragover` sur une grille de section autorise le drop (`preventDefault`).
- Au `drop` cross-section : lire l'`id` de la photo (dataTransfer) et l'`id` de catégorie de
  la section cible (attribut `data-category-id` sur la section/grille — à ajouter au rendu si
  absent). Appeler `pc_change_category`.
- Succès → déplacer la carte dans le DOM vers la section cible et mettre à jour
  `state` (catégorie de la photo) ; rafraîchir les compteurs de quota.
- Refus → toast d'erreur (message serveur), la carte reste dans sa section d'origine.
- Le réordonnancement intra-section (`sauvegarderOrdre`) reste inchangé : distinguer drop
  intra-section (réordonner) de drop cross-section (changer catégorie) via l'id de catégorie.

### Feature 2 — Garde-fou nom dans le titre

**`includes/class-pc-photos.php`**

```php
/**
 * Vrai si le titre contient le prénom ou le nom du candidat (mot entier,
 * insensible à la casse et aux accents). Noms vides ou < 2 caractères ignorés.
 */
public function titre_contient_nom( string $titre, int $user_id ): bool
```

Implémentation :
- Récupérer `prenom`/`nom` du profil (`PC_Profile`).
- Normaliser : `remove_accents()` + `mb_strtolower()` sur le titre et chaque terme.
- Pour chaque terme non vide de longueur ≥ 2 : test mot entier via
  `preg_match( '/\b' . preg_quote( $terme, '/' ) . '\b/u', $titre_normalise )`.
- Retourne vrai dès qu'un terme correspond.

**Édition — `ajax_update_titre()`** : après sanitisation, avant l'UPDATE :

```php
if ( $titre !== '' && $this->titre_contient_nom( $titre, $user_id ) ) {
    wp_send_json_error( [ 'message' => __( 'Le titre ne doit pas contenir votre nom ni votre prénom.', PC_TEXT_DOMAIN ) ] );
}
```

**Import EXIF — `upload_photo()`** : après extraction du titre EXIF/IPTC, si
`titre_contient_nom( $titre_exif, $user_id )` → forcer `$titre = ''` (stockage vide,
le candidat renseignera ensuite). Pas de message (import automatique).

**`public/js/pc-gallery.js`** : à l'édition du titre, sur réponse `error`, afficher le message
retourné et garder le champ d'édition ouvert (ne pas valider).

## Sécurité

- Nonce + capability sur les deux endpoints. Vérification d'appartenance de la photo au
  candidat courant (`user_id = get_current_user_id()`) côté serveur, jamais sur confiance
  client.
- `category_id` et `photo_id` castés `(int)`. `changer_categorie` revalide tout côté serveur
  (quota, dépôt actif, catégorie active) indépendamment du JS.
- Titre toujours `sanitize_text_field` avant contrôle/stockage.

## Tests

Nouveau harness `tests/test-pc-galerie.php` (modèle `tests/test-pc-categories.php`) :
- `titre_contient_nom` : prénom présent (mot entier) → true ; nom présent → true ; accents
  (« Bénédicte » vs « benedicte ») → true ; casse → true ; faux positif « Martingale » avec
  nom « Martin » → false ; terme < 2 car ignoré ; titre sans le nom → false.
- `changer_categorie` : déplacement OK (catégorie cible avec place) → success + category_id
  mis à jour en base ; catégorie cible pleine → success:false ; dépôt clôturé → success:false ;
  photo d'un autre candidat → success:false ; catégorie inactive/inexistante → success:false.
- Fixtures créées et nettoyées (candidat + catégories + photos), `is_depot_actif` rendu vrai
  via réglages (`depot_actif=true`, dates vides).

## Hors scope (YAGNI)

- Déplacement multi-sélection (plusieurs photos à la fois).
- Réorganisation après clôture du dépôt.
- Création/édition de catégories côté candidat (réservé admin).
- Modification du rendu lightbox / filtres.
- Profil RGPD / opt-in (sous-projet 3).

## Fichiers touchés (prévision)

- `includes/class-pc-photos.php` (`changer_categorie`, `ajax_change_category`,
  `titre_contient_nom`, garde dans `ajax_update_titre` et `upload_photo`)
- `includes/class-pc-shortcodes.php` (nonce `pc_category_nonce` + `data-category-id` si besoin
  dans les données de section)
- `public/js/pc-gallery.js` (drop cross-section + gestion erreur titre)
- `tests/test-pc-galerie.php` (nouveau)
