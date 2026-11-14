<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$base = '/african-trust-fund';
$nom_ent = $_SESSION['entreprise_nom'] ?? 'Entreprise';
$num_ent = $_SESSION['entreprise_numero'] ?? '';
?>
<header class="atf-header">
    <div class="atf-header-inner" style="display:flex;justify-content:space-between;align-items:center;padding:14px 24px;background:linear-gradient(145deg,#0D1B5E,#1E293B);border-bottom:2px solid #8B5CF6">
        <a href="<?= $base ?>/entreprise/dashboard.php" style="display:flex;align-items:center;gap:10px;text-decoration:none;color:#fff;font-weight:800">
            🏢 <span style="color:#A78BFA">ESPACE ENTREPRISE</span>
        </a>
        <div style="display:flex;align-items:center;gap:16px">
            <span style="color:#CBD5E1;font-size:.85rem"><?= htmlspecialchars($nom_ent) ?></span>
            <span style="color:#A78BFA;font-family:monospace;font-size:.75rem;padding:4px 10px;background:rgba(139,92,246,.15);border-radius:999px;border:1px solid rgba(139,92,246,.3)">
                <?= htmlspecialchars($num_ent) ?>
            </span>
            <a href="<?= $base ?>/entreprise/logout.php" style="color:#FCA5A5;text-decoration:none;font-size:.82rem;font-weight:700">🚪</a>
        </div>
    </div>
</header>