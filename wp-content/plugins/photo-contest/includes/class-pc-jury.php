<?php
defined( 'ABSPATH' ) || exit;

/**
 * Module Jury — Délibération des photos.
 * Responsabilités : photos anonymisées, votes, délibération collective automatique.
 */
class PC_Jury {

    private static ?self $instance = null;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_pc_jury_get_photos', [ $this, 'ajax_get_photos' ] );
        add_action( 'wp_ajax_pc_jury_voter',      [ $this, 'ajax_voter' ] );
        add_action( 'wp_ajax_pc_jury_get_stats',  [ $this, 'ajax_get_stats' ] );
    }

    // ── Lecture des photos à délibérer ────────────────────────────────

    public function get_photos_pour_jury( int $jury_user_id, string $filtre = 'toutes', int $category_id = 0 ): array {
        global $wpdb;
        $tp = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $tv = PC_Database::table( PC_Database::TABLE_VOTES );
        $tc = PC_Database::table( PC_Database::TABLE_CATEGORIES );

        $tpay      = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $cat_where = $category_id > 0 ? 'AND p.category_id = %d' : '';
        $sql = "SELECT p.id, p.largeur_px, p.hauteur_px, p.ratio_type, p.taille_octets, p.statut, p.ordre_affichage,
                       p.category_id, c.nom AS nom_categorie,
                       v.decision AS mon_vote, v.commentaire AS mon_commentaire
                FROM {$tp} p
                LEFT JOIN {$tv} v ON v.photo_id = p.id AND v.jury_user_id = %d
                LEFT JOIN {$tc} c ON c.id = p.category_id
                WHERE p.statut IN ('en_attente','en_examen')
                  AND EXISTS ( SELECT 1 FROM {$tpay} pay
                               WHERE pay.photo_id = p.id AND pay.statut_paiement = 'paiement_recu' )
                  {$cat_where}
                ORDER BY p.ordre_affichage ASC";

        $args = $category_id > 0 ? [ $jury_user_id, $category_id ] : [ $jury_user_id ];
        $photos = $wpdb->get_results(
            $wpdb->prepare( $sql, $args ),
            ARRAY_A
        ) ?: [];

        if ( $filtre === 'non_votes' )  $photos = array_filter( $photos, fn($p) => empty($p['mon_vote']) );
        if ( $filtre === 'retenues' )   $photos = array_filter( $photos, fn($p) => $p['mon_vote'] === 'retenue' );
        if ( $filtre === 'refusees' )   $photos = array_filter( $photos, fn($p) => $p['mon_vote'] === 'refusee' );

        return array_values( array_map( function( $photo ) {
            $photo['url_thumb'] = $this->get_photo_url( (int) $photo['id'], 'thumb' );
            $photo['url_full']  = $this->get_photo_url( (int) $photo['id'], 'full' );
            return $photo;
        }, $photos ) );
    }

    public function get_mes_votes( int $jury_user_id ): array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_VOTES );
        $rows  = $wpdb->get_results(
            $wpdb->prepare( "SELECT photo_id, decision, commentaire FROM {$table} WHERE jury_user_id = %d", $jury_user_id ),
            ARRAY_A
        ) ?: [];
        $idx = [];
        foreach ( $rows as $r ) $idx[ $r['photo_id'] ] = [ 'decision' => $r['decision'], 'commentaire' => $r['commentaire'] ];
        return $idx;
    }

    // ── Vote ──────────────────────────────────────────────────────────

    public function voter( int $jury_user_id, int $photo_id, string $decision, string $commentaire = '' ): array {
        if ( ! in_array( $decision, [ 'retenue', 'refusee' ], true ) )
            return [ 'success' => false, 'message' => __( 'Décision invalide.', 'photo-contest' ) ];

        $photo = PC_Photos::get_instance()->get_photo( $photo_id );
        if ( ! $photo || ! in_array( $photo['statut'], [ 'en_attente', 'en_examen' ], true ) )
            return [ 'success' => false, 'message' => __( 'Cette photo n\'est plus en délibération.', 'photo-contest' ) ];

        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_VOTES );

        if ( $photo['statut'] === 'en_attente' )
            PC_Photos::get_instance()->update_statut( $photo_id, 'en_examen' );

        $existant = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE photo_id = %d AND jury_user_id = %d", $photo_id, $jury_user_id
        ) );

        $data = [ 'decision' => $decision, 'commentaire' => sanitize_textarea_field( $commentaire ) ];

        if ( $existant ) {
            $wpdb->update( $table, $data, [ 'photo_id' => $photo_id, 'jury_user_id' => $jury_user_id ] );
        } else {
            $wpdb->insert( $table, array_merge( $data, [ 'photo_id' => $photo_id, 'jury_user_id' => $jury_user_id ] ) );
        }

        return array_merge( [ 'success' => true ], $this->deliberer( $photo_id ) );
    }

    // ── Délibération collective ───────────────────────────────────────

    public function deliberer( int $photo_id ): array {
        global $wpdb;
        $tv     = PC_Database::table( PC_Database::TABLE_VOTES );
        $regle  = PC_Settings::get( 'regle_deliberation', 'unanimite' );
        $nb_jur = $this->compter_jurys_actifs();

        $votes = $wpdb->get_results(
            $wpdb->prepare( "SELECT decision, COUNT(*) AS nb FROM {$tv} WHERE photo_id = %d GROUP BY decision", $photo_id ),
            ARRAY_A
        ) ?: [];

        $nb_retenu = $nb_refuse = $nb_total = 0;
        foreach ( $votes as $v ) {
            if ( $v['decision'] === 'retenue' ) $nb_retenu = (int) $v['nb'];
            if ( $v['decision'] === 'refusee' ) $nb_refuse = (int) $v['nb'];
            $nb_total += (int) $v['nb'];
        }

        $clore = match( $regle ) {
            'unanimite' => $nb_jur > 0 && $nb_total >= $nb_jur,
            'majorite'  => $nb_jur > 0 && $nb_total >= $nb_jur,
            'quorum'    => $nb_total >= (int) PC_Settings::get( 'jury_quorum', 3 ),
            default     => false,
        };

        if ( ! $clore ) return [];

        $nouveau = $nb_retenu >= $nb_refuse ? 'retenue' : 'refusee';
        PC_Photos::get_instance()->update_statut( $photo_id, $nouveau );

        // Phase 2 : la cascade automatique retenue → participation_demandee est SUPPRIMÉE.
        // La photo reste en « retenue » jusqu'à la clôture admin (PC_Payments::execute_cloture),
        // qui bascule en lot toutes les retenues et crée les paiements groupés par candidat.

        return [
            'nouveau_statut' => $nouveau,
            'libelle_statut' => PC_STATUTS_PHOTO[ $nouveau ] ?? $nouveau,
        ];
    }

    // ── Stats ─────────────────────────────────────────────────────────

    public function get_stats_jury( int $jury_user_id ): array {
        global $wpdb;
        $t = PC_Database::table( PC_Database::TABLE_VOTES );
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS votes_total, SUM(decision='retenue') AS retenus, SUM(decision='refusee') AS refuses
                 FROM {$t} WHERE jury_user_id = %d",
                $jury_user_id
            ), ARRAY_A
        ) ?: [ 'votes_total' => 0, 'retenus' => 0, 'refuses' => 0 ];
    }

    public function get_stats_globales(): array {
        global $wpdb;
        $tp = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $tv = PC_Database::table( PC_Database::TABLE_VOTES );
        $totaux = $wpdb->get_row(
            "SELECT COUNT(*) AS total,
             SUM(statut='en_attente') AS en_attente, SUM(statut='en_examen') AS en_examen,
             SUM(statut='retenue') AS retenues, SUM(statut='refusee') AS refusees,
             SUM(statut='participation_demandee') AS participation_demandee,
             SUM(statut='paiement_recu') AS paiement_recu, SUM(statut='au_catalogue') AS au_catalogue
             FROM {$tp}", ARRAY_A
        ) ?: [];
        return array_merge( $totaux, [
            'votes_total'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tv}" ),
            'jurys_actifs' => $this->compter_jurys_actifs(),
        ] );
    }

    private function compter_jurys_actifs(): int {
        $users = get_users( [ 'role' => PC_ROLE_JURY ] );
        return count( $users );
    }

    private function get_photo_url( int $photo_id, string $taille = 'full' ): string {
        $token = wp_create_nonce( "pc_jury_photo_{$photo_id}_" . get_current_user_id() );
        return add_query_arg( [ 'pc_photo' => $photo_id, 'taille' => $taille, 'token' => $token, 'jury' => 1 ], home_url( '/' ) );
    }

    // ── AJAX ──────────────────────────────────────────────────────────

    public function ajax_get_photos(): void {
        check_ajax_referer( 'pc_jury_get_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_view_all_photos' ) )
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'photo-contest' ) ] );

        $uid         = get_current_user_id();
        $filtre      = sanitize_key( $_POST['filtre'] ?? 'toutes' );
        $category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
        wp_send_json_success( [
            'photos'    => $this->get_photos_pour_jury( $uid, $filtre, $category_id ),
            'mes_votes' => $this->get_mes_votes( $uid ),
            'stats'     => $this->get_stats_jury( $uid ),
        ] );
    }

    public function ajax_voter(): void {
        check_ajax_referer( 'pc_jury_vote_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_vote_photo' ) )
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'photo-contest' ) ] );

        $photo_id    = (int) ( $_POST['photo_id']    ?? 0 );
        $decision    = sanitize_key( $_POST['decision']   ?? '' );
        $commentaire = sanitize_textarea_field( $_POST['commentaire'] ?? '' );

        if ( ! $photo_id )
            wp_send_json_error( [ 'message' => __( 'Photo invalide.', 'photo-contest' ) ] );

        $r = $this->voter( get_current_user_id(), $photo_id, $decision, $commentaire );
        $r['success'] ? wp_send_json_success( $r ) : wp_send_json_error( $r );
    }

    public function ajax_get_stats(): void {
        check_ajax_referer( 'pc_jury_get_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_view_jury_panel' ) )
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'photo-contest' ) ] );
        wp_send_json_success( $this->get_stats_jury( get_current_user_id() ) );
    }
}
