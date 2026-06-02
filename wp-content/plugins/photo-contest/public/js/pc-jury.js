/**
 * Photo Contest — Interface Jury
 * Navigation clavier (←/→/R/X), vote AJAX, commentaires, stats en temps réel
 */
(function () {
  'use strict';

  const CFG = window.pcJuryConfig || {};

  // ── État global ───────────────────────────────────────────────────
  const state = {
    photos:       [],   // liste complète
    index:        0,    // photo courante
    votes:        {},   // { photo_id: { decision, commentaire } }
    enVote:       false,// verrou anti-doublon
  };

  let activeCatId = 0; // 0 = toutes les catégories

  // ── Sélecteurs ────────────────────────────────────────────────────
  const $ = s => document.querySelector(s);
  const $$ = s => [...document.querySelectorAll(s)];

  let elImg, elNum, elFlash, elPrev, elNext,
      elStrip, elPanel, elVoteConfirme,
      elBtnRetenu, elBtnRefuse, elBtnPasser, elBtnModifier,
      elComment, elProgressFill, elProgressPct,
      elCounter, elToastContainer;

  // ── Init ──────────────────────────────────────────────────────────
  function init() {
    elImg          = $('.pcj-img');
    elNum          = $('.pcj-viewer__num');
    elFlash        = $('.pcj-vote-flash');
    elPrev         = $('.pcj-nav-btn--prev');
    elNext         = $('.pcj-nav-btn--next');
    elStrip        = $('.pcj-strip');
    elVoteConfirme = $('.pcj-vote-confirme');
    elBtnRetenu    = $('.pcj-vote-btn--retenu');
    elBtnRefuse    = $('.pcj-vote-btn--refuse');
    elBtnPasser    = $('.pcj-btn-passer');
    elBtnModifier  = $('.pcj-btn-modifier');
    elComment      = $('.pcj-comment');
    elProgressFill = $('.pcj-progress-bar-fill');
    elProgressPct  = $('.pcj-progress-pct');
    elCounter      = $('.pcj-counter');
    elToastContainer = $('.pcj-toast-container');

    if (!elImg) return;

    bindEvents();
    chargerPhotos();
  }

  // ── Chargement des photos à délibérer ─────────────────────────────
  function chargerPhotos() {
    fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'pc_jury_get_photos', nonce: CFG.nonceGet, category_id: activeCatId }),
    })
    .then(r => r.json())
    .then(data => {
      if (!data.success) { toast(data.data?.message || 'Erreur.', 'info'); return; }
      state.photos = data.data.photos || [];
      state.votes  = data.data.mes_votes || {};

      // Trouver la première photo sans vote personnel
      const idx = state.photos.findIndex(p => !state.votes[p.id]);
      state.index = idx >= 0 ? idx : 0;

      construireStrip();
      afficherPhoto(state.index);
      mettreAJourProgression();
    })
    .catch(() => toast('Connexion impossible.', 'info'));
  }

  // ── Strip de navigation ───────────────────────────────────────────
  function construireStrip() {
    if (!elStrip) return;
    elStrip.innerHTML = '';

    state.photos.forEach((photo, i) => {
      const div = document.createElement('div');
      div.className = 'pcj-thumb';
      div.dataset.index = i;

      const img = document.createElement('img');
      img.src = photo.url_thumb;
      img.alt = '';
      img.loading = 'lazy';
      div.appendChild(img);

      const dot = document.createElement('span');
      dot.className = 'pcj-thumb__dot';
      div.appendChild(dot);

      appliquerClasseVote(div, dot, state.votes[photo.id]?.decision);

      div.addEventListener('click', () => allerA(i));
      elStrip.appendChild(div);
    });
  }

  function appliquerClasseVote(thumb, dot, decision) {
    thumb.classList.remove('vote-retenu', 'vote-refuse');
    dot.style.background = '';

    if (decision === 'retenue') {
      thumb.classList.add('vote-retenu');
      dot.style.background = '#4da876';
    } else if (decision === 'refusee') {
      thumb.classList.add('vote-refuse');
      dot.style.background = '#b05050';
    } else {
      dot.style.background = '';
    }
  }

  // ── Affichage photo ───────────────────────────────────────────────
  function afficherPhoto(index) {
    if (!state.photos.length) { afficherFin(); return; }
    index = Math.max(0, Math.min(index, state.photos.length - 1));
    state.index = index;

    const photo = state.photos[index];

    // Mise à jour image
    if (elImg) {
      elImg.classList.add('chargement');
      elImg.src = photo.url_full;
      elImg.onload = () => elImg.classList.remove('chargement');
    }

    // Numéro décoratif
    if (elNum) elNum.textContent = String(index + 1).padStart(3, '0');

    // Compteur
    if (elCounter) {
      elCounter.innerHTML = `Photo <strong>${index + 1}</strong> / ${state.photos.length}`;
    }

    // Métadonnées (anonymisées)
    mettreAJourMeta(photo);

    // Navigation
    if (elPrev) elPrev.disabled = index === 0;
    if (elNext) elNext.disabled = index === state.photos.length - 1;

    // Strip : scroll vers la miniature active
    const thumbActif = elStrip?.querySelector(`[data-index="${index}"]`);
    if (thumbActif) {
      elStrip.querySelectorAll('.pcj-thumb').forEach(t => t.classList.remove('actif'));
      thumbActif.classList.add('actif');
      thumbActif.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    }

    // Panneau vote : état existant ?
    const voteExistant = state.votes[photo.id];
    afficherEtatVote(voteExistant);
  }

  // ── Métadonnées anonymisées ───────────────────────────────────────
  function mettreAJourMeta(photo) {
    const sel = '.pcj-meta-row .val';
    const rows = $$('.pcj-meta-row');

    rows.forEach(row => {
      const key = row.querySelector('.key')?.textContent?.trim();
      const val = row.querySelector('.val');
      if (!val) return;

      if (key === 'Réf.') val.textContent = '#' + String(photo.id).padStart(5, '0');
      if (key === 'Catégorie') val.textContent = photo.nom_categorie || '—';
      if (key === 'Dimensions')  val.textContent = photo.largeur_px + ' × ' + photo.hauteur_px + ' px';
      if (key === 'Ratio')  val.textContent = photo.ratio_type === '3_2' ? '3:2 — Paysage' : '2:3 — Portrait';
      if (key === 'Poids')  val.textContent = formatPoids(photo.taille_octets);
    });
  }

  // ── État vote (déjà voté / pas encore) ───────────────────────────
  function afficherEtatVote(vote) {
    if (!elVoteConfirme) return;

    if (vote) {
      // Vote existant : afficher le récap
      elVoteConfirme.className = 'pcj-vote-confirme visible ' + (vote.decision === 'retenue' ? 'retenu' : 'refusee');
      elVoteConfirme.querySelector('.pcj-vote-confirme__txt').textContent =
        vote.decision === 'retenue' ? 'Retenue' : 'Refusée';

      if (elComment) elComment.value = vote.commentaire || '';

      // Masquer les boutons de vote principaux
      if (elBtnRetenu) elBtnRetenu.style.opacity = '.4';
      if (elBtnRefuse) elBtnRefuse.style.opacity = '.4';
      if (elBtnPasser) elBtnPasser.style.display = 'none';

    } else {
      // Pas encore voté
      elVoteConfirme.className = 'pcj-vote-confirme';
      if (elComment) elComment.value = '';
      if (elBtnRetenu) { elBtnRetenu.style.opacity = ''; elBtnRetenu.classList.remove('actif'); }
      if (elBtnRefuse) { elBtnRefuse.style.opacity = ''; elBtnRefuse.classList.remove('actif'); }
      if (elBtnPasser) elBtnPasser.style.display = '';
    }
  }

  // ── Voter ─────────────────────────────────────────────────────────
  function voter(decision) {
    if (state.enVote || !state.photos.length) return;
    const photo   = state.photos[state.index];
    const comment = elComment?.value.trim() || '';

    // Feedback visuel immédiat
    flashVote(decision);
    if (elBtnRetenu) elBtnRetenu.classList.toggle('actif', decision === 'retenue');
    if (elBtnRefuse) elBtnRefuse.classList.toggle('actif', decision === 'refusee');

    state.enVote = true;

    fetch(CFG.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action:      'pc_jury_voter',
        nonce:       CFG.nonceVote,
        photo_id:    photo.id,
        decision:    decision,
        commentaire: comment,
      }),
    })
    .then(r => r.json())
    .then(data => {
      state.enVote = false;

      if (data.success) {
        // Enregistrer le vote local
        state.votes[photo.id] = { decision, commentaire: comment };

        // Mettre à jour la miniature strip
        const thumb = elStrip?.querySelector(`[data-index="${state.index}"]`);
        const dot   = thumb?.querySelector('.pcj-thumb__dot');
        if (thumb && dot) appliquerClasseVote(thumb, dot, decision);

        afficherEtatVote({ decision, commentaire: comment });
        mettreAJourProgression();

        const label = decision === 'retenue' ? 'Retenue' : 'Refusée';
        toast(`Photo #${String(photo.id).padStart(5,'0')} — ${label}`, decision === 'retenue' ? 'retenu' : 'refusee');

        // Auto-avancer vers la prochaine photo sans vote
        setTimeout(() => avancerVersProchaineSansVote(), 600);

        // Notifier le statut automatique si renvoyé
        if (data.data?.nouveau_statut) {
          setTimeout(() => toast(
            `Délibération complète — photo classée "${data.data.libelle_statut}"`, 'info'
          ), 800);
        }
      } else {
        toast(data.data?.message || 'Erreur lors du vote.', 'info');
        if (elBtnRetenu) elBtnRetenu.classList.remove('actif');
        if (elBtnRefuse) elBtnRefuse.classList.remove('actif');
      }
    })
    .catch(() => {
      state.enVote = false;
      toast('Erreur réseau.', 'info');
    });
  }

  // ── Navigation ────────────────────────────────────────────────────
  function allerA(index) {
    afficherPhoto(index);
  }

  function avancerVersProchaineSansVote() {
    // Cherche d'abord après l'index courant, puis au début
    for (let i = state.index + 1; i < state.photos.length; i++) {
      if (!state.votes[state.photos[i].id]) { allerA(i); return; }
    }
    for (let i = 0; i < state.index; i++) {
      if (!state.votes[state.photos[i].id]) { allerA(i); return; }
    }
    // Toutes votées
    mettreAJourProgression();
  }

  // ── Progression ───────────────────────────────────────────────────
  function mettreAJourProgression() {
    const total  = state.photos.length;
    const votes  = Object.keys(state.votes).length;
    const retenus = Object.values(state.votes).filter(v => v.decision === 'retenue').length;
    const refuses = Object.values(state.votes).filter(v => v.decision === 'refusee').length;
    const pct    = total > 0 ? Math.round((votes / total) * 100) : 0;

    if (elProgressFill) elProgressFill.style.width = pct + '%';
    if (elProgressPct)  elProgressPct.textContent  = pct + '%';

    // Stats panneau
    const statVotes   = $('.pcj-stats .stat-votes .val');
    const statRetenus = $('.pcj-stats .stat-retenus .val');
    const statRefuses = $('.pcj-stats .stat-refuses .val');
    const barRetenus  = $('.pcj-stats .bar-retenus');
    const barRefuses  = $('.pcj-stats .bar-refuses');

    if (statVotes)   statVotes.textContent   = votes + ' / ' + total;
    if (statRetenus) statRetenus.textContent = retenus;
    if (statRefuses) statRefuses.textContent = refuses;

    if (barRetenus && votes > 0)
      barRetenus.style.width = Math.round((retenus / votes) * 100) + '%';
    if (barRefuses && votes > 0)
      barRefuses.style.width = Math.round((refuses / votes) * 100) + '%';

    if (pct === 100) {
      setTimeout(() => toast('Session terminée — toutes les photos ont été délibérées.', 'info'), 400);
    }
  }

  // ── Flash visuel lors d'un vote ───────────────────────────────────
  function flashVote(decision) {
    if (!elFlash) return;
    elFlash.classList.remove('retenu', 'refuse', 'actif');
    void elFlash.offsetWidth; // reflow
    elFlash.classList.add(decision === 'retenue' ? 'retenu' : 'refusee', 'actif');
    setTimeout(() => elFlash.classList.remove('actif'), 250);
  }

  // ── Afficher écran de fin ─────────────────────────────────────────
  function afficherFin() {
    const viewer = $('.pcj-viewer');
    if (!viewer) return;
    viewer.innerHTML = `<div class="pcj-fin">
      <p class="pcj-fin__titre">Délibération terminée</p>
      <p class="pcj-fin__sub">Vous avez examiné toutes les photos assignées à votre session.</p>
    </div>`;
  }

  // ── Toast ─────────────────────────────────────────────────────────
  function toast(msg, type = 'info') {
    if (!elToastContainer) return;
    const el = document.createElement('div');
    el.className = `pcj-toast pcj-toast--${type}`;
    el.textContent = msg;
    elToastContainer.appendChild(el);
    setTimeout(() => {
      el.classList.add('pcj-toast--sortie');
      el.addEventListener('animationend', () => el.remove(), { once: true });
    }, 3200);
  }

  // ── Formatage ─────────────────────────────────────────────────────
  function formatPoids(octets) {
    if (!octets) return '—';
    if (octets > 1048576) return (octets / 1048576).toFixed(1) + ' Mo';
    return (octets / 1024).toFixed(0) + ' Ko';
  }

  // ── Binding événements ─────────────────────────────────────────────
  function bindEvents() {
    // Filtre catégories
    document.querySelectorAll('.pcj-cat-filter__btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.pcj-cat-filter__btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeCatId = parseInt(btn.dataset.catId, 10) || 0;
        chargerPhotos();
      });
    });

    // Boutons vote
    elBtnRetenu?.addEventListener('click', () => voter('retenue'));
    elBtnRefuse?.addEventListener('click', () => voter('refusee'));

    // Passer sans voter
    elBtnPasser?.addEventListener('click', () => {
      if (state.index < state.photos.length - 1) allerA(state.index + 1);
    });

    // Modifier un vote existant
    elBtnModifier?.addEventListener('click', () => {
      const photo = state.photos[state.index];
      delete state.votes[photo.id];
      const thumb = elStrip?.querySelector(`[data-index="${state.index}"]`);
      const dot   = thumb?.querySelector('.pcj-thumb__dot');
      if (thumb && dot) appliquerClasseVote(thumb, dot, null);
      afficherEtatVote(null);
      mettreAJourProgression();
    });

    // Navigation flèches
    elPrev?.addEventListener('click', () => allerA(state.index - 1));
    elNext?.addEventListener('click', () => allerA(state.index + 1));

    // Raccourcis clavier
    document.addEventListener('keydown', e => {
      // Ignorer si focus sur textarea/input
      if (['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) return;

      switch (e.key) {
        case 'ArrowRight': case 'ArrowDown':
          e.preventDefault(); allerA(state.index + 1); break;
        case 'ArrowLeft': case 'ArrowUp':
          e.preventDefault(); allerA(state.index - 1); break;
        case 'r': case 'R':
          voter('retenue'); break;
        case 'x': case 'X': case 'Backspace':
          voter('refusee'); break;
        case ' ':
          e.preventDefault();
          elComment?.focus();
          break;
      }
    });

    // Clic sur le fond lightbox pour passer à la suivante
    $('.pcj-viewer')?.addEventListener('click', e => {
      if (e.target === e.currentTarget) allerA(state.index + 1);
    });
  }

  // ── Lancement ─────────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
