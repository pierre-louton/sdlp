<?php
defined( 'ABSPATH' ) || exit;

/**
 * Page wp-admin « Concours Photo > Reset (dev) ».
 *
 * Page de remise à zéro pour tests dev. Inerte sans la constante PC_ALLOW_RESET
 * définie à true dans wp-config.php — c'est volontaire pour éviter toute activation
 * accidentelle en production.
 *
 * Capability requise : manage_options.
 */
class PC_Reset_Admin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    public static function is_enabled(): bool {
        return defined( 'PC_ALLOW_RESET' ) && PC_ALLOW_RESET === true;
    }

    private function __construct() {
        add_action( 'admin_menu',               [ $this, 'register_menu' ], 90 );
        add_action( 'admin_enqueue_scripts',    [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_pc_reset_execute', [ $this, 'ajax_execute' ] );
    }

    public function register_menu(): void {
        if ( ! self::is_enabled() ) return;
        add_submenu_page(
            'photo-contest',
            __( 'Reset (dev)', PC_TEXT_DOMAIN ),
            __( 'Reset (dev)', PC_TEXT_DOMAIN ),
            'manage_options',
            'photo-contest-reset',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'photo-contest-reset' ) === false ) return;
        wp_enqueue_style( 'pc-reset-admin', PC_PLUGIN_URL . 'admin/css/pc-reset-admin.css', [], PC_VERSION );
        wp_enqueue_script( 'pc-reset-admin', PC_PLUGIN_URL . 'admin/js/pc-reset-admin.js', [], PC_VERSION, true );
        wp_localize_script( 'pc-reset-admin', 'pcResetAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'pc_reset_nonce' ),
            'i18n'    => [
                'confirm'        => __( 'IRRÉVERSIBLE. Confirmer la suppression ?', PC_TEXT_DOMAIN ),
                'no_scope'       => __( 'Sélectionnez au moins un élément à supprimer.', PC_TEXT_DOMAIN ),
                'must_check_ack' => __( 'Vous devez cocher la confirmation IRRÉVERSIBLE.', PC_TEXT_DOMAIN ),
            ],
        ] );
    }

    public function render_page(): void {
        if ( ! self::is_enabled() ) {
            wp_die( esc_html__( 'Reset désactivé : la constante PC_ALLOW_RESET n\'est pas définie.', PC_TEXT_DOMAIN ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', PC_TEXT_DOMAIN ) );
        }

        // Pré-calcul des chiffres pour le récap
        global $wpdb;
        $counts = [
            'photos'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . PC_Database::table( PC_Database::TABLE_PHOTOS ) ),
            'votes'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . PC_Database::table( PC_Database::TABLE_VOTES ) ),
            'payments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . PC_Database::table( PC_Database::TABLE_PAYMENTS ) ),
            'catalogue'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . PC_Database::table( PC_Database::TABLE_CATALOGUE ) ),
            'candidates' => count( get_users( [ 'role' => PC_ROLE_CANDIDAT, 'fields' => 'ID' ] ) ),
            'jurors'     => count( get_users( [ 'role' => 'jurymembre', 'fields' => 'ID' ] ) ),
        ];

        // Calcul du volume disque privé
        $private_dir = WP_CONTENT_DIR . '/uploads/photo-contest/private/';
        $disk_size   = 0;
        if ( is_dir( $private_dir ) ) {
            $disk_size = $this->dir_size( $private_dir );
        }

        ?>
        <div class="wrap pc-reset-admin">
            <h1><?php esc_html_e( 'Reset Concours Photo (dev)', PC_TEXT_DOMAIN ); ?></h1>

            <div class="notice notice-warning inline">
                <p>
                    <strong><?php esc_html_e( 'Page de test.', PC_TEXT_DOMAIN ); ?></strong>
                    <?php esc_html_e( 'Active uniquement parce que la constante PC_ALLOW_RESET est définie. Retirez-la de wp-config.php pour masquer ce menu.', PC_TEXT_DOMAIN ); ?>
                </p>
            </div>

            <h2><?php esc_html_e( 'État actuel', PC_TEXT_DOMAIN ); ?></h2>
            <table class="widefat striped" style="max-width:600px;">
                <tr><th><?php esc_html_e( 'Photos en BDD', PC_TEXT_DOMAIN ); ?></th><td><?php echo (int) $counts['photos']; ?></td></tr>
                <tr><th><?php esc_html_e( 'Fichiers privés sur disque', PC_TEXT_DOMAIN ); ?></th><td><?php echo esc_html( size_format( $disk_size ) ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Votes jury', PC_TEXT_DOMAIN ); ?></th><td><?php echo (int) $counts['votes']; ?></td></tr>
                <tr><th><?php esc_html_e( 'Paiements', PC_TEXT_DOMAIN ); ?></th><td><?php echo (int) $counts['payments']; ?></td></tr>
                <tr><th><?php esc_html_e( 'Entrées catalogue', PC_TEXT_DOMAIN ); ?></th><td><?php echo (int) $counts['catalogue']; ?></td></tr>
                <tr><th><?php esc_html_e( 'Comptes candidats', PC_TEXT_DOMAIN ); ?></th><td><?php echo (int) $counts['candidates']; ?></td></tr>
                <tr><th><?php esc_html_e( 'Comptes jurés', PC_TEXT_DOMAIN ); ?></th><td><?php echo (int) $counts['jurors']; ?></td></tr>
            </table>

            <h2 style="margin-top:24px"><?php esc_html_e( 'Sélectionner les éléments à supprimer', PC_TEXT_DOMAIN ); ?></h2>
            <fieldset class="pc-reset-fieldset">
                <label><input type="checkbox" name="scope" value="photos" id="pc-reset-photos"> <?php esc_html_e( 'Photos (BDD + fichiers disque)', PC_TEXT_DOMAIN ); ?></label>
                <label><input type="checkbox" name="scope" value="votes"> <?php esc_html_e( 'Votes jury', PC_TEXT_DOMAIN ); ?></label>
                <label><input type="checkbox" name="scope" value="payments"> <?php esc_html_e( 'Paiements', PC_TEXT_DOMAIN ); ?></label>
                <label><input type="checkbox" name="scope" value="catalogue"> <?php esc_html_e( 'Catalogue', PC_TEXT_DOMAIN ); ?></label>
                <label><input type="checkbox" name="scope" value="candidates" id="pc-reset-candidates"> <?php esc_html_e( 'Comptes candidats (force aussi les photos)', PC_TEXT_DOMAIN ); ?></label>
                <label><input type="checkbox" name="scope" value="jurors"> <?php esc_html_e( 'Comptes jurés', PC_TEXT_DOMAIN ); ?></label>
            </fieldset>

            <p style="margin-top:20px">
                <label class="pc-reset-ack">
                    <input type="checkbox" id="pc-reset-ack">
                    <strong><?php esc_html_e( 'Je comprends que cette action est IRRÉVERSIBLE.', PC_TEXT_DOMAIN ); ?></strong>
                </label>
            </p>

            <p>
                <button type="button" class="button button-primary button-large pc-reset-btn" id="pc-reset-btn" disabled>
                    <?php esc_html_e( 'Exécuter le reset', PC_TEXT_DOMAIN ); ?>
                </button>
            </p>

            <div id="pc-reset-msg" style="margin-top:16px"></div>
        </div>
        <?php
    }

    public function ajax_execute(): void {
        // Triple verrouillage
        if ( ! self::is_enabled() ) {
            wp_send_json_error( [ 'message' => __( 'Reset désactivé.', PC_TEXT_DOMAIN ) ] );
        }
        check_ajax_referer( 'pc_reset_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }

        $scope = isset( $_POST['scope'] ) ? (array) $_POST['scope'] : [];
        $scope = array_intersect(
            array_map( 'sanitize_key', $scope ),
            [ 'photos', 'votes', 'payments', 'catalogue', 'candidates', 'jurors' ]
        );

        if ( empty( $scope ) ) {
            wp_send_json_error( [ 'message' => __( 'Aucun élément sélectionné.', PC_TEXT_DOMAIN ) ] );
        }

        // Si on supprime les candidats, on force aussi les photos
        if ( in_array( 'candidates', $scope, true ) && ! in_array( 'photos', $scope, true ) ) {
            $scope[] = 'photos';
        }

        $user_id = get_current_user_id();
        $report  = [];

        global $wpdb;

        // Order matters : delete children before parents (loose FK).
        // 1. Catalogue (depends on photos)
        if ( in_array( 'catalogue', $scope, true ) ) {
            $t = PC_Database::table( PC_Database::TABLE_CATALOGUE );
            $n = (int) $wpdb->query( "DELETE FROM {$t}" );
            $report['catalogue'] = $n;
            error_log( "[PC_Reset] user={$user_id} catalogue: deleted {$n} rows" );
        }

        // 2. Payments (depends on photos)
        if ( in_array( 'payments', $scope, true ) ) {
            $t = PC_Database::table( PC_Database::TABLE_PAYMENTS );
            $n = (int) $wpdb->query( "DELETE FROM {$t}" );
            $report['payments'] = $n;
            error_log( "[PC_Reset] user={$user_id} payments: deleted {$n} rows" );
        }

        // 3. Votes (depends on photos)
        if ( in_array( 'votes', $scope, true ) ) {
            $t = PC_Database::table( PC_Database::TABLE_VOTES );
            $n = (int) $wpdb->query( "DELETE FROM {$t}" );
            $report['votes'] = $n;
            error_log( "[PC_Reset] user={$user_id} votes: deleted {$n} rows" );
        }

        // 4. Photos (BDD + disk)
        if ( in_array( 'photos', $scope, true ) ) {
            $t = PC_Database::table( PC_Database::TABLE_PHOTOS );
            $n = (int) $wpdb->query( "DELETE FROM {$t}" );
            $report['photos_db'] = $n;

            // Disk cleanup
            $private_dir  = WP_CONTENT_DIR . '/uploads/photo-contest/private/';
            $bytes_before = is_dir( $private_dir ) ? $this->dir_size( $private_dir ) : 0;
            $this->purge_directory_contents( $private_dir );
            $report['photos_disk_bytes'] = $bytes_before;
            error_log( "[PC_Reset] user={$user_id} photos: deleted {$n} rows + " . size_format( $bytes_before ) . " on disk" );
        }

        // 5. Candidates (after their photos/profiles)
        if ( in_array( 'candidates', $scope, true ) ) {
            $ids     = get_users( [ 'role' => PC_ROLE_CANDIDAT, 'fields' => 'ID' ] );
            $deleted = 0;
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ( $ids as $uid ) {
                $uid = (int) $uid;
                // Cascade : profiles + email_tokens
                $wpdb->delete( PC_Database::table( PC_Database::TABLE_PROFILES ),     [ 'user_id' => $uid ] );
                $wpdb->delete( PC_Database::table( PC_Database::TABLE_EMAIL_TOKENS ), [ 'user_id' => $uid ] );
                if ( wp_delete_user( $uid ) ) $deleted++;
            }
            $report['candidates'] = $deleted;
            error_log( "[PC_Reset] user={$user_id} candidates: deleted {$deleted} users + cascaded profiles/tokens" );
        }

        // 6. Jurors
        if ( in_array( 'jurors', $scope, true ) ) {
            $ids     = get_users( [ 'role' => 'jurymembre', 'fields' => 'ID' ] );
            $deleted = 0;
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ( $ids as $uid ) {
                if ( wp_delete_user( (int) $uid ) ) $deleted++;
            }
            $report['jurors'] = $deleted;
            error_log( "[PC_Reset] user={$user_id} jurors: deleted {$deleted} users" );
        }

        wp_send_json_success( [
            'message' => __( 'Reset effectué.', PC_TEXT_DOMAIN ),
            'report'  => $report,
        ] );
    }

    /**
     * Supprime récursivement le contenu d'un répertoire (mais pas le répertoire lui-même).
     */
    private function purge_directory_contents( string $dir ): void {
        if ( ! is_dir( $dir ) ) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                @rmdir( $item->getRealPath() );
            } else {
                @unlink( $item->getRealPath() );
            }
        }
    }

    private function dir_size( string $dir ): int {
        if ( ! is_dir( $dir ) ) return 0;
        $total = 0;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );
        foreach ( $items as $item ) {
            if ( $item->isFile() ) $total += $item->getSize();
        }
        return $total;
    }
}
