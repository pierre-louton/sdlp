<?php
defined( 'ABSPATH' ) || exit;

/**
 * Module Paiements — Stripe Checkout.
 *
 * Flux : caddy profil → endpoint /?pc_pay=<token> → Stripe Checkout → webhook → lignes paiement_recu
 * (le statut jury de la photo n'est PAS modifié par le paiement)
 * Webhook URL : <home_url>/?pc_stripe_webhook=1
 * Prérequis  : composer require stripe/stripe-php
 */
class PC_Payments {

    private static ?self $instance = null;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init',                       [ $this, 'register_webhook_endpoint' ] );
        add_action( 'wp_ajax_pc_create_caddy_session', [ $this, 'ajax_create_caddy_session' ] );

        // Phase 2 : relances quotidiennes des paiements impayés
        add_action( 'pc_relance_impayes_daily', [ $this, 'cron_relances_quotidiennes' ] );

        if ( ! wp_next_scheduled( 'pc_relance_impayes_daily' ) ) {
            wp_schedule_event( time() + 60, 'daily', 'pc_relance_impayes_daily' );
        }

        // Phase 2 Task 9 : endpoint /?pc_pay=<token> — Elementor-safe via plugins_loaded:20
        add_action( 'plugins_loaded', [ $this, 'maybe_handle_payment_link' ], 20 );
    }

    // ── SDK Stripe ────────────────────────────────────────────────────

    private function load_stripe(): bool {
        $autoload = PC_PLUGIN_DIR . 'vendor/autoload.php';
        if ( ! file_exists( $autoload ) ) return false;
        require_once $autoload;
        $secret = PC_Settings::get( 'stripe_secret_key', '' );
        if ( ! $secret ) return false;
        \Stripe\Stripe::setApiKey( $secret );
        \Stripe\Stripe::setAppInfo( 'Photo Contest Manager', PC_VERSION, home_url() );
        return true;
    }

    private function is_configured(): bool {
        return ! empty( PC_Settings::get( 'stripe_secret_key' ) )
            && ! empty( PC_Settings::get( 'stripe_publishable_key' ) );
    }

    // ── Endpoint /?pc_pay=<token> (Phase 2 Task 9) ───────────────────

    /**
     * Phase 2 : endpoint /?pc_pay=<token> qui crée une session Stripe Checkout pour
     * tous les paiements groupés sous ce token, puis redirige vers Stripe.
     *
     * Hook : plugins_loaded priorité 20 — avant qu'Elementor n'ouvre son output buffer.
     * Cf. skills/elementor-gotchas pour le contexte.
     */
    public function maybe_handle_payment_link(): void {
        if ( empty( $_GET['pc_pay'] ) ) return;

        // Filet de sécurité : vider tout buffer ouvert (Elementor & autres)
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        // Validation stricte du token (UUID v4)
        $token = sanitize_text_field( wp_unslash( $_GET['pc_pay'] ) );
        if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token ) ) {
            wp_die( esc_html__( 'Lien invalide.', PC_TEXT_DOMAIN ), '', [ 'response' => 400 ] );
        }

        global $wpdb;
        $payments_t = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $photos_t   = PC_Database::table( PC_Database::TABLE_PHOTOS );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pay.id, pay.user_id, pay.photo_id, pay.montant_centimes, pay.devise,
                    pay.statut_paiement, pay.reference_externe,
                    p.titre AS photo_titre
             FROM {$payments_t} pay
             JOIN {$photos_t} p ON p.id = pay.photo_id
             WHERE pay.payment_token = %s",
            $token
        ) );

        if ( empty( $rows ) ) {
            wp_die( esc_html__( 'Lien invalide ou expiré.', PC_TEXT_DOMAIN ), '', [ 'response' => 404 ] );
        }

        // Tous les paiements doivent appartenir au même candidat
        $user_id = (int) $rows[0]->user_id;

        // Détection : déjà payés ?
        $en_attente = array_filter( $rows, fn( $r ) => $r->statut_paiement === 'en_attente' );
        if ( empty( $en_attente ) ) {
            wp_die( esc_html__( 'Ce paiement a déjà été effectué.', PC_TEXT_DOMAIN ), '', [ 'response' => 410 ] );
        }

        // Authentification : redirection vers login si non connecté
        if ( ! is_user_logged_in() ) {
            $login_slug = (string) PC_Settings::get( 'login_slug', 'connexion' );
            $login_url  = home_url( '/' . trim( $login_slug, '/' ) . '/' );
            $back_url   = home_url( '/?pc_pay=' . rawurlencode( $token ) );
            wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $back_url ), $login_url ) );
            exit;
        }

        if ( get_current_user_id() !== $user_id ) {
            wp_die( esc_html__( 'Ce lien ne vous est pas destiné.', PC_TEXT_DOMAIN ), '', [ 'response' => 403 ] );
        }

        // Création de la session Stripe
        $autoload = PC_PLUGIN_DIR . 'vendor/autoload.php';
        if ( ! file_exists( $autoload ) ) {
            error_log( '[PC_Payments::maybe_handle_payment_link] vendor/autoload.php manquant — composer install requis' );
            wp_die( esc_html__( 'Service de paiement indisponible. Contactez l\'administrateur.', PC_TEXT_DOMAIN ), '', [ 'response' => 503 ] );
        }
        require_once $autoload;

        $stripe_key = (string) PC_Settings::get( 'stripe_secret_key', '' );
        if ( $stripe_key === '' ) {
            error_log( '[PC_Payments::maybe_handle_payment_link] stripe_secret_key non configurée' );
            wp_die( esc_html__( 'Service de paiement non configuré.', PC_TEXT_DOMAIN ), '', [ 'response' => 503 ] );
        }
        \Stripe\Stripe::setApiKey( $stripe_key );

        $line_items = [];
        foreach ( $en_attente as $row ) {
            $line_items[] = [
                'price_data' => [
                    'currency'     => strtolower( (string) $row->devise ),
                    'product_data' => [
                        'name' => $row->photo_titre ?: ( 'Photo #' . (int) $row->photo_id ),
                    ],
                    'unit_amount' => (int) $row->montant_centimes,
                ],
                'quantity' => 1,
            ];
        }

        // URLs success/cancel — construites sans get_permalink() (piège Elementor)
        $espace_page_id = (int) get_option( 'pc_page_espace_candidat', 0 );
        $success_url = $espace_page_id > 0
            ? home_url( '/?page_id=' . $espace_page_id . '&pc_paiement=ok' )
            : home_url( '/?pc_paiement=ok' );
        $cancel_url  = home_url( '/?pc_pay=' . rawurlencode( $token ) . '&canceled=1' );

        // Autoriser le redirect vers Stripe (hôte externe)
        add_filter( 'allowed_redirect_hosts', function( $hosts ) {
            $hosts[] = 'checkout.stripe.com';
            $hosts[] = 'connect.stripe.com';
            return $hosts;
        } );

        try {
            $session = \Stripe\Checkout\Session::create( [
                'mode'           => 'payment',
                'line_items'     => $line_items,
                'success_url'    => $success_url,
                'cancel_url'     => $cancel_url,
                'customer_email' => wp_get_current_user()->user_email,
                'metadata'       => [
                    'pc_payment_token' => $token,
                    'pc_user_id'       => (string) $user_id,
                ],
            ] );
        } catch ( \Throwable $e ) {
            error_log( '[PC_Payments::maybe_handle_payment_link] Stripe session error : ' . $e->getMessage() );
            wp_die( esc_html__( 'Erreur lors de la connexion à Stripe. Réessayez plus tard.', PC_TEXT_DOMAIN ), '', [ 'response' => 502 ] );
        }

        // La référence Stripe (payment_intent) est posée par le webhook à la
        // complétion : on retrouve les lignes par payment_token, pas par session id.
        wp_safe_redirect( $session->url );
        exit;
    }

    // ── Webhook Stripe ────────────────────────────────────────────────

    public function register_webhook_endpoint(): void {
        add_filter( 'query_vars', fn( $v ) => array_merge( $v, [ 'pc_stripe_webhook' ] ) );

        if ( get_query_var( 'pc_stripe_webhook' ) ) {
            $this->process_webhook();
            exit;
        }
    }

    private function process_webhook(): void {
        $payload = @file_get_contents( 'php://input' );
        $sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $secret  = PC_Settings::get( 'stripe_webhook_secret', '' );

        if ( ! $this->load_stripe() ) {
            http_response_code( 500 ); echo 'Stripe non configuré.'; return;
        }

        try {
            $event = \Stripe\Webhook::constructEvent( $payload, $sig, $secret );
        } catch ( \Exception $e ) {
            http_response_code( 400 ); echo 'Signature invalide.'; return;
        }

        switch ( $event->type ) {
            case 'checkout.session.completed':
                $this->on_checkout_completed( $event->data->object );
                break;
        }

        http_response_code( 200 ); echo 'OK';
    }

    private function on_checkout_completed( object $session ): void {
        $user_id = (int) ( $session->metadata->pc_user_id  ?? 0 );
        $token   = (string) ( $session->metadata->pc_payment_token ?? '' );

        if ( ! $user_id ) return;

        // Paiement groupé par token (plusieurs photos en une session)
        if ( $token !== '' ) {
            $this->on_token_checkout_completed( $token, $session );
        }
    }

    /**
     * Phase 2 Task 9 : finalise un paiement groupé identifié par son payment_token.
     *
     * Bascule toutes les lignes encore `en_attente` du token vers `paiement_recu`
     * et déclenche la cascade (Fluent CRM + catalogue) pour chaque photo.
     *
     * Idempotent : Stripe peut renvoyer checkout.session.completed plusieurs fois.
     * On ne touche que les lignes `en_attente`, donc un rejeu n'a aucun effet.
     * Le montant par ligne (`montant_centimes`) n'est jamais écrasé par `amount_total`.
     */
    private function on_token_checkout_completed( string $token, object $session ): void {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PAYMENTS );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, photo_id, user_id FROM {$table}
             WHERE payment_token = %s AND statut_paiement = 'en_attente'",
            $token
        ) );

        if ( empty( $rows ) ) return;

        $reference = (string) ( $session->payment_intent ?? $session->id );
        $tag_paye  = (int) PC_Settings::get( 'fluent_tag_paye', 0 );
        $user_id   = 0;
        foreach ( $rows as $row ) {
            $wpdb->update(
                $table,
                [
                    'statut_paiement'   => 'paiement_recu',
                    'reference_externe' => $reference,
                    'methode'           => 'stripe_checkout',
                ],
                [ 'id' => (int) $row->id ]
            );
            // NE PAS changer le statut jury de la photo : elle reste en_attente, juste « payée ».
            $user_id = (int) $row->user_id; // toutes les lignes d'un token partagent le même candidat
        }

        if ( $user_id > 0 ) {
            do_action( 'pc_paiement_panier_recu', $user_id, $tag_paye );
        }
    }

    // ── CRUD paiements ────────────────────────────────────────────────

    public function get_paiement_by_photo( int $photo_id ): ?array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE photo_id = %d ORDER BY id DESC LIMIT 1", $photo_id ),
            ARRAY_A
        ) ?: null;
    }

    public function get_paiements_user( int $user_id ): array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", $user_id ),
            ARRAY_A
        ) ?: [];
    }

    public function get_stats(): array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        return $wpdb->get_row(
            "SELECT COUNT(*) AS total,
             SUM(statut_paiement='paiement_recu') AS recus,
             SUM(statut_paiement='en_attente') AS en_attente,
             SUM(statut_paiement='echoue') AS echoues,
             SUM(CASE WHEN statut_paiement='paiement_recu' THEN montant_centimes ELSE 0 END) AS total_centimes
             FROM {$table}",
            ARRAY_A
        ) ?: [];
    }

    // ── Helpers caddy (Task 1 — paiement avant jury) ─────────────────

    /**
     * Une photo est « payée » s'il existe une ligne paiement_recu pour son id.
     */
    public function photo_est_payee( int $photo_id ): bool {
        global $wpdb;
        $t = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$t} WHERE photo_id = %d AND statut_paiement = 'paiement_recu' LIMIT 1",
            $photo_id
        ) );
    }

    /**
     * Récapitulatif de paiement (« caddy ») pour un candidat, sur ses photos en_attente.
     *
     * @return array{prix_unitaire_cts:int,nb_payees:int,nb_non_payees:int,montant_paye_cts:int,montant_du_cts:int,total_cts:int}
     */
    public function get_caddy( int $user_id ): array {
        global $wpdb;
        $photos_t   = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $payments_t = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $prix       = (int) PC_Settings::get( 'montant_participation_cts', 1500 );

        $nb_payees = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$photos_t} p
             WHERE p.user_id = %d AND p.statut = 'en_attente'
               AND EXISTS ( SELECT 1 FROM {$payments_t} pay
                            WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu' )",
            $user_id
        ) );
        $nb_non_payees = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$photos_t} p
             WHERE p.user_id = %d AND p.statut = 'en_attente'
               AND NOT EXISTS ( SELECT 1 FROM {$payments_t} pay
                                WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu' )",
            $user_id
        ) );

        return [
            'prix_unitaire_cts' => $prix,
            'nb_payees'         => $nb_payees,
            'nb_non_payees'     => $nb_non_payees,
            'montant_paye_cts'  => $nb_payees * $prix,
            'montant_du_cts'    => $nb_non_payees * $prix,
            'total_cts'         => ( $nb_payees + $nb_non_payees ) * $prix,
        ];
    }

    /**
     * Crée une ligne de paiement `en_attente` (prix unitaire) pour chaque photo en_attente
     * non encore payée du candidat, sous un même payment_token. Renvoie le token, ou ''
     * s'il n'y a rien à payer.
     */
    public function creer_lignes_panier( int $user_id ): string {
        global $wpdb;
        $photos_t   = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $payments_t = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $prix       = (int) PC_Settings::get( 'montant_participation_cts', 1500 );
        $devise     = strtoupper( (string) PC_Settings::get( 'devise', 'EUR' ) );

        $photo_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.id FROM {$photos_t} p
             WHERE p.user_id = %d AND p.statut = 'en_attente'
               AND NOT EXISTS ( SELECT 1 FROM {$payments_t} pay
                                WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu' )",
            $user_id
        ) );
        if ( empty( $photo_ids ) ) {
            return '';
        }

        $token = wp_generate_uuid4();
        foreach ( $photo_ids as $pid ) {
            // Nettoyer une éventuelle ligne en_attente orpheline (panier abandonné).
            // Borné par user_id : on ne touche que les lignes du candidat courant.
            $wpdb->delete( $payments_t, [ 'photo_id' => (int) $pid, 'user_id' => $user_id, 'statut_paiement' => 'en_attente' ] );
            $wpdb->insert( $payments_t, [
                'photo_id'         => (int) $pid,
                'user_id'          => $user_id,
                'montant_centimes' => $prix,
                'devise'           => $devise,
                'statut_paiement'  => 'en_attente',
                'methode'          => 'stripe_checkout',
                'payment_token'    => $token,
            ] );
        }
        return $token;
    }

    /**
     * AJAX : crée les lignes panier pour le candidat courant et renvoie l'URL
     * de l'endpoint /?pc_pay=<token> (qui ouvrira Stripe Checkout, Elementor-safe).
     */
    public function ajax_create_caddy_session(): void {
        check_ajax_referer( 'pc_profile_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! current_user_can( 'pc_pay_participation' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }
        if ( ! PC_Settings::is_depot_actif() ) {
            wp_send_json_error( [ 'message' => __( 'Le dépôt est clôturé : paiement impossible.', PC_TEXT_DOMAIN ) ] );
        }
        if ( ! $this->is_configured() ) {
            wp_send_json_error( [ 'message' => __( 'Stripe n\'est pas configuré.', PC_TEXT_DOMAIN ) ] );
        }

        $token = $this->creer_lignes_panier( get_current_user_id() );
        if ( $token === '' ) {
            wp_send_json_error( [ 'message' => __( 'Aucune photo à payer.', PC_TEXT_DOMAIN ) ] );
        }

        wp_send_json_success( [ 'url' => home_url( '/?pc_pay=' . rawurlencode( $token ) ) ] );
    }

    // ── Clôture jury (Phase 2) ────────────────────────────────────────

    public static function get_cloture_stats(): array {
        global $wpdb;
        $photos = PC_Database::table( PC_Database::TABLE_PHOTOS );

        return [
            'en_delibration_a_refuser' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$photos} WHERE statut IN ('en_attente','en_examen')" ),
            'photos_retenues'          => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$photos} WHERE statut = 'retenue'" ),
        ];
    }

    /**
     * Clôture de la délibération du jury (modèle paiement-avant-jury).
     *
     * 1. Verrou anti-double-clic (cloture_en_cours)
     * 2. Photos payées encore en délibération (en_attente/en_examen) -> refusee
     * 3. Photos retenue -> au_catalogue (déclenche l'alimentation catalogue)
     * 4. Flags : jury_actif=false, catalogue_actif=true, cloture_effectuee_at=time()
     * 5. do_action( 'pc_cloture_jury_effectuee' )
     *
     * Aucune création de paiement (le paiement a lieu avant le jury).
     *
     * @return array{refused_count?:int, au_catalogue_count?:int, error?:string}
     */
    public static function execute_cloture(): array {
        global $wpdb;
        $photos_t = PC_Database::table( PC_Database::TABLE_PHOTOS );

        // 1. Verrou
        $lock_at = (int) PC_Settings::get( 'cloture_en_cours', 0 );
        if ( $lock_at && ( time() - $lock_at ) < 300 ) {
            return [ 'error' => 'cloture_en_cours' ];
        }
        PC_Settings::set( 'cloture_en_cours', time() );

        // 2. Refus des photos non départagées
        $refused = (int) $wpdb->query(
            "UPDATE {$photos_t} SET statut = 'refusee' WHERE statut IN ('en_attente','en_examen')"
        );

        // 3. retenue -> au_catalogue (via update_statut pour déclencher l'alimentation catalogue)
        $retenue_ids = $wpdb->get_col( "SELECT id FROM {$photos_t} WHERE statut = 'retenue'" );
        $photos = PC_Photos::get_instance();
        foreach ( $retenue_ids as $pid ) {
            $photos->update_statut( (int) $pid, 'au_catalogue' );
        }

        // 4. Flags
        PC_Settings::set( 'jury_actif',           false );
        PC_Settings::set( 'catalogue_actif',      true );
        PC_Settings::set( 'cloture_en_cours',     0 );
        PC_Settings::set( 'cloture_effectuee_at', time() );

        // 5. Trigger
        do_action( 'pc_cloture_jury_effectuee' );

        return [
            'refused_count'      => $refused,
            'au_catalogue_count' => count( $retenue_ids ),
        ];
    }

    /**
     * Liste des user_id ayant au moins une photo en_attente non payée.
     * Sert au ciblage des relances avant clôture.
     *
     * @return int[]
     */
    public function candidats_avec_photos_non_payees(): array {
        global $wpdb;
        $photos_t   = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $payments_t = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        return array_map( 'intval', $wpdb->get_col(
            "SELECT DISTINCT p.user_id FROM {$photos_t} p
             WHERE p.statut = 'en_attente'
               AND NOT EXISTS ( SELECT 1 FROM {$payments_t} pay
                                WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu' )"
        ) );
    }

    /**
     * Cron quotidien : relances de paiement avant la clôture des dépôts.
     *
     * Envoie une relance aux candidats ayant ≥1 photo non payée, lorsqu'il reste
     * exactement relance_offset_1 (J-10) ou relance_offset_2 (J-5) jours avant
     * date_fermeture_depot. Calcul des jours côté PHP, ancré sur wp_timezone().
     * Anti-doublon : option par offset et par date de clôture.
     */
    public function cron_relances_quotidiennes(): void {
        $fermeture = (string) PC_Settings::get( 'date_fermeture_depot', '' );
        if ( $fermeture === '' ) {
            return;
        }
        try {
            $tz    = wp_timezone();
            $end   = ( new DateTimeImmutable( $fermeture, $tz ) )->setTime( 0, 0 );
            $today = ( new DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 );
        } catch ( Exception $e ) {
            return;
        }
        $jours_restants = (int) $today->diff( $end )->format( '%r%a' );
        if ( $jours_restants < 0 ) {
            return; // clôture passée
        }

        $offsets = [
            1 => (int) PC_Settings::get( 'relance_offset_1', 10 ),
            2 => (int) PC_Settings::get( 'relance_offset_2', 5 ),
        ];

        foreach ( $offsets as $rang => $offset ) {
            if ( $offset <= 0 || $jours_restants !== $offset ) {
                continue;
            }
            $flag = 'pc_relance_sent_' . $rang . '_' . $end->format( 'Ymd' );
            if ( get_option( $flag ) ) {
                continue; // déjà envoyée pour cette édition
            }

            $profil_url = get_permalink( get_option( 'pc_page_profil' ) ) ?: home_url( '/' );

            foreach ( $this->candidats_avec_photos_non_payees() as $user_id ) {
                $caddy = $this->get_caddy( $user_id );
                if ( $caddy['nb_non_payees'] <= 0 ) {
                    continue;
                }
                // Hook consommé par PC_Fluent_CRM. Dégradation gracieuse si absent.
                do_action( 'pc_relance_paiement', $user_id, [
                    'nb_non_payees'  => $caddy['nb_non_payees'],
                    'montant_du_cts' => $caddy['montant_du_cts'],
                    'date_cloture'   => $end->format( 'Y-m-d' ),
                    'profil_url'     => $profil_url,
                    'rang'           => $rang,
                ] );
            }

            update_option( $flag, time(), false );
        }
    }

}