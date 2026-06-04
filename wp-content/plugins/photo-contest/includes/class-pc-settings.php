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

        // Phase 2 : envoi par lots + relances impayés
        'email_batch_size'             => 20,
        'email_batch_interval_minutes' => 5,
        'relance_jours'                => 5,
        'relance_max'                  => 2,
        'cloture_effectuee_at'         => 0,
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
     * Tient compte du flag admin ET des dates de concours.
     */
    public static function is_depot_actif(): bool {
        if ( ! self::get( 'depot_actif' ) ) {
            return false;
        }

        $ouverture  = self::get( 'date_ouverture' );
        $fermeture  = self::get( 'date_fermeture_depot' );
        $now        = current_time( 'mysql' );

        if ( $ouverture && $now < $ouverture ) {
            return false; // pas encore ouvert
        }
        if ( $fermeture && $now > $fermeture ) {
            return false; // clôturé
        }

        return true;
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
