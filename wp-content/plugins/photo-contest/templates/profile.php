<?php
/**
 * Template : Profil candidat
 * Variables : $user, $profile, $etape, $montant, $reglement_url, $espace_url, $logout_url
 */
defined( 'ABSPATH' ) || exit;
$prenom     = $profile['prenom']         ?? '';
$nom        = $profile['nom']            ?? '';
$naissance  = $profile['date_naissance'] ?? '';
$telephone  = $profile['telephone']      ?? '';
$adresse    = $profile['adresse_rue']    ?? '';
$ville      = $profile['ville']          ?? '';
$pays       = $profile['pays']           ?? 'France';
$reg_fait   = ! empty( $profile['reglement_accepte'] );
$reg_date   = $profile['reglement_date'] ?? '';
$reglement_url = $reglement_url ?? '';
$has_pdf    = ! empty( $reglement_url );
?>
<div class="pc-profile-wrap">

  <!-- Topbar -->
  <header class="pcp-topbar">
    <?php echo PC_Settings::logo_html( 'pcp-topbar__logo' ); ?>
    <span class="pcp-topbar__titre"><?php esc_html_e( 'Mon profil', PC_TEXT_DOMAIN ); ?></span>
    <a href="<?php echo esc_url( $logout_url ); ?>" class="pc-topbar__logout">
      <?php esc_html_e( 'Déconnexion', PC_TEXT_DOMAIN ); ?>
    </a>
  </header>

  <!-- Indicateur d'étapes -->
  <div class="pcp-steps">
    <?php
    $etapes = [
      ['profil',    __( 'Profil', PC_TEXT_DOMAIN )],
      ['reglement', __( 'Règlement', PC_TEXT_DOMAIN )],
      ['paiement',  __( 'Participation', PC_TEXT_DOMAIN )],
    ];
    $ordre = ['email_non_verifie'=>0,'profil_incomplet'=>1,'reglement_non_accepte'=>2,'paiement_requis'=>3,'complet'=>4];
    $actuel = $ordre[$etape] ?? 0;
    foreach ( $etapes as $i => [$slug, $label] ) :
      $idx = $i + 1;
      $classe = '';
      if ( $actuel > $idx )      $classe = 'fait';
      elseif ( $actuel === $idx ) $classe = 'actif';
    ?>
      <?php if ( $i > 0 ) : ?><div class="pcp-step__sep"></div><?php endif; ?>
      <div class="pcp-step <?php echo esc_attr( $classe ); ?>">
        <span class="pcp-step__num"><?php echo $idx; ?></span>
        <span class="pcp-step__lbl"><?php echo esc_html( $label ); ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="pcp-content">

    <?php if ( ! empty( $_GET['pc_email_verifie'] ) ) : ?>
      <div class="pcp-notice pcp-notice--succes">
        <?php esc_html_e( 'Email confirmé — complétez votre profil pour continuer.', PC_TEXT_DOMAIN ); ?>
      </div>
    <?php endif; ?>

    <!-- ── Étape 1 : Profil ──────────────────────────────────────── -->
    <section class="pcp-section">
      <h2 class="pcp-section__titre"><?php esc_html_e( 'Informations personnelles', PC_TEXT_DOMAIN ); ?></h2>
      <div id="pcp-profil-msg"></div>
      <form class="pcp-form" id="pcp-form-profil">
        <div class="pcp-fields">

          <div class="pcp-field pcp-field--half">
            <label class="pcp-field__lbl" for="pcp-prenom"><?php esc_html_e( 'Prénom *', PC_TEXT_DOMAIN ); ?></label>
            <input class="pcp-field__input" type="text" id="pcp-prenom" name="prenom"
                   value="<?php echo esc_attr( $prenom ); ?>" required>
          </div>

          <div class="pcp-field pcp-field--half">
            <label class="pcp-field__lbl" for="pcp-nom"><?php esc_html_e( 'Nom *', PC_TEXT_DOMAIN ); ?></label>
            <input class="pcp-field__input" type="text" id="pcp-nom" name="nom"
                   value="<?php echo esc_attr( $nom ); ?>" required>
          </div>

          <div class="pcp-field pcp-field--half">
            <label class="pcp-field__lbl" for="pcp-naissance"><?php esc_html_e( 'Date de naissance *', PC_TEXT_DOMAIN ); ?></label>
            <input class="pcp-field__input" type="date" id="pcp-naissance" name="date_naissance"
                   value="<?php echo esc_attr( $naissance ); ?>" required>
          </div>

          <div class="pcp-field pcp-field--half">
            <label class="pcp-field__lbl" for="pcp-tel"><?php esc_html_e( 'Téléphone *', PC_TEXT_DOMAIN ); ?></label>
            <input class="pcp-field__input" type="tel" id="pcp-tel" name="telephone"
                   value="<?php echo esc_attr( $telephone ); ?>" required>
          </div>

          <div class="pcp-field">
            <label class="pcp-field__lbl" for="pcp-adresse"><?php esc_html_e( 'Adresse *', PC_TEXT_DOMAIN ); ?></label>
            <input class="pcp-field__input" type="text" id="pcp-adresse" name="adresse_rue"
                   value="<?php echo esc_attr( $adresse ); ?>" required>
          </div>

          <div class="pcp-field pcp-field--half">
            <label class="pcp-field__lbl" for="pcp-cp"><?php esc_html_e( 'Code postal *', PC_TEXT_DOMAIN ); ?></label>
            <input class="pcp-field__input" type="text" id="pcp-cp" name="code_postal"
                   value="<?php echo esc_attr( $profile['code_postal'] ?? '' ); ?>" required>
          </div>

          <div class="pcp-field pcp-field--half">
            <label class="pcp-field__lbl" for="pcp-ville"><?php esc_html_e( 'Ville *', PC_TEXT_DOMAIN ); ?></label>
            <input class="pcp-field__input" type="text" id="pcp-ville" name="ville"
                   value="<?php echo esc_attr( $ville ); ?>" required>
          </div>

          <div class="pcp-field pcp-field--half">
            <label class="pcp-field__lbl" for="pcp-pays"><?php esc_html_e( 'Pays *', PC_TEXT_DOMAIN ); ?></label>
            <input class="pcp-field__input" type="text" id="pcp-pays" name="pays"
                   value="<?php echo esc_attr( $pays ); ?>" required>
          </div>

        </div>

        <div class="pcp-email-info">
          <span class="pcp-field__lbl"><?php esc_html_e( 'Email', PC_TEXT_DOMAIN ); ?></span>
          <span class="pcp-email-val"><?php echo esc_html( $user->user_email ); ?></span>
        </div>

        <button class="pcp-btn pcp-btn--primary" type="submit" id="pcp-btn-save">
          <?php echo $etape === 'profil_incomplet'
            ? esc_html__( 'Enregistrer et continuer', PC_TEXT_DOMAIN )
            : esc_html__( 'Mettre à jour', PC_TEXT_DOMAIN ); ?>
        </button>
      </form>
    </section>

    <!-- ── Étape 2 : Règlement ───────────────────────────────────── -->
    <?php if ( in_array( $etape, [ 'reglement_non_accepte', 'paiement_requis', 'complet' ], true ) ) : ?>
    <section class="pcp-section" id="pcp-section-reglement">
      <h2 class="pcp-section__titre"><?php esc_html_e( 'Règlement du concours', PC_TEXT_DOMAIN ); ?></h2>

      <?php if ( $reg_fait ) : ?>
        <div class="pcp-notice pcp-notice--succes">
          <?php printf(
            esc_html__( 'Règlement accepté le %s.', PC_TEXT_DOMAIN ),
            wp_date( get_option( 'date_format' ), strtotime( $reg_date ) )
          ); ?>
        </div>
      <?php else : ?>

        <?php if ( $has_pdf ) : ?>
          <div class="pcp-pdf-zone" id="pcp-pdf-zone">
            <div class="pcp-pdf-icon">📄</div>
            <div class="pcp-pdf-info">
              <span class="pcp-pdf-name">
                <?php echo esc_html( basename( $reglement_url ) ); ?>
              </span>
              <span class="pcp-pdf-hint">
                <?php esc_html_e( 'Téléchargez et lisez le règlement complet avant de continuer.', PC_TEXT_DOMAIN ); ?>
              </span>
            </div>
            <a href="<?php echo esc_url( $reglement_url ); ?>"
               target="_blank" rel="noopener"
               class="pcp-pdf-btn" id="pcp-pdf-link">
              <?php esc_html_e( 'Télécharger le PDF', PC_TEXT_DOMAIN ); ?>
            </a>
          </div>
          <div id="pcp-pdf-downloaded" class="pcp-pdf-ok" style="display:none;">
            ✓ <?php esc_html_e( 'Règlement téléchargé', PC_TEXT_DOMAIN ); ?>
          </div>
        <?php else : ?>
          <div class="pcp-reglement-texte">
            <?php echo wp_kses_post( PC_Settings::get( 'reglement_texte',
              'En participant à ce concours, vous acceptez que vos œuvres puissent être exposées et publiées dans le catalogue de l\'exposition. Vous certifiez être l\'auteur des photographies soumises et détenir tous les droits nécessaires.'
            ) ); ?>
          </div>
        <?php endif; ?>

        <div id="pcp-reglement-msg"></div>
        <label class="pcp-checkbox <?php echo $has_pdf ? 'pcp-checkbox--locked' : ''; ?>"
               id="pcp-reglement-label">
          <input type="checkbox" id="pcp-reglement-check" <?php echo $has_pdf ? 'disabled' : ''; ?>>
          <span id="pcp-reglement-txt">
            <?php if ( $has_pdf ) : ?>
              <?php esc_html_e( 'Téléchargez d\'abord le règlement pour activer cette case', PC_TEXT_DOMAIN ); ?>
            <?php else : ?>
              <?php esc_html_e( 'J\'ai lu et j\'accepte le règlement du concours', PC_TEXT_DOMAIN ); ?>
            <?php endif; ?>
          </span>
        </label>
        <button class="pcp-btn pcp-btn--primary" id="pcp-btn-reglement" disabled>
          <?php esc_html_e( 'Accepter et continuer', PC_TEXT_DOMAIN ); ?>
        </button>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ── Étape 3 : Paiement inscription ───────────────────────── -->
    <?php if ( in_array( $etape, [ 'paiement_requis', 'complet' ], true ) ) : ?>
    <section class="pcp-section" id="pcp-section-paiement">
      <h2 class="pcp-section__titre"><?php esc_html_e( 'Participation au concours', PC_TEXT_DOMAIN ); ?></h2>

      <?php if ( $etape === 'complet' ) : ?>
        <div class="pcp-notice pcp-notice--succes">
          <?php esc_html_e( 'Participation confirmée — vous pouvez déposer vos photos.', PC_TEXT_DOMAIN ); ?>
          <br>
          <a href="<?php echo esc_url( $espace_url ); ?>" class="pcp-btn pcp-btn--outline" style="margin-top:14px;display:inline-block;">
            <?php esc_html_e( 'Accéder à mon espace galerie →', PC_TEXT_DOMAIN ); ?>
          </a>
        </div>
      <?php else : ?>
        <p class="pcp-paiement-desc">
          <?php printf(
            esc_html__( 'Pour finaliser votre inscription, un règlement de %s est demandé.', PC_TEXT_DOMAIN ),
            '<strong>' . esc_html( $montant ) . '</strong>'
          ); ?>
        </p>
        <?php if ( PC_Settings::get( 'stripe_mode', 'test' ) === 'test' ) : ?>
          <div class="pcp-notice pcp-notice--info">
            <?php esc_html_e( 'Mode test Stripe actif — aucun débit réel.', PC_TEXT_DOMAIN ); ?>
          </div>
        <?php endif; ?>
        <div id="pcp-paiement-msg"></div>
        <button class="pcp-btn pcp-btn--gold" id="pcp-btn-paiement">
          <span class="pcp-btn__txt"><?php printf( esc_html__( 'Payer %s', PC_TEXT_DOMAIN ), esc_html( $montant ) ); ?></span>
          <span class="pcp-btn__spinner"></span>
        </button>
      <?php endif; ?>
    </section>
    <?php endif; ?>

  </div><!-- .pcp-content -->
</div><!-- .pc-profile-wrap -->
