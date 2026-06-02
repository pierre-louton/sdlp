<?php
defined( 'ABSPATH' ) || exit;

/**
 * Module Inscription & Vérification Email.
 *
 * Nouveau flux candidat :
 *  1. Inscription sur /connexion/ → compte créé, email de vérification envoyé
 *  2. Clic sur le lien de vérification → email_verifie = 1
 *  3. Redirection vers profil → complétion + règlement
 *  4. Redirection vers paiement d'inscription → Stripe
 *  5. Paiement reçu → paiement_inscription_recu = 1 → accès galerie
 *
 * Anti-fraude :
 *  - Rate limiting inscription : 3 comptes max / IP / 24h
 *  - Email unique (WordPress natif)
 *  - Vérification email obligatoire avant tout accès
 */
class PC_Registration {

    private static ?self $instance = null;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct() {
        // Enregistrement de la query var pour le lien de vérification
        add_action( 'init',               [ $this, 'register_verify_endpoint' ] );

        // CRITIQUE : intercepter le lien de vérification AVANT Elementor.
        // Le module image-loading-optimization d'Elementor démarre un output buffer
        // très tôt sur 'init' qui empêche ensuite tout envoi de header.
        // 'plugins_loaded' priorité 20 = APRÈS pc_load_plugin (qui crée nos classes
        // sur plugins_loaded sans priorité = 10) mais AVANT que 'init' ne démarre.
        add_action( 'plugins_loaded',     [ $this, 'handle_verify_email' ], 20 );

        // AJAX inscription
        add_action( 'wp_ajax_nopriv_pc_register', [ $this, 'ajax_register' ] );

        // Hook après paiement inscription reçu
        add_action( 'pc_inscription_paiement_recu', [ $this, 'on_inscription_paiement_recu' ] );
    }

    // ──────────────────────────────────────────────────────────────────
    // Inscription
    // ──────────────────────────────────────────────────────────────────

    /**
     * Traite le formulaire d'inscription depuis la page /connexion/.
     * Appelé en AJAX (nopriv) ou directement depuis handle_login_page.
     */
    public function inscrire( string $email, string $password, string $password2 ): array {
        // Validation basique
        if ( empty( $email ) || ! is_email( $email ) ) {
            return [ 'success' => false, 'message' => __( 'Adresse email invalide.', PC_TEXT_DOMAIN ) ];
        }
        if ( strlen( $password ) < 8 ) {
            return [ 'success' => false, 'message' => __( 'Le mot de passe doit contenir au moins 8 caractères.', PC_TEXT_DOMAIN ) ];
        }
        if ( $password !== $password2 ) {
            return [ 'success' => false, 'message' => __( 'Les mots de passe ne correspondent pas.', PC_TEXT_DOMAIN ) ];
        }

        // Email déjà utilisé
        if ( email_exists( $email ) ) {
            return [ 'success' => false, 'message' => __( 'Cette adresse email est déjà utilisée.', PC_TEXT_DOMAIN ) ];
        }

        // Rate limiting par IP
        $ip  = $this->get_ip();
        $key = 'pc_reg_' . md5( $ip );
        $nb  = (int) get_transient( $key );
        if ( $nb >= 3 ) {
            return [ 'success' => false, 'message' => __( 'Trop d\'inscriptions depuis cette adresse. Réessayez dans 24h.', PC_TEXT_DOMAIN ) ];
        }

        // Création du compte WordPress
        $username = $this->generer_username( $email );
        $user_id  = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            return [ 'success' => false, 'message' => $user_id->get_error_message() ];
        }

        // Assigner le rôle candidat
        $user = new WP_User( $user_id );
        $user->set_role( PC_ROLE_CANDIDAT );

        // Créer le profil (vide, email non vérifié)
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PROFILES );
        $wpdb->insert( $table, [
            'user_id'       => $user_id,
            'email_verifie' => 0,
            'profil_complet'=> 0,
        ] );

        // Incrémenter le compteur IP
        set_transient( $key, $nb + 1, DAY_IN_SECONDS );

        // Envoyer l'email de vérification
        $this->envoyer_email_verification( $user_id, $email );

        // Sync Fluent CRM
        if ( class_exists( 'PC_Fluent_CRM' ) ) {
            PC_Fluent_CRM::get_instance()->sync_contact( $user_id );
        }

        return [
            'success' => true,
            'message' => __( 'Compte créé ! Vérifiez votre email pour activer votre accès.', PC_TEXT_DOMAIN ),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Vérification email
    // ──────────────────────────────────────────────────────────────────

    /**
     * Génère et envoie un token de vérification email.
     */
    public function envoyer_email_verification( int $user_id, string $email ): void {
        global $wpdb;

        $token    = bin2hex( random_bytes( 32 ) );
        $expires  = date( 'Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS );
        $table    = PC_Database::table( PC_Database::TABLE_EMAIL_TOKENS );

        // Supprimer les anciens tokens non utilisés pour cet utilisateur
        $wpdb->delete( $table, [ 'user_id' => $user_id, 'type' => 'email_verification', 'used' => 0 ] );

        $wpdb->insert( $table, [
            'user_id'    => $user_id,
            'token'      => $token,
            'type'       => 'email_verification',
            'expires_at' => $expires,
        ] );

        $verify_url   = add_query_arg( [ 'pc_verify_email' => $token ], home_url( '/' ) );
        $nom_concours = PC_Settings::get( 'nom_concours', 'SDLP' );

        $sujet = sprintf(
            __( '[%s] Confirmez votre adresse email', PC_TEXT_DOMAIN ),
            $nom_concours
        );

        $message = sprintf(
            __( "Bonjour,\n\nMerci de vous être inscrit au concours %s.\n\nCliquez sur le lien ci-dessous pour confirmer votre adresse email :\n\n%s\n\nCe lien est valable 48 heures.\n\nÀ bientôt,\nL'équipe %s", PC_TEXT_DOMAIN ),
            $nom_concours,
            $verify_url,
            $nom_concours
        );

        wp_mail( $email, $sujet, $message );
    }

    /**
     * Enregistre la query var pour le lien de vérification.
     */
    public function register_verify_endpoint(): void {
        add_filter( 'query_vars', fn( $v ) => array_merge( $v, [ 'pc_verify_email' ] ) );
    }

    /**
     * Traite le clic sur le lien de vérification email.
     * Lit le token depuis $_GET directement car l'URL est de type ?pc_verify_email=token.
     */
    public function handle_verify_email(): void {
        // Lire depuis $_GET directement — plus fiable que get_query_var pour les params GET simples
        $token = sanitize_text_field( $_GET['pc_verify_email'] ?? '' );
        if ( ! $token ) return;

        // Nettoyer tout output déjà bufferisé (Elementor, cache plugins…)
        // pour garantir que wp_set_auth_cookie() et wp_safe_redirect() peuvent
        // envoyer leurs headers sans déclencher "headers already sent".
        if ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_EMAIL_TOKENS );

        // La comparaison expires_at > NOW() peut échouer sur des serveurs locaux
        // (Laragon, WAMP) à cause d'un décalage de timezone entre PHP et MySQL.
        // On récupère le token sans filtrer sur la date et on compare côté PHP.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE token = %s AND type = 'email_verification' AND used = 0",
            sanitize_text_field( $token )
        ), ARRAY_A );

        // Vérification de l'expiration côté PHP (évite les décalages timezone MySQL/PHP)
        if ( $row && strtotime( $row['expires_at'] ) < time() ) {
            $row = null; // expiré
        }

        if ( ! $row ) {
            // Token invalide ou expiré — rediriger vers la page de connexion
            $login_slug = PC_Settings::get( 'login_slug', 'connexion' );
            wp_safe_redirect( add_query_arg( 'pc_verify_error', '1', home_url( '/' . $login_slug . '/' ) ) );
            exit;
        }

        $user_id = (int) $row['user_id'];

        // Marquer le token comme utilisé
        $wpdb->update( $table, [ 'used' => 1 ], [ 'id' => $row['id'] ] );

        // Marquer l'email comme vérifié
        $profiles = PC_Database::table( PC_Database::TABLE_PROFILES );
        $exists   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$profiles} WHERE user_id = %d", $user_id ) );

        if ( $exists ) {
            $wpdb->update( $profiles, [ 'email_verifie' => 1 ], [ 'user_id' => $user_id ] );
        } else {
            $wpdb->insert( $profiles, [ 'user_id' => $user_id, 'email_verifie' => 1 ] );
        }

        // Connecter l'utilisateur automatiquement
        wp_set_auth_cookie( $user_id, false );
        wp_set_current_user( $user_id );

        // Rediriger vers le profil — construire l'URL directement sans get_permalink()
        // car get_permalink() peut déclencher des hooks 'init' indirects (rewrite rules)
        // qui rouvriraient les output buffers d'Elementor.
        $profil_page_id = (int) get_option( 'pc_page_profil' );
        $profil_url     = $profil_page_id
            ? home_url( '/?page_id=' . $profil_page_id )
            : home_url( '/' );
        wp_safe_redirect( add_query_arg( 'pc_email_verifie', '1', $profil_url ) );
        exit;
    }

    // ──────────────────────────────────────────────────────────────────
    // Vérification du statut candidat
    // ──────────────────────────────────────────────────────────────────

    /**
     * Retourne l'étape actuelle du candidat dans le flux d'inscription.
     * Utilisé par PC_Profile pour déterminer la redirection.
     */
    public static function get_etape( int $user_id ): string {
        global $wpdb;
        $table   = PC_Database::table( PC_Database::TABLE_PROFILES );
        $profile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );

        if ( ! $profile )                              return 'email_non_verifie';
        if ( ! $profile['email_verifie'] )             return 'email_non_verifie';
        if ( ! $profile['profil_complet'] )            return 'profil_incomplet';
        if ( ! $profile['reglement_accepte'] )         return 'reglement_non_accepte';
        if ( ! $profile['paiement_inscription_recu'] ) return 'paiement_requis';
        return 'complet';
    }

    /**
     * Le candidat a accès à la galerie uniquement si l'étape est 'complet'.
     */
    public static function peut_deposer( int $user_id ): bool {
        return self::get_etape( $user_id ) === 'complet';
    }

    // ──────────────────────────────────────────────────────────────────
    // Après paiement d'inscription reçu
    // ──────────────────────────────────────────────────────────────────

    public function on_inscription_paiement_recu( int $user_id ): void {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PROFILES );
        $wpdb->update( $table, [ 'paiement_inscription_recu' => 1 ], [ 'user_id' => $user_id ] );
    }

    // ──────────────────────────────────────────────────────────────────
    // AJAX inscription
    // ──────────────────────────────────────────────────────────────────

    public function ajax_register(): void {
        check_ajax_referer( 'pc_register_nonce', 'nonce' );

        $email  = sanitize_email( $_POST['email']     ?? '' );
        $pwd1   = $_POST['password']                  ?? '';
        $pwd2   = $_POST['password2']                 ?? '';

        $result = $this->inscrire( $email, $pwd1, $pwd2 );
        $result['success']
            ? wp_send_json_success( $result )
            : wp_send_json_error( $result );
    }

    // ──────────────────────────────────────────────────────────────────
    // Utilitaires
    // ──────────────────────────────────────────────────────────────────

    private function generer_username( string $email ): string {
        $base = sanitize_user( strstr( $email, '@', true ), true );
        $base = preg_replace( '/[^a-z0-9]/', '', strtolower( $base ) ) ?: 'candidat';

        $username = $base;
        $i        = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $i;
            $i++;
        }
        return $username;
    }

    private function get_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $h ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '0.0.0.0';
    }
}