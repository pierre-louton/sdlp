(function () {
  'use strict';
  const CFG  = window.pcResetAdmin || {};
  const I18N = CFG.i18n || {};
  const btn        = document.getElementById('pc-reset-btn');
  const ack        = document.getElementById('pc-reset-ack');
  const msg        = document.getElementById('pc-reset-msg');
  const candidates = document.getElementById('pc-reset-candidates');
  const photos     = document.getElementById('pc-reset-photos');
  const scopeBoxes = document.querySelectorAll('input[name="scope"]');

  // Enable button only when ack is checked AND at least one scope is selected
  function updateBtn() {
    const anyScope = Array.from(scopeBoxes).some(b => b.checked);
    btn.disabled = !(ack.checked && anyScope);
  }
  ack.addEventListener('change', updateBtn);
  scopeBoxes.forEach(b => b.addEventListener('change', updateBtn));

  // Auto-check photos when candidates is checked
  candidates.addEventListener('change', () => {
    if (candidates.checked) {
      photos.checked  = true;
      photos.disabled = true;
    } else {
      photos.disabled = false;
    }
    updateBtn();
  });

  btn.addEventListener('click', () => {
    if (!confirm(I18N.confirm)) return;
    btn.disabled    = true;
    btn.textContent = '…';
    msg.textContent = '';

    const scope = Array.from(scopeBoxes).filter(b => b.checked).map(b => b.value);
    const body  = new URLSearchParams();
    body.append('action', 'pc_reset_execute');
    body.append('nonce',  CFG.nonce);
    scope.forEach(s => body.append('scope[]', s));

    fetch(CFG.ajaxUrl, { method: 'POST', body })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          msg.className = 'pc-msg-success';
          msg.innerHTML = res.data.message + '<pre>' + JSON.stringify(res.data.report, null, 2) + '</pre>';
        } else {
          msg.className   = 'pc-msg-error';
          msg.textContent = res.data?.message || 'Erreur';
        }
        btn.textContent = 'Exécuter le reset';
        updateBtn();
      })
      .catch(() => {
        msg.className   = 'pc-msg-error';
        msg.textContent = 'Erreur réseau';
        btn.textContent = 'Exécuter le reset';
        updateBtn();
      });
  });
})();
