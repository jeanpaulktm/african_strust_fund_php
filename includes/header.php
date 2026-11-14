<?php
// Charge les dépendances si pas déjà faites
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/paths.php';
}
if (!class_exists('Database')) {
    require_once __DIR__ . '/../config/database.php';
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    startSecureSession();
}
?>
<!-- ============================================================
     BOUTON HAMBURGER (visible sur mobile uniquement)
     ============================================================ -->
<button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Ouvrir le menu">
  <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
    <path d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/>
  </svg>
</button>

<!-- Overlay sombre derrière la sidebar -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<header class="atf-header">
  <a href="<?= url('index1.php') ?>" class="brand">
    <img src="<?= asset('img/logo.png') ?>"
         alt="AFRICAN TRUST FUND"
         class="logo"
         onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect width=%22100%22 height=%22100%22 rx=%2220%22 fill=%22%230D1B5E%22/><text x=%2250%22 y=%2262%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23F5B800%22 font-weight=%22bold%22 font-family=%22Arial%22>ATF</text></svg>'">
    <div class="brand-text">
      <span class="brand-name">AFRICAN TRUST-FUND</span>
      <span class="brand-slogan">L'argent de l'Afrique pour construire l'Afrique</span>
    </div>
  </a>

  <nav class="atf-nav">
    <?php if (!empty($_SESSION['role'])): ?>
      <span class="user-chip">
        <span class="dot"></span>
        <?= htmlspecialchars($_SESSION['client_nom'] ?? $_SESSION['admin_nom'] ?? $_SESSION['caissier_nom'] ?? 'Utilisateur') ?>
        <small>(<?= htmlspecialchars($_SESSION['role']) ?>)</small>
      </span>
      <a class="btn-ghost" href="<?= url('auth/logout.php') ?>">Déconnexion</a>
    <?php else: ?>
              <a class="btn-ghost" href="<?= url('index.php') ?>">Accueil</a>
        <a class="btn-ghost" href="<?= url('api/chat_ia.php') ?>">Service client</a>
      <a class="btn-ghost" href="<?= url('auth/connexion_client.php') ?>">Se connecter</a>

    <?php endif; ?>
  </nav>
</header>