<?php
defined( 'ABSPATH' ) || exit;

/**
 * Page wp-admin "Concours Photo > Catégories"
 *
 * CRUD pour les catégories du concours.
 */
class PC_Categories_Admin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',                  [ $this, 'register_menu' ], 30 );
        add_action( 'admin_enqueue_scripts',       [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_pc_add_category',     [ $this, 'ajax_add' ] );
        add_action( 'wp_ajax_pc_update_category',  [ $this, 'ajax_update' ] );
        add_action( 'wp_ajax_pc_toggle_category',  [ $this, 'ajax_toggle' ] );
        add_action( 'wp_ajax_pc_delete_category',  [ $this, 'ajax_delete' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'photo-contest',
            __( 'Catégories', PC_TEXT_DOMAIN ),
            __( 'Catégories', PC_TEXT_DOMAIN ),
            'pc_manage_contest_settings',
            'photo-contest-categories',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'photo-contest-categories' ) === false ) {
            return;
        }
        wp_enqueue_style(  'pc-categories-admin', PC_PLUGIN_URL . 'admin/css/pc-categories-admin.css', [], PC_VERSION );
        wp_enqueue_script( 'pc-categories-admin', PC_PLUGIN_URL . 'admin/js/pc-categories-admin.js', [], PC_VERSION, true );
        wp_localize_script( 'pc-categories-admin', 'pcCategoriesAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'pc_categories_nonce' ),
            'i18n'    => [
                'confirm_delete' => __( 'Supprimer cette catégorie ?', PC_TEXT_DOMAIN ),
                'name_required'  => __( 'Le nom est obligatoire.', PC_TEXT_DOMAIN ),
                'added'          => __( 'Ajoutée', PC_TEXT_DOMAIN ),
                'updated'        => __( 'Mis à jour', PC_TEXT_DOMAIN ),
                'generic_error'  => __( 'Erreur', PC_TEXT_DOMAIN ),
            ],
        ] );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) {
            wp_die( esc_html__( 'Accès refusé.', PC_TEXT_DOMAIN ) );
        }
        $categories = PC_Categories::get_all( false );
        ?>
        <div class="wrap pc-categories-admin">
            <h1><?php esc_html_e( 'Catégories du concours', PC_TEXT_DOMAIN ); ?></h1>
            <p><?php esc_html_e( 'Une photo appartient à exactement une catégorie. Le quota max de photos s\'applique par catégorie.', PC_TEXT_DOMAIN ); ?></p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Nom', PC_TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'Actif', PC_TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'Photos', PC_TEXT_DOMAIN ); ?></th>
                        <th><?php esc_html_e( 'Actions', PC_TEXT_DOMAIN ); ?></th>
                    </tr>
                </thead>
                <tbody id="pc-cat-tbody">
                <?php foreach ( $categories as $cat ) : ?>
                    <?php $nb = PC_Categories::count_photos( (int) $cat['id'] ); ?>
                    <tr data-cat-id="<?php echo (int) $cat['id']; ?>">
                        <td>
                            <input type="text" class="pc-cat-nom" maxlength="150"
                                   value="<?php echo esc_attr( $cat['nom'] ); ?>"
                                   data-original="<?php echo esc_attr( $cat['nom'] ); ?>">
                        </td>
                        <td>
                            <button type="button" class="button pc-cat-toggle">
                                <?php echo $cat['actif']
                                    ? '✓ ' . esc_html__( 'Actif', PC_TEXT_DOMAIN )
                                    : esc_html__( 'Inactif', PC_TEXT_DOMAIN ); ?>
                            </button>
                        </td>
                        <td><?php echo (int) $nb; ?></td>
                        <td>
                            <button type="button" class="button pc-cat-save"><?php esc_html_e( 'Enregistrer', PC_TEXT_DOMAIN ); ?></button>
                            <?php if ( $nb === 0 ) : ?>
                                <button type="button" class="button button-link-delete pc-cat-delete"><?php esc_html_e( 'Supprimer', PC_TEXT_DOMAIN ); ?></button>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e( '(suppression bloquée tant qu\'il y a des photos)', PC_TEXT_DOMAIN ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:24px"><?php esc_html_e( 'Ajouter une catégorie', PC_TEXT_DOMAIN ); ?></h2>
            <p>
                <input type="text" id="pc-cat-new-nom" maxlength="150"
                       placeholder="<?php esc_attr_e( 'Nom de la nouvelle catégorie', PC_TEXT_DOMAIN ); ?>"
                       style="width:300px">
                <button type="button" class="button button-primary" id="pc-cat-add"><?php esc_html_e( 'Ajouter', PC_TEXT_DOMAIN ); ?></button>
            </p>

            <div id="pc-cat-msg" style="margin-top:12px"></div>
        </div>
        <?php
    }

    public function ajax_add(): void {
        check_ajax_referer( 'pc_categories_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }
        $nom = isset( $_POST['nom'] ) ? sanitize_text_field( wp_unslash( $_POST['nom'] ) ) : '';
        if ( $nom === '' ) {
            wp_send_json_error( [ 'message' => __( 'Le nom est obligatoire.', PC_TEXT_DOMAIN ) ] );
        }
        $id = PC_Categories::create( $nom );
        if ( $id === 0 ) {
            wp_send_json_error( [ 'message' => __( 'Création impossible.', PC_TEXT_DOMAIN ) ] );
        }
        wp_send_json_success( [ 'id' => $id, 'nom' => $nom, 'actif' => 1, 'nb_photos' => 0 ] );
    }

    public function ajax_update(): void {
        check_ajax_referer( 'pc_categories_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }
        $id  = isset( $_POST['id'] )  ? absint( $_POST['id'] ) : 0;
        $nom = isset( $_POST['nom'] ) ? sanitize_text_field( wp_unslash( $_POST['nom'] ) ) : '';
        if ( ! PC_Categories::update( $id, $nom ) ) {
            wp_send_json_error( [ 'message' => __( 'Mise à jour impossible.', PC_TEXT_DOMAIN ) ] );
        }
        wp_send_json_success( [ 'id' => $id, 'nom' => $nom ] );
    }

    public function ajax_toggle(): void {
        check_ajax_referer( 'pc_categories_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! PC_Categories::toggle( $id ) ) {
            wp_send_json_error( [ 'message' => __( 'Toggle impossible.', PC_TEXT_DOMAIN ) ] );
        }
        $cat = PC_Categories::get( $id );
        if ( ! $cat ) {
            wp_send_json_error( [ 'message' => __( 'Catégorie introuvable.', PC_TEXT_DOMAIN ) ] );
        }
        wp_send_json_success( [ 'id' => $id, 'actif' => (int) $cat['actif'] ] );
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'pc_categories_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', PC_TEXT_DOMAIN ) ] );
        }
        $id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $res = PC_Categories::delete( $id );
        // CRITICAL: WP_Error is truthy — check is_wp_error FIRST
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( [ 'message' => $res->get_error_message() ] );
        }
        if ( $res !== true ) {
            wp_send_json_error( [ 'message' => __( 'Suppression impossible.', PC_TEXT_DOMAIN ) ] );
        }
        wp_send_json_success( [ 'id' => $id ] );
    }
}
