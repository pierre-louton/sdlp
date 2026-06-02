<?php
/**
 * Template : Page de connexion + inscription SDLP
 * Variables : $logo_url, $nom_concours, $error, $success, $redirect_to
 */
defined( 'ABSPATH' ) || exit;

$onglet_actif = $_GET['mode'] ?? 'login';
$verify_error = ! empty( $_GET['pc_verify_error'] );
?>
<div class="pc-login-wrap">
  <div class="pcl-card">

    <div class="pcl-header">
      <?php if ( $logo_url ) : ?>
        <img class="pcl-logo" src="<?php echo esc_url( $logo_url ); ?>"
             alt="<?php echo esc_attr( $nom_concours ); ?>"
             style="max-height:56px;width:auto;filter:brightness(1);display:block;margin:0 auto 12px;">
      <?php else : ?>
        <p class="pcl-nom"><?php echo esc_html( $nom_concours ); ?></p>
      <?php endif; ?>
      <p class="pcl-baseline"><?php esc_html_e( 'Espace candidats', PC_TEXT_DOMAIN ); ?></p>
    </div>

    <div class="pcl-tabs">
      <button class="pcl-tab <?php echo $onglet_actif === 'login' ? 'actif' : ''; ?>" data-tab="login">
        <?php esc_html_e( 'Se connecter', PC_TEXT_DOMAIN ); ?>
      </button>
      <button class="pcl-tab <?php echo $onglet_actif === 'register' ? 'actif' : ''; ?>" data-tab="register">
        <?php esc_html_e( 'Créer un compte', PC_TEXT_DOMAIN ); ?>
      </button>
    </div>

    <?php if ( $verify_error ) : ?>
      <div class="pcl-error"><span class="pcl-error__dot"></span>
        <?php esc_html_e( 'Lien de vérification invalide ou expiré. Reconnectez-vous pour en recevoir un nouveau.', PC_TEXT_DOMAIN ); ?>
      </div>
    <?php endif; ?>

    <?php if ( $error ) : ?>
      <div class="pcl-error"><span class="pcl-error__dot"></span><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <?php if ( ! empty( $success ) ) : ?>
      <div class="pcl-success"><span class="pcl-success__dot"></span><?php echo esc_html( $success ); ?></div>
    <?php endif; ?>

    <!-- Connexion -->
    <div class="pcl-panel <?php echo $onglet_actif === 'login' ? 'actif' : ''; ?>" id="pcl-panel-login">
      <form class="pcl-form" method="post">
        <?php wp_nonce_field( 'pc_login_form' ); ?>
        <?php if ( $redirect_to ) : ?><input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>"><?php endif; ?>
        <div class="pcl-field">
          <label class="pcl-field__lbl" for="log"><?php esc_html_e( 'Email ou identifiant', PC_TEXT_DOMAIN ); ?></label>
          <input class="pcl-field__input" type="text" id="log" name="log" autocomplete="username" autofocus value="<?php echo esc_attr( $_POST['log'] ?? '' ); ?>">
        </div>
        <div class="pcl-field">
          <label class="pcl-field__lbl" for="pwd"><?php esc_html_e( 'Mot de passe', PC_TEXT_DOMAIN ); ?></label>
          <input class="pcl-field__input" type="password" id="pwd" name="pwd" autocomplete="current-password">
        </div>
        <div class="pcl-remember">
          <label><input type="checkbox" name="rememberme" value="forever"> <?php esc_html_e( 'Rester connecté', PC_TEXT_DOMAIN ); ?></label>
        </div>
        <button class="pcl-submit" type="submit" name="pc_login_submit"><?php esc_html_e( 'Se connecter', PC_TEXT_DOMAIN ); ?></button>
      </form>
      <div class="pcl-footer">
        <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" class="pcl-link"><?php esc_html_e( 'Mot de passe oublié ?', PC_TEXT_DOMAIN ); ?></a>
      </div>
    </div>

    <!-- Inscription -->
    <div class="pcl-panel <?php echo $onglet_actif === 'register' ? 'actif' : ''; ?>" id="pcl-panel-register">
      <form class="pcl-form" id="pcl-form-register">
        <input type="hidden" id="pcl-register-nonce" value="<?php echo wp_create_nonce( 'pc_register_nonce' ); ?>">
        <div class="pcl-field">
          <label class="pcl-field__lbl" for="reg-email"><?php esc_html_e( 'Adresse email', PC_TEXT_DOMAIN ); ?></label>
          <input class="pcl-field__input" type="email" id="reg-email" name="email" autocomplete="email" required>
        </div>
        <div class="pcl-field">
          <label class="pcl-field__lbl" for="reg-pwd"><?php esc_html_e( 'Mot de passe (8 car. min.)', PC_TEXT_DOMAIN ); ?></label>
          <input class="pcl-field__input" type="password" id="reg-pwd" name="password" autocomplete="new-password" minlength="8" required>
        </div>
        <div class="pcl-field">
          <label class="pcl-field__lbl" for="reg-pwd2"><?php esc_html_e( 'Confirmer le mot de passe', PC_TEXT_DOMAIN ); ?></label>
          <input class="pcl-field__input" type="password" id="reg-pwd2" name="password2" autocomplete="new-password" required>
        </div>
        <div id="pcl-register-error" class="pcl-error" style="display:none"><span class="pcl-error__dot"></span><span class="pcl-register-msg"></span></div>
        <div id="pcl-register-success" class="pcl-success" style="display:none"><span class="pcl-success__dot"></span><span class="pcl-register-msg"></span></div>
        <button class="pcl-submit" type="submit" id="pcl-btn-register"><?php esc_html_e( 'Créer mon compte', PC_TEXT_DOMAIN ); ?></button>
      </form>
      <p class="pcl-mention"><?php esc_html_e( 'Un email de confirmation vous sera envoyé. Vérifiez vos spams.', PC_TEXT_DOMAIN ); ?></p>
    </div>

  </div>
</div>
<script>
(function(){
  document.querySelectorAll('.pcl-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.pcl-tab,.pcl-panel').forEach(el => el.classList.remove('actif'));
      btn.classList.add('actif');
      document.getElementById('pcl-panel-'+btn.dataset.tab).classList.add('actif');
    });
  });
  const form = document.getElementById('pcl-form-register');
  const btn  = document.getElementById('pcl-btn-register');
  if (!form) return;
  form.addEventListener('submit', e => {
    e.preventDefault();
    btn.disabled = true;
    btn.textContent = 'Création en cours…';
    const fd = new FormData(form);
    fd.append('action','pc_register');
    fd.append('nonce', document.getElementById('pcl-register-nonce').value);
    fetch('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>',{method:'POST',body:fd})
    .then(r=>r.json()).then(res=>{
      const err = document.getElementById('pcl-register-error');
      const ok  = document.getElementById('pcl-register-success');
      if(res.success){
        err.style.display='none';
        ok.querySelector('.pcl-register-msg').textContent=res.data.message;
        ok.style.display='flex'; form.reset();
        btn.textContent='Email envoyé ✓';
      } else {
        ok.style.display='none';
        err.querySelector('.pcl-register-msg').textContent=res.data.message;
        err.style.display='flex'; btn.disabled=false;
        btn.textContent='Créer mon compte';
      }
    }).catch(()=>{btn.disabled=false;btn.textContent='Créer mon compte';});
  });
})();
</script>
