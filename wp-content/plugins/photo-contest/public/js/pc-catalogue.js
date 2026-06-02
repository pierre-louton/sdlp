/**
 * Photo Contest — Interface Catalogue Éditeur
 * Sélection planche, fiche éditable, drag-and-drop, exports PDF/CSV/JSON
 */
(function () {
  'use strict';

  const CFG = window.pcCatalogueConfig || {};

  const state = {
    items:      [],    // ordre courant
    actif:      null,  // id item sélectionné
    modifie:    new Set(),
    dragSrcId:  null,
    sauvegarde: {},    // cache des fiches sauvegardées
  };

  const $ = s => document.querySelector(s);
  const $$ = s => [...document.querySelectorAll(s)];

  // ── Init ─────────────────────────────────────────────────────────────
  function init() {
    const wrap = $('.pc-cat-wrap');
    if (!wrap) return;
    bindExportBtns();
    charger();
  }

  // ── Chargement ───────────────────────────────────────────────────────
  function charger() {
    fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'pc_catalogue_get', nonce: CFG.nonceGet }),
    })
    .then(r => r.json())
    .then(data => {
      if (!data.success) { toast(data.data?.message || 'Erreur chargement.', 'err'); return; }
      state.items = data.data.items || [];
      renderGrille();
      mettreAJourStats();
    })
    .catch(() => toast('Connexion impossible.', 'err'));
  }

  // ── Rendu grille planches ────────────────────────────────────────────
  function renderGrille() {
    const grid = $('.pcc-grid');
    if (!grid) return;
    grid.innerHTML = '';

    if (!state.items.length) {
      grid.innerHTML = `<p style="grid-column:1/-1;font-family:var(--mono);font-size:12px;
        color:var(--txt-m);padding:40px 0;text-align:center;">
        Aucune photo prête pour le catalogue.<br>
        <span style="font-size:10px;opacity:.7">Les photos doivent avoir le statut « Paiement reçu ».</span>
      </p>`;
      return;
    }

    state.items.forEach((item, i) => {
      const el = creerPlanche(item, i + 1);
      grid.appendChild(el);
    });

    initDragDrop();
    mettreAJourCompteurs();
  }

  // ── Carte planche ────────────────────────────────────────────────────
  function creerPlanche(item, num) {
    const div = document.createElement('div');
    div.className = 'pcc-planche' + (item.inclus_catalogue ? '' : ' exclu');
    div.dataset.id    = item.photo_id;
    div.dataset.ratio = item.ratio_type;
    div.setAttribute('draggable', 'true');

    const pmtClass = item.statut_paiement === 'paiement_recu' ? 'pcc-pmt--paye' : 'pcc-pmt--attente';
    const pmtLabel = item.statut_paiement === 'paiement_recu' ? 'Payé' : 'En attente';

    div.innerHTML = `
      <img class="pcc-planche__img pcc-planche__img--load"
           src="${esc(item.url_thumb)}" alt="" loading="lazy" draggable="false">
      <div class="pcc-planche__overlay"></div>
      <span class="pcc-planche__num">${num}</span>
      <span class="pcc-planche__pmt ${pmtClass}">${pmtLabel}</span>
      <span class="pcc-planche__titre">${esc(item.titre_catalogue || 'Sans titre')}</span>
      ${item.inclus_catalogue ? '<span class="pcc-planche__inclu"></span>' : ''}
    `;

    const img = div.querySelector('.pcc-planche__img');
    img.onload = () => img.classList.remove('pcc-planche__img--load');

    div.addEventListener('click', () => selectionner(item.photo_id));
    return div;
  }

  // ── Sélection planche → panneau ──────────────────────────────────────
  function selectionner(photoId) {
    state.actif = photoId;
    $$('.pcc-planche').forEach(p => p.classList.toggle('active', p.dataset.id == photoId));
    afficherPanneau(photoId);
  }

  function afficherPanneau(photoId) {
    const panel = $('.pcc-panel');
    if (!panel) return;

    const item = state.items.find(i => i.photo_id == photoId);
    if (!item) return;

    const num = state.items.indexOf(item) + 1;

    panel.innerHTML = `
      <img class="pcc-panel__thumb"
           src="${esc(item.url_full)}"
           data-ratio="${esc(item.ratio_type)}" alt="">

      <div class="pcc-panel__hdr">
        <span class="pcc-panel__hdr-num">${num}</span>
        <div class="pcc-panel__hdr-info">
          <div class="pcc-panel__hdr-ref">#${String(item.photo_id).padStart(5,'0')} — ${esc(item.ratio_type === '3_2' ? '3:2 Paysage' : '2:3 Portrait')}</div>
          <div class="pcc-panel__hdr-dim">${item.largeur_px} × ${item.hauteur_px} px</div>
        </div>
      </div>

      <div class="pcc-toggle-inclu ${item.inclus_catalogue ? 'on' : ''}" id="toggle-inclu">
        <span class="pcc-toggle-inclu__switch"></span>
        <span class="pcc-toggle-inclu__lbl">
          ${item.inclus_catalogue ? 'Incluse dans le catalogue' : 'Exclue du catalogue'}
        </span>
      </div>

      <div class="pcc-field">
        <label class="pcc-field__lbl">Titre de la planche <span>affiché dans le catalogue</span></label>
        <input class="pcc-input" id="f-titre"
               value="${esc(item.titre_catalogue || '')}"
               placeholder="Titre de l'œuvre…">
      </div>

      <div class="pcc-field">
        <label class="pcc-field__lbl">Biographie <span>courte, 2-3 lignes max</span></label>
        <textarea class="pcc-textarea" id="f-bio"
                  placeholder="Présentation de l'artiste…">${esc(item.biographie || '')}</textarea>
      </div>

      <div class="pcc-field">
        <label class="pcc-field__lbl">Tirage <span>ex : 30×45 cm, Hahnemühle</span></label>
        <input class="pcc-input" id="f-tirage"
               value="${esc(item.tirage || '')}"
               placeholder="Format et support…">
      </div>

      <div class="pcc-field">
        <label class="pcc-field__lbl">Notes éditeur <span>internes, non imprimées</span></label>
        <textarea class="pcc-textarea" id="f-notes"
                  placeholder="Remarques de mise en page, corrections…"
                  style="min-height:60px">${esc(item.notes_editeur || '')}</textarea>
      </div>

      <div class="pcc-field">
        <button class="pcc-btn-save" id="btn-save">Enregistrer la fiche</button>
        <div class="pcc-saved" id="pcc-saved">
          <span class="pcc-saved__dot"></span>Fiche enregistrée
        </div>
      </div>
    `;

    // Toggle inclusion
    document.getElementById('toggle-inclu')?.addEventListener('click', () => {
      toggleInclusion(photoId);
    });

    // Sauvegarder
    document.getElementById('btn-save')?.addEventListener('click', () => {
      sauvegarderFiche(photoId);
    });

    // Raccourci Ctrl+S
    panel.addEventListener('keydown', e => {
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        sauvegarderFiche(photoId);
      }
    });
  }

  // ── Toggle inclusion catalogue ───────────────────────────────────────
  function toggleInclusion(photoId) {
    const item = state.items.find(i => i.photo_id == photoId);
    if (!item) return;

    const nouvelEtat = !item.inclus_catalogue;

    fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action:          'pc_catalogue_toggle',
        nonce:           CFG.nonceSave,
        photo_id:        photoId,
        inclus_catalogue: nouvelEtat ? 1 : 0,
      }),
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        item.inclus_catalogue = nouvelEtat;
        // Mise à jour UI toggle
        const tg = document.getElementById('toggle-inclu');
        const lbl = tg?.querySelector('.pcc-toggle-inclu__lbl');
        tg?.classList.toggle('on', nouvelEtat);
        if (lbl) lbl.textContent = nouvelEtat ? 'Incluse dans le catalogue' : 'Exclue du catalogue';

        // Mise à jour planche
        const planche = $(`.pcc-planche[data-id="${photoId}"]`);
        if (planche) {
          planche.classList.toggle('exclu', !nouvelEtat);
          const inclu = planche.querySelector('.pcc-planche__inclu');
          if (nouvelEtat && !inclu) {
            const span = document.createElement('span');
            span.className = 'pcc-planche__inclu';
            planche.appendChild(span);
          } else if (!nouvelEtat && inclu) {
            inclu.remove();
          }
        }

        mettreAJourStats();
        toast(nouvelEtat ? 'Photo incluse dans le catalogue.' : 'Photo exclue du catalogue.', 'info');
      } else {
        toast(data.data?.message || 'Erreur.', 'err');
      }
    });
  }

  // ── Sauvegarde fiche ────────────────────────────────────────────────
  function sauvegarderFiche(photoId) {
    const item = state.items.find(i => i.photo_id == photoId);
    if (!item) return;

    const titre   = document.getElementById('f-titre')?.value.trim() || '';
    const bio     = document.getElementById('f-bio')?.value.trim() || '';
    const tirage  = document.getElementById('f-tirage')?.value.trim() || '';
    const notes   = document.getElementById('f-notes')?.value.trim() || '';

    const btn = document.getElementById('btn-save');
    if (btn) btn.disabled = true;

    fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action:          'pc_catalogue_save_fiche',
        nonce:           CFG.nonceSave,
        photo_id:        photoId,
        titre_catalogue: titre,
        biographie:      bio,
        tirage:          tirage,
        notes_editeur:   notes,
      }),
    })
    .then(r => r.json())
    .then(data => {
      if (btn) btn.disabled = false;
      if (data.success) {
        // Mise à jour cache local
        item.titre_catalogue = titre;
        item.biographie      = bio;
        item.tirage          = tirage;
        item.notes_editeur   = notes;

        // Mise à jour titre sur la planche
        const titreEl = $(`.pcc-planche[data-id="${photoId}"] .pcc-planche__titre`);
        if (titreEl) titreEl.textContent = titre || 'Sans titre';

        // Indicateur sauvegardé
        const saved = document.getElementById('pcc-saved');
        if (saved) {
          saved.classList.add('visible');
          setTimeout(() => saved.classList.remove('visible'), 2500);
        }

        toast('Fiche enregistrée.', 'ok');
      } else {
        toast(data.data?.message || 'Erreur sauvegarde.', 'err');
      }
    })
    .catch(() => {
      if (btn) btn.disabled = false;
      toast('Erreur réseau.', 'err');
    });
  }

  // ── Drag & drop réordonnancement ────────────────────────────────────
  function initDragDrop() {
    const planches = $$('.pcc-planche');
    planches.forEach(p => {
      p.addEventListener('dragstart', e => {
        state.dragSrcId = p.dataset.id;
        p.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
      });
      p.addEventListener('dragend', () => {
        p.classList.remove('dragging');
        $$('.pcc-planche').forEach(x => x.classList.remove('drag-over'));
        sauvegarderOrdre();
      });
      p.addEventListener('dragover', e => {
        e.preventDefault();
        if (p.dataset.id !== state.dragSrcId) {
          $$('.pcc-planche').forEach(x => x.classList.remove('drag-over'));
          p.classList.add('drag-over');
        }
      });
      p.addEventListener('drop', e => {
        e.preventDefault();
        if (!state.dragSrcId || p.dataset.id === state.dragSrcId) return;
        const grid   = $('.pcc-grid');
        const src    = grid.querySelector(`[data-id="${state.dragSrcId}"]`);
        const dst    = p;
        const srcIdx = [...grid.children].indexOf(src);
        const dstIdx = [...grid.children].indexOf(dst);
        srcIdx < dstIdx ? dst.after(src) : dst.before(src);
        p.classList.remove('drag-over');
        // Resync state.items
        const newOrder = [...grid.querySelectorAll('.pcc-planche')].map(x => x.dataset.id);
        state.items.sort((a, b) => newOrder.indexOf(String(a.photo_id)) - newOrder.indexOf(String(b.photo_id)));
        mettreAJourNumerosVisibles();
      });
    });
  }

  function mettreAJourNumerosVisibles() {
    $$('.pcc-planche').forEach((p, i) => {
      const num = p.querySelector('.pcc-planche__num');
      if (num) num.textContent = i + 1;
    });
  }

  function sauvegarderOrdre() {
    const ids = [...$$('.pcc-planche')].map(p => p.dataset.id);
    const body = new URLSearchParams({ action: 'pc_catalogue_reorder', nonce: CFG.nonceSave });
    ids.forEach((id, i) => body.append(`ids[${i}]`, id));
    fetch(CFG.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
      .catch(() => {});
  }

  // ── Stats topbar ────────────────────────────────────────────────────
  function mettreAJourStats() {
    const total   = state.items.length;
    const inclus  = state.items.filter(i => i.inclus_catalogue).length;
    const payes   = state.items.filter(i => i.statut_paiement === 'paiement_recu').length;

    const elTotal  = $('.pcc-stat--total .pcc-stat__n');
    const elInclus = $('.pcc-stat--inclus .pcc-stat__n');
    const elPayes  = $('.pcc-stat--payes .pcc-stat__n');

    if (elTotal)  elTotal.textContent  = total;
    if (elInclus) elInclus.textContent = inclus;
    if (elPayes)  elPayes.textContent  = payes;

    mettreAJourCompteurs();
  }

  function mettreAJourCompteurs() {
    const nb = document.querySelector('.pcc-section-hdr .nb');
    if (nb) nb.textContent = state.items.length + ' photos';
  }

  // ── Exports ─────────────────────────────────────────────────────────
  function bindExportBtns() {
    $('.pcc-btn--pdf')?.addEventListener('click', () => lancerExport('pdf'));
    $('.pcc-btn--csv')?.addEventListener('click', () => lancerExport('csv'));
    $('.pcc-btn--json')?.addEventListener('click', () => lancerExport('json'));
  }

  function lancerExport(format) {
    const overlay = $('.pcc-export-overlay');
    const sub     = overlay?.querySelector('.pcc-export-sub');

    if (overlay) overlay.classList.add('visible');
    if (sub) sub.textContent = format.toUpperCase() + ' en cours de génération…';

    fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'pc_catalogue_export',
        nonce:  CFG.nonceExport,
        format: format,
      }),
    })
    .then(r => {
      if (format === 'pdf') return r.blob();
      return r.json();
    })
    .then(data => {
      if (overlay) overlay.classList.remove('visible');

      if (format === 'pdf' && data instanceof Blob) {
        telecharger(data, `catalogue-${CFG.edition || 'export'}.pdf`, 'application/pdf');
        toast('PDF généré avec succès.', 'ok');
        return;
      }

      if (data.success) {
        const contenu  = data.data.contenu;
        const mime     = format === 'csv' ? 'text/csv' : 'application/json';
        const ext      = format === 'csv' ? 'csv' : 'json';
        const blob     = new Blob([contenu], { type: mime });
        telecharger(blob, `catalogue-${CFG.edition || 'export'}.${ext}`, mime);
        toast(`Export ${format.toUpperCase()} téléchargé.`, 'ok');
      } else {
        toast(data.data?.message || 'Erreur export.', 'err');
      }
    })
    .catch(() => {
      if (overlay) overlay.classList.remove('visible');
      toast('Erreur lors de l\'export.', 'err');
    });
  }

  function telecharger(blob, nom, mime) {
    const url = URL.createObjectURL(blob);
    const a   = document.createElement('a');
    a.href     = url;
    a.download = nom;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 500);
  }

  // ── Toast ────────────────────────────────────────────────────────────
  function toast(msg, type = 'info') {
    const container = $('.pcc-toasts');
    if (!container) return;
    const el = document.createElement('div');
    el.className = `pcc-toast pcc-toast--${type}`;
    el.textContent = msg;
    container.appendChild(el);
    setTimeout(() => {
      el.classList.add('pcc-toast--out');
      el.addEventListener('animationend', () => el.remove(), { once: true });
    }, 3200);
  }

  // ── Utilitaires ──────────────────────────────────────────────────────
  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ── Lancement ────────────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
