<?php
/* ============================================================
   PAGE PORTE ADMIN — AFRICAN TRUST FUND
   Accès différé après 60 secondes
   ============================================================ */
session_start();

/* Sécurité : anti-cache */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Accès restreint — AFRICAN TRUST FUND</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ============================================================
   RESET & BASE
   ============================================================ */
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
  background: #0B0E14;
  color: #E8ECF3;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  position: relative;
}

/* Fond animé */
body::before {
  content: "";
  position: absolute;
  inset: 0;
  background:
    radial-gradient(800px 500px at 15% 20%, rgba(13,27,94,.7), transparent 60%),
    radial-gradient(700px 500px at 85% 80%, rgba(245,184,0,.12), transparent 60%);
  animation: bgPulse 8s ease-in-out infinite;
  z-index: 0;
}
body::after {
  content: "";
  position: absolute;
  inset: 0;
  background-image: 
    linear-gradient(rgba(245,184,0,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(245,184,0,.03) 1px, transparent 1px);
  background-size: 50px 50px;
  z-index: 0;
  opacity: .5;
}

@keyframes bgPulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%      { opacity: .85; transform: scale(1.05); }
}

/* ============================================================
   CARTE PRINCIPALE
   ============================================================ */
.gate {
  position: relative;
  z-index: 1;
  max-width: 520px;
  width: 90%;
  padding: 50px 40px;
  background: linear-gradient(160deg, #1A2130, #121824);
  border-radius: 24px;
  border: 1px solid rgba(245,184,0,.25);
  box-shadow: 
    0 30px 80px rgba(0,0,0,.6),
    0 0 60px rgba(245,184,0,.08) inset;
  text-align: center;
  animation: cardEnter .6s cubic-bezier(.34,1.56,.64,1) both;
}

@keyframes cardEnter {
  from { opacity: 0; transform: translateY(30px) scale(.95); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* Logo */
.gate-logo {
  width: 90px;
  height: 90px;
  object-fit: contain;
  border-radius: 50%;
  border: 3px solid #F5B800;
  padding: 6px;
  background: #fff;
  box-shadow: 
    0 0 0 4px rgba(245,184,0,.15),
    0 0 40px rgba(245,184,0,.4);
  margin-bottom: 24px;
  animation: logoFloat 3s ease-in-out infinite;
}

@keyframes logoFloat {
  0%, 100% { transform: translateY(0); }
  50%      { transform: translateY(-8px); }
}

/* Titre */
.brand-name {
  font-size: 1.4rem;
  font-weight: 900;
  letter-spacing: 2px;
  background: linear-gradient(90deg, #F5B800, #FFE082, #D4AF37);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  margin-bottom: 6px;
}

.brand-slogan {
  font-size: .78rem;
  color: #8A93A6;
  font-style: italic;
  margin-bottom: 30px;
}

/* Icône cadenas animée */
.lock-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(185,28,28,.05));
  border: 2px solid rgba(239,68,68,.4);
  margin-bottom: 24px;
  font-size: 2.4rem;
  animation: lockPulse 2s ease-in-out infinite;
}

@keyframes lockPulse {
  0%, 100% {
    transform: scale(1);
    box-shadow: 0 0 0 0 rgba(239,68,68,.4);
  }
  50% {
    transform: scale(1.05);
    box-shadow: 0 0 0 20px rgba(239,68,68,0);
  }
}

/* Message principal */
.gate h1 {
  font-size: 1.6rem;
  font-weight: 800;
  color: #FCA5A5;
  margin-bottom: 12px;
  line-height: 1.2;
}

.gate p.desc {
  color: #8A93A6;
  font-size: .92rem;
  line-height: 1.6;
  margin-bottom: 28px;
}

/* Compteur */
.counter-box {
  padding: 20px;
  border-radius: 16px;
  background: linear-gradient(135deg, rgba(245,184,0,.08), rgba(245,184,0,.02));
  border: 1px solid rgba(245,184,0,.3);
  margin-bottom: 28px;
}

.counter-label {
  font-size: .72rem;
  color: #8A93A6;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  font-weight: 700;
  margin-bottom: 10px;
}

.counter-value {
  font-size: 2.8rem;
  font-weight: 900;
  color: #F5B800;
  line-height: 1;
  text-shadow: 0 0 30px rgba(245,184,0,.5);
  font-variant-numeric: tabular-nums;
}

.counter-value.done {
  color: #10B981;
  text-shadow: 0 0 30px rgba(16,185,129,.5);
}

/* Barre de progression */
.progress-track {
  height: 6px;
  background: rgba(245,184,0,.1);
  border-radius: 999px;
  overflow: hidden;
  margin-top: 14px;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #F5B800, #D4AF37);
  border-radius: 999px;
  width: 0%;
  transition: width 1s linear;
  box-shadow: 0 0 15px rgba(245,184,0,.6);
}

.progress-fill.done {
  background: linear-gradient(90deg, #10B981, #059669);
  box-shadow: 0 0 15px rgba(16,185,129,.6);
}

/* Boutons */
.btn-gate {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  padding: 16px 28px;
  border-radius: 999px;
  font-size: 1rem;
  font-weight: 800;
  text-decoration: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  transition: all .3s cubic-bezier(.4,0,.2,1);
  position: relative;
  overflow: hidden;
}

.btn-gate::before {
  content: "";
  position: absolute;
  top: 0; left: -100%;
  width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,.2), transparent);
  transition: left .6s ease;
}
.btn-gate:hover::before { left: 100%; }

.btn-gate:hover {
  transform: translateY(-3px);
}

/* Bouton Retour */
.btn-back {
  background: linear-gradient(135deg, #1E3A8A, #0D1B5E);
  color: #E8ECF3;
  border: 1px solid rgba(59,130,246,.4);
  margin-bottom: 12px;
}
.btn-back:hover {
  box-shadow: 0 15px 40px rgba(30,58,138,.5);
  border-color: #3B82F6;
}

/* Bouton Admin (caché au début) */
.btn-admin {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  box-shadow: 
    0 8px 24px rgba(245,184,0,.4),
    0 0 40px rgba(245,184,0,.2);
  display: none;
  animation: btnEnter .5s ease-out both;
}

@keyframes btnEnter {
  from { opacity: 0; transform: translateY(20px) scale(.9); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

.btn-admin:hover {
  box-shadow: 0 15px 45px rgba(245,184,0,.7);
}

.btn-admin.show { display: inline-flex; }

/* Bouton désactivé (pendant les 60s) */
.btn-disabled {
  opacity: .4;
  cursor: not-allowed;
  pointer-events: none;
}

/* Footer */
.gate-footer {
  margin-top: 26px;
  padding-top: 20px;
  border-top: 1px solid rgba(255,255,255,.08);
  font-size: .72rem;
  color: #5A6275;
  line-height: 1.6;
}

.gate-footer strong { color: #8A93A6; }

/* Message de sécurité */
.security-note {
  margin-top: 18px;
  padding: 12px 16px;
  background: rgba(239,68,68,.06);
  border-left: 3px solid #EF4444;
  border-radius: 8px;
  font-size: .75rem;
  color: #FCA5A5;
  text-align: left;
  line-height: 1.5;
}

/* Responsive */
@media (max-width: 600px) {
  .gate { padding: 36px 24px; }
  .gate h1 { font-size: 1.3rem; }
  .counter-value { font-size: 2.2rem; }
  .gate-logo { width: 70px; height: 70px; }
}
</style>
</head>
<body>

<div class="gate">

  <!-- Logo -->
  <img src="assets/img/logo.png" 
       alt="AFRICAN TRUST FUND" 
       class="gate-logo"
       onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%23F5B800%22/><text x=%2250%22 y=%2262%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%230B0E14%22 font-weight=%22bold%22 font-family=%22Arial%22>ATF</text></svg>'">

  <!-- Nom entreprise -->
  <div class="brand-name">AFRICAN TRUST FUND</div>
  <div class="brand-slogan">« L'argent de l'Afrique pour construire l'Afrique »</div>

  <!-- Cadenas -->
  <div class="lock-icon">🔒</div>

  <!-- Message -->
  <h1 id="mainTitle">Page inaccessible</h1>
  <p class="desc" id="mainDesc">
    Cette zone est réservée à l'administration.<br>
    Veuillez patienter <strong style="color:#F5B800">60 secondes</strong> avant de pouvoir continuer.
  </p>

  <!-- Compteur -->
  <div class="counter-box">
    <div class="counter-label">⏳ Temps restant</div>
    <div class="counter-value" id="counter">60</div>
    <div class="progress-track">
      <div class="progress-fill" id="progressFill"></div>
    </div>
  </div>

  <!-- Bouton Retour -->
  <a href="index.php" class="btn-gate btn-back">
    ← Retour à l'accueil
  </a>

  <!-- Bouton Admin (caché pendant 60s) -->
  <a href="auth/connexion_admin.php" class="btn-gate btn-admin" id="btnAdmin">
    🔓 Accéder à l'espace Admin
  </a>

  <!-- Footer -->
  <div class="gate-footer">
    <strong>AFRICAN TRUST FUND</strong> — Microfinance & Épargne<br>
    Accès restreint · Toutes les tentatives sont enregistrées
  </div>

  <!-- Note de sécurité -->
  <div class="security-note">
    ⚠️ <strong>Sécurité :</strong> Cette page enregistre votre adresse IP, 
    votre navigateur et l'heure d'accès. Toute tentative non autorisée sera signalée.
  </div>

</div>

<script>
/* ============================================================
   COMPTEUR 60 SECONDES + RÉVÉLATION BOUTON ADMIN
   ============================================================ */
(function() {
  'use strict';

  const TOTAL_SECONDS = 60;
  let remaining = TOTAL_SECONDS;

  const counterEl    = document.getElementById('counter');
  const progressEl   = document.getElementById('progressFill');
  const btnAdmin     = document.getElementById('btnAdmin');
  const mainTitle    = document.getElementById('mainTitle');
  const mainDesc     = document.getElementById('mainDesc');

  /* ----- Initialisation ----- */
  counterEl.textContent = remaining;
  progressEl.style.width = '0%';

  /* ----- Compte à rebours ----- */
  const interval = setInterval(() => {
    remaining--;
    counterEl.textContent = remaining;

    // Barre de progression
    const pct = ((TOTAL_SECONDS - remaining) / TOTAL_SECONDS) * 100;
    progressEl.style.width = pct + '%';

    // Animation de pulsation à chaque seconde
    counterEl.style.transform = 'scale(1.06)';
    setTimeout(() => counterEl.style.transform = 'scale(1)', 100);

    /* ----- Fin du compte à rebours ----- */
    if (remaining <= 0) {
      clearInterval(interval);

      // Mettre à jour l'apparence du compteur
      counterEl.textContent = '✓';
      counterEl.classList.add('done');
      progressEl.classList.add('done');
      progressEl.style.width = '100%';

      // Mettre à jour le message
      mainTitle.textContent = '🔓 Accès autorisé';
      mainTitle.style.color = '#6EE7B7';
      mainDesc.innerHTML = 
        'Vous pouvez maintenant accéder à l\'<strong style="color:#F5B800">espace administrateur</strong>.<br>' +
        '<small style="color:#8A93A6">Cliquez sur le bouton ci-dessous pour continuer.</small>';

      // Afficher le bouton admin
      btnAdmin.classList.add('show');

      // Scroll fluide vers le bouton
      setTimeout(() => {
        btnAdmin.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }, 200);

      // Son discret (optionnel)
      try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);
        oscillator.frequency.value = 800;
        oscillator.type = 'sine';
        gainNode.gain.setValueAtTime(0.1, audioCtx.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
        oscillator.start(audioCtx.currentTime);
        oscillator.stop(audioCtx.currentTime + 0.3);
      } catch(e) { /* silencieux si audio bloqué */ }
    }
  }, 1000);

  /* ============================================================
     Sécurité : empêcher le retour arrière
     ============================================================ */
  history.pushState(null, '', location.href);
  window.addEventListener('popstate', () => {
    history.pushState(null, '', location.href);
    // Optionnel : avertir
    if (typeof window.showToast === 'function') {
      window.showToast('Utilisez le bouton "Retour à l\'accueil"', 'info');
    }
  });

  /* ============================================================
     Détection de sortie (log côté serveur possible plus tard)
     ============================================================ */
  window.addEventListener('beforeunload', (e) => {
    // Si l'utilisateur essaie de fermer/partir avant la fin
    if (remaining > 0) {
      // Optionnel : désactiver l'avertissement
      // return 'Vous quittez une page en attente...';
    }
  });

  /* ============================================================
     Log console (développeurs)
     ============================================================ */
  console.log('%c🚪 AFRICAN TRUST FUND — Porte Admin', 'color:#F5B800;font-weight:900;font-size:16px');
  console.log('%c⏳ Patientez 60 secondes...', 'color:#8A93A6;font-size:12px');
})();
</script>

</body>
</html>