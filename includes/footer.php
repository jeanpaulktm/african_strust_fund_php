<?php
/**
 * ============================================================
 * ATF MICROFINANCE — Footer global
 * ============================================================
 */
?>
<footer class="atf-footer">
  <div class="atf-footer-inner">
    <div class="atf-footer-brand">
      <span class="logo">🏦</span>
      <div>
        <strong>African Trust Fund</strong>
        <small>Microfinance & Épargne sécurisée</small>
      </div>
    </div>

    <div class="atf-footer-links">
      <a href="<?= url('admin/dashboard.php') ?>">Dashboard</a>
      <a href="<?= url('admin/clients.php') ?>">Clients</a>
      <a href="<?= url('admin/placements.php') ?>">Placements</a>
      <a href="<?= url('auth/logout.php') ?>">Déconnexion</a>
    </div>

    <div class="atf-footer-copy">
      © <?= date('Y') ?> ATF — Tous droits réservés
    </div>
  </div>
</footer>

<style>
.atf-footer {
  margin-top: 40px;
  padding: 24px 32px;
  background: linear-gradient(145deg, #121824, #0B0E14);
  border-top: 1px solid rgba(245,184,0,.2);
  color: #94A3B8;
  font-size: .85rem;
}
.atf-footer-inner {
  max-width: 1400px;
  margin: 0 auto;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
}
.atf-footer-brand {
  display: flex;
  align-items: center;
  gap: 12px;
}
.atf-footer-brand .logo { font-size: 1.8rem; }
.atf-footer-brand strong {
  display: block;
  color: #F5B800;
  font-size: .95rem;
}
.atf-footer-brand small { color: #64748B; font-size: .72rem; }
.atf-footer-links {
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
}
.atf-footer-links a {
  color: #94A3B8;
  text-decoration: none;
  transition: color .2s;
  font-weight: 600;
}
.atf-footer-links a:hover { color: #F5B800; }
.atf-footer-copy { color: #475569; font-size: .75rem; }

@media (max-width: 700px) {
  .atf-footer-inner { flex-direction: column; text-align: center; }
}
</style>