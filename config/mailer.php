<?php
declare(strict_types=1);

/**
 * Envoi d'emails transactionnels ATF
 * Utilise mail() de PHP (configurer SMTP sur le serveur)
 * Pour la production : passer à PHPMailer + SMTP
 */

function envoyerEmail(string $destinataire, string $sujet, string $corpsHtml): bool
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: AFRICAN TRUST FUND <noreply@africantrustfund.cd>',
        'Reply-To: support@africantrustfund.cd',
        'X-Mailer: PHP/' . phpversion(),
    ];

    $corpsComplet = gabaritEmail($sujet, $corpsHtml);

    return @mail($destinataire, $sujet, $corpsComplet, implode("\r\n", $headers));
}

function gabaritEmail(string $titre, string $contenu): string
{
    $annee = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>{$titre}</title></head>
<body style="margin:0;padding:0;background:#0B0E14;font-family:Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0B0E14;padding:30px 0">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#1A2130;border-radius:16px;overflow:hidden">
        <tr>
          <td style="background:linear-gradient(135deg,#0D1B5E,#1E293B);padding:24px;text-align:center">
            <h1 style="margin:0;color:#F5B800;font-size:20px;letter-spacing:2px">AFRICAN TRUST FUND</h1>
            <p style="margin:6px 0 0;color:#8A93A6;font-size:12px;font-style:italic">
              L'argent de l'Afrique pour construire l'Afrique
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;color:#E8ECF3;font-size:15px;line-height:1.6">
            {$contenu}
          </td>
        </tr>
        <tr>
          <td style="background:#121824;padding:20px;text-align:center;color:#8A93A6;font-size:11px">
            © {$annee} AFRICAN TRUST FUND — Tous droits réservés.<br>
            Cet email est automatique, merci de ne pas y répondre.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Notifie un client que ses intérêts ont été crédités
 */
function notifierInterets(PDO $pdo, int $id_client, float $montant_interet, string $produit_nom, float $nouveau_solde, string $devise): bool
{
    // Récupérer email du client (à ajouter dans la table Client si absent)
    $stmt = $pdo->prepare("SELECT nom, prenom, email FROM Client WHERE id_client = ?");
    $stmt->execute([$id_client]);
    $client = $stmt->fetch();

    if (!$client || empty($client['email'])) return false;

    $sujet = "📈 Vos intérêts d'épargne ont été crédités";

    $contenu = "
        <p>Bonjour <strong>" . htmlspecialchars($client['prenom']) . "</strong>,</p>
        <p>Excellente nouvelle ! Vos intérêts d'épargne viennent d'être crédités sur votre compte ATF.</p>

        <table width='100%' cellpadding='12' style='background:#121824;border-radius:12px;margin:20px 0'>
          <tr><td style='color:#8A93A6;font-size:13px'>Produit :</td>
              <td style='color:#F5B800;font-weight:700;text-align:right'>" . htmlspecialchars($produit_nom) . "</td></tr>
          <tr><td style='color:#8A93A6;font-size:13px'>Intérêts crédités :</td>
              <td style='color:#10B981;font-weight:700;text-align:right;font-size:18px'>+" .
              number_format($montant_interet, 2, ',', ' ') . " " . htmlspecialchars($devise) . "</td></tr>
          <tr><td style='color:#8A93A6;font-size:13px'>Nouveau solde :</td>
              <td style='color:#fff;font-weight:700;text-align:right'>" .
              number_format($nouveau_solde, 2, ',', ' ') . " " . htmlspecialchars($devise) . "</td></tr>
        </table>

        <p>Continuez à épargner pour faire fructifier votre argent !</p>

        <p style='margin-top:24px'>
          <a href='https://africantrustfund.cd/client/epargne.php'
             style='display:inline-block;background:#F5B800;color:#0B0E14;padding:12px 26px;text-decoration:none;border-radius:999px;font-weight:700'>
            Voir mon épargne →
          </a>
        </p>

        <p style='color:#8A93A6;font-size:12px;margin-top:24px'>
          Cordialement,<br>
          L'équipe AFRICAN TRUST FUND
        </p>
    ";

    return envoyerEmail($client['email'], $sujet, $contenu);
}