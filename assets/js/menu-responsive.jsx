/* ============================================================
   MENU RESPONSIVE UNIVERSEL — AFRICAN TRUST FUND
   Gère automatiquement : Desktop / Tablette / Mobile
   
   USAGE :
   Ajouter dans chaque page admin :
   <script src="/african-trust-fund/assets/js/menu-responsive.js"></script>
   
   Le script détecte automatiquement :
   - La sidebar (.admin-sidebar)
   - La balise <body>
   - Ajoute les éléments manquants (FAB, overlay, bouton fermer)
   ============================================================ */

(function() {
  'use strict';

  /* ============================================================
     CONFIGURATION
     ============================================================ */
  const CONFIG = {
    sidebarSelector: '.admin-sidebar',
    fabId: 'atfMenuFab',
    overlayId: 'atfOverlay',
    closeBtnId: 'atfSidebarClose',
    mobileBreakpoint: 768,
    animationDelay: 300
  };

  /* ============================================================
     INITIALISATION AU CHARGEMENT DU DOM
     ============================================================ */
  function init() {
    const sidebar = document.querySelector(CONFIG.sidebarSelector);
    if (!sidebar) {
      return; // Pas de sidebar sur cette page
    }

    /* ----- Créer les éléments manquants ----- */
    createFab();
    createOverlay();
    createCloseButton(sidebar);

    /* ----- Attacher les événements ----- */
    attachEvents(sidebar);

    /* ----- État initial ----- */
    handleResize(sidebar);

    /* ----- Log console ----- */
    console.log(
      '%c📱 Menu responsive ATF initialisé',
      'color:#F5B800;font-weight:bold;font-size:13px'
    );
  }

  /* ============================================================
     CRÉER LE BOUTON FAB (si absent)
     ============================================================ */
  function createFab() {
    if (document.getElementById(CONFIG.fabId)) return;

    const fab = document.createElement('button');
    fab.id = CONFIG.fabId;
    fab.type = 'button';
    fab.setAttribute('aria-label', 'Ouvrir le menu');
    fab.setAttribute('aria-expanded', 'false');
    fab.innerHTML = `
      <div class="atf-bars">
        <span></span>
        <span></span>
        <span></span>
      </div>
    `;
    document.body.appendChild(fab);
  }

  /* ============================================================
     CRÉER L'OVERLAY (si absent)
     ============================================================ */
  function createOverlay() {
    if (document.getElementById(CONFIG.overlayId)) return;

    const overlay = document.createElement('div');
    overlay.id = CONFIG.overlayId;
    overlay.setAttribute('aria-hidden', 'true');
    document.body.appendChild(overlay);
  }

  /* ============================================================
     CRÉER LE BOUTON FERMER (si absent)
     ============================================================ */
  function createCloseButton(sidebar) {
    if (document.getElementById(CONFIG.closeBtnId)) return;

    const closeBtn = document.createElement('button');
    closeBtn.id = CONFIG.closeBtnId;
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Fermer le menu');
    closeBtn.textContent = '✕';
    sidebar.insertBefore(closeBtn, sidebar.firstChild);
  }

  /* ============================================================
     ATTACHER LES ÉVÉNEMENTS
     ============================================================ */
  function attachEvents(sidebar) {
    const fab = document.getElementById(CONFIG.fabId);
    const overlay = document.getElementById(CONFIG.overlayId);
    const closeBtn = document.getElementById(CONFIG.closeBtnId);

    /* Ouvrir / fermer via FAB */
    fab.addEventListener('click', function() {
      if (sidebar.classList.contains('mobile-open')) {
        closeMenu(sidebar);
      } else {
        openMenu(sidebar);
      }
    });

    /* Fermer via overlay */
    overlay.addEventListener('click', function() {
      closeMenu(sidebar);
    });

    /* Fermer via bouton ✕ */
    closeBtn.addEventListener('click', function() {
      closeMenu(sidebar);
    });

    /* Fermer au clic sur un lien (mobile seulement) */
    sidebar.querySelectorAll('a').forEach(function(link) {
      link.addEventListener('click', function() {
        if (window.innerWidth <= CONFIG.mobileBreakpoint) {
          setTimeout(function() {
            closeMenu(sidebar);
          }, CONFIG.animationDelay);
        }
      });
    });

    /* Fermer avec la touche Échap */
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
        closeMenu(sidebar);
      }
    });

    /* Détecter le changement de taille */
    let resizeTimer;
    window.addEventListener('resize', function() {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function() {
        handleResize(sidebar);
      }, 250);
    });

    /* Swipe vers la gauche pour fermer */
    let touchStartX = 0;
    let touchStartY = 0;

    sidebar.addEventListener('touchstart', function(e) {
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
    }, { passive: true });

    sidebar.addEventListener('touchend', function(e) {
      const touchEndX = e.changedTouches[0].clientX;
      const touchEndY = e.changedTouches[0].clientY;
      const deltaX = touchStartX - touchEndX;
      const deltaY = Math.abs(touchStartY - touchEndY);

      /* Swipe horizontal vers la gauche (rapide et horizontal) */
      if (deltaX > 80 && deltaY < 60) {
        closeMenu(sidebar);
      }
    }, { passive: true });

    /* Prévenir les clics sur les liens externes en mode mobile */
    document.addEventListener('click', function(e) {
      if (
        window.innerWidth <= CONFIG.mobileBreakpoint &&
        sidebar.classList.contains('mobile-open') &&
        !sidebar.contains(e.target) &&
        e.target.id !== CONFIG.fabId &&
        !e.target.closest('#' + CONFIG.fabId)
      ) {
        closeMenu(sidebar);
      }
    });
  }

  /* ============================================================
     OUVRIR LE MENU
     ============================================================ */
  function openMenu(sidebar) {
    sidebar.classList.add('mobile-open');
    document.getElementById(CONFIG.overlayId).classList.add('active');
    document.getElementById(CONFIG.fabId).classList.add('active');
    document.getElementById(CONFIG.fabId).setAttribute('aria-expanded', 'true');
    document.body.classList.add('atf-menu-locked');

    /* Vibration (retour haptique sur mobile) */
    if (navigator.vibrate && window.innerWidth <= CONFIG.mobileBreakpoint) {
      navigator.vibrate(30);
    }
  }

  /* ============================================================
     FERMER LE MENU
     ============================================================ */
  function closeMenu(sidebar) {
    sidebar.classList.remove('mobile-open');
    document.getElementById(CONFIG.overlayId).classList.remove('active');
    document.getElementById(CONFIG.fabId).classList.remove('active');
    document.getElementById(CONFIG.fabId).setAttribute('aria-expanded', 'false');
    document.body.classList.remove('atf-menu-locked');
  }

  /* ============================================================
     GÉRER LE REDIMENSIONNEMENT
     ============================================================ */
  function handleResize(sidebar) {
    /* Fermer automatiquement si on passe en mode desktop */
    if (window.innerWidth > CONFIG.mobileBreakpoint) {
      closeMenu(sidebar);
    }
  }

  /* ============================================================
     LANCEMENT
     ============================================================ */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();