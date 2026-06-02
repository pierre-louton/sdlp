<?php
/**
 * Template : Interface catalogue éditeur
 * Rendu par [photo_contest_catalogue]
 * Variables : $nb_total, $nb_inclus, $nb_payes, $edition, $titre_catalogue
 */
defined( 'ABSPATH' ) || exit;
?>

<div class="pc-cat-wrap">

  <!-- ── Topbar ─────────────────────────────────────────────────────── -->
  <header class="pcc-topbar">
    <?php echo PC_Settings::logo_html( 'pcc-logo' ); ?>
    <span class="pcc-badge"><?php esc_html_e( 'Catalogue', 'photo-contest' ); ?></span>
    <span class="pcc-sep"></span>
    <span class="pcc-edition">
      <?php echo esc_html( $titre_catalogue ?: __( 'Sans titre', 'photo-contest' ) ); ?>
      <?php if ( $edition ) : ?>
        &nbsp;—&nbsp;<?php echo esc_html( $edition ); ?>
      <?php endif; ?>
    </span>

    <!-- Stats -->
    <div class="pcc-stats">
      <div class="pcc-stat pcc-stat--total">
        <span class="pcc-stat__n">0</span>
        <span class="pcc-stat__l"><?php esc_html_e( 'photos', 'photo-contest' ); ?></span>
      </div>
      <div class="pcc-stat pcc-stat--inclus" style="color:var(--gold)">
        <span class="pcc-stat__n">0</span>
        <span class="pcc-stat__l"><?php esc_html_e( 'incluses', 'photo-contest' ); ?></span>
      </div>
      <div class="pcc-stat pcc-stat--payes" style="color:var(--green)">
        <span class="pcc-stat__n">0</span>
        <span class="pcc-stat__l"><?php esc_html_e( 'payées', 'photo-contest' ); ?></span>
      </div>

      <span class="pcc-sep"></span>

      <!-- Boutons export -->
      <div class="pcc-export-btns">
        <button class="pcc-btn pcc-btn--pdf">
          <?php esc_html_e( 'Export PDF', 'photo-contest' ); ?>
        </button>
        <button class="pcc-btn pcc-btn--csv">CSV</button>
        <button class="pcc-btn pcc-btn--json">JSON</button>
      </div>
    </div>
  </header>

  <!-- ── Corps ──────────────────────────────────────────────────────── -->
  <div class="pcc-body">

    <!-- Grille des planches -->
    <main class="pcc-main">
      <div class="pcc-section-hdr">
        <h2><?php esc_html_e( 'Planches', 'photo-contest' ); ?></h2>
        <span class="nb">0 photos</span>
      </div>

      <!-- grille remplie par JS -->
      <div class="pcc-grid"></div>
    </main>

    <!-- Panneau fiche droit -->
    <aside class="pcc-panel">
      <div class="pcc-panel--vide">
        <span class="pcc-panel--vide__icon">&#9744;</span>
        <p class="pcc-panel--vide__txt">
          <?php esc_html_e( 'Cliquez sur une planche pour éditer sa fiche.', 'photo-contest' ); ?><br>
          <span style="font-size:9px;letter-spacing:.05em;text-transform:uppercase;opacity:.6">
            Ctrl+S pour sauvegarder
          </span>
        </p>
      </div>
    </aside>

  </div>

  <!-- ── Overlay export ────────────────────────────────────────────── -->
  <div class="pcc-export-overlay">
    <div class="pcc-export-spinner"></div>
    <p class="pcc-export-overlay__titre"><?php esc_html_e( 'Génération en cours…', 'photo-contest' ); ?></p>
    <p class="pcc-export-sub"></p>
  </div>

  <!-- ── Toasts ────────────────────────────────────────────────────── -->
  <div class="pcc-toasts"></div>

</div>
