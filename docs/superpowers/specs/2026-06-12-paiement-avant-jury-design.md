# Paiement à l'inscription (avant jury) — Spec de conception

> Spec de conception — 2026-06-12
> Plugin : Photo Contest Manager (SDLP)
> Sous-projet 1/3 de la refonte du parcours candidat (les 2 autres : galerie glisser-déposer/titres, profil RGPD/opt-in).

## Dépendance

Ce sous-projet dépend de la feature « pilotage des phases par dates »
(`docs/superpowers/specs/2026-06-12-phases-par-dates-design.md`, PR #2) : il utilise
`PC_Settings::is_depot_actif()` et le réglage `date_fermeture_depot`. La branche
`feature/paiement-avant-jury` part de `feature/phases-par-dates` ; à merger après / à
rebaser sur `main` une fois la PR #2 intégrée.

## Objectif

Renverser le modèle de paiement : aujourd'hui le candidat dépose gratuitement, le jury
examine **toutes** les photos, et seules les photos retenues sont payées (pour le
catalogue). Désormais, **chaque photo doit être payée avant la date de clôture des
dépôts pour être examinée par le jury**. Les photos non payées sont écartées du jury
mais conservées. Le candidat suit dans son profil ce qu'il a payé et ce qu'il lui reste
à payer (« caddy »).

## Décisions de conception (validées en brainstorming)

1. **Tarification : prix fixe par photo**, réutilise le réglage existant
   `montant_participation_cts`. Total dû = (nb photos non payées) × prix unitaire.
2. **Photo non payée à la clôture** : écartée du jury, **conservée** (jamais supprimée),
   affichée « non payée — non examinée ». Le jury ne reçoit que les photos payées.
3. **Paiement en panier groupé** : une seule session Stripe Checkout pour toutes les
   photos non payées, via le mécanisme `payment_token` déjà en place.
4. **Non remboursable** : pas de remboursement si la photo est refusée par le jury ni si
   le candidat supprime une photo payée. La suppression d'une photo payée reste permise
   (sans remboursement).
5. **Relances à J-10 et J-5** avant `date_fermeture_depot`, aux candidats ayant ≥1 photo
   non payée. Remplace les relances quotidiennes post-paiement actuelles.
6. **Clôture délibération** : ne crée plus de paiements ni d'emails Stripe ; finalise
   seulement les votes. Une photo `retenue` part directement `au_catalogue`.

## Source de vérité « payée »

Une photo est **payée** si et seulement si il existe dans `wp_pc_payments` une ligne
liée à son `photo_id` avec `statut_paiement = 'paiement_recu'`. Pas de colonne
dénormalisée sur `wp_pc_photos` (évite toute désynchronisation). Les requêtes jury et
caddy utilisent un `EXISTS` sur `wp_pc_payments`. Un index dédié
`idx_photo_statut (photo_id, statut_paiement)` est ajouté pour la performance.

## Nouveau cycle de vie

```
dépôt → en_attente (non payée)
  ├─ candidat paie (panier) ─────────► photo PAYÉE (statut jury inchangé : en_attente)
  └─ non payée à la clôture dépôt ───► reste en_attente, écartée du jury
        ▼ [clôture dépôt par date]
jury examine UNIQUEMENT les photos payées :
        en_attente(payée) → en_examen → retenue / refusee
        ▼ [clôture délibération — finalise les votes]
   photos payées restantes (en_attente/en_examen) → refusee
   retenue ───────────────────────────► au_catalogue (direct, sans 2e paiement)
catalogue composé depuis au_catalogue (inchangé)
```

Les statuts photo `participation_demandee` et `paiement_recu` **sortent du flux**. La
valeur `paiement_recu` subsiste uniquement comme **statut de paiement** dans
`wp_pc_payments` (ENUM inchangé).

## Composants et changements

### 1. Caddy & tarification — `includes/class-pc-payments.php`

Nouvelles méthodes :

- `photo_est_payee( int $photo_id ): bool` — `EXISTS` paiement_recu pour cette photo.
- `get_caddy( int $user_id ): array` — agrège l'état de paiement des photos déposées du
  candidat :
  ```php
  [
    'prix_unitaire_cts' => int,   // montant_participation_cts
    'nb_payees'         => int,
    'nb_non_payees'     => int,
    'montant_paye_cts'  => int,   // nb_payees * prix_unitaire
    'montant_du_cts'    => int,   // nb_non_payees * prix_unitaire
    'total_cts'         => int,   // (nb_payees + nb_non_payees) * prix_unitaire
  ]
  ```
  Périmètre du caddy : les photos `en_attente` du candidat (l'état où une photo se
  trouve pendant la fenêtre de dépôt, avant toute action du jury). `nb_payees` = celles
  ayant une ligne `paiement_recu` ; `nb_non_payees` = les autres. Les photos déjà passées
  en `en_examen`/`retenue`/`refusee` (après ouverture du jury) ne sont plus payables et
  sortent du calcul du « reste à payer ».

### 2. Paiement panier — `includes/class-pc-payments.php`

Nouvelle action AJAX `wp_ajax_pc_create_caddy_session` (remplace l'amorce
`ajax_create_inscription_session`) :

- Vérifie nonce `pc_profile_nonce`, `is_user_logged_in()`, capability `pc_pay_participation`.
- Refuse si `! PC_Settings::is_depot_actif()` (dépôt fermé → plus de paiement possible).
- Sélectionne les photos `en_attente` du candidat **sans** ligne `paiement_recu`.
- Si aucune photo à payer → message d'erreur clair.
- Génère un `payment_token` (UUID v4), insère une ligne `en_attente` par photo
  (`montant_centimes = prix_unitaire`, `methode = 'stripe_checkout'`), puis renvoie l'URL
  de l'endpoint `/?pc_pay=<token>`.

Réutilise l'endpoint existant `maybe_handle_payment_link()` (`/?pc_pay=<token>`,
`plugins_loaded:20`, Elementor-safe) qui crée la session Stripe Checkout (1 line item par
photo) et redirige. **À adapter** : le wording produit doit refléter « participation
photo » et non « impression ».

Le **webhook** `checkout.session.completed` (existant) passe toutes les lignes du token en
`paiement_recu`. Aucun changement de statut photo. À vérifier : le handler webhook
actuel ne doit pas, dans le nouveau modèle, déclencher de transition photo vers
`paiement_recu`/`au_catalogue`.

### 3. Jury — `includes/class-pc-jury.php`

Dans `get_photos_pour_jury()`, ajouter au `WHERE` la contrainte de paiement :

```sql
WHERE p.statut IN ('en_attente','en_examen')
  AND EXISTS (
    SELECT 1 FROM {$payments} pay
    WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu'
  )
  {$cat_where}
```

Le jury ne voit donc jamais une photo non payée.

### 4. Clôture délibération — `includes/class-pc-payments.php::execute_cloture`

Réécriture de la séquence :

- **Supprimer** : la création des lignes `wp_pc_payments` par candidat, la planification
  du cron `pc_send_payment_email_batch`, la bascule vers `participation_demandee`.
- **Conserver** : le verrou anti-double-clic, la bascule des photos payées restantes
  (`en_attente`/`en_examen`) vers `refusee`, et la bascule `retenue → au_catalogue`.
- **Conserver** les flags existants : `jury_actif=false`, `catalogue_actif=true`
  (ajouté par la feature phases), `cloture_effectuee_at=time()`.
- `do_action( 'pc_cloture_jury_effectuee' )` conservé.

`get_cloture_stats()` est adaptée : elle ne calcule plus de « montant à encaisser »
(le paiement est déjà fait) mais reste informative (nb à refuser, nb retenues →
catalogue). La page admin « Clôture délibération » est mise à jour en conséquence
(retrait du récap financier, du total à encaisser).

#### Alimentation du catalogue (correction post-audit)

`PC_Catalogue` alimente aujourd'hui le catalogue quand une photo passe au statut
**`paiement_recu`** (`class-pc-catalogue.php:32`, `on_statut_changed`). Ce statut photo
**disparaît du flux** : sans correction, le catalogue ne se remplirait jamais. Le
déclencheur est déplacé de `paiement_recu` → **`au_catalogue`** : la création de l'entrée
catalogue se fait quand la clôture jury bascule `retenue → au_catalogue`. La requête
d'export (`class-pc-catalogue.php:65`) qui joint `wp_pc_payments` sur `paiement_recu` pour
afficher « Payé/En attente » reste correcte (toute photo au catalogue a, par construction,
une ligne `paiement_recu` payée avant le jury).

### 5. Relances J-10 / J-5 — `includes/class-pc-payments.php` (cron)

Le hook `pc_relance_impayes_daily` (déjà planifié quotidiennement) est réorienté :

- À chaque tick : lire `date_fermeture_depot`. Calculer le nombre de jours restants
  (côté PHP, ancré sur `wp_timezone()` — jamais SQL ni `strtotime()` nu, cf. feature
  phases).
- Si jours restants == `relance_offset_1` (défaut 10) ou == `relance_offset_2`
  (défaut 5), envoyer une relance à chaque candidat ayant ≥1 photo `en_attente` non
  payée.
- Anti-doublon : flag par offset et par édition (option
  `pc_relance_sent_{offset}_{date_fermeture}` ou meta utilisateur). Une relance donnée
  n'est envoyée qu'une fois.
- Déclenche l'automation Fluent CRM `pc_relance_paiement` avec les variables : nb photos
  non payées, montant dû, date de clôture. Dégradation gracieuse si Fluent CRM absent.

Réglages remplacés dans `PC_Settings::$defaults` et l'admin :
- `relance_jours` / `relance_max` → `relance_offset_1 = 10`, `relance_offset_2 = 5`
  (0 = relance désactivée pour cet offset).

### 6. UI candidat

**Profil — `templates/profile.php`** : bloc « caddy » affichant
- prix unitaire par photo,
- nb payées + montant payé,
- nb non payées + montant dû,
- bouton « Payer mes N photos non payées » (désactivé si dépôt fermé ou si rien à payer),
  déclenchant `pc_create_caddy_session`.

**Galerie — `templates/gallery.php`** : badge par photo
- « payée ✓ » si `photo_est_payee`,
- « non payée » pendant le dépôt (avec rappel du prix),
- « non payée — non examinée » une fois le dépôt clôturé (`! is_depot_actif()`).

Le bouton de paiement groupé vit dans le **profil** (caddy). La galerie n'affiche que les
badges (pas de paiement photo par photo).

### 7. Nettoyage du code legacy

Supprimer ou neutraliser :
- `creer_session_inscription()` et `ajax_create_inscription_session()` (amorce forfait
  candidat incomplète, remplacée par le panier).
- `ajax_create_session()` (paiement post-jury par photo, statut
  `participation_demandee`/`retenue`).
- `cron_send_batch()` + hook `pc_send_payment_email_batch` (emails de paiement post-jury
  par lots) et les réglages associés `email_batch_size` / `email_batch_interval_minutes`.
- Toute référence aux statuts photo `participation_demandee` / `paiement_recu` dans le
  flux (gallery, shortcodes, fluent-crm) à auditer et retirer.

> Audit requis avant suppression : `grep` sur `participation_demandee`,
> `paiement_recu`, `creer_session_inscription`, `ajax_create_session`,
> `pc_send_payment_email_batch`, `email_batch` pour lister tous les points d'appel.

## Capabilities

`pc_pay_participation` (existante) reste la capability requise pour payer. Pas de nouvelle
capability.

## Sécurité

- Nonce sur l'action AJAX panier ; vérification que les photos appartiennent au candidat
  courant (`user_id = get_current_user_id()`).
- Endpoint `/?pc_pay=<token>` : validation UUID stricte + appartenance candidat
  (déjà en place).
- Webhook : signature Stripe vérifiée (déjà en place) ; idempotence (ne pas re-traiter
  un token déjà `paiement_recu`).
- Montants calculés **côté serveur** uniquement (jamais depuis le POST client).

## Tests

Harness léger (modèle `tests/test-pc-*.php`), nouveau `tests/test-pc-caddy.php` :
- `get_caddy()` : 0 photo, N non payées, mix payées/non payées → montants corrects.
- `photo_est_payee()` : sans paiement, avec `en_attente`, avec `paiement_recu`.
- Jury : `get_photos_pour_jury()` exclut une photo non payée, inclut une payée.
- Panier : création des lignes `en_attente` sous un token unique pour les seules photos
  non payées ; refus si dépôt fermé.
- Clôture : `retenue → au_catalogue`, photos payées restantes → `refusee`, aucune ligne
  de paiement créée.
- Relances : ciblage correct (candidats avec photos non payées), anti-doublon par offset.

Tests Stripe **live** (session réelle + webhook) : manuels, via le subagent
`test-runner` (cf. mémoire « Phase 2 : tests Stripe live à faire »).

## Hors scope (YAGNI)

- Remboursements (modèle non remboursable).
- Paiement photo par photo (panier uniquement).
- Tarification dégressive / forfait + supplément.
- Modification des exports catalogue (inchangés).
- Galerie glisser-déposer / édition de titres (sous-projet 2).
- Mentions RGPD / opt-in prochain concours (sous-projet 3).

## Révisions post-audit du code

L'audit `grep` des points d'appel a révélé trois couplages que la première version de la
spec sous-estimait. Décisions validées :

### A. Onboarding : suppression du forfait d'inscription

Le profil contient une étape **active** « Participation » (`templates/profile.php:193-225`)
où le candidat paie un **forfait unique** (`montant_participation_cts`) à l'inscription,
*avant* de pouvoir déposer. La machine à états `PC_Registration::get_etape()` est :
`email_non_verifie → profil_incomplet → reglement_non_accepte → paiement_requis → complet`,
et `PC_Registration::peut_deposer()` exige `complet`.

**Décision : suppression du forfait.** Le modèle devient « paiement par photo » uniquement.

- `get_etape()` perd l'état `paiement_requis` :
  `… → reglement_non_accepte → complet`. `complet` = règlement accepté.
- `peut_deposer()` = `complet` (donc = règlement accepté). Le dépôt devient libre après
  acceptation du règlement.
- La colonne `paiement_inscription_recu` (table profiles) cesse d'être un gate (conservée
  en base, plus consultée — pas de migration destructive).
- Suppression de `PC_Registration::on_inscription_paiement_recu()`, du hook
  `pc_inscription_paiement_recu`, et de la branche `type === 'inscription'` du webhook
  (`on_checkout_completed`).
- `templates/profile.php` : retrait de la section « Étape 3 : Participation » forfaitaire.
  L'indicateur d'étapes passe à **2 étapes** (Profil, Règlement). Le **caddy** est une
  section du profil affichée une fois `complet`.

### B. Alimentation du catalogue (déjà documentée plus haut)

Déclencheur déplacé `paiement_recu` → `au_catalogue` dans `PC_Catalogue::on_statut_changed`.

### C. Webhook : ne plus basculer le statut photo

`on_token_checkout_completed()` (`class-pc-payments.php:504`) marque les paiements
`paiement_recu` **et** appelle `update_statut(photo, 'paiement_recu')`. Dans le nouveau
modèle, le paiement ne change **pas** le statut jury de la photo (elle reste `en_attente`).
On retire l'appel `update_statut`. Idem pour la branche mono-photo `on_checkout_completed`
(lignes 480-491), retirée avec le reste du paiement post-jury.

### D. Cases Fluent CRM mortes

`PC_Fluent_CRM::on_statut_change` traite `participation_demandee` (génère un lien de
paiement) et `paiement_recu` (tag payé + automation). Ces `case` deviennent inatteignables
(aucune photo n'atteint ces statuts) → suppression. Conserver `retenue`, `refusee`,
`au_catalogue`. Le tag « payé » (`fluent_tag_paye`) est désormais posé au webhook du panier.

### E. Relances : réécriture complète

`cron_relances_quotidiennes()` (`class-pc-payments.php:795`) est bâti sur l'ancien modèle
(relancer des lignes de paiement `en_attente` déjà initiées avec `email_envoye_at`). Le
nouveau cron est piloté par date (J-10 / J-5 avant `date_fermeture_depot`) et cible les
candidats ayant des **photos** `en_attente` non payées (pas des lignes de paiement). Le
lien de la relance pointe vers la **page profil** (caddy), pas vers `/?pc_pay=` (le
candidat n'a pas encore de token). Réécriture intégrale de la méthode.

## Fichiers touchés (prévision)

- `includes/class-pc-payments.php` (caddy, panier, webhook, clôture, relances, nettoyage)
- `includes/class-pc-jury.php` (`get_photos_pour_jury`)
- `includes/class-pc-catalogue.php` (alimentation sur `au_catalogue`)
- `includes/class-pc-registration.php` (machine à états sans `paiement_requis`, retrait inscription payée)
- `includes/class-pc-fluent-crm.php` (retrait des `case` morts)
- `includes/class-pc-database.php` (index `idx_photo_statut`, upgrade)
- `includes/class-pc-settings.php` (réglages relances, retrait email_batch)
- `includes/class-pc-shortcodes.php` (audit statuts `participation_demandee`)
- `photo-contest.php` (retrait alias statuts morts, `wp_clear_scheduled_hook` email batch)
- `admin/class-pc-admin.php` (réglages relances, retrait email_batch)
- `admin/class-pc-cloture-admin.php` (récap sans volet financier)
- `templates/profile.php` (retrait étape forfait, caddy)
- `templates/gallery.php` (badges payée/non payée)
- `templates/payment.php` (obsolète — à retirer)
- `public/js/` (déclenchement session panier)
- `tests/test-pc-caddy.php` (nouveau)
