/**
 * Photo Contest — Interface Galerie Candidat
 * Gestion : upload AJAX, drag-and-drop réordonnancement, sélection, lightbox, filtres
 * v2 : rendu par sections de catégorie, upload ciblé par catégorie
 */
(function () {
  'use strict';

  // ── Configuration injectée par wp_localize_script ──────────────────
  const CFG = window.pcGalleryConfig || {};

  // Libellés statuts (injectés depuis PHP)
  const STATUTS_LABELS = CFG.statuts || {};

  // Dépôt actif (injecté depuis PHP)
  const PC_DEPOT_ACTIF = !!CFG.depotActif;

  // État global
  const state = {
    photos:           [],   // tableau plat dérivé de toutes les sections (compat filtres/lightbox)
    categories:       [],   // tableau de { id, nom, quota, photos[] }
    selection:        new Set(),
    filtreActif:      'tous',
    dragSrcId:        null,
    lightboxPhotoId:  null,
    uploadCategoryId: 0,    // catégorie cible de l'upload en cours
  };

  // ── Sélecteurs DOM ─────────────────────────────────────────────────
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

  let elSections, elSidebar, elToolbar, elDropZone, elFileInput,
      elBtnUpload, elBtnSupprSel, elSelectionInfo,
      elProgressWrap, elProgressBar, elLightbox,
      elToastContainer, elPageDropOverlay;

  // ── Init ───────────────────────────────────────────────────────────
  function init() {
    elSections       = $('#pc-gallery-sections');
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

    if (!elSections) return;

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
        state.categories = data.data.categories || [];
        // Tableau plat pour la compatibilité lightbox / filtres
        state.photos     = state.categories.flatMap(s => s.photos || []);
        // Catégorie cible upload : première section active avec quota disponible
        const firstActive = state.categories.find(s => s.id > 0 && (s.photos || []).length < s.quota);
        state.uploadCategoryId = firstActive ? firstActive.id : 0;
        renderGalerie();
        mettreAJourFiltres(data.data.stats_statuts || {});
      } else {
        elSections.innerHTML = '';
        afficherErreur(data.data?.message || 'Erreur de chargement.');
      }
    })
    .catch(() => afficherErreur('Connexion impossible.'));
  }

  // ── Rendu de la galerie en sections par catégorie ──────────────────
  function renderGalerie() {
    if (!elSections) return;
    elSections.innerHTML = '';
    state.selection.clear();
    mettreAJourBarre();

    if (state.categories.length === 0) {
      elSections.innerHTML = '<p class="pc-empty">Aucune catégorie disponible.</p>';
      return;
    }

    state.categories.forEach(section => {
      const photosFiltrees = filtrerPhotos(section.photos);
      const filled   = (section.photos || []).length;
      const quota    = section.quota || 0;
      const canAdd   = section.id > 0 && filled < quota && PC_DEPOT_ACTIF;

      const sec = document.createElement('section');
      sec.className   = 'pc-gallery-section';
      sec.dataset.catId = section.id;

      sec.innerHTML = `
        <header class="pc-gallery-section__header">
          <h2 class="pc-gallery-section__title">${escHTML(section.nom)}</h2>
          <span class="pc-gallery-section__count">${filled} / ${quota}</span>
        </header>
        <div class="pc-gallery-section__grid pc-grid" data-cat-id="${section.id}"></div>
        ${canAdd
          ? `<button class="pc-add-btn" data-cat-id="${section.id}">+ Ajouter une photo</button>`
          : (section.id > 0 ? `<p class="pc-add-disabled">Quota atteint</p>` : '')}
      `;

      const gridEl = sec.querySelector('.pc-gallery-section__grid');

      if (photosFiltrees.length === 0 && filled === 0) {
        gridEl.innerHTML = `<div class="pc-empty pc-empty--section">
          <div class="pc-empty__icon">&#9728;</div>
          <p class="pc-empty__sub">Aucune photo dans cette catégorie.</p>
        </div>`;
      } else {
        photosFiltrees.forEach(photo => gridEl.appendChild(creerCarte(photo)));
      }

      elSections.appendChild(sec);
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
      </div>`;

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

    // Badge payée / non payée
    var badge = document.createElement('span');
    badge.className = 'pc-pay-badge ' + (photo.paye ? 'pc-pay-badge--ok' : 'pc-pay-badge--ko');
    badge.textContent = photo.paye
      ? 'payée'
      : (CFG.depotActif ? 'non payée' : 'non payée — non examinée');
    div.appendChild(badge);

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

  // ── Filtres sidebar ────────────────────────────────────────────────
  /**
   * Filtre un tableau de photos par statut actif.
   * @param {Array} photos - tableau de photos (section ou global)
   */
  function filtrerPhotos(photos) {
    const src = photos || state.photos;
    if (state.filtreActif === 'tous') return src;
    return src.filter(p => p.statut === state.filtreActif);
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

    // F3 : afficher TOUS les statuts définis (count=0 inclus) pour que le candidat
    // visualise le pipeline complet (en_attente → en_cours_examen → retenue / refusée …).
    section.innerHTML = filtres
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

  // ── Upload ─────────────────────────────────────────────────────────
  function handleFiles(files) {
    if (state.uploadCategoryId <= 0) {
      toast('Veuillez choisir une catégorie via « + Ajouter une photo ».', 'erreur');
      return;
    }
    const fichiers = Array.from(files).filter(f => f.type === 'image/jpeg');
    if (fichiers.length === 0) {
      toast('Seules les images JPG sont acceptées.', 'erreur');
      return;
    }

    fichiers.forEach(fichier => uploadFichier(fichier));
  }

  function uploadFichier(fichier) {
    // Carte placeholder pendant l'upload — insérée dans la section cible
    const tempId    = 'temp_' + Date.now();
    const tempCarte = creerCarteUpload(tempId, fichier.name);

    const targetGrid = elSections
      ? elSections.querySelector(`.pc-gallery-section__grid[data-cat-id="${state.uploadCategoryId}"]`)
      : null;
    if (targetGrid) {
      targetGrid.prepend(tempCarte);
    } else if (elSections) {
      elSections.prepend(tempCarte);
    }

    const formData = new FormData();
    formData.append('action',      'pc_upload_photo');
    formData.append('nonce',       CFG.nonceUpload);
    formData.append('photo',       fichier);
    formData.append('category_id', state.uploadCategoryId);

    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', e => {
      if (e.lengthComputable) {
        const pct = Math.round((e.loaded / e.total) * 100);
        const pctEl = tempCarte.querySelector('.pc-upload-pct');
        if (pctEl) pctEl.textContent = pct + '%';
        if (elProgressBar) elProgressBar.style.width = pct + '%';
      }
    });

    xhr.addEventListener('load', () => {
      if (elProgressWrap) elProgressWrap.classList.remove('visible');
      if (elProgressBar)  elProgressBar.style.width = '0%';
      tempCarte.remove();

      try {
        const data = JSON.parse(xhr.responseText);
        if (data.success) {
          toast('Photo déposée avec succès.', 'succes');
          chargerPhotos(); // recharge complète pour synchro serveur + màj compteurs
        } else {
          toast(data.data?.message || 'Erreur lors du dépôt.', 'erreur');
        }
      } catch {
        toast('Réponse serveur invalide.', 'erreur');
      }
    });

    xhr.addEventListener('error', () => {
      if (elProgressWrap) elProgressWrap.classList.remove('visible');
      tempCarte.remove();
      toast('Erreur réseau lors du dépôt.', 'erreur');
    });

    if (elProgressWrap) elProgressWrap.classList.add('visible');
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

    const carte = elSections
      ? elSections.querySelector(`[data-id="${photoId}"]`)
      : null;
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

    Promise.all(ids.map(id => supprimerPhotoSilencieux(id)))
      .finally(() => chargerPhotos());
  }

  function supprimerPhotoSilencieux(photoId) {
    return fetch(CFG.ajaxUrl, {
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
        state.selection.delete(String(photoId));
      } else {
        toast(data.data?.message || 'Suppression impossible.', 'erreur');
      }
    })
    .catch(() => toast('Erreur réseau.', 'erreur'));
  }

  function supprimerPhoto(photoId) {
    supprimerPhotoSilencieux(photoId).finally(() => chargerPhotos());
  }

  // ── Drag & drop réordonnancement (intra-section) ───────────────────
  function initDragDrop() {
    if (!elSections) return;

    // Opère sur chaque grille de section indépendamment
    $$('.pc-gallery-section__grid', elSections).forEach(grid => {
      const cartes = $$('.pc-card[draggable="true"]', grid);

      cartes.forEach(carte => {
        carte.addEventListener('dragstart', e => {
          state.dragSrcId = carte.dataset.id;
          carte.classList.add('dragging');
          e.dataTransfer.effectAllowed = 'move';
        });

        carte.addEventListener('dragend', () => {
          carte.classList.remove('dragging');
          $$('.pc-card', grid).forEach(c => c.classList.remove('drag-over'));
          sauvegarderOrdre(grid);
        });

        carte.addEventListener('dragover', e => {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          if (carte.dataset.id !== state.dragSrcId) {
            $$('.pc-card', grid).forEach(c => c.classList.remove('drag-over'));
            carte.classList.add('drag-over');
          }
        });

        carte.addEventListener('drop', e => {
          e.preventDefault();
          if (!state.dragSrcId || carte.dataset.id === state.dragSrcId) return;

          const src  = grid.querySelector(`[data-id="${state.dragSrcId}"]`);
          if (!src) {
            toast('Le réordonnancement est limité à une même catégorie.', 'erreur');
            state.dragSrcId = null;
            return;
          }
          const dest = carte;
          if (!dest) return;

          const srcIdx  = [...grid.children].indexOf(src);
          const destIdx = [...grid.children].indexOf(dest);

          if (srcIdx < destIdx) {
            dest.after(src);
          } else {
            dest.before(src);
          }

          carte.classList.remove('drag-over');
        });
      });
    });
  }

  function peutEtreDeplace(photo) {
    return ['en_attente', 'refusee'].includes(photo.statut);
  }

  function sauvegarderOrdre(grid) {
    const ids = $$('.pc-card[data-id]', grid)
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
  // F4 : navigation clavier ← → et Esc, boutons prev/next, dans la liste
  // courante (filtrée par statut actif via filtrerPhotos()).
  function lightboxPhotosCourantes() {
    return filtrerPhotos(state.photos);
  }

  function ouvrirLightbox(photoId) {
    if (!elLightbox) return;
    const photos = lightboxPhotosCourantes();
    const index  = photos.findIndex(p => String(p.id) === String(photoId));
    if (index < 0) return;

    state.lightboxPhotoId = photoId;
    rendreLightbox(photos, index);

    elLightbox.classList.add('ouvert');
    document.body.style.overflow = 'hidden';

    // Listeners — re-créés à chaque ouverture, retirés à la fermeture
    document.addEventListener('keydown', onLightboxKey);
    elLightbox.addEventListener('click', onLightboxBgClick);
  }

  function rendreLightbox(photos, index) {
    const photo = photos[index];
    const total = photos.length;
    const hasPrev = index > 0;
    const hasNext = index < total - 1;

    elLightbox.innerHTML = `
      <button class="pc-lightbox__close" id="pc-lb-close" aria-label="Fermer">&#215;</button>
      ${hasPrev ? `<button class="pc-lightbox__nav pc-lightbox__nav--prev" id="pc-lb-prev" aria-label="Précédente">&#8592;</button>` : ''}
      ${hasNext ? `<button class="pc-lightbox__nav pc-lightbox__nav--next" id="pc-lb-next" aria-label="Suivante">&#8594;</button>` : ''}
      <div class="pc-lightbox__img-wrap">
        <img class="pc-lightbox__img" src="${escHTML(photo.url_full)}" alt="${escHTML(photo.titre || '')}">
        <div class="pc-lightbox__meta">
          <span>${escHTML(photo.titre || 'Sans titre')}</span>
          <span>${photo.largeur_px}&times;${photo.hauteur_px} px</span>
          <span class="pc-statut-${escHTML(photo.statut)}" style="padding:2px 8px;border-radius:3px;font-size:10px;font-family:var(--pc-font-mono)">
            ${escHTML(STATUTS_LABELS[photo.statut] || photo.statut)}
          </span>
          <span class="pc-lightbox__counter">${index + 1} / ${total}</span>
        </div>
      </div>`;

    state.lightboxPhotoId = photo.id;
    document.getElementById('pc-lb-close').addEventListener('click', fermerLightbox);
    document.getElementById('pc-lb-prev')?.addEventListener('click', e => { e.stopPropagation(); naviguerLightbox(-1); });
    document.getElementById('pc-lb-next')?.addEventListener('click', e => { e.stopPropagation(); naviguerLightbox(+1); });
  }

  function naviguerLightbox(delta) {
    const photos = lightboxPhotosCourantes();
    const idx    = photos.findIndex(p => String(p.id) === String(state.lightboxPhotoId));
    if (idx < 0) return;
    const target = idx + delta;
    if (target < 0 || target >= photos.length) return;
    rendreLightbox(photos, target);
  }

  function onLightboxKey(e) {
    if (!elLightbox?.classList.contains('ouvert')) return;
    // Ne pas capturer si on édite un titre (input focus)
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    switch (e.key) {
      case 'Escape':    fermerLightbox(); break;
      case 'ArrowLeft':  naviguerLightbox(-1); e.preventDefault(); break;
      case 'ArrowRight': naviguerLightbox(+1); e.preventDefault(); break;
    }
  }

  function onLightboxBgClick(e) {
    if (e.target === elLightbox) fermerLightbox();
  }

  function fermerLightbox() {
    if (!elLightbox) return;
    elLightbox.classList.remove('ouvert');
    elLightbox.innerHTML = '';
    document.body.style.overflow = '';
    state.lightboxPhotoId = null;
    document.removeEventListener('keydown', onLightboxKey);
    elLightbox.removeEventListener('click', onLightboxBgClick);
  }

  // ── Skeletons ─────────────────────────────────────────────────────
  function afficherSkeletons(nb) {
    if (!elSections) return;
    elSections.innerHTML = '';
    // Affiche les skeletons dans un conteneur temporaire
    const tempGrid = document.createElement('div');
    tempGrid.className = 'pc-gallery-section__grid pc-grid';
    for (let i = 0; i < nb; i++) {
      const div = document.createElement('div');
      div.className = 'pc-card pc-card--skeleton';
      tempGrid.appendChild(div);
    }
    elSections.appendChild(tempGrid);
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
      en_attente:   '#4a7fa5',
      en_examen:    '#c49a3c',
      retenue:      '#4da876',
      refusee:      '#b05050',
      au_catalogue: '#c49a3c',
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

    // Délégation pour les boutons "+ Ajouter une photo" par section
    if (elSections) {
      elSections.addEventListener('click', e => {
        const btn = e.target.closest('.pc-add-btn');
        if (!btn) return;
        state.uploadCategoryId = parseInt(btn.dataset.catId, 10) || 0;
        if (elFileInput) elFileInput.click();
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
