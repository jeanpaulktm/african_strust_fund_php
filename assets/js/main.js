/* ============================================================
   AFRICAN TRUST FUND — Script principal
   Version 2.0 — Compteur, toggle pwd, toast, validation, etc.
   ============================================================ */
(function () {
  'use strict';

  /* ============================================================
     1. COMPTEUR CIRCULAIRE (10 secondes)
     ============================================================ */
  const circle  = document.getElementById('circleWidget');
  const ring    = document.getElementById('ringProgress');
  const counter = document.getElementById('counterValue');

  if (circle && ring && counter) {
    const CIRCUMFERENCE = 2 * Math.PI * 95; // ≈ 596.9 pour r=95
    const DURATION = 10000;                  // 10 secondes
    let animating = false;

    const animate = () => {
      if (animating) return;
      animating = true;
      const start = performance.now();

      const step = (now) => {
        const elapsed  = now - start;
        const progress = Math.min(elapsed / DURATION, 1);
        const eased    = 1 - Math.pow(1 - progress, 3); // ease-out cubic
        const percent  = Math.round(eased * 100);

        counter.innerHTML = percent + '<small>%</small>';
        ring.style.strokeDashoffset = String(CIRCUMFERENCE * (1 - eased));

        if (progress < 1) {
          requestAnimationFrame(step);
        } else {
          animating = false;
        }
      };
      requestAnimationFrame(step);
    };

    circle.addEventListener('mouseenter', animate);
    circle.addEventListener('touchstart', animate, { passive: true });
    circle.addEventListener('focus', animate);
    circle.addEventListener('click', animate);
  }

  /* ============================================================
     2. TOGGLE VISIBILITÉ MOT DE PASSE
     ============================================================ */
  document.querySelectorAll('.eye').forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.dataset.target;
      const input = document.getElementById(targetId);
      if (!input) return;
      input.type = input.type === 'password' ? 'text' : 'password';
      btn.textContent = input.type === 'password' ? '👁' : '🙈';
    });
  });

  /* ============================================================
     3. JAUGE DE ROBUSTESSE DU MOT DE PASSE
     ============================================================ */
  const pwd1   = document.getElementById('pwd1');
  const pwdBar = document.getElementById('pwdBar');

  if (pwd1 && pwdBar) {
    pwd1.addEventListener('input', () => {
      const v = pwd1.value;
      let score = 0;
      if (v.length >= 8)     score++;
      if (/[A-Z]/.test(v))   score++;
      if (/[a-z]/.test(v))   score++;
      if (/\d/.test(v))      score++;
      if (/[\W_]/.test(v))   score++;

      const colors = ['#ef4444', '#f97316', '#f5b800', '#22c55e', '#10b981'];
      pwdBar.style.width      = (score * 20) + '%';
      pwdBar.style.background = colors[Math.max(0, score - 1)] || colors[0];
    });
  }

  /* ============================================================
     4. VALIDATION TEMPS RÉEL DES FORMULAIRES
     ============================================================ */
  document.querySelectorAll('form[data-validate]').forEach(form => {
    form.addEventListener('submit', (e) => {
      let valid = true;
      form.querySelectorAll('[required]').forEach(input => {
        if (!input.value.trim()) {
          input.style.borderColor = '#EF4444';
          valid = false;
        } else {
          input.style.borderColor = '';
        }
      });
      if (!valid) {
        e.preventDefault();
        window.showToast('Veuillez remplir tous les champs obligatoires.', 'error');
      }
    });
  });

  /* ============================================================
     5. SYSTÈME DE TOAST (notifications)
     ============================================================ */
  window.showToast = function (message, type = 'info', duration = 4000) {
    let toast = document.getElementById('atfToast');
    if (toast) toast.remove();

    toast = document.createElement('div');
    toast.id = 'atfToast';
    toast.className = 'toast ' + type;
    toast.textContent = message;
    document.body.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('show'));

    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 500);
    }, duration);
  };

  /* ============================================================
     6. FORMAT AUTOMATIQUE DES MONTANTS
     ============================================================ */
  document.querySelectorAll('input[name="montant"]').forEach(input => {
    input.addEventListener('input', (e) => {
      let v = e.target.value.replace(/[^\d.]/g, '');
      const parts = v.split('.');
      if (parts.length > 2) v = parts[0] + '.' + parts.slice(1).join('');
      e.target.value = v;
    });
  });

  /* ============================================================
     7. CONFIRMATION AVANT ACTIONS DESTRUCTRICES
     ============================================================ */
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', (e) => {
      if (!confirm(el.dataset.confirm)) {
        e.preventDefault();
        return false;
      }
    });
  });

  /* ============================================================
     8. FILTRES DE TABLEAU (recherche instantanée)
     ============================================================ */
  document.querySelectorAll('[data-table-filter]').forEach(input => {
    const tableId = input.dataset.tableFilter;
    const table = document.getElementById(tableId);
    if (!table) return;

    input.addEventListener('input', () => {
      const term = input.value.toLowerCase();
      table.querySelectorAll('tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
      });
    });
  });

  /* ============================================================
     9. AUTO-DISMISS DES ALERTS
     ============================================================ */
  document.querySelectorAll('.alert.success').forEach(alert => {
    setTimeout(() => {
      alert.style.transition = 'opacity .5s, transform .5s';
      alert.style.opacity = '0';
      alert.style.transform = 'translateY(-10px)';
      setTimeout(() => alert.remove(), 500);
    }, 5000);
  });

  /* ============================================================
     10. BADGE "EN LIGNE" TEMPS RÉEL (compteur client)
     ============================================================ */
  const onlineBadge = document.getElementById('onlineCount');
  if (onlineBadge) {
    const updateOnlineCount = () => {
      fetch('/african-trust-fund/api/heartbeat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ping: Date.now() })
      })
        .then(r => r.json())
        .then(data => {
          if (data.online !== undefined) onlineBadge.textContent = data.online;
        })
        .catch(() => { /* silencieux */ });
    };
    updateOnlineCount();
    setInterval(updateOnlineCount, 30000); // toutes les 30s
  }

  /* ============================================================
     11. NAVIGATION ACTIVE (surlignage du lien courant)
     ============================================================ */
  const currentPath = window.location.pathname;
  document.querySelectorAll('.atf-nav a, .filters a').forEach(link => {
    if (link.getAttribute('href') && currentPath.includes(link.getAttribute('href').split('/').pop())) {
      link.classList.add('active');
    }
  });

  /* ============================================================
     12. SCROLL FLUIDE POUR ANCRES
     ============================================================ */
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      const targetId = this.getAttribute('href');
      if (targetId === '#' || targetId === '#!') return;
      const target = document.querySelector(targetId);
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  /* ============================================================
     13. DÉTECTION CAPS LOCK (sur les champs mot de passe)
     ============================================================ */
  document.querySelectorAll('input[type=password]').forEach(input => {
    input.addEventListener('keyup', (e) => {
      const caps = e.getModifierState && e.getModifierState('CapsLock');
      let warning = input.parentElement.querySelector('.caps-warning');
      if (caps && !warning) {
        warning = document.createElement('small');
        warning.className = 'caps-warning';
        warning.textContent = '⚠️ Verr. Maj activé';
        warning.style.color = '#F59E0B';
        warning.style.fontSize = '.75rem';
        input.parentElement.appendChild(warning);
      } else if (!caps && warning) {
        warning.remove();
      }
    });
  });

  /* ============================================================
     14. SIDEBAR TOGGLE (mobile)
     ============================================================ */
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('atfSidebar');
  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
    });
  }

  console.log('%c🏦 AFRICAN TRUST FUND — Interface chargée', 'color:#F5B800;font-weight:bold;font-size:14px');
})();
/* ============================================================
   MENU RESPONSIVE — Hamburger mobile
   ============================================================ */
(function() {
  'use strict';

  document.addEventListener('DOMContentLoaded', function() {

    const sidebar = document.querySelector('.admin-sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebarOverlay');
    const closeBtn = document.getElementById('sidebarClose');
    const body = document.body;

    if (!sidebar || !toggleBtn) return; // Pas sur une page admin

    /* ----- OUVRIR le menu ----- */
    function openSidebar() {
      sidebar.classList.add('open');
      overlay?.classList.add('active');
      body.classList.add('sidebar-open');
    }

    /* ----- FERMER le menu ----- */
    function closeSidebar() {
      sidebar.classList.remove('open');
      overlay?.classList.remove('active');
      body.classList.remove('sidebar-open');
    }

    /* ----- Événements ----- */
    toggleBtn.addEventListener('click', openSidebar);
    overlay?.addEventListener('click', closeSidebar);
    closeBtn?.addEventListener('click', closeSidebar);

    /* ----- Fermer au clic sur un lien ----- */
    sidebar.querySelectorAll('a').forEach(function(link) {
      link.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
          closeSidebar();
        }
      });
    });

    /* ----- Fermer au touche ESC ----- */
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeSidebar();
      }
    });

    /* ----- Fermer si on agrandit la fenêtre ----- */
    let resizeTimer;
    window.addEventListener('resize', function() {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function() {
        if (window.innerWidth > 768) {
          closeSidebar();
        }
      }, 250);
    });

    /* ----- Swipe pour fermer ----- */
    let touchStartX = 0;
    let touchEndX = 0;

    sidebar.addEventListener('touchstart', function(e) {
      touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });

    sidebar.addEventListener('touchend', function(e) {
      touchEndX = e.changedTouches[0].screenX;
      if (touchStartX - touchEndX > 80) {
        closeSidebar();
      }
    }, { passive: true });

  });
})();