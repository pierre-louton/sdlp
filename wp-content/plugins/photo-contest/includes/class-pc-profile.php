<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gestion des profils candidats.
 *
 * Responsabilités :
 *  - Création / mise à jour du profil étendu (table pc_profiles)
 *  - Détection de profil incomplet → redirection à la première connexion
 *  - Validation et enregistrement du règlement
 *  - Compatibilité WPML/Polylang pour les pages de redirection
 */
class PC_Profile {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Interception après login pour complétion du profil
        add_action( 'wp_login',            [ $this, 'redirect_on_login' ], 10, 2 );
        add_action( 'template_redirect',   [ $this, 'enforce_profile_completion' ] );

        // AJAX : sauvegarde du profil
        add_action( 'wp_ajax_pc_save_profile',   [ $this, 'ajax_save_profile' ] );
        add_action( 'wp_ajax_pc_accept_reglement', [ $this, 'ajax_accept_reglement' ] );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Lecture / écriture BDD
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Retourne le profil d'un utilisateur (ou null si inexistant).
     */
    public function get_profile( int $user_id ): ?array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PROFILES );
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Crée ou met à jour le profil d'un utilisateur.
     *
     * @param int   $user_id
     * @param array $data    Champs du profil (validés avant l'appel)
     */
    public function save_profile( int $user_id, array $data ): bool {
        global $wpdb;
        $table   = PC_Database::table( PC_Database::TABLE_PROFILES );
        $exists  = $this->get_profile( $user_id );

        $allowed_fields = [
            'prenom', 'nom', 'date_naissance',
            'adresse_rue', 'code_postal', 'ville', 'pays', 'telephone',
        ];

        $sanitized = [];
        foreach ( $allowed_fields as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $sanitized[ $field ] = sanitize_text_field( $data[ $field ] );
            }
        }

        if ( empty( $sanitized ) ) {
            return false;
        }

        // Marquer le profil complet si tous les champs obligatoires sont renseignés
        $sanitized['profil_complet'] = $this->is_profile_data_complete( $sanitized ) ? 1 : 0;

        if ( $exists ) {
            $wpdb->update( $table, $sanitized, [ 'user_id' => $user_id ] );
        } else {
            $sanitized['user_id'] = $user_id;
            $wpdb->insert( $table, $sanitized );
        }

        // rows_affected = 0 quand les données sont identiques — on vérifie l'absence d'erreur SQL
        return $wpdb->last_error === '';
    }

    /**
     * Enregistre l'acceptation du règlement avec horodatage.
     */
    public function accept_reglement( int $user_id ): bool {
        global $wpdb;
        $table  = PC_Database::table( PC_Database::TABLE_PROFILES );
        $exists = $this->get_profile( $user_id );

        $data = [
            'reglement_accepte' => 1,
            'reglement_date'    => current_time( 'mysql' ),
        ];

        if ( $exists ) {
            return (bool) $wpdb->update( $table, $data, [ 'user_id' => $user_id ] );
        }

        $data['user_id'] = $user_id;
        return (bool) $wpdb->insert( $table, $data );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Logique de complétion et redirection
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Vérifie si un profil est complet (tous champs obligatoires renseignés).
     */
    public function is_profile_complete( int $user_id ): bool {
        $profile = $this->get_profile( $user_id );
        if ( ! $profile ) {
            return false;
        }
        return (bool) $profile['profil_complet'] && (bool) $profile['reglement_accepte'];
    }

    /**
     * Vérifie les données d'un profil sans passer par la BDD.
     */
    private function is_profile_data_complete( array $data ): bool {
        $required = [ 'prenom', 'nom', 'date_naissance', 'adresse_rue', 'code_postal', 'ville', 'pays', 'telephone' ];
        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Après le login d'un candidat, redirige vers la page de profil
     * si son profil n'est pas complet.
     */
    public function redirect_on_login( string $user_login, WP_User $user ): void {
        if ( ! in_array( PC_ROLE_CANDIDAT, (array) $user->roles, true ) ) {
            return;
        }

        if ( $this->is_profile_complete( $user->ID ) ) {
            return;
        }

        $profil_page_id = get_option( 'pc_page_profil' );
        if ( $profil_page_id ) {
            wp_safe_redirect( get_permalink( $profil_page_id ) );
            exit;
        }
    }

    /**
     * Sur chaque page front, force le candidat à compléter son profil
     * avant d'accéder à l'espace galerie.
     */
    public function enforce_profile_completion(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user = wp_get_current_user();
        if ( ! in_array( PC_ROLE_CANDIDAT, (array) $user->roles, true ) ) {
            return;
        }

        // On ne bloque pas sur la page de profil elle-même
        $profil_page_id  = (int) get_option( 'pc_page_profil' );
        $espace_page_id  = (int) get_option( 'pc_page_espace_candidat' );
        $current_page_id = (int) get_queried_object_id();

        if ( $current_page_id === $profil_page_id ) {
            return;
        }

        // Si on est sur l'espace candidat et que le profil est incomplet → redirection
        if ( $current_page_id === $espace_page_id && ! $this->is_profile_complete( $user->ID ) ) {
            wp_safe_redirect( get_permalink( $profil_page_id ) );
            exit;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Handlers AJAX
    // ──────────────────────────────────────────────────────────────────────

    public function ajax_save_profile(): void {
        check_ajax_referer( 'pc_profile_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }

        $user_id = get_current_user_id();
        $data    = $_POST; // filtré dans save_profile()

        if ( $this->save_profile( $user_id, $data ) ) {
            wp_send_json_success( [
                'message'          => __( 'Profil enregistré.', PC_TEXT_DOMAIN ),
                'profil_complet'   => $this->is_profile_complete( $user_id ),
            ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de la sauvegarde.', PC_TEXT_DOMAIN ) ] );
        }
    }

    public function ajax_accept_reglement(): void {
        check_ajax_referer( 'pc_reglement_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }

        $user_id = get_current_user_id();

        if ( $this->accept_reglement( $user_id ) ) {
            wp_send_json_success( [
                'message' => __( 'Règlement accepté. Vous pouvez maintenant déposer vos photos.', PC_TEXT_DOMAIN ),
            ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de l\'enregistrement.', PC_TEXT_DOMAIN ) ] );
        }
    }
}
