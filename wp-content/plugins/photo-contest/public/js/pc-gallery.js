/**
 * Photo Contest — Interface Galerie Candidat
 * Gestion : upload AJAX, drag-and-drop réordonnancement, sélection, lightbox, filtres
 */
(function () {
  'use strict';

  // ── Configuration injectée par wp_localize_script ──────────────────
  const CFG = window.pcGalleryConfig || {};

  // Libellés statuts (injectés depuis PHP)
  const STATUTS_LABELS = CFG.statuts || {};

  // État global
  const state = {
    photos:         [],   // tableau d'objets photo
    selection:      new Set(),
    filtreActif:    'tous',
    dragSrcId:      null,
    lightboxPhotoId: null,
  };

  // ── Sélecteurs DOM ─────────────────────────────────────────────────
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

  let elGrid, elSidebar, elToolbar, elDropZone, elFileInput,
      elBtnUpload, elBtnSupprSel, elSelectionInfo,
      elProgressWrap, elProgressBar, elLightbox,
      elToastContainer, elPageDropOverlay,
      elQuotaFill, elQuotaLabel;

  // ── Init ───────────────────────────────────────────────────────────
  function init() {
    elGrid           = $('.pc-grid');
    elSidebar        = $('.pc-sidebar');
    elDropZone       = $('.pc-drop-zone');
    elFileInput      = $('.pc-drop-zone__input');
    elBtnUpload      = $('.pc-btn-upload');
    elBtnSupprSel    = $('.pc-btn-suppr-sel');
    elSelectionInfo  = $('.pc-selection-info');
    elProgressWrap   = $('.pc-progress-wrap');
    elProgressBar    = $('.pc-progress-bar');
    elLightbox       = $('.pc-lightbox');
    elToastContainer = $('.pc-toast-container');
    elPageDropOverlay = $('.pc-page-drop-overlay');
    elQuotaFill      = $('.pc-quota-bar__fill');
    elQuotaLabel     = $('.pc-quota-label');

    if (!elGrid) return;

    bindEvents();
    chargerPhotos();
  }

  // ── Chargement initial des photos depuis l'API ─────────────────────
  function chargerPhotos() {
    afficherSkeletons(4);

    fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'pc_get_photos',
        nonce:  CFG.nonceGet,
      }),
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        state.photos = data.data.photos || [];
        renderGalerie();
        mettreAJourQuota(data.data.quota_utilise, data.data.quota_max);
        mettreAJourFiltres(data.data.stats_statuts || {});
      } else {
        elGrid.innerHTML = '';
        afficherErreur(data.data?.message || 'Erreur de chargement.');
      }
    })
    .catch(() => afficherErreur('Connexion impossible.'));
  }

  // ── Rendu de la grille ─────────────────────────────────────────────
  function renderGalerie() {
    elGrid.innerHTML = '';
    state.selection.clear();
    mettreAJourBarre();

    const photosFiltrees = filtrerPhotos();

    if (photosFiltrees.length === 0) {
      elGrid.parentElement.innerHTML += templateVide();
      return;
    }

    // Retire le message vide éventuel
    const vide = document.querySelector('.pc-empty');
    if (vide) vide.remove();

    photosFiltrees.forEach(photo => {
      const carte = creerCarte(photo);
      elGrid.appendChild(carte);
    });

    initDragDrop();
  }

  // ── Template carte photo ───────────────────────────────────────────
  function creerCarte(photo) {
    const div = document.createElement('div');
    div.className = 'pc-card';
    div.dataset.id    = photo.id;
    div.dataset.ratio = photo.ratio_type;
    div.setAttribute('draggable', peutEtreDeplace(photo) ? 'true' : 'false');

    const labelStatut  = STATUTS_LABELS[photo.statut] || photo.statut;
    const peutSuppr    = ['en_attente', 'refusee'].includes(photo.statut);
    const peutPayer    = photo.statut === 'participation_demandee' && photo.url_paiement;
    const urlThumb     = photo.url_thumb;

    div.innerHTML = `
      <img class="pc-card__img pc-card__img--loading"
           src="${escHTML(urlThumb)}"
           alt="${escHTML(photo.titre || 'Photo ' + photo.ordre_affichage)}"
           loading="lazy"
           draggable="false">
      <div class="pc-card__overlay"></div>
      <span class="pc-card__num">${String(photo.ordre_affichage).padStart(2, '0')}</span>
      <span class="pc-card__statut pc-statut-${escHTML(photo.statut)}">${escHTML(labelStatut)}</span>
      <div class="pc-card__titre-wrap" title="Double-clic pour renommer">
        <span class="pc-card__titre-txt">${escHTML(photo.titre || 'Sans titre')}</span>
        <input class="pc-card__titre-input" type="text"
               value="${escHTML(photo.titre || '')}"
               placeholder="Titre…"
               style="display:none">
      </div>
      <div class="pc-card__actions">
        <button class="pc-card__action-btn pc-card__action-btn--voir"
                data-action="voir" data-id="${photo.id}">Voir</button>
        ${peutSuppr ? `<button class="pc-card__action-btn pc-card__action-btn--suppr"
                data-action="supprimer" data-id="${photo.id}">Supprimer</button>` : ''}
        ${peutPayer ? `<a class="pc-card__action-btn pc-card__action-btn--payer"
                href="${escHTML(photo.url_paiement)}">Payer</a>` : ''}
      </div>`;

    if ( peutPayer ) {
      div.classList.add('pc-card--paiement-requis');
      const actions = div.querySelector('.pc-card__actions');
      if (actions) {
        actions.style.opacity = '1';
        actions.style.transform = 'translateY(0)';
        actions.style.pointerEvents = 'auto';
      }
    }

    // Lazy load avec fade-in
    const img = div.querySelector('.pc-card__img');
    img.onload = () => img.classList.remove('pc-card__img--loading');

    // Édition inline du titre (double-clic)
    const titreTxt   = div.querySelector('.pc-card__titre-txt');
    const titreInput = div.querySelector('.pc-card__titre-input');
    if (titreTxt && titreInput) {
      titreTxt.addEventListener('dblclick', e => {
        e.stopPropagation();
        titreTxt.style.display = 'none';
        titreInput.style.display = 'block';
        titreInput.focus();
        titreInput.select();
      });
      titreInput.addEventListener('blur', () => sauvegarderTitre(photo.id, titreInput, titreTxt));
      titreInput.addEventListener('keydown', e => {
        if (e.key === 'Enter')  { e.preventDefault(); titreInput.blur(); }
        if (e.key === 'Escape') { titreInput.value = photo.titre || ''; titreInput.blur(); }
        e.stopPropagation();
      });
      titreInput.addEventListener('click', e => e.stopPropagation());
    }

    // Sélection par clic
    div.addEventListener('click', e => {
      if (e.target.dataset.action) return; // délégué aux boutons
      toggleSelection(photo.id);
    });

    // Boutons action
    div.addEventListener('click', e => {
      const btn = e.target.closest('[data-action]');
      if (!btn) return;
      e.stopPropagation();
      if (btn.dataset.action === 'voir')      ouvrirLightbox(photo.id);
      if (btn.dataset.action === 'supprimer') confirmerSuppression([photo.id]);
    });

    return div;
  }

  function templateVide() {
    return `<div class="pc-empty">
      <div class="pc-empty__icon">&#9728;</div>
      <p class="pc-empty__titre">Aucune photo déposée</p>
      <p class="pc-empty__sub">Glissez vos photos dans la zone ci-dessous ou cliquez sur « Ajouter ».</p>
    </div>`;
  }

  // ── Filtres sidebar ────────────────────────────────────────────────
  function filtrerPhotos() {
    if (state.filtreActif === 'tous') return state.photos;
    return state.photos.filter(p => p.statut === state.filtreActif);
  }

  function mettreAJourFiltres(stats) {
    if (!elSidebar) return;
    const section = elSidebar.querySelector('.pc-sidebar__filtres');
    if (!section) return;

    const total = state.photos.length;
    const filtres = [
      { slug: 'tous', label: 'Toutes', count: total },
      ...Object.entries(STATUTS_LABELS).map(([slug, label]) => ({
        slug, label, count: stats[slug] || 0,
      })),
    ];

    section.innerHTML = filtres
      .filter(f => f.slug === 'tous' || f.count > 0)
      .map(f => `
        <button class="pc-filter-btn ${state.filtreActif === f.slug ? 'actif' : ''}"
                data-filtre="${f.slug}">
          <span class="pc-filter-dot" style="background:${couleurStatut(f.slug)}"></span>
          <span>${escHTML(f.label)}</span>
          <span class="pc-filter-count">${f.count}</span>
        </button>`)
      .join('');

    section.querySelectorAll('.pc-filter-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        state.filtreActif = btn.dataset.filtre;
        section.querySelectorAll('.pc-filter-btn').forEach(b => b.classList.remove('actif'));
        btn.classList.add('actif');
        renderGalerie();
      });
    });
  }

  // ── Quota ──────────────────────────────────────────────────────────
  function mettreAJourQuota(utilise, max) {
    if (!elQuotaFill || !elQuotaLabel) return;
    const pct = max > 0 ? Math.round((utilise / max) * 100) : 0;
    elQuotaFill.style.width = pct + '%';
    elQuotaFill.classList.toggle('pc-quota-bar__fill--plein', utilise >= max);
    elQuotaLabel.textContent = `${utilise} / ${max}`;

    if (elBtnUpload) elBtnUpload.disabled = (utilise >= max);
    if (elFileInput) elFileInput.disabled = (utilise >= max);
  }

  // ── Upload ─────────────────────────────────────────────────────────
  function handleFiles(files) {
    const fichiers = Array.from(files).filter(f => f.type === 'image/jpeg');
    if (fichiers.length === 0) {
      toast('Seules les images JPG sont acceptées.', 'erreur');
      return;
    }

    fichiers.forEach(fichier => uploadFichier(fichier));
  }

  function uploadFichier(fichier) {
    // Carte placeholder pendant l'upload
    const tempId   = 'temp_' + Date.now();
    const tempCarte = creerCarteUpload(tempId, fichier.name);
    elGrid.prepend(tempCarte);

    const formData = new FormData();
    formData.append('action', 'pc_upload_photo');
    formData.append('nonce',  CFG.nonceUpload);
    formData.append('photo',  fichier);

    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable) {
        const pct = Math.round((e.loaded / e.total) * 100);
        const pctEl = tempCarte.querySelector('.pc-upload-pct');
        if (pctEl) pctEl.textContent = pct + '%';
        elProgressBar.style.width = pct + '%';
      }
    });

    xhr.addEventListener('load', () => {
      elProgressWrap.classList.remove('visible');
      elProgressBar.style.width = '0%';
      tempCarte.remove();

      try {
        const data = JSON.parse(xhr.responseText);
        if (data.success) {
          toast('Photo déposée avec succès.', 'succes');
          chargerPhotos(); // recharge complète pour synchro serveur
        } else {
          toast(data.data?.message || 'Erreur lors du dépôt.', 'erreur');
        }
      } catch {
        toast('Réponse serveur invalide.', 'erreur');
      }
    });

    xhr.addEventListener('error', () => {
      elProgressWrap.classList.remove('visible');
      tempCarte.remove();
      toast('Erreur réseau lors du dépôt.', 'erreur');
    });

    elProgressWrap.classList.add('visible');
    xhr.open('POST', CFG.ajaxUrl);
    xhr.send(formData);
  }

  function creerCarteUpload(tempId, nom) {
    const div = document.createElement('div');
    div.className  = 'pc-card pc-card--uploading';
    div.dataset.id = tempId;
    div.innerHTML  = `
      <div class="pc-card__upload-progress">
        <div class="pc-upload-spinner"></div>
        <span class="pc-upload-pct">0%</span>
      </div>`;
    return div;
  }

  // ── Sélection ──────────────────────────────────────────────────────
  function toggleSelection(photoId) {
    photoId = String(photoId);
    if (state.selection.has(photoId)) {
      state.selection.delete(photoId);
    } else {
      state.selection.add(photoId);
    }

    const carte = elGrid.querySelector(`[data-id="${photoId}"]`);
    if (carte) carte.classList.toggle('selectionne', state.selection.has(photoId));

    mettreAJourBarre();
  }

  function mettreAJourBarre() {
    const nb = state.selection.size;
    if (elSelectionInfo) {
      elSelectionInfo.textContent = nb > 0
        ? `${nb} photo${nb > 1 ? 's' : ''} sélectionnée${nb > 1 ? 's' : ''}`
        : '';
    }
    if (elBtnSupprSel) elBtnSupprSel.disabled = nb === 0;
  }

  // ── Suppression ────────────────────────────────────────────────────
  function confirmerSuppression(ids) {
    const nb  = ids.length;
    const msg = nb === 1
      ? 'Supprimer cette photo ?'
      : `Supprimer ces ${nb} photos ?`;

    if (!confirm(msg)) return;

    ids.forEach(id => supprimerPhoto(id));
  }

  function supprimerPhoto(photoId) {
    fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action:   'pc_delete_photo',
        nonce:    CFG.nonceDelete,
        photo_id: photoId,
      }),
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        toast('Photo supprimée.', 'succes');
        state.photos = state.photos.filter(p => String(p.id) !== String(photoId));
        state.selection.delete(String(photoId));
        renderGalerie();
        mettreAJourQuota(state.photos.length, parseInt(elQuotaLabel?.textContent?.split('/')[1]) || CFG.quotaMax);
      } else {
        toast(data.data?.message || 'Suppression impossible.', 'erreur');
      }
    })
    .catch(() => toast('Erreur réseau.', 'erreur'));
  }

  // ── Drag & drop réordonnancement ───────────────────────────────────
  function initDragDrop() {
    const cartes = elGrid.querySelectorAll('.pc-card[draggable="true"]');

    cartes.forEach(carte => {
      carte.addEventListener('dragstart', e => {
        state.dragSrcId = carte.dataset.id;
        carte.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
      });

      carte.addEventListener('dragend', () => {
        carte.classList.remove('dragging');
        elGrid.querySelectorAll('.pc-card').forEach(c => c.classList.remove('drag-over'));
        sauvegarderOrdre();
      });

      carte.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (carte.dataset.id !== state.dragSrcId) {
          elGrid.querySelectorAll('.pc-card').forEach(c => c.classList.remove('drag-over'));
          carte.classList.add('drag-over');
        }
      });

      carte.addEventListener('drop', e => {
        e.preventDefault();
        if (!state.dragSrcId || carte.dataset.id === state.dragSrcId) return;

        const src  = elGrid.querySelector(`[data-id="${state.dragSrcId}"]`);
        const dest = carte;
        if (!src || !dest) return;

        const srcIdx  = [...elGrid.children].indexOf(src);
        const destIdx = [...elGrid.children].indexOf(dest);

        if (srcIdx < destIdx) {
          dest.after(src);
        } else {
          dest.before(src);
        }

        carte.classList.remove('drag-over');
      });
    });
  }

  function peutEtreDeplace(photo) {
    return ['en_attente', 'refusee'].includes(photo.statut);
  }

  function sauvegarderOrdre() {
    const ids = [...elGrid.querySelectorAll('.pc-card[data-id]')]
      .map(c => c.dataset.id)
      .filter(id => !id.startsWith('temp_'));

    if (!ids.length) return;

    const body = new URLSearchParams({ action: 'pc_reorder_photos', nonce: CFG.nonceReorder });
    ids.forEach((id, i) => body.append(`ids[${i}]`, id));

    fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    }).catch(() => {/* silencieux */});
  }

  // ── Lightbox ───────────────────────────────────────────────────────
  function ouvrirLightbox(photoId) {
    const photo = state.photos.find(p => String(p.id) === String(photoId));
    if (!photo || !elLightbox) return;

    state.lightboxPhotoId = photoId;

    elLightbox.innerHTML = `
      <button class="pc-lightbox__close" id="pc-lb-close">&#215;</button>
      <div class="pc-lightbox__img-wrap">
        <img class="pc-lightbox__img" src="${escHTML(photo.url_full)}" alt="${escHTML(photo.titre || '')}">
        <div class="pc-lightbox__meta">
          <span>${escHTML(photo.titre || 'Sans titre')}</span>
          <span>${photo.largeur_px}&times;${photo.hauteur_px} px</span>
          <span class="pc-statut-${escHTML(photo.statut)}" style="padding:2px 8px;border-radius:3px;font-size:10px;font-family:var(--pc-font-mono)">
            ${escHTML(STATUTS_LABELS[photo.statut] || photo.statut)}
          </span>
        </div>
      </div>`;

    elLightbox.classList.add('ouvert');
    document.body.style.overflow = 'hidden';

    document.getElementById('pc-lb-close').addEventListener('click', fermerLightbox);
    elLightbox.addEventListener('click', e => {
      if (e.target === elLightbox) fermerLightbox();
    });
  }

  function fermerLightbox() {
    if (!elLightbox) return;
    elLightbox.classList.remove('ouvert');
    elLightbox.innerHTML = '';
    document.body.style.overflow = '';
    state.lightboxPhotoId = null;
  }

  // ── Skeletons ─────────────────────────────────────────────────────
  function afficherSkeletons(nb) {
    elGrid.innerHTML = '';
    for (let i = 0; i < nb; i++) {
      const div = document.createElement('div');
      div.className = 'pc-card pc-card--skeleton';
      elGrid.appendChild(div);
    }
  }

  // ── Toast ──────────────────────────────────────────────────────────
  function toast(msg, type = 'info') {
    if (!elToastContainer) return;
    const el = document.createElement('div');
    el.className = `pc-toast pc-toast--${type}`;
    el.textContent = msg;
    elToastContainer.appendChild(el);

    setTimeout(() => {
      el.classList.add('pc-toast--sortie');
      el.addEventListener('animationend', () => el.remove());
    }, 3500);
  }

  function afficherErreur(msg) {
    toast(msg, 'erreur');
  }

  // ── Drag-and-drop page entière ─────────────────────────────────────
  function initPageDrop() {
    let dragCounter = 0;

    document.addEventListener('dragenter', e => {
      if (!e.dataTransfer?.types?.includes('Files')) return;
      dragCounter++;
      if (elPageDropOverlay) elPageDropOverlay.classList.add('visible');
    });

    document.addEventListener('dragleave', () => {
      dragCounter--;
      if (dragCounter <= 0) {
        dragCounter = 0;
        if (elPageDropOverlay) elPageDropOverlay.classList.remove('visible');
      }
    });

    document.addEventListener('dragover', e => e.preventDefault());

    document.addEventListener('drop', e => {
      e.preventDefault();
      dragCounter = 0;
      if (elPageDropOverlay) elPageDropOverlay.classList.remove('visible');

      const files = e.dataTransfer?.files;
      if (files?.length) handleFiles(files);
    });
  }

  // ── Couleur de point filtre sidebar ───────────────────────────────
  function couleurStatut(slug) {
    const map = {
      en_attente:             '#4a7fa5',
      en_examen:              '#c49a3c',
      retenue:                '#4da876',
      refusee:                '#b05050',
      participation_demandee: '#9a6ec4',
      paiement_recu:          '#4da876',
      au_catalogue:           '#c49a3c',
    };
    return map[slug] || '#555450';
  }

  // ── Sauvegarde titre inline ────────────────────────────────────────
  function sauvegarderTitre(photoId, input, txtEl) {
    const nouveauTitre = input.value.trim();
    txtEl.textContent  = nouveauTitre || 'Sans titre';
    txtEl.style.display = '';
    input.style.display = 'none';

    // Mise à jour locale dans state.photos
    const photo = state.photos.find(p => String(p.id) === String(photoId));
    if (photo) photo.titre = nouveauTitre;

    fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action:   'pc_update_titre',
        nonce:    CFG.nonceTitre,
        photo_id: photoId,
        titre:    nouveauTitre,
      }),
    })
    .then(r => r.json())
    .then(data => {
      if (!data.success) toast('Erreur sauvegarde du titre.', 'erreur');
    })
    .catch(() => toast('Erreur réseau.', 'erreur'));
  }

  // ── Utilitaires ────────────────────────────────────────────────────
  function escHTML(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Binding events ────────────────────────────────────────────────
  function bindEvents() {
    // Upload via bouton / zone de drop barre basse
    if (elFileInput) {
      elFileInput.addEventListener('change', e => handleFiles(e.target.files));
    }

    if (elDropZone) {
      elDropZone.addEventListener('dragover',  e => { e.preventDefault(); elDropZone.classList.add('dragover'); });
      elDropZone.addEventListener('dragleave', ()  => elDropZone.classList.remove('dragover'));
      elDropZone.addEventListener('drop', e => {
        e.preventDefault();
        elDropZone.classList.remove('dragover');
        if (e.dataTransfer?.files?.length) handleFiles(e.dataTransfer.files);
      });
    }

    // Suppression de la sélection
    if (elBtnSupprSel) {
      elBtnSupprSel.addEventListener('click', () => {
        if (state.selection.size === 0) return;
        confirmerSuppression([...state.selection]);
      });
    }

    // Fermeture lightbox au clavier
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && state.lightboxPhotoId) fermerLightbox();
    });

    // Drag-and-drop depuis l'explorateur de fichiers
    initPageDrop();
  }

  // ── Lancement ─────────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
