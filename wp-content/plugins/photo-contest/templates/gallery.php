<?php
/**
 * Template : Interface galerie candidat
 * Rendu par [photo_contest_gallery] via PC_Shortcodes
 *
 * Variables disponibles (passées via extract()) :
 *   $user_id   int
 *   $profil    array
 *   $quota_max int
 *   $statuts   array  (slug => libellé)
 */
defined( 'ABSPATH' ) || exit;

$nom_complet = trim( ( $profil['prenom'] ?? '' ) . ' ' . ( $profil['nom'] ?? '' ) );
if ( ! $nom_complet ) {
    $nom_complet = wp_get_current_user()->display_name;
}
?>

<div class="pc-gallery-wrap">

  <!-- ── Barre supérieure ──────────────────────────────────────────── -->
  <header class="pc-topbar">
    <?php echo PC_Settings::logo_html( 'pc-topbar__logo' ); ?>
    <span class="pc-topbar__sep"></span>
    <span class="pc-topbar__nom"><?php echo esc_html( $nom_complet ); ?></span>
    <?php $reglement_url = PC_Settings::get( 'reglement_url', '' ); ?>
    <?php if ( $reglement_url ) : ?>
      <a href="<?php echo esc_url( $reglement_url ); ?>" class="pc-topbar__reglement"
         target="_blank" rel="noopener">
        <?php esc_html_e( 'Règlement', PC_TEXT_DOMAIN ); ?>
      </a>
    <?php endif; ?>
    <a href="<?php echo esc_url( wp_logout_url( home_url( '/' . PC_Settings::get( 'login_slug', 'connexion' ) . '/' ) ) ); ?>"
       class="pc-topbar__logout"
       title="<?php esc_attr_e( 'Déconnexion', 'photo-contest' ); ?>">
      <?php esc_html_e( 'Déconnexion', 'photo-contest' ); ?>
    </a>
  </header>

  <!-- ── Corps ─────────────────────────────────────────────────────── -->
  <div class="pc-body">

    <!-- Sidebar filtres -->
    <aside class="pc-sidebar">
      <div class="pc-sidebar__section">
        <p class="pc-sidebar__label"><?php esc_html_e( 'Filtrer par statut', 'photo-contest' ); ?></p>
        <div class="pc-sidebar__filtres">
          <!-- rempli par JS -->
        </div>
      </div>

      <div class="pc-sidebar__section">
        <p class="pc-sidebar__label"><?php esc_html_e( 'Informations', 'photo-contest' ); ?></p>
        <?php if ( PC_Settings::get( 'date_fermeture_depot' ) ) : ?>
          <p style="font-size:12px;color:var(--pc-text-secondary);line-height:1.6;">
            <?php esc_html_e( 'Clôture des dépôts :', 'photo-contest' ); ?><br>
            <strong style="color:var(--pc-text-primary);">
              <?php echo esc_html(
                wp_date( get_option( 'date_format' ), strtotime( PC_Settings::get( 'date_fermeture_depot' ) ) )
              ); ?>
            </strong>
          </p>
        <?php endif; ?>

        <?php if ( ! PC_Settings::is_depot_actif() ) : ?>
          <p style="font-size:12px;color:var(--pc-statut-refusee);margin-top:10px;">
            <?php esc_html_e( 'Le dépôt de photos est actuellement fermé.', 'photo-contest' ); ?>
          </p>
        <?php endif; ?>
      </div>

      <!-- Statuts légende -->
      <div class="pc-sidebar__section">
        <p class="pc-sidebar__label"><?php esc_html_e( 'Statuts', 'photo-contest' ); ?></p>
        <?php foreach ( PC_STATUTS_CANDIDAT as $slug => $label ) : ?>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
            <span class="pc-card__statut pc-statut-<?php echo esc_attr( $slug ); ?>"
                  style="position:static;font-size:9px;padding:2px 6px;">
              <?php echo esc_html( __( $label, 'photo-contest' ) ); ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </aside>

    <!-- Zone principale galerie -->
    <main class="pc-main">

      <?php if ( ! PC_Settings::is_depot_actif() ) : ?>
        <div style="background:rgba(176,80,80,0.08);border:1px solid rgba(176,80,80,0.2);
             border-radius:8px;padding:12px 16px;margin-bottom:20px;
             font-size:13px;color:#b05050;font-family:var(--pc-font-mono);">
          <?php esc_html_e( 'Les dépôts sont fermés. Vous pouvez consulter vos photos mais ne pouvez plus en ajouter ni en supprimer.', 'photo-contest' ); ?>
        </div>
      <?php endif; ?>

      <!-- Sections par catégorie — remplies par pc-gallery.js -->
      <div class="pc-gallery-sections" id="pc-gallery-sections">
        <p class="pc-gallery-loading"><?php esc_html_e( 'Chargement…', PC_TEXT_DOMAIN ); ?></p>
      </div>

    </main>
  </div>

  <!-- ── Barre d'outils basse ─────────────────────────────────────── -->
  <div class="pc-toolbar">

    <?php if ( PC_Settings::is_depot_actif() ) : ?>
      <!-- Zone de drop -->
      <label class="pc-drop-zone" for="pc-file-input"
             title="<?php esc_attr_e( 'Glissez vos photos ici ou cliquez pour sélectionner', 'photo-contest' ); ?>">
        <span class="pc-drop-zone__text">
          <?php esc_html_e( 'Glissez vos JPG ici — ratio 3:2 ou 2:3', 'photo-contest' ); ?>
        </span>
        <input class="pc-drop-zone__input"
               type="file"
               id="pc-file-input"
               accept="image/jpeg"
               multiple>
      </label>

      <div class="pc-toolbar__sep"></div>

      <button class="pc-btn-upload" id="pc-btn-upload"
              onclick="document.getElementById('pc-file-input').click()">
        <?php esc_html_e( 'Ajouter', 'photo-contest' ); ?>
      </button>

      <div class="pc-toolbar__sep"></div>
    <?php endif; ?>

    <span class="pc-selection-info"></span>

    <button class="pc-btn-suppr-sel" disabled>
      <?php esc_html_e( 'Supprimer la sélection', 'photo-contest' ); ?>
    </button>

  </div>

  <!-- ── Barre de progression upload ─────────────────────────────── -->
  <div class="pc-progress-wrap">
    <div class="pc-progress-bar"></div>
  </div>

  <!-- ── Overlay drag-and-drop pleine page ────────────────────────── -->
  <?php if ( PC_Settings::is_depot_actif() ) : ?>
    <div class="pc-page-drop-overlay">
      <p class="pc-page-drop-overlay__text">
        <?php esc_html_e( 'Déposez vos photos', 'photo-contest' ); ?>
      </p>
      <p class="pc-page-drop-overlay__sub">
        <?php esc_html_e( 'JPG — ratio 3:2 ou 2:3', 'photo-contest' ); ?>
      </p>
    </div>
  <?php endif; ?>

  <!-- ── Lightbox ─────────────────────────────────────────────────── -->
  <div class="pc-lightbox" id="pc-lightbox"></div>

  <!-- ── Toasts ───────────────────────────────────────────────────── -->
  <div class="pc-toast-container"></div>

</div>