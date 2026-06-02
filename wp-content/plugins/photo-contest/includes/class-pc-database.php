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
            KEY idx_actif (actif)
        ) {$charset};" );

        // Mise à jour de la version en base
        update_option( 'pc_db_version', PC_VERSION );
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
