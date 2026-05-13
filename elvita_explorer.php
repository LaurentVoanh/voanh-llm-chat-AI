<?php
session_start();
require_once __DIR__ . '/db/init.php';

// Vérification admin (adapte si ton système de session diffère)
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403); die('Accès réservé administrateur.');
}

$db = getDB();

// --- HANDLER AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $act = $input['action'] ?? '';

    if ($act === 'get_users') {
        $search = $input['search'] ?? '';
        $stmt = $db->prepare("SELECT u.id, u.email, u.pseudo, u.certified, u.last_seen,
            (SELECT COUNT(*) FROM messages WHERE user_id = u.id) as msg_count,
            (SELECT COALESCE(SUM(tokens_used),0) FROM messages WHERE user_id = u.id) as total_tokens
            FROM users u WHERE u.role != 'admin' AND (u.email LIKE ? OR u.pseudo LIKE ?) ORDER BY u.last_seen DESC LIMIT 100");
        $stmt->bindValue(1, "%$search%"); $stmt->bindValue(2, "%$search%");
        $res = $stmt->execute();
        $users = []; while($row = $res->fetchArray(SQLITE3_ASSOC)) $users[] = $row;
        echo json_encode(['success' => true, 'users' => $users]); exit;
    }

    if ($act === 'get_messages') {
        $uid = intval($input['user_id']);
        $res = $db->query("SELECT role, sender_type, content, created_at, tokens_used FROM messages WHERE user_id = $uid ORDER BY created_at ASC LIMIT 300");
        $msgs = []; while($row = $res->fetchArray(SQLITE3_ASSOC)) $msgs[] = $row;
        echo json_encode(['success' => true, 'messages' => $msgs]); exit;
    }

    if ($act === 'analyze') {
        $uid = intval($input['user_id'] ?? 0);
        $prompt = trim($input['prompt'] ?? '');
        $scope = $input['scope'] ?? 'user';
        $context = '';

        if ($scope === 'user' && $uid) {
            $msgs_res = $db->query("SELECT role, content FROM messages WHERE user_id = $uid ORDER BY created_at DESC LIMIT 80");
            $m = []; while($r = $msgs_res->fetchArray(SQLITE3_ASSOC)) $m[] = "[$r[role]]: $r[content]";
            $context = "HISTORIQUE UTILISATEUR (80 derniers messages):\n" . implode("\n", array_reverse($m));
        } else {
            $stats = $db->querySingle("SELECT COUNT(*) as users, SUM(tokens_used) as tok FROM (SELECT COUNT(*) as cnt, SUM(tokens_used) as tokens FROM messages GROUP BY user_id)", true);
            $context = "STATS GLOBALES BDD:\nUtilisateurs actifs: {$stats['users']}\nTotal Tokens IA: {$stats['tok']}\n\nDemande d'analyse globale.";
        }

        // API Mistral (même protocole & modèle que mistral.php)
        $API_KEY = '5qaRTjRake';
        $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'mistral-large-2411',
                'messages' => [
                    ['role' => 'system', 'content' => "Tu es ELVITA ANALYZER. Réponds de manière structurée, concise et experte. $prompt"],
                    ['role' => 'user', 'content' => $context]
                ],
                'max_tokens' => 1200, 'temperature' => 0.3
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $API_KEY],
            CURLOPT_TIMEOUT => 90
        ]);
        $resp = curl_exec($ch); curl_close($ch);
        $data = json_decode($resp, true);
        echo json_encode(['success' => true, 'result' => $data['choices'][0]['message']['content'] ?? 'Erreur API ou réponse vide.']);
        exit;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SYLVAIN // DB EXPLORER</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
  <style>
    body{background:#05080f;color:#c4f0ff;font-family:'JetBrains Mono',monospace}
    .hud{font-family:'Orbitron',sans-serif}
    .glass{background:rgba(10,20,30,0.85);border:1px solid rgba(0,240,255,0.2);backdrop-filter:blur(8px)}
    .neon{box-shadow:0 0 15px rgba(0,240,255,0.15);border-color:rgba(0,240,255,0.4)}
    .msg-u{background:rgba(57,255,20,0.1);border-left:3px solid #39ff14}
    .msg-a{background:rgba(255,59,48,0.1);border-left:3px solid #ff3b30}
    ::-webkit-scrollbar{width:5px} ::-webkit-scrollbar-thumb{background:#00f0ff;border-radius:3px}
  </style>
</head>
<body class="p-4 md:p-8 min-h-screen">
  <div class="max-w-7xl mx-auto space-y-6">
    <header class="flex flex-col md:flex-row justify-between items-center gap-4 mb-6">
      <h1 class="hud text-2xl text-[#00f0ff] tracking-widest">SYLVAIN // DB EXPLORER <span class="text-xs text-gray-500">v1.0</span></h1>
      <button onclick="loadUsers()" class="px-4 py-2 bg-[#00f0ff]/10 border border-[#00f0ff] text-[#00f0ff] hover:bg-[#00f0ff] hover:text-black transition font-bold text-sm">⟳ ACTUALISER</button>
    </header>

    <div class="glass p-4 flex flex-col md:flex-row gap-4 items-center">
      <input id="search" type="text" placeholder="🔍 Rechercher email, pseudo..." class="flex-1 bg-black/50 border border-gray-700 text-white px-4 py-2 focus:border-[#00f0ff] outline-none w-full" onkeyup="debounceSearch()">
      <select id="scope" class="bg-black/50 border border-gray-700 text-gray-300 px-3 py-2 focus:border-[#00f0ff] outline-none">
        <option value="user">Analyse Utilisateur</option>
        <option value="global">Analyse Globale BDD</option>
      </select>
    </div>

    <div class="glass p-4 overflow-x-auto neon">
      <table class="w-full text-sm">
        <thead class="text-[#00f0ff] border-b border-gray-700">
          <tr><th class="p-2 text-left">ID</th><th class="p-2 text-left">PSEUDO</th><th class="p-2 text-left">EMAIL</th>
          <th class="p-2 text-center">MSG</th><th class="p-2 text-center">TOKENS</th><th class="p-2 text-center">CERT.</th>
          <th class="p-2 text-left">DERNIÈRE VISITE</th><th class="p-2 text-center">ACTION</th></tr>
        </thead>
        <tbody id="user-table" class="divide-y divide-gray-800"></tbody>
      </table>
    </div>

    <div id="detail-panel" class="hidden glass p-6 neon relative">
      <div class="flex justify-between items-center mb-4">
        <h2 id="detail-title" class="hud text-xl text-[#ffcc00]">DÉTAILS UTILISATEUR</h2>
        <button onclick="document.getElementById('detail-panel').classList.add('hidden')" class="text-red-400 hover:text-red-200 text-2xl">&times;</button>
      </div>
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1 space-y-4">
          <textarea id="ai-prompt" class="w-full h-48 bg-black/60 border border-gray-700 text-gray-300 p-3 text-xs focus:border-[#00f0ff] outline-none resize-none" placeholder="Prompt IA personnalisé (ex: Détecte les signaux d'achat, analyse le profil psycho, etc.)"></textarea>
          <button onclick="runAnalysis()" id="btn-analyze" class="w-full py-3 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-500 hover:to-blue-500 text-white font-bold tracking-wide transition disabled:opacity-50">⚡ LANCER ANALYSE IA</button>
          <div id="ai-result" class="bg-black/40 border border-gray-700 p-4 text-sm text-gray-300 whitespace-pre-wrap max-h-[500px] overflow-y-auto min-h-[200px]">En attente...</div>
        </div>
        <div class="lg:col-span-2 bg-black/30 border border-gray-800 p-4 h-[600px] overflow-y-auto space-y-3" id="chat-history">
          <div class="text-center text-gray-500 mt-20">Sélectionnez un utilisateur pour voir l'historique</div>
        </div>
      </div>
    </div>
  </div>

  <script>
    let currentUid = null;
    let debounceTimer;
    function debounceSearch(){clearTimeout(debounceTimer);debounceTimer=setTimeout(loadUsers,400)}

    async function loadUsers(){
      const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_users',search:document.getElementById('search').value})});
      const data=await res.json();
      document.getElementById('user-table').innerHTML=data.users.map(u=>`
        <tr class="hover:bg-white/5 transition cursor-pointer" onclick="openUser(${u.id},'${u.pseudo.replace(/'/g,"\\'")}')">
          <td class="p-2 text-gray-400">#${u.id}</td><td class="p-2 font-bold">${u.pseudo||'-'}</td><td class="p-2 text-gray-300">${u.email}</td>
          <td class="p-2 text-center text-[#00f0ff]">${u.msg_count}</td><td class="p-2 text-center text-[#ffcc00]">${u.total_tokens}</td>
          <td class="p-2 text-center">${u.certified?'<span class="text-[#39ff14]">✓</span>':'✗'}</td>
          <td class="p-2 text-gray-400 text-xs">${u.last_seen}</td>
          <td class="p-2 text-center"><button class="px-2 py-1 bg-[#00f0ff]/20 text-[#00f0ff] text-xs hover:bg-[#00f0ff] hover:text-black">VOIR</button></td>
        </tr>`).join('');
    }

    async function openUser(uid,pseudo){
      currentUid=uid; document.getElementById('detail-panel').classList.remove('hidden');
      document.getElementById('detail-title').innerText=`ANALYSE // ${pseudo.toUpperCase()}`;
      document.getElementById('chat-history').innerHTML='<div class="text-[#00f0ff] animate-pulse text-center">Chargement...</div>';
      document.getElementById('scope').value='user';

      const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_messages',user_id:uid})});
      const data=await res.json();
      document.getElementById('chat-history').innerHTML=data.messages.map(m=>`
        <div class="${m.sender_type==='user'?'msg-u':'msg-a'} p-3 rounded">
          <div class="flex justify-between text-xs text-gray-400 mb-1"><span class="font-bold">${m.sender_type==='user'?'👤 USER':'🤖 IA'}</span><span>${m.created_at} | ${m.tokens_used||0}tk</span></div>
          <div class="text-sm leading-relaxed whitespace-pre-wrap">${m.content}</div>
        </div>`).join('');
      document.getElementById('ai-result').innerText='En attente...';
    }

    async function runAnalysis(){
      if(document.getElementById('scope').value==='user' && !currentUid) return alert('Sélectionnez un utilisateur');
      const btn=document.getElementById('btn-analyze'), resBox=document.getElementById('ai-result');
      btn.disabled=true; btn.innerText='⏳ MOTEUR IA ACTIVÉ...'; resBox.innerText='Interrogation Mistral...\n\n';
      try{
        const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'analyze',user_id:currentUid,prompt:document.getElementById('ai-prompt').value,scope:document.getElementById('scope').value})});
        const data=await res.json();
        resBox.innerText=data.result||'Aucune réponse.';
      }catch(e){resBox.innerText='Erreur: '+e.message;}
      btn.disabled=false; btn.innerText='⚡ LANCER ANALYSE IA';
    }
    loadUsers();
  </script>
</body>
</html>