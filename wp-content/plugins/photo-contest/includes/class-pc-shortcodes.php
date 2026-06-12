<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes publics du plugin.
 */
class PC_Shortcodes {

    private static ?self $instance = null;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'photo_contest_gallery',      [ $this, 'render_gallery' ] );
        add_shortcode( 'photo_contest_profile',      [ $this, 'render_profile' ] );
        add_shortcode( 'photo_contest_jury',         [ $this, 'render_jury' ] );
        add_shortcode( 'photo_contest_catalogue',    [ $this, 'render_catalogue' ] );
        add_shortcode( 'photo_contest_inscription',  [ $this, 'render_inscription' ] ); // nouveau flux public

        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
        add_action( 'wp_ajax_pc_get_photos', [ $this, 'ajax_get_photos' ] );
    }

    public function maybe_enqueue_assets(): void {
        global $post;
        if ( ! $post ) return;
        if ( has_shortcode( $post->post_content, 'photo_contest_gallery' ) )
            $this->enqueue_gallery_assets();
        if ( has_shortcode( $post->post_content, 'photo_contest_jury' ) )
            $this->enqueue_jury_assets();
        if ( has_shortcode( $post->post_content, 'photo_contest_catalogue' ) )
            $this->enqueue_catalogue_assets();
    }

    private function enqueue_catalogue_assets(): void {
        wp_enqueue_style( 'pc-catalogue', PC_PLUGIN_URL . 'public/css/pc-catalogue.css', [], PC_VERSION . '.3' );
        wp_enqueue_script( 'pc-catalogue', PC_PLUGIN_URL . 'public/js/pc-catalogue.js', [], PC_VERSION . '.3', true );
        wp_localize_script( 'pc-catalogue', 'pcCatalogueConfig', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonceGet'    => wp_create_nonce( 'pc_catalogue_get_nonce' ),
            'nonceSave'   => wp_create_nonce( 'pc_catalogue_save_nonce' ),
            'nonceExport' => wp_create_nonce( 'pc_catalogue_export_nonce' ),
            'edition'     => PC_Settings::get( 'edition', '' ),
        ] );
    }

    private function enqueue_jury_assets(): void {
        wp_enqueue_style( 'pc-jury', PC_PLUGIN_URL . 'public/css/pc-jury.css', [], PC_VERSION . '.7' );
        wp_enqueue_script( 'pc-jury', PC_PLUGIN_URL . 'public/js/pc-jury.js', [], PC_VERSION . '.7', true );
        wp_localize_script( 'pc-jury', 'pcJuryConfig', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonceGet'   => wp_create_nonce( 'pc_jury_get_nonce' ),
            'nonceVote'  => wp_create_nonce( 'pc_jury_vote_nonce' ),
        ] );
    }

    private function enqueue_gallery_assets(): void {
        wp_enqueue_style( 'pc-gallery', PC_PLUGIN_URL . 'public/css/pc-gallery.css', [], PC_VERSION . '.9' );
        wp_enqueue_script( 'pc-gallery', PC_PLUGIN_URL . 'public/js/pc-gallery.js', [], PC_VERSION . '.9', true );
        wp_localize_script( 'pc-gallery', 'pcGalleryConfig', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonceGet'     => wp_create_nonce( 'pc_get_photos_nonce' ),
            'nonceUpload'  => wp_create_nonce( 'pc_upload_nonce' ),
            'nonceDelete'  => wp_create_nonce( 'pc_delete_nonce' ),
            'nonceReorder' => wp_create_nonce( 'pc_reorder_nonce' ),
            'nonceTitre'   => wp_create_nonce( 'pc_titre_nonce' ),
            'quotaMax'     => (int) PC_Settings::get( 'quota_photos', 5 ),
            'depotActif'   => PC_Settings::is_depot_actif(),
            'statuts'      => PC_STATUTS_CANDIDAT,
        ] );
    }

    public function render_gallery( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) return $this->render_non_connecte();
        $user_id = get_current_user_id();
        if ( ! PC_Roles::is_candidat( $user_id ) )
            return '<p class="pc-notice">' . esc_html__( 'Cet espace est réservé aux candidats.', 'photo-contest' ) . '</p>';

        $profile_instance = PC_Profile::get_instance();
        if ( ! $profile_instance->is_profile_complete( $user_id ) ) {
            $url = get_permalink( get_option( 'pc_page_profil' ) );
            return '<p class="pc-notice">' . sprintf(
                esc_html__( 'Veuillez d\'abord %s avant de déposer vos photos.', 'photo-contest' ),
                '<a href="' . esc_url( $url ) . '">' . esc_html__( 'compléter votre profil', 'photo-contest' ) . '</a>'
            ) . '</p>';
        }

        $profil    = $profile_instance->get_profile( $user_id );
        $quota_max = (int) PC_Settings::get( 'quota_photos', 5 );
        ob_start();
        extract( compact( 'user_id', 'profil', 'quota_max' ) );
        include PC_PLUGIN_DIR . 'templates/gallery.php';
        return ob_get_clean();
    }

    public function render_profile( array $atts = [] ): string {
        // Admins : aperçu du template sans contrainte de rôle
        if ( current_user_can( 'manage_options' ) ) {
            $user_id = get_current_user_id();
            $user    = get_userdata( $user_id );
            $profile = PC_Profile::get_instance()->get_profile( $user_id ) ?: [];
            $etape   = 'complet'; // admins voient la vue finale
            $montant = PC_Settings::montant_formate();
            $reglement_url = PC_Settings::get( 'reglement_url', '' );
            $espace_url = get_permalink( get_option( 'pc_page_espace_candidat' ) ) ?: home_url( '/' );
            $logout_url = wp_logout_url( home_url( '/' . PC_Settings::get( 'login_slug', 'connexion' ) . '/' ) );
            wp_enqueue_style(  'pc-profile', PC_PLUGIN_URL . 'public/css/pc-profile.css', [], PC_VERSION . '.4' );
            wp_enqueue_script( 'pc-profile', PC_PLUGIN_URL . 'public/js/pc-profile.js',  [], PC_VERSION . '.4', true );
            wp_localize_script( 'pc-profile', 'pcProfileConfig', [
                'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
                'nonceSave'      => wp_create_nonce( 'pc_profile_nonce' ),
                'nonceReglement' => wp_create_nonce( 'pc_reglement_nonce' ),
                'etape'          => $etape,
                'i18n'           => [
                    'reglementLabelDownloaded' => __( 'J’ai téléchargé, lu et j’accepte le règlement du concours', PC_TEXT_DOMAIN ),
                    'pdfDownloadedBtn'         => __( '✓ Téléchargé', PC_TEXT_DOMAIN ),
                    'errorPdfRequired'         => __( 'Veuillez d’abord télécharger le règlement.', PC_TEXT_DOMAIN ),
                ],
            ] );
            ob_start();
            include PC_PLUGIN_DIR . 'templates/profile.php';
            return ob_get_clean();
        }

        if ( ! is_user_logged_in() || ! PC_Roles::is_candidat() ) {
            return $this->render_non_connecte();
        }
        $user_id = get_current_user_id();
        $user    = get_userdata( $user_id );
        $profile = PC_Profile::get_instance()->get_profile( $user_id ) ?: [];
        $etape   = class_exists( 'PC_Registration' ) ? PC_Registration::get_etape( $user_id ) : 'profil_incomplet';
        $montant = PC_Settings::montant_formate();
        $reglement_url = PC_Settings::get( 'reglement_url', '' );
        $espace_url = get_permalink( get_option( 'pc_page_espace_candidat' ) ) ?: home_url( '/' );
        $logout_url = wp_logout_url( home_url( '/' . PC_Settings::get( 'login_slug', 'connexion' ) . '/' ) );

        wp_enqueue_style(  'pc-profile', PC_PLUGIN_URL . 'public/css/pc-profile.css', [], PC_VERSION . '.4' );
        wp_enqueue_script( 'pc-profile', PC_PLUGIN_URL . 'public/js/pc-profile.js',  [], PC_VERSION . '.4', true );
        wp_localize_script( 'pc-profile', 'pcProfileConfig', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonceSave'      => wp_create_nonce( 'pc_profile_nonce' ),
            'nonceReglement' => wp_create_nonce( 'pc_reglement_nonce' ),
            'etape'          => $etape,
            'i18n'           => [
                'reglementLabelDownloaded' => __( 'J’ai téléchargé, lu et j’accepte le règlement du concours', PC_TEXT_DOMAIN ),
                'pdfDownloadedBtn'         => __( '✓ Téléchargé', PC_TEXT_DOMAIN ),
                'errorPdfRequired'         => __( 'Veuillez d’abord télécharger le règlement.', PC_TEXT_DOMAIN ),
            ],
        ] );

        ob_start();
        include PC_PLUGIN_DIR . 'templates/profile.php';
        return ob_get_clean();
    }

    public function render_jury( array $atts = [] ): string {
        if ( ! is_user_logged_in() || ! PC_Roles::is_jury() )
            return '<p class="pc-notice">' . esc_html__( 'Accès réservé aux membres du jury.', 'photo-contest' ) . '</p>';

        if ( ! PC_Settings::is_jury_actif() )
            return '<p class="pc-notice">' . esc_html__( 'La phase de délibération n\'est pas encore ouverte.', 'photo-contest' ) . '</p>';

        $jury_user_id = get_current_user_id();
        $user         = wp_get_current_user();
        $nom_jury     = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
        $jury         = PC_Jury::get_instance();
        $total_photos = count( $jury->get_photos_pour_jury( $jury_user_id ) );

        ob_start();
        extract( compact( 'jury_user_id', 'nom_jury', 'total_photos' ) );
        include PC_PLUGIN_DIR . 'templates/jury.php';
        return ob_get_clean();
    }

    public function render_catalogue( array $atts = [] ): string {
        if ( ! is_user_logged_in() || ! current_user_can( 'pc_view_catalogue_panel' ) )
            return '<p class="pc-notice">' . esc_html__( 'Accès non autorisé.', 'photo-contest' ) . '</p>';

        if ( ! PC_Settings::get( 'catalogue_actif', false ) )
            return '<p class="pc-notice">' . esc_html__( 'Le catalogue n\'est pas encore disponible.', 'photo-contest' ) . '</p>';

        $titre_catalogue = PC_Settings::get( 'catalogue_titre', '' );
        $edition         = PC_Settings::get( 'edition', '' );
        $nb_total        = 0;
        $nb_inclus       = 0;
        $nb_payes        = 0;

        ob_start();
        extract( compact( 'titre_catalogue', 'edition', 'nb_total', 'nb_inclus', 'nb_payes' ) );
        include PC_PLUGIN_DIR . 'templates/catalogue.php';
        return ob_get_clean();
    }

    public function ajax_get_photos(): void {
        check_ajax_referer( 'pc_get_photos_nonce', 'nonce' );
        if ( ! is_user_logged_in() || ! PC_Roles::is_candidat() ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }

        $user_id    = get_current_user_id();
        $photos     = PC_Photos::get_instance()->get_user_photos( $user_id );
        $quota      = (int) PC_Settings::get( 'quota_photos', 5 );
        $categories = PC_Categories::get_all( true ); // only active

        // Enrichir chaque photo (URLs)
        $photos = array_map( function ( $photo ) use ( $user_id ) {
            $photo['url_thumb'] = $this->get_photo_url( (int) $photo['id'], 'thumb' );
            $photo['url_full']  = $this->get_photo_url( (int) $photo['id'], 'full' );

            if ( $photo['statut'] === 'participation_demandee' ) {
                $token = wp_create_nonce( "pc_payment_{$user_id}_{$photo['id']}" );
                $base  = get_permalink( get_option( 'pc_page_espace_candidat' ) ) ?: home_url( '/' );
                $photo['url_paiement'] = add_query_arg( [
                    'pc_action' => 'paiement',
                    'photo'     => $photo['id'],
                    'token'     => $token,
                ], $base );
            } else {
                $photo['url_paiement'] = '';
            }

            return $photo;
        }, $photos );

        // Bucket photos by category_id
        $by_category = [];
        foreach ( $photos as $p ) {
            $cid = (int) ( $p['category_id'] ?? 0 );
            $by_category[ $cid ][] = $p;
        }

        // Build active-category sections (always present, even empty)
        $sections = [];
        foreach ( $categories as $cat ) {
            $cid = (int) $cat['id'];
            $sections[] = [
                'id'     => $cid,
                'nom'    => $cat['nom'],
                'quota'  => $quota,
                'photos' => $by_category[ $cid ] ?? [],
            ];
            unset( $by_category[ $cid ] );
        }

        // Append "Non classées" section if any photo points to an inactive/deleted category
        $orphelines = [];
        foreach ( $by_category as $list ) {
            $orphelines = array_merge( $orphelines, $list );
        }
        if ( ! empty( $orphelines ) ) {
            $sections[] = [
                'id'     => 0,
                'nom'    => __( 'Non classées', PC_TEXT_DOMAIN ),
                'quota'  => 0,
                'photos' => $orphelines,
            ];
        }

        // Stats statuts (unchanged behavior)
        $stats = [];
        foreach ( $photos as $p ) {
            $stats[ $p['statut'] ] = ( $stats[ $p['statut'] ] ?? 0 ) + 1;
        }

        wp_send_json_success( [
            'categories'    => $sections,
            'quota_max'     => $quota,
            'quota_utilise' => count( $photos ),
            'stats_statuts' => $stats,
        ] );
    }

    public function get_photo_url( int $photo_id, string $taille = 'full' ): string {
        $token = wp_create_nonce( "pc_photo_{$photo_id}_" . get_current_user_id() );
        return add_query_arg( [ 'pc_photo' => $photo_id, 'taille' => $taille, 'token' => $token ], home_url( '/' ) );
    }

    /**
     * Shortcode [photo_contest_inscription]
     *
     * Page publique d'inscription au concours :
     *  - Étape 1 : Formulaire email + mot de passe + acceptation règlement
     *  - Étape 2 : Message de confirmation (email envoyé)
     *
     * Si l'utilisateur est déjà connecté en tant que candidat,
     * redirige vers son profil. Si admin, affiche un aperçu.
     */
    public function render_inscription( array $atts = [] ): string {

        // Candidat déjà connecté → renvoyer vers son profil
        if ( is_user_logged_in() && PC_Roles::is_candidat() ) {
            $profil_url = get_permalink( get_option( 'pc_page_profil' ) ) ?: home_url( '/' );
            wp_safe_redirect( $profil_url );
            exit;
        }

        // Admin → message d'aperçu
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            return '<div style="padding:20px;background:#1c1d1f;color:#e8e6e1;border-radius:8px;font-family:DM Sans,sans-serif;">
                <strong>Aperçu admin</strong> — Ce formulaire est visible par les visiteurs non connectés.<br>
                Shortcode : <code>[photo_contest_inscription]</code>
            </div>';
        }

        wp_enqueue_style(  'pc-login', PC_PLUGIN_URL . 'public/css/pc-login.css', [], PC_VERSION . '.3' );

        $logo_url     = PC_Settings::get( 'logo_url', '' );
        $nom_concours = PC_Settings::get( 'nom_concours', 'SDLP' );
        $reglement_url = PC_Settings::get( 'reglement_url', '' );
        $reglement_texte = PC_Settings::get( 'reglement_texte',
            'En participant à ce concours, vous acceptez que vos œuvres puissent être exposées et publiées dans le catalogue de l\'exposition. Vous certifiez être l\'auteur des photographies soumises et détenir tous les droits nécessaires.'
        );
        $connexion_url = home_url( '/' . PC_Settings::get( 'login_slug', 'connexion' ) . '/' );
        $nonce_inscription = wp_create_nonce( 'pc_register_nonce' );

        ob_start();
        include PC_PLUGIN_DIR . 'templates/inscription.php';
        return ob_get_clean();
    }

    private function render_non_connecte(): string {
        $url = wp_login_url( get_permalink() );
        return '<p class="pc-notice">' . sprintf(
            esc_html__( 'Vous devez être %s pour accéder à cet espace.', 'photo-contest' ),
            '<a href="' . esc_url( $url ) . '">' . esc_html__( 'connecté', 'photo-contest' ) . '</a>'
        ) . '</p>';
    }
}
