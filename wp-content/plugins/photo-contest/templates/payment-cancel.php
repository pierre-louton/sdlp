<?php
/**
 * Template : Paiement annulé (retour Stripe cancel_url)
 * Variables : $photo, $retour_url, $retry_url
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="pc-pay-wrap">
  <header class="pcp-header">
    <p class="pcp-logo">
      <?php echo esc_html( PC_Settings::get( 'nom_concours', 'Concours Photo' ) ); ?>
    </p>
  </header>

  <div class="pcp-statut pcp-statut--echec">
    <span class="pcp-statut__ico">&#215;</span>
    <p class="pcp-statut__titre"><?php esc_html_e( 'Paiement annulé', 'photo-contest' ); ?></p>
    <p class="pcp-statut__desc">
      <?php esc_html_e( 'Votre paiement n\'a pas été finalisé.', 'photo-contest' ); ?><br>
      <?php esc_html_e( 'Aucun débit n\'a été effectué.', 'photo-contest' ); ?><br>
      <?php esc_html_e( 'Vous pouvez réessayer à tout moment depuis votre espace.', 'photo-contest' ); ?>
    </p>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:20px;flex-wrap:wrap;">
      <?php if ( ! empty( $retry_url ) ) : ?>
        <a href="<?php echo esc_url( $retry_url ); ?>" class="pcp-btn-retour"
           style="background:var(--gold);color:#0e0f10;border-color:var(--gold);">
          <?php esc_html_e( 'Réessayer', 'photo-contest' ); ?>
        </a>
      <?php endif; ?>
      <a href="<?php echo esc_url( $retour_url ); ?>" class="pcp-btn-retour">
        <?php esc_html_e( 'Retour à mon espace', 'photo-contest' ); ?>
      </a>
    </div>
  </div>
</div>
