<?php
defined( 'ABSPATH' ) || exit;

/**
 * Module Catalogue — Composition et exports.
 *
 * Alimentation auto quand statut → "paiement_recu"
 * CRUD fiches, toggle inclusion, réordonnancement
 * Export CSV, JSON, PDF (via mPDF : composer require mpdf/mpdf)
 */
class PC_Catalogue {

    private static ?self $instance = null;

    public static function get_instance(): self {
        self::$instance ??= new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'pc_photo_statut_changed',     [ $this, 'on_statut_changed' ], 10, 3 );
        add_action( 'wp_ajax_pc_catalogue_get',        [ $this, 'ajax_get' ] );
        add_action( 'wp_ajax_pc_catalogue_save_fiche', [ $this, 'ajax_save_fiche' ] );
        add_action( 'wp_ajax_pc_catalogue_toggle',     [ $this, 'ajax_toggle' ] );
        add_action( 'wp_ajax_pc_catalogue_reorder',    [ $this, 'ajax_reorder' ] );
        add_action( 'wp_ajax_pc_catalogue_export',     [ $this, 'ajax_export' ] );
    }

    // ── Alimentation automatique ──────────────────────────────────────

    public function on_statut_changed( int $photo_id, string $nouveau, string $ancien ): void {
        if ( $nouveau === 'paiement_recu' ) $this->creer_entree_si_absente( $photo_id );
    }

    public function creer_entree_si_absente( int $photo_id ): void {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_CATALOGUE );
        $photo = PC_Photos::get_instance()->get_photo( $photo_id );
        if ( ! $photo ) return;
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE photo_id = %d", $photo_id ) ) ) return;
        $max = (int) $wpdb->get_var( "SELECT MAX(ordre_catalogue) FROM {$table}" );
        $wpdb->insert( $table, [
            'photo_id' => $photo_id, 'user_id' => $photo['user_id'],
            'titre_catalogue' => '', 'biographie' => '', 'tirage' => '',
            'ordre_catalogue' => $max + 1, 'inclus_catalogue' => 1, 'notes_editeur' => '',
        ] );
        PC_Photos::get_instance()->update_statut( $photo_id, 'au_catalogue' );
    }

    // ── Lecture ───────────────────────────────────────────────────────

    public function get_items(): array {
        global $wpdb;
        $tc = PC_Database::table( PC_Database::TABLE_CATALOGUE );
        $tp = PC_Database::table( PC_Database::TABLE_PHOTOS );
        $tt = PC_Database::table( PC_Database::TABLE_PAYMENTS );
        $rows = $wpdb->get_results(
            "SELECT c.*, p.largeur_px, p.hauteur_px, p.ratio_type, p.statut, p.nom_fichier, p.chemin_fichier,
                    COALESCE(pay.statut_paiement,'en_attente') AS statut_paiement
             FROM {$tc} c
             JOIN {$tp} p ON p.id = c.photo_id
             LEFT JOIN {$tt} pay ON pay.photo_id = c.photo_id AND pay.statut_paiement = 'paiement_recu'
             ORDER BY c.ordre_catalogue ASC",
            ARRAY_A
        ) ?: [];
        return array_map( function( $row ) {
            $row['url_thumb'] = $this->get_photo_url( (int) $row['photo_id'], 'thumb' );
            $row['url_full']  = $this->get_photo_url( (int) $row['photo_id'], 'full' );
            return $row;
        }, $rows );
    }

    // ── Écriture ──────────────────────────────────────────────────────

    public function save_fiche( int $photo_id, array $data ): bool {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_CATALOGUE );
        $clean = [];
        foreach ( [ 'titre_catalogue', 'biographie', 'tirage', 'notes_editeur' ] as $f ) {
            if ( array_key_exists( $f, $data ) ) $clean[ $f ] = sanitize_textarea_field( $data[ $f ] );
        }
        return ! empty( $clean ) && (bool) $wpdb->update( $table, $clean, [ 'photo_id' => $photo_id ] );
    }

    public function toggle_inclusion( int $photo_id, bool $inclus ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            PC_Database::table( PC_Database::TABLE_CATALOGUE ),
            [ 'inclus_catalogue' => $inclus ? 1 : 0 ], [ 'photo_id' => $photo_id ]
        );
    }

    public function save_ordre( array $photo_ids ): void {
        global $wpdb;
        $table = PC_Database::table( PC_Database::TABLE_CATALOGUE );
        foreach ( $photo_ids as $ordre => $id )
            $wpdb->update( $table, [ 'ordre_catalogue' => (int) $ordre + 1 ], [ 'photo_id' => (int) $id ] );
    }

    // ── Export CSV ────────────────────────────────────────────────────

    public function export_csv(): string {
        $items = array_values( array_filter( $this->get_items(), fn($i) => (bool) $i['inclus_catalogue'] ) );
        if ( empty( $items ) ) return '';

        $titre     = PC_Settings::get( 'catalogue_titre', '' );
        $sous      = PC_Settings::get( 'catalogue_sous_titre', '' );
        $edition   = PC_Settings::get( 'edition', '' );

        // En-tête catalogue
        $lignes = [];
        if ( $titre )  $lignes[] = 'Titre;'     . $this->csv_esc( $titre );
        if ( $sous )   $lignes[] = 'Sous-titre;' . $this->csv_esc( $sous );
        if ( $edition ) $lignes[] = 'Édition;'   . $this->csv_esc( $edition );
        if ( $lignes ) $lignes[] = ''; // ligne vide de séparation

        $lignes[] = implode( ';', [ 'Numéro','Référence','Titre','Biographie','Tirage','Dimensions','Ratio','Paiement' ] );
        foreach ( $items as $k => $item ) {
            $lignes[] = implode( ';', array_map( [ $this, 'csv_esc' ], [
                $k + 1,
                '#' . str_pad( $item['photo_id'], 5, '0', STR_PAD_LEFT ),
                $item['titre_catalogue'] ?: '—',
                preg_replace( '/\s+/', ' ', stripslashes( $item['biographie'] ) ) ?: '—',
                stripslashes( $item['tirage'] ) ?: '—',
                $item['largeur_px'] . ' × ' . $item['hauteur_px'] . ' px',
                $item['ratio_type'] === '3_2' ? '3:2 Paysage' : '2:3 Portrait',
                $item['statut_paiement'] === 'paiement_recu' ? 'Payé' : 'En attente',
            ] ) );
        }
        return "\xEF\xBB\xBF" . implode( "\n", $lignes );
    }

    private function csv_esc( string $v ): string {
        $v = str_replace( '"', '""', $v );
        return ( str_contains( $v, ';' ) || str_contains( $v, '"' ) || str_contains( $v, "\n" ) ) ? '"' . $v . '"' : $v;
    }

    // ── Export JSON ───────────────────────────────────────────────────

    public function export_json(): string {
        $items = array_values( array_filter( $this->get_items(), fn($i) => (bool) $i['inclus_catalogue'] ) );
        $planches = array_map( fn($item, $i) => [
            'numero'     => $i + 1,
            'reference'  => '#' . str_pad( $item['photo_id'], 5, '0', STR_PAD_LEFT ),
            'titre'      => $item['titre_catalogue'] ?: null,
            'biographie' => $item['biographie'] ?: null,
            'tirage'     => $item['tirage'] ?: null,
            'image'      => [
                'largeur' => (int) $item['largeur_px'],
                'hauteur' => (int) $item['hauteur_px'],
                'ratio'   => $item['ratio_type'],
                'fichier' => basename( $item['nom_fichier'] ),
            ],
            'paiement' => $item['statut_paiement'],
        ], $items, array_keys( $items ) );

        return wp_json_encode( [
            'catalogue'   => [
                'titre'      => PC_Settings::get( 'catalogue_titre' ),
                'sous_titre' => PC_Settings::get( 'catalogue_sous_titre' ),
                'edition'    => PC_Settings::get( 'edition' ),
                'isbn'       => PC_Settings::get( 'catalogue_isbn' ),
                'date_export'=> current_time( 'c' ),
            ],
            'nb_planches' => count( $planches ),
            'planches'    => $planches,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    // ── Export PDF (mPDF) ─────────────────────────────────────────────

    public function export_pdf(): string {
        $autoload = PC_PLUGIN_DIR . 'vendor/autoload.php';
        if ( ! file_exists( $autoload ) )
            throw new \RuntimeException( __( 'mPDF non installé. Lancez : composer require mpdf/mpdf dans le dossier du plugin.', 'photo-contest' ) );
        require_once $autoload;

        $items       = array_values( array_filter( $this->get_items(), fn($i) => (bool) $i['inclus_catalogue'] ) );
        $titre       = PC_Settings::get( 'catalogue_titre', 'Catalogue' );
        $sous_titre  = PC_Settings::get( 'catalogue_sous_titre', '' );
        $edition     = PC_Settings::get( 'edition', '' );
        $isbn        = PC_Settings::get( 'catalogue_isbn', '' );
        $nom_concours = PC_Settings::get( 'nom_concours', 'SDLP' );
        $logo_url    = PC_Settings::get( 'logo_url', '' );

        // Logo en-tête : convertir URL en chemin absolu si possible
        $logo_html = '';
        if ( $logo_url ) {
            $logo_path = str_replace( home_url( '/' ), ABSPATH, $logo_url );
            if ( file_exists( $logo_path ) ) {
                $logo_html = '<img src="' . esc_attr( $logo_path ) . '" style="height:10mm;width:auto;">';
            } else {
                $logo_html = '<span style="font-size:12pt;font-weight:bold;color:#0E2340;letter-spacing:1px;">' . esc_html( $nom_concours ) . '</span>';
            }
        } else {
            $logo_html = '<span style="font-size:12pt;font-weight:bold;color:#0E2340;letter-spacing:1px;">' . esc_html( $nom_concours ) . '</span>';
        }

        // En-tête et pied de page mPDF
        $header_html = '
        <table width="100%" style="border-bottom:0.5pt solid #C49A3C;padding-bottom:3mm;">
          <tr>
            <td style="vertical-align:middle;">' . $logo_html . '</td>
            <td style="text-align:right;vertical-align:middle;font-size:7pt;color:#888;font-family:Arial,sans-serif;">'
              . esc_html( $titre ) . ( $edition ? ' — ' . esc_html( $edition ) : '' ) .
            '</td>
          </tr>
        </table>';

        $footer_html = '
        <table width="100%" style="border-top:0.5pt solid #C49A3C;padding-top:2mm;">
          <tr>
            <td style="font-size:7pt;color:#bbb;font-family:Arial,sans-serif;">' . esc_html( $nom_concours ) . '</td>
            <td style="text-align:right;font-size:7pt;color:#bbb;font-family:Arial,sans-serif;">{PAGENO} / {nbpg}</td>
          </tr>
        </table>';

        $mpdf = new \Mpdf\Mpdf( [
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_left'   => 15,
            'margin_right'  => 15,
            'margin_top'    => 28,
            'margin_bottom' => 20,
            'margin_header' => 5,
            'margin_footer' => 5,
            'tempDir'       => sys_get_temp_dir() . '/mpdf',
        ] );

        $mpdf->SetHTMLHeader( $header_html );
        $mpdf->SetHTMLFooter( $footer_html );

        // Couverture (sans en-tête/pied)
        $mpdf->AddPage();
        $mpdf->SetHTMLHeader( '' );
        $mpdf->SetHTMLFooter( '' );
        $mpdf->WriteHTML( '
        <div style="text-align:center;margin-top:100px;font-family:Georgia,serif;">
            ' . ( $logo_url && file_exists( str_replace( home_url( '/' ), ABSPATH, $logo_url ) )
                ? '<img src="' . esc_attr( str_replace( home_url( '/' ), ABSPATH, $logo_url ) ) . '" style="height:20mm;width:auto;margin-bottom:12mm;"><br>'
                : '' ) . '
            <h1 style="font-size:28pt;font-weight:normal;color:#0E2340;letter-spacing:3px;margin-bottom:4mm;">' . esc_html( $titre ) . '</h1>
            ' . ( $sous_titre ? '<p style="font-size:14pt;color:#C49A3C;margin-bottom:4mm;">' . esc_html( $sous_titre ) . '</p>' : '' ) . '
            ' . ( $edition ? '<p style="font-size:12pt;color:#555;margin-top:8mm;">' . esc_html( $edition ) . '</p>' : '' ) . '
            ' . ( $isbn ? '<p style="font-size:8pt;color:#999;margin-top:40px;font-family:Courier,monospace;">ISBN ' . esc_html( $isbn ) . '</p>' : '' ) . '
        </div>' );

        // Rétablir en-tête/pied pour les planches
        $mpdf->SetHTMLHeader( $header_html );
        $mpdf->SetHTMLFooter( $footer_html );

        // Pré-charger les noms des candidats
        global $wpdb;
        $profiles_table = PC_Database::table( PC_Database::TABLE_PROFILES );

        // Planches
        foreach ( $items as $i => $item ) {
            $mpdf->AddPage();

            // Nom candidat depuis profiles
            $profile = $wpdb->get_row( $wpdb->prepare(
                "SELECT prenom, nom FROM {$profiles_table} WHERE user_id = %d",
                $item['user_id']
            ), ARRAY_A );
            $nom_candidat = $profile
                ? trim( $profile['prenom'] . ' ' . $profile['nom'] )
                : get_userdata( $item['user_id'] )->display_name;

            $img_html = '';
            if ( ! empty( $item['chemin_fichier'] ) && file_exists( $item['chemin_fichier'] ) ) {
                $img_html = '<img src="' . esc_attr( $item['chemin_fichier'] ) . '" style="max-width:100%;max-height:175mm;display:block;">';
            }

            $ref    = '#' . str_pad( $item['photo_id'], 5, '0', STR_PAD_LEFT );
            $orient = $item['ratio_type'] === '3_2' ? 'Paysage' : 'Portrait';
            $dims   = $item['largeur_px'] . ' × ' . $item['hauteur_px'] . ' px';

            $mpdf->WriteHTML( '
            <div style="font-family:Georgia,serif;padding:6mm 0;">
              <table width="100%" cellpadding="0" cellspacing="0"><tr>
                <td width="63%" style="vertical-align:middle;padding-right:10mm;">' . $img_html . '</td>
                <td width="37%" style="vertical-align:top;padding-left:6mm;border-left:0.5pt solid #C49A3C;">

                  <p style="font-size:7pt;color:#C49A3C;font-family:Arial,sans-serif;letter-spacing:2px;text-transform:uppercase;margin-bottom:5mm;">
                    Planche ' . sprintf( '%02d', $i + 1 ) . ' &nbsp;—&nbsp; ' . esc_html( $ref ) . '
                  </p>

                  <h2 style="font-size:13pt;font-weight:normal;color:#0E2340;margin-bottom:3mm;line-height:1.3;">
                    ' . esc_html( $item['titre_catalogue'] ?: ( 'Planche ' . ( $i + 1 ) ) ) . '
                  </h2>

                  <p style="font-size:9pt;color:#555;font-family:Arial,sans-serif;margin-bottom:5mm;font-style:italic;">
                    ' . esc_html( $nom_candidat ) . '
                  </p>

                  ' . ( $item['biographie'] ? '
                  <p style="font-size:8.5pt;color:#333;line-height:1.65;margin-bottom:5mm;">
                    ' . nl2br( esc_html( stripslashes( $item['biographie'] ) ) ) . '
                  </p>' : '' ) . '

                  ' . ( $item['tirage'] ? '
                  <p style="font-size:8pt;color:#666;font-family:Arial,sans-serif;margin-bottom:3mm;">
                    <span style="color:#aaa;font-size:7pt;text-transform:uppercase;letter-spacing:1px;">Tirage</span><br>
                    ' . esc_html( stripslashes( $item['tirage'] ) ) . '
                  </p>' : '' ) . '

                  <p style="font-size:7pt;color:#bbb;font-family:Arial,sans-serif;margin-top:6mm;">
                    ' . esc_html( $dims ) . ' &nbsp;—&nbsp; ' . esc_html( $orient ) . '
                  </p>

                </td>
              </tr></table>
            </div>' );
        }

        return $mpdf->Output( '', 'S' );
    }

    // ── URL sécurisée ─────────────────────────────────────────────────

    private function get_photo_url( int $photo_id, string $taille = 'full' ): string {
        $token = wp_create_nonce( "pc_photo_{$photo_id}_" . get_current_user_id() );
        return add_query_arg( [ 'pc_photo' => $photo_id, 'taille' => $taille, 'token' => $token ], home_url( '/' ) );
    }

    // ── AJAX ──────────────────────────────────────────────────────────

    public function ajax_get(): void {
        check_ajax_referer( 'pc_catalogue_get_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_view_catalogue_panel' ) ) wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'photo-contest' ) ] );
        wp_send_json_success( [ 'items' => $this->get_items() ] );
    }

    public function ajax_save_fiche(): void {
        check_ajax_referer( 'pc_catalogue_save_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_edit_catalogue_item' ) ) wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'photo-contest' ) ] );
        $photo_id = (int) ( $_POST['photo_id'] ?? 0 );
        if ( ! $photo_id ) wp_send_json_error( [ 'message' => __( 'Photo invalide.', 'photo-contest' ) ] );
        $this->save_fiche( $photo_id, $_POST ) ? wp_send_json_success() : wp_send_json_error( [ 'message' => __( 'Erreur.', 'photo-contest' ) ] );
    }

    public function ajax_toggle(): void {
        check_ajax_referer( 'pc_catalogue_save_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_edit_catalogue_item' ) ) wp_send_json_error();
        $this->toggle_inclusion( (int) ( $_POST['photo_id'] ?? 0 ), (bool) ( $_POST['inclus_catalogue'] ?? 0 ) )
            ? wp_send_json_success() : wp_send_json_error();
    }

    public function ajax_reorder(): void {
        check_ajax_referer( 'pc_catalogue_save_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_reorder_catalogue' ) ) wp_send_json_error();
        $this->save_ordre( array_map( 'intval', $_POST['ids'] ?? [] ) );
        wp_send_json_success();
    }

    public function ajax_export(): void {
        check_ajax_referer( 'pc_catalogue_export_nonce', 'nonce' );
        if ( ! current_user_can( 'pc_export_catalogue_csv' ) ) wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'photo-contest' ) ] );
        $format = sanitize_key( $_POST['format'] ?? 'csv' );
        if ( $format === 'pdf' ) {
            try {
                $pdf = $this->export_pdf();
                header( 'Content-Type: application/pdf' );
                header( 'Content-Disposition: attachment; filename="catalogue.pdf"' );
                header( 'Content-Length: ' . strlen( $pdf ) );
                echo $pdf; exit;
            } catch ( \Exception $e ) {
                wp_send_json_error( [ 'message' => $e->getMessage() ] );
            }
        }
        $contenu = $format === 'json' ? $this->export_json() : $this->export_csv();
        wp_send_json_success( [ 'contenu' => $contenu ] );
    }
}
