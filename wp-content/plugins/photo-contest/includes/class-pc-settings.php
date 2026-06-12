<?php
defined( 'ABSPATH' ) || exit;

/**
 * Paramètres globaux du concours, stockés en option WordPress.
 *
 * Accès : PC_Settings::get( 'quota_photos' )
 * Écriture : PC_Settings::set( 'quota_photos', 10 )
 */
class PC_Settings {

    private const OPTION_KEY = 'pc_settings';

    /**
     * Valeurs par défaut de tous les paramètres.
     */
    private static array $defaults = [
        // Concours
        'nom_concours'              => 'SDLP — Semaines de la Photo',
        'logo_url'                  => '',
        'reglement_url'             => '',         // URL du PDF règlement (depuis Médias WP)
        'edition'                   => '',
        'date_ouverture'            => '',
        'date_fermeture_depot'      => '',
        'date_annonce_resultats'    => '',

        // Photos
        'quota_photos'              => 5,          // nombre max par catégorie (et par candidat)
        'poids_max_mo'              => 40,         // en mégaoctets
        'ratio_autorises'           => ['3_2', '2_3'],

        // Paiement
        'montant_participation_cts' => 1500,       // en centimes (15,00 €)
        'devise'                    => 'EUR',
        'methode_paiement'          => 'stripe',   // stripe | fluent_forms | woocommerce

        // Stripe
        'stripe_secret_key'         => '',         // sk_live_... ou sk_test_...
        'stripe_publishable_key'    => '',         // pk_live_... ou pk_test_...
        'stripe_webhook_secret'     => '',         // whsec_...
        'stripe_mode'               => 'test',     // 'test' | 'live'

        // Fluent CRM
        'fluent_list_candidats'     => 0,          // ID liste Fluent CRM
        'fluent_list_retenus'       => 0,
        'fluent_tag_paye'           => 0,

        // Catalogue
        'catalogue_titre'           => '',
        'catalogue_sous_titre'      => '',
        'catalogue_isbn'            => '',

        // Interface
        'depot_actif'               => true,
        'jury_actif'                => false,
        'catalogue_actif'           => false,

        // Sécurité
        'login_slug'                => 'connexion',  // URL custom : /connexion/
        'login_max_attempts'        => 5,
        'login_lockout_minutes'     => 15,

        // Phase 2 : relances impayés
        'relance_offset_1'             => 10,   // 1re relance : J-10 avant clôture dépôt (0 = off)
        'relance_offset_2'             => 5,    // 2e relance  : J-5 avant clôture dépôt (0 = off)
        'cloture_effectuee_at'         => 0,

        // Phase 3 : RGPD
        'rgpd_texte'                => 'Les informations recueillies dans ce formulaire sont enregistrées par le Photo Club Pavillonnais et utilisées uniquement pour la gestion de votre participation au concours photo (inscription, délibération du jury, paiement, catalogue). Elles ne sont ni cédées à des tiers, ni exploitées à d\'autres fins. Conformément au RGPD, vous disposez d\'un droit d\'accès, de rectification et d\'effacement de vos données en écrivant à contact@photo-club-pavillonnais.fr.',
    ];

    private static ?array $cache = null;

    /**
     * Retourne tous les paramètres (fusionnés avec les défauts).
     */
    public static function all(): array {
        if ( self::$cache === null ) {
            $saved       = get_option( self::OPTION_KEY, [] );
            self::$cache = array_merge( self::$defaults, (array) $saved );
        }
        return self::$cache;
    }

    /**
     * Retourne la valeur d'un paramètre.
     *
     * @param string $key     Clé du paramètre
     * @param mixed  $default Valeur par défaut si clé absente
     */
    public static function get( string $key, mixed $default = null ): mixed {
        $all = self::all();
        return $all[ $key ] ?? $default;
    }

    /**
     * Enregistre un ou plusieurs paramètres.
     *
     * @param string|array $key   Clé unique ou tableau clé=>valeur
     * @param mixed        $value Valeur (ignorée si $key est tableau)
     */
    public static function set( string|array $key, mixed $value = null ): void {
        $all = self::all();

        if ( is_array( $key ) ) {
            $all = array_merge( $all, $key );
        } else {
            $all[ $key ] = $value;
        }

        update_option( self::OPTION_KEY, $all );
        self::$cache = $all;
    }

    /**
     * Vérifie si le dépôt de photos est actuellement actif.
     * Interrupteur maître `depot_actif` ET fenêtre de dates [ouverture ; clôture].
     * Comparaisons ancrées sur wp_timezone() (voir date_to_ts) — jamais l'horloge serveur.
     */
    public static function is_depot_actif(): bool {
        if ( ! self::get( 'depot_actif' ) ) {
            return false;
        }
        $now       = time();
        $ouverture = self::date_to_ts( self::get( 'date_ouverture' ) );
        $fermeture = self::date_to_ts( self::get( 'date_fermeture_depot' ) );

        if ( $ouverture !== null && $now < $ouverture ) {
            return false; // pas encore ouvert
        }
        if ( $fermeture !== null && $now > $fermeture ) {
            return false; // clôturé
        }
        return true;
    }

    /**
     * Vérifie si la phase jury (délibération) est actuellement ouverte.
     *
     * Ordre de priorité :
     *  1. Case `jury_actif` cochée -> true (forçage manuel, prioritaire sur dates ET clôture ; usage test)
     *  2. Clôture effectuée (cloture_effectuee_at > 0) -> false (chemin automatique verrouillé)
     *  3. time() >= date_fermeture_depot -> true (ouverture automatique)
     *  4. sinon false
     */
    public static function is_jury_actif(): bool {
        if ( self::get( 'jury_actif' ) ) {
            return true;
        }
        if ( (int) self::get( 'cloture_effectuee_at', 0 ) > 0 ) {
            return false;
        }
        $fermeture = self::date_to_ts( self::get( 'date_fermeture_depot' ) );
        return $fermeture !== null && time() >= $fermeture;
    }

    /**
     * Normalise une saisie de date (ex. datetime-local "Y-m-d\TH:i") en "Y-m-d H:i:s",
     * interprétée comme heure murale du fuseau du site. Chaîne vide si vide ou invalide.
     * Utilisé à la sauvegarde des réglages admin.
     */
    public static function normalize_stored_date( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }
        try {
            return ( new DateTimeImmutable( $value, wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    /**
     * Convertit une date stockée (heure murale du fuseau du site, sans fuseau explicite)
     * en timestamp epoch UTC, comparable à time(). null si vide/invalide.
     *
     * CRITIQUE : on ancre sur wp_timezone() (Réglages -> Général), jamais sur strtotime()/date()
     * nus ni l'horloge serveur. Laragon (dev) et O2Switch (prod) n'ont pas le même fuseau système ;
     * cet ancrage garantit que la deadline se déclenche au même instant réel partout.
     */
    private static function date_to_ts( mixed $value ): ?int {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( $value === '' ) {
            return null;
        }
        try {
            return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
        } catch ( Exception $e ) {
            return null;
        }
    }

    /**
     * Retourne le HTML du logo SDLP (img ou texte fallback).
     * Utilisé dans tous les templates topbar.
     */
    public static function logo_html( string $classe = 'pc-topbar__logo' ): string {
        $url = self::get( 'logo_url', '' );
        $nom = self::get( 'nom_concours', 'SDLP' );

        if ( $url ) {
            return sprintf(
                '<span class="%s" style="display:flex;align-items:center;gap:10px;">'
                . '<img src="%s" alt="%s" style="max-height:26px;width:auto;filter:brightness(1);">'
                . '<span style="font-family:var(--pc-font-mono,\'DM Mono\',monospace);font-size:11px;font-weight:500;letter-spacing:.06em;text-transform:uppercase;opacity:.85;">%s</span>'
                . '</span>',
                esc_attr( $classe ),
                esc_url( $url ),
                esc_attr( $nom ),
                esc_html( $nom )
            );
        }

        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr( $classe ),
            esc_html( $nom )
        );
    }

    /**
     * Retourne le montant de participation formaté (ex : "15,00 €").
     */
    public static function montant_formate(): string {
        $centimes = (int) self::get( 'montant_participation_cts', 0 );
        $devise   = self::get( 'devise', 'EUR' );
        return number_format( $centimes / 100, 2, ',', ' ' ) . ' ' . $devise;
    }
}
