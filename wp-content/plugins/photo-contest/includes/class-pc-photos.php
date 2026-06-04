<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gestion du dépôt de photos.
 *
 * Responsabilités :
 *  - Upload sécurisé hors du media library WordPress
 *  - Contrôle du ratio (3/2 ou 2/3), du poids et du format JPG
 *  - Respect du quota configurable par candidat
 *  - CRUD photos : ajout, suppression, liste, statut
 *  - Hook sur changement de statut (déclenche notifications Fluent CRM)
 */
class PC_Photos {

    private static ?self $instance = null;

    /** Répertoire privé pour les fichiers uploadés (hors uploads WordPress). */
    private string $upload_dir;

    /** Tolérance de ratio en % (5% de marge pour couvrir les capteurs photo). */
    private const RATIO_TOLERANCE = 0.05;

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $base_upload    = wp_upload_dir();
        $this->upload_dir = trailingslashit( $base_upload['basedir'] ) . 'photo-contest-private/';

        add_action( 'init',                    [ $this, 'protect_upload_directory' ] );
        add_action( 'template_redirect',       [ $this, 'register_file_endpoint' ] );
        add_action( 'wp_ajax_pc_upload_photo', [ $this, 'ajax_upload' ] );
        add_action( 'wp_ajax_pc_delete_photo', [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_pc_reorder_photos',  [ $this, 'ajax_reorder' ] );
        add_action( 'wp_ajax_pc_update_titre',    [ $this, 'ajax_update_titre' ] );

        // Hook interne : statut changé → notification
        add_action( 'pc_photo_statut_changed', [ $this, 'on_statut_changed' ], 10, 3 );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Protection du répertoire privé
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Crée le répertoire privé et y place un .htaccess bloquant l'accès direct.
     */
    public function protect_upload_directory(): void {
        if ( ! file_exists( $this->upload_dir ) ) {
            wp_mkdir_p( $this->upload_dir );
        }

        $htaccess = $this->upload_dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" );
        }

        $index = $this->upload_dir . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // Silence is golden\n" );
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Service des fichiers privés
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Intercepte ?pc_photo=X&taille=thumb&token=Y et sert le fichier physique.
     * Vérifie le nonce + les droits avant tout readfile().
     */
    public function register_file_endpoint(): void {
        if ( empty( $_GET['pc_photo'] ) ) return;

        $photo_id  = (int) $_GET['pc_photo'];
        $taille    = sanitize_key( $_GET['taille'] ?? 'full' );
        $token     = sanitize_text_field( $_GET['token'] ?? '' );
        $jury_mode = ! empty( $_GET['jury'] );
        $user_id   = get_current_user_id();

        if ( ! is_user_logged_in() ) {
            http_response_code( 401 ); exit( 'Non autorisé.' );
        }

        $nonce_action = $jury_mode
            ? "pc_jury_photo_{$photo_id}_{$user_id}"
            : "pc_photo_{$photo_id}_{$user_id}";

        if ( ! wp_verify_nonce( $token, $nonce_action ) ) {
            http_response_code( 403 ); exit( 'Token invalide ou expiré.' );
        }

        if ( $jury_mode || current_user_can( 'pc_view_all_photos' ) ) {
            $photo = $this->get_photo( $photo_id );
        } else {
            $photo = $this->get_photo( $photo_id, $user_id );
        }

        if ( ! $photo || empty( $photo['chemin_fichier'] ) ) {
            http_response_code( 404 ); exit( 'Photo introuvable.' );
        }

        $chemin = $taille === 'thumb'
            ? $this->get_or_create_thumb( $photo )
            : $photo['chemin_fichier'];

        if ( ! file_exists( $chemin ) ) {
            http_response_code( 404 ); exit( 'Fichier introuvable.' );
        }

        // Vider tout buffer de sortie WordPress avant d'envoyer le binaire
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        header( 'Content-Type: ' . ( mime_content_type( $chemin ) ?: 'image/jpeg' ) );
        header( 'Content-Length: ' . filesize( $chemin ) );
        header( 'Cache-Control: private, max-age=3600' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $chemin );
        exit;
    }

    /**
     * Génère une vignette 400px via GD et la met en cache dans le même dossier.
     */
    private function get_or_create_thumb( array $photo ): string {
        $original = $photo['chemin_fichier'];
        $dir      = dirname( $original );
        $base     = pathinfo( $photo['nom_fichier'], PATHINFO_FILENAME );
        $thumb    = $dir . '/' . $base . '_thumb.jpg';

        if ( file_exists( $thumb ) ) return $thumb;
        if ( ! extension_loaded( 'gd' ) ) return $original;

        $img = @imagecreatefromjpeg( $original );
        if ( ! $img ) return $original;

        $wo = imagesx( $img );
        $ho = imagesy( $img );
        $max = 400;

        if ( $wo >= $ho ) {
            $wt = $max; $ht = (int) round( $max * $ho / $wo );
        } else {
            $ht = $max; $wt = (int) round( $max * $wo / $ho );
        }

        $dst = imagecreatetruecolor( $wt, $ht );
        imagecopyresampled( $dst, $img, 0, 0, 0, 0, $wt, $ht, $wo, $ho );
        imagejpeg( $dst, $thumb, 85 );
        imagedestroy( $img );
        imagedestroy( $dst );

        return $thumb;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Upload
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Dépôt d'une photo par un candidat dans une catégorie donnée.
     *
     * @param int   $user_id
     * @param array $file        Entrée de $_FILES
     * @param int   $category_id ID de la catégorie cible (obligatoire, > 0)
     * @return array{success: bool, message: string, photo_id?: int, category_id?: int}
     */
    public function upload_photo( int $user_id, array $file, int $category_id ): array {
        // 1. Dépôt ouvert ?
        if ( ! PC_Settings::is_depot_actif() ) {
            return [ 'success' => false, 'message' => __( 'Le dépôt de photos est actuellement fermé.', PC_TEXT_DOMAIN ) ];
        }

        // 2. Catégorie valide et active ?
        if ( $category_id <= 0 ) {
            return [ 'success' => false, 'message' => __( 'Catégorie manquante.', PC_TEXT_DOMAIN ) ];
        }
        $category = PC_Categories::get( $category_id );
        if ( ! $category ) {
            return [ 'success' => false, 'message' => __( 'Catégorie introuvable.', PC_TEXT_DOMAIN ) ];
        }
        if ( (int) $category['actif'] !== 1 ) {
            return [ 'success' => false, 'message' => __( 'Cette catégorie n\'est plus active.', PC_TEXT_DOMAIN ) ];
        }

        // 3. Quota par catégorie atteint ?
        global $wpdb;
        $table_photos = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $quota        = (int) PC_Settings::get( 'quota_photos', 5 );
        $nb_in_cat    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_photos} WHERE user_id = %d AND category_id = %d",
            $user_id, $category_id
        ) );
        if ( $nb_in_cat >= $quota ) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %1$d quota, %2$s nom catégorie */
                    __( 'Quota atteint (%1$d) pour la catégorie « %2$s ».', PC_TEXT_DOMAIN ),
                    $quota, $category['nom']
                ),
            ];
        }

        // 4. Type MIME : JPG uniquement
        $finfo    = finfo_open( FILEINFO_MIME_TYPE );
        $mime     = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( $mime !== 'image/jpeg' ) {
            return [ 'success' => false, 'message' => __( 'Seules les images JPG sont acceptées.', PC_TEXT_DOMAIN ) ];
        }

        // 4. Poids
        $poids_max = (int) PC_Settings::get( 'poids_max_mo', 40 ) * 1024 * 1024;
        if ( $file['size'] > $poids_max ) {
            return [
                'success' => false,
                'message' => sprintf(
                    __( 'Le fichier dépasse la taille maximale de %d Mo.', PC_TEXT_DOMAIN ),
                    PC_Settings::get( 'poids_max_mo', 40 )
                ),
            ];
        }

        // 5. Ratio
        $image_info = getimagesize( $file['tmp_name'] );
        if ( ! $image_info ) {
            return [ 'success' => false, 'message' => __( 'Impossible de lire les dimensions de l\'image.', PC_TEXT_DOMAIN ) ];
        }

        [$largeur, $hauteur] = $image_info;
        $ratio_type = $this->detect_ratio( $largeur, $hauteur );

        if ( $ratio_type === 'invalide' ) {
            return [
                'success' => false,
                'message' => __( 'Le ratio de l\'image doit être 3:2 (paysage) ou 2:3 (portrait).', PC_TEXT_DOMAIN ),
            ];
        }

        // 6. Déduplication par hash SHA-1 (avant tout déplacement du fichier)
        $hash = sha1_file( $file['tmp_name'] );
        if ( ! $hash ) {
            return [ 'success' => false, 'message' => __( 'Impossible de calculer le hash du fichier.', PC_TEXT_DOMAIN ) ];
        }

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table_photos} WHERE user_id = %d AND hash_sha1 = %s",
            $user_id, $hash
        ) );
        if ( $existing > 0 ) {
            return [ 'success' => false, 'message' => __( 'Cette photo a déjà été déposée.', PC_TEXT_DOMAIN ) ];
        }

        // 7. Déplacement du fichier dans le répertoire privé (après vérification hash)
        $user_dir = $this->upload_dir . $user_id . '/';
        wp_mkdir_p( $user_dir );

        $nom_fichier  = wp_unique_filename( $user_dir, sanitize_file_name( $file['name'] ) );
        $chemin_dest  = $user_dir . $nom_fichier;

        if ( ! move_uploaded_file( $file['tmp_name'], $chemin_dest ) ) {
            return [ 'success' => false, 'message' => __( 'Erreur lors du déplacement du fichier.', PC_TEXT_DOMAIN ) ];
        }

        // 8. Extraction du titre depuis les métadonnées EXIF/IPTC
        $titre = $this->extract_titre_from_exif( $chemin_dest, $nom_fichier );

        // 9. Insertion en BDD
        $wpdb->insert( $table_photos, [
            'user_id'         => $user_id,
            'category_id'     => $category_id,
            'titre'           => $titre,
            'nom_fichier'     => $nom_fichier,
            'chemin_fichier'  => $chemin_dest,
            'hash_sha1'       => $hash,
            'taille_octets'   => $file['size'],
            'largeur_px'      => $largeur,
            'hauteur_px'      => $hauteur,
            'ratio_type'      => $ratio_type,
            'statut'          => 'en_attente',
            'ordre_affichage' => $nb_in_cat + 1,
        ] );

        $photo_id = (int) $wpdb->insert_id;

        return [
            'success'     => true,
            'message'     => __( 'Photo déposée avec succès.', PC_TEXT_DOMAIN ),
            'photo_id'    => $photo_id,
            'category_id' => $category_id,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Lecture / suppression
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Retourne la liste des photos d'un candidat.
     */
    public function get_user_photos( int $user_id ): array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PHOTOS );
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY ordre_affichage ASC",
                $user_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Retourne une photo par son ID (avec vérification de propriétaire).
     */
    public function get_photo( int $photo_id, int $user_id = 0 ): ?array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PHOTOS );

        if ( $user_id ) {
            $row = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $photo_id, $user_id ),
                ARRAY_A
            );
        } else {
            $row = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $photo_id ),
                ARRAY_A
            );
        }

        return $row ?: null;
    }

    /**
     * Supprime une photo (fichier + ligne BDD).
     * Seules les photos "en_attente" peuvent être supprimées par le candidat.
     */
    public function delete_photo( int $photo_id, int $user_id ): array {
        $photo = $this->get_photo( $photo_id, $user_id );

        if ( ! $photo ) {
            return [ 'success' => false, 'message' => __( 'Photo introuvable.', PC_TEXT_DOMAIN ) ];
        }

        if ( ! in_array( $photo['statut'], [ 'en_attente', 'refusee' ], true ) ) {
            return [
                'success' => false,
                'message' => __( 'Cette photo ne peut plus être supprimée (elle est en cours d\'examen ou retenue).', PC_TEXT_DOMAIN ),
            ];
        }

        // Suppression du fichier physique
        if ( file_exists( $photo['chemin_fichier'] ) ) {
            unlink( $photo['chemin_fichier'] );
        }

        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $wpdb->delete( $table, [ 'id' => $photo_id, 'user_id' => $user_id ] );

        return [ 'success' => true, 'message' => __( 'Photo supprimée.', PC_TEXT_DOMAIN ) ];
    }

    /**
     * Met à jour le statut d'une photo et déclenche les actions associées.
     */
    public function update_statut( int $photo_id, string $nouveau_statut ): bool {
        if ( ! array_key_exists( $nouveau_statut, PC_STATUTS_PHOTO ) ) {
            return false;
        }

        global $wpdb;
        $table        = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $photo        = $this->get_photo( $photo_id );
        $ancien_statut = $photo ? $photo['statut'] : '';

        $updated = $wpdb->update( $table, [ 'statut' => $nouveau_statut ], [ 'id' => $photo_id ] );

        if ( $updated && $ancien_statut !== $nouveau_statut ) {
            do_action( 'pc_photo_statut_changed', $photo_id, $nouveau_statut, $ancien_statut );
        }

        return (bool) $updated;
    }

    /**
     * Compte le nombre de photos d'un candidat.
     */
    public function count_user_photos( int $user_id ): int {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PHOTOS );
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id )
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Extraction titre EXIF/IPTC
    // ──────────────────────────────────────────────────────────────────────

    private function extract_titre_from_exif( string $chemin, string $nom_fichier ): string {
        // 1. IPTC — priorité maximale (Lightroom, Capture One, etc.)
        $size = @getimagesize( $chemin, $info );
        if ( isset( $info['APP13'] ) ) {
            $iptc = iptcparse( $info['APP13'] );
            foreach ( [ '2#005', '2#120' ] as $tag ) {
                if ( ! empty( $iptc[ $tag ][0] ) ) {
                    $titre = trim( $iptc[ $tag ][0] );
                    if ( $titre !== '' ) return sanitize_text_field( $titre );
                }
            }
        }

        // 2. EXIF ImageDescription / XPTitle
        if ( function_exists( 'exif_read_data' ) ) {
            $exif = @exif_read_data( $chemin, 'IFD0', false );
            if ( ! empty( $exif['ImageDescription'] ) ) {
                $titre = trim( $exif['ImageDescription'] );
                if ( $titre !== '' && ! preg_match( '/^[A-Z ]+DIGITAL CAMERA$/', $titre ) ) {
                    return sanitize_text_field( $titre );
                }
            }
            if ( ! empty( $exif['XPTitle'] ) ) {
                $titre = trim( mb_convert_encoding( $exif['XPTitle'], 'UTF-8', 'UTF-16LE' ) );
                if ( $titre !== '' ) return sanitize_text_field( $titre );
            }
        }

        // 3. Fallback : nom de fichier nettoyé
        $fallback = pathinfo( $nom_fichier, PATHINFO_FILENAME );
        $fallback = preg_replace( '/[-_]+/', ' ', $fallback );
        return ucfirst( strtolower( trim( $fallback ) ) );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Mise à jour titre (AJAX)
    // ──────────────────────────────────────────────────────────────────────

    public function ajax_update_titre(): void {
        check_ajax_referer( 'pc_titre_nonce', 'nonce' );

        if ( ! current_user_can( 'pc_view_own_photos' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }

        $photo_id = (int) ( $_POST['photo_id'] ?? 0 );
        $titre    = sanitize_text_field( $_POST['titre'] ?? '' );
        $user_id  = get_current_user_id();

        if ( ! $photo_id ) {
            wp_send_json_error( [ 'message' => __( 'Photo invalide.', PC_TEXT_DOMAIN ) ] );
        }

        // Vérifier que la photo appartient au candidat
        $photo = $this->get_photo( $photo_id, $user_id );
        if ( ! $photo ) {
            wp_send_json_error( [ 'message' => __( 'Photo introuvable.', PC_TEXT_DOMAIN ) ] );
        }

        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $wpdb->update( $table, [ 'titre' => $titre ], [ 'id' => $photo_id, 'user_id' => $user_id ] );

        wp_send_json_success( [ 'titre' => $titre ] );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Détection du ratio
    // ──────────────────────────────────────────────────────────────────────

    private function detect_ratio( int $w, int $h ): string {
        if ( $h === 0 ) {
            return 'invalide';
        }

        $ratio    = $w / $h;
        $cible_32 = 3 / 2; // 1.5
        $cible_23 = 2 / 3; // ~0.666

        if ( abs( $ratio - $cible_32 ) / $cible_32 <= self::RATIO_TOLERANCE ) {
            return '3_2';
        }

        if ( abs( $ratio - $cible_23 ) / $cible_23 <= self::RATIO_TOLERANCE ) {
            return '2_3';
        }

        return 'invalide';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Hook : changement de statut
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Déclenche les actions métier après un changement de statut.
     * Fluent CRM sera notifié ici.
     */
    public function on_statut_changed( int $photo_id, string $nouveau, string $ancien ): void {
        $photo = $this->get_photo( $photo_id );
        if ( ! $photo ) {
            return;
        }

        // Notification Fluent CRM selon le nouveau statut
        do_action( 'pc_fluent_notify_statut', $photo['user_id'], $photo_id, $nouveau );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Handlers AJAX
    // ──────────────────────────────────────────────────────────────────────

    public function ajax_upload(): void {
        check_ajax_referer( 'pc_upload_nonce', 'nonce' );

        if ( ! current_user_can( 'pc_upload_photo' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }

        if ( empty( $_FILES['photo'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Aucun fichier reçu.', PC_TEXT_DOMAIN ) ] );
        }

        $category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;

        $result = $this->upload_photo( get_current_user_id(), $_FILES['photo'], $category_id );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'pc_delete_nonce', 'nonce' );

        if ( ! current_user_can( 'pc_delete_own_photo' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }

        $photo_id = (int) ( $_POST['photo_id'] ?? 0 );
        $result   = $this->delete_photo( $photo_id, get_current_user_id() );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_reorder(): void {
        check_ajax_referer( 'pc_reorder_nonce', 'nonce' );

        if ( ! current_user_can( 'pc_view_own_photos' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', PC_TEXT_DOMAIN ) ] );
        }

        $ids     = array_map( 'intval', $_POST['ids'] ?? [] );
        $user_id = get_current_user_id();

        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_PHOTOS );

        foreach ( $ids as $ordre => $photo_id ) {
            $wpdb->update(
                $table,
                [ 'ordre_affichage' => $ordre + 1 ],
                [ 'id' => $photo_id, 'user_id' => $user_id ]
            );
        }

        wp_send_json_success( [ 'message' => __( 'Ordre enregistré.', PC_TEXT_DOMAIN ) ] );
    }
}
