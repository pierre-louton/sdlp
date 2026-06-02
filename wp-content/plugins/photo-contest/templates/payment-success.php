<?php
/**
 * Template : Paiement réussi
 * Variables : $photo, $paiement, $montant_fmt, $retour_url
 * Si $photo est null → paiement d'inscription, sinon → paiement impression
 */
defined( 'ABSPATH' ) || exit;
$est_inscription = empty( $photo );
?>
<div class="pc-pay-wrap">
  <header class="pcp-header">
    <?php echo PC_Settings::logo_html( 'pcp-logo' ); ?>
  </header>

  <div class="pcp-statut pcp-statut--succes">
    <span class="pcp-statut__ico">&#10003;</span>
    <p class="pcp-statut__titre"><?php esc_html_e( 'Paiement confirmé', 'photo-contest' ); ?></p>
    <p class="pcp-statut__desc">
      <?php if ( $est_inscription ) : ?>
        <?php esc_html_e( 'Votre inscription au concours est confirmée.', 'photo-contest' ); ?><br>
        <?php esc_html_e( 'Vous pouvez maintenant déposer vos photos.', 'photo-contest' ); ?><br>
        <?php esc_html_e( 'Un email de confirmation vous a été envoyé.', 'photo-contest' ); ?>
      <?php else : ?>
        <?php esc_html_e( 'Votre participation à l\'impression a bien été enregistrée.', 'photo-contest' ); ?><br>
        <?php esc_html_e( 'Votre photo sera incluse dans le catalogue de l\'exposition.', 'photo-contest' ); ?><br>
        <?php esc_html_e( 'Un email de confirmation vous a été envoyé.', 'photo-contest' ); ?>
      <?php endif; ?>
    </p>
    <?php if ( ! empty( $paiement['reference_externe'] ) ) : ?>
      <p class="pcp-statut__ref">
        <?php printf(
          esc_html__( 'Référence : %s', 'photo-contest' ),
          '<strong>' . esc_html( $paiement['reference_externe'] ) . '</strong>'
        ); ?>
      </p>
    <?php endif; ?>
    <a href="<?php echo esc_url( $retour_url ); ?>" class="pcp-btn-retour">
      <?php echo $est_inscription
        ? esc_html__( '→ Accéder à mon espace galerie', 'photo-contest' )
        : esc_html__( '← Retour à mon espace', 'photo-contest' ); ?>
    </a>
  </div>
</div>