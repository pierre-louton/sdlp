<?php defined( 'ABSPATH' ) || exit;
class PC_Admin {
    private static ?self $instance = null;
    public static function get_instance(): self { self::$instance ??= new self(); return self::$instance; }
    private function __construct() {
        add_action( 'admin_menu',  [ $this, 'register_menus' ] );
        add_action( 'admin_init',  [ $this, 'maybe_upgrade_db' ] );
        add_action( 'admin_init',  [ $this, 'save_settings' ] );
        add_action( 'admin_notices', [ $this, 'stripe_notice' ] );
    }

    public function register_menus(): void {
        add_menu_page(
            __( 'Concours Photo', PC_TEXT_DOMAIN ),
            __( 'Concours Photo', PC_TEXT_DOMAIN ),
            'pc_manage_contest_settings',
            'photo-contest',
            [ $this, 'render_dashboard' ],
            'dashicons-format-gallery',
            30
        );
        add_submenu_page( 'photo-contest', __( 'Paramètres', PC_TEXT_DOMAIN ), __( 'Paramètres', PC_TEXT_DOMAIN ), 'pc_manage_contest_settings', 'photo-contest-settings', [ $this, 'render_settings' ] );
        add_submenu_page( 'photo-contest', __( 'Paiements', PC_TEXT_DOMAIN ), __( 'Paiements', PC_TEXT_DOMAIN ), 'pc_manage_payments', 'photo-contest-payments', [ $this, 'render_payments' ] );
    }

    public function render_dashboard(): void {
        $stats_pmt = PC_Payments::get_instance()->get_stats();
        $total_cts = (int) ( $stats_pmt['total_centimes'] ?? 0 );
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Concours Photo — Tableau de bord', PC_TEXT_DOMAIN ) . '</h1>';
        echo '<table class="widefat" style="max-width:600px;margin-top:20px"><tbody>';
        printf( '<tr><th>%s</th><td><strong>%s</strong></td></tr>',
            esc_html__( 'Paiements reçus', PC_TEXT_DOMAIN ),
            esc_html( ( $stats_pmt['recus'] ?? 0 ) . ' (' . number_format( $total_cts / 100, 2, ',', ' ' ) . ' €)' )
        );
        printf( '<tr><th>%s</th><td>%s</td></tr>',
            esc_html__( 'En attente', PC_TEXT_DOMAIN ),
            esc_html( $stats_pmt['en_attente'] ?? 0 )
        );
        printf( '<tr><th>%s</th><td>%s</td></tr>',
            esc_html__( 'Échoués', PC_TEXT_DOMAIN ),
            esc_html( $stats_pmt['echoues'] ?? 0 )
        );
        echo '</tbody></table></div>';
    }

    public function render_settings(): void {
        $s = PC_Settings::all();
        echo '<div class="wrap"><h1>' . esc_html__( 'Paramètres du concours', PC_TEXT_DOMAIN ) . '</h1>';
        echo '<form method="post"><table class="form-table">';
        wp_nonce_field( 'pc_save_settings', 'pc_settings_nonce' );

        $fields = [
            'nom_concours'           => __( 'Nom du concours', PC_TEXT_DOMAIN ),
            'logo_url'               => __( 'URL du logo (depuis Médias WP)', PC_TEXT_DOMAIN ),
            'reglement_url'          => __( 'URL du règlement PDF (depuis Médias WP)', PC_TEXT_DOMAIN ),
            'edition'                => __( 'Édition', PC_TEXT_DOMAIN ),
            'quota_photos'           => __( 'Quota max photos par catégorie (par candidat)', PC_TEXT_DOMAIN ),
            'montant_participation_cts' => __( 'Montant par photo retenue (centimes)', PC_TEXT_DOMAIN ),
            'devise'                 => __( 'Devise (ex: EUR)', PC_TEXT_DOMAIN ),
            'stripe_publishable_key' => __( 'Stripe — Clé publique (pk_...)', PC_TEXT_DOMAIN ),
            'stripe_secret_key'      => __( 'Stripe — Clé secrète (sk_...)', PC_TEXT_DOMAIN ),
            'stripe_webhook_secret'  => __( 'Stripe — Secret webhook (whsec_...)', PC_TEXT_DOMAIN ),
            'login_slug'             => __( 'URL de connexion (ex: connexion)', PC_TEXT_DOMAIN ),
            'catalogue_titre'        => __( 'Titre du catalogue', PC_TEXT_DOMAIN ),
            'catalogue_isbn'         => __( 'ISBN', PC_TEXT_DOMAIN ),
        ];

        foreach ( $fields as $key => $label ) {
            $val  = $s[ $key ] ?? '';
            $type = str_contains( $key, 'secret' ) || str_contains( $key, 'sk_' ) ? 'password' : 'text';
            printf(
                '<tr><th><label for="%1$s">%2$s</label></th><td><input class="regular-text" type="%4$s" id="%1$s" name="pc_settings[%1$s]" value="%3$s"></td></tr>',
                esc_attr( $key ), esc_html( $label ), esc_attr( $val ), esc_attr( $type )
            );
        }

        // Toggles
        $toggles = [
            'stripe_mode'     => [ __( 'Mode Stripe', PC_TEXT_DOMAIN ), 'test', 'live' ],
        ];
        foreach ( $toggles as $key => [ $label, $opt1, $opt2 ] ) {
            $val = $s[ $key ] ?? $opt1;
            printf( '<tr><th>%s</th><td><label><input type="radio" name="pc_settings[%s]" value="%s" %s> %s</label> &nbsp; <label><input type="radio" name="pc_settings[%s]" value="%s" %s> %s</label></td></tr>',
                esc_html( $label ),
                esc_attr( $key ), esc_attr( $opt1 ), checked( $val, $opt1, false ), esc_html( ucfirst( $opt1 ) ),
                esc_attr( $key ), esc_attr( $opt2 ), checked( $val, $opt2, false ), esc_html( ucfirst( $opt2 ) )
            );
        }

        // Checkboxes activations
        $checkboxes = [
            'depot_actif'     => __( 'Dépôt de photos actif', PC_TEXT_DOMAIN ),
            'jury_actif'      => __( 'Forcer l\'ouverture du jury (sinon auto à la clôture du dépôt)', PC_TEXT_DOMAIN ),
            'catalogue_actif' => __( 'Forcer l\'affichage du catalogue (sinon auto à la clôture de la délibération)', PC_TEXT_DOMAIN ),
        ];
        $cb_desc = [
            'jury_actif'      => __( 'Coché = jury ouvert immédiatement, quelles que soient les dates et l\'état de clôture (utile en test). Décoché = ouverture automatique dès la date de clôture du dépôt.', PC_TEXT_DOMAIN ),
            'catalogue_actif' => __( 'Coché = catalogue visible immédiatement (utile en test). Sinon activé automatiquement lors de la clôture de la délibération.', PC_TEXT_DOMAIN ),
        ];
        foreach ( $checkboxes as $key => $label ) {
            $desc = isset( $cb_desc[ $key ] )
                ? '<p class="description">' . esc_html( $cb_desc[ $key ] ) . '</p>'
                : '';
            printf( '<tr><th>%s</th><td><input type="checkbox" name="pc_settings[%s]" value="1" %s>%s</td></tr>',
                esc_html( $label ), esc_attr( $key ), checked( ! empty( $s[ $key ] ), true, false ), $desc
            );
        }

        echo '</table>';

        // ── Calendrier du concours ──────────────────────────────────────
        $fmt_input = static function ( string $stored ): string {
            $stored = trim( $stored );
            if ( $stored === '' ) {
                return '';
            }
            try {
                return ( new DateTimeImmutable( $stored, wp_timezone() ) )->format( 'Y-m-d\TH:i' );
            } catch ( Exception $e ) {
                return '';
            }
        };
        ?>
        <h2 style="margin-top:24px"><?php esc_html_e( 'Calendrier du concours', PC_TEXT_DOMAIN ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="date_ouverture"><?php esc_html_e( 'Ouverture du dépôt', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="datetime-local" id="date_ouverture" name="pc_settings[date_ouverture]"
                           value="<?php echo esc_attr( $fmt_input( $s['date_ouverture'] ?? '' ) ); ?>">
                    <p class="description"><?php esc_html_e( 'Date et heure d\'ouverture du dépôt. Vide = aucune contrainte. Heure du fuseau du site (Réglages → Général).', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="date_fermeture_depot"><?php esc_html_e( 'Clôture du dépôt', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="datetime-local" id="date_fermeture_depot" name="pc_settings[date_fermeture_depot]"
                           value="<?php echo esc_attr( $fmt_input( $s['date_fermeture_depot'] ?? '' ) ); ?>">
                    <p class="description"><?php esc_html_e( 'Au-delà de cette date : le dépôt ferme et la phase jury s\'ouvre automatiquement.', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
        </table>

        <?php
        // ── Photos ──────────────────────────────────────────────────────
        ?>
        <h2 style="margin-top:24px"><?php esc_html_e( 'Photos', PC_TEXT_DOMAIN ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="poids_max_mo"><?php esc_html_e( 'Poids maximum par photo (Mo)', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="number" min="1" max="100" id="poids_max_mo" name="pc_settings[poids_max_mo]"
                           value="<?php echo esc_attr( $s['poids_max_mo'] ?? 40 ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Taille maximale d\'un fichier photo accepté à l\'upload (1-100). Défaut : 40. Doit rester inférieur à upload_max_filesize / post_max_size du serveur.', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
        </table>

        <h2 style="margin-top:24px"><?php esc_html_e( 'Relances impayés (Phase 2)', PC_TEXT_DOMAIN ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="relance_offset_1"><?php esc_html_e( '1re relance (jours avant clôture)', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="number" min="0" max="60" id="relance_offset_1" name="pc_settings[relance_offset_1]"
                           value="<?php echo esc_attr( $s['relance_offset_1'] ?? 10 ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( '0 = désactivée. Défaut : 10 (J-10).', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="relance_offset_2"><?php esc_html_e( '2e relance (jours avant clôture)', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="number" min="0" max="60" id="relance_offset_2" name="pc_settings[relance_offset_2]"
                           value="<?php echo esc_attr( $s['relance_offset_2'] ?? 5 ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( '0 = désactivée. Défaut : 5 (J-5).', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
        </table>
        ?>
        <h2 style="margin-top:24px"><?php esc_html_e( 'Données personnelles (RGPD)', PC_TEXT_DOMAIN ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="rgpd_texte"><?php esc_html_e( 'Mention RGPD (profil candidat)', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <textarea id="rgpd_texte" name="pc_settings[rgpd_texte]" rows="5" class="large-text"><?php echo esc_textarea( $s['rgpd_texte'] ?? '' ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Texte affiché dans le profil du candidat sous son adresse email.', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
        </table>
        <?php

        printf( '<p class="submit"><input type="submit" class="button-primary" value="%s"></p>', esc_attr__( 'Enregistrer', PC_TEXT_DOMAIN ) );
        echo '</form>';

        // URL webhook
        $webhook_url = add_query_arg( 'pc_stripe_webhook', '1', home_url( '/' ) );
        echo '<hr><h2>' . esc_html__( 'Configuration Stripe', PC_TEXT_DOMAIN ) . '</h2>';
        printf( '<p>%s <code>%s</code></p>',
            esc_html__( 'URL webhook à déclarer dans votre dashboard Stripe :', PC_TEXT_DOMAIN ),
            esc_html( $webhook_url )
        );
        echo '<p>' . esc_html__( 'Événements à écouter : checkout.session.completed, payment_intent.payment_failed', PC_TEXT_DOMAIN ) . '</p>';
        echo '<hr><h2>' . esc_html__( 'Installation des dépendances', PC_TEXT_DOMAIN ) . '</h2>';
        echo '<p><code>cd ' . esc_html( PC_PLUGIN_DIR ) . ' && composer install</code></p>';
        echo '</div>';
    }

    public function render_payments(): void {
        global $wpdb;
        $table  = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $rows   = $wpdb->get_results(
            "SELECT p.*, ph.ratio_type, ph.statut AS photo_statut, u.user_email
             FROM {$table} p
             LEFT JOIN {$wpdb->prefix}pc_photos ph ON ph.id = p.photo_id
             LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
             ORDER BY p.created_at DESC LIMIT 200",
            ARRAY_A
        ) ?: [];

        echo '<div class="wrap"><h1>' . esc_html__( 'Paiements', PC_TEXT_DOMAIN ) . '</h1>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ( [ 'ID', 'Photo', 'Candidat', 'Montant', 'Statut', 'Référence', 'Date' ] as $col )
            echo '<th>' . esc_html( $col ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $montant = number_format( $row['montant_centimes'] / 100, 2, ',', ' ' ) . ' ' . $row['devise'];
            $statuts_labels = [
                'en_attente'    => '⏳ En attente',
                'paiement_recu' => '✅ Reçu',
                'echoue'        => '❌ Échoué',
                'rembourse'     => '↩ Remboursé',
            ];
            printf( '<tr><td>%d</td><td>#%s</td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
                $row['id'],
                str_pad( $row['photo_id'], 5, '0', STR_PAD_LEFT ),
                esc_html( $row['user_email'] ?? '—' ),
                esc_html( $montant ),
                esc_html( $statuts_labels[ $row['statut_paiement'] ] ?? $row['statut_paiement'] ),
                esc_html( $row['reference_externe'] ?? '—' ),
                esc_html( wp_date( get_option( 'date_format' ), strtotime( $row['created_at'] ) ) )
            );
        }
        echo '</tbody></table></div>';
    }

    public function save_settings(): void {
        if ( ! isset( $_POST['pc_settings_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['pc_settings_nonce'], 'pc_save_settings' ) ) return;
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) return;

        $data = $_POST['pc_settings'] ?? [];
        // Sanitize
        $clean = [];
        foreach ( $data as $k => $v ) {
            $clean[ sanitize_key( $k ) ] = is_array( $v ) ? array_map( 'sanitize_text_field', $v ) : sanitize_text_field( $v );
        }
        // Checkboxes non cochées
        foreach ( [ 'depot_actif', 'jury_actif', 'catalogue_actif' ] as $cb ) {
            $clean[ $cb ] = ! empty( $clean[ $cb ] );
        }
        // Phase 2 : bornes des nouveaux réglages
        $clean['relance_offset_1']             = max( 0, min( 60, (int) ( $data['relance_offset_1'] ?? 10 ) ) );
        $clean['relance_offset_2']             = max( 0, min( 60, (int) ( $data['relance_offset_2'] ?? 5 ) ) );
        $clean['poids_max_mo']                 = max( 1, min( 100, (int) ( $data['poids_max_mo'] ?? 40 ) ) );
        $clean['rgpd_texte']                   = isset( $data['rgpd_texte'] ) ? sanitize_textarea_field( $data['rgpd_texte'] ) : PC_Settings::get( 'rgpd_texte', '' );
        // Dates calendrier : normalisées en heure murale du fuseau du site (Y-m-d H:i:s).
        foreach ( [ 'date_ouverture', 'date_fermeture_depot' ] as $dk ) {
            $clean[ $dk ] = PC_Settings::normalize_stored_date( (string) ( $data[ $dk ] ?? '' ) );
        }
        PC_Settings::set( $clean );
        add_action( 'admin_notices', fn() => print '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paramètres enregistrés.', PC_TEXT_DOMAIN ) . '</p></div>' );
    }

    public function stripe_notice(): void {
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) return;
        if ( ! empty( PC_Settings::get( 'stripe_secret_key' ) ) ) return;
        $url = admin_url( 'admin.php?page=photo-contest-settings' );
        echo '<div class="notice notice-warning"><p>';
        printf( esc_html__( 'Photo Contest : %s pour activer les paiements Stripe.', PC_TEXT_DOMAIN ),
            '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Configurez vos clés Stripe', PC_TEXT_DOMAIN ) . '</a>'
        );
        echo '</p></div>';
    }

    public function maybe_upgrade_db(): void {
        PC_Database::maybe_upgrade();
    }
}
