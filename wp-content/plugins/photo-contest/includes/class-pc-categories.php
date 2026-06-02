<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gestion des catégories du concours.
 *
 * Responsabilités :
 *  - CRUD basique sur la table pc_categories
 *  - Comptage des photos rattachées (utilisé par admin + suppression)
 *
 * Note : méthodes statiques pures, pas d'état d'instance, pas de hook.
 * Consommée par : PC_Admin (catégories), PC_Photos (upload), PC_Jury (filtre), PC_Catalogue (groupement).
 */
class PC_Categories {

    /**
     * Retourne toutes les catégories triées par id ASC (ordre de création).
     *
     * @param bool $only_active Si true, filtre WHERE actif = 1.
     * @return array Tableau d'arrays associatifs (id, nom, actif, created_at, updated_at). Vide si aucune.
     */
    public static function get_all( bool $only_active = false ): array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $where = $only_active ? 'WHERE actif = 1' : '';
        $rows  = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id ASC", ARRAY_A );
        return $rows ?: [];
    }

    /**
     * Retourne une catégorie par son ID.
     *
     * @param int $id
     * @return array|null Array associatif ou null si introuvable.
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Crée une nouvelle catégorie active.
     *
     * Sanitise le nom et tronque à 150 caractères (longueur BDD).
     * Ne déduplique PAS par nom — l'unicité est laissée à l'appelant.
     *
     * @param string $nom Nom de la catégorie (sera sanitisé et tronqué).
     * @return int ID de la catégorie créée, ou 0 si nom vide ou échec.
     */
    public static function create( string $nom ): int {
        global $wpdb;
        $nom = mb_substr( sanitize_text_field( $nom ), 0, 150 );
        if ( $nom === '' ) {
            return 0;
        }
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $ok    = $wpdb->insert( $table, [ 'nom' => $nom, 'actif' => 1 ] );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Renomme une catégorie existante.
     *
     * Retourne true même si aucune ligne n'est modifiée (cas valeur identique) :
     * la seule cause d'échec est une erreur SQL, pas une absence de changement.
     *
     * @param int    $id
     * @param string $nom Nouveau nom (sanitisé + tronqué à 150 caractères).
     * @return bool false si entrée invalide, true sinon.
     */
    public static function update( int $id, string $nom ): bool {
        global $wpdb;
        $nom = mb_substr( sanitize_text_field( $nom ), 0, 150 );
        if ( $nom === '' || $id <= 0 ) {
            return false;
        }
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $wpdb->update( $table, [ 'nom' => $nom ], [ 'id' => $id ] );
        return $wpdb->last_error === '';
    }

    /**
     * Bascule actif/inactif de manière atomique côté SQL.
     *
     * Utilise UPDATE ... SET actif = 1 - actif qui évite toute race condition
     * entre un read et un write.
     *
     * @param int $id
     * @return bool false si id invalide, true sinon.
     */
    public static function toggle( int $id ): bool {
        global $wpdb;
        if ( $id <= 0 ) {
            return false;
        }
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET actif = 1 - actif WHERE id = %d",
            $id
        ) );
        return $wpdb->last_error === '';
    }

    /**
     * Supprime une catégorie si elle ne contient aucune photo.
     *
     * ATTENTION : signature mixte. L'appelant DOIT vérifier is_wp_error()
     * avant d'évaluer le retour comme booléen :
     *
     *   $r = PC_Categories::delete( $id );
     *   if ( is_wp_error( $r ) ) { ... message d'erreur ... }
     *   elseif ( $r === true ) { ... succès ... }
     *
     * Un `if ( PC_Categories::delete( $id ) )` simple est PIÉGEUX car WP_Error
     * est un objet truthy.
     *
     * @param int $id
     * @return bool|WP_Error true si supprimée. WP_Error('pc_invalid_id') si id <= 0.
     *                       WP_Error('pc_category_has_photos') si des photos sont rattachées.
     */
    public static function delete( int $id ) {
        global $wpdb;
        if ( $id <= 0 ) {
            return new WP_Error( 'pc_invalid_id', __( 'ID invalide.', PC_TEXT_DOMAIN ) );
        }
        $count = self::count_photos( $id );
        if ( $count > 0 ) {
            return new WP_Error(
                'pc_category_has_photos',
                sprintf(
                    /* translators: %d = nombre de photos */
                    __( 'Suppression refusée : %d photos sont rattachées à cette catégorie.', PC_TEXT_DOMAIN ),
                    $count
                )
            );
        }
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $wpdb->delete( $table, [ 'id' => $id ] );
        return $wpdb->last_error === '';
    }

    /**
     * Compte les photos rattachées à une catégorie.
     *
     * @param int $id
     * @return int Nombre de photos (0 si aucune ou catégorie inexistante).
     */
    public static function count_photos( int $id ): int {
        global $wpdb;
        $table_photos = PC_Database::table( PC_Database::TABLE_PHOTOS );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_photos} WHERE category_id = %d",
            $id
        ) );
    }
}
