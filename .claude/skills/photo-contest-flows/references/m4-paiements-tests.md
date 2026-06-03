# M4 — Plan de test : flux de paiement groupé par token (Phase 2)

> Plan de validation du flux de paiement groupé introduit en Phase 2 (Task 9, commit `6e3581d`).
> Couvre l'endpoint `/?pc_pay=<token>`, la clôture délibération, le webhook Stripe et la cascade de statuts.
>
> Fichier de code testé : `wp-content/plugins/photo-contest/includes/class-pc-payments.php`
> — endpoint `maybe_handle_payment_link()`, clôture `execute_cloture()`,
>   webhook `on_checkout_completed()` + `on_token_checkout_completed()`.

## Rappel du flux

```
Clôture jury → 1 payment_token (UUID v4) par candidat
            → photos retenue → participation_demandee
            → INSERT 1 ligne/photo dans wp_pc_payments (en_attente, même token)
                    ↓
Cron pc_send_payment_email_batch → email avec lien home_url('/?pc_pay=<token>')
                    ↓
Candidat clique → maybe_handle_payment_link() (plugins_loaded:20, Elementor-safe)
            → valide token UUID v4 + ownership user_id → Stripe Checkout
              (1 line_item par photo en_attente, metadata pc_payment_token + pc_user_id)
                    ↓
Stripe → webhook checkout.session.completed → on_checkout_completed()
            → détecte pc_payment_token → on_token_checkout_completed()
            → toutes les lignes en_attente du token → paiement_recu
            → reference_externe = payment_intent (posée ICI uniquement)
            → PC_Photos::update_statut('paiement_recu') par photo
            → cascade Fluent CRM (tag "Payé") + entrée wp_pc_catalogue_items
```

## Environnement & pré-requis

| Élément | Attendu |
|---|---|
| Plugin actif | `photo-contest/photo-contest.php` dans `active_plugins` |
| Version | `PC_VERSION = 2.0.0` |
| Tables | `wp_pc_payments`, `wp_pc_photos`, `wp_pc_catalogue_items` |
| Colonnes `wp_pc_payments` | `payment_token` (varchar 64, indexée), `statut_paiement` (enum), `reference_externe` (varchar 255), `email_envoye_at`, `nb_relances`, `derniere_relance_at`, `montant_centimes`, `devise`, `methode` |
| Webhook URL | `<home_url>/?pc_stripe_webhook=1` |

**WP-CLI** : non installé sous ce Laragon. Utiliser le client MySQL directement :
`C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` (lecture pour vérifier, écriture pour setup de test en dev uniquement).

### ⚠️ Piège timezone à vérifier

CLAUDE.md documente Laragon MySQL en **UTC**. Mesure du 2026-06-03 : ce MySQL répond en heure **locale (+02:00)**. Avant de juger un cron « en retard » ou « en cooldown », comparer `email_envoye_at` / `derniere_relance_at` avec `NOW()` **du même serveur**, jamais avec l'heure UTC. À reconfirmer selon la machine.

### 🚧 Bloquants config Stripe (à régler avant scénarios 4–7)

Lus dans `pc_settings` au 2026-06-03 — **à corriger pour les tests live** :
- `stripe_secret_key` = placeholder (pas une `sk_test_...`) → `Session::create()` échoue en 502
- `stripe_webhook_secret` = **vide** → tout webhook rejeté en 400, complétion impossible
- `stripe_publishable_key` = une adresse email (mal renseignée)
- `montant_participation_cts = 200` → 2,00 € par photo (référence pour les montants)

En local, récupérer le `whsec_...` via :
```
stripe login
stripe listen --forward-to "https://sdlp.test/?pc_stripe_webhook=1"
```
Carte test : `4242 4242 4242 4242`, date future, CVC quelconque.

---

## Scénario 1 — Préparation : photos `retenue` (jury)

**But** : ≥ 2 photos `retenue` pour 1 candidat (+ idéalement 1 photo pour un 2e candidat → test multi-token).

**Option A (réaliste)** : voter R via `/espace-jury/` (rôle `jurymembre`) jusqu'au seuil de décision.

**Option B (raccourci dev, DESTRUCTIF — valider avant exécution)** :
```sql
UPDATE wp_pc_photos SET statut='retenue' WHERE id IN (50,51,52,53);
-- multi-candidat : affecter une photo à un 2e user_id puis la passer 'retenue'
```

**Vérif** :
```sql
SELECT user_id, COUNT(*) FROM wp_pc_photos WHERE statut='retenue' GROUP BY user_id;
```
✅ ≥ 1 candidat avec ≥ 1 photo `retenue`.

---

## Scénario 2 — Clôture délibération → génération des tokens groupés

**Action** : page admin « Clôture délibération » (bouton irréversible) → `PC_Payments::execute_cloture()`.
Sans WP-CLI : via la page wp-admin, ou un script PHP one-shot chargeant `wp-load.php`.

**Vérif AVANT** :
```sql
SELECT statut, COUNT(*) FROM wp_pc_photos GROUP BY statut;
SELECT COUNT(*) FROM wp_pc_payments;          -- attendu : 0
```

**Vérif APRÈS** :
```sql
-- 1 token par candidat, N lignes par token
SELECT user_id, payment_token, COUNT(*) nb_photos,
       SUM(montant_centimes) total_cts,
       GROUP_CONCAT(statut_paiement) statuts
FROM wp_pc_payments
GROUP BY user_id, payment_token;

-- les photos retenues passent en participation_demandee
SELECT statut, COUNT(*) FROM wp_pc_photos GROUP BY statut;

-- plus aucune photo en délibération
SELECT COUNT(*) FROM wp_pc_photos WHERE statut IN ('en_attente','en_examen'); -- attendu : 0

-- format UUID v4 des tokens
SELECT DISTINCT payment_token FROM wp_pc_payments
WHERE payment_token NOT REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';
-- attendu : 0 ligne
```

**Critères de succès** :
- 1 `payment_token` UUID v4 **distinct par candidat**, partagé par toutes ses lignes
- Nombre de lignes du token = nombre de photos retenues du candidat
- Lignes : `statut_paiement='en_attente'`, `montant_centimes=200`, `devise='EUR'`, `methode='stripe_checkout'`, `reference_externe=NULL`, `email_envoye_at=NULL`, `nb_relances=0`
- Photos `retenue` → `participation_demandee` ; reste de la délibération → `refusee`
- `pc_settings` : `jury_actif=false`, `cloture_effectuee_at`=timestamp, `cloture_en_cours=0`
- Event cron `pc_send_payment_email_batch` planifié

**Critères d'échec (BLOQUANTS)** :
- Un même token sur 2 `user_id` → bug de groupement
- Lignes en double pour une photo → double exécution (vérifier le verrou `cloture_en_cours` 300 s)
- `montant_centimes` ≠ 200 → mauvaise lecture du réglage

---

## Scénario 3 — Endpoint `/?pc_pay=<token>` : cas limites (sans Stripe)

Hook `plugins_loaded:20` → `maybe_handle_payment_link()`. Testables au navigateur dès qu'un token existe (les validations précèdent l'appel Stripe, sauf 3g).

| # | Cas | URL / condition | Attendu |
|---|---|---|---|
| 3a | Token malformé | `/?pc_pay=not-a-uuid` | `wp_die` **400** « Lien invalide. » |
| 3b | UUID v4 inexistant | `/?pc_pay=<uuid v4 random>` | `wp_die` **404** « Lien invalide ou expiré. » |
| 3c | Token déjà entièrement payé | toutes lignes = `paiement_recu` | `wp_die` **410** « Ce paiement a déjà été effectué. » |
| 3d | Non connecté | token valide, déconnecté | **302** vers `/connexion/?redirect_to=<.../?pc_pay=token>` |
| 3e | Mauvais user | token de user A, connecté en user B | `wp_die` **403** « Ce lien ne vous est pas destiné. » |
| 3f | vendor/autoload absent | (théorique) | **503** + log |
| 3g | clé Stripe vide | `stripe_secret_key=''` | **503** « Service de paiement non configuré. » |

Fabriquer un cas 3c (DESTRUCTIF, valider avant) :
```sql
UPDATE wp_pc_payments SET statut_paiement='paiement_recu' WHERE payment_token='<token>';
```

**Gardes critiques de sécurité** : filtrage `en_attente` + ownership `get_current_user_id() !== $user_id`. Le cas 3e ne doit JAMAIS aboutir à une session Stripe.

> Note : avec une clé Stripe placeholder, un token valide + bon user passe les validations puis **échoue en 502** à `Session::create()`. Comportement attendu tant que la vraie clé n'est pas posée — pas un bug.

---

## Scénario 4 — Création session Stripe Checkout (nécessite `sk_test_`)

**Pré-conditions** : clé `sk_test_...` valide, connecté en propriétaire du token, ≥ 1 ligne `en_attente`.

**Étapes** :
1. Ouvrir `/?pc_pay=<token>` connecté en bon user
2. Vérifier la redirection 302 vers `checkout.stripe.com`
3. Sur Checkout : **1 line item par photo `en_attente`**, chacun à `unit_amount = montant_centimes` (200), libellé = titre photo (ou « Photo #id »), devise EUR
4. Dashboard Stripe : la session porte `metadata.pc_payment_token` et `metadata.pc_user_id`

**Critères de succès** :
- Nombre de line items = lignes `en_attente` du token (pas les déjà payées)
- Montant total Checkout = `nb_photos_en_attente × 200`
- `customer_email` = email du candidat
- **AUCUNE écriture en base à ce stade** : ligne reste `en_attente`, `reference_externe` toujours NULL

**Vérif APRÈS création (avant paiement)** :
```sql
SELECT photo_id, statut_paiement, reference_externe
FROM wp_pc_payments WHERE payment_token='<token>';
-- attendu : tout 'en_attente', reference_externe NULL
```

---

## Scénario 5 — Webhook `checkout.session.completed` : complétion nominale

**Pré-condition critique** : `stripe_webhook_secret = whsec_...` renseigné, sinon **400 systématique**.

**Vérif AVANT paiement** :
```sql
SELECT photo_id, statut_paiement, reference_externe FROM wp_pc_payments WHERE payment_token='<token>';
SELECT id, statut FROM wp_pc_photos
WHERE id IN (SELECT photo_id FROM wp_pc_payments WHERE payment_token='<token>');
```

**Vérif APRÈS paiement** :
```sql
-- toutes les lignes du token basculent
SELECT photo_id, statut_paiement, reference_externe, methode
FROM wp_pc_payments WHERE payment_token='<token>';
-- attendu : 'paiement_recu', reference_externe = pi_xxx (identique sur toutes), methode='stripe_checkout'

-- montant préservé par ligne (jamais écrasé par amount_total)
SELECT DISTINCT montant_centimes FROM wp_pc_payments WHERE payment_token='<token>'; -- attendu : 200

-- cascade photos
SELECT id, statut FROM wp_pc_photos
WHERE id IN (SELECT photo_id FROM wp_pc_payments WHERE payment_token='<token>'); -- attendu : 'paiement_recu'

-- catalogue alimenté
SELECT COUNT(*) FROM wp_pc_catalogue_items;
```

**Critères de succès** :
- Toutes les lignes `en_attente` du token → `paiement_recu`
- `reference_externe` = `payment_intent` (pi_…) **identique** sur toutes les lignes
- `montant_centimes` **inchangé** (200) — `amount_total` (N×200) ne doit JAMAIS écraser le montant unitaire
- Chaque photo → `paiement_recu` (cascade Fluent CRM + entrée catalogue)

**Critères d'échec** :
- Une seule ligne mise à jour sur N → boucle `foreach` cassée
- `montant_centimes` devenu N×200 → régression
- Photo non basculée → `update_statut` non appelé

---

## Scénario 6 — Idempotence (rejeu du webhook)

**But** : un 2e `checkout.session.completed` pour le même token ne change RIEN.

**Étapes** : après scénario 5, rejouer l'event (`stripe events resend <evt_id>` ou dashboard). Comparer `reference_externe` et `updated_at` avant/après.

```sql
SELECT id, statut_paiement, reference_externe, updated_at FROM wp_pc_payments WHERE payment_token='<token>';
-- ... rejeu ... aucune ligne ne doit changer
```

**Succès** : `on_token_checkout_completed` fait `SELECT ... WHERE statut_paiement='en_attente'` → 0 ligne au rejeu → `return` immédiat. `reference_externe` et `updated_at` inchangés, pas de doublon catalogue, pas de 2e email Fluent CRM.

**Échec (BLOQUANT)** : nouvelle entrée catalogue ou `updated_at` modifié.

---

## Scénario 7 — Paiement partiel déjà payé (token mixte)

**But** : si certaines lignes sont déjà `paiement_recu` et d'autres `en_attente`, un nouveau passage ne facture QUE les `en_attente`.

**Setup (DESTRUCTIF, valider avant)** :
```sql
UPDATE wp_pc_payments SET statut_paiement='paiement_recu', reference_externe='pi_manual'
WHERE id = <une ligne du token>;
```

**Étapes** : `/?pc_pay=<token>` → Checkout ne liste que les lignes restées `en_attente` → payer → webhook.

```sql
SELECT photo_id, statut_paiement, reference_externe FROM wp_pc_payments WHERE payment_token='<token>';
```

**Critères de succès** :
- Checkout facture seulement N restantes (montant = N_restantes × 200)
- La ligne déjà payée conserve son `reference_externe` d'origine (`pi_manual`), non écrasé
- Les lignes nouvellement payées prennent le nouveau `payment_intent`
- Si AUCUNE ligne `en_attente` au départ → **410** (cf. 3c) avant tout Stripe

---

## Scénario 8 — Cron envoi d'emails groupés (annexe)

`pc_send_payment_email_batch` envoie 1 email par groupe `(user_id, payment_token)` où `email_envoye_at IS NULL`, par lots de `email_batch_size=20`.

```sql
SELECT user_id, payment_token,
       MIN(email_envoye_at) envoye, MAX(nb_relances) relances
FROM wp_pc_payments
WHERE payment_token IS NOT NULL
GROUP BY user_id, payment_token;
```

**Critères** : après le tick, `email_envoye_at` renseigné (1 fois par groupe), pas de double envoi au re-tick (filtre `IS NULL`). Lien email = `home_url('/?pc_pay=<token>')`.

---

## Remise à zéro (NON destructif à valider — dev uniquement)

```sql
DELETE FROM wp_pc_payments WHERE payment_token='<token>';
UPDATE wp_pc_photos SET statut='en_examen' WHERE id IN (50,51,52,53);
DELETE FROM wp_pc_catalogue_items WHERE photo_id IN (50,51,52,53);
-- pc_settings : jury_actif=true, cloture_effectuee_at=0
```

---

## Synthèse

**Automatisable (lecture seule)** : environnement, version, schémas, snapshot BDD, format UUID des tokens, vérifs de statut avant/après chaque étape.

**Manuel (Stripe)** : scénarios 4–7 (Checkout + webhook) nécessitent une vraie `sk_test_` et un `whsec_` + `stripe listen`. Les cas 3a–3g de l'endpoint sont testables au navigateur dès qu'un token existe.

**À régler avant les tests live** : les 3 bloquants config Stripe (cf. section dédiée en haut).
