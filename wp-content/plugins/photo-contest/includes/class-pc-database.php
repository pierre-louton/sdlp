<?php
defined( 'ABSPATH' ) || exit;

/**
 * Création et gestion des tables custom du plugin.
 *
 * Tables :
 *  - pc_profiles        : profil étendu du candidat
 *  - pc_photos          : photos déposées
 *  - pc_jury_votes      : décisions du jury par photo
 *  - pc_payments        : participations financières
 *  - pc_catalogue_items : métadonnées catalogue par photo retenue
 *  - pc_email_tokens    : tokens de vérification email
 *  - pc_categories      : catégories de concours
 */
class PC_Database {

    // Noms des tables (sans préfixe wpdb)
    const TABLE_PROFILES   = 'pc_profiles';
    const TABLE_PHOTOS     = 'pc_photos';
    const TABLE_VOTES      = 'pc_jury_votes';
    const TABLE_PAYMENTS   = 'pc_payments';
    const TABLE_CATALOGUE  = 'pc_catalogue_items';
    const TABLE_EMAIL_TOKENS = 'pc_email_tokens';
    const TABLE_CATEGORIES = 'pc_categories';

    /**
     * Retourne le nom complet d'une table (avec préfixe wpdb).
     */
    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    /**
     * Crée toutes les tables. Appelé à l'activation.
     * dbDelta() est idempotent : peut être relancé sans danger lors des mises à jour.
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Profils candidats ──────────────────────────────────────────────
        $profiles = self::table( self::TABLE_PROFILES );
        dbDelta( "CREATE TABLE {$profiles} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id         BIGINT UNSIGNED NOT NULL,
            prenom          VARCHAR(100)    NOT NULL DEFAULT '',
            nom             VARCHAR(100)    NOT NULL DEFAULT '',
            date_naissance  DATE            NULL,
            adresse_rue     VARCHAR(255)    NOT NULL DEFAULT '',
            code_postal     VARCHAR(20)     NOT NULL DEFAULT '',
            ville           VARCHAR(100)    NOT NULL DEFAULT '',
            pays            VARCHAR(100)    NOT NULL DEFAULT '',
            telephone       VARCHAR(30)     NOT NULL DEFAULT '',
            profil_complet  TINYINT(1)      NOT NULL DEFAULT 0,
            reglement_accepte TINYINT(1)    NOT NULL DEFAULT 0,
            reglement_date  DATETIME        NULL,
            opt_in_prochain TINYINT(1)      NOT NULL DEFAULT 0,
            email_verifie   TINYINT(1)      NOT NULL DEFAULT 0,
            paiement_inscription_recu TINYINT(1) NOT NULL DEFAULT 0,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) {$charset};" );

        // ── Tokens de vérification email ───────────────────────────────────
        $tokens = self::table( self::TABLE_EMAIL_TOKENS );
        dbDelta( "CREATE TABLE {$tokens} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL,
            token       VARCHAR(64)     NOT NULL,
            type        VARCHAR(30)     NOT NULL DEFAULT 'email_verification',
            expires_at  DATETIME        NOT NULL,
            used        TINYINT(1)      NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id)
        ) {$charset};" );

        // ── Photos ─────────────────────────────────────────────────────────
        $photos = self::table( self::TABLE_PHOTOS );
        dbDelta( "CREATE TABLE {$photos} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id         BIGINT UNSIGNED NOT NULL,
            titre           VARCHAR(255)    NOT NULL DEFAULT '',
            nom_fichier     VARCHAR(255)    NOT NULL,
            chemin_fichier  VARCHAR(500)    NOT NULL,
            taille_octets   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            largeur_px      INT UNSIGNED    NOT NULL DEFAULT 0,
            hauteur_px      INT UNSIGNED    NOT NULL DEFAULT 0,
            ratio_type      ENUM('3_2','2_3','invalide') NOT NULL DEFAULT 'invalide',
            statut          VARCHAR(50)     NOT NULL DEFAULT 'en_attente',
            ordre_affichage INT UNSIGNED    NOT NULL DEFAULT 0,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY statut (statut)
        ) {$charset};" );

        // ── Votes jury ─────────────────────────────────────────────────────
        $votes = self::table( self::TABLE_VOTES );
        dbDelta( "CREATE TABLE {$votes} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            photo_id        BIGINT UNSIGNED NOT NULL,
            jury_user_id    BIGINT UNSIGNED NOT NULL,
            decision        ENUM('retenue','refusee') NOT NULL,
            commentaire     TEXT            NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY photo_jury (photo_id, jury_user_id),
            KEY photo_id (photo_id)
        ) {$charset};" );

        // ── Paiements ──────────────────────────────────────────────────────
        $payments = self::table( self::TABLE_PAYMENTS );
        dbDelta( "CREATE TABLE {$payments} (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            photo_id            BIGINT UNSIGNED NOT NULL,
            user_id             BIGINT UNSIGNED NOT NULL,
            montant_centimes    INT UNSIGNED    NOT NULL DEFAULT 0,
            devise              VARCHAR(3)      NOT NULL DEFAULT 'EUR',
            statut_paiement     ENUM('en_attente','paiement_recu','echoue','rembourse') NOT NULL DEFAULT 'en_attente',
            reference_externe   VARCHAR(255)    NULL COMMENT 'ID transaction Stripe ou autre',
            methode             VARCHAR(50)     NOT NULL DEFAULT '',
            created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY photo_id (photo_id),
            KEY user_id (user_id),
            KEY statut_paiement (statut_paiement)
        ) {$charset};" );

        // ── Catalogue ──────────────────────────────────────────────────────
        $catalogue = self::table( self::TABLE_CATALOGUE );
        dbDelta( "CREATE TABLE {$catalogue} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            photo_id        BIGINT UNSIGNED NOT NULL,
            user_id         BIGINT UNSIGNED NOT NULL,
            titre_catalogue VARCHAR(255)    NOT NULL DEFAULT '',
            biographie      TEXT            NULL,
            tirage          VARCHAR(100)    NOT NULL DEFAULT '',
            ordre_catalogue INT UNSIGNED    NOT NULL DEFAULT 0,
            inclus_catalogue TINYINT(1)     NOT NULL DEFAULT 0,
            notes_editeur   TEXT            NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY photo_id (photo_id)
        ) {$charset};" );

        // ── Catégories ─────────────────────────────────────────────────────
        $table_categories = self::table( self::TABLE_CATEGORIES );
        dbDelta( "CREATE TABLE {$table_categories} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nom           VARCHAR(150)    NOT NULL,
            actif         TINYINT(1)      NOT NULL DEFAULT 1,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY actif (actif)
        ) {$charset};" );

        // Migrations structurelles idempotentes
        self::add_category_column_to_photos();
        self::seed_default_category();
        self::migrate_legacy_photos_to_default_category();
        self::add_hash_column_to_photos();
        self::backfill_hash_sha1();
        self::dedupe_existing_photos();
        self::add_unique_hash_index();
        self::add_payments_phase2_columns();
        self::add_profiles_phase3_columns();
        self::backfill_email_envoye_at();

        // Mise à jour de la version en base
        update_option( 'pc_db_version', PC_VERSION );
    }

    /**
     * Insère la catégorie par défaut « Photo Club Pavillonnais » si la table est vide.
     * Idempotent : retourne l'ID de la première catégorie si existante.
     *
     * @return int ID de la catégorie par défaut (ou de la première si plusieurs existent déjà).
     */
    private static function seed_default_category(): int {
        global $wpdb;
        $table = self::table( self::TABLE_CATEGORIES );

        $existing = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1" );
        if ( $existing > 0 ) {
            return $existing;
        }

        $wpdb->insert( $table, [
            'nom'   => 'Photo Club Pavillonnais',
            'actif' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Affecte la catégorie par défaut à toutes les photos avec category_id = 0.
     * Idempotent : ne fait rien si aucune photo n'est orpheline.
     */
    private static function migrate_legacy_photos_to_default_category(): void {
        global $wpdb;
        $table = self::table( self::TABLE_PHOTOS );

        $orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE category_id = 0" );
        if ( $orphans === 0 ) {
            return;
        }

        $default_id = self::seed_default_category();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET category_id = %d WHERE category_id = 0",
            $default_id
        ) );
    }

    /**
     * Add category_id column to wp_pc_photos.
     * Idempotent: checks information_schema before issuing ALTER.
     */
    private static function add_category_column_to_photos(): void {
        global $wpdb;
        $table = self::table( self::TABLE_PHOTOS );

        $col_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = %s
               AND column_name = 'category_id'",
            $table
        ) );

        if ( ! $col_exists ) {
            $wpdb->query( "ALTER TABLE {$table}
                ADD COLUMN category_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id,
                ADD KEY category_id (category_id),
                ADD KEY user_category (user_id, category_id)" );
        }
    }

    /**
     * Ajoute la colonne hash_sha1 à wp_pc_photos si elle n'existe pas encore.
     *
     * La colonne est NULLABLE : NULL signifie « fichier absent au moment de la migration,
     * hash non calculable ». Les valeurs NULL sont ignorées par les index UNIQUE MySQL,
     * ce qui permet à l'index unique (user_id, hash_sha1) de fonctionner même si certains
     * fichiers n'existent pas localement (ex. photos importées depuis la prod).
     *
     * Idempotent : vérifie information_schema avant toute modification.
     */
    private static function add_hash_column_to_photos(): void {
        global $wpdb;
        $table = self::table( self::TABLE_PHOTOS );

        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = %s
               AND column_name = 'hash_sha1'",
            $table
        ) );

        if ( ! $col ) {
            $wpdb->query( "ALTER TABLE {$table}
                ADD COLUMN hash_sha1 VARCHAR(40) NULL DEFAULT NULL AFTER chemin_fichier" );
        }
    }

    /**
     * Calcule et stocke le hash SHA-1 pour toutes les photos qui n'en ont pas encore.
     * Les photos dont le fichier est absent (ex. données de prod) restent à NULL.
     * Idempotent : ignore les lignes dont hash_sha1 est déjà non NULL.
     */
    private static function backfill_hash_sha1(): void {
        global $wpdb;
        $table = self::table( self::TABLE_PHOTOS );

        $rows = $wpdb->get_results( "SELECT id, chemin_fichier FROM {$table} WHERE hash_sha1 IS NULL" );
        foreach ( $rows as $r ) {
            if ( file_exists( $r->chemin_fichier ) ) {
                $hash = sha1_file( $r->chemin_fichier );
                if ( $hash ) {
                    $wpdb->update( $table, [ 'hash_sha1' => $hash ], [ 'id' => (int) $r->id ] );
                }
            }
            // Si le fichier est absent, on laisse hash_sha1 à NULL.
            // NULL est ignoré par l'index UNIQUE, donc pas de conflit.
        }
    }

    /**
     * Supprime les doublons de photos (BDD + fichier disque) en gardant la plus ancienne (MIN id)
     * par couple (user_id, hash_sha1).
     *
     * ATTENTION ENVIRONNEMENT DEV : si la BDD a été clonée depuis la prod et que les fichiers
     * physiques pointent vers des chemins serveur (/home/.../) absents en local, leur hash_sha1
     * reste NULL après backfill et ils sont IGNORÉS ici (NULL ne crée pas de groupe).
     *
     * IMPORTANT : une version antérieure utilisait DEFAULT '' (chaîne vide). Avec cette version,
     * tous les fichiers absents étaient regroupés et supprimés en masse — incident 2026-06-03 :
     * 6 photos legacy user 6 perdues sur dev. Le passage à NULL DEFAULT NULL évite ce piège.
     *
     * Idempotent : ne fait rien si aucun doublon n'existe.
     */
    private static function dedupe_existing_photos(): void {
        global $wpdb;
        $table = self::table( self::TABLE_PHOTOS );

        $groups = $wpdb->get_results(
            "SELECT user_id, hash_sha1, MIN(id) AS keeper, GROUP_CONCAT(id) AS ids
             FROM {$table}
             WHERE hash_sha1 IS NOT NULL
             GROUP BY user_id, hash_sha1
             HAVING COUNT(*) > 1"
        );

        foreach ( $groups as $g ) {
            $all_ids    = array_map( 'intval', explode( ',', $g->ids ) );
            $duplicates = array_diff( $all_ids, [ (int) $g->keeper ] );

            foreach ( $duplicates as $duplicate_id ) {
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT chemin_fichier FROM {$table} WHERE id = %d",
                    $duplicate_id
                ) );

                if ( $row && ! empty( $row->chemin_fichier ) && file_exists( $row->chemin_fichier ) ) {
                    if ( ! @unlink( $row->chemin_fichier ) ) {
                        error_log( '[PC_Database::dedupe_existing_photos] Failed to unlink ' . $row->chemin_fichier );
                    }
                }

                $wpdb->delete( $table, [ 'id' => $duplicate_id ] );
            }
        }
    }

    /**
     * Ajoute l'index unique (user_id, hash_sha1) sur wp_pc_photos.
     * Les lignes avec hash_sha1 = NULL sont exclues de l'index par MySQL.
     * Idempotent : vérifie SHOW INDEX avant toute modification.
     *
     * IMPORTANT : appeler APRÈS backfill_hash_sha1() et dedupe_existing_photos()
     * pour garantir qu'il n'y a plus de doublons (user_id, hash_sha1) avant la
     * création de l'index UNIQUE.
     */
    private static function add_unique_hash_index(): void {
        global $wpdb;
        $table = self::table( self::TABLE_PHOTOS );

        $exists = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'unique_user_hash'" );
        if ( $exists ) {
            return;
        }

        // Supprimer l'index simple hash_sha1 s'il est présent (remplacé par l'index composé unique)
        $non_unique = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'hash_sha1' AND Non_unique = 1" );
        if ( $non_unique ) {
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX hash_sha1" );
        }

        $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY unique_user_hash (user_id, hash_sha1)" );
    }

    /**
     * Phase 2 : ajoute les colonnes pour le paiement groupé (payment_token + suivi cron emails/relances).
     * Idempotent — vérifie chaque colonne et index avant ALTER.
     */
    private static function add_payments_phase2_columns(): void {
        global $wpdb;
        $table = self::table( self::TABLE_PAYMENTS );

        $needed = [
            'payment_token'       => "VARCHAR(64) NULL AFTER reference_externe",
            'email_envoye_at'     => "DATETIME NULL AFTER payment_token",
            'derniere_relance_at' => "DATETIME NULL AFTER email_envoye_at",
            'nb_relances'         => "TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER derniere_relance_at",
        ];

        foreach ( $needed as $col => $def ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
                $table, $col
            ) );
            if ( ! $exists ) {
                $result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$def}" );
                if ( false === $result ) {
                    error_log( '[PC_Database::add_payments_phase2_columns] ALTER failed for ' . $col . ' : ' . $wpdb->last_error );
                }
            }
        }

        // Index
        $existing_indexes = array_map( fn( $i ) => $i->Key_name, $wpdb->get_results( "SHOW INDEX FROM {$table}" ) ?: [] );
        if ( ! in_array( 'idx_payment_token', $existing_indexes, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_payment_token (payment_token)" );
        }
        if ( ! in_array( 'idx_email_pending', $existing_indexes, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_email_pending (email_envoye_at, statut_paiement)" );
        }
        if ( ! in_array( 'idx_photo_statut', $existing_indexes, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_photo_statut (photo_id, statut_paiement)" );
        }
    }

    /**
     * Cohérence historique : pour les paiements déjà reçus avant Phase 2, on initialise
     * email_envoye_at avec updated_at — sinon le cron de relance les considérerait comme
     * éligibles à relance, alors qu'ils sont payés.
     */
    private static function backfill_email_envoye_at(): void {
        global $wpdb;
        $table = self::table( self::TABLE_PAYMENTS );
        // Vérifier que la colonne existe avant le UPDATE (évite erreur si Task 2 n'a pas tourné en amont).
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'email_envoye_at'",
            $table
        ) );
        if ( ! $col ) return;
        $wpdb->query(
            "UPDATE {$table}
             SET email_envoye_at = updated_at
             WHERE statut_paiement = 'paiement_recu' AND email_envoye_at IS NULL"
        );
    }

    /**
     * Sous-projet 3 : colonne d'opt-in « prochain concours » sur la table profils.
     * Idempotent — vérifie la colonne avant ALTER.
     */
    private static function add_profiles_phase3_columns(): void {
        global $wpdb;
        $table = self::table( self::TABLE_PROFILES );
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
            $table, 'opt_in_prochain'
        ) );
        if ( ! $exists ) {
            $result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN opt_in_prochain TINYINT(1) NOT NULL DEFAULT 0" );
            if ( false === $result ) {
                error_log( '[PC_Database::add_profiles_phase3_columns] ALTER failed : ' . $wpdb->last_error );
            }
        }
    }

    /**
     * Supprime toutes les tables. Appelé à la désinstallation uniquement.
     */
    public static function drop_tables(): void {
        global $wpdb;
        // On supprime dans l'ordre inverse des dépendances FK
        foreach ( [
            self::TABLE_CATALOGUE,
            self::TABLE_PAYMENTS,
            self::TABLE_VOTES,
            self::TABLE_PHOTOS,
            self::TABLE_PROFILES,
            self::TABLE_EMAIL_TOKENS,
            self::TABLE_CATEGORIES,
        ] as $table ) {
            $wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $table ) );
        }
        delete_option( 'pc_db_version' );
    }

    /**
     * Vérifie si une mise à jour de schéma est nécessaire et la lance.
     * À appeler dans un hook admin_init.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( 'pc_db_version' ) !== PC_VERSION ) {
            self::create_tables();
        }
    }
}
