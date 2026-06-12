<?php
defined( 'ABSPATH' ) || exit;

/**
 * Page wp-admin « Concours Photo > Clôture délibération ».
 *
 * Cette page exécute la clôture du jury (modèle paiement-avant-jury) :
 * bascule en lot les photos en délibération vers « refusee », et les photos
 * « retenue » vers « au_catalogue ». Aucun paiement ni email n'est créé ici.
 *
 * Capability requise : pc_manage_payments.
 * Action irréversible (verrou anti-double-clic de 5 min côté PC_Payments).
 */
class PC_Cloture_Admin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',                 [ $this, 'register_menu' ], 50 );
        add_action( 'admin_enqueue_scripts',      [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_pc_cloture_execute', [ $this, 'ajax_execute' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'photo-contest',
            __( 'Clôture délibération', PC_TEXT_DOMAIN ),
            __( 'Clôture délibération', PC_TEXT_DOMAIN ),
            'pc_manage_payments',
            'photo-contest-cloture',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'photo-contest-cloture' ) === false ) return;
        wp_enqueue_style(  'pc-cloture-admin', PC_PLUGIN_URL . 'admin/css/pc-cloture-admin.css', [], PC_VERSION );
        wp_enqueue_script( 'pc-cloture-admin', PC_PLUGIN_URL . 'admin/js/pc-cloture-admin.js', [], PC_VERSION, true );
        wp_localize_script( 'pc-cloture-admin', 'pcClotureAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'pc_cloture_nonce' ),
            'i18n'    => [
                'confirm'        => __( 'IRRÉVERSIBLE. Confirmer la clôture ?', PC_TEXT_DOMAIN ),
                'cloturing'      => __( 'Clôture en cours…', PC_TEXT_DOMAIN ),
                'button_default' => __( 'Je confirme la clôture', PC_TEXT_DOMAIN ),
                'error_network'  => __( 'Erreur réseau', PC_TEXT_DOMAIN ),
                'error_generic'  => __( 'Erreur', PC_TEXT_DOMAIN ),
            ],
        ] );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'pc_manage_payments' ) ) {
            wp_die( esc_html__( 'Accès refusé.', PC_TEXT_DOMAIN ) );
        }

        $stats     = PC_Payments::get_cloture_stats();
        $effective = (int) PC_Settings::get( 'cloture_effectuee_at', 0 );

        ?>
        <div class="wrap pc-cloture-admin">
            <h1><?php esc_html_e( 'Clôture de la délibération du jury', PC_TEXT_DOMAIN ); ?></h1>

            <?php if ( $effective > 0 ) : ?>
                <div class="notice notice-info inline">
                    <p><?php printf(
                        /* translators: %s = date */
                        esc_html__( 'Clôture déjà effectuée le %s.', PC_TEXT_DOMAIN ),
                        esc_html( wp_date( 'd/m/Y H:i', $effective ) )
                    ); ?></p>
                </div>
            <?php endif; ?>

            <div class="pc-cloture-recap">
                <h2><?php esc_html_e( 'État actuel', PC_TEXT_DOMAIN ); ?></h2>
                <table class="widefat striped" style="max-width:600px">
                    <tr>
                        <th><?php esc_html_e( 'Photos en délibération à refuser', PC_TEXT_DOMAIN ); ?></th>
                        <td><?php echo (int) $stats['en_delibration_a_refuser']; ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Photos retenues → catalogue', PC_TEXT_DOMAIN ); ?></th>
                        <td><?php echo (int) $stats['photos_retenues']; ?></td>
                    </tr>
                </table>
            </div>

            <div class="pc-cloture-warning">
                <h3>⚠️ <?php esc_html_e( 'Action irréversible', PC_TEXT_DOMAIN ); ?></h3>
                <ul>
                    <li><?php esc_html_e( 'Toutes les photos en délibération basculeront en « refusée ».', PC_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Les photos retenues passeront « au catalogue ».', PC_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Le flag jury_actif sera mis à 0 (votes verrouillés).', PC_TEXT_DOMAIN ); ?></li>
                </ul>
            </div>

            <p style="margin-top:16px">
                <label class="pc-cloture-ack">
                    <input type="checkbox" id="pc-cloture-ack" <?php disabled( $effective > 0 ); ?>>
                    <strong><?php esc_html_e( 'Je comprends que c\'est IRRÉVERSIBLE.', PC_TEXT_DOMAIN ); ?></strong>
                </label>
            </p>

            <p>
                <button type="button" class="button button-primary button-large pc-cloture-btn" id="pc-cloture-btn" disabled>
                    <?php echo $effective > 0
                        ? esc_html__( 'Déjà effectuée', PC_TEXT_DOMAIN )
                        : esc_html__( 'Je confirme la clôture', PC_TEXT_DOMAIN ); ?>
                </button>
            </p>

            <div id="pc-cloture-msg"></div>
        </div>
        <?php
    }

    public function ajax_execute(): void {
        check_ajax_referer( 'pc_cloture_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_manage_payments' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }

        $result = PC_Payments::execute_cloture();

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [
                'message' => __( 'Clôture déjà en cours, réessayez dans 5 minutes.', PC_TEXT_DOMAIN ),
            ] );
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: 1: refusées, 2: au catalogue */
                __( 'Clôture OK. %1$d refusées, %2$d passées au catalogue.', PC_TEXT_DOMAIN ),
                (int) ( $result['refused_count']      ?? 0 ),
                (int) ( $result['au_catalogue_count'] ?? 0 )
            ),
            'report'  => $result,
        ] );
    }
}
