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

        if ( ! $user_id ) return;

        // Paiement d'inscription (avant dépôt photos)
        if ( $type === 'inscription' ) {
            do_action( 'pc_inscription_paiement_recu', $user_id );
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