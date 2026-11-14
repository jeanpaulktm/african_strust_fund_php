<?php
require_once __DIR__ . '/config/database.php';
sendSecurityHeaders();
startSecureSession();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AFRICAN TRUST FUND — L'argent de l'Afrique pour construire l'Afrique</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="dark-landing">

<?php require_once __DIR__ . '/includes/header.php'; ?>

<main class="hero">
  <section class="hero-text">
    <span class="badge gold">Nouvelle génération d'épargne</span>
    <h1>AFRICAN <span class="gold"> TRUST FUND</span></h1>
    <p class="slogan">« L'argent de l'Afrique pour construire l'Afrique. »</p>
    <p class="lead">
      Une plateforme fintech sécurisée pour votre Epargne, Crédit et Investissement —
      en ligne ou en agence, 24/7.
    </p>

    

    <div class="stats-row">
      <div class="stat"><strong>100%</strong><span>Sécurisé</span></div>
      <div class="stat"><strong>24/7</strong><span>Accessible</span></div>
      <div class="stat"><strong>0</strong><span>Frais cachés</span></div>
    </div>
  </section>

  <section class="hero-visual">
    <div class="circle-wrapper" id="circleWidget" tabindex="0" aria-label="Compteur de confiance">
      <svg class="progress-ring" viewBox="0 0 220 220">
        <defs>
          <linearGradient id="goldGrad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%"  stop-color="#F5B800"/>
            <stop offset="100%" stop-color="#D4AF37"/>
          </linearGradient>
        </defs>
        <circle class="ring-bg" cx="110" cy="110" r="95"></circle>
        <circle class="ring-fg" id="ringProgress" cx="110" cy="110" r="95"
                stroke-dasharray="596.9" stroke-dashoffset="596.9"></circle>
      </svg>
      <div class="circle-center">
        <span id="counterValue">100<small>%</small></span>
        <span class="circle-label">Confiance africaine</span>
      </div>
      <span class="pulse-ring"></span>
    </div>
    <p class="counter-caption">Conservons Notre patrimoine Ensemble.</p>
  </section>
</main>

<footer class="atf-footer">
  <p>© 2025 african TRUST FUND — Tous droits réservés.</p>
  <p>Jeanpaul Katumo.Dev</p>
  
  <p>Contact: jeanpaulkatumo@gmail.com</p>

</footer>

<script src="/assets/js/main.js"></script>
</body>
</html>