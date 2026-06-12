/* Photo Contest — Profil candidat */
(function () {
  'use strict';

  const CFG  = window.pcProfileConfig || {};
  const I18N = CFG.i18n || {};

  // ── Formulaire profil ────────────────────────────────────────────
  const formProfil = document.getElementById('pcp-form-profil');
  const btnSave    = document.getElementById('pcp-btn-save');
  const msgProfil  = document.getElementById('pcp-profil-msg');

  if (formProfil) {
    formProfil.addEventListener('submit', e => {
      e.preventDefault();
      btnSave.disabled = true;
      btnSave.textContent = 'Enregistrement…';

      const fd = new FormData(formProfil);
      fd.append('action', 'pc_save_profile');
      fd.append('nonce',  CFG.nonceSave);

      fetch(CFG.ajaxUrl, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            afficherMsg(msgProfil, 'succes', res.data?.message || 'Profil enregistré.');
            btnSave.textContent = 'Mis à jour ✓';
            // Recharger si l'étape change (profil_incomplet → reglement)
            if (CFG.etape === 'profil_incomplet') {
              setTimeout(() => location.reload(), 800);
            }
          } else {
            afficherMsg(msgProfil, 'erreur', res.data?.message || 'Erreur.');
            btnSave.disabled = false;
            btnSave.textContent = 'Enregistrer et continuer';
          }
        })
        .catch(() => {
          afficherMsg(msgProfil, 'erreur', 'Erreur réseau.');
          btnSave.disabled = false;
          btnSave.textContent = 'Enregistrer et continuer';
        });
    });
  }

  // ── Règlement ────────────────────────────────────────────────────
  const checkReg   = document.getElementById('pcp-reglement-check');
  const btnReg     = document.getElementById('pcp-btn-reglement');
  const msgReg     = document.getElementById('pcp-reglement-msg');
  const pdfLink    = document.getElementById('pcp-pdf-link');
  const pdfOk      = document.getElementById('pcp-pdf-downloaded');
  const regLabel   = document.getElementById('pcp-reglement-label');
  const regTxt     = document.getElementById('pcp-reglement-txt');
  let   pdfReady   = !pdfLink; // si pas de PDF, considéré comme prêt

  if (pdfLink) {
    pdfLink.addEventListener('click', () => {
      setTimeout(() => {
        pdfReady = true;
        if (checkReg) checkReg.disabled = false;
        if (regLabel) regLabel.classList.remove('pcp-checkbox--locked');
        if (regTxt && I18N.reglementLabelDownloaded) regTxt.textContent = I18N.reglementLabelDownloaded;
        pdfLink.classList.add('downloaded');
        if (I18N.pdfDownloadedBtn) pdfLink.textContent = I18N.pdfDownloadedBtn;
        if (pdfOk) pdfOk.style.display = 'block';
      }, 1200);
    });
  }

  if (checkReg && btnReg) {
    checkReg.addEventListener('change', () => {
      btnReg.disabled = !(checkReg.checked && pdfReady);
    });

    btnReg.addEventListener('click', () => {
      if (!pdfReady) {
        afficherMsg(msgReg, 'erreur', I18N.errorPdfRequired || 'Veuillez d’abord télécharger le règlement.');
        return;
      }
      btnReg.disabled = true;
      btnReg.textContent = 'Enregistrement…';

      fetch(CFG.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'pc_accept_reglement',
          nonce:  CFG.nonceReglement,
        }),
      })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            afficherMsg(msgReg, 'succes', 'Règlement accepté.');
            setTimeout(() => location.reload(), 600);
          } else {
            afficherMsg(msgReg, 'erreur', res.data?.message || 'Erreur.');
            btnReg.disabled = false;
            btnReg.textContent = 'Accepter et continuer';
          }
        });
    });
  }

  // ── Caddy : paiement des photos ──────────────────────────────────
  var btnCaddy = document.getElementById('pcp-btn-caddy');
  if (btnCaddy) {
    btnCaddy.addEventListener('click', function () {
      btnCaddy.disabled = true;
      var body = new URLSearchParams();
      body.append('action', 'pc_create_caddy_session');
      body.append('nonce', CFG.nonceSave);
      fetch(CFG.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.success && res.data && res.data.url) {
            window.location.href = res.data.url;
          } else {
            btnCaddy.disabled = false;
            var msg = document.getElementById('pcp-caddy-msg');
            if (msg) { msg.textContent = (res && res.data && res.data.message) ? res.data.message : 'Erreur'; }
          }
        })
        .catch(function () { btnCaddy.disabled = false; });
    });
  }

  // ── Utilitaires ──────────────────────────────────────────────────
  function afficherMsg(el, type, texte) {
    if (!el) return;
    el.className = 'pcp-notice pcp-notice--' + type;
    el.textContent = texte;
    el.style.display = 'block';
  }

})();
