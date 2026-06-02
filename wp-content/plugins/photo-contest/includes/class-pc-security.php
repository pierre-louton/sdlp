<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sécurité et accès — Module transversal.
 *
 * Fonctions :
 *  1. Masquer la barre d'administration WordPress en front pour candidats/jury
 *  2. Rediriger candidats et jury hors de /wp-admin vers leur espace
 *  3. Changer l'URL de connexion (/connexion au lieu de /wp-login.php)
 *  4. Masquer les erreurs de connexion (ne pas révéler si l'email existe)
 *  5. Désactiver l'API REST WP pour les non-connectés (sauf endpoints plugin)
 *  6. Ajouter les headers de sécurité HTTP
 */
class PC_Security {

    private static ?self $instance = null;

    /** Slug de la page de connexion custom. Configurable via PC_Settings. */
    private string $login_slug;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct() {
        $this->login_slug = PC_Settings::get( 'login_slug', 'connexion' );

        // ── Barre admin ──────────────────────────────────────────────
        add_action( 'after_setup_theme',     [ $this, 'masquer_barre_admin' ] );

        // ── Redirections wp-admin ────────────────────────────────────
        add_action( 'admin_init',            [ $this, 'rediriger_non_admins' ] );

        // ── URL de connexion custom ──────────────────────────────────
        add_action( 'init',                  [ $this, 'register_login_page' ] );
        add_filter( 'login_url',             [ $this, 'filter_login_url' ], 10, 3 );
        add_filter( 'logout_url',            [ $this, 'filter_logout_url' ] );
        add_filter( 'site_url',              [ $this, 'filter_site_url' ], 10, 4 );
        add_filter( 'network_site_url',      [ $this, 'filter_network_site_url' ], 10, 3 );
        add_action( 'template_redirect',     [ $this, 'handle_login_page' ] );

        // Blocage wp-login.php via mu-plugin (s'exécute avant WordPress)
        add_action( 'plugins_loaded',        [ $this, 'creer_mu_plugin_login' ] );

        // ── Sécurité connexion ───────────────────────────────────────
        add_filter( 'login_errors',          [ $this, 'masquer_erreurs_login' ] );
        add_filter( 'authenticate',          [ $this, 'limiter_tentatives' ], 30, 3 );

        // ── Headers HTTP sécurité ────────────────────────────────────
        add_action( 'send_headers',          [ $this, 'security_headers' ] );

        // ── REST API ─────────────────────────────────────────────────
        add_filter( 'rest_authentication_errors', [ $this, 'restreindre_rest_api' ] );
    }

    // ──────────────────────────────────────────────────────────────────
    // 1. Barre d'administration
    // ──────────────────────────────────────────────────────────────────

    public function masquer_barre_admin(): void {
        if ( ! is_user_logged_in() ) return;

        $user = wp_get_current_user();
        $roles_sans_barre = [ PC_ROLE_CANDIDAT, PC_ROLE_JURY, PC_ROLE_CATALOGUE ];

        foreach ( $roles_sans_barre as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                // Masquer la barre admin en front
                show_admin_bar( false );
                // Empêcher l'accès aux pages d'admin WP spécifiques au rôle
                add_filter( 'user_has_cap', [ $this, 'retirer_cap_dashboard' ], 10, 4 );
                return;
            }
        }
    }

    /**
     * Retire la capability 'read' du dashboard WP (pas du site front).
     * Ne touche pas aux capabilities custom du plugin.
     */
    public function retirer_cap_dashboard( array $allcaps, array $caps, array $args, WP_User $user ): array {
        // On ne touche qu'aux rôles non-admin
        if ( array_intersect( [ 'administrator', 'editor' ], (array) $user->roles ) ) {
            return $allcaps;
        }
        // Retirer l'accès aux menus admin standard (pas aux nôtres)
        $allcaps['edit_posts']             = false;
        $allcaps['edit_pages']             = false;
        $allcaps['manage_categories']      = false;
        $allcaps['moderate_comments']      = false;
        $allcaps['upload_files']           = false;
        return $allcaps;
    }

    // ──────────────────────────────────────────────────────────────────
    // 2. Redirection wp-admin → espace dédié
    // ──────────────────────────────────────────────────────────────────

    public function rediriger_non_admins(): void {
        if ( ! is_user_logged_in() ) return;
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;

        $user = wp_get_current_user();

        // Candidat → espace galerie
        if ( in_array( PC_ROLE_CANDIDAT, (array) $user->roles, true ) ) {
            $url = get_permalink( get_option( 'pc_page_espace_candidat' ) );
            if ( $url ) {
                wp_safe_redirect( $url );
                exit;
            }
        }

        // Jury → espace jury
        if ( in_array( PC_ROLE_JURY, (array) $user->roles, true ) ) {
            $url = get_permalink( get_option( 'pc_page_jury' ) );
            if ( $url ) {
                wp_safe_redirect( $url );
                exit;
            }
        }

        // Catalogue editor → page catalogue
        if ( in_array( PC_ROLE_CATALOGUE, (array) $user->roles, true ) ) {
            $url = get_permalink( get_option( 'pc_page_catalogue' ) );
            if ( $url ) {
                wp_safe_redirect( $url );
                exit;
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // 3. URL de connexion custom (/connexion)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Enregistre la query var pour la page de connexion custom.
     */
    public function register_login_page(): void {
        add_rewrite_tag( '%pc_login%', '([^&]+)' );
        add_rewrite_rule(
            '^' . $this->login_slug . '/?$',
            'index.php?pc_login=1',
            'top'
        );
        // Flush si la règle n'existe pas encore OU si le slug a changé depuis le dernier flush
        $flushed_slug = get_option( 'pc_login_rewrite_flushed', '' );
        if ( $flushed_slug !== $this->login_slug ) {
            flush_rewrite_rules();
            update_option( 'pc_login_rewrite_flushed', $this->login_slug );
        }
    }

    /**
     * Remplace l'URL de connexion WP par notre URL custom.
     */
    public function filter_login_url( string $login_url, string $redirect, bool $force_reauth ): string {
        $url = home_url( '/' . $this->login_slug . '/' );
        if ( $redirect ) {
            $url = add_query_arg( 'redirect_to', urlencode( $redirect ), $url );
        }
        if ( $force_reauth ) {
            $url = add_query_arg( 'reauth', '1', $url );
        }
        return $url;
    }

    public function filter_logout_url( string $logout_url ): string {
        return $logout_url; // logout reste sur wp-login.php?action=logout avec nonce
    }

    /**
     * Empêche les références directes à wp-login.php dans les URLs générées.
     */
    public function filter_site_url( string $url, string $path, ?string $scheme, ?int $blog_id ): string {
        if ( str_contains( $url, 'wp-login.php' ) && ! str_contains( $url, 'action=logout' ) ) {
            $url = str_replace( 'wp-login.php', $this->login_slug . '/', $url );
        }
        return $url;
    }

    public function filter_network_site_url( string $url, string $path, ?string $scheme ): string {
        if ( str_contains( $url, 'wp-login.php' ) && ! str_contains( $url, 'action=logout' ) ) {
            $url = str_replace( 'wp-login.php', $this->login_slug . '/', $url );
        }
        return $url;
    }

    /**
     * Gère la page de connexion custom : affiche le formulaire WP stylé.
     */
    public function handle_login_page(): void {
        // Détection via rewrite rule (pc_login=1) OU via REQUEST_URI direct
        // Le second cas couvre les URLs avec query string : /connexion/?pc_verify_error=1
        $via_rewrite = get_query_var( 'pc_login' ) === '1';
        $request_uri = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        $via_slug    = $request_uri === $this->login_slug
                    || $request_uri === $this->login_slug . '/';

        if ( ! $via_rewrite && ! $via_slug ) return;

        // Si déjà connecté, rediriger
        if ( is_user_logged_in() ) {
            wp_safe_redirect( $this->get_redirect_after_login() );
            exit;
        }

        // Traitement du formulaire soumis
        $error   = '';
        $success = '';

        if ( isset( $_POST['pc_login_submit'] ) ) {
            check_admin_referer( 'pc_login_form' );

            $creds = [
                'user_login'    => sanitize_text_field( $_POST['log'] ?? '' ),
                'user_password' => $_POST['pwd'] ?? '',
                'remember'      => ! empty( $_POST['rememberme'] ),
            ];

            $user = wp_signon( $creds, is_ssl() );

            if ( is_wp_error( $user ) ) {
                $error = __( 'Identifiant ou mot de passe incorrect.', PC_TEXT_DOMAIN );
            } else {
                // Respecter le redirect_to s'il pointe vers notre domaine
                $redirect_to = sanitize_url( $_POST['redirect_to'] ?? $_GET['redirect_to'] ?? '' );
                if ( $redirect_to && wp_validate_redirect( $redirect_to, '' ) ) {
                    wp_safe_redirect( $redirect_to );
                } else {
                    wp_safe_redirect( $this->get_redirect_after_login( $user ) );
                }
                exit;
            }
        }

        // Affichage de la page de connexion — page HTML autonome
        // On n'utilise PAS get_header()/get_footer() car Elementor intercepte
        // le cycle de rendu et produit une page blanche en conflit.
        // Cette approche est identique à celle de wp-login.php natif.
        status_header( 200 );
        nocache_headers();

        $logo_url     = PC_Settings::get( 'logo_url', '' );
        $nom_concours = PC_Settings::get( 'nom_concours', 'SDLP' );
        $redirect_to  = sanitize_url( $_GET['redirect_to'] ?? '' );

        // CSS login inline — chargé directement sans wp_enqueue (headers déjà partis avec get_header sinon)
        $login_css_url  = PC_PLUGIN_URL . 'public/css/pc-login.css?v=' . PC_VERSION . '.2';
        $site_name      = get_bloginfo( 'name' );
        $charset        = get_bloginfo( 'charset' );

        // Empêcher Elementor et autres page builders de s'injecter sur cette page autonome
        add_filter( 'elementor/frontend/the_content', '__return_false' );
        add_filter( 'elementor_pro/frontend/the_content', '__return_false' );
        remove_all_actions( 'elementor/page_templates/canvas/before_content' );
        remove_all_actions( 'elementor/page_templates/canvas/after_content' );

        // Page HTML complète autonome
        header( 'Content-Type: text/html; charset=' . $charset );
        ?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="<?php echo esc_attr( $charset ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $nom_concours . ' — ' . __( 'Connexion', PC_TEXT_DOMAIN ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( $login_css_url ); ?>">
<?php wp_head(); ?>
</head>
<body class="pc-login-page">
<?php include PC_PLUGIN_DIR . 'templates/login.php'; ?>
<?php wp_footer(); ?>
</body>
</html>
<?php
        exit;
    }

    /**
     * Crée un mu-plugin qui s'exécute très tôt dans le cycle WordPress
     * et redirige wp-login.php vers l'URL custom.
     *
     * Un mu-plugin (must-use plugin) est chargé automatiquement par WordPress
     * avant tous les plugins normaux — c'est la seule façon fiable d'intercepter
     * wp-login.php sans toucher au .htaccess.
     *
     * Le fichier est recréé à chaque chargement si absent (idempotent).
     */
    public function creer_mu_plugin_login(): void {
        $mu_dir  = WPMU_PLUGIN_DIR;
        $mu_file = $mu_dir . '/pc-login-redirect.php';

        // Reconstruire si le slug a changé ou si le fichier est absent
        $slug_actuel = PC_Settings::get( 'login_slug', 'connexion' );
        $contenu_attendu_hash = md5( $slug_actuel );
        $hash_stocke = get_option( 'pc_mu_plugin_hash', '' );

        if ( file_exists( $mu_file ) && $hash_stocke === $contenu_attendu_hash ) {
            return; // déjà à jour
        }

        // Créer le dossier mu-plugins s'il n'existe pas
        if ( ! file_exists( $mu_dir ) ) {
            wp_mkdir_p( $mu_dir );
        }

        $login_url = home_url( '/' . $slug_actuel . '/' );

        $contenu = '<?php' . PHP_EOL
            . '/**' . PHP_EOL
            . ' * Photo Contest — Login redirect (auto-généré, ne pas modifier)' . PHP_EOL
            . ' * Supprimez ce fichier pour rétablir /wp-login.php.' . PHP_EOL
            . ' */' . PHP_EOL
            . 'defined( \'ABSPATH\' ) || exit;' . PHP_EOL
            . PHP_EOL
            . '// Actions légitimes qui doivent rester sur wp-login.php' . PHP_EOL
            . '$actions_ok = [\'logout\', \'lostpassword\', \'retrievepassword\', \'resetpass\', \'rp\', \'confirmaction\', \'postpass\'];' . PHP_EOL
            . '$action = sanitize_key( $_GET[\'action\'] ?? $_POST[\'action\'] ?? \'\' );' . PHP_EOL
            . PHP_EOL
            . 'if (' . PHP_EOL
            . '    isset( $_SERVER[\'SCRIPT_FILENAME\'] ) &&' . PHP_EOL
            . '    str_ends_with( $_SERVER[\'SCRIPT_FILENAME\'], \'wp-login.php\' ) &&' . PHP_EOL
            . '    ! in_array( $action, $actions_ok, true ) &&' . PHP_EOL
            . '    ! isset( $_GET[\'key\'] )' . PHP_EOL
            . ') {' . PHP_EOL
            . '    header( \'Location: ' . esc_url_raw( $login_url ) . '\', true, 301 );' . PHP_EOL
            . '    exit;' . PHP_EOL
            . '}' . PHP_EOL;

        file_put_contents( $mu_file, $contenu );
        update_option( 'pc_mu_plugin_hash', $contenu_attendu_hash );
    }

    /**
     * Supprime le mu-plugin à la désinstallation du plugin.
     */
    public static function supprimer_mu_plugin(): void {
        $mu_file = WPMU_PLUGIN_DIR . '/pc-login-redirect.php';
        if ( file_exists( $mu_file ) ) {
            unlink( $mu_file );
        }
        delete_option( 'pc_mu_plugin_hash' );
        delete_option( 'pc_login_rewrite_flushed' ); // sera recréé avec le slug courant au prochain chargement
    }

    /**
     * Détermine l'URL de redirection après connexion selon le rôle.
     */
    private function get_redirect_after_login( ?WP_User $user = null ): string {
        $user  = $user ?: wp_get_current_user();
        $roles = (array) $user->roles;

        if ( in_array( PC_ROLE_CANDIDAT, $roles, true ) ) {
            return get_permalink( get_option( 'pc_page_espace_candidat' ) ) ?: home_url( '/' );
        }
        if ( in_array( PC_ROLE_JURY, $roles, true ) ) {
            return get_permalink( get_option( 'pc_page_jury' ) ) ?: home_url( '/' );
        }
        if ( in_array( PC_ROLE_CATALOGUE, $roles, true ) ) {
            return get_permalink( get_option( 'pc_page_catalogue' ) ) ?: home_url( '/' );
        }
        // Administrateurs et éditeurs → wp-admin
        if ( $user->has_cap( 'manage_options' ) || $user->has_cap( 'edit_posts' ) ) {
            return admin_url();
        }
        // Tous les autres rôles inconnus → accueil du site
        return home_url( '/' );
    }

    // ──────────────────────────────────────────────────────────────────
    // 4. Sécurité connexion
    // ──────────────────────────────────────────────────────────────────

    /**
     * Message d'erreur générique — ne révèle pas si l'email/login existe.
     */
    public function masquer_erreurs_login( string $error ): string {
        return __( 'Identifiant ou mot de passe incorrect.', PC_TEXT_DOMAIN );
    }

    /**
     * Limite les tentatives de connexion par IP (5 max sur 15 min).
     * Stocké en transients WordPress, sans plugin tiers.
     */
    public function limiter_tentatives( $user, string $username, string $password ) {
        if ( empty( $username ) || empty( $password ) ) return $user;

        $ip       = $this->get_client_ip();
        $key      = 'pc_login_attempts_' . md5( $ip );
        $attempts = (int) get_transient( $key );
        $max      = (int) PC_Settings::get( 'login_max_attempts', 5 );
        $duree    = (int) PC_Settings::get( 'login_lockout_minutes', 15 ) * 60;

        if ( $attempts >= $max ) {
            return new WP_Error(
                'pc_too_many_attempts',
                sprintf(
                    __( 'Trop de tentatives. Réessayez dans %d minutes.', PC_TEXT_DOMAIN ),
                    ceil( $duree / 60 )
                )
            );
        }

        // Incrémenter après un échec (on vérifie en filtre authenticate, priorité 30)
        if ( ! is_wp_error( $user ) && $user instanceof WP_User ) {
            // Connexion réussie — réinitialiser le compteur
            delete_transient( $key );
        } else {
            set_transient( $key, $attempts + 1, $duree );
        }

        return $user;
    }

    private function get_client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $header ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '0.0.0.0';
    }

    // ──────────────────────────────────────────────────────────────────
    // 5. REST API
    // ──────────────────────────────────────────────────────────────────

    /**
     * Bloque l'API REST WP pour les non-connectés.
     * Les endpoints du plugin (/photo-contest/v1/) restent accessibles.
     */
    public function restreindre_rest_api( $errors ) {
        if ( is_user_logged_in() ) return $errors;

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Laisser passer les endpoints du plugin et les endpoints WP essentiels
        $whitelist = [
            '/photo-contest/v1/',
            '/wp/v2/types',       // nécessaire pour Gutenberg
            '/wp/v2/taxonomies',
        ];

        foreach ( $whitelist as $allowed ) {
            if ( str_contains( $request_uri, $allowed ) ) return $errors;
        }

        return new WP_Error(
            'rest_not_logged_in',
            __( 'Authentification requise.', PC_TEXT_DOMAIN ),
            [ 'status' => 401 ]
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // 6. Headers HTTP
    // ──────────────────────────────────────────────────────────────────

    public function security_headers(): void {
        if ( is_admin() ) return;

        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'X-XSS-Protection: 1; mode=block' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
    }
}
