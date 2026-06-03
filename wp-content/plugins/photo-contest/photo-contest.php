<?php
/**
 * Plugin Name:       Photo Contest Manager
 * Plugin URI:        https://example.com/photo-contest
 * Description:       Gestion complète d'un concours photo international : dépôt, jury, paiement, catalogue.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Votre Nom
 * Text Domain:       photo-contest
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// Constantes globales du plugin
define( 'PC_VERSION',     '2.0.0' );
define( 'PC_PLUGIN_FILE', __FILE__ );
define( 'PC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'PC_TEXT_DOMAIN', 'photo-contest' );

// Rôles custom — définis immédiatement, avant tout hook
define( 'PC_ROLE_CANDIDAT',  'pc_candidat' );
define( 'PC_ROLE_JURY',      'jurymembre' );
define( 'PC_ROLE_CATALOGUE', 'pc_catalogue_editor' );

// Statuts des photos (slugs internes → libellés FR)
define( 'PC_STATUTS_PHOTO', [
    'en_attente'             => 'En attente',
    'en_examen'              => 'En cours d\'examen',
    'retenue'                => 'Retenue',
    'refusee'                => 'Refusée',
    'participation_demandee' => 'Retenue',      // alias interne — même affichage que retenue
    'paiement_recu'          => 'Au catalogue', // alias interne
    'au_catalogue'           => 'Au catalogue',
] );

// Statuts visibles côté candidat (filtre sidebar)
define( 'PC_STATUTS_CANDIDAT', [
    'en_attente' => 'En attente',
    'en_examen'  => 'En cours d\'examen',
    'retenue'    => 'Retenue',
    'refusee'    => 'Refusée',
    'au_catalogue' => 'Au catalogue',
] );

/**
 * Chargement des fichiers du plugin.
 */
function pc_load_plugin(): void {
    // i18n
    load_plugin_textdomain(
        PC_TEXT_DOMAIN,
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );

    // Modules cœur
    require_once PC_PLUGIN_DIR . 'includes/class-pc-database.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-categories.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-roles.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-settings.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-security.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-registration.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-profile.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-photos.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-jury.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-payments.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-catalogue.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-fluent-crm.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-rest-api.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-shortcodes.php';

    // Administration
    if ( is_admin() ) {
        require_once PC_PLUGIN_DIR . 'admin/class-pc-admin.php';
        PC_Admin::get_instance();
        require_once PC_PLUGIN_DIR . 'admin/class-pc-categories-admin.php';
        PC_Categories_Admin::get_instance();
        require_once PC_PLUGIN_DIR . 'admin/class-pc-reset-admin.php';
        PC_Reset_Admin::get_instance();
    }

    // Initialisation des singletons
    PC_Security::get_instance();
    PC_Registration::get_instance();
    PC_Profile::get_instance();
    PC_Photos::get_instance();
    PC_Jury::get_instance();
    PC_Payments::get_instance();
    PC_Catalogue::get_instance();
    PC_Fluent_CRM::get_instance();
    PC_REST_API::get_instance();
    PC_Shortcodes::get_instance();
}
add_action( 'plugins_loaded', 'pc_load_plugin' );

/**
 * Enregistrement des rôles très tôt dans le cycle WP (priorité 1)
 * pour qu'ils soient disponibles quand WordPress charge les capabilities utilisateur.
 */
function pc_register_roles_early(): void {
    if ( ! class_exists( 'PC_Roles' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-pc-roles.php';
    }
    // Vérifier si les rôles existent déjà avant de les recréer
    if ( ! get_role( PC_ROLE_CANDIDAT ) ) {
        PC_Roles::register();
    }
}
add_action( 'init', 'pc_register_roles_early', 1 );

/**
 * Activation : création des tables et des rôles.
 */
function pc_activate(): void {
    require_once PC_PLUGIN_DIR . 'includes/class-pc-database.php';
    require_once PC_PLUGIN_DIR . 'includes/class-pc-roles.php';

    PC_Database::create_tables();
    PC_Roles::register();

    // Page "Mon espace" créée automatiquement si absente
    pc_maybe_create_pages();

    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'pc_activate' );

/**
 * Désactivation : on conserve les données, on supprime juste les rewrite rules.
 */
function pc_deactivate(): void {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pc_deactivate' );

/**
 * Désinstallation complète : suppression tables + options + rôles.
 */
function pc_uninstall(): void {
    PC_Database::drop_tables();
    PC_Roles::remove();
    PC_Security::supprimer_mu_plugin();
    delete_option( 'pc_settings' );
}
register_uninstall_hook( __FILE__, 'pc_uninstall' );

/**
 * Crée les pages WordPress nécessaires si elles n'existent pas.
 * Chaque page reçoit le shortcode correspondant.
 */
function pc_maybe_create_pages(): void {
    $pages = [
        'pc_page_espace_candidat' => [
            'title'     => __( 'Mon espace candidat', PC_TEXT_DOMAIN ),
            'shortcode' => '[photo_contest_gallery]',
        ],
        'pc_page_profil' => [
            'title'     => __( 'Mon profil', PC_TEXT_DOMAIN ),
            'shortcode' => '[photo_contest_profile]',
        ],
        'pc_page_jury' => [
            'title'     => __( 'Espace jury', PC_TEXT_DOMAIN ),
            'shortcode' => '[photo_contest_jury]',
        ],
        'pc_page_catalogue' => [
            'title'     => __( 'Catalogue', PC_TEXT_DOMAIN ),
            'shortcode' => '[photo_contest_catalogue]',
        ],
    ];

    foreach ( $pages as $option_key => $page ) {
        if ( get_option( $option_key ) ) {
            continue; // page déjà créée
        }

        $page_id = wp_insert_post( [
            'post_title'   => $page['title'],
            'post_content' => $page['shortcode'],
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( $option_key, $page_id );
        }
    }
}
