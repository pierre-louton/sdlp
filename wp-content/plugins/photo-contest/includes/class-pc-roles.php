<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gestion des rôles et capabilities WordPress du plugin.
 *
 * Rôles créés :
 *  - pc_candidat        : dépose et gère ses propres photos uniquement
 *  - pc_jury            : examine et vote sur les photos (anonymisées)
 *  - pc_catalogue_editor: compose le catalogue, exporte les données
 *
 * Le rôle administrator conserve toutes les capabilities via
 * l'option pc_admin_caps.
 */
class PC_Roles {

    /**
     * Capabilities du candidat.
     */
    private static array $caps_candidat = [
        'read'                  => true,
        'pc_view_own_profile'   => true,
        'pc_edit_own_profile'   => true,
        'pc_upload_photo'       => true,
        'pc_delete_own_photo'   => true,
        'pc_view_own_photos'    => true,
        'pc_view_own_statuts'   => true,
        'pc_pay_participation'  => true,
    ];

    /**
     * Capabilities du jury.
     */
    private static array $caps_jury = [
        'read'                  => true,
        'pc_view_all_photos'    => true,
        'pc_vote_photo'         => true,
        'pc_view_jury_panel'    => true,
        'pc_add_jury_comment'   => true,
    ];

    /**
     * Capabilities de l'éditeur catalogue.
     */
    private static array $caps_catalogue = [
        'read'                      => true,
        'pc_view_catalogue_panel'   => true,
        'pc_edit_catalogue_item'    => true,
        'pc_reorder_catalogue'      => true,
        'pc_export_catalogue_pdf'   => true,
        'pc_export_catalogue_csv'   => true,
    ];

    /**
     * Toutes les capabilities du plugin (pour l'administrateur).
     */
    public static function all_caps(): array {
        return array_merge(
            self::$caps_candidat,
            self::$caps_jury,
            self::$caps_catalogue,
            [
                'pc_manage_contest_settings' => true,
                'pc_view_all_candidates'     => true,
                'pc_export_all_data'         => true,
                'pc_send_mass_emails'        => true,
                'pc_manage_payments'         => true,
            ]
        );
    }

    /**
     * Enregistre les trois rôles et dote l'administrateur de toutes les caps.
     * Appelé à l'activation et sécurisé contre les doublons par add_role().
     */
    public static function register(): void {
        add_role(
            PC_ROLE_CANDIDAT,
            __( 'Candidat', PC_TEXT_DOMAIN ),
            self::$caps_candidat
        );

        add_role(
            PC_ROLE_JURY,
            __( 'Membre du jury', PC_TEXT_DOMAIN ),
            self::$caps_jury
        );

        add_role(
            PC_ROLE_CATALOGUE,
            __( 'Éditeur catalogue', PC_TEXT_DOMAIN ),
            self::$caps_catalogue
        );

        // L'administrateur hérite de toutes les capabilities du plugin
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::all_caps() as $cap => $grant ) {
                $admin->add_cap( $cap, $grant );
            }
        }
    }

    /**
     * Supprime les rôles et retire les caps de l'administrateur.
     * Appelé uniquement à la désinstallation complète.
     */
    public static function remove(): void {
        remove_role( PC_ROLE_CANDIDAT );
        remove_role( PC_ROLE_JURY );
        remove_role( PC_ROLE_CATALOGUE );

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( array_keys( self::all_caps() ) as $cap ) {
                $admin->remove_cap( $cap );
            }
        }
    }

    /**
     * Vérifie si l'utilisateur courant a une capability du plugin.
     * Raccourci pratique pour les autres classes.
     */
    public static function current_user_can( string $cap ): bool {
        return current_user_can( $cap );
    }

    /**
     * Vérifie si l'utilisateur courant est un candidat.
     */
    public static function is_candidat( int $user_id = 0 ): bool {
        $user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
        return $user && in_array( PC_ROLE_CANDIDAT, (array) $user->roles, true );
    }

    /**
     * Vérifie si l'utilisateur courant est un membre du jury.
     */
    public static function is_jury( int $user_id = 0 ): bool {
        $user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
        return $user && in_array( PC_ROLE_JURY, (array) $user->roles, true );
    }

    /**
     * Assigne le rôle candidat à un utilisateur WordPress existant.
     * Remplace tous les rôles existants.
     */
    public static function assign_candidat( int $user_id ): void {
        $user = new WP_User( $user_id );
        $user->set_role( PC_ROLE_CANDIDAT );
    }

    /**
     * Assigne le rôle jury à un utilisateur WordPress existant.
     */
    public static function assign_jury( int $user_id ): void {
        $user = new WP_User( $user_id );
        $user->set_role( PC_ROLE_JURY );
    }
}
