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
            'jury_actif'      => __( 'Phase jury active', PC_TEXT_DOMAIN ),
            'catalogue_actif' => __( 'Catalogue actif', PC_TEXT_DOMAIN ),
        ];
        foreach ( $checkboxes as $key => $label ) {
            printf( '<tr><th>%s</th><td><input type="checkbox" name="pc_settings[%s]" value="1" %s></td></tr>',
                esc_html( $label ), esc_attr( $key ), checked( ! empty( $s[ $key ] ), true, false )
            );
        }

        echo '</table>';

        // ── Phase 2 : envoi emails par lots ─────────────────────────────
        ?>
        <h2 style="margin-top:24px"><?php esc_html_e( 'Envoi d\'emails (Phase 2)', PC_TEXT_DOMAIN ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="email_batch_size"><?php esc_html_e( 'Taille de lot', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="number" min="1" max="100" id="email_batch_size" name="pc_settings[email_batch_size]"
                           value="<?php echo esc_attr( $s['email_batch_size'] ?? 20 ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( 'Nombre d\'emails envoyés par tick cron (1-100). Défaut : 20.', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="email_batch_interval_minutes"><?php esc_html_e( 'Intervalle (minutes)', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="number" min="1" max="60" id="email_batch_interval_minutes" name="pc_settings[email_batch_interval_minutes]"
                           value="<?php echo esc_attr( $s['email_batch_interval_minutes'] ?? 5 ); ?>" class="small-text">
                    <p class="description">
                        <?php
                        $size     = (int) ( $s['email_batch_size'] ?? 20 );
                        $itv      = (int) ( $s['email_batch_interval_minutes'] ?? 5 );
                        $per_hour = $itv > 0 ? (int) round( $size * ( 60 / $itv ) ) : 0;
                        printf(
                            /* translators: %d nb emails par heure */
                            esc_html__( 'Avec ces réglages : jusqu\'à %d emails par heure.', PC_TEXT_DOMAIN ),
                            $per_hour
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2 style="margin-top:24px"><?php esc_html_e( 'Relances impayés (Phase 2)', PC_TEXT_DOMAIN ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="relance_jours"><?php esc_html_e( 'Délai entre relances (jours)', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="number" min="0" max="30" id="relance_jours" name="pc_settings[relance_jours]"
                           value="<?php echo esc_attr( $s['relance_jours'] ?? 5 ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( '0 = relances désactivées. Défaut : 5.', PC_TEXT_DOMAIN ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="relance_max"><?php esc_html_e( 'Nombre maximum de relances', PC_TEXT_DOMAIN ); ?></label></th>
                <td>
                    <input type="number" min="0" max="5" id="relance_max" name="pc_settings[relance_max]"
                           value="<?php echo esc_attr( $s['relance_max'] ?? 2 ); ?>" class="small-text">
                    <p class="description"><?php esc_html_e( '0 = relances désactivées. Défaut : 2.', PC_TEXT_DOMAIN ); ?></p>
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
        $clean['email_batch_size']             = max( 1, min( 100, (int) ( $data['email_batch_size'] ?? 20 ) ) );
        $clean['email_batch_interval_minutes'] = max( 1, min( 60,  (int) ( $data['email_batch_interval_minutes'] ?? 5 ) ) );
        $clean['relance_jours']                = max( 0, min( 30,  (int) ( $data['relance_jours'] ?? 5 ) ) );
        $clean['relance_max']                  = max( 0, min( 5,   (int) ( $data['relance_max'] ?? 2 ) ) );
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
