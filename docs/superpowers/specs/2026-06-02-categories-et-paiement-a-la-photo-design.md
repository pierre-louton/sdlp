# Catégories du concours & paiement à la photo retenue

**Date :** 2026-06-02
**Auteur :** Pierre Beaubié (validation) + Claude (rédaction)
**Plugin cible :** `wp-content/plugins/photo-contest`
**Version cible plugin :** `2.0.0`

## Sommaire

1. Contexte et objectifs
2. Décisions clés (résumé)
3. Modèle de données
4. Admin — gestion des catégories
5. Flux dépôt candidat
6. Flux jury
7. Clôture délibération + paiement groupé
8. Catalogue
9. Intégrations Fluent CRM + planification d'envois
10. Migration des données existantes
11. Tests et critères de réussite
12. Découpage en deux plans d'implémentation

---

## 1. Contexte et objectifs

Le concours SDLP gère aujourd'hui un parcours candidat linéaire : inscription + paiement de 15 € à l'inscription → dépôt photos → délibération jury → paiement individuel par photo retenue (ancien flux résiduel) → catalogue.

Deux changements métier majeurs sont demandés simultanément :

**Système A — Catégorisation du concours.** Le concours est désormais organisé en N catégories (ex. « Nature », « Faune dans la ville », « Biodiversité »). Le quota max de photos s'applique **par catégorie** : avec un quota global de 5 et 3 catégories, un candidat peut déposer jusqu'à 15 photos.

**Système B — Refonte du modèle économique.** Le paiement à l'inscription (15 € global) disparaît. Le paiement est désormais demandé **par photo retenue par le jury**, et regroupé en une seule transaction Stripe à la clôture de la délibération.

Les deux systèmes sont **techniquement indépendants** (aucun couplage de données) mais sont conçus et livrés ensemble pour cohérence éditoriale du nouveau workflow.

## 2. Décisions clés (résumé)

| Sujet | Décision |
|---|---|
| Attributs catégorie | Nom + flag actif/inactif uniquement |
| Quota photos | Global, identique pour toutes les catégories (`pc_settings.quota_photos`) |
| Affectation photo→catégorie | Choisie à l'upload, **figée**, non modifiable ensuite |
| Désactivation catégorie utilisée | Plus de dépôt possible ; photos existantes continuent leur cycle |
| Jury et catégorie | Catégorie visible + filtre UI, règle de décision unique pour toutes |
| Catalogue | Catalogue unique, photos groupées par catégorie (sections/chapitres) |
| Tarif paiement | Unique global (`pc_settings.montant_participation_cts`), même valeur pour toutes catégories |
| Mode paiement | Groupé en une session Stripe (N line_items) après la clôture admin |
| Déclencheur clôture | Bouton dédié dans wp-admin avec récap pré-clôture |
| Stockage catégorie photo | Colonne `category_id` (FK logique) sur `wp_pc_photos` |
| Migration | Big bang sur l'édition en cours ; catégorie « Photo Club Pavillonnais » par défaut pour le legacy |
| Envoi emails clôture | WP-Cron par lots, taille et intervalle paramétrables |
| Relances impayés | WP-Cron quotidien, 2 relances max par défaut, espacées de 5 jours |
| Photo non payée après dernière relance | Reste en `participation_demandee` indéfiniment (gestion admin manuelle) |
| Paiement à l'inscription | Supprimé. Champ BDD `paiement_inscription_recu` conservé en lecture seule |

## 3. Modèle de données

### 3.1 Nouvelle table `wp_pc_categories`

```sql
CREATE TABLE wp_pc_categories (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom           VARCHAR(150)    NOT NULL,
  actif         TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Pas de soft-delete. L'admin désactive (`actif=0`) plutôt que supprimer si la catégorie a des photos.

### 3.2 Modification `wp_pc_photos`

```sql
ALTER TABLE wp_pc_photos
  ADD COLUMN category_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id,
  ADD KEY idx_category (category_id),
  ADD KEY idx_user_category (user_id, category_id);
```

`category_id=0` réservé exclusivement à la fenêtre de migration. Après migration : invariant `category_id > 0` pour toute photo. Pas de contrainte SQL FK (cohérent avec le reste du plugin) ; intégrité gérée côté PHP.

### 3.3 Modification `wp_pc_payments`

```sql
ALTER TABLE wp_pc_payments
  ADD COLUMN payment_token        VARCHAR(64) NULL AFTER reference_externe,
  ADD COLUMN email_envoye_at      DATETIME    NULL AFTER payment_token,
  ADD COLUMN derniere_relance_at  DATETIME    NULL AFTER email_envoye_at,
  ADD COLUMN nb_relances          TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER derniere_relance_at,
  ADD KEY idx_payment_token       (payment_token),
  ADD KEY idx_email_pending       (email_envoye_at, statut_paiement);
```

`payment_token` regroupe N paiements (1 par photo retenue) d'un même candidat en une transaction Stripe unique.

### 3.4 Aucune modification sur les autres tables

`wp_pc_profiles`, `wp_pc_jury_votes`, `wp_pc_catalogue_items`, `wp_pc_email_tokens` restent inchangés. Le champ `wp_pc_profiles.paiement_inscription_recu` est conservé mais devient lecture seule (plus mis à jour).

### 3.5 Nouvelles options dans `pc_settings`

| Clé | Type | Défaut | Description |
|---|---|---|---|
| `email_batch_size` | int | 20 | Nombre d'emails envoyés par tick cron (borne 1-100) |
| `email_batch_interval_minutes` | int | 5 | Intervalle entre deux ticks (borne 1-60 min) |
| `relance_jours` | int | 5 | Délai sans paiement avant relance (borne 1-30) |
| `relance_max` | int | 2 | Nombre maximum de relances par candidat (borne 1-5) |
| `cloture_effectuee_at` | int | 0 | Timestamp de la dernière clôture (traçabilité) |

La sémantique de **deux options existantes change** :
- `quota_photos` : « max photos **par catégorie** » au lieu de « max photos total ». Étiquette UI mise à jour.
- `montant_participation_cts` : « montant **par photo retenue** » au lieu de « montant d'inscription unique ». L'admin doit revoir cette valeur (actuellement 1500 = 15 € d'inscription) lors du déploiement, car le sens change radicalement. Étiquette UI mise à jour.

## 4. Admin — gestion des catégories

### 4.1 Page wp-admin

- **Menu** : `Concours Photo > Catégories`
- **Capability** : `pc_manage_contest_settings`
- **Slug** : `pc-categories`

### 4.2 Écran de liste

Tableau avec colonnes : Nom · Actif · Photos · Actions. Formulaire d'ajout en pied de page (nom + bouton « Ajouter »).

Règles :
- **Suppression** autorisée uniquement si `COUNT(photos) = 0` ; sinon bouton remplacé par « Désactiver ».
- **Désactivation** toujours possible ; la catégorie disparaît du select à l'upload, mais les photos liées restent fonctionnelles dans tout le pipeline.
- **Édition** : modification du nom uniquement ; le statut actif est un toggle inline.

### 4.3 Endpoints AJAX

| Action | Nonce | Capability | Effet |
|---|---|---|---|
| `pc_add_category` | `pc_categories_nonce` | `pc_manage_contest_settings` | Création |
| `pc_update_category` | `pc_categories_nonce` | idem | Renommage |
| `pc_toggle_category` | `pc_categories_nonce` | idem | Bascule actif/inactif |
| `pc_delete_category` | `pc_categories_nonce` | idem | Suppression (vérifie 0 photos, sinon erreur) |

### 4.4 Classe `PC_Categories`

Nouveau fichier `includes/class-pc-categories.php`. Méthodes statiques pures (cohérent avec `PC_Database`) :

```php
class PC_Categories {
    public static function get_all( bool $only_active = false ): array;
    public static function get( int $id ): ?array;
    public static function create( string $nom ): int;
    public static function update( int $id, string $nom ): bool;
    public static function toggle( int $id ): bool;
    public static function delete( int $id ): bool|WP_Error;
    public static function count_photos( int $id ): int;
}
```

## 5. Flux dépôt candidat

### 5.1 Page `/mon-espace-candidat/`

La galerie est désormais segmentée en sections, une par catégorie **active**. Chaque section affiche son compteur `n / quota_max`, ses vignettes, et son bouton « + Ajouter » pré-affecté à cette catégorie.

Le compteur est calculé via `COUNT(*) FROM wp_pc_photos WHERE user_id=X AND category_id=Y`.

### 5.2 Sélection de la catégorie à l'upload

Pas de menu déroulant. Le bouton « + Ajouter » de chaque section porte la catégorie en paramètre implicite. Une photo ne peut être uploadée que dans une catégorie active.

### 5.3 Photo figée à sa catégorie

Une fois uploadée, la photo conserve sa `category_id` pour toute sa durée de vie. Aucune édition possible côté candidat ni côté admin (décision tranchée). En cas d'erreur, le candidat supprime et redépose.

### 5.4 Modifications de `PC_Photos::ajax_upload()`

1. Lecture du nouveau paramètre `category_id` du POST.
2. Validation : `category_id > 0`, catégorie existe, catégorie a `actif=1`.
3. Validation quota : `COUNT(*) WHERE user_id=X AND category_id=Y < pc_settings.quota_photos`.
4. Insertion avec `category_id` rempli.
5. Réponse JSON inclut `category_id` pour permettre au JS de ranger la vignette dans la bonne section.

### 5.5 Modifications de `PC_Photos::ajax_get_photos()` (alias actuel `pc_get_photos`)

Réponse enrichie :

```json
{
  "categories": [
    { "id": 1, "nom": "Photo Club Pavillonnais", "quota": 5, "photos": [...] },
    { "id": 2, "nom": "Faune dans la ville", "quota": 5, "photos": [...] }
  ],
  "stats_statuts": { ... }
}
```

### 5.6 Backward compat

Si une photo ancienne a `category_id=0` (cas exceptionnel post-migration), elle apparaît dans une section « Non classées » non éditable et est masquée du sélecteur jury. Signalé à l'admin via une notice.

## 6. Flux jury

### 6.1 Page `/espace-jury/`

Une barre de filtres en haut : `Catégorie : [ Toutes ▾ ] [ ... ]`. Filtre UI uniquement (la règle de décision reste unique pour toutes catégories).

Chaque vignette jury affiche un badge subtil avec le nom de la catégorie sous le numéro interne anonymisé. L'identité du candidat reste invisible (anonymisation préservée).

### 6.2 API `pc_jury_get_photos`

Ajout d'un paramètre optionnel `category_id` pour filtrer côté serveur. Réponse enrichie avec `nom_categorie` pour chaque photo.

### 6.3 Suppression de la cascade automatique

**Changement majeur dans `PC_Jury::apply_decision()` :**
- Avant : vote suffisant → photo passe en `retenue` → cascade automatique vers `participation_demandee` → email Fluent CRM individuel.
- Après : vote suffisant → photo passe en `retenue` → **stop**. Aucun email envoyé, aucun paiement créé.

La transition `retenue → participation_demandee` est désormais déclenchée **exclusivement** par la clôture admin (section 7).

### 6.4 Backward compat

Les photos déjà en `participation_demandee` au moment de la mise en production (4 entrées en local) restent dans cet état. Leur paiement individuel à l'ancien modèle continue à fonctionner via l'ancien webhook jusqu'à passage en `paiement_recu`.

## 7. Clôture délibération + paiement groupé

### 7.1 Nouvelle page admin « Clôture délibération »

- **Menu** : `Concours Photo > Clôture délibération`
- **Capability** : `pc_manage_payments`
- **Slug** : `pc-cloture`

### 7.2 Récap pré-clôture

Page affichant en temps réel :
- Photos en délibération à figer en `refusee` (statuts `en_attente` et `en_cours_examen`)
- Photos retenues par le jury (à passer en `participation_demandee`)
- Nombre de candidats à notifier
- Total à encaisser (montant_participation_cts × nb photos retenues)
- Avertissement IRRÉVERSIBILITÉ

Chiffres calculés via `COUNT(*)` côté serveur, mis en cache transient 30 secondes.

### 7.3 Action de clôture (POST avec nonce `pc_cloture_nonce`)

1. Vérif capability + nonce.
2. Pose du flag `pc_settings.cloture_en_cours = time()` (verrouillage anti-double-clic).
3. Bascule en lot des statuts `en_attente` et `en_cours_examen` → `refusee` (un `UPDATE` SQL unique).
4. Pour chaque `user_id` distinct ayant au moins une photo en `retenue` :
   - Génération d'un `payment_token` unique (UUID).
   - Pour chaque photo retenue de ce user : `UPDATE wp_pc_photos SET statut='participation_demandee'` et `INSERT INTO wp_pc_payments (photo_id, user_id, montant_centimes, statut_paiement='en_attente', payment_token=token)`.
5. Planification immédiate du premier tick d'envoi d'emails : `wp_schedule_single_event( time(), 'pc_send_payment_email_batch' )`.
6. Mise à jour : `pc_settings.jury_actif = 0`, `pc_settings.cloture_en_cours = null`, `pc_settings.cloture_effectuee_at = time()`.

Réponse à l'admin : « Clôture effectuée. X candidats en file d'envoi. Les emails partent par lots. »

### 7.4 Endpoint `pc_pay` (génération session Stripe)

Nouveau handler sur **`plugins_loaded` priorité 20** (cf. `skills/elementor-gotchas` pour le contexte) :

```php
add_action( 'plugins_loaded', [ $this, 'maybe_handle_payment' ], 20 );

public function maybe_handle_payment(): void {
    if ( empty( $_GET['pc_pay'] ) ) return;
    while ( ob_get_level() > 0 ) ob_end_clean();

    $token = sanitize_text_field( $_GET['pc_pay'] );
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM wp_pc_payments
         WHERE payment_token = %s AND statut_paiement = 'en_attente'",
        $token
    ) );

    if ( ! $rows ) wp_die( __( 'Lien invalide ou paiement déjà effectué.' ) );

    // Si non connecté → connecter d'abord (auth_url)
    // Sinon → créer session Stripe avec N line_items
    // wp_safe_redirect( $session->url ); exit;
}
```

Génération de la session Stripe : un `line_item` par photo (`name = titre_photo`, `quantity = 1`, `unit_amount = montant_participation_cts`). Stockage de `cs_xxxxx` dans `reference_externe` des N paiements liés au token.

### 7.5 Webhook Stripe — adaptation multi-paiement

`PC_Payments::handle_webhook()` :

1. Récupère `session_id` depuis l'event `checkout.session.completed`.
2. `SELECT * FROM wp_pc_payments WHERE reference_externe = session_id` → N lignes.
3. Pour chaque ligne :
   - `UPDATE wp_pc_payments SET statut_paiement='paiement_recu'`
   - `UPDATE wp_pc_photos SET statut='paiement_recu' WHERE id=photo_id`
4. Création des N entrées dans `wp_pc_catalogue_items` (cascade existante).
5. Un seul trigger Fluent CRM `pc_paiement_recu_groupe` avec `nb_photos`, `montant_total`.

**Idempotence** : si le webhook est reçu deux fois pour le même `cs_xxxxx`, l'`UPDATE` est sans effet (statut déjà `paiement_recu`) et aucune entrée catalogue n'est dupliquée (vérification `INSERT IGNORE` ou check préalable).

### 7.6 Disparition du paiement à l'inscription

- Suppression de l'action AJAX `pc_create_inscription_session` dans `PC_Payments`.
- Suppression de la section « Étape 3 : Participation » dans `templates/profile.php`.
- `PC_Registration::get_etape()` ne retourne plus jamais `paiement_requis` : passage direct de `reglement_non_accepte` à `complet` après acceptation du règlement.
- `wp_pc_profiles.paiement_inscription_recu` conservé en BDD mais lecture seule.

## 8. Catalogue

### 8.1 Tableau de composition

Sections par catégorie active. L'éditeur drague-dépose à l'intérieur d'une catégorie. L'ordre global du PDF suit l'ordre des catégories (par `id` croissant = ordre de création).

### 8.2 Exports

| Format | Changement |
|---|---|
| PDF (mPDF) | Un seul PDF, page de séparation par catégorie en début de chapitre |
| CSV (BOM UTF-8) | Nouvelle colonne `categorie` entre `candidat` et `titre` |
| JSON | Structure groupée : `{ "categories": [ { "id", "nom", "photos": [...] } ] }` |

### 8.3 Endpoints existants

- `pc_catalogue_get` : réponse enrichie avec groupement.
- `pc_catalogue_save_order` : sauvegarde de l'ordre par couple `(category_id, photo_id)`.
- `pc_catalogue_export_*` : adaptés au nouveau format.

### 8.4 Backward compat

Les photos en `au_catalogue` issues de l'ancien flux sont migrées avec `category_id = 1` (Photo Club Pavillonnais) → apparaissent sous cette section.

## 9. Intégrations Fluent CRM + planification d'envois

### 9.1 Triggers modifiés

| Avant | Après |
|---|---|
| `pc_photo_retenue` (par photo) | Supprimé |
| `pc_participation_demandee` (par photo, lien individuel) | `pc_participation_demandee_groupee` (par candidat, lien groupé) |
| `pc_paiement_recu` (par photo) | `pc_paiement_recu_groupe` (par candidat) |
| `pc_photo_refusee` (par photo) | Inchangé en nom, déclenché en lot à la clôture |
| `pc_photo_au_catalogue` | Inchangé |

### 9.2 Nouveau trigger `pc_cloture_jury_effectuee`

Lancé une fois à la clôture, sans paramètre. Utile pour notification admin ou logging externe.

### 9.3 Champs custom Fluent CRM

Posés sur le contact à la clôture, lus dans les templates d'email :

- `pc_nb_photos_retenues` (int)
- `pc_montant_a_payer_cts` (int)
- `pc_payment_url` (string : `https://sdlp.test/?pc_pay=<token>`)

### 9.4 Envoi par lots via WP-Cron

Hook récurrent : `pc_send_payment_email_batch` (déclenché par la clôture, puis ré-auto-planifié).

Callback `PC_Payments::cron_send_batch()` :

```
1. SELECT user_id, payment_token, GROUP_CONCAT(photo_id), SUM(montant_centimes), nb_photos
   FROM wp_pc_payments
   WHERE email_envoye_at IS NULL AND statut_paiement = 'en_attente'
   GROUP BY user_id, payment_token
   LIMIT pc_settings.email_batch_size;

2. Pour chaque ligne :
   - Pose des champs custom Fluent CRM sur le contact
   - Trigger pc_participation_demandee_groupee
   - UPDATE wp_pc_payments SET email_envoye_at = NOW()
     WHERE user_id = X AND payment_token = Y

3. S'il reste des entrées en attente :
   wp_schedule_single_event(
     time() + (pc_settings.email_batch_interval_minutes * 60),
     'pc_send_payment_email_batch'
   );
```

Paramétrable via `Paramètres > Envoi d'emails` :
- `email_batch_size` (défaut 20, borne 1-100)
- `email_batch_interval_minutes` (défaut 5, borne 1-60)

Calcul indicatif affiché à l'admin : « Avec ces réglages, vous pouvez envoyer jusqu'à 240 emails par heure. »

### 9.5 Relances automatiques impayés

Hook récurrent quotidien : `pc_relance_impayes_daily` (event WP-Cron schedule `daily`).

Callback `PC_Payments::cron_relances_quotidiennes()` :

```
SELECT user_id, payment_token, GROUP_CONCAT(photo_id), SUM(montant_centimes)
FROM wp_pc_payments
WHERE statut_paiement = 'en_attente'
  AND email_envoye_at IS NOT NULL
  AND email_envoye_at < NOW() - INTERVAL pc_settings.relance_jours DAY
  AND nb_relances < pc_settings.relance_max
  AND ( derniere_relance_at IS NULL
        OR derniere_relance_at < NOW() - INTERVAL pc_settings.relance_jours DAY )
GROUP BY user_id, payment_token
LIMIT pc_settings.email_batch_size;
```

Pour chaque ligne :
- Trigger Fluent CRM `pc_relance_paiement` (nouveau, mêmes champs custom)
- `UPDATE wp_pc_payments SET derniere_relance_at = NOW(), nb_relances = nb_relances + 1 WHERE ...`

Paramétrable :
- `relance_jours` (défaut 5, borne 1-30)
- `relance_max` (défaut 2, borne 1-5)

**Désactivation** : si `relance_jours = 0` ou `relance_max = 0`, le cron quotidien ne fait rien (sentinel).

### 9.6 Comportement après dernière relance sans paiement

La photo reste en `participation_demandee` indéfiniment. Le lien Stripe reste valable. Aucun statut terminal automatique. L'admin gère manuellement les impayés via une vue dédiée (à prévoir dans la page Paiements existante : filtre « En attente depuis plus de X jours »).

### 9.7 Graceful degradation

Si Fluent CRM est inactif :
- `PC_Fluent_CRM::trigger()` log et continue.
- La clôture du jury fonctionne quand même (BDD mise à jour, paiements créés en `en_attente`).
- L'admin doit relancer les emails manuellement.
- Export CSV des candidats à notifier disponible dans la page Clôture.

## 10. Migration des données existantes

### 10.1 Stratégie : Big Bang sur l'édition en cours

Pas de mode de compatibilité long terme. Le nouveau code remplace l'ancien dès le déploiement.

### 10.2 Orchestration via `PC_Database::maybe_upgrade()`

Le fichier `class-pc-database.php` contient déjà une logique de version. Bump de `PC_VERSION` à `2.0.0` déclenche :

```
1. CREATE TABLE IF NOT EXISTS wp_pc_categories
2. INSERT IGNORE INTO wp_pc_categories (id, nom, actif) VALUES (1, 'Photo Club Pavillonnais', 1)
3. ALTER TABLE wp_pc_photos ADD COLUMN category_id ... (idempotent via SHOW COLUMNS)
4. UPDATE wp_pc_photos SET category_id = 1 WHERE category_id = 0
5. ALTER TABLE wp_pc_payments ADD COLUMN payment_token, email_envoye_at, derniere_relance_at, nb_relances
6. Pour chaque wp_pc_payments en statut_paiement='paiement_recu' : email_envoye_at = updated_at (cohérence historique)
7. Ajout des nouvelles clés pc_settings avec valeurs par défaut (uniquement si absentes)
8. Update pc_db_version
```

### 10.3 Idempotence

Chaque étape :
- Utilise `IF NOT EXISTS`, `INSERT IGNORE`, ou `SHOW COLUMNS LIKE` pour les ALTER.
- Re-exécution sans dommage. Le code n'assume jamais l'état initial.

### 10.4 Sécurité prod

Avant déploiement prod :
- Audit BDD prod (nb photos par statut, nb paiements_inscription_recu, taille tables).
- Backup BDD complet pré-déploiement.
- Test du script de migration sur copie de la prod.
- Si paiements d'inscription en prod : décision à valider (informellement « crédités », pas de remboursement, première photo retenue « offerte » ? → décision admin à prendre au cas par cas).

### 10.5 Données conservées

- `wp_pc_profiles.paiement_inscription_recu` : conservé en BDD, plus modifié.
- Entrées historiques de `wp_pc_payments` à 15 € (ancien modèle) : conservées telles quelles. Webhook ancien continue à fonctionner pour leurs sessions Stripe en cours.

## 11. Tests et critères de réussite

### 11.1 Scénarios Phase 1 — Catégories

1. **Admin** : créer 3 catégories, en désactiver 1 avec photos, échec de suppression d'une catégorie utilisée, succès de suppression d'une catégorie vide.
2. **Candidat dépôt** : voir 2 sections (actives), uploader dans chacune, atteindre le quota d'une catégorie, vérifier que l'upload est refusé au-delà.
3. **Jury** : voir le filtre catégorie, voter inchangé, anonymisation candidat préservée.
4. **Catalogue** : exports PDF/CSV/JSON groupés correctement par catégorie.
5. **Migration** : 8 photos legacy passent toutes à `category_id=1`.

### 11.2 Scénarios Phase 2 — Paiement

1. **Clôture admin** : récap pré-clôture cohérent, basculement de tous les statuts, création des paiements en lot, premier tick cron planifié.
2. **File d'envoi** : 89 candidats / batch 20 = 5 ticks à 5 min d'intervalle, vérification du séquencement et de l'absence de doublons.
3. **Endpoint `pc_pay`** : session Stripe avec N line_items, montant correct, token verrouillé après premier paiement.
4. **Webhook** : N photos passent en `paiement_recu` en une fois, entrées catalogue créées, trigger unique `pc_paiement_recu_groupe`.
5. **Webhook idempotent** : envoi double du même `cs_xxxxx` sans effet de bord.
6. **Relances** : J+5 → 1ère relance, J+10 → 2ème, J+15 → plus rien (`nb_relances=2` = max atteint).
7. **Disparition paiement inscription** : `/mon-profil/` ne montre plus l'étape 3 ; nouveau candidat passe direct de `reglement_non_accepte` à `complet`.
8. **Régression** : flux ancien (photos déjà en `paiement_recu` avant migration) inchangé.

### 11.3 Critères globaux de réussite

- Aucune régression sur les flux candidat connecté / dépôt photo / jury vote / catalogue export.
- Aucune entrée orpheline (`wp_pc_photos.category_id=0` après migration).
- Aucun email envoyé en rafale supérieure à `email_batch_size` dans une fenêtre d'une minute.
- Webhook idempotent vérifié.
- Pas de leak d'identité candidat côté jury.

## 12. Découpage en deux plans d'implémentation

### 12.1 Plan 1 — Catégories (Système A)

**Indépendant**, livrable autonome. Le paiement à l'inscription continue à fonctionner pendant cette phase.

Périmètre :
- Migration BDD : table `wp_pc_categories` + colonne `category_id` sur `wp_pc_photos` + insertion catégorie par défaut + UPDATE legacy.
- Classe `PC_Categories` + page admin `Concours Photo > Catégories` + endpoints AJAX.
- Modification de `PC_Photos::ajax_upload()` (param `category_id`, validation, insertion).
- Modification de `PC_Photos::ajax_get_photos()` (réponse groupée).
- Modification de `templates/gallery.php` (sections par catégorie).
- Modification de `public/js/pc-gallery.js` (rendu par section, bouton ajout par catégorie).
- Modification de `templates/jury.php` + `public/js/pc-jury.js` (filtre + badge).
- Modification de `PC_Jury::get_photos_pour_jury()` (param category_id).
- Modification du module catalogue : `PC_Catalogue` (groupement) + exports PDF/CSV/JSON + template + JS.
- Adaptation du label `quota_photos` dans la page Paramètres.

### 12.2 Plan 2 — Paiement à la photo (Système B)

Dépend de la Phase 1 **uniquement** pour la mention de la catégorie dans certains emails (champ enrichi mais pas bloquant).

Périmètre :
- Migration BDD : colonnes additionnelles sur `wp_pc_payments`.
- Désactivation du paiement à l'inscription : suppression de l'action AJAX `pc_create_inscription_session`, de la section profil étape 3, du retour `paiement_requis` dans `PC_Registration::get_etape()`.
- Désactivation de la cascade auto `retenue → participation_demandee` dans `PC_Jury::apply_decision()`.
- Nouvelle page admin `Concours Photo > Clôture délibération` + récap pré-clôture + bouton + verrouillage anti-double-clic.
- Logique de clôture (bascule en lot + création des paiements + planification cron).
- Endpoint `?pc_pay=token` sur `plugins_loaded` priorité 20 + génération session Stripe avec line_items multiples.
- WP-Cron `pc_send_payment_email_batch` (envoi par lots).
- WP-Cron `pc_relance_impayes_daily` (relances).
- Adaptation `PC_Payments::handle_webhook()` pour traitement multi-paiement + idempotence.
- Nouveaux triggers Fluent CRM `pc_participation_demandee_groupee`, `pc_paiement_recu_groupe`, `pc_relance_paiement`, `pc_cloture_jury_effectuee`.
- Nouveaux champs custom Fluent CRM `pc_nb_photos_retenues`, `pc_montant_a_payer_cts`, `pc_payment_url`.
- Page Paramètres : ajout des 4 nouveaux réglages (batch_size, batch_interval, relance_jours, relance_max).

### 12.3 Ordre recommandé

Phase 1 **puis** Phase 2. Permet de valider la catégorisation (et notamment la migration legacy) avant de toucher au paiement, qui est le sujet financièrement le plus sensible.
