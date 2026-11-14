<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Test Chat IA ATF</title>
<style>
body {
    font-family: 'Inter', Arial, sans-serif;
    background: #0B0E14;
    color: #E8ECF3;
    padding: 30px;
    max-width: 700px;
    margin: 0 auto;
}
h1 { color: #F5B800; }
.chat-box {
    background: #1A2130;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #333;
    margin-top: 20px;
}
.messages {
    height: 400px;
    overflow-y: auto;
    padding: 16px;
    background: #121824;
    border-radius: 12px;
    margin-bottom: 16px;
    border: 1px solid #333;
}
.msg {
    padding: 12px 16px;
    border-radius: 12px;
    margin-bottom: 12px;
    max-width: 85%;
    line-height: 1.5;
    animation: fadeIn .3s;
}
@keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } }
.msg.user {
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    color: #0B0E14;
    margin-left: auto;
    font-weight: 600;
}
.msg.bot {
    background: #1E293B;
    color: #E8ECF3;
    border: 1px solid rgba(245,184,0,.15);
}
.msg.error {
    background: rgba(239,68,68,.15);
    color: #FCA5A5;
    border: 1px solid rgba(239,68,68,.3);
}
.msg.typing {
    background: #1E293B;
    color: #8A93A6;
    font-style: italic;
}
.input-row {
    display: flex;
    gap: 10px;
}
input[type="text"] {
    flex: 1;
    padding: 14px 18px;
    background: #0B0E14;
    border: 1px solid #333;
    border-radius: 999px;
    color: #fff;
    font-size: 15px;
    outline: none;
    font-family: inherit;
}
input[type="text"]:focus { border-color: #F5B800; }
button {
    padding: 14px 26px;
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    color: #0B0E14;
    border: none;
    border-radius: 999px;
    font-weight: 800;
    font-size: 15px;
    cursor: pointer;
    font-family: inherit;
}
button:hover { transform: translateY(-2px); }
button:disabled { opacity: .5; cursor: not-allowed; }
.status {
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 14px;
}
.status.ok { background: rgba(16,185,129,.15); color: #6EE7B7; }
.status.ko { background: rgba(239,68,68,.15); color: #FCA5A5; }
</style>
</head>
<body>

<h1>🧪 Test du Chat IA ATF</h1>

<div class="status ok" id="status">
    ✅ Fichier HTML chargé. Envoyez un message pour tester l'IA.
</div>

<div class="chat-box">
    <div class="messages" id="messages">
        <div class="msg bot">
            Bonjour 👋 Tapez votre message ci-dessous pour tester l'assistant IA.
        </div>
    </div>

    <div class="input-row">
        <input type="text" id="messageInput" placeholder="Ex: Comment ouvrir un compte ?" autofocus>
        <button id="sendBtn" onclick="envoyer()">Envoyer</button>
    </div>
</div>

<script>
const API_URL = '/african-trust-fund/api/chat_ia.php';
const CSRF = 'test_csrf_token'; // ← On va l'adapter

/* ============================================================
   1. RÉCUPÉRER LE CSRF TOKEN
   ============================================================ */
async function recupererCSRF() {
    try {
        const res = await fetch('/african-trust-fund/api/get_csrf.php');
        const data = await res.json();
        return data.csrf || '';
    } catch (e) {
        console.warn('Impossible de récupérer le CSRF token');
        return '';
    }
}

/* ============================================================
   2. ENVOYER UN MESSAGE
   ============================================================ */
async function envoyer() {
    const input = document.getElementById('messageInput');
    const btn = document.getElementById('sendBtn');
    const messages = document.getElementById('messages');
    const message = input.value.trim();

    if (!message) return;

    /* Afficher le message utilisateur */
    const userMsg = document.createElement('div');
    userMsg.className = 'msg user';
    userMsg.textContent = message;
    messages.appendChild(userMsg);
    input.value = '';

    /* Message "typing" */
    const typing = document.createElement('div');
    typing.className = 'msg typing';
    typing.textContent = '⏳ ATF Assistant réfléchit...';
    messages.appendChild(typing);
    messages.scrollTop = messages.scrollHeight;

    btn.disabled = true;

    try {
        /* Récupérer le CSRF si pas encore fait */
        let csrf = CSRF;
        if (csrf === 'test_csrf_token') {
            csrf = await recupererCSRF();
        }

        /* Envoyer la requête POST */
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: message,
                historique: [],
                csrf: csrf
            })
        });

        const data = await response.json();

        /* Retirer le typing */
        typing.remove();

        /* Afficher la réponse */
        if (data.success) {
            const botMsg = document.createElement('div');
            botMsg.className = 'msg bot';
            botMsg.textContent = data.reponse;
            messages.appendChild(botMsg);
        } else {
            const errMsg = document.createElement('div');
            errMsg.className = 'msg error';
            errMsg.textContent = '❌ ' + (data.error || 'Erreur inconnue');
            messages.appendChild(errMsg);
        }

    } catch (err) {
        typing.remove();
        const errMsg = document.createElement('div');
        errMsg.className = 'msg error';
        errMsg.textContent = '❌ Erreur : ' + err.message;
        messages.appendChild(errMsg);
    } finally {
        btn.disabled = false;
        input.focus();
        messages.scrollTop = messages.scrollHeight;
    }
}

/* Envoyer avec Entrée */
document.getElementById('messageInput').addEventListener('keypress', (e) => {
    if (e.key === 'Enter') envoyer();
});
</script>

</body>
</html>