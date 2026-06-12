# Pilotage des phases du concours par dates

> Spec de conception — 2026-06-12
> Plugin : Photo Contest Manager (SDLP)

## Objectif

Permettre à l'administrateur de définir une **date d'ouverture** et une **date de
clôture** du dépôt de photos. Au-delà de la date de clôture, la **phase jury**
s'ouvre automatiquement. La **phase catalogue** s'ouvre automatiquement lors de la
clôture manuelle de la délibération du jury.

Pendant les phases de test, l'administrateur doit pouvoir forcer manuellement
l'ouverture du jury et/ou du catalogue, quelles que soient les dates et l'état de
clôture.

## Modèle de cycle de vie

```
date_ouverture ──► dépôt actif ──► date_fermeture_depot ──► jury actif (auto)
                                                              │
                                          clôture manuelle ───┘──► catalogue actif (auto)
```

- **Dépôt** : ouvert entre `date_ouverture` et `date_fermeture_depot`.
- **Jury** : ouvert automatiquement dès que `date_fermeture_depot` est dépassée,
  jusqu'à la clôture manuelle de la délibération.
- **Catalogue** : ouvert automatiquement quand la clôture manuelle
  (`PC_Payments::execute_cloture()`) est exécutée.

## État de l'existant (avant modification)

- `PC_Settings::$defaults` contient déjà `date_ouverture`, `date_fermeture_depot`,
  `date_annonce_resultats` — mais **ces champs ne sont pas affichés** dans le
  formulaire d'administration.
- `PC_Settings::is_depot_actif()` existe et tient compte des dates, mais compare des
  **chaînes** lexicographiquement (`$now < $ouverture` avec `current_time('mysql')`),
  ce qui casse dès que le format stocké diffère — c'est le piège timezone documenté
  dans le CLAUDE.md.
- `jury_actif` et `catalogue_actif` sont des **cases à cocher manuelles** stockées dans
  l'option `pc_settings`.
- `PC_Payments::execute_cloture()` (clôture manuelle irréversible) met déjà
  `jury_actif = false` et `cloture_effectuee_at = time()`.
- Lecture du flag jury : `class-pc-shortcodes.php:163`
  (`PC_Settings::get('jury_actif', false)`).
- Lecture du flag catalogue : `class-pc-shortcodes.php:182`
  (`PC_Settings::get('catalogue_actif', false)`).

## Changements

### 1. `includes/class-pc-settings.php`

**Nouvelle méthode `is_jury_actif(): bool`** — ordre de priorité :

1. Case `jury_actif` cochée → `true`
   **(override manuel, prioritaire sur les dates ET sur la clôture — usage test)**
2. Sinon, clôture effectuée (`cloture_effectuee_at > 0`) → `false`
   (le chemin *automatique* reste verrouillé après clôture)
3. Sinon, `time() >= date_fermeture_depot` → `true` (automatique)
4. Sinon → `false`

```php
public static function is_jury_actif(): bool {
    // 1. Override manuel : prioritaire sur tout (ouverture anticipée, tests)
    if ( self::get( 'jury_actif' ) ) {
        return true;
    }
    // 2. Clôture effectuée → chemin automatique verrouillé
    if ( (int) self::get( 'cloture_effectuee_at', 0 ) > 0 ) {
        return false;
    }
    // 3. Automatique : après la fermeture du dépôt
    $fermeture = self::date_to_ts( self::get( 'date_fermeture_depot' ) );
    return $fermeture !== null && time() >= $fermeture;
}
```

**Refactor `is_depot_actif()`** pour comparer avec `time()` / `strtotime()` (corrige
le bug de comparaison de chaînes) :

```php
public static function is_depot_actif(): bool {
    if ( ! self::get( 'depot_actif' ) ) {
        return false; // interrupteur maître
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
```

**Nouveau helper privé `date_to_ts()`** — parse une date stockée en timestamp, ou
`null` si vide/invalide :

```php
private static function date_to_ts( mixed $value ): ?int {
    $value = is_string( $value ) ? trim( $value ) : '';
    if ( $value === '' ) {
        return null;
    }
    $ts = strtotime( $value );
    return $ts === false ? null : $ts;
}
```

> Convention timezone : on suit la règle du CLAUDE.md — comparaison `time()` vs
> `strtotime($date)` côté PHP, jamais en SQL. `strtotime()` et `time()` sont cohérents
> entre eux (même fuseau PHP).

### 2. `admin/class-pc-admin.php`

**Nouvelle section « Calendrier du concours »** dans `render_settings()`, avec deux
champs `datetime-local` :

- `date_ouverture` — « Date et heure d'ouverture du dépôt »
- `date_fermeture_depot` — « Date et heure de clôture du dépôt »

La valeur affichée doit être convertie au format attendu par `datetime-local`
(`Y-m-d\TH:i`) à partir de la valeur stockée.

**Ré-étiquetage de la case `jury_actif`** dans la liste des cases à cocher :
> « Forcer l'ouverture du jury (sinon automatique à la clôture du dépôt) »

Une `<p class="description">` explicite : « Coché = jury ouvert immédiatement, quelles
que soient les dates et l'état de clôture (utile en test). Décoché = ouverture
automatique dès la date de clôture du dépôt. »

**`save_settings()`** — normaliser les deux dates en `Y-m-d H:i:s` :

- Champ vide → on stocke une chaîne vide (pas de date = pas de contrainte).
- Champ rempli → `date('Y-m-d H:i:s', strtotime($valeur))` (heure locale, **cohérente
  avec la lecture** qui repasse par `strtotime()`) ; chaîne vide si `strtotime()` échoue.
  Ne **pas** utiliser `gmdate()`, qui décalerait vers UTC et casserait la comparaison.

> Les cases à cocher `depot_actif`, `jury_actif`, `catalogue_actif` continuent d'être
> traitées comme aujourd'hui (booléen). Aucun changement de mécanique de sauvegarde
> pour elles.

### 3. `includes/class-pc-shortcodes.php`

Ligne 163 — remplacer la lecture directe du flag par la méthode calculée :

```php
// avant
if ( ! PC_Settings::get( 'jury_actif', false ) )
// après
if ( ! PC_Settings::is_jury_actif() )
```

Le catalogue (ligne 182) **reste inchangé** : il lit toujours le flag stocké
`catalogue_actif`, désormais posé soit manuellement, soit par la clôture.

### 4. `includes/class-pc-payments.php`

Dans `execute_cloture()`, à l'étape « 4. Bascule des flags » (≈ ligne 691), ajouter
l'activation du catalogue à côté du verrouillage du jury :

```php
PC_Settings::set( 'jury_actif',           false );
PC_Settings::set( 'catalogue_actif',      true );   // ← ajout : ouverture catalogue
PC_Settings::set( 'cloture_en_cours',     0 );
PC_Settings::set( 'cloture_effectuee_at', time() );
```

Mettre à jour le bloc de commentaire de tête de la méthode (étape 4) pour mentionner
`catalogue_actif = true`.

## Tableau de décision (comportement attendu)

| jury_actif coché | cloture faite | date clôture dépôt | `is_jury_actif()` |
|------------------|---------------|--------------------|-------------------|
| oui              | peu importe   | peu importe        | **true** (override) |
| non              | oui           | peu importe        | false (verrouillé) |
| non              | non           | dépassée           | **true** (auto)   |
| non              | non           | future / absente   | false             |

| catalogue_actif coché | cloture faite | flag effectif catalogue |
|-----------------------|---------------|-------------------------|
| oui                   | peu importe   | true (manuel)           |
| non                   | oui           | true (posé par clôture) |
| non                   | non           | false                   |

## Cas limites

- **Aucune date de clôture** : le jury ne s'ouvre jamais automatiquement (seul le
  forçage manuel l'ouvre) ; le dépôt ne se ferme jamais par date.
- **Clôture déjà faite + jury_actif coché** : jury ré-ouvert (cas test assumé).
- **Forçage jury avant la date de clôture** : jury ouvert en avance ; le dépôt peut
  rester ouvert simultanément (responsabilité de l'admin).
- **Date d'ouverture future** : le dépôt reste fermé jusqu'à cette date même si
  `depot_actif` est coché.

## Hors scope (YAGNI)

- Notifications automatiques à l'ouverture de la phase jury.
- 3e date dédiée à l'ouverture du catalogue.
- Exposition du champ `date_annonce_resultats` (laissé inutilisé).
- Toute tâche WP-Cron : l'activation jury est **calculée à la volée**, sans dépendance
  au cron (robuste face au cache O2Switch).

## Plan de test

1. **Dépôt fermé par date future** : `date_ouverture` dans le futur → upload refusé,
   galerie affiche « dépôt fermé ».
2. **Dépôt ouvert** : `date_ouverture` passée, `date_fermeture_depot` future → upload
   autorisé, jury verrouillé (« délibération pas encore ouverte »).
3. **Bascule auto vers jury** : `date_fermeture_depot` passée, sans clôture, sans
   forçage → dépôt fermé + `templates/jury.php` accessible.
4. **Verrou post-clôture** : lancer `execute_cloture()` → jury verrouillé même si la
   date est passée, `catalogue_actif` à true → catalogue accessible.
5. **Override test** : cocher `jury_actif` après clôture → jury de nouveau accessible.
6. **Régression timezone** : vérifier que les comparaisons fonctionnent en local
   Laragon (PHP local / MySQL UTC) — aucune requête SQL sur les dates.

## Fichiers touchés

- `includes/class-pc-settings.php` (méthodes `is_jury_actif`, `is_depot_actif`, helper `date_to_ts`)
- `admin/class-pc-admin.php` (section calendrier, label jury, sauvegarde dates)
- `includes/class-pc-shortcodes.php` (ligne 163)
- `includes/class-pc-payments.php` (`execute_cloture`, ≈ ligne 691 + commentaire)
