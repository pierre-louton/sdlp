<?php
/**
 * Template : Interface jury
 * Rendu par [photo_contest_jury] via PC_Shortcodes
 * Variables : $jury_user_id, $nom_jury, $total_photos
 */
defined( 'ABSPATH' ) || exit;
?>

<div class="pc-jury-wrap">

  <!-- ── Topbar ─────────────────────────────────────────────────── -->
  <header class="pcj-topbar">
    <?php echo PC_Settings::logo_html( 'pcj-topbar__logo' ); ?>
    <span class="pcj-topbar__role"><?php esc_html_e( 'Jury', 'photo-contest' ); ?></span>
    <span class="pcj-topbar__juré"><?php echo esc_html( $nom_jury ); ?></span>
    <span class="pcj-topbar__sep"></span>
    <span class="pcj-counter">
      <?php esc_html_e( 'Photo', 'photo-contest' ); ?> <strong>—</strong> / <?php echo (int) $total_photos; ?>
    </span>

    <div class="pcj-progress-global">
      <div class="pcj-progress-bar-wrap">
        <div class="pcj-progress-bar-fill" style="width:0%"></div>
      </div>
      <span class="pcj-progress-pct">0%</span>
    </div>

    <span class="pcj-topbar__sep"></span>
    <span class="pcj-kbd-hint">
      <kbd>R</kbd> <?php esc_html_e( 'Retenir', 'photo-contest' ); ?> &nbsp;
      <kbd>X</kbd> <?php esc_html_e( 'Refuser', 'photo-contest' ); ?> &nbsp;
      <kbd>←</kbd><kbd>→</kbd> <?php esc_html_e( 'Naviguer', 'photo-contest' ); ?>
    </span>
  </header>

  <!-- ── Strip miniatures ────────────────────────────────────────── -->
  <div class="pcj-strip">
    <!-- rempli par JavaScript -->
  </div>

  <!-- ── Corps ─────────────────────────────────────────────────────── -->
  <div class="pcj-body">

    <!-- Zone photo -->
    <div class="pcj-viewer">
      <span class="pcj-viewer__num">001</span>

      <div class="pcj-img-wrap">
        <img class="pcj-img chargement"
             src=""
             alt="<?php esc_attr_e( 'Photo en délibération', 'photo-contest' ); ?>">
        <div class="pcj-vote-flash"></div>
      </div>

      <button class="pcj-nav-btn pcj-nav-btn--prev" disabled aria-label="<?php esc_attr_e( 'Précédente', 'photo-contest' ); ?>">&#8592;</button>
      <button class="pcj-nav-btn pcj-nav-btn--next" aria-label="<?php esc_attr_e( 'Suivante', 'photo-contest' ); ?>">&#8594;</button>
    </div>

    <!-- Panneau vote -->
    <aside class="pcj-panel">

      <!-- Métadonnées photo anonymisées -->
      <div class="pcj-section">
        <p class="pcj-section__label"><?php esc_html_e( 'Photo', 'photo-contest' ); ?></p>
        <div class="pcj-meta-row">
          <span class="key">Réf.</span>
          <span class="val">—</span>
        </div>
        <div class="pcj-meta-row">
          <span class="key">Dimensions</span>
          <span class="val">—</span>
        </div>
        <div class="pcj-meta-row">
          <span class="key">Ratio</span>
          <span class="val">—</span>
        </div>
        <div class="pcj-meta-row">
          <span class="key">Poids</span>
          <span class="val">—</span>
        </div>
      </div>

      <!-- Zone de vote -->
      <div class="pcj-section">
        <p class="pcj-section__label"><?php esc_html_e( 'Ma décision', 'photo-contest' ); ?></p>

        <!-- Confirmation vote existant -->
        <div class="pcj-vote-confirme">
          <span class="pcj-vote-confirme__dot"></span>
          <span class="pcj-vote-confirme__txt"></span>
          <button class="pcj-btn-modifier"><?php esc_html_e( 'Modifier', 'photo-contest' ); ?></button>
        </div>

        <!-- Boutons vote -->
        <div class="pcj-vote-btns">
          <button class="pcj-vote-btn pcj-vote-btn--retenu">
            <?php esc_html_e( 'Retenir', 'photo-contest' ); ?>
            <span class="pcj-vote-btn__kbd"><?php esc_html_e( 'touche R', 'photo-contest' ); ?></span>
          </button>
          <button class="pcj-vote-btn pcj-vote-btn--refuse">
            <?php esc_html_e( 'Refuser', 'photo-contest' ); ?>
            <span class="pcj-vote-btn__kbd"><?php esc_html_e( 'touche X', 'photo-contest' ); ?></span>
          </button>
        </div>

        <button class="pcj-btn-passer">
          <?php esc_html_e( 'Passer sans décider', 'photo-contest' ); ?>
        </button>
      </div>

      <!-- Commentaire -->
      <div class="pcj-section">
        <p class="pcj-section__label"><?php esc_html_e( 'Commentaire (facultatif)', 'photo-contest' ); ?></p>
        <textarea class="pcj-comment"
                  placeholder="<?php esc_attr_e( 'Notes pour la délibération…', 'photo-contest' ); ?>"></textarea>
      </div>

      <!-- Stats personnelles -->
      <div class="pcj-stats">
        <p class="pcj-section__label" style="margin-bottom:12px;"><?php esc_html_e( 'Ma session', 'photo-contest' ); ?></p>

        <div class="pcj-stats__row stat-votes">
          <span class="lbl"><?php esc_html_e( 'Photos examinées', 'photo-contest' ); ?></span>
          <span class="val" style="color:var(--pc-text-s)">0 / <?php echo (int) $total_photos; ?></span>
        </div>

        <div class="pcj-stats__row stat-retenus">
          <span class="lbl"><?php esc_html_e( 'Retenues', 'photo-contest' ); ?></span>
          <span class="val" style="color:var(--pc-retenu)">0</span>
        </div>
        <div class="pcj-stat-mini-bar bar-retenus"
             style="background:var(--pc-retenu);width:0%"></div>

        <div class="pcj-stats__row stat-refuses" style="margin-top:8px">
          <span class="lbl"><?php esc_html_e( 'Refusées', 'photo-contest' ); ?></span>
          <span class="val" style="color:var(--pc-refuse)">0</span>
        </div>
        <div class="pcj-stat-mini-bar bar-refuses"
             style="background:var(--pc-refuse);width:0%"></div>
      </div>

    </aside>
  </div>

  <!-- ── Toasts ────────────────────────────────────────────────────── -->
  <div class="pcj-toast-container"></div>

</div>
