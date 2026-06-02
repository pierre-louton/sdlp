(function () {
  'use strict';
  const CFG  = window.pcCategoriesAdmin || {};
  const I18N = CFG.i18n || {};
  const tbody = document.getElementById('pc-cat-tbody');
  const msg   = document.getElementById('pc-cat-msg');

  function setMsg(type, text) {
    if (!msg) return;
    msg.className = type ? ('pc-msg-' + type) : '';
    msg.textContent = text || '';
  }

  function post(action, data) {
    const body = new URLSearchParams(Object.assign({ action, nonce: CFG.nonce }, data));
    return fetch(CFG.ajaxUrl, { method: 'POST', body }).then(r => r.json());
  }

  document.getElementById('pc-cat-add')?.addEventListener('click', () => {
    const input = document.getElementById('pc-cat-new-nom');
    const nom = (input.value || '').trim();
    if (!nom) { setMsg('error', I18N.name_required); return; }
    post('pc_add_category', { nom }).then(res => {
      if (res.success) {
        setMsg('success', I18N.added || 'Ajoutée');
        location.reload();
      } else {
        setMsg('error', res.data?.message || I18N.generic_error || 'Erreur');
      }
    });
  });

  tbody?.addEventListener('click', e => {
    const tr = e.target.closest('tr[data-cat-id]');
    if (!tr) return;
    const id = tr.dataset.catId;

    if (e.target.classList.contains('pc-cat-save')) {
      const input = tr.querySelector('.pc-cat-nom');
      const nom = (input.value || '').trim();
      if (!nom) { setMsg('error', I18N.name_required); return; }
      post('pc_update_category', { id, nom }).then(res => {
        if (res.success) {
          input.dataset.original = nom;
          setMsg('success', I18N.updated || 'Mis à jour');
        } else {
          setMsg('error', res.data?.message || I18N.generic_error || 'Erreur');
        }
      });
    }

    if (e.target.classList.contains('pc-cat-toggle')) {
      post('pc_toggle_category', { id }).then(res => {
        if (res.success) location.reload();
        else setMsg('error', res.data?.message || I18N.generic_error || 'Erreur');
      });
    }

    if (e.target.classList.contains('pc-cat-delete')) {
      if (!confirm(I18N.confirm_delete)) return;
      post('pc_delete_category', { id }).then(res => {
        if (res.success) location.reload();
        else setMsg('error', res.data?.message || I18N.generic_error || 'Erreur');
      });
    }
  });
})();
