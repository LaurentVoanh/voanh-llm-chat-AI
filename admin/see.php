<?php
// admin/message_reader.php - Lecteur de messages ELVITA v1.0
// Visionnez toutes les conversations membres ↔ IA avec pagination 50/page

session_start();
require_once __DIR__ . '/../db/init.php';

$db = getDB();

// ---------- GESTION DE L'AUTHENTIFICATION ADMIN ----------
$error = '';
$logged_in = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
    $stmt->bindValue(1, $email);
    $result = $stmt->execute();
    $admin = $result->fetchArray(SQLITE3_ASSOC);
    if ($admin && password_verify($pass, $admin['password'])) {
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_email'] = $admin['email'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $error = '⛔ ACCÈS REFUSÉ — Identifiants invalides';
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_id'], $_SESSION['admin_email']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$logged_in = isset($_SESSION['admin_id']);

// ---------- PARAMÈTRES DE FILTRAGE ET PAGINATION ----------
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$selected_user_id = isset($_GET['user_id']) && $_GET['user_id'] !== 'all' ? (int)$_GET['user_id'] : null;
$items_per_page = 50;
$offset = ($current_page - 1) * $items_per_page;

// Construction de la requête COUNT (pour la pagination)
$count_sql = "SELECT COUNT(*) as total FROM messages m";
$count_params = [];
if ($selected_user_id) {
    $count_sql .= " WHERE m.user_id = :user_id";
    $count_params[':user_id'] = $selected_user_id;
}
$count_stmt = $db->prepare($count_sql);
foreach ($count_params as $k => $v) $count_stmt->bindValue($k, $v);
$count_res = $count_stmt->execute();
$total_messages = $count_res->fetchArray(SQLITE3_ASSOC)['total'];
$total_pages = ceil($total_messages / $items_per_page);

// Requête principale des messages avec infos utilisateur
$sql = "SELECT m.*, u.pseudo, u.email, u.id as user_id 
        FROM messages m 
        JOIN users u ON m.user_id = u.id";
if ($selected_user_id) {
    $sql .= " WHERE m.user_id = :user_id";
}
$sql .= " ORDER BY m.created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
if ($selected_user_id) $stmt->bindValue(':user_id', $selected_user_id, SQLITE3_INTEGER);
$stmt->bindValue(':limit', $items_per_page, SQLITE3_INTEGER);
$stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
$msg_res = $stmt->execute();
$messages = [];
while ($row = $msg_res->fetchArray(SQLITE3_ASSOC)) {
    $messages[] = $row;
}

// Liste de tous les membres (pour le filtre déroulant)
$users_list = [];
$user_stmt = $db->query("SELECT id, pseudo, email FROM users WHERE role != 'admin' ORDER BY pseudo ASC");
while ($u = $user_stmt->fetchArray(SQLITE3_ASSOC)) {
    $users_list[] = $u;
}

// Statistiques supplémentaires pour la sidebar
$stats = [
    'total_messages' => $db->querySingle("SELECT COUNT(*) FROM messages"),
    'total_users'    => $db->querySingle("SELECT COUNT(*) FROM users WHERE role != 'admin'"),
    'today_msgs'     => $db->querySingle("SELECT COUNT(*) FROM messages WHERE DATE(created_at) = DATE('now')")
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ELVITA :: LECTEUR DE MESSAGES [NEXUS]</title>
    <!-- FONTS CYBERPUNK -->
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;900&family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- BOOTSTRAP 5 (léger) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- NOTYF TOASTS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <!-- PARTICLES.JS -->
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    <!-- GSAP -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <style>
        /* ============================================================
           DESIGN SYSTÈME CYBERPUNK — ELVITA NEXUS
           ============================================================ */
        :root {
            --bg-deep:    #00050a;
            --bg-panel:   rgba(0, 8, 18, 0.97);
            --bg-card:    rgba(0, 15, 35, 0.85);
            --bg-hover:   rgba(0, 240, 255, 0.04);
            --cyan:       #00f0ff;
            --cyan-dim:   rgba(0, 240, 255, 0.15);
            --gold:       #ffd700;
            --gold-dim:   rgba(255, 215, 0, 0.15);
            --red:        #ff3355;
            --green:      #00ff88;
            --purple:     #b44dff;
            --text-main:  #c8e8ff;
            --text-dim:   #4a7a99;
            --border:     rgba(0, 240, 255, 0.12);
            --border-gold:rgba(255, 215, 0, 0.25);
            --glow-cyan:  0 0 20px rgba(0,240,255,0.4);
            --glow-gold:  0 0 20px rgba(255,215,0,0.4);
            --font-orb:   'Orbitron', sans-serif;
            --font-mono:  'Share Tech Mono', monospace;
            --font-body:  'Exo 2', sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: var(--bg-deep);
            color: var(--text-main);
            font-family: var(--font-body);
            min-height: 100vh;
            overflow-x: hidden;
        }
        #particles-js {
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 0; pointer-events: none;
        }
        .grid-bg {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-image: linear-gradient(rgba(0,240,255,0.04) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(0,240,255,0.04) 1px, transparent 1px);
            background-size: 40px 40px;
            z-index: 0; pointer-events: none;
            animation: gridShift 20s linear infinite;
        }
        @keyframes gridShift {
            0%   { background-position: 0 0; }
            100% { background-position: 40px 40px; }
        }
        .scanline {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(0,0,0,0.05) 2px, rgba(0,0,0,0.05) 4px);
            z-index: 1; pointer-events: none;
        }

        /* LOGIN SCREEN (identique à l'original) */
        .login-screen {
            position: relative; z-index: 10;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh;
        }
        .login-panel {
            width: 440px; max-width: 95vw;
            background: var(--bg-panel);
            border: 1px solid var(--gold);
            box-shadow: 0 0 60px rgba(255,215,0,0.15), inset 0 0 60px rgba(0,0,0,0.5);
            clip-path: polygon(0 0, calc(100% - 30px) 0, 100% 30px, 100% 100%, 30px 100%, 0 calc(100% - 30px));
            animation: panelPulse 4s ease-in-out infinite;
        }
        @keyframes panelPulse {
            0%,100% { box-shadow: 0 0 40px rgba(255,215,0,0.15), inset 0 0 60px rgba(0,0,0,0.5); }
            50%      { box-shadow: 0 0 80px rgba(255,215,0,0.30), inset 0 0 60px rgba(0,0,0,0.5); }
        }
        .login-inner { padding: 50px 40px 40px; }
        .login-logo-hex {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, var(--gold), #ff8c00);
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
            margin: 0 auto 20px;
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-orb); font-size: 20px; font-weight: 900; color: #000;
        }
        .login-title {
            font-family: var(--font-orb);
            color: var(--gold);
            font-size: 22px; font-weight: 900; letter-spacing: 4px;
            text-align: center;
        }
        .neon-field { margin-bottom: 20px; }
        .neon-field input {
            width: 100%;
            background: rgba(0,240,255,0.03);
            border: 1px solid rgba(0,240,255,0.2);
            border-left: 3px solid var(--cyan);
            color: #fff;
            font-family: var(--font-mono);
            padding: 13px 15px;
            outline: none;
        }
        .btn-login-main {
            width: 100%;
            background: transparent;
            border: 1px solid var(--gold);
            color: var(--gold);
            font-family: var(--font-orb);
            font-weight: 700;
            letter-spacing: 4px;
            padding: 16px;
            cursor: pointer;
            clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 12px, 100% 100%, 12px 100%, 0 calc(100% - 12px));
            transition: all 0.3s;
        }
        .btn-login-main:hover { background: var(--gold-dim); box-shadow: var(--glow-gold); }
        .login-error { color: var(--red); text-align: center; margin-top: 15px; font-family: var(--font-mono); }

        /* ADMIN LAYOUT */
        .admin-wrap { position: relative; z-index: 10; display: flex; flex-direction: column; min-height: 100vh; }
        .topbar {
            background: rgba(0,4,12,0.98);
            border-bottom: 1px solid var(--border-gold);
            padding: 0 24px;
            display: flex; align-items: center; justify-content: space-between;
            height: 64px;
            position: sticky; top: 0; z-index: 200;
        }
        .topbar-brand {
            display: flex; align-items: center; gap: 14px;
        }
        .topbar-hex {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--gold), #cc7700);
            clip-path: polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%);
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-orb); font-weight: 900; color: #000;
        }
        .topbar-title {
            font-family: var(--font-orb);
            font-size: 15px; font-weight: 900; letter-spacing: 3px;
            color: var(--gold);
        }
        .topbar-title span { color: var(--cyan); }
        .btn-neon {
            background: transparent;
            border: 1px solid currentColor;
            font-family: var(--font-orb);
            font-size: 9px;
            letter-spacing: 2px;
            padding: 7px 14px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.25s;
        }
        .btn-neon.red { color: var(--red); }
        .btn-neon.red:hover { background: var(--red-dim); }
        .btn-neon.cyan { color: var(--cyan); }
        .btn-neon.cyan:hover { background: var(--cyan-dim); }

        /* MAIN CONTENT */
        .main-content { flex: 1; padding: 24px; max-width: 1400px; margin: 0 auto; width: 100%; }
        .section-header {
            display: flex; align-items: center; gap: 15px;
            margin-bottom: 22px; padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }
        .section-header-line {
            width: 4px; height: 28px;
            background: linear-gradient(to bottom, var(--gold), var(--cyan));
        }
        .section-title {
            font-family: var(--font-orb);
            font-size: 14px; font-weight: 700; letter-spacing: 3px;
            color: var(--gold);
        }

        /* Filtres et contrôles */
        .filter-bar {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 200px;
        }
        .filter-group label {
            font-family: var(--font-orb);
            font-size: 9px;
            letter-spacing: 2px;
            color: var(--text-dim);
            text-transform: uppercase;
        }
        .cyber-select, .cyber-input {
            background: rgba(0,0,0,0.5);
            border: 1px solid var(--border);
            border-left: 2px solid var(--cyan);
            color: var(--text-main);
            font-family: var(--font-mono);
            font-size: 13px;
            padding: 10px 14px;
            outline: none;
            width: 100%;
        }
        .cyber-select:focus, .cyber-input:focus {
            border-color: var(--gold);
            box-shadow: 0 0 10px rgba(255,215,0,0.1);
        }

        /* TABLE DES MESSAGES */
        .data-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            margin-bottom: 20px;
        }
        .data-card-header {
            display: flex; align-items: center; gap: 10px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            background: rgba(0,0,0,0.3);
        }
        .data-card-title {
            font-family: var(--font-orb);
            font-size: 10px; letter-spacing: 2px;
            color: var(--cyan);
        }
        .table-scroll { overflow-x: auto; }
        .elvita-table {
            width: 100%;
            border-collapse: collapse;
            font-family: var(--font-body);
            font-size: 13px;
        }
        .elvita-table thead tr {
            background: rgba(0,0,0,0.5);
            border-bottom: 1px solid var(--border-gold);
        }
        .elvita-table th {
            font-family: var(--font-orb);
            font-size: 8px; letter-spacing: 2px;
            color: var(--gold);
            padding: 12px 14px;
            text-align: left;
            white-space: nowrap;
        }
        .elvita-table td {
            padding: 10px 14px;
            border-bottom: 1px solid rgba(0,240,255,0.04);
            color: var(--text-main);
            vertical-align: middle;
        }
        .elvita-table tbody tr:hover { background: var(--bg-hover); }
        .badge-ev {
            display: inline-block;
            font-family: var(--font-mono);
            font-size: 9px;
            letter-spacing: 1px;
            padding: 3px 8px;
            border: 1px solid currentColor;
            text-transform: uppercase;
        }
        .badge-ev.cyan { color: var(--cyan); background: var(--cyan-dim); }
        .badge-ev.gold { color: var(--gold); background: var(--gold-dim); }
        .badge-ev.green { color: var(--green); background: rgba(0,255,136,0.1); }

        /* PAGINATION CYBER */
        .pagination-wrap {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        .page-link {
            background: rgba(0,0,0,0.6);
            border: 1px solid var(--border);
            color: var(--cyan);
            font-family: var(--font-mono);
            font-size: 12px;
            padding: 8px 14px;
            text-decoration: none;
            transition: all 0.2s;
            clip-path: polygon(0 0, calc(100% - 6px) 0, 100% 6px, 100% 100%, 6px 100%, 0 calc(100% - 6px));
        }
        .page-link:hover, .page-link.active {
            background: var(--cyan-dim);
            border-color: var(--cyan);
            color: var(--gold);
            box-shadow: var(--glow-cyan);
        }
        .page-link.disabled {
            opacity: 0.3;
            pointer-events: none;
        }

        /* STATS MINI CARDS */
        .mini-stats {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 12px 20px;
            flex: 1;
            text-align: center;
        }
        .stat-value {
            font-family: var(--font-orb);
            font-size: 22px;
            font-weight: 900;
            color: var(--gold);
        }
        .stat-label {
            font-family: var(--font-mono);
            font-size: 9px;
            color: var(--text-dim);
            letter-spacing: 2px;
        }

        /* MODAL */
        .modal-overlay {
            position: fixed; inset: 0; z-index: 1000;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(6px);
            display: none; align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal-panel {
            background: var(--bg-panel);
            border: 1px solid var(--gold);
            width: 700px; max-width: 95vw;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 0 80px rgba(255,215,0,0.15);
            clip-path: polygon(0 0, calc(100% - 20px) 0, 100% 20px, 100% 100%, 20px 100%, 0 calc(100% - 20px));
        }
        .modal-header {
            display: flex; align-items: center; gap: 12px;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border-gold);
            background: rgba(255,215,0,0.03);
        }
        .modal-title {
            font-family: var(--font-orb);
            font-size: 12px; letter-spacing: 3px;
            color: var(--gold);
            flex: 1;
        }
        .modal-close {
            background: none; border: 1px solid var(--red); color: var(--red);
            width: 28px; height: 28px; cursor: pointer;
        }
        .modal-body { padding: 22px; }
        .chat-bubble {
            margin-bottom: 16px;
            max-width: 85%;
        }
        .chat-bubble.user { margin-left: auto; text-align: right; }
        .bubble-inner {
            display: inline-block;
            background: rgba(0,240,255,0.07);
            border: 1px solid var(--border);
            padding: 10px 16px;
            font-size: 13px;
            line-height: 1.5;
            clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 8px 100%, 0 calc(100% - 8px));
        }
        .chat-bubble.user .bubble-inner {
            background: rgba(255,215,0,0.06);
            border-color: rgba(255,215,0,0.15);
        }
        .bubble-meta {
            font-family: var(--font-mono);
            font-size: 9px;
            color: var(--text-dim);
            margin-bottom: 4px;
        }

        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; }
            .elvita-table th, .elvita-table td { font-size: 10px; padding: 6px 8px; }
        }
    </style>
</head>
<body>
<div id="particles-js"></div>
<div class="grid-bg"></div>
<div class="scanline"></div>

<?php if (!$logged_in): ?>
<!-- ÉCRAN DE CONNEXION ADMIN -->
<div class="login-screen">
    <div class="login-panel">
        <div class="login-inner">
            <div class="login-logo-hex">E∇</div>
            <div class="login-title">ELVITA MESSAGE READER</div>
            <div style="text-align:center; font-family:var(--font-mono); font-size:10px; color:var(--text-dim); margin-bottom:30px;">ACCÈS ADMIN RÉSERVÉ</div>
            <?php if ($error): ?>
            <div class="login-error">⚠ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="admin_login" value="1">
                <div class="neon-field">
                    <input type="email" name="email" placeholder="IDENTIFIANT ADMIN" required>
                </div>
                <div class="neon-field">
                    <input type="password" name="password" placeholder="CODE D'ACCÈS" required>
                </div>
                <button type="submit" class="btn-login-main">⚡ ACCÉDER AU LECTEUR</button>
            </form>
        </div>
    </div>
</div>
<?php else: ?>
<!-- INTERFACE PRINCIPALE -->
<div class="admin-wrap">
    <header class="topbar">
        <div class="topbar-brand">
            <div class="topbar-hex">E∇</div>
            <div>
                <div class="topbar-title">ELVITA <span>MESSAGE READER</span></div>
                <div style="font-size:9px; font-family:var(--font-mono); color:var(--text-dim);">NEXUS · ARCHIVES CONVERSATIONNELLES</div>
            </div>
        </div>
        <div class="topbar-right">
            <span class="badge-ev cyan" style="padding:6px 12px;">👑 <?= htmlspecialchars($_SESSION['admin_email']) ?></span>
            <a href="?logout=1" class="btn-neon red">DÉCONNEXION</a>
            <a href="index.php" class="btn-neon cyan">← RETOUR ADMIN</a>
        </div>
    </header>

    <main class="main-content">
        <div class="section-header">
            <div class="section-header-line"></div>
            <div>
                <div class="section-title">📡 LECTEUR DE MESSAGES GLOBAUX</div>
                <div style="font-size:11px; font-family:var(--font-mono); color:var(--text-dim);">Toutes les interactions membres ↔ Clone IA</div>
            </div>
        </div>

        <!-- Mini stats -->
        <div class="mini-stats">
            <div class="stat-card"><div class="stat-value"><?= $stats['total_messages'] ?></div><div class="stat-label">MESSAGES TOTAL</div></div>
            <div class="stat-card"><div class="stat-value"><?= $stats['total_users'] ?></div><div class="stat-label">MEMBRES</div></div>
            <div class="stat-card"><div class="stat-value"><?= $stats['today_msgs'] ?></div><div class="stat-label">MESSAGES AUJOURD'HUI</div></div>
        </div>

     <!-- Filtres et pagination info -->
<div class="filter-bar">
    <div class="filter-group">
        <label>🔍 FILTRER PAR MEMBRE</label>
        <select class="cyber-select" id="memberFilter" onchange="window.location.href='?user_id='+this.value+'&page=1'">
            <option value="all" <?= $selected_user_id === null ? 'selected' : '' ?>>📢 TOUS LES MEMBRES</option>
            <?php foreach ($users_list as $user): ?>
            <option value="<?= $user['id'] ?>" <?= ($selected_user_id == $user['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($user['pseudo'] ?: $user['email']) ?> (ID: <?= $user['id'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>📄 PAGINATION</label>
        <div style="font-family:var(--font-mono); font-size:12px; color:var(--cyan);">
            Page <?= $current_page ?> / <?= max(1, $total_pages) ?> — 
            <?= number_format($total_messages) ?> messages au total
        </div>
    </div>
</div>

        <!-- Table des messages -->
        <div class="data-card">
            <div class="data-card-header">
                <div class="data-card-title">◉ MESSAGES (50 par page) — TRI DESCENDANT</div>
            </div>
            <div class="table-scroll">
                <table class="elvita-table">
                    <thead>
                        <tr>
                            <th>DATE & HEURE</th><th>MEMBRE</th><th>RÔLE</th><th>CONNU SOUS</th><th>CONTENU</th><th>MODÈLE</th><th>TOKENS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($messages)): ?>
                        <tr><td colspan="7" style="text-align:center; color:var(--text-dim); padding:40px;">🔮 Aucun message trouvé pour ce filtre.</td></tr>
                        <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                        <tr>
                            <td class="dim" style="white-space:nowrap; font-size:10px;"><?= htmlspecialchars($msg['created_at']) ?></td>
                            <td><strong style="color:var(--cyan);"><?= htmlspecialchars($msg['pseudo'] ?: $msg['email']) ?></strong><br><span class="dim" style="font-size:9px;">#<?= $msg['user_id'] ?></span></td>
                            <td>
                                <?php if ($msg['role'] === 'user'): ?>
                                <span class="badge-ev cyan">UTILISATEUR</span>
                                <?php else: ?>
                                <span class="badge-ev gold">CLONE IA</span>
                                <?php endif; ?>
                            </td>
                            <td class="dim mono" style="font-size:10px;"><?= htmlspecialchars($msg['sender_type'] ?? '-') ?></td>
                            <td style="max-width: 400px; cursor: pointer;" onclick="showFullMessage(<?= htmlspecialchars(json_encode($msg['content'])) ?>)">
                                <?= htmlspecialchars(mb_substr($msg['content'], 0, 120)) ?><?= strlen($msg['content']) > 120 ? '…' : '' ?>
                            </td>
                            <td class="dim mono" style="font-size:10px;"><?= htmlspecialchars($msg['model_used'] ?? '-') ?></td>
                            <td class="dim mono"><?= number_format($msg['tokens_used'] ?? 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-wrap">
            <?php if ($current_page > 1): ?>
            <a href="?page=<?= $current_page-1 ?>&user_id=<?= $selected_user_id ?? 'all' ?>" class="page-link">◀ PRÉCÉDENT</a>
            <?php else: ?>
            <span class="page-link disabled">◀ PRÉCÉDENT</span>
            <?php endif; ?>

            <?php
            $start_page = max(1, $current_page - 3);
            $end_page = min($total_pages, $current_page + 3);
            for ($p = $start_page; $p <= $end_page; $p++):
            ?>
            <a href="?page=<?= $p ?>&user_id=<?= $selected_user_id ?? 'all' ?>" class="page-link <?= $p == $current_page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>

            <?php if ($current_page < $total_pages): ?>
            <a href="?page=<?= $current_page+1 ?>&user_id=<?= $selected_user_id ?? 'all' ?>" class="page-link">SUIVANT ▶</a>
            <?php else: ?>
            <span class="page-link disabled">SUIVANT ▶</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Zone de vue détaillée d'un membre (conversation complète) si un membre est sélectionné -->
        <?php if ($selected_user_id): 
            $mem_info = null;
            foreach ($users_list as $u) if ($u['id'] == $selected_user_id) { $mem_info = $u; break; }
            if ($mem_info):
        ?>
        <div class="data-card" style="margin-top: 30px;">
            <div class="data-card-header">
                <div class="data-card-title">💬 CONVERSATION COMPLÈTE AVEC <?= strtoupper(htmlspecialchars($mem_info['pseudo'] ?: $mem_info['email'])) ?></div>
                <button class="btn-neon cyan" style="margin-left:auto; padding:4px 12px;" onclick="loadFullConversation(<?= $selected_user_id ?>, '<?= htmlspecialchars($mem_info['pseudo'] ?: $mem_info['email']) ?>')">⚡ VOIR EN MODAL →</button>
            </div>
            <div class="data-card-body" style="padding: 16px; max-height: 400px; overflow-y: auto;" id="conversationPreview">
                <?php
                // Récupérer les 20 derniers messages de ce membre (pour preview)
                $preview_stmt = $db->prepare("SELECT m.*, u.pseudo FROM messages m JOIN users u ON m.user_id = u.id WHERE m.user_id = :uid ORDER BY m.created_at DESC LIMIT 20");
                $preview_stmt->bindValue(':uid', $selected_user_id, SQLITE3_INTEGER);
                $p_res = $preview_stmt->execute();
                $conv_msgs = [];
                while ($c = $p_res->fetchArray(SQLITE3_ASSOC)) $conv_msgs[] = $c;
                $conv_msgs = array_reverse($conv_msgs); // ordre chronologique
                foreach ($conv_msgs as $cmsg):
                ?>
                <div class="chat-bubble <?= $cmsg['role'] === 'user' ? 'user' : '' ?>">
                    <div class="bubble-meta">
                        <?= htmlspecialchars($cmsg['role'] === 'user' ? ($mem_info['pseudo'] ?: $mem_info['email']) : 'CLONE IA ELVITA') ?> 
                        · <?= $cmsg['created_at'] ?>
                    </div>
                    <div class="bubble-inner"><?= nl2br(htmlspecialchars($cmsg['content'])) ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (count($conv_msgs) == 0): echo '<div style="color:var(--text-dim); padding:20px; text-align:center;">Aucun message échangé avec ce membre.</div>'; endif; ?>
            </div>
        </div>
        <?php endif; endif; ?>
    </main>
</div>

<!-- MODAL MESSAGE COMPLET -->
<div class="modal-overlay" id="modal-message">
    <div class="modal-panel">
        <div class="modal-header">
            <div class="modal-title">📜 MESSAGE COMPLET</div>
            <button class="modal-close" onclick="closeModal('modal-message')">✕</button>
        </div>
        <div class="modal-body" id="modal-message-content" style="white-space: pre-wrap; font-family: var(--font-mono); font-size: 13px;"></div>
    </div>
</div>

<!-- MODAL CONVERSATION COMPLÈTE (thread) -->
<div class="modal-overlay" id="modal-conversation">
    <div class="modal-panel" style="max-width: 800px;">
        <div class="modal-header">
            <div class="modal-title" id="convModalTitle">CONVERSATION</div>
            <button class="modal-close" onclick="closeModal('modal-conversation')">✕</button>
        </div>
        <div class="modal-body" id="modal-conversation-body" style="max-height: 65vh; overflow-y: auto;"></div>
    </div>
</div>

<script>
    // Initialisation des particules
    particlesJS('particles-js', {
        particles: {
            number: { value: 50 },
            color: { value: ['#00f0ff','#ffd700','#00ff88'] },
            shape: { type: 'circle' },
            opacity: { value: 0.2, random: true },
            size: { value: 1.2, random: true },
            move: { enable: true, speed: 0.5, direction: 'none', random: true }
        },
        interactivity: { events: { onhover: { enable: true, mode: 'repulse' } } }
    });

    // Notifications
    const notyf = new Notyf({ duration: 2500, position: { x:'right', y:'top' } });

    function showFullMessage(content) {
        document.getElementById('modal-message-content').textContent = content;
        openModal('modal-message');
    }

    function loadFullConversation(userId, pseudo) {
        const modal = document.getElementById('modal-conversation');
        document.getElementById('convModalTitle').innerHTML = `💬 CONVERSATION AVEC ${pseudo.toUpperCase()}`;
        document.getElementById('modal-conversation-body').innerHTML = '<div class="modal-loading" style="text-align:center; padding:40px;"><div class="loader-ring" style="display:inline-block; width:28px; height:28px; border:2px solid transparent; border-top-color:var(--cyan); border-radius:50%; animation:spin 0.8s linear infinite;"></div> CHARGEMENT...</div>';
        openModal('modal-conversation');

        fetch('ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_user_chat', user_id: userId })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.messages || data.messages.length === 0) {
                document.getElementById('modal-conversation-body').innerHTML = '<div style="color:var(--text-dim);padding:20px;text-align:center;">Aucun message trouvé pour ce membre.</div>';
                return;
            }
            let html = '';
            data.messages.forEach(msg => {
                const isUser = (msg.role === 'user');
                html += `<div class="chat-bubble ${isUser ? 'user' : ''}">
                            <div class="bubble-meta">${isUser ? pseudo : 'CLONE IA ELVITA'} · ${msg.created_at}</div>
                            <div class="bubble-inner">${escapeHtml(msg.content)}</div>
                         </div>`;
            });
            document.getElementById('modal-conversation-body').innerHTML = html;
        })
        .catch(err => {
            document.getElementById('modal-conversation-body').innerHTML = '<div style="color:var(--red);">⚠ Erreur réseau lors du chargement.</div>';
            console.error(err);
        });
    }

    function openModal(id) {
        document.getElementById(id).classList.add('open');
        document.addEventListener('keydown', escListener);
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        document.removeEventListener('keydown', escListener);
    }
    function escListener(e) { if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open')); }
    document.querySelectorAll('.modal-overlay').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Animation GSAP
    gsap.from('.topbar', { duration: 0.4, y: -64, opacity: 0 });
    gsap.from('.filter-bar', { duration: 0.5, opacity: 0, y: 20, delay: 0.1 });
    gsap.from('.data-card', { duration: 0.5, opacity: 0, y: 20, stagger: 0.1, delay: 0.2 });
</script>

<style>
    .loader-ring {
        animation: spin 0.8s linear infinite;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    .modal-loading {
        font-family: var(--font-mono);
        color: var(--cyan);
        letter-spacing: 2px;
    }
</style>
<?php endif; ?>
</body>
</html>