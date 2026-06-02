<?php
/**
 * Template : Page de paiement participation impression
 * Rendu via le handler pc_action=paiement sur l'espace candidat.
 *
 * Variables disponibles :
 *   $photo        array   — données photo
 *   $paiement     array|null — paiement existant (si déjà en cours)
 *   $montant_cts  int     — montant en centimes
 *   $devise       string  — ex: 'EUR'
 *   $mode_test    bool    — true si clé Stripe en mode test
 *   $stripe_pub   string  — clé publique Stripe
 *   $retour_url   string  — URL espace candidat
 */
defined( 'ABSPATH' ) || exit;

$montant_fmt = number_format( $montant_cts / 100, 2, ',', ' ' ) . ' ' . $devise;
$deja_paye   = $paiement && $paiement['statut_paiement'] === 'paiement_recu';
$nom_concours = PC_Settings::get( 'nom_concours', 'Concours Photo' );
?>

<div class="pc-pay-wrap">

  <header class="pcp-header">
    <?php echo PC_Settings::logo_html( 'pcp-logo' ); ?>
  </header>

  <?php if ( $deja_paye ) : ?>

    <!-- ── Déjà payé ──────────────────────────────────────────────── -->
    <div class="pcp-deja-paye">
      <p class="pcp-deja-paye__titre">
        <?php esc_html_e( 'Paiement déjà effectué', 'photo-contest' ); ?>
      </p>
      <p class="pcp-deja-paye__desc">
        <?php esc_html_e( 'Votre participation pour cette photo a bien été enregistrée.', 'photo-contest' ); ?><br>
        <?php
          printf(
            esc_html__( 'Référence : %s', 'photo-contest' ),
            '<strong>' . esc_html( $paiement['reference_externe'] ?? '—' ) . '</strong>'
          );
        ?>
      </p>
      <a href="<?php echo esc_url( $retour_url ); ?>" class="pcp-btn-retour">
        <?php esc_html_e( '← Retour à mon espace', 'photo-contest' ); ?>
      </a>
    </div>

  <?php else : ?>

    <!-- ── Carte paiement ─────────────────────────────────────────── -->
    <div class="pcp-card">

      <!-- Photo retenue -->
      <div class="pcp-photo-bloc">
        <div class="pcp-photo-vignette">
          <img src="<?php echo esc_url( PC_Payments::get_instance()->get_thumb_url( (int) $photo['id'] ) ); ?>"
               alt="<?php esc_attr_e( 'Photo retenue', 'photo-contest' ); ?>">
        </div>
        <div class="pcp-photo-info">
          <span class="pcp-badge-retenu"><?php esc_html_e( 'Retenue', 'photo-contest' ); ?></span>
          <p class="pcp-photo-ref">
            #<?php echo str_pad( $photo['id'], 5, '0', STR_PAD_LEFT ); ?>
            &nbsp;—&nbsp;
            <?php echo $photo['ratio_type'] === '3_2'
              ? esc_html__( '3:2 Paysage', 'photo-contest' )
              : esc_html__( '2:3 Portrait', 'photo-contest' ); ?>
          </p>
          <p class="pcp-photo-titre">
            <?php echo esc_html( $photo['titre'] ?: __( 'Sans titre', 'photo-contest' ) ); ?>
          </p>
          <p class="pcp-photo-meta">
            <?php echo (int) $photo['largeur_px']; ?> × <?php echo (int) $photo['hauteur_px']; ?> px
          </p>
        </div>
      </div>

      <!-- Récapitulatif -->
      <div class="pcp-recapitulatif">
        <p class="pcp-recap-titre"><?php esc_html_e( 'Récapitulatif', 'photo-contest' ); ?></p>

        <div class="pcp-ligne">
          <span class="lbl"><?php esc_html_e( 'Impression photo', 'photo-contest' ); ?></span>
          <span class="val"><?php echo esc_html( $montant_fmt ); ?></span>
        </div>
        <div class="pcp-ligne">
          <span class="lbl"><?php esc_html_e( 'TVA', 'photo-contest' ); ?></span>
          <span class="val"><?php esc_html_e( 'incluse', 'photo-contest' ); ?></span>
        </div>

        <div class="pcp-total-ligne">
          <span class="lbl"><?php esc_html_e( 'Total', 'photo-contest' ); ?></span>
          <span class="montant"><?php echo esc_html( $montant_fmt ); ?></span>
        </div>
      </div>

      <!-- CTA Stripe -->
      <div class="pcp-cta">

        <?php if ( $mode_test ) : ?>
          <div class="pcp-mode-test">
            <?php esc_html_e( 'Mode test Stripe actif — aucun débit réel.', 'photo-contest' ); ?>
          </div>
        <?php endif; ?>

        <button class="pcp-btn-stripe" id="pc-pay-btn">
          <span class="pcp-btn-stripe__txt">
            <?php
              printf(
                esc_html__( 'Payer %s', 'photo-contest' ),
                esc_html( $montant_fmt )
              );
            ?>
          </span>
          <span class="pcp-btn-stripe__spinner"></span>
        </button>

        <div class="pcp-securite">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="3" y="11" width="18" height="11" rx="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
          <?php esc_html_e( 'Paiement sécurisé par Stripe', 'photo-contest' ); ?>
        </div>

      </div>
    </div>

    <!-- Script Stripe JS -->
    <script src="https://js.stripe.com/v3/"></script>
    <script>
    (function(){
      const btn    = document.getElementById('pc-pay-btn');
      const stripe = Stripe(<?php echo wp_json_encode( $stripe_pub ); ?>);

      btn.addEventListener('click', function() {
        btn.classList.add('chargement');
        btn.disabled = true;

        fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action:   'pc_create_checkout_session',
            nonce:    <?php echo wp_json_encode( wp_create_nonce( 'pc_checkout_nonce' ) ); ?>,
            photo_id: <?php echo (int) $photo['id']; ?>,
          }),
        })
        .then(r => r.json())
        .then(data => {
          if (data.success && data.data.session_id) {
            return stripe.redirectToCheckout({ sessionId: data.data.session_id });
          }
          throw new Error(data.data?.message || <?php echo wp_json_encode( __( 'Erreur lors de la création de la session.', 'photo-contest' ) ); ?>);
        })
        .catch(err => {
          btn.classList.remove('chargement');
          btn.disabled = false;
          alert(err.message);
        });
      });
    })();
    </script>

  <?php endif; ?>

</div>
