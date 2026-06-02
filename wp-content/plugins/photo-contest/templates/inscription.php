<?php
/**
 * Template : Inscription publique au concours SDLP
 * Shortcode : [photo_contest_inscription]
 * Variables : $logo_url, $nom_concours, $reglement_url, $reglement_texte,
 *             $connexion_url, $nonce_inscription
 */
defined( 'ABSPATH' ) || exit;
$has_pdf = ! empty( $reglement_url );
?>
<div class="pc-login-wrap pc-inscription-wrap">
  <div class="pcl-card" style="max-width:480px;">

    <!-- En-tête -->
    <div class="pcl-header">
      <?php if ( $logo_url ) : ?>
        <img class="pcl-logo" src="<?php echo esc_url( $logo_url ); ?>"
             alt="<?php echo esc_attr( $nom_concours ); ?>">
      <?php else : ?>
        <p class="pcl-nom"><?php echo esc_html( $nom_concours ); ?></p>
      <?php endif; ?>
      <p class="pcl-baseline"><?php esc_html_e( 'Inscription au concours', PC_TEXT_DOMAIN ); ?></p>
    </div>

    <!-- Panneau formulaire -->
    <div id="pci-panel-form">

      <div id="pci-error" class="pcl-error" style="display:none">
        <span class="pcl-error__dot"></span>
        <span id="pci-error-msg"></span>
      </div>

      <form class="pcl-form" id="pci-form" novalidate>
        <input type="hidden" id="pci-nonce" value="<?php echo esc_attr( $nonce_inscription ); ?>">

        <!-- Email -->
        <div class="pcl-field">
          <label class="pcl-field__lbl" for="pci-email">
            <?php esc_html_e( 'Adresse email *', PC_TEXT_DOMAIN ); ?>
          </label>
          <input class="pcl-field__input" type="email" id="pci-email"
                 name="email" autocomplete="email" required
                 placeholder="votre@email.com">
        </div>

        <!-- Mot de passe -->
        <div class="pcl-field">
          <label class="pcl-field__lbl" for="pci-pwd">
            <?php esc_html_e( 'Mot de passe (8 caractères min.) *', PC_TEXT_DOMAIN ); ?>
          </label>
          <input class="pcl-field__input" type="password" id="pci-pwd"
                 name="password" autocomplete="new-password" minlength="8" required>
        </div>

        <!-- Confirmation mot de passe -->
        <div class="pcl-field">
          <label class="pcl-field__lbl" for="pci-pwd2">
            <?php esc_html_e( 'Confirmer le mot de passe *', PC_TEXT_DOMAIN ); ?>
          </label>
          <input class="pcl-field__input" type="password" id="pci-pwd2"
                 name="password2" autocomplete="new-password" required>
        </div>

        <!-- ── Règlement ─────────────────────────────────────────────── -->
        <div class="pci-reglement-bloc">
          <p class="pci-reglement-titre">
            <?php esc_html_e( 'Règlement du concours', PC_TEXT_DOMAIN ); ?>
          </p>

          <?php if ( $has_pdf ) : ?>
            <!-- PDF obligatoire à télécharger -->
            <div class="pci-pdf-zone" id="pci-pdf-zone">
              <div class="pci-pdf-icon">📄</div>
              <div class="pci-pdf-info">
                <span class="pci-pdf-name">
                  <?php echo esc_html( basename( $reglement_url ) ); ?>
                </span>
                <span class="pci-pdf-hint">
                  <?php esc_html_e( 'Téléchargez et lisez le règlement complet avant de continuer.', PC_TEXT_DOMAIN ); ?>
                </span>
              </div>
              <a href="<?php echo esc_url( $reglement_url ); ?>"
                 target="_blank" rel="noopener"
                 class="pci-pdf-btn" id="pci-pdf-link">
                <?php esc_html_e( 'Télécharger le PDF', PC_TEXT_DOMAIN ); ?>
              </a>
            </div>
            <div id="pci-pdf-downloaded" class="pci-pdf-ok" style="display:none;">
              ✓ <?php esc_html_e( 'Règlement téléchargé', PC_TEXT_DOMAIN ); ?>
            </div>
          <?php else : ?>
            <!-- Texte règlement si pas de PDF -->
            <div class="pci-reglement-texte">
              <?php echo wp_kses_post( $reglement_texte ); ?>
            </div>
          <?php endif; ?>

          <!-- Checkbox — désactivée jusqu'au téléchargement si PDF présent -->
          <label class="pci-checkbox <?php echo $has_pdf ? 'pci-checkbox--locked' : ''; ?>"
                 id="pci-checkbox-label">
            <input type="checkbox" id="pci-reglement" name="reglement"
                   <?php echo $has_pdf ? 'disabled' : ''; ?> required>
            <span id="pci-checkbox-txt">
              <?php if ( $has_pdf ) : ?>
                <?php esc_html_e( 'Téléchargez d\'abord le règlement pour activer cette case', PC_TEXT_DOMAIN ); ?>
              <?php else : ?>
                <?php esc_html_e( 'J\'ai lu et j\'accepte le règlement du concours *', PC_TEXT_DOMAIN ); ?>
              <?php endif; ?>
            </span>
          </label>
        </div>

        <!-- Bouton -->
        <button class="pcl-submit" type="submit" id="pci-btn" disabled>
          <?php esc_html_e( 'Créer mon compte', PC_TEXT_DOMAIN ); ?>
        </button>

        <p class="pcl-mention" style="margin-top:14px;">
          <?php esc_html_e( 'Un email de confirmation vous sera envoyé. Vérifiez vos spams.', PC_TEXT_DOMAIN ); ?>
        </p>
      </form>

      <div class="pcl-footer" style="margin-top:20px;text-align:center;">
        <?php esc_html_e( 'Déjà inscrit ?', PC_TEXT_DOMAIN ); ?>
        <a href="<?php echo esc_url( $connexion_url ); ?>" class="pcl-link">
          <?php esc_html_e( 'Se connecter', PC_TEXT_DOMAIN ); ?>
        </a>
      </div>

    </div><!-- #pci-panel-form -->

    <!-- Panneau succès -->
    <div id="pci-panel-succes" style="display:none;">
      <div class="pcl-success" style="display:flex;margin-bottom:20px;">
        <span class="pcl-success__dot"></span>
        <span><?php esc_html_e( 'Compte créé avec succès !', PC_TEXT_DOMAIN ); ?></span>
      </div>
      <div style="background:#0a2518;border:1px solid #1a4030;border-radius:10px;padding:20px;color:#e8e6e1;font-family:DM Sans,sans-serif;font-size:14px;line-height:1.6;">
        <p style="margin-bottom:10px;">
          <?php esc_html_e( 'Un email de confirmation a été envoyé à l\'adresse que vous avez indiquée.', PC_TEXT_DOMAIN ); ?>
        </p>
        <p style="margin-bottom:10px;">
          <strong><?php esc_html_e( 'Étape suivante :', PC_TEXT_DOMAIN ); ?></strong>
          <?php esc_html_e( 'Cliquez sur le lien dans l\'email pour activer votre compte, puis complétez votre profil.', PC_TEXT_DOMAIN ); ?>
        </p>
        <p style="color:#888480;font-size:12px;">
          <?php esc_html_e( 'Vous n\'avez pas reçu l\'email ? Vérifiez votre dossier spam.', PC_TEXT_DOMAIN ); ?>
        </p>
      </div>
      <div style="text-align:center;margin-top:20px;">
        <a href="<?php echo esc_url( $connexion_url ); ?>" class="pcl-submit"
           style="display:inline-block;text-decoration:none;">
          <?php esc_html_e( '← Retour à la connexion', PC_TEXT_DOMAIN ); ?>
        </a>
      </div>
    </div>

  </div><!-- .pcl-card -->
</div><!-- .pc-inscription-wrap -->

<style>
/* ── Règlement PDF ───────────────────────────────────────────────────── */
.pci-reglement-bloc {
  margin: 18px 0;
  padding: 16px;
  background: #0e0f10;
  border: 1px solid #2a2b2e;
  border-radius: 8px;
}
.pci-reglement-titre {
  font-size: 11px;
  font-family: 'DM Mono', monospace;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: #888480;
  margin-bottom: 12px;
}
.pci-reglement-texte {
  font-size: 13px;
  color: #b0ada8;
  line-height: 1.6;
  max-height: 130px;
  overflow-y: auto;
  margin-bottom: 14px;
}
/* Zone PDF */
.pci-pdf-zone {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #1c1d1f;
  border: 1px solid #2a2b2e;
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 14px;
}
.pci-pdf-icon { font-size: 22px; flex-shrink: 0; }
.pci-pdf-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}
.pci-pdf-name {
  font-size: 13px;
  color: #e8e6e1;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pci-pdf-hint { font-size: 11px; color: #888480; }
.pci-pdf-btn {
  flex-shrink: 0;
  background: #c49a3c;
  color: #0e0f10;
  border: none;
  border-radius: 6px;
  padding: 7px 14px;
  font-size: 12px;
  font-weight: 600;
  font-family: 'DM Mono', monospace;
  text-decoration: none;
  cursor: pointer;
  transition: opacity .2s;
}
.pci-pdf-btn:hover { opacity: .85; color: #0e0f10; }
.pci-pdf-btn.downloaded {
  background: #2a2b2e;
  color: #4da876;
  cursor: default;
}
.pci-pdf-ok {
  font-size: 12px;
  color: #4da876;
  margin-bottom: 10px;
  font-family: 'DM Mono', monospace;
}
/* Checkbox */
.pci-checkbox {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  cursor: pointer;
  font-size: 13px;
  color: #e8e6e1;
  line-height: 1.4;
  margin-top: 4px;
}
.pci-checkbox--locked { opacity: .45; cursor: not-allowed; }
.pci-checkbox input[type="checkbox"] {
  margin-top: 2px;
  flex-shrink: 0;
  width: 15px; height: 15px;
  accent-color: #c49a3c;
}
/* Bouton désactivé */
.pcl-submit:disabled { opacity: .4; cursor: not-allowed; }
</style>

<script>
(function () {
  var hasPdf       = <?php echo $has_pdf ? 'true' : 'false'; ?>;
  var pdfDownloaded = false;

  var form      = document.getElementById('pci-form');
  var btn       = document.getElementById('pci-btn');
  var check     = document.getElementById('pci-reglement');
  var errDiv    = document.getElementById('pci-error');
  var errMsg    = document.getElementById('pci-error-msg');
  var pdfLink   = document.getElementById('pci-pdf-link');
  var pdfOk     = document.getElementById('pci-pdf-downloaded');
  var chkLabel  = document.getElementById('pci-checkbox-label');
  var chkTxt    = document.getElementById('pci-checkbox-txt');

  if (!form) return;

  // ── Gestion du téléchargement PDF ──────────────────────────────────
  if (hasPdf && pdfLink) {
    pdfLink.addEventListener('click', function () {
      // Délai pour laisser le navigateur initier le téléchargement
      setTimeout(function () {
        pdfDownloaded = true;
        // Déverrouiller la checkbox
        check.disabled = false;
        chkLabel.classList.remove('pci-checkbox--locked');
        chkTxt.textContent = '<?php echo esc_js( __( 'J\'ai téléchargé, lu et j\'accepte le règlement du concours *', PC_TEXT_DOMAIN ) ); ?>';
        // Feedback visuel sur le bouton PDF
        pdfLink.classList.add('downloaded');
        pdfLink.textContent = '✓ <?php echo esc_js( __( 'Téléchargé', PC_TEXT_DOMAIN ) ); ?>';
        if (pdfOk) pdfOk.style.display = 'block';
      }, 1200);
    });
  }

  // ── Activation du bouton submit ────────────────────────────────────
  check.addEventListener('change', function () {
    btn.disabled = !check.checked;
  });

  // ── Soumission AJAX ────────────────────────────────────────────────
  form.addEventListener('submit', function (e) {
    e.preventDefault();

    if (hasPdf && !pdfDownloaded) {
      afficherErreur('<?php echo esc_js( __( 'Veuillez d\'abord télécharger le règlement.', PC_TEXT_DOMAIN ) ); ?>');
      return;
    }
    if (!check.checked) {
      afficherErreur('<?php echo esc_js( __( 'Vous devez accepter le règlement pour continuer.', PC_TEXT_DOMAIN ) ); ?>');
      return;
    }

    var pwd1 = document.getElementById('pci-pwd').value;
    var pwd2 = document.getElementById('pci-pwd2').value;

    if (pwd1.length < 8) {
      afficherErreur('<?php echo esc_js( __( 'Le mot de passe doit contenir au moins 8 caractères.', PC_TEXT_DOMAIN ) ); ?>');
      return;
    }
    if (pwd1 !== pwd2) {
      afficherErreur('<?php echo esc_js( __( 'Les mots de passe ne correspondent pas.', PC_TEXT_DOMAIN ) ); ?>');
      return;
    }

    btn.disabled = true;
    btn.textContent = '<?php echo esc_js( __( 'Création en cours…', PC_TEXT_DOMAIN ) ); ?>';
    errDiv.style.display = 'none';

    var fd = new FormData(form);
    fd.append('action', 'pc_register');
    fd.append('nonce', document.getElementById('pci-nonce').value);

    fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
      method: 'POST', body: fd,
    })
    .then(function(r){ return r.json(); })
    .then(function(res){
      if (res.success) {
        document.getElementById('pci-panel-form').style.display = 'none';
        document.getElementById('pci-panel-succes').style.display = 'block';
      } else {
        afficherErreur(res.data && res.data.message
          ? res.data.message
          : '<?php echo esc_js( __( 'Une erreur est survenue.', PC_TEXT_DOMAIN ) ); ?>');
        btn.disabled = !check.checked;
        btn.textContent = '<?php echo esc_js( __( 'Créer mon compte', PC_TEXT_DOMAIN ) ); ?>';
      }
    })
    .catch(function(){
      afficherErreur('<?php echo esc_js( __( 'Erreur réseau. Veuillez réessayer.', PC_TEXT_DOMAIN ) ); ?>');
      btn.disabled = !check.checked;
      btn.textContent = '<?php echo esc_js( __( 'Créer mon compte', PC_TEXT_DOMAIN ) ); ?>';
    });
  });

  function afficherErreur(msg) {
    errMsg.textContent = msg;
    errDiv.style.display = 'flex';
    errDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
})();
</script>
