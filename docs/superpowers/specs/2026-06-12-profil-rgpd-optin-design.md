# Profil candidat : mention RGPD + opt-in prochain concours — Spec de conception

> Spec de conception — 2026-06-12
> Plugin : Photo Contest Manager (SDLP)
> Sous-projet 3/3 de la refonte du parcours candidat (1 = paiement avant jury, 2 = galerie catégories/titre).

## Dépendance

Part de `feature/paiement-avant-jury` (PR #3) car modifie `templates/profile.php` déjà touché
par le caddy. À rebaser sur `main` après merge des PR amont.

## Objectif

Dans le profil candidat (`/mon-profil/`, `[photo_contest_profile]`) :

1. **Mention RGPD** : informer que les données servent uniquement à la gestion du concours et
   ne sont pas exploitées en dehors, dans une formulation conforme RGPD.
2. **Opt-in prochain concours** : une case à cocher « être prévenu du prochain concours » à
   l'adresse email affichée. Le consentement est **stocké** (sans synchronisation Fluent CRM).

## État de l'existant

- Le profil se sauvegarde via `PC_Profile::save_profile( int $user_id, array $data )` qui
  n'accepte que les champs texte listés dans `$allowed_fields` (`prenom`, `nom`, …).
- Table `wp_pc_profiles` (schéma dans `class-pc-database.php`), clé unique `user_id`.
- Le profil est rendu par `templates/profile.php` (section « Informations personnelles »
  contenant le bloc email `pcp-email-info`).
- `pc-profile.js` sérialise et envoie le formulaire profil (`#pcp-form-profil`) via
  l'action AJAX `pc_save_profile` (`PC_Profile::ajax_save_profile`).
- Il existe déjà un réglage texte libre `reglement_texte` (rendu via `wp_kses_post`) — modèle
  à suivre pour `rgpd_texte`.
- `PC_Settings` : `$defaults` + `get()`/`set()` + page admin Paramètres (`PC_Admin::render_settings`
  / `save_settings`).

## Décisions de conception (validées en brainstorming)

1. **Mention RGPD** : réglage `rgpd_texte` éditable en admin, avec un texte par défaut conforme,
   affiché dans le profil. Pas de donnée stockée côté candidat (affichage seul).
2. **Opt-in** : colonne booléenne `opt_in_prochain` dans `wp_pc_profiles`, enregistrée à la
   sauvegarde du profil. **Aucune** synchronisation Fluent CRM (consentement stocké, exporté
   ultérieurement par l'admin).
3. La case décochée doit être enregistrée comme `0` : le JS envoie explicitement
   `opt_in_prochain=0/1` à chaque sauvegarde (une case décochée n'est pas postée par défaut).

## Composants et changements

### Feature 1 — Mention RGPD

**`includes/class-pc-settings.php`** — ajouter à `$defaults` :

```php
        'rgpd_texte' => 'Les informations recueillies dans ce formulaire sont enregistrées par le Photo Club Pavillonnais et utilisées uniquement pour la gestion de votre participation au concours photo (inscription, délibération du jury, paiement, catalogue). Elles ne sont ni cédées à des tiers, ni exploitées à d\'autres fins. Conformément au RGPD, vous disposez d\'un droit d\'accès, de rectification et d\'effacement de vos données en écrivant à contact@photo-club-pavillonnais.fr.',
```

**`admin/class-pc-admin.php`** — ajouter un champ `textarea` « Mention RGPD (profil candidat) »
dans `render_settings()` (section Concours), et le sanitiser dans `save_settings()` via
`sanitize_textarea_field()` (clé `rgpd_texte`).

**`templates/profile.php`** — dans la section « Informations personnelles », sous le bloc
`pcp-email-info`, afficher la mention RGPD si non vide :

```php
        <?php $rgpd = PC_Settings::get( 'rgpd_texte', '' ); if ( $rgpd !== '' ) : ?>
          <p class="pcp-rgpd"><?php echo wp_kses_post( $rgpd ); ?></p>
        <?php endif; ?>
```

### Feature 2 — Opt-in prochain concours

**`includes/class-pc-database.php`** — ajouter la colonne au `CREATE TABLE` profiles
(`opt_in_prochain TINYINT(1) NOT NULL DEFAULT 0`, placée après `reglement_date`) **et** un
ajout idempotent dans `maybe_upgrade()` : vérifier la présence de la colonne
(`SHOW COLUMNS FROM {profiles} LIKE 'opt_in_prochain'`) et, si absente,
`ALTER TABLE {profiles} ADD COLUMN opt_in_prochain TINYINT(1) NOT NULL DEFAULT 0` — sur le
même modèle que les ajouts de colonnes existants (`payment_token`, etc.).

**`includes/class-pc-profile.php`** — dans `save_profile()`, enregistrer le booléen séparément
des champs texte. Comme le JS envoie toujours la clé (0 ou 1), traiter sa présence :

```php
        if ( array_key_exists( 'opt_in_prochain', $data ) ) {
            $sanitized['opt_in_prochain'] = ! empty( $data['opt_in_prochain'] ) ? 1 : 0;
        }
```

> Remarque : `save_profile()` retourne `false` si `$sanitized` est vide. Avec l'opt-in toujours
> présent, ce cas ne se produit plus lors d'une sauvegarde de profil normale.

**`templates/profile.php`** — case à cocher dans la section info perso, près de l'email :

```php
        <label class="pcp-optin">
          <input type="checkbox" id="pcp-optin" name="opt_in_prochain" value="1"
                 <?php checked( ! empty( $profile['opt_in_prochain'] ) ); ?>>
          <span><?php esc_html_e( 'Je souhaite être prévenu(e) du prochain concours à cette adresse email.', PC_TEXT_DOMAIN ); ?></span>
        </label>
```

**`public/js/pc-profile.js`** — à la sauvegarde du profil (`#pcp-form-profil`), envoyer
explicitement `opt_in_prochain` à `1` ou `0` selon l'état de la case `#pcp-optin` (sinon une
case décochée ne serait jamais transmise → impossible de désactiver l'opt-in). Conserver
l'envoi des autres champs existant.

## Sécurité

- `rgpd_texte` : `sanitize_textarea_field` à la sauvegarde admin, `wp_kses_post` à l'affichage.
- `opt_in_prochain` : casté en `0/1` côté serveur, jamais stocké brut. Sauvegarde dans le
  flux profil existant (nonce + capability déjà vérifiés dans `ajax_save_profile`).

## Tests

Harness `tests/test-pc-profil.php` (modèle `tests/test-pc-categories.php`) :
- `save_profile` persiste `opt_in_prochain = 1` puis `= 0` (création + mise à jour).
- Les champs texte du profil restent enregistrés en présence de l'opt-in.
- Le réglage `rgpd_texte` par défaut est une chaîne non vide
  (`PC_Settings::get( 'rgpd_texte' ) !== ''`).
- Fixtures (candidat + profil) créées et nettoyées.

## Hors scope (YAGNI)

- Synchronisation Fluent CRM (liste/tag) de l'opt-in.
- Écran admin d'export des opt-in (récupération via SQL / sous-agent `db-inspector` pour
  l'instant).
- Versionnage/horodatage du consentement (on stocke l'état courant, pas l'historique).

## Fichiers touchés (prévision)

- `includes/class-pc-settings.php` (`rgpd_texte` dans `$defaults`)
- `includes/class-pc-database.php` (colonne `opt_in_prochain` + upgrade)
- `includes/class-pc-profile.php` (`save_profile` gère l'opt-in)
- `admin/class-pc-admin.php` (champ textarea `rgpd_texte`)
- `templates/profile.php` (mention RGPD + case opt-in)
- `public/js/pc-profile.js` (envoi explicite de `opt_in_prochain`)
- `tests/test-pc-profil.php` (nouveau)
