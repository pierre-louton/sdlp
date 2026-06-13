# SDLP — Photo Contest Manager

Plugin WordPress sur mesure de gestion d'un concours photo international, développé pour le
**Photo Club Pavillonnais**. Il prend en charge le cycle de vie complet d'une édition :
inscription des candidats, dépôt et paiement des photos, délibération anonymisée du jury,
et composition du catalogue d'exposition. Dimensionné pour **7 000 à 10 000 candidats par an**.

> Nom de code interne : **SDLP** (Semaines de la Photo).

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Cycle de vie d'une photo](#cycle-de-vie-dune-photo)
- [Stack technique](#stack-technique)
- [Installation](#installation)
- [Configuration](#configuration)
- [Pages et shortcodes](#pages-et-shortcodes)
- [Structure du plugin](#structure-du-plugin)
- [Développement et tests](#développement-et-tests)
- [Déploiement](#déploiement)
- [Licence](#licence)

## Fonctionnalités

- **Inscription publique** des candidats avec vérification d'email et acceptation du règlement.
- **Dépôt de photos** par glisser-déposer (JPG, ratios 3:2 ou 2:3), vignettes générées via GD,
  titre lu depuis l'EXIF/IPTC et éditable. Rangement par **catégories en glisser-déposer**.
- **Paiement à l'inscription (avant jury)** : chaque photo doit être payée — via un **panier
  groupé Stripe Checkout** — avant la date de clôture des dépôts pour être examinée. Le candidat
  suit dans son profil ce qu'il a payé et ce qui reste à régler (« caddy »), avec relances
  automatiques à J-10 et J-5 avant la clôture.
- **Délibération du jury anonymisée** : le jury ne voit jamais l'identité du photographe, ni
  son nom dans le titre des œuvres ; seules les **photos payées** lui sont soumises.
- **Catalogue d'exposition** : composition éditoriale et exports **PDF** (mPDF), **CSV** et
  **JSON** (intégration InDesign).
- **Pilotage des phases par dates** : ouverture/clôture du dépôt programmées ; ouverture
  automatique du jury à la clôture du dépôt, et du catalogue à la clôture de la délibération.
  Comparaisons de dates ancrées sur le fuseau du site WordPress (robustes aux différences de
  fuseau serveur dev/prod).
- **Conformité RGPD** : mention d'information dans le profil + opt-in « être prévenu du prochain
  concours ».
- **Intégrations** : Fluent CRM (emails transactionnels et automations), Fluent Forms,
  Elementor Free.

## Cycle de vie d'une photo

```
dépôt → en_attente (non payée)
  ├─ paiement (panier) ──────────────► photo PAYÉE (reste en_attente côté jury)
  └─ non payée à la clôture du dépôt ─► écartée du jury, conservée ("non examinée")
        ▼ [clôture du dépôt par date]
jury : en_attente(payée) → en_examen → retenue / refusee
        ▼ [clôture de la délibération]
   retenue ─────────────────────────► au_catalogue
catalogue : composition + exports
```

Une photo est considérée **payée** dès qu'il existe pour elle un paiement `paiement_recu` dans
la table `wp_pc_payments`. Les transitions de statut jury sont orchestrées par `PC_Jury`
(retenue/refus) et la clôture de délibération.

## Stack technique

| Composant | Version |
|-----------|---------|
| PHP | 8.1 minimum (testé 8.3) |
| WordPress | 6.4 minimum (testé 6.9) |
| MariaDB | 11.4 |
| Stripe | `stripe/stripe-php ^13.0` (Checkout + webhook) |
| PDF | `mpdf/mpdf ^8.2` |
| Constructeur de page | Elementor Free 3.x |
| Email / CRM | Fluent CRM, Fluent Forms |

**Environnements** : dev sur Laragon (Windows), prod sur O2Switch (AccelerateWP / cache Nginx).

## Installation

Le plugin vit dans `wp-content/plugins/photo-contest/`.

```bash
# 1. Dépendances Composer (Stripe + mPDF)
cd wp-content/plugins/photo-contest
composer install

# 2. Activer le plugin
wp plugin activate photo-contest

# 3. Lancer la création / mise à jour des tables
wp eval 'PC_Database::maybe_upgrade();'
```

L'activation crée les rôles (`pc_candidat`, `jurymembre`, `pc_catalogue_editor`), les
capabilities, les tables `wp_pc_*` et la *rewrite rule* de la page de connexion.

## Configuration

Réglages dans **Concours Photo → Paramètres** (`pc_settings`) :

- **Concours** : nom, logo, règlement (PDF), édition, **calendrier** (ouverture/clôture du dépôt),
  mention RGPD.
- **Photos** : quota par catégorie, poids maximum.
- **Paiement** : montant par photo, devise, clés **Stripe** (`sk_…`, `pk_…`, `whsec_…`), mode
  test/live. URL du webhook à déclarer dans Stripe : `<home_url>/?pc_stripe_webhook=1`
  (événement `checkout.session.completed`).
- **Relances** : offsets J-10 / J-5 avant la clôture du dépôt.
- **Fluent CRM** : IDs de listes et tags ; automations `pc_paiement_confirme`,
  `pc_relance_paiement` à créer côté Fluent CRM pour l'envoi réel des emails.

## Pages et shortcodes

Le plugin attend que des options pointent vers des pages WordPress contenant chaque shortcode :

| Page | Slug type | Shortcode | Accès |
|------|-----------|-----------|-------|
| Inscription | `inscription-photographe` | `[photo_contest_inscription]` | Public |
| Mon profil | `mon-profil` | `[photo_contest_profile]` | Candidat |
| Mon espace | `mon-espace-candidat` | `[photo_contest_gallery]` | Candidat |
| Espace jury | `espace-jury` | `[photo_contest_jury]` | Jury |
| Catalogue | `catalogue` | `[photo_contest_catalogue]` | Éditeur catalogue |

> La page de connexion (`/connexion-photographe/`) n'est pas une page WordPress : c'est une
> *rewrite rule* gérée par `PC_Security`, dont le slug est configurable.
>
> ⚠️ La page d'inscription et la page de profil **doivent être distinctes** (sinon boucle de
> redirection).

## Structure du plugin

```
wp-content/plugins/photo-contest/
├── photo-contest.php          # Bootstrap : constantes, autoload, activation
├── composer.json              # Dépendances Stripe + mPDF
├── includes/                  # Classes métier (PC_*)
│   ├── class-pc-database.php  # Schéma BDD, migrations
│   ├── class-pc-settings.php  # Réglages + état des phases (dates)
│   ├── class-pc-security.php  # Login custom, rate limit
│   ├── class-pc-registration.php
│   ├── class-pc-profile.php
│   ├── class-pc-photos.php    # Upload, vignettes, EXIF, catégories, titres
│   ├── class-pc-jury.php      # Délibération anonymisée
│   ├── class-pc-payments.php  # Caddy, panier Stripe, webhook, relances, clôture
│   ├── class-pc-catalogue.php # Composition + exports PDF/CSV/JSON
│   ├── class-pc-shortcodes.php
│   └── class-pc-fluent-crm.php
├── admin/                     # Pages wp-admin (paramètres, paiements, clôture)
├── public/                    # CSS et JS front
├── templates/                 # Templates PHP des shortcodes
└── tests/                     # Scripts de test (harness) (sans PHPUnit)
```

**Conventions** : tout est préfixé `pc_` (classes `PC_*`, tables `wp_pc_*`, options, hooks,
capabilities). Sécurité WordPress systématique (nonces, capabilities, sanitize/escape,
`$wpdb->prepare`). Voir `CLAUDE.md` pour le guide complet.

## Développement et tests

Tests sous forme de **scripts de test légers maison (« harness »)** (pas de PHPUnit), à lancer en CLI :

```bash
php wp-content/plugins/photo-contest/tests/test-pc-caddy.php
php wp-content/plugins/photo-contest/tests/test-pc-phases.php
php wp-content/plugins/photo-contest/tests/test-pc-galerie.php
php wp-content/plugins/photo-contest/tests/test-pc-profil.php
php wp-content/plugins/photo-contest/tests/test-pc-categories.php
```

Chaque harness boote WordPress via `wp-load.php`, crée ses propres données de test et les
nettoie en fin de run (sortie : `N tests · M échecs`, code de sortie non nul si échec).

Les specs et plans d'implémentation sont archivés sous `docs/superpowers/`.

## Déploiement

1. `composer install --no-dev` dans le plugin.
2. Déployer le code ; la constante `PC_VERSION` déclenche `PC_Database::maybe_upgrade()`
   (migrations idempotentes) — vérifier `wp option get pc_db_version`.
3. Renseigner les clés Stripe (mode `live`) et déclarer le webhook.
4. Créer les automations Fluent CRM.
5. Dérouler le guide `docs/superpowers/validation-2.1.0.md` (Stripe live, fuseau horaire,
   Fluent CRM).

## Licence

Plugin propriétaire développé pour le Photo Club Pavillonnais. Tous droits réservés.

**Auteur** : Pierre Beaubié — Photo Club Pavillonnais.
