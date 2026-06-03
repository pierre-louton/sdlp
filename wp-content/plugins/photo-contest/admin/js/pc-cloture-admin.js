(function () {
  'use strict';
  const CFG  = window.pcClotureAdmin || {};
  const I18N = CFG.i18n || {};
  const btn  = document.getElementById('pc-cloture-btn');
  const ack  = document.getElementById('pc-cloture-ack');
  const msg  = document.getElementById('pc-cloture-msg');

  if (!btn || !ack || !msg) return;

  ack.addEventListener('change', () => {
    btn.disabled = !ack.checked;
  });

  btn.addEventListener('click', () => {
    if (!confirm(I18N.confirm)) return;
    btn.disabled = true;
    ack.disabled = true;
    btn.textContent = I18N.cloturing;
    msg.textContent = '';
    msg.className   = '';

    fetch(CFG.ajaxUrl, {
      method: 'POST',
      body: new URLSearchParams({ action: 'pc_cloture_execute', nonce: CFG.nonce }),
    })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          msg.className = 'pc-msg-success';
          msg.innerHTML = res.data.message + '<pre>' + JSON.stringify(res.data.report, null, 2) + '</pre>';
          setTimeout(() => location.reload(), 2000);
        } else {
          msg.className = 'pc-msg-error';
          msg.textContent = res.data?.message || I18N.error_generic;
          btn.disabled = false;
          ack.disabled = false;
          btn.textContent = I18N.button_default;
        }
      })
      .catch(() => {
        msg.className = 'pc-msg-error';
        msg.textContent = I18N.error_network;
        btn.disabled = false;
        ack.disabled = false;
        btn.textContent = I18N.button_default;
      });
  });
})();
