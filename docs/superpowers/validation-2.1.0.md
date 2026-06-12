# Guide de validation manuelle — Photo Contest Manager (SDLP) 2.1.0

> Le code de la 2.1.0 est implemente et couvert par 65 tests unitaires verts.
> Il reste 3 validations manuelles / externes a realiser, par nature impossibles
> a automatiser : Stripe (service externe), fuseau horaire de prod O2Switch, et
> automations Fluent CRM (configuration cote CRM). Ce document les guide pas a pas.

---

## 0. Rappel pre-vol — migration de schema 2.1.0

Bumper la version puis deployer/charger le plugin declenche PC_Database::maybe_upgrade()
(appele sur admin_init via PC_Admin::maybe_upgrade_db()). La condition est simple :

    public static function maybe_upgrade(): void {
        if ( get_option( 'pc_db_version' ) !== PC_VERSION ) { // PC_VERSION = 2.1.0
            self::create_tables();
        }
    }

create_tables() rejoue (idempotent) les migrations de la 2.1.0 :
- index idx_photo_statut sur wp_pc_payments (photo_id, statut_paiement)
- colonne opt_in_prochain (TINYINT) sur wp_pc_profiles

### A faire APRES chaque deploiement (dev et prod)

1. Charger une page wp-admin une fois (declenche admin_init), ou forcer la migration :

       wp eval "PC_Database::maybe_upgrade();"

2. Verifier la version de schema :

       wp option get pc_db_version

   - OK : doit afficher 2.1.0
   - KO : la migration n'a pas tourne ; verifier que le plugin actif est bien la 2.1.0
     (wp plugin get photo-contest --field=version) puis relancer wp eval.

3. Verifier les deux objets de schema :

       wp db query "SHOW INDEX FROM wp_pc_payments WHERE Key_name='idx_photo_statut'"
       wp db query "SHOW COLUMNS FROM wp_pc_profiles LIKE 'opt_in_prochain'"

   - OK : chacune retourne 1 ligne.
   - KO : migration incomplete ; consulter wp-content/debug.log
     (cherchez [PC_Database::add_profiles_phase3_columns] ALTER failed).

> Note prefixe : si la table n'est pas wp_, remplacez le prefixe partout
> (wp db prefix pour le connaitre). Les requetes ci-dessous supposent wp_.

---

## Validation A — Paiement panier Stripe (mode test)

### Objectif
Valider le flux complet du caddy : un candidat regle en une seule session Stripe
N photos en attente, le webhook bascule toutes les lignes du meme payment_token
en paiement_recu, le badge galerie passe payee, et — critere cle — le statut jury
de la photo reste en_attente (le paiement ne modifie jamais le statut de deliberation).

### Noms exacts dans le code (pour le diagnostic)
- Action AJAX caddy : wp_ajax_pc_create_caddy_session -> PC_Payments::ajax_create_caddy_session()
  (nonce pc_profile_nonce, capability pc_pay_participation)
- Endpoint de paiement : home_url('/?pc_pay=<token>') -> PC_Payments::maybe_handle_payment_link()
  sur plugins_loaded priorite 20 (Elementor-safe, vide les output buffers)
- Webhook : home_url('/?pc_stripe_webhook=1') -> query var pc_stripe_webhook ->
  PC_Payments::process_webhook() -> sur checkout.session.completed ->
  on_checkout_completed() -> on_token_checkout_completed()
- A la completion : do_action('pc_paiement_panier_recu', $user_id, $tag_paye)

### Pre-requis
- Migration 2.1.0 OK (section 0).
- Compte Stripe en mode Test active (toggle Test mode du dashboard).
- Reglages plugin (wp-admin -> Photo Contest -> Parametres / Paiements) :
  - stripe_secret_key = cle sk_test_...
  - stripe_publishable_key = cle pk_test_... (le cas echeant)
  - stripe_webhook_secret = secret whsec_... du endpoint de test (voir ci-dessous)
  - montant_participation_cts (ex. 1500 = 15,00 EUR) et devise (EUR) renseignes
- composer install execute (presence de vendor/autoload.php).
- Depot actif : PC_Settings::is_depot_actif() doit etre vrai (sinon paiement refuse).
- Un candidat de test (role pc_candidat, capability pc_pay_participation), profil
  complet, possedant au moins 2 photos en statut en_attente non encore payees.

### Declarer le webhook dans Stripe (mode Test)
1. Stripe Dashboard -> Developers -> Webhooks -> Add endpoint.
2. Endpoint URL : https://VOTRE-DOMAINE/?pc_stripe_webhook=1
3. Evenements a ecouter :
   - checkout.session.completed  (traite par le code)
   - payment_intent.payment_failed  (s'abonner pour observabilite / monitoring)

     Note : la 2.1.0 ne fait que renvoyer 200 OK sur cet evenement (le switch de
     process_webhook() ne traite que checkout.session.completed). C'est attendu :
     un echec laisse simplement les lignes en en_attente.
4. Copier le Signing secret (whsec_...) dans stripe_webhook_secret.

> En local (Laragon, pas d'URL publique), utiliser la Stripe CLI :
> stripe listen --forward-to "http://sdlp.test/?pc_stripe_webhook=1"
> et utiliser le whsec_... affiche par la CLI comme stripe_webhook_secret.

### Donnees d'entree
- Carte succes : 4242 4242 4242 4242, date future, CVC et code postal quelconques.
- Optionnel : echec 4000 0000 0000 9995 ; 3D Secure 4000 0027 6000 3184.

### Etapes
1. Se connecter en candidat de test. Etat initial :

       wp db query "SELECT id, titre, statut FROM wp_pc_photos WHERE user_id = ID_USER AND statut = 'en_attente'"

   Noter le nombre de photos en_attente eligibles (= N attendu).
2. Aller sur la page profil (/mon-profil/), bloc Paiement de mes photos (le caddy).
3. Verifier que le bouton affiche Payer mes N photos.
4. Cliquer Payer mes N photos. L'AJAX pc_create_caddy_session cree les lignes
   en_attente sous un meme payment_token, puis redirige vers /?pc_pay=<token>,
   qui ouvre Stripe Checkout.
5. Recuperer le token cree :

       wp db query "SELECT DISTINCT payment_token FROM wp_pc_payments WHERE user_id = ID_USER AND statut_paiement = 'en_attente' ORDER BY id DESC LIMIT 1"
       wp db query "SELECT id, photo_id, montant_centimes, statut_paiement FROM wp_pc_payments WHERE payment_token = 'TOKEN'"

   Verifier que N lignes partagent ce token.
6. Sur Stripe Checkout, payer avec 4242 4242 4242 4242.
7. Stripe redirige vers la page de succes (/?page_id=...&pc_paiement=ok ou /?pc_paiement=ok).
8. Laisser le webhook arriver (quelques secondes ; en CLI, surveiller stripe listen).

### Criteres de succes

Critere cle — toutes les lignes du token payees :

    wp db query "SELECT id, photo_id, statut_paiement, reference_externe, methode FROM wp_pc_payments WHERE payment_token = 'TOKEN'"

- OK : toutes les lignes ont statut_paiement = paiement_recu
- OK : reference_externe renseignee (payment_intent, ex. pi_...)
- OK : methode = stripe_checkout

Critere cle — le statut jury de la photo NE change PAS :

    wp db query "SELECT p.id, p.titre, p.statut FROM wp_pc_photos p JOIN wp_pc_payments pay ON pay.photo_id = p.id WHERE pay.payment_token = 'TOKEN'"

- OK : chaque photo reste en statut = en_attente
- KO : une photo passee a retenue/refusee/autre du fait du paiement -> bug bloquant

Interface galerie :
9. Retourner sur /mon-espace-candidat/ (ou rafraichir le profil).
- OK : le badge de chaque photo reglee affiche payee
- KO : badge reste a payer alors que la BDD montre paiement_recu -> cache front
  (purger AccelerateWP/Nginx) ou rendu du badge.

### Idempotence (re-livraison du webhook)
Stripe peut renvoyer checkout.session.completed plusieurs fois.
on_token_checkout_completed() ne met a jour que les lignes encore en_attente du token.
1. Dashboard Stripe -> Webhooks -> l'evenement -> Resend (ou CLI : stripe events resend evt_...).
2. Re-verifier :

       wp db query "SELECT id, montant_centimes, statut_paiement FROM wp_pc_payments WHERE payment_token = 'TOKEN'"

- OK : aucune ligne dupliquee, statuts inchanges (paiement_recu)
- OK : les montant_centimes (prix unitaire) inchanges — jamais ecrases par amount_total
- KO : duplication ou montant ecrase -> regression d'idempotence.

### Verrou depot ferme (paiement impossible hors fenetre)
ajax_create_caddy_session() refuse si PC_Settings::is_depot_actif() est faux.
1. wp-admin -> Parametres : decocher depot_actif (ou poser une date_fermeture_depot passee).
2. Recharger le profil, cliquer Payer mes N photos.
- OK : message "Le depot est cloture : paiement impossible." Aucune session Stripe, aucune nouvelle ligne en_attente.
3. Reactiver depot_actif apres le test.

### Quota
Le caddy ne facture que les photos reellement deposees en en_attente et non deja
payees (creer_lignes_panier()).
- OK : N lignes = nombre de photos en_attente non payees du candidat.
- KO : N superieur au nombre reel -> bug de selection.

### Scenarios annexes (rapides)
- Echec carte (4000 0000 0000 9995) : refuse sur Checkout. OK = lignes restent en_attente, photo en_attente, badge a payer.
- Lien deja paye : re-ouvrir /?pc_pay=<token> apres paiement. OK = wp_die "Ce paiement a deja ete effectue." (HTTP 410).
- Lien d'un autre candidat : ouvrir le token avec un autre compte. OK = "Ce lien ne vous est pas destine." (HTTP 403).

### Nettoyage (dev)
> Ecritures BDD de test autorisees en local Laragon — montrer la requete avant.

    DELETE FROM wp_pc_payments WHERE payment_token = 'TOKEN';
    -- UPDATE wp_pc_photos SET statut = 'en_attente' WHERE id IN (...);  -- si un test les a fait bouger

---

## Validation B — Fuseau horaire O2Switch (deadline & relances)

### Objectif
Confirmer en production O2Switch que toutes les bascules temporelles
(is_depot_actif(), is_jury_actif(), calcul des relances) suivent l'heure de Paris
(wp_timezone(), Reglages -> General), et jamais l'horloge systeme du serveur — qui
sur O2Switch peut differer de Paris.

### Pourquoi c'est un point de vigilance
Le code ancre chaque comparaison de date sur wp_timezone() via date_to_ts() :

    private static function date_to_ts( mixed $value ): ?int {
        // ...
        return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
    }

is_depot_actif() compare time() (epoch UTC) a ces bornes ; is_jury_actif() bascule
a vrai des que time() >= date_fermeture_depot. Le risque historique (Laragon vs
O2Switch) est que la deadline se declenche a l'heure serveur. Cette validation
prouve que ce n'est pas le cas en prod.

### Pre-requis
- Migration 2.1.0 OK (section 0).
- Acces WP-CLI sur O2Switch (SSH).
- Reglages -> General -> Fuseau horaire du site = Europe/Paris (choisir la ville, pas
  un offset UTC+X fixe, pour gerer l'heure d'ete).

### Commande de diagnostic (sur O2Switch)
Coller ce bloc PHP dans un fichier diag.php temporaire et lancer "wp eval-file diag.php",
ou taper la commande "wp eval" sur une seule ligne. Contenu du diagnostic :

    $tz = wp_timezone();
    echo "PHP default tz   : " . date_default_timezone_get() . "\n";
    echo "Serveur (date)   : " . date("Y-m-d H:i:s") . "\n";
    echo "wp_timezone()    : " . $tz->getName() . "\n";
    echo "current_time(sql): " . current_time("mysql") . "\n";
    echo "now @Paris       : " . (new DateTimeImmutable("now", $tz))->format("Y-m-d H:i:s") . "\n";
    echo "date_fermeture   : " . PC_Settings::get("date_fermeture_depot") . "\n";
    echo "depot_actif      : " . ( PC_Settings::is_depot_actif() ? "true" : "false" ) . "\n";
    echo "jury_actif       : " . ( PC_Settings::is_jury_actif() ? "true" : "false" ) . "\n";

- OK : wp_timezone() = Europe/Paris ; "now @Paris" coherent avec l'heure reelle
  parisienne, meme si "Serveur (date)" differe.

### Etapes — bascule du depot a l'heure de Paris
1. Noter l'heure de Paris courante ("now @Paris" du diagnostic).
2. wp-admin -> Parametres : cocher depot_actif, et poser date_fermeture_depot a
   maintenant + 3 minutes (heure de Paris). Laisser jury_actif decoche et s'assurer
   que la cloture manuelle n'a pas eu lieu (cloture_effectuee_at = 0).
3. Avant l'echeance, lancer le diagnostic :
   - OK : depot_actif: true, jury_actif: false.
4. Attendre de depasser l'heure de Paris fixee (re-verifier "now @Paris").
5. Relancer le diagnostic :
   - OK : depot_actif: false (le depot se ferme exactement a l'heure de Paris, pas serveur).
   - OK : jury_actif: true (ouverture auto des que time() >= date_fermeture_depot).
6. Controle anti-faux-positif : si le serveur O2Switch n'est pas en Europe/Paris,
   l'instant de bascule observe doit correspondre a l'horloge parisienne, pas a
   "Serveur (date)". Verifier que la bascule ne s'est PAS produite a l'heure serveur.

### Criteres de succes
- OK : bascule de is_depot_actif() (true->false) a l'instant parisien fixe, a la minute pres.
- OK : is_jury_actif() passe a true au meme instant (chemin auto, cloture manuelle absente).
- KO : bascule calee sur "Serveur (date)" au lieu de l'heure de Paris -> regression timezone (bloquant prod).

### Nettoyage
- Remettre date_fermeture_depot a la vraie date de cloture de l'edition.
- Re-verifier is_depot_actif() / is_jury_actif() coherents avec cette date.

---

## Validation C — Automations Fluent CRM (relances & confirmation)

### Objectif
Le plugin declenche des hooks ; les emails ne partent que si les automations
correspondantes existent cote Fluent CRM. Valider que :
1. la relance de paiement (J-10 / J-5) envoie un email aux candidats ayant des photos non payees ;
2. la confirmation de paiement panier part au paiement recu et pose le tag paye.

### Chaine de hooks (noms exacts)
| Declencheur plugin | Consomme par | Event Fluent CRM (slug a creer) |
|---|---|---|
| cron pc_relance_impayes_daily -> do_action('pc_relance_paiement', $user_id, $data) | PC_Fluent_CRM::on_relance_paiement() | pc_relance_paiement |
| webhook -> do_action('pc_paiement_panier_recu', $user_id, $tag_paye) | PC_Fluent_CRM::on_paiement_panier_recu() | pc_paiement_confirme |

> Subtilite importante : le hook pc_paiement_panier_recu (cote paiement) est distinct
> du slug d'automation pc_paiement_confirme (cote Fluent CRM). C'est
> on_paiement_panier_recu() qui pose le tag puis appelle
> fire_automation(..., 'pc_paiement_confirme'). Dans Fluent CRM, l'automation de
> confirmation doit ecouter pc_paiement_confirme, PAS pc_paiement_panier_recu.

fire_automation() declenche deux signaux pour compatibilite :

    do_action( $event_slug, $contact, $event_data );                                       // hook direct
    do_action( 'fluentcrm_fire_custom_trigger', $event_slug, $contact->id, $event_data );  // trigger Fluent CRM 2.7+

Payloads transmis :
- pc_relance_paiement : nb_non_payees, montant (formate "12,00 EUR"), date_cloture
  (Y-m-d), profil_url, rang (1 = J-10, 2 = J-5).
- pc_paiement_confirme : payload vide (le contact suffit).

### Pre-requis
- Migration 2.1.0 OK (section 0).
- Fluent CRM actif (FLUENTCRM defini, FluentCrmApi() dispo) et Fluent SMTP configure (envoi reel).
- Reglage plugin fluent_tag_paye = ID du tag Fluent CRM paye (verifier l'ID dans
  Fluent CRM -> Tags) :

      wp option get pc_settings --format=json   # verifier la valeur de fluent_tag_paye

- Offsets de relance : relance_offset_1 (def. 10) et relance_offset_2 (def. 5).

### Creer les automations dans Fluent CRM
Pour chaque slug (pc_relance_paiement, pc_paiement_confirme) :
1. Fluent CRM -> Automations -> New Automation.
2. Trigger : Custom Action Hook (ou Fire a custom trigger).
3. Saisir le nom de l'action = le slug exact (pc_relance_paiement / pc_paiement_confirme).
4. Ajouter une action Send Email (le template de relance peut reutiliser les smartcodes
   du payload : nb de photos, montant, date de cloture, lien profil).
5. Activer / publier l'automation.

### Test 1 — Relance de paiement (J-10)
But : forcer la condition "il reste exactement 10 jours avant la cloture", puis lancer le cron.
1. Creer/reutiliser un candidat de test avec au moins 1 photo en_attente non payee
   (aucune ligne paiement_recu sur cette photo) et un email controlable.
2. wp-admin -> Parametres : poser date_fermeture_depot a aujourd'hui + 10 jours (heure
   de Paris). S'assurer que relance_offset_1 = 10.
3. Purger l'anti-doublon de l'edition (flag par offset + date de cloture) :

       wp option list --search='pc_relance_sent_%' --format=table
       wp option delete pc_relance_sent_1_AAAAMMJJ   # AAAAMMJJ = date de cloture posee (format Ymd)

4. Lancer la relance :

       wp cron event run pc_relance_impayes_daily
       # ou equivalent direct :
       wp eval "do_action('pc_relance_impayes_daily');"

5. Verifier :
   - OK : le contact de test recoit l'email de relance (boite mail / Fluent CRM -> Email Logs).
   - OK : le flag anti-doublon est pose :

         wp option get pc_relance_sent_1_AAAAMMJJ

   - OK : relancer le cron une 2e fois n'envoie PAS de second email (anti-doublon respecte).
   - KO : aucun email alors que l'automation existe -> verifier que le slug Fluent CRM
     correspond exactement a pc_relance_paiement, que le candidat a bien une photo non
     payee, et que jours_restants vaut exactement 10.

> Variante J-5 : poser date_fermeture_depot a aujourd'hui + 5 jours, purger
> pc_relance_sent_2_AAAAMMJJ, relancer le cron. OK = email de rang 2 recu.

### Test 2 — Confirmation de paiement + tag paye
Se branche directement sur la Validation A (paiement reussi via Stripe).
1. Realiser un paiement panier reussi (Validation A, etapes 1-8).
2. Au passage du webhook, on_paiement_panier_recu() pose le tag fluent_tag_paye
   et declenche l'automation pc_paiement_confirme.
3. Verifier dans Fluent CRM (Contacts -> le contact du candidat) :
   - OK : le tag paye (ID = fluent_tag_paye) est present sur le contact.
   - OK : l'email de confirmation (pc_paiement_confirme) est parti (Email Logs).
   - KO : tag absent -> fluent_tag_paye = 0 ou ID errone ; email absent -> automation
     pc_paiement_confirme inexistante ou inactive.

> Test isole sans Stripe (dev) — simuler le hook de fin de paiement sur une seule ligne :

      wp eval "do_action('pc_paiement_panier_recu', ID_USER, 0);"

> (remplacer 0 par l'ID du tag paye pour valider aussi la pose du tag).

### Criteres de succes globaux (C)
- OK : Relance : email recu par le candidat impaye au bon offset, flag anti-doublon pose.
- OK : Confirmation : email de confirmation recu ET tag paye present sur le contact.

### Nettoyage

    wp option list --search='pc_relance_sent_%' --format=table
    wp option delete pc_relance_sent_1_AAAAMMJJ
    wp option delete pc_relance_sent_2_AAAAMMJJ

- Retirer le tag paye de test du contact si le paiement etait fictif.
- Desactiver les automations de test si elles ne doivent pas tourner en prod tout de suite.

---

## Recapitulatif — checklist de release 2.1.0

- [ ] wp option get pc_db_version = 2.1.0 ; idx_photo_statut + colonne opt_in_prochain presents
- [ ] A. Paiement panier : toutes les lignes du payment_token en paiement_recu, badge payee, photo reste en_attente
- [ ] A. Idempotence : re-livraison webhook sans duplication ni ecrasement de montant
- [ ] A. Verrou : paiement refuse si is_depot_actif() faux
- [ ] B. Bascule depot/jury calee sur l'heure de Paris en prod O2Switch (diagnostic wp eval)
- [ ] C. Relance J-10 / J-5 : email recu + flag anti-doublon
- [ ] C. Confirmation paiement : email recu + tag paye pose
