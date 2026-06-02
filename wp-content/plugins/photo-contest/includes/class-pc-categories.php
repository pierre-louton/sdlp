<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gestion des catégories du concours.
 * Méthodes statiques pures, pas d'état d'instance.
 */
class PC_Categories {

    public static function get_all( bool $only_active = false ): array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $where = $only_active ? 'WHERE actif = 1' : '';
        $rows  = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id ASC", ARRAY_A );
        return $rows ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function create( string $nom ): int {
        global $wpdb;
        $nom = sanitize_text_field( $nom );
        if ( $nom === '' ) {
            return 0;
        }
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $ok    = $wpdb->insert( $table, [ 'nom' => $nom, 'actif' => 1 ] );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function update( int $id, string $nom ): bool {
        global $wpdb;
        $nom = sanitize_text_field( $nom );
        if ( $nom === '' || $id <= 0 ) {
            return false;
        }
        $table = PC_Database::table( PC_Database::TABLE_CATEGORIES );
        $wpdb->update( $table, [ 'nom' => $nom ], [ 'id' => $id ] );
        return $wpdb->last_error === '';
    }

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
     * @return bool|WP_Error true si supprimée, WP_Error si bloquée.
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

    public static function count_photos( int $id ): int {
        global $wpdb;
        $table_photos = PC_Database::table( PC_Database::TABLE_PHOTOS );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_photos} WHERE category_id = %d",
            $id
        ) );
    }
}
