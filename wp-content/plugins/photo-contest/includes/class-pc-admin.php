<?php defined( 'ABSPATH' ) || exit;
class PC_Admin {
    private static ?self $instance = null;
    public static function get_instance(): self { self::$instance ??= new self(); return self::$instance; }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_init',            [ $this, 'maybe_upgrade_db' ] );
        add_action( 'admin_init',            [ $this, 'save_settings' ] );
        add_action( 'admin_notices',         [ $this, 'stripe_notice' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
    }

    // ── Scripts admin ────────────────────────────────────────────────────
    public function enqueue_admin_scripts( string $hook ): void {
        if ( ! str_contains( $hook, 'photo-contest' ) ) return;
        wp_enqueue_media(); // médiathèque WP
        wp_add_inline_script( 'jquery-core', $this->media_picker_js() );
    }

    private function media_picker_js(): string {
        return <<<JS
jQuery(function($){
    $(document).on('click', '.pc-media-btn', function(e){
        e.preventDefault();
        var btn      = $(this);
        var targetId = btn.data('target');
        var previewId= btn.data('preview');
        var mimeType = btn.data('mime') || 'image';
        var frame = wp.media({
            title:   btn.data('title') || 'Choisir un fichier',
            button:  { text: 'Utiliser ce fichier' },
            library: { type: mimeType },
            multiple: false,
        });
        frame.on('select', function(){
            var att = frame.state().get('selection').first().toJSON();
            $('#' + targetId).val(att.url);
            if (previewId) {
                var prev = $('#' + previewId);
                if (mimeType === 'application/pdf') {
                    prev.html('<a href="' + att.url + '" target="_blank" style="color:#c49a3c;font-size:13px;">📄 ' + att.filename + '</a>');
                } else {
                    prev.html('<img src="' + att.url + '" style="max-height:60px;margin-top:8px;border-radius:4px;">');
                }
            }
        });
        frame.open();
    });
    $(document).on('click', '.pc-media-clear', function(e){
        e.preventDefault();
        $('#' + $(this).data('target')).val('');
        var prev = $(this).data('preview');
        if (prev) $('#' + prev).html('');
    });
});
JS;
    }

    // ── Menus ────────────────────────────────────────────────────────────
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

    // ── Dashboard ────────────────────────────────────────────────────────
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

    // ── Paramètres ───────────────────────────────────────────────────────
    public function render_settings(): void {
        $s = PC_Settings::all();
        echo '<div class="wrap"><h1>' . esc_html__( 'Paramètres du concours', PC_TEXT_DOMAIN ) . '</h1>';
        echo '<form method="post"><table class="form-table">';
        wp_nonce_field( 'pc_save_settings', 'pc_settings_nonce' );

        // ── Champs texte simples ─────────────────────────────────────────
        $fields_texte = [
            'nom_concours'              => __( 'Nom du concours', PC_TEXT_DOMAIN ),
            'edition'                   => __( 'Édition', PC_TEXT_DOMAIN ),
            'quota_photos'              => __( 'Quota photos / candidat', PC_TEXT_DOMAIN ),
            'montant_participation_cts' => __( 'Montant participation (centimes)', PC_TEXT_DOMAIN ),
            'devise'                    => __( 'Devise (ex: EUR)', PC_TEXT_DOMAIN ),
            'stripe_publishable_key'    => __( 'Stripe — Clé publique (pk_...)', PC_TEXT_DOMAIN ),
            'stripe_secret_key'         => __( 'Stripe — Clé secrète (sk_...)', PC_TEXT_DOMAIN ),
            'stripe_webhook_secret'     => __( 'Stripe — Secret webhook (whsec_...)', PC_TEXT_DOMAIN ),
            'login_slug'                => __( 'URL de connexion (ex: connexion)', PC_TEXT_DOMAIN ),
            'catalogue_titre'           => __( 'Titre du catalogue', PC_TEXT_DOMAIN ),
            'catalogue_isbn'            => __( 'ISBN', PC_TEXT_DOMAIN ),
        ];

        foreach ( $fields_texte as $key => $label ) {
            $val  = $s[ $key ] ?? '';
            $type = ( str_contains( $key, 'secret' ) || str_contains( $key, '_key' ) ) ? 'password' : 'text';
            printf(
                '<tr><th><label for="%1$s">%2$s</label></th><td><input class="regular-text" type="%4$s" id="%1$s" name="pc_settings[%1$s]" value="%3$s"></td></tr>',
                esc_attr( $key ), esc_html( $label ), esc_attr( $val ), esc_attr( $type )
            );
        }

        // ── Logo — sélecteur médiathèque ────────────────────────────────
        $logo_url = $s['logo_url'] ?? '';
        echo '<tr><th><label>' . esc_html__( 'Logo du concours', PC_TEXT_DOMAIN ) . '</label></th><td>';
        printf( '<input class="regular-text" type="text" id="logo_url" name="pc_settings[logo_url]" value="%s" style="width:320px;">', esc_attr( $logo_url ) );
        echo ' <button type="button" class="button pc-media-btn"
                data-target="logo_url"
                data-preview="logo_preview"
                data-mime="image"
                data-title="' . esc_attr__( 'Choisir le logo', PC_TEXT_DOMAIN ) . '">'
            . esc_html__( 'Choisir…', PC_TEXT_DOMAIN ) . '</button>';
        echo ' <button type="button" class="button pc-media-clear" data-target="logo_url" data-preview="logo_preview">'
            . esc_html__( 'Effacer', PC_TEXT_DOMAIN ) . '</button>';
        echo '<div id="logo_preview" style="margin-top:8px;">';
        if ( $logo_url ) echo '<img src="' . esc_url( $logo_url ) . '" style="max-height:60px;border-radius:4px;">';
        echo '</div></td></tr>';

        // ── Règlement PDF — sélecteur médiathèque ───────────────────────
        $reglement_url = $s['reglement_url'] ?? '';
        echo '<tr><th><label>' . esc_html__( 'Règlement PDF du concours', PC_TEXT_DOMAIN ) . '</label></th><td>';
        printf( '<input class="regular-text" type="text" id="reglement_url" name="pc_settings[reglement_url]" value="%s" style="width:320px;">', esc_attr( $reglement_url ) );
        echo ' <button type="button" class="button pc-media-btn"
                data-target="reglement_url"
                data-preview="reglement_preview"
                data-mime="application/pdf"
                data-title="' . esc_attr__( 'Choisir le règlement PDF', PC_TEXT_DOMAIN ) . '">'
            . esc_html__( 'Choisir…', PC_TEXT_DOMAIN ) . '</button>';
        echo ' <button type="button" class="button pc-media-clear" data-target="reglement_url" data-preview="reglement_preview">'
            . esc_html__( 'Effacer', PC_TEXT_DOMAIN ) . '</button>';
        echo '<div id="reglement_preview" style="margin-top:8px;">';
        if ( $reglement_url ) {
            $filename = basename( $reglement_url );
            echo '<a href="' . esc_url( $reglement_url ) . '" target="_blank" style="color:#c49a3c;font-size:13px;">📄 ' . esc_html( $filename ) . '</a>';
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__( 'Ce fichier sera proposé en téléchargement aux candidats lors de leur inscription. Son téléchargement sera obligatoire avant de pouvoir cocher "J\'ai lu le règlement".', PC_TEXT_DOMAIN ) . '</p>';
        echo '</td></tr>';

        // ── Mode Stripe ─────────────────────────────────────────────────
        $stripe_mode = $s['stripe_mode'] ?? 'test';
        printf( '<tr><th>%s</th><td>
            <label><input type="radio" name="pc_settings[stripe_mode]" value="test" %s> Test</label> &nbsp;
            <label><input type="radio" name="pc_settings[stripe_mode]" value="live" %s> Live</label>
            </td></tr>',
            esc_html__( 'Mode Stripe', PC_TEXT_DOMAIN ),
            checked( $stripe_mode, 'test', false ),
            checked( $stripe_mode, 'live', false )
        );

        // ── Checkboxes activations ───────────────────────────────────────
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
        printf( '<p class="submit"><input type="submit" class="button-primary" value="%s"></p>', esc_attr__( 'Enregistrer', PC_TEXT_DOMAIN ) );
        echo '</form>';

        // ── Infos techniques ─────────────────────────────────────────────
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

    // ── Paiements ────────────────────────────────────────────────────────
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

    // ── Sauvegarde settings ──────────────────────────────────────────────
    public function save_settings(): void {
        if ( ! isset( $_POST['pc_settings_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['pc_settings_nonce'], 'pc_save_settings' ) ) return;
        if ( ! current_user_can( 'pc_manage_contest_settings' ) ) return;

        $data  = $_POST['pc_settings'] ?? [];
        $clean = [];
        foreach ( $data as $k => $v ) {
            $clean[ sanitize_key( $k ) ] = is_array( $v )
                ? array_map( 'sanitize_text_field', $v )
                : sanitize_text_field( $v );
        }
        // URLs — on sanitise en URL
        foreach ( [ 'logo_url', 'reglement_url' ] as $url_key ) {
            if ( isset( $clean[ $url_key ] ) ) {
                $clean[ $url_key ] = esc_url_raw( $clean[ $url_key ] );
            }
        }
        // Checkboxes non cochées = false
        foreach ( [ 'depot_actif', 'jury_actif', 'catalogue_actif' ] as $cb ) {
            $clean[ $cb ] = ! empty( $clean[ $cb ] );
        }
        PC_Settings::set( $clean );
        add_action( 'admin_notices', fn() => print '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paramètres enregistrés.', PC_TEXT_DOMAIN ) . '</p></div>' );
    }

    // ── Notice Stripe ────────────────────────────────────────────────────
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
