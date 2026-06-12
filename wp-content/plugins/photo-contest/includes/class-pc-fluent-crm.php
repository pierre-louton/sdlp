<?php
defined( 'ABSPATH' ) || exit;

/**
 * Intégration Fluent CRM.
 *
 * Gère la synchronisation des candidats avec les listes Fluent CRM
 * et déclenche les séquences email selon les changements de statut photo.
 *
 * Séquences gérées :
 *  - Inscription          : candidat ajouté à la liste "Tous les candidats"
 *  - Retenue              : candidat ajouté à la liste "Retenus", email d'annonce
 *  - Refusée              : email de notification de refus
 *  - Paiement panier reçu : tag "payé" + automation de confirmation
 *  - Relance paiement     : automation de relance J-10 / J-5
 */
class PC_Fluent_CRM {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Écoute des changements de statut photo
        add_action( 'pc_fluent_notify_statut', [ $this, 'on_statut_change' ], 10, 3 );

        // Synchronisation à l'inscription d'un nouveau candidat
        add_action( 'user_register', [ $this, 'on_user_register' ] );

        // Synchronisation à la complétion du profil
        add_action( 'pc_profile_completed', [ $this, 'on_profile_completed' ] );

        // Paiement panier reçu (webhook Stripe) : tag payé + automation de confirmation
        add_action( 'pc_paiement_panier_recu', [ $this, 'on_paiement_panier_recu' ], 10, 2 );

        // Relance de paiement (cron J-10 / J-5)
        add_action( 'pc_relance_paiement', [ $this, 'on_relance_paiement' ], 10, 2 );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Vérification disponibilité Fluent CRM
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Vérifie que Fluent CRM est installé et actif.
     */
    private function is_fluent_active(): bool {
        return defined( 'FLUENTCRM' ) && function_exists( 'FluentCrmApi' );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Gestion des contacts
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Crée ou met à jour un contact Fluent CRM depuis un user WordPress.
     */
    public function sync_contact( int $user_id, array $extra_data = [] ): ?object {
        if ( ! $this->is_fluent_active() ) {
            return null;
        }

        $user    = get_userdata( $user_id );
        $profile = PC_Profile::get_instance()->get_profile( $user_id );

        if ( ! $user ) {
            return null;
        }

        $contact_data = array_merge( [
            'email'      => $user->user_email,
            'first_name' => $profile['prenom'] ?? $user->first_name,
            'last_name'  => $profile['nom']    ?? $user->last_name,
            'phone'      => $profile['telephone'] ?? '',
            'address_line_1' => $profile['adresse_rue'] ?? '',
            'city'           => $profile['ville'] ?? '',
            'country'        => $profile['pays'] ?? '',
            'status'         => 'subscribed',
        ], $extra_data );

        $contactApi = FluentCrmApi( 'contacts' );
        return $contactApi->createOrUpdate( $contact_data );
    }

    /**
     * Ajoute un contact à une liste Fluent CRM par son ID.
     */
    public function add_to_list( int $user_id, int $list_id ): void {
        if ( ! $this->is_fluent_active() || ! $list_id ) {
            return;
        }

        $contact = $this->sync_contact( $user_id );
        if ( $contact ) {
            $contact->attachLists( [ $list_id ] );
        }
    }

    /**
     * Ajoute un tag Fluent CRM à un contact.
     */
    public function add_tag( int $user_id, int $tag_id ): void {
        if ( ! $this->is_fluent_active() || ! $tag_id ) {
            return;
        }

        $contact = $this->sync_contact( $user_id );
        if ( $contact ) {
            $contact->attachTags( [ $tag_id ] );
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Hooks WordPress
    // ──────────────────────────────────────────────────────────────────────

    /**
     * À l'inscription : sync contact + ajout liste candidats.
     */
    public function on_user_register( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user || ! in_array( PC_ROLE_CANDIDAT, (array) $user->roles, true ) ) {
            return;
        }

        $list_id = (int) PC_Settings::get( 'fluent_list_candidats', 0 );
        $this->add_to_list( $user_id, $list_id );
    }

    /**
     * À la complétion du profil : mise à jour des données de contact.
     */
    public function on_profile_completed( int $user_id ): void {
        $this->sync_contact( $user_id );
    }

    /**
     * Sur changement de statut photo : déclenche la séquence correspondante.
     *
     * @param int    $user_id   Propriétaire de la photo
     * @param int    $photo_id
     * @param string $nouveau_statut
     */
    public function on_statut_change( int $user_id, int $photo_id, string $nouveau_statut ): void {
        if ( ! $this->is_fluent_active() ) {
            return;
        }

        switch ( $nouveau_statut ) {

            case 'retenue':
                // Ajout à la liste "Retenus"
                $list_retenus = (int) PC_Settings::get( 'fluent_list_retenus', 0 );
                $this->add_to_list( $user_id, $list_retenus );

                // Déclenchement de l'automation "photo retenue"
                $this->fire_automation( $user_id, 'pc_photo_retenue', [
                    'photo_id' => $photo_id,
                ] );
                break;

            case 'refusee':
                $this->fire_automation( $user_id, 'pc_photo_refusee', [
                    'photo_id' => $photo_id,
                ] );
                break;

            case 'au_catalogue':
                $this->fire_automation( $user_id, 'pc_photo_au_catalogue', [
                    'photo_id' => $photo_id,
                ] );
                break;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Déclenche une automation Fluent CRM via un événement custom.
     * Les automations doivent être configurées dans Fluent CRM avec
     * le trigger "Custom Event" correspondant aux slugs utilisés ici.
     *
     * @param int    $user_id
     * @param string $event_slug  Slug de l'événement Fluent CRM
     * @param array  $event_data  Données passées à l'automation
     */
    private function fire_automation( int $user_id, string $event_slug, array $event_data = [] ): void {
        if ( ! $this->is_fluent_active() ) {
            return;
        }

        $contact = $this->sync_contact( $user_id );
        if ( ! $contact ) {
            return;
        }

        /**
         * Déclenche un événement custom Fluent CRM.
         * Dans Fluent CRM, créer une automation avec le déclencheur
         * "Custom Action Hook" et saisir le nom de l'action (ex: pc_photo_retenue).
         */
        try {
            // Méthode 1 : hook WordPress direct (compatible toutes versions)
            do_action( $event_slug, $contact, $event_data );

            // Méthode 2 : trigger Fluent CRM natif (Fluent CRM 2.7+)
            do_action( 'fluentcrm_fire_custom_trigger', $event_slug, $contact->id, $event_data );

        } catch ( \Exception $e ) {
            // Ne pas bloquer le flux principal si Fluent CRM échoue
            error_log( 'PC_Fluent_CRM::fire_automation error: ' . $e->getMessage() );
        }
    }

    /**
     * Webhook panier : pose le tag « payé » Fluent CRM pour le candidat + automation de confirmation.
     *
     * @param int $user_id
     * @param int $tag_id   ID du tag Fluent CRM à poser (0 = pas de tag configuré)
     */
    public function on_paiement_panier_recu( int $user_id, int $tag_id ): void {
        if ( $tag_id > 0 ) {
            $this->add_tag( $user_id, $tag_id );
        }
        $this->fire_automation( $user_id, 'pc_paiement_confirme', [] );
    }

    /**
     * Relance de paiement (J-10 / J-5 avant clôture des dépôts) : déclenche l'automation
     * Fluent CRM `pc_relance_paiement` avec le détail du caddy. Dégradation gracieuse si absent.
     *
     * @param int   $user_id
     * @param array $data  Clés : nb_non_payees, montant_du_cts, date_cloture, profil_url, rang
     */
    public function on_relance_paiement( int $user_id, array $data ): void {
        $this->fire_automation( $user_id, 'pc_relance_paiement', [
            'nb_non_payees' => (int) ( $data['nb_non_payees'] ?? 0 ),
            'montant'       => number_format( (int) ( $data['montant_du_cts'] ?? 0 ) / 100, 2, ',', ' ' ) . ' €',
            'date_cloture'  => (string) ( $data['date_cloture'] ?? '' ),
            'profil_url'    => (string) ( $data['profil_url'] ?? '' ),
            'rang'          => (int) ( $data['rang'] ?? 0 ),
        ] );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Export / stats (utilisé par le back-office)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Retourne le nombre de contacts dans une liste Fluent CRM.
     */
    public function count_list_contacts( int $list_id ): int {
        if ( ! $this->is_fluent_active() || ! $list_id ) {
            return 0;
        }

        $list = FluentCrmApi( 'lists' )->find( $list_id );
        return $list ? (int) $list->countSubscribers() : 0;
    }

    /**
     * Retourne toutes les listes Fluent CRM disponibles (pour le sélecteur admin).
     */
    public function get_all_lists(): array {
        if ( ! $this->is_fluent_active() ) {
            return [];
        }

        $lists = FluentCrmApi( 'lists' )->all();
        return array_map( fn( $l ) => [
            'id'    => $l->id,
            'label' => $l->title,
        ], $lists );
    }

    /**
     * Retourne tous les tags Fluent CRM disponibles (pour le sélecteur admin).
     */
    public function get_all_tags(): array {
        if ( ! $this->is_fluent_active() ) {
            return [];
        }

        $tags = FluentCrmApi( 'tags' )->all();
        return array_map( fn( $t ) => [
            'id'    => $t->id,
            'label' => $t->title,
        ], $tags );
    }
}