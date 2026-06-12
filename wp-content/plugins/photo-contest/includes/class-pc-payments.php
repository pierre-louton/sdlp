<?php
defined( 'ABSPATH' ) || exit;

/**
 * Module Paiements — Stripe Checkout.
 *
 * Flux : page de paiement → Session Checkout → webhook → update_statut('paiement_recu')
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
        add_action( 'template_redirect',          [ $this, 'handle_payment_page' ] );
        add_action( 'template_redirect',          [ $this, 'handle_stripe_return' ] );
        add_action( 'init',                       [ $this, 'register_webhook_endpoint' ] );
        add_action( 'wp_ajax_pc_create_checkout_session', [ $this, 'ajax_create_session' ] );
        add_action( 'wp_ajax_pc_create_inscription_session', [ $this, 'ajax_create_inscription_session' ] );
        add_action( 'wp_enqueue_scripts',         [ $this, 'maybe_enqueue_assets' ] );
        add_action( 'pc_send_payment_email_batch', [ $this, 'cron_send_batch' ] );

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

    // ── Page de paiement ──────────────────────────────────────────────

    public function handle_payment_page(): void {
        if ( ! is_user_logged_in() ) return;
        if ( ( $_GET['pc_action'] ?? '' ) !== 'paiement' ) return;

        $photo_id = (int) ( $_GET['photo'] ?? 0 );
        $token    = sanitize_text_field( $_GET['token'] ?? '' );
        $user_id  = get_current_user_id();

        if ( ! $photo_id || ! wp_verify_nonce( $token, "pc_payment_{$user_id}_{$photo_id}" ) )
            wp_die( esc_html__( 'Lien de paiement invalide ou expiré.', 'photo-contest' ) );

        $photo = PC_Photos::get_instance()->get_photo( $photo_id, $user_id );
        if ( ! $photo || ! in_array( $photo['statut'], [ 'participation_demandee', 'paiement_recu', 'au_catalogue' ], true ) )
            wp_die( esc_html__( 'Photo introuvable ou non éligible.', 'photo-contest' ) );

        $this->render_payment_page( $photo, $user_id );
    }

    private function render_payment_page( array $photo, int $user_id ): void {
        $paiement    = $this->get_paiement_by_photo( (int) $photo['id'] );
        $montant_cts = (int) PC_Settings::get( 'montant_participation_cts', 1500 );
        $devise      = PC_Settings::get( 'devise', 'EUR' );
        $mode_test   = PC_Settings::get( 'stripe_mode', 'test' ) === 'test';
        $stripe_pub  = PC_Settings::get( 'stripe_publishable_key', '' );
        $retour_url  = get_permalink( get_option( 'pc_page_espace_candidat' ) ) ?: home_url( '/' );

        wp_enqueue_style( 'pc-payment', PC_PLUGIN_URL . 'public/css/pc-payment.css', [], PC_VERSION . '.2' );
        extract( compact( 'photo', 'paiement', 'montant_cts', 'devise', 'mode_test', 'stripe_pub', 'retour_url' ) );
        get_header();
        include PC_PLUGIN_DIR . 'templates/payment.php';
        get_footer();
        exit;
    }

    public function handle_stripe_return(): void {
        if ( ! is_user_logged_in() ) return;
        $action = sanitize_key( $_GET['pc_stripe'] ?? '' );
        if ( ! in_array( $action, [ 'success', 'cancel', 'inscription_ok', 'inscription_cancel' ], true ) ) return;

        $photo_id   = (int) ( $_GET['photo'] ?? 0 );
        $user_id    = get_current_user_id();
        $retour_url = get_permalink( get_option( 'pc_page_espace_candidat' ) ) ?: home_url( '/' );

        $profil_url = get_permalink( get_option( 'pc_page_profil' ) ) ?: home_url( '/' );

        wp_enqueue_style( 'pc-payment', PC_PLUGIN_URL . 'public/css/pc-payment.css', [], PC_VERSION . '.2' );
        get_header();

        if ( $action === 'inscription_ok' ) {
            // Paiement inscription réussi
            $montant_fmt = PC_Settings::montant_formate();
            $paiement    = null;
            $photo       = null;
            extract( compact( 'montant_fmt', 'retour_url' ) );
            include PC_PLUGIN_DIR . 'templates/payment-success.php';

        } elseif ( $action === 'inscription_cancel' ) {
            // Paiement inscription annulé — retry vers le profil
            $photo     = null;
            $retry_url = $profil_url;
            extract( compact( 'photo', 'retour_url', 'retry_url' ) );
            include PC_PLUGIN_DIR . 'templates/payment-cancel.php';

        } elseif ( $action === 'success' ) {
            $photo       = $photo_id ? PC_Photos::get_instance()->get_photo( $photo_id, $user_id ) : null;
            $paiement    = $photo_id ? $this->get_paiement_by_photo( $photo_id ) : null;
            $montant_fmt = PC_Settings::montant_formate();
            extract( compact( 'photo', 'paiement', 'montant_fmt', 'retour_url' ) );
            include PC_PLUGIN_DIR . 'templates/payment-success.php';

        } else {
            $photo     = $photo_id ? PC_Photos::get_instance()->get_photo( $photo_id, $user_id ) : null;
            $token     = wp_create_nonce( "pc_payment_{$user_id}_{$photo_id}" );
            $retry_url = add_query_arg( [ 'pc_action' => 'paiement', 'photo' => $photo_id, 'token' => $token ], $retour_url );
            extract( compact( 'photo', 'retour_url', 'retry_url' ) );
            include PC_PLUGIN_DIR . 'templates/payment-cancel.php';
        }

        get_footer();
        exit;
    }

    // ── Paiement d'inscription ────────────────────────────────────────

    /**
     * Crée une Session Stripe Checkout pour le paiement d'inscription.
     * Appelé depuis PC_Profile après acceptation du règlement.
     */
    public function creer_session_inscription( int $user_id ): string|false {
        if ( ! $this->load_stripe() ) return false;

        try {
            $montant_cts  = (int) PC_Settings::get( 'montant_participation_cts', 1500 );
            $devise       = strtolower( PC_Settings::get( 'devise', 'EUR' ) );
            $nom_concours = PC_Settings::get( 'nom_concours', 'SDLP' );
            $user         = get_userdata( $user_id );
            $base         = get_permalink( get_option( 'pc_page_espace_candidat' ) ) ?: home_url( '/' );

            $success_url = add_query_arg( [ 'pc_stripe' => 'inscription_ok', 'session_id' => '{CHECKOUT_SESSION_ID}' ], $base );
            $cancel_url  = add_query_arg( [ 'pc_stripe' => 'inscription_cancel' ], $base );

            $session = \Stripe\Checkout\Session::create( [
                'mode'           => 'payment',
                'currency'       => $devise,
                'line_items'     => [ [
                    'price_data' => [
                        'currency'     => $devise,
                        'unit_amount'  => $montant_cts,
                        'product_data' => [
                            'name'        => sprintf( '%s — Participation candidat', $nom_concours ),
                            'description' => __( 'Frais de participation au concours photo', PC_TEXT_DOMAIN ),
                        ],
                    ],
                    'quantity' => 1,
                ] ],
                'customer_email' => $user->user_email,
                'metadata'       => [
                    'pc_user_id' => $user_id,
                    'pc_type'    => 'inscription',
                    'pc_plugin'  => 'photo-contest',
                ],
                'success_url' => $success_url,
                'cancel_url'  => $cancel_url,
                'locale'      => 'fr',
            ] );

            return $session->id;

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            error_log( 'PC_Payments::creer_session_inscription error: ' . $e->getMessage() );
            return false;
        }
    }

    public function ajax_create_inscription_session(): void {
        check_ajax_referer( 'pc_profile_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }

        $user_id  = get_current_user_id();
        $session_id = $this->creer_session_inscription( $user_id );

        if ( ! $session_id ) {
            wp_send_json_error( [ 'message' => __( 'Erreur Stripe — vérifiez la configuration.', PC_TEXT_DOMAIN ) ] );
        }

        // Retourner l'URL Stripe Checkout directement
        try {
            $this->load_stripe();
            $session = \Stripe\Checkout\Session::retrieve( $session_id );
            wp_send_json_success( [ 'url' => $session->url ] );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // ── Création session Stripe Checkout impression ───────────────────

    public function ajax_create_session(): void {
        check_ajax_referer( 'pc_checkout_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! current_user_can( 'pc_pay_participation' ) )
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'photo-contest' ) ] );

        if ( ! $this->is_configured() )
            wp_send_json_error( [ 'message' => __( 'Stripe n\'est pas configuré.', 'photo-contest' ) ] );

        $photo_id = (int) ( $_POST['photo_id'] ?? 0 );
        $user_id  = get_current_user_id();

        if ( ! $photo_id ) wp_send_json_error( [ 'message' => __( 'Photo invalide.', 'photo-contest' ) ] );

        $photo = PC_Photos::get_instance()->get_photo( $photo_id, $user_id );
        if ( ! $photo || ! in_array( $photo['statut'], [ 'participation_demandee', 'retenue' ], true ) )
            wp_send_json_error( [ 'message' => __( 'Photo non éligible.', 'photo-contest' ) ] );

        $pmt_existant = $this->get_paiement_by_photo( $photo_id );
        if ( $pmt_existant && $pmt_existant['statut_paiement'] === 'paiement_recu' )
            wp_send_json_error( [ 'message' => __( 'Cette photo a déjà été payée.', 'photo-contest' ) ] );

        if ( ! $this->load_stripe() )
            wp_send_json_error( [ 'message' => __( 'Erreur initialisation Stripe.', 'photo-contest' ) ] );

        try {
            $montant_cts  = (int) PC_Settings::get( 'montant_participation_cts', 1500 );
            $devise       = strtolower( PC_Settings::get( 'devise', 'EUR' ) );
            $nom_concours = PC_Settings::get( 'nom_concours', 'Concours Photo' );
            $user         = get_userdata( $user_id );
            $base         = get_permalink( get_option( 'pc_page_espace_candidat' ) ) ?: home_url( '/' );

            $success_url = add_query_arg( [ 'pc_stripe' => 'success', 'photo' => $photo_id, 'session_id' => '{CHECKOUT_SESSION_ID}' ], $base );
            $cancel_url  = add_query_arg( [ 'pc_stripe' => 'cancel',  'photo' => $photo_id ], $base );

            $session = \Stripe\Checkout\Session::create( [
                'mode'           => 'payment',
                'currency'       => $devise,
                'line_items'     => [ [
                    'price_data' => [
                        'currency'     => $devise,
                        'unit_amount'  => $montant_cts,
                        'product_data' => [
                            'name'        => sprintf( '%s — Photo #%s', $nom_concours, str_pad( $photo_id, 5, '0', STR_PAD_LEFT ) ),
                            'description' => __( 'Participation à l\'impression pour l\'exposition', 'photo-contest' ),
                        ],
                    ],
                    'quantity' => 1,
                ] ],
                'customer_email' => $user->user_email,
                'metadata'       => [ 'pc_photo_id' => $photo_id, 'pc_user_id' => $user_id, 'pc_plugin' => 'photo-contest' ],
                'success_url'    => $success_url,
                'cancel_url'     => $cancel_url,
                'locale'         => 'fr',
            ] );

            // Enregistrement en attente
            $this->upsert_paiement( $photo_id, $user_id, $montant_cts, strtoupper( $devise ), [
                'statut_paiement'   => 'en_attente',
                'reference_externe' => $session->id,
                'methode'           => 'stripe_checkout',
            ] );

            wp_send_json_success( [ 'session_id' => $session->id ] );

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
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
            case 'payment_intent.payment_failed':
                $this->on_payment_failed( $event->data->object );
                break;
        }

        http_response_code( 200 ); echo 'OK';
    }

    private function on_checkout_completed( object $session ): void {
        $photo_id = (int) ( $session->metadata->pc_photo_id ?? 0 );
        $user_id  = (int) ( $session->metadata->pc_user_id  ?? 0 );
        $type     = $session->metadata->pc_type ?? 'impression';
        $token    = (string) ( $session->metadata->pc_payment_token ?? '' );

        if ( ! $user_id ) return;

        // Paiement d'inscription (avant dépôt photos)
        if ( $type === 'inscription' ) {
            do_action( 'pc_inscription_paiement_recu', $user_id );
            return;
        }

        // Phase 2 Task 9 : paiement groupé par token (plusieurs photos en une session)
        if ( $token !== '' ) {
            $this->on_token_checkout_completed( $token, $session );
            return;
        }

        // Paiement impression (conservé pour compatibilité)
        if ( ! $photo_id ) return;

        $this->upsert_paiement( $photo_id, $user_id,
            (int) ( $session->amount_total ?? 0 ),
            strtoupper( $session->currency ?? 'EUR' ),
            [
                'statut_paiement'   => 'paiement_recu',
                'reference_externe' => $session->payment_intent ?? $session->id,
                'methode'           => 'stripe_checkout',
            ]
        );

        // Déclenche Fluent CRM + création entrée catalogue
        PC_Photos::get_instance()->update_statut( $photo_id, 'paiement_recu' );
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
            "SELECT id, photo_id FROM {$table}
             WHERE payment_token = %s AND statut_paiement = 'en_attente'",
            $token
        ) );

        if ( empty( $rows ) ) return;

        $reference = (string) ( $session->payment_intent ?? $session->id );
        $photos    = PC_Photos::get_instance();

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

            // Déclenche Fluent CRM + création entrée catalogue
            $photos->update_statut( (int) $row->photo_id, 'paiement_recu' );
        }
    }

    private function on_payment_failed( object $intent ): void {
        $photo_id = (int) ( $intent->metadata->pc_photo_id ?? 0 );
        $user_id  = (int) ( $intent->metadata->pc_user_id  ?? 0 );
        if ( ! $photo_id || ! $user_id ) return;

        $this->upsert_paiement( $photo_id, $user_id, 0, 'EUR', [
            'statut_paiement'   => 'echoue',
            'reference_externe' => $intent->id,
            'methode'           => 'stripe_checkout',
        ] );
    }

    // ── CRUD paiements ────────────────────────────────────────────────

    public function upsert_paiement( int $photo_id, int $user_id, int $montant_cts, string $devise, array $data ): void {
        global $wpdb;
        $table   = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $existant = $this->get_paiement_by_photo( $photo_id );
        $payload  = array_merge( [ 'montant_centimes' => $montant_cts, 'devise' => strtoupper( $devise ) ], $data );

        if ( $existant ) {
            $wpdb->update( $table, $payload, [ 'photo_id' => $photo_id ] );
        } else {
            $wpdb->insert( $table, array_merge( $payload, [ 'photo_id' => $photo_id, 'user_id' => $user_id ] ) );
        }
    }

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

    // ── Clôture jury (Phase 2) ────────────────────────────────────────

    /**
     * Phase 2 : statistiques pré-clôture pour le récap admin.
     *
     * @return array{en_delibration_a_refuser:int, photos_retenues:int, candidats_a_notifier:int, montant_total_cts:int, montant_unitaire_cts:int}
     */
    public static function get_cloture_stats(): array {
        global $wpdb;
        $photos      = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $montant_cts = (int) PC_Settings::get( 'montant_participation_cts', 1500 );

        $en_delib  = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$photos} WHERE statut IN ('en_attente', 'en_examen')"
        );
        $retenues  = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$photos} WHERE statut = 'retenue'"
        );
        $candidats = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$photos} WHERE statut = 'retenue'"
        );

        return [
            'en_delibration_a_refuser' => $en_delib,
            'photos_retenues'          => $retenues,
            'candidats_a_notifier'     => $candidats,
            'montant_total_cts'        => $retenues * $montant_cts,
            'montant_unitaire_cts'     => $montant_cts,
        ];
    }

    /**
     * Phase 2 : exécute la clôture de la délibération du jury.
     *
     * Séquence :
     *  1. Verrou anti-double-clic (cloture_en_cours)
     *  2. Refus en lot des photos restantes en délibération
     *  3. Pour chaque candidat à photos retenues : génération d'un payment_token, bascule vers
     *     participation_demandee, INSERT dans wp_pc_payments (1 ligne par photo)
     *  4. Bascule des flags (jury_actif=false, catalogue_actif=true, cloture_effectuee_at=time())
     *  5. Planification du 1er tick cron d'envoi d'emails
     *  6. do_action( 'pc_cloture_jury_effectuee' )
     *
     * @return array{refused_count?:int, retenues_count?:int, candidats_count?:int, error?:string}
     */
    public static function execute_cloture(): array {
        global $wpdb;
        $photos_t    = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $payments_t  = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $montant_cts = (int) PC_Settings::get( 'montant_participation_cts', 1500 );

        // 1. Verrou anti-double-clic
        $lock_at = (int) PC_Settings::get( 'cloture_en_cours', 0 );
        if ( $lock_at && ( time() - $lock_at ) < 300 ) {
            return [ 'error' => 'cloture_en_cours' ];
        }
        PC_Settings::set( 'cloture_en_cours', time() );

        // 2. Refus en lot
        $refused = (int) $wpdb->query(
            "UPDATE {$photos_t}
             SET statut = 'refusee'
             WHERE statut IN ('en_attente', 'en_examen')"
        );

        // 3. Bascule par candidat + création paiements
        $candidats = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$photos_t} WHERE statut = 'retenue'"
        );

        $total_retenues = 0;
        foreach ( $candidats as $user_id ) {
            $user_id = (int) $user_id;
            $token   = wp_generate_uuid4();

            $retenue_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$photos_t} WHERE user_id = %d AND statut = 'retenue'",
                $user_id
            ) );

            foreach ( $retenue_ids as $photo_id ) {
                $photo_id = (int) $photo_id;
                $wpdb->update( $photos_t, [ 'statut' => 'participation_demandee' ], [ 'id' => $photo_id ] );
                $wpdb->insert( $payments_t, [
                    'photo_id'         => $photo_id,
                    'user_id'          => $user_id,
                    'montant_centimes' => $montant_cts,
                    'devise'           => 'EUR',
                    'statut_paiement'  => 'en_attente',
                    'methode'          => 'stripe_checkout',
                    'payment_token'    => $token,
                ] );
                $total_retenues++;
            }
        }

        // 4. Bascule des flags
        PC_Settings::set( 'jury_actif',           false );
        PC_Settings::set( 'catalogue_actif',      true );   // ouverture auto du catalogue
        PC_Settings::set( 'cloture_en_cours',     0 );
        PC_Settings::set( 'cloture_effectuee_at', time() );

        // 5. Planification du 1er tick cron d'envoi d'emails
        if ( ! wp_next_scheduled( 'pc_send_payment_email_batch' ) ) {
            wp_schedule_single_event( time(), 'pc_send_payment_email_batch' );
        }

        // 6. Trigger
        do_action( 'pc_cloture_jury_effectuee' );

        return [
            'refused_count'   => $refused,
            'retenues_count'  => $total_retenues,
            'candidats_count' => count( $candidats ),
        ];
    }

    /**
     * Phase 2 : tick WP-Cron — envoie une fournée d'emails de demande de paiement groupé.
     *
     * SELECT groupe (user_id, payment_token) avec email_envoye_at IS NULL, taille bornée par
     * pc_settings.email_batch_size. Pour chaque groupe : trigger Fluent CRM (Task 12) + marquer
     * email_envoye_at. Re-planifie un tick suivant si reste des entrées en attente.
     */
    public function cron_send_batch(): void {
        global $wpdb;
        $payments = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $batch    = max( 1, min( 100, (int) PC_Settings::get( 'email_batch_size', 20 ) ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, payment_token,
                    GROUP_CONCAT(photo_id) AS photo_ids,
                    SUM(montant_centimes) AS montant_total,
                    COUNT(*)              AS nb_photos
             FROM {$payments}
             WHERE email_envoye_at IS NULL
               AND statut_paiement = 'en_attente'
               AND payment_token IS NOT NULL
             GROUP BY user_id, payment_token
             LIMIT %d",
            $batch
        ) );

        foreach ( $rows as $row ) {
            $user_id     = (int) $row->user_id;
            $token       = (string) $row->payment_token;
            $nb_photos   = (int) $row->nb_photos;
            $montant_cts = (int) $row->montant_total;
            $payment_url = home_url( '/?pc_pay=' . rawurlencode( $token ) );

            // Hook consommé par PC_Fluent_CRM (Task 12). En l'absence de listener,
            // l'événement est silencieusement ignoré (graceful degradation).
            do_action( 'pc_participation_demandee_groupee', $user_id, [
                'nb_photos'   => $nb_photos,
                'montant_cts' => $montant_cts,
                'payment_url' => $payment_url,
            ] );

            // Marquer envoyés (idempotent : si l'UPDATE échoue partiellement, le tick suivant
            // retentera mais le hook Fluent CRM pourra être déclenché plusieurs fois ; la
            // déduplication finale est côté Fluent CRM via le contact email).
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$payments}
                 SET email_envoye_at = NOW()
                 WHERE user_id = %d AND payment_token = %s AND email_envoye_at IS NULL",
                $user_id, $token
            ) );
        }

        // S'il reste des paiements en attente d'email, replanifier le prochain tick
        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$payments}
             WHERE email_envoye_at IS NULL
               AND statut_paiement = 'en_attente'
               AND payment_token IS NOT NULL"
        );

        if ( $remaining > 0 ) {
            $itv = max( 1, min( 60, (int) PC_Settings::get( 'email_batch_interval_minutes', 5 ) ) );
            if ( ! wp_next_scheduled( 'pc_send_payment_email_batch' ) ) {
                wp_schedule_single_event( time() + ( $itv * 60 ), 'pc_send_payment_email_batch' );
            }
        }
    }

    /**
     * Phase 2 : tick WP-Cron quotidien — relance des candidats avec paiements impayés.
     *
     * Désactivé si pc_settings.relance_jours = 0 ou pc_settings.relance_max = 0.
     *
     * Conditions de sélection :
     *  - statut_paiement = 'en_attente'
     *  - email_envoye_at IS NOT NULL (l'email initial a été envoyé)
     *  - email_envoye_at < NOW() - INTERVAL relance_jours DAY (assez vieux)
     *  - nb_relances < relance_max (n'a pas atteint le plafond)
     *  - derniere_relance_at IS NULL OR < NOW() - INTERVAL relance_jours DAY (cooldown respecté)
     *
     * Pour chaque candidat retourné : trigger pc_relance_paiement, UPDATE nb_relances + derniere_relance_at.
     * La taille de lot est bornée par pc_settings.email_batch_size (même limite que l'envoi initial).
     */
    public function cron_relances_quotidiennes(): void {
        global $wpdb;

        $relance_jours = (int) PC_Settings::get( 'relance_jours', 5 );
        $relance_max   = (int) PC_Settings::get( 'relance_max', 2 );

        if ( $relance_jours === 0 || $relance_max === 0 ) {
            return; // Désactivé via réglages
        }

        $payments = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $batch    = max( 1, min( 100, (int) PC_Settings::get( 'email_batch_size', 20 ) ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, payment_token,
                    COUNT(*)                  AS nb_photos,
                    SUM(montant_centimes)     AS montant_total,
                    MAX(nb_relances)          AS nb_relances_actuel,
                    MAX(derniere_relance_at)  AS last_relance
             FROM {$payments}
             WHERE statut_paiement = 'en_attente'
               AND email_envoye_at IS NOT NULL
               AND email_envoye_at < DATE_SUB( NOW(), INTERVAL %d DAY )
               AND payment_token IS NOT NULL
             GROUP BY user_id, payment_token
             HAVING nb_relances_actuel < %d
                AND ( last_relance IS NULL
                      OR last_relance < DATE_SUB( NOW(), INTERVAL %d DAY ) )
             LIMIT %d",
            $relance_jours, $relance_max, $relance_jours, $batch
        ) );

        foreach ( $rows as $row ) {
            $user_id     = (int) $row->user_id;
            $token       = (string) $row->payment_token;
            $nb_photos   = (int) $row->nb_photos;
            $montant_cts = (int) $row->montant_total;
            $payment_url = home_url( '/?pc_pay=' . rawurlencode( $token ) );
            $nb_done     = (int) $row->nb_relances_actuel + 1;

            // Hook consommé par PC_Fluent_CRM (Task 12). Sans listener : graceful degradation.
            do_action( 'pc_relance_paiement', $user_id, [
                'nb_photos'   => $nb_photos,
                'montant_cts' => $montant_cts,
                'payment_url' => $payment_url,
                'nb_relances' => $nb_done,
            ] );

            // Incrément nb_relances + horodatage derniere_relance_at sur toutes les lignes du groupe
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$payments}
                 SET derniere_relance_at = NOW(),
                     nb_relances         = nb_relances + 1
                 WHERE user_id = %d
                   AND payment_token = %s
                   AND statut_paiement = 'en_attente'",
                $user_id, $token
            ) );
        }
    }

    // ── Utilitaires ───────────────────────────────────────────────────

    public function get_thumb_url( int $photo_id ): string {
        $token = wp_create_nonce( "pc_photo_{$photo_id}_" . get_current_user_id() );
        return add_query_arg( [ 'pc_photo' => $photo_id, 'taille' => 'thumb', 'token' => $token ], home_url( '/' ) );
    }

    public function maybe_enqueue_assets(): void {
        if ( isset( $_GET['pc_action'] ) || isset( $_GET['pc_stripe'] ) )
            wp_enqueue_style( 'pc-payment', PC_PLUGIN_URL . 'public/css/pc-payment.css', [], PC_VERSION . '.2' );
    }
}