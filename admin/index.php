<?php
// admin/index.php - ELVITA ADMIN NEXUS v4.0 :: GRAND MONARQUE INTERFACE

session_start();
require_once __DIR__ . '/../db/init.php';

$db = getDB();

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
        $db->exec("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = {$admin['id']}");
        logVisit($db, 'https://elvita.web-4.art/admin/', $admin['id']);
        header('Location: https://elvita.web-4.art/admin/');
        exit;
    } else {
        $error = 'ACCÈS REFUSÉ — IDENTIFIANTS INVALIDES';
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_id'], $_SESSION['admin_email']);
    header('Location: https://elvita.web-4.art/admin/');
    exit;
}

$logged_in = isset($_SESSION['admin_id']);

$stats = []; $last_users = []; $last_msgs = []; $last_opga = []; $visits_by_day = [];
$all_users = []; $boutique_items = []; $admin_logs = []; $prompts_config = [];

if ($logged_in) {
    $stats['total_users']    = $db->querySingle("SELECT COUNT(*) FROM users WHERE role != 'admin'");
    $stats['certified_users']= $db->querySingle("SELECT COUNT(*) FROM users WHERE certified = 1");
    $stats['total_messages'] = $db->querySingle("SELECT COUNT(*) FROM messages");
    $stats['total_opga']     = $db->querySingle("SELECT COUNT(*) FROM opga");
    $stats['visites_today']  = $db->querySingle("SELECT COUNT(*) FROM visites WHERE DATE(created_at) = DATE('now')");
    $stats['visites_total']  = $db->querySingle("SELECT COUNT(*) FROM visites");
    $stats['visites_week']   = $db->querySingle("SELECT COUNT(*) FROM visites WHERE created_at >= datetime('now', '-7 days')");
    $stats['tokens_used']    = $db->querySingle("SELECT COALESCE(SUM(tokens_used), 0) FROM messages");
    $stats['boutique_items'] = $db->querySingle("SELECT COUNT(*) FROM boutique WHERE actif = 1");
    $stats['active_sessions']= $db->querySingle("SELECT COUNT(DISTINCT session_id) FROM messages WHERE created_at >= datetime('now', '-1 day')");

    $vbd = $db->query("SELECT DATE(created_at) as day, COUNT(*) as count FROM visites WHERE created_at >= datetime('now', '-7 days') GROUP BY DATE(created_at) ORDER BY day");
    while ($row = $vbd->fetchArray(SQLITE3_ASSOC)) $visits_by_day[] = $row;

    $lu = $db->query("SELECT id, email, pseudo, certified, role, kpi_bonheur, kpi_sante, kpi_finance, kpi_karma, kpi_amour, kpi_travail, kpi_confiance, kpi_influence, last_seen, created_at FROM users ORDER BY created_at DESC LIMIT 50");
    while ($row = $lu->fetchArray(SQLITE3_ASSOC)) $last_users[] = $row;

    $lm = $db->query("SELECT m.*, u.email, u.pseudo FROM messages m JOIN users u ON m.user_id = u.id ORDER BY m.created_at DESC LIMIT 50");
    while ($row = $lm->fetchArray(SQLITE3_ASSOC)) $last_msgs[] = $row;

    $lo = $db->query("SELECT o.*, u.pseudo FROM opga o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 30");
    while ($row = $lo->fetchArray(SQLITE3_ASSOC)) $last_opga[] = $row;

    $bi = $db->query("SELECT * FROM boutique ORDER BY actif DESC, prix ASC");
    while ($row = $bi->fetchArray(SQLITE3_ASSOC)) $boutique_items[] = $row;

    $al = $db->query("SELECT * FROM admin_log ORDER BY created_at DESC LIMIT 50");
    while ($row = $al->fetchArray(SQLITE3_ASSOC)) $admin_logs[] = $row;

    // Prompts IA config (table à créer si absente)
    $db->exec("CREATE TABLE IF NOT EXISTS ia_prompts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT UNIQUE NOT NULL,
        label TEXT NOT NULL,
        content TEXT NOT NULL,
        model TEXT DEFAULT 'mistral-medium-2505',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pq = $db->query("SELECT * FROM ia_prompts ORDER BY slug");
    while ($row = $pq->fetchArray(SQLITE3_ASSOC)) $prompts_config[] = $row;

    // Insérer prompts par défaut si absents
    if (empty($prompts_config)) {
        $defaults = [
            ['chat_main',    'Clone IA - Chat Principal',         "Tu es ELVITA, le Clone IA du Grand Monarque Sylvain Pierre Durif...", 'mistral-medium-2505'],
            ['analyze_user', 'Analyse Profil Utilisateur',        "Tu es ELVITA ANALYZER - module d'analyse psycho-commerciale...",     'mistral-large-2411'],
            ['admin_report', 'Rapport Admin IA',                  "Tu es ELVITA ADMIN INTELLIGENCE - module d'audit utilisateur...",    'magistral-medium-2509'],
            ['opga_detect',  'Détection OPGA/OPGV',               "Analyse ce message et détecte toute intention d'achat, vente...",    'mistral-medium-2505'],
        ];
        foreach ($defaults as $d) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO ia_prompts (slug, label, content, model) VALUES (?, ?, ?, ?)");
            $stmt->bindValue(1, $d[0]); $stmt->bindValue(2, $d[1]);
            $stmt->bindValue(3, $d[2]); $stmt->bindValue(4, $d[3]);
            $stmt->execute();
        }
        $pq2 = $db->query("SELECT * FROM ia_prompts ORDER BY slug");
        while ($row = $pq2->fetchArray(SQLITE3_ASSOC)) $prompts_config[] = $row;
    }
}

// JSON pour JS
$stats_json       = json_encode($stats);
$visits_day_json  = json_encode($visits_by_day);
$users_json       = json_encode($last_users);
$prompts_json     = json_encode($prompts_config);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ELVITA NEXUS :: GRAND MONARQUE ADMIN</title>

<!-- FONTS -->
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700;900&family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">

<!-- BOOTSTRAP 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- ANIMATE.CSS -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">

<!-- CHART.JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<!-- GSAP -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>

<!-- PARTICLES.JS -->
<script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>

<!-- TIPPY.JS TOOLTIP -->
<link href="https://unpkg.com/tippy.js@6/dist/tippy.css" rel="stylesheet">
<script src="https://unpkg.com/@popperjs/core@2/dist/umd/popper.min.js"></script>
<script src="https://unpkg.com/tippy.js@6/dist/tippy-bundle.umd.min.js"></script>

<!-- NOTYF TOASTS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
<script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>

<style>
/* ============================================================
   ELVITA NEXUS — 2ADVANCED DESIGN SYSTEM v4.0
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
  --red-dim:    rgba(255, 51, 85, 0.15);
  --green:      #00ff88;
  --green-dim:  rgba(0, 255, 136, 0.15);
  --purple:     #b44dff;
  --purple-dim: rgba(180, 77, 255, 0.15);
  --orange:     #ff8c00;
  --text-main:  #c8e8ff;
  --text-dim:   #4a7a99;
  --text-muted: #2a4a66;
  --border:     rgba(0, 240, 255, 0.12);
  --border-gold:rgba(255, 215, 0, 0.25);
  --glow-cyan:  0 0 20px rgba(0,240,255,0.4);
  --glow-gold:  0 0 20px rgba(255,215,0,0.4);
  --glow-red:   0 0 20px rgba(255,51,85,0.4);
  --font-orb:   'Orbitron', sans-serif;
  --font-mono:  'Share Tech Mono', monospace;
  --font-body:  'Exo 2', sans-serif;
  --font-raj:   'Rajdhani', sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  background: var(--bg-deep);
  color: var(--text-main);
  font-family: var(--font-body);
  min-height: 100vh;
  overflow-x: hidden;
}

/* ---- CANVAS BACKGROUND ---- */
#particles-js {
  position: fixed; top: 0; left: 0;
  width: 100%; height: 100%;
  z-index: 0; pointer-events: none;
}

/* ---- GRID OVERLAY ---- */
.grid-bg {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background-image:
    linear-gradient(rgba(0,240,255,0.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,240,255,0.04) 1px, transparent 1px);
  background-size: 40px 40px;
  z-index: 0; pointer-events: none;
  animation: gridShift 20s linear infinite;
}
@keyframes gridShift {
  0%   { background-position: 0 0; }
  100% { background-position: 40px 40px; }
}

/* ---- SCAN LINE ---- */
.scanline {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: repeating-linear-gradient(
    0deg,
    transparent,
    transparent 2px,
    rgba(0,0,0,0.05) 2px,
    rgba(0,0,0,0.05) 4px
  );
  z-index: 1; pointer-events: none;
}

/* ============================================================
   LOGIN SCREEN
   ============================================================ */
.login-screen {
  position: relative; z-index: 10;
  display: flex; align-items: center; justify-content: center;
  min-height: 100vh;
}

.login-panel {
  width: 440px; max-width: 95vw;
  background: var(--bg-panel);
  border: 1px solid var(--gold);
  position: relative;
  box-shadow: 0 0 60px rgba(255,215,0,0.15), inset 0 0 60px rgba(0,0,0,0.5);
  clip-path: polygon(0 0, calc(100% - 30px) 0, 100% 30px, 100% 100%, 30px 100%, 0 calc(100% - 30px));
  animation: panelPulse 4s ease-in-out infinite;
}
@keyframes panelPulse {
  0%,100% { box-shadow: 0 0 40px rgba(255,215,0,0.15), inset 0 0 60px rgba(0,0,0,0.5); }
  50%      { box-shadow: 0 0 80px rgba(255,215,0,0.30), inset 0 0 60px rgba(0,0,0,0.5); }
}

.login-corner {
  position: absolute;
  width: 12px; height: 12px;
  border-color: var(--cyan);
  border-style: solid;
}
.login-corner.tl { top: 8px; left: 8px; border-width: 2px 0 0 2px; }
.login-corner.tr { top: 8px; right: 8px; border-width: 2px 2px 0 0; }
.login-corner.bl { bottom: 8px; left: 8px; border-width: 0 0 2px 2px; }
.login-corner.br { bottom: 8px; right: 8px; border-width: 0 2px 2px 0; }

.login-inner { padding: 50px 40px 40px; }

.login-logo-wrap { text-align: center; margin-bottom: 10px; }
.login-logo-hex {
  width: 64px; height: 64px;
  background: linear-gradient(135deg, var(--gold), #ff8c00);
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  display: inline-flex; align-items: center; justify-content: center;
  font-family: var(--font-orb); font-size: 20px; font-weight: 900; color: #000;
  animation: hexRotate 8s linear infinite;
}
@keyframes hexRotate {
  0%   { filter: drop-shadow(0 0 8px var(--gold)); }
  50%  { filter: drop-shadow(0 0 20px var(--gold)); }
  100% { filter: drop-shadow(0 0 8px var(--gold)); }
}

.login-title {
  font-family: var(--font-orb);
  color: var(--gold);
  font-size: 22px;
  font-weight: 900;
  letter-spacing: 4px;
  text-align: center;
  margin: 15px 0 4px;
}
.login-sub {
  font-family: var(--font-mono);
  color: var(--text-dim);
  font-size: 12px;
  text-align: center;
  letter-spacing: 2px;
  margin-bottom: 35px;
}

.neon-field { position: relative; margin-bottom: 20px; }
.neon-field label {
  display: block;
  font-family: var(--font-orb);
  font-size: 9px;
  letter-spacing: 3px;
  color: var(--cyan);
  margin-bottom: 6px;
}
.neon-field input {
  width: 100%;
  background: rgba(0, 240, 255, 0.03);
  border: 1px solid rgba(0, 240, 255, 0.2);
  border-left: 3px solid var(--cyan);
  color: #fff;
  font-family: var(--font-mono);
  font-size: 15px;
  padding: 13px 15px;
  outline: none;
  transition: all 0.3s;
}
.neon-field input:focus {
  border-color: var(--gold);
  border-left-color: var(--gold);
  background: rgba(255,215,0,0.04);
  box-shadow: 0 0 15px rgba(255,215,0,0.1);
}

.btn-login-main {
  width: 100%;
  background: transparent;
  border: 1px solid var(--gold);
  color: var(--gold);
  font-family: var(--font-orb);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 4px;
  padding: 16px;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  clip-path: polygon(0 0, calc(100% - 12px) 0, 100% 12px, 100% 100%, 12px 100%, 0 calc(100% - 12px));
  transition: all 0.3s;
  margin-top: 10px;
}
.btn-login-main::before {
  content: '';
  position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,215,0,0.1), transparent);
  transition: left 0.5s;
}
.btn-login-main:hover::before { left: 100%; }
.btn-login-main:hover { background: var(--gold-dim); box-shadow: var(--glow-gold); }

.login-error {
  font-family: var(--font-mono);
  color: var(--red);
  text-align: center;
  margin-top: 15px;
  font-size: 12px;
  animation: blink 1s step-end infinite;
}
@keyframes blink { 50% { opacity: 0.3; } }

/* ============================================================
   ADMIN LAYOUT
   ============================================================ */
.admin-wrap { position: relative; z-index: 10; display: flex; flex-direction: column; min-height: 100vh; }

/* ---- TOPBAR ---- */
.topbar {
  background: rgba(0,4,12,0.98);
  border-bottom: 1px solid var(--border-gold);
  padding: 0 24px;
  display: flex; align-items: center; justify-content: space-between;
  height: 64px;
  position: sticky; top: 0; z-index: 200;
  box-shadow: 0 2px 30px rgba(0,0,0,0.8), 0 1px 0 rgba(255,215,0,0.1);
}

.topbar-brand {
  display: flex; align-items: center; gap: 14px;
}
.topbar-hex {
  width: 38px; height: 38px;
  background: linear-gradient(135deg, var(--gold), #cc7700);
  clip-path: polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%);
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-orb); font-size: 11px; font-weight: 900; color: #000;
  flex-shrink: 0;
  animation: hexPulse 3s ease-in-out infinite;
}
@keyframes hexPulse {
  0%,100% { filter: brightness(1); }
  50%      { filter: brightness(1.3) drop-shadow(0 0 10px var(--gold)); }
}
.topbar-title {
  font-family: var(--font-orb);
  font-size: 15px;
  font-weight: 900;
  letter-spacing: 3px;
  color: var(--gold);
}
.topbar-title span { color: var(--cyan); font-weight: 400; }
.topbar-version {
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--text-dim);
  letter-spacing: 2px;
}

.topbar-center { display: flex; align-items: center; gap: 20px; }
.status-dot {
  display: flex; align-items: center; gap: 6px;
  font-family: var(--font-mono); font-size: 11px; color: var(--text-dim);
}
.dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--green);
  animation: dotPulse 2s ease-in-out infinite;
}
@keyframes dotPulse {
  0%,100% { box-shadow: 0 0 4px var(--green); opacity: 1; }
  50%      { box-shadow: 0 0 12px var(--green); opacity: 0.7; }
}
.topbar-time {
  font-family: var(--font-mono);
  font-size: 13px;
  color: var(--cyan);
  letter-spacing: 1px;
}

.topbar-right { display: flex; align-items: center; gap: 12px; }
.admin-badge {
  background: var(--gold-dim);
  border: 1px solid var(--gold);
  color: var(--gold);
  font-family: var(--font-mono);
  font-size: 10px;
  letter-spacing: 2px;
  padding: 4px 10px;
}
.btn-neon {
  background: transparent;
  border: 1px solid currentColor;
  font-family: var(--font-orb);
  font-size: 9px;
  letter-spacing: 2px;
  padding: 7px 14px;
  cursor: pointer;
  transition: all 0.25s;
  text-decoration: none;
  display: inline-block;
}
.btn-neon.red { color: var(--red); }
.btn-neon.red:hover { background: var(--red-dim); box-shadow: var(--glow-red); }
.btn-neon.cyan { color: var(--cyan); }
.btn-neon.cyan:hover { background: var(--cyan-dim); box-shadow: var(--glow-cyan); }
.btn-neon.gold { color: var(--gold); }
.btn-neon.gold:hover { background: var(--gold-dim); box-shadow: var(--glow-gold); }

/* ---- SIDEBAR + MAIN ---- */
.admin-body { display: flex; flex: 1; }

.sidebar {
  width: 220px; flex-shrink: 0;
  background: rgba(0,4,12,0.97);
  border-right: 1px solid var(--border);
  position: sticky; top: 64px; height: calc(100vh - 64px);
  overflow-y: auto; overflow-x: hidden;
  scrollbar-width: thin;
  scrollbar-color: var(--border) transparent;
}
.sidebar::-webkit-scrollbar { width: 3px; }
.sidebar::-webkit-scrollbar-thumb { background: var(--border); }

.sidebar-section { padding: 18px 0 8px; }
.sidebar-label {
  font-family: var(--font-orb);
  font-size: 8px;
  letter-spacing: 3px;
  color: var(--text-muted);
  padding: 0 18px 8px;
  text-transform: uppercase;
}

.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 11px 18px;
  font-family: var(--font-raj);
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 1px;
  color: var(--text-dim);
  cursor: pointer;
  border-left: 2px solid transparent;
  transition: all 0.2s;
  position: relative;
}
.nav-item:hover {
  color: var(--cyan);
  background: var(--bg-hover);
  border-left-color: rgba(0,240,255,0.3);
}
.nav-item.active {
  color: var(--gold);
  background: var(--gold-dim);
  border-left-color: var(--gold);
}
.nav-item .nav-icon { font-size: 15px; width: 20px; text-align: center; }
.nav-badge {
  margin-left: auto;
  background: var(--red-dim);
  border: 1px solid var(--red);
  color: var(--red);
  font-family: var(--font-mono);
  font-size: 9px;
  padding: 1px 5px;
  min-width: 20px;
  text-align: center;
}
.nav-badge.green { background: var(--green-dim); border-color: var(--green); color: var(--green); }

/* ---- MAIN CONTENT ---- */
.main-content {
  flex: 1; padding: 24px;
  min-width: 0;
}

.tab-pane { display: none; }
.tab-pane.active { display: block; animation: fadeInUp 0.4s ease; }
@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ---- SECTION HEADER ---- */
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
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 3px;
  color: var(--gold);
}
.section-sub {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--text-dim);
  margin-top: 2px;
}
.section-actions { margin-left: auto; display: flex; gap: 8px; }

/* ---- KPI CARDS ---- */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 14px;
  margin-bottom: 24px;
}

.kpi-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  padding: 18px 20px;
  position: relative;
  overflow: hidden;
  clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 10px 100%, 0 calc(100% - 10px));
  transition: all 0.3s;
  cursor: default;
}
.kpi-card::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent, var(--accent), transparent);
}
.kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 30px rgba(0,0,0,0.4);
}

.kpi-card.gold  { --accent: var(--gold); border-color: var(--border-gold); }
.kpi-card.cyan  { --accent: var(--cyan); border-color: rgba(0,240,255,0.15); }
.kpi-card.green { --accent: var(--green); border-color: rgba(0,255,136,0.15); }
.kpi-card.red   { --accent: var(--red); border-color: rgba(255,51,85,0.15); }
.kpi-card.purple{ --accent: var(--purple); border-color: rgba(180,77,255,0.15); }

.kpi-icon {
  font-size: 20px; margin-bottom: 8px;
  filter: drop-shadow(0 0 6px var(--accent));
}
.kpi-value {
  font-family: var(--font-orb);
  font-size: 28px;
  font-weight: 900;
  color: var(--accent);
  line-height: 1;
  margin-bottom: 4px;
  transition: all 1s;
}
.kpi-label {
  font-family: var(--font-mono);
  font-size: 9px;
  letter-spacing: 2px;
  color: var(--text-dim);
  text-transform: uppercase;
}
.kpi-trend {
  position: absolute; top: 14px; right: 14px;
  font-family: var(--font-mono); font-size: 10px;
  color: var(--green);
}

/* ---- DATA CARDS ---- */
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
  font-size: 10px;
  letter-spacing: 2px;
  color: var(--cyan);
}
.data-card-body { padding: 18px; }

/* ---- CHART CONTAINER ---- */
.chart-wrap {
  background: var(--bg-card);
  border: 1px solid var(--border);
  padding: 20px;
  margin-bottom: 20px;
}
.chart-wrap canvas { max-height: 220px; }

/* ---- TABLE ---- */
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
  font-size: 8px;
  letter-spacing: 2px;
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
.elvita-table td.dim { color: var(--text-dim); font-size: 11px; }
.elvita-table td.mono { font-family: var(--font-mono); font-size: 12px; }

/* ---- BADGES ---- */
.badge-ev {
  display: inline-block;
  font-family: var(--font-mono);
  font-size: 9px;
  letter-spacing: 1px;
  padding: 3px 8px;
  border: 1px solid currentColor;
  text-transform: uppercase;
}
.badge-ev.gold   { color: var(--gold);   background: var(--gold-dim); }
.badge-ev.cyan   { color: var(--cyan);   background: var(--cyan-dim); }
.badge-ev.green  { color: var(--green);  background: var(--green-dim); }
.badge-ev.red    { color: var(--red);    background: var(--red-dim); }
.badge-ev.purple { color: var(--purple); background: var(--purple-dim); }
.badge-ev.orange { color: var(--orange); background: rgba(255,140,0,0.12); }

/* ---- MINI KPI BAR ---- */
.kpi-bars { display: flex; flex-direction: column; gap: 3px; }
.kpi-bar-row { display: flex; align-items: center; gap: 6px; font-size: 10px; }
.kpi-bar-label { color: var(--text-dim); width: 28px; font-family: var(--font-mono); }
.kpi-bar-track {
  flex: 1; height: 4px;
  background: rgba(255,255,255,0.05);
  position: relative;
}
.kpi-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--cyan), var(--gold));
  transition: width 1s ease;
}
.kpi-bar-val { color: var(--text-dim); width: 30px; text-align: right; font-family: var(--font-mono); font-size: 9px; }

/* ---- BUTTONS ---- */
.btn-ev {
  background: transparent;
  border: 1px solid;
  font-family: var(--font-orb);
  font-size: 8px;
  letter-spacing: 2px;
  padding: 7px 12px;
  cursor: pointer;
  transition: all 0.25s;
  text-transform: uppercase;
  white-space: nowrap;
}
.btn-ev.sm { padding: 4px 8px; font-size: 7px; }
.btn-ev.gold  { color: var(--gold);  border-color: rgba(255,215,0,0.4); }
.btn-ev.gold:hover  { background: var(--gold-dim); box-shadow: var(--glow-gold); }
.btn-ev.cyan  { color: var(--cyan);  border-color: rgba(0,240,255,0.4); }
.btn-ev.cyan:hover  { background: var(--cyan-dim); box-shadow: var(--glow-cyan); }
.btn-ev.red   { color: var(--red);   border-color: rgba(255,51,85,0.4); }
.btn-ev.red:hover   { background: var(--red-dim);  box-shadow: var(--glow-red); }
.btn-ev.green { color: var(--green); border-color: rgba(0,255,136,0.4); }
.btn-ev.green:hover { background: var(--green-dim); }
.btn-ev.purple { color: var(--purple); border-color: rgba(180,77,255,0.4); }
.btn-ev.purple:hover { background: var(--purple-dim); }

/* ---- SEARCH BAR ---- */
.search-bar {
  display: flex; gap: 8px; margin-bottom: 16px; align-items: center;
}
.search-input {
  flex: 1;
  background: rgba(0,0,0,0.4);
  border: 1px solid var(--border);
  border-left: 2px solid var(--cyan);
  color: var(--text-main);
  font-family: var(--font-mono);
  font-size: 13px;
  padding: 9px 14px;
  outline: none;
  transition: all 0.3s;
  max-width: 340px;
}
.search-input:focus { border-color: var(--gold); box-shadow: 0 0 10px rgba(255,215,0,0.1); }
.search-input::placeholder { color: var(--text-muted); }

/* ---- SCROLL ---- */
.table-scroll { overflow-x: auto; }
.table-scroll::-webkit-scrollbar { height: 3px; }
.table-scroll::-webkit-scrollbar-thumb { background: var(--border); }

/* ---- MODAL ---- */
.modal-overlay {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0,0,0,0.85);
  backdrop-filter: blur(6px);
  display: none; align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; animation: fadeIn 0.2s ease; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.modal-panel {
  background: var(--bg-panel);
  border: 1px solid var(--gold);
  width: 700px; max-width: 95vw;
  max-height: 85vh;
  overflow-y: auto;
  position: relative;
  box-shadow: 0 0 80px rgba(255,215,0,0.15);
  clip-path: polygon(0 0, calc(100% - 20px) 0, 100% 20px, 100% 100%, 20px 100%, 0 calc(100% - 20px));
  animation: slideUp 0.3s ease;
}
@keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: none; opacity: 1; } }

.modal-header {
  display: flex; align-items: center; gap: 12px;
  padding: 18px 22px;
  border-bottom: 1px solid var(--border-gold);
  background: rgba(255,215,0,0.03);
  position: sticky; top: 0; z-index: 2;
}
.modal-title {
  font-family: var(--font-orb);
  font-size: 12px;
  letter-spacing: 3px;
  color: var(--gold);
  flex: 1;
}
.modal-close {
  background: none; border: 1px solid var(--red); color: var(--red);
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 13px; transition: all 0.2s; flex-shrink: 0;
}
.modal-close:hover { background: var(--red); color: #fff; }
.modal-body { padding: 22px; }

.modal-loading {
  display: flex; align-items: center; justify-content: center;
  gap: 12px; padding: 40px;
  font-family: var(--font-mono);
  color: var(--cyan);
  font-size: 12px;
  letter-spacing: 2px;
}
.loader-ring {
  width: 32px; height: 32px;
  border: 2px solid transparent;
  border-top-color: var(--cyan);
  border-right-color: var(--gold);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.modal-report {
  font-family: var(--font-body);
  font-size: 14px;
  line-height: 1.7;
  color: var(--text-main);
  white-space: pre-wrap;
}

/* ---- PROMPT EDITOR ---- */
.prompt-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  margin-bottom: 16px;
}
.prompt-card-header {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
  background: rgba(0,0,0,0.3);
}
.prompt-slug {
  font-family: var(--font-mono);
  font-size: 10px;
  letter-spacing: 2px;
  color: var(--cyan);
}
.prompt-label {
  font-family: var(--font-raj);
  font-size: 14px;
  font-weight: 600;
  color: var(--text-main);
}
.prompt-body { padding: 14px 16px; }
.prompt-textarea {
  width: 100%;
  background: rgba(0,0,0,0.5);
  border: 1px solid var(--border);
  border-left: 2px solid var(--purple);
  color: var(--text-main);
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 1.6;
  padding: 12px;
  resize: vertical;
  min-height: 140px;
  outline: none;
  transition: border-color 0.3s;
}
.prompt-textarea:focus { border-color: var(--gold); }
.prompt-footer { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-top: 1px solid var(--border); }
.model-select {
  background: rgba(0,0,0,0.5);
  border: 1px solid var(--border);
  color: var(--cyan);
  font-family: var(--font-mono);
  font-size: 11px;
  padding: 5px 10px;
  outline: none;
  flex: 1; max-width: 260px;
}

/* ---- BOUTIQUE CARDS ---- */
.boutique-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.boutique-item {
  background: var(--bg-card);
  border: 1px solid var(--border);
  padding: 18px;
  position: relative;
  clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 8px 100%, 0 calc(100% - 8px));
  transition: all 0.3s;
}
.boutique-item:hover { border-color: var(--gold); transform: translateY(-2px); }
.boutique-item.inactive { opacity: 0.5; border-style: dashed; }
.boutique-cat {
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 2px;
  color: var(--cyan); margin-bottom: 6px;
}
.boutique-title {
  font-family: var(--font-raj); font-size: 15px; font-weight: 600;
  color: var(--text-main); margin-bottom: 8px;
}
.boutique-price {
  font-family: var(--font-orb); font-size: 18px;
  color: var(--gold); font-weight: 700;
}
.boutique-actions { display: flex; gap: 6px; margin-top: 12px; }

/* ---- MINI TIMELINE ---- */
.timeline { display: flex; flex-direction: column; gap: 10px; }
.timeline-item { display: flex; gap: 12px; align-items: flex-start; }
.tl-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--cyan); flex-shrink: 0; margin-top: 5px;
  box-shadow: 0 0 8px var(--cyan);
}
.tl-dot.gold { background: var(--gold); box-shadow: 0 0 8px var(--gold); }
.tl-dot.red  { background: var(--red);  box-shadow: 0 0 8px var(--red); }
.tl-content { flex: 1; }
.tl-action { font-family: var(--font-raj); font-size: 13px; color: var(--text-main); }
.tl-time   { font-family: var(--font-mono); font-size: 10px; color: var(--text-dim); margin-top: 2px; }

/* ---- INLINE FORM ---- */
.ev-form-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px; }
.ev-field { display: flex; flex-direction: column; gap: 4px; min-width: 140px; }
.ev-field label {
  font-family: var(--font-orb); font-size: 8px; letter-spacing: 2px;
  color: var(--text-dim); text-transform: uppercase;
}
.ev-input, .ev-select {
  background: rgba(0,0,0,0.4);
  border: 1px solid var(--border);
  color: var(--text-main);
  font-family: var(--font-mono);
  font-size: 13px;
  padding: 8px 12px;
  outline: none;
  transition: border-color 0.3s;
}
.ev-input:focus, .ev-select:focus { border-color: var(--cyan); }

/* ---- USER DETAIL PANEL ---- */
.user-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.user-detail-meta { display: flex; flex-direction: column; gap: 8px; }
.ud-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; border-bottom: 1px solid var(--border); }
.ud-key { font-family: var(--font-mono); font-size: 10px; color: var(--text-dim); letter-spacing: 1px; }
.ud-val { font-family: var(--font-raj); font-size: 14px; font-weight: 600; color: var(--text-main); }

/* ---- CHAT PREVIEW ---- */
.chat-bubble {
  margin-bottom: 10px; max-width: 85%;
}
.chat-bubble.user { margin-left: auto; text-align: right; }
.bubble-inner {
  display: inline-block;
  background: rgba(0,240,255,0.07);
  border: 1px solid var(--border);
  padding: 8px 14px;
  font-family: var(--font-body); font-size: 13px;
  line-height: 1.5; color: var(--text-main);
  clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 8px 100%, 0 calc(100% - 8px));
}
.chat-bubble.user .bubble-inner {
  background: rgba(255,215,0,0.06);
  border-color: rgba(255,215,0,0.15);
}
.bubble-meta { font-family: var(--font-mono); font-size: 9px; color: var(--text-dim); margin-top: 3px; letter-spacing: 1px; }

/* ---- IP MAP FRAME ---- */
.ip-badge {
  font-family: var(--font-mono); font-size: 11px;
  color: var(--green); cursor: pointer;
}
.ip-badge:hover { color: var(--gold); text-decoration: underline; }

/* ---- CERTIFICATION TOGGLE ---- */
.cert-toggle {
  background: none; border: 1px solid var(--green);
  color: var(--green); font-family: var(--font-mono);
  font-size: 9px; padding: 3px 8px; cursor: pointer; letter-spacing: 1px;
  transition: all 0.2s;
}
.cert-toggle:hover { background: var(--green-dim); }
.cert-toggle.off { border-color: var(--red); color: var(--red); }
.cert-toggle.off:hover { background: var(--red-dim); }

/* ---- LIVE TERMINAL ---- */
.terminal {
  background: #000;
  border: 1px solid rgba(0,255,136,0.2);
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--green);
  padding: 16px;
  max-height: 280px;
  overflow-y: auto;
  line-height: 1.6;
}
.terminal::-webkit-scrollbar { width: 3px; }
.terminal::-webkit-scrollbar-thumb { background: rgba(0,255,136,0.3); }
.term-line { white-space: pre-wrap; word-break: break-all; }
.term-line.cmd { color: var(--cyan); }
.term-line.err { color: var(--red); }
.term-line.warn { color: var(--gold); }
.term-line.sys { color: var(--text-dim); }
.term-cursor {
  display: inline-block; width: 8px; height: 14px;
  background: var(--green); vertical-align: middle;
  animation: curBlink 1s step-end infinite;
}
@keyframes curBlink { 50% { opacity: 0; } }

/* ---- RESPONSIVE ---- */
@media (max-width: 768px) {
  .sidebar { display: none; }
  .topbar-center { display: none; }
  .kpi-grid { grid-template-columns: repeat(2, 1fr); }
  .user-detail-grid { grid-template-columns: 1fr; }
}

/* ---- CORNER DECORATORS ---- */
.corner-tl, .corner-br {
  position: absolute;
  width: 20px; height: 20px;
  pointer-events: none;
}
.corner-tl { top: 0; left: 0; border-top: 2px solid var(--cyan); border-left: 2px solid var(--cyan); }
.corner-br { bottom: 0; right: 0; border-bottom: 2px solid var(--cyan); border-right: 2px solid var(--cyan); }

/* ---- GLITCH TEXT ---- */
.glitch {
  position: relative;
  animation: glitch 5s infinite;
}
@keyframes glitch {
  0%,95%,100% { text-shadow: none; }
  96% { text-shadow: -2px 0 var(--red), 2px 0 var(--cyan); }
  97% { text-shadow: 2px 0 var(--red), -2px 0 var(--cyan); }
  98% { text-shadow: none; }
}

/* ---- PROGRESS RING ---- */
.prog-ring { transform: rotate(-90deg); }
.prog-ring-bg { fill: none; stroke: rgba(255,255,255,0.05); }
.prog-ring-fill { fill: none; stroke-linecap: round; transition: stroke-dashoffset 1.2s ease; }

</style>
</head>
<body>
<div id="particles-js"></div>
<div class="grid-bg"></div>
<div class="scanline"></div>

<?php if (!$logged_in): ?>
<!-- ============================================================
     LOGIN SCREEN
     ============================================================ -->
<div class="login-screen">
  <div class="login-panel animate__animated animate__fadeInDown">
    <div class="login-corner tl"></div>
    <div class="login-corner tr"></div>
    <div class="login-corner bl"></div>
    <div class="login-corner br"></div>
    <div class="login-inner">
      <div class="login-logo-wrap">
        <div class="login-logo-hex">E∇</div>
      </div>
      <div class="login-title glitch">ELVITA NEXUS</div>
      <div class="login-sub">GRAND MONARQUE ADMIN INTERFACE v4.0</div>

      <?php if ($error): ?>
      <div class="login-error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="admin_login" value="1">
        <div class="neon-field">
          <label>IDENTIFIANT SÉCURISÉ</label>
          <input type="email" name="email" placeholder="admin@elvita.net" required autocomplete="off">
        </div>
        <div class="neon-field">
          <label>CODE D'ACCÈS</label>
          <input type="password" name="password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn-login-main">⚡ ACCÈS NEXUS</button>
      </form>

      <div style="margin-top:20px;text-align:center;font-family:var(--font-mono);font-size:10px;color:var(--text-muted);">
        SYSTÈME ELVITA KINGDOM © 2024 — ACCÈS RESTREINT
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ============================================================
     ADMIN APP
     ============================================================ -->
<div class="admin-wrap">

  <!-- TOPBAR -->
  <header class="topbar">
    <div class="topbar-brand">
      <div class="topbar-hex">E∇</div>
      <div>
        <div class="topbar-title">ELVITA <span>NEXUS</span></div>
        <div class="topbar-version">ADMIN CONTROL SYSTEM v4.0</div>
      </div>
    </div>
    <div class="topbar-center">
      <div class="status-dot"><div class="dot"></div> SYSTÈME ACTIF</div>
      <div class="status-dot" style="border-left:1px solid var(--border);padding-left:16px;">
        <div class="dot" style="background:var(--gold);box-shadow:0 0 8px var(--gold);"></div>
        IA MISTRAL CONNECTÉE
      </div>
      <div class="topbar-time" id="clock">--:--:--</div>
    </div>
    <div class="topbar-right">
      <div class="admin-badge">👑 GRAND MONARQUE</div>
      <span class="admin-badge" style="color:var(--cyan);border-color:rgba(0,240,255,0.3);background:var(--cyan-dim);">
        <?= htmlspecialchars($_SESSION['admin_email'] ?? '') ?>
      </span>
      <a href="?logout=1" class="btn-neon red">DÉCONNEXION</a>
    </div>
  </header>

  <div class="admin-body">
    <!-- SIDEBAR -->
    <nav class="sidebar">
      <div class="sidebar-section">
        <div class="sidebar-label">TABLEAU DE BORD</div>
        <div class="nav-item active" onclick="showTab('dashboard',this)">
          <span class="nav-icon">◈</span> DASHBOARD
        </div>
        <div class="nav-item" onclick="showTab('realtime',this)">
          <span class="nav-icon">◉</span> TEMPS RÉEL
          <span class="nav-badge green"><?= $stats['active_sessions'] ?></span>
        </div>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-label">UTILISATEURS</div>
        <div class="nav-item" onclick="showTab('users',this)">
          <span class="nav-icon">◎</span> MEMBRES
          <span class="nav-badge"><?= $stats['total_users'] ?></span>
        </div>
        <div class="nav-item" onclick="showTab('certifs',this)">
          <span class="nav-icon">◇</span> CERTIFIÉS
          <span class="nav-badge green"><?= $stats['certified_users'] ?></span>
        </div>
        <div class="nav-item" onclick="showTab('messages',this)">
          <span class="nav-icon">◫</span> CONVERSATIONS
          <span class="nav-badge"><?= $stats['total_messages'] ?></span>
        </div>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-label">MARKETPLACE</div>
        <div class="nav-item" onclick="showTab('opga',this)">
          <span class="nav-icon">◈</span> OPGA / OPGV
          <span class="nav-badge"><?= $stats['total_opga'] ?></span>
        </div>
        <div class="nav-item" onclick="showTab('boutique',this)">
          <span class="nav-icon">◉</span> BOUTIQUE
          <span class="nav-badge green"><?= $stats['boutique_items'] ?></span>
        </div>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-label">INTELLIGENCE IA</div>
        <div class="nav-item" onclick="showTab('ia-prompts',this)">
          <span class="nav-icon">⬡</span> PROMPTS IA
        </div>
        <div class="nav-item" onclick="showTab('ia-batch',this)">
          <span class="nav-icon">⬡</span> ANALYSE BATCH
        </div>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-label">SYSTÈME</div>
        <div class="nav-item" onclick="showTab('visites',this)">
          <span class="nav-icon">◌</span> VISITES
          <span class="nav-badge"><?= $stats['visites_today'] ?></span>
        </div>
        <div class="nav-item" onclick="showTab('logs',this)">
          <span class="nav-icon">◌</span> AUDIT LOG
        </div>
      </div>
    </nav>

    <!-- MAIN -->
    <main class="main-content">

      <!-- ========================
           DASHBOARD
           ======================== -->
      <div class="tab-pane active" id="pane-dashboard">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div>
            <div class="section-title">DASHBOARD NEXUS</div>
            <div class="section-sub">VUE GLOBALE DU ROYAUME ELVITA</div>
          </div>
          <div class="section-actions">
            <button class="btn-ev cyan" onclick="refreshDashboard()">↻ ACTUALISER</button>
          </div>
        </div>

        <div class="kpi-grid">
          <div class="kpi-card gold">
            <div class="kpi-icon">👑</div>
            <div class="kpi-value" id="kv-users"><?= $stats['total_users'] ?></div>
            <div class="kpi-label">MEMBRES</div>
            <div class="kpi-trend">+<?= $db->querySingle("SELECT COUNT(*) FROM users WHERE created_at >= datetime('now','-7 days') AND role != 'admin'") ?> / 7J</div>
          </div>
          <div class="kpi-card cyan">
            <div class="kpi-icon">✦</div>
            <div class="kpi-value" id="kv-msgs"><?= $stats['total_messages'] ?></div>
            <div class="kpi-label">MESSAGES IA</div>
          </div>
          <div class="kpi-card green">
            <div class="kpi-icon">◈</div>
            <div class="kpi-value" id="kv-opga"><?= $stats['total_opga'] ?></div>
            <div class="kpi-label">OPGA DÉTECTÉES</div>
          </div>
          <div class="kpi-card red">
            <div class="kpi-icon">⚡</div>
            <div class="kpi-value" id="kv-tokens"><?= number_format($stats['tokens_used']) ?></div>
            <div class="kpi-label">TOKENS CONSOMMÉS</div>
          </div>
          <div class="kpi-card purple">
            <div class="kpi-icon">◉</div>
            <div class="kpi-value"><?= $stats['visites_today'] ?></div>
            <div class="kpi-label">VISITES AUJOURD'HUI</div>
          </div>
          <div class="kpi-card cyan" style="--accent:var(--orange);">
            <div class="kpi-icon">◇</div>
            <div class="kpi-value"><?= $stats['certified_users'] ?></div>
            <div class="kpi-label">CERTIFIÉS</div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-lg-8">
            <div class="chart-wrap">
              <div class="data-card-header" style="padding:0 0 12px;border:none;">
                <div class="data-card-title">◈ VISITES — 7 DERNIERS JOURS</div>
              </div>
              <canvas id="visitChart"></canvas>
            </div>

            <div class="data-card">
              <div class="data-card-header">
                <div class="data-card-title">◉ DERNIERS MEMBRES INSCRITS</div>
              </div>
              <div class="data-card-body" style="padding:0;">
                <div class="table-scroll">
                  <table class="elvita-table">
                    <thead><tr>
                      <th>ID</th><th>PSEUDO</th><th>EMAIL</th><th>RÔLE</th><th>KPIs</th><th>INSCRIT</th><th>ACTIONS</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach (array_slice($last_users, 0, 8) as $u): ?>
                    <tr>
                      <td class="mono">#<?= $u['id'] ?></td>
                      <td><strong><?= htmlspecialchars($u['pseudo']) ?></strong></td>
                      <td class="dim"><?= htmlspecialchars($u['email']) ?></td>
                      <td><?= $u['role']==='admin'
                          ? '<span class="badge-ev gold">ADMIN</span>'
                          : '<span class="badge-ev cyan">USER</span>' ?></td>
                      <td>
                        <div class="kpi-bars">
                          <?php foreach (['bon'=>$u['kpi_bonheur'],'fin'=>$u['kpi_finance'],'san'=>$u['kpi_sante']] as $k=>$v): ?>
                          <div class="kpi-bar-row">
                            <span class="kpi-bar-label"><?= $k ?></span>
                            <div class="kpi-bar-track"><div class="kpi-bar-fill" style="width:<?= $v ?>%"></div></div>
                            <span class="kpi-bar-val"><?= round($v) ?></span>
                          </div>
                          <?php endforeach; ?>
                        </div>
                      </td>
                      <td class="dim" style="font-size:11px;"><?= substr($u['created_at'],0,10) ?></td>
                      <td>
                        <div style="display:flex;gap:4px;">
                          <button class="btn-ev cyan sm" onclick="openUserModal(<?= htmlspecialchars(json_encode($u)) ?>)">DÉTAIL</button>
                          <button class="btn-ev gold sm" onclick="analyzeUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['pseudo']) ?>')">⚡ IA</button>
                        </div>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="data-card" style="margin-bottom:14px;">
              <div class="data-card-header">
                <div class="data-card-title">⬡ MESSAGES RÉCENTS</div>
              </div>
              <div class="data-card-body" style="padding:14px;max-height:260px;overflow-y:auto;">
                <?php foreach (array_slice($last_msgs, 0, 6) as $m): ?>
                <div class="chat-bubble <?= $m['role']==='user'?'user':'' ?>" style="margin-bottom:8px;">
                  <div class="bubble-meta"><?= htmlspecialchars($m['pseudo']?:$m['email']) ?> · <?= $m['sender_type'] ?></div>
                  <div class="bubble-inner"><?= htmlspecialchars(mb_substr($m['content'],0,80)) ?>...</div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="data-card">
              <div class="data-card-header">
                <div class="data-card-title">◈ RÉPARTITION KPI GLOBAL</div>
              </div>
              <div class="data-card-body">
                <canvas id="kpiRadarChart" style="max-height:200px;"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ========================
           TEMPS RÉEL
           ======================== -->
      <div class="tab-pane" id="pane-realtime">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div><div class="section-title">MONITORING TEMPS RÉEL</div></div>
          <div class="section-actions">
            <button class="btn-ev green" onclick="startAutoRefresh()" id="auto-btn">▶ AUTO-REFRESH</button>
          </div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <div class="kpi-card cyan" style="text-align:center;">
              <div class="kpi-icon">◉</div>
              <div class="kpi-value" id="rt-sessions"><?= $stats['active_sessions'] ?></div>
              <div class="kpi-label">SESSIONS ACTIVES (24H)</div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="kpi-card gold" style="text-align:center;">
              <div class="kpi-icon">⚡</div>
              <div class="kpi-value" id="rt-visits-today"><?= $stats['visites_today'] ?></div>
              <div class="kpi-label">VISITES AUJOURD'HUI</div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="kpi-card green" style="text-align:center;">
              <div class="kpi-icon">◈</div>
              <div class="kpi-value" id="rt-tokens"><?= number_format($stats['tokens_used']) ?></div>
              <div class="kpi-label">TOKENS TOTAUX</div>
            </div>
          </div>
        </div>
        <div class="data-card">
          <div class="data-card-header"><div class="data-card-title">◉ TERMINAL SYSTÈME</div></div>
          <div class="data-card-body" style="padding:0;">
            <div class="terminal" id="terminal">
              <div class="term-line sys">[ ELVITA NEXUS v4.0 — TERMINAL SYSTÈME ]</div>
              <div class="term-line sys">[ <?= date('Y-m-d H:i:s') ?> ] Système opérationnel</div>
              <div class="term-line">[ <?= date('Y-m-d H:i:s') ?> ] <?= $stats['total_users'] ?> membres enregistrés</div>
              <div class="term-line">[ <?= date('Y-m-d H:i:s') ?> ] <?= $stats['total_messages'] ?> messages IA traités</div>
              <div class="term-line cmd">[ <?= date('Y-m-d H:i:s') ?> ] API Mistral connectée — 3 clés actives</div>
              <div class="term-line">█<span class="term-cursor"></span></div>
            </div>
          </div>
        </div>
        <div class="data-card" style="margin-top:14px;">
          <div class="data-card-header"><div class="data-card-title">⚡ FLUX MESSAGES EN DIRECT</div></div>
          <div class="data-card-body" style="padding:0;max-height:320px;overflow-y:auto;" id="live-msgs">
            <table class="elvita-table">
              <thead><tr><th>TEMPS</th><th>UTILISATEUR</th><th>TYPE</th><th>EXTRAIT</th><th>TOKENS</th></tr></thead>
              <tbody>
              <?php foreach (array_slice($last_msgs,0,15) as $m): ?>
              <tr>
                <td class="dim" style="white-space:nowrap;font-size:10px;"><?= $m['created_at'] ?></td>
                <td><?= htmlspecialchars($m['pseudo']?:$m['email']) ?></td>
                <td><?= $m['role']==='user'
                    ? '<span class="badge-ev cyan">USER</span>'
                    : '<span class="badge-ev gold">CLONE IA</span>' ?></td>
                <td class="dim" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                  <?= htmlspecialchars(mb_substr($m['content'],0,70)) ?>
                </td>
                <td class="dim mono"><?= $m['tokens_used']?:'-' ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ========================
           USERS
           ======================== -->
      <div class="tab-pane" id="pane-users">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div><div class="section-title">GESTION DES MEMBRES</div></div>
          <div class="section-actions">
            <button class="btn-ev gold" onclick="openBatchAnalyze()">⚡ ANALYSE BATCH IA</button>
          </div>
        </div>
        <div class="search-bar">
          <input type="text" class="search-input" id="users-search" placeholder="🔍  RECHERCHER UN MEMBRE..." oninput="filterUsers(this.value)">
          <select class="ev-select" id="users-filter-role" onchange="filterUsers(document.getElementById('users-search').value)" style="max-width:150px;">
            <option value="">TOUS LES RÔLES</option>
            <option value="user">USER</option>
            <option value="admin">ADMIN</option>
          </select>
          <select class="ev-select" id="users-filter-cert" onchange="filterUsers(document.getElementById('users-search').value)" style="max-width:160px;">
            <option value="">TOUTES CERTIFS</option>
            <option value="1">CERTIFIÉS</option>
            <option value="0">NON CERTIFIÉS</option>
          </select>
        </div>
        <div class="table-scroll">
          <table class="elvita-table" id="users-table">
            <thead><tr>
              <th>ID</th><th>PSEUDO</th><th>EMAIL</th><th>RÔLE</th><th>CERTIFIÉ</th>
              <th>BONHEUR</th><th>FINANCE</th><th>SANTÉ</th><th>KARMA</th>
              <th>CONNEXION</th><th>INSCRIT</th><th>ACTIONS</th>
            </tr></thead>
            <tbody id="users-tbody">
            <?php foreach ($last_users as $u): ?>
            <tr data-email="<?= htmlspecialchars($u['email']) ?>"
                data-pseudo="<?= htmlspecialchars($u['pseudo']) ?>"
                data-role="<?= $u['role'] ?>"
                data-cert="<?= $u['certified'] ?>">
              <td class="mono">#<?= $u['id'] ?></td>
              <td><strong style="color:var(--cyan);"><?= htmlspecialchars($u['pseudo']) ?></strong></td>
              <td class="dim"><?= htmlspecialchars($u['email']) ?></td>
              <td><?= $u['role']==='admin'
                  ? '<span class="badge-ev gold">ADMIN</span>'
                  : '<span class="badge-ev cyan">USER</span>' ?></td>
              <td>
                <button class="cert-toggle <?= $u['certified']?'':'off' ?>"
                        onclick="toggleCert(<?= $u['id'] ?>, <?= $u['certified']?1:0 ?>, this)"
                        title="Cliquer pour modifier">
                  <?= $u['certified'] ? '✓ CERTIFIÉ' : '✗ NON CERTIFIÉ' ?>
                </button>
              </td>
              <td style="color:<?= $u['kpi_bonheur']>=70?'var(--green)':($u['kpi_bonheur']>=40?'var(--gold)':'var(--red)') ?>">
                <?= round($u['kpi_bonheur'],1) ?>%
              </td>
              <td><?= round($u['kpi_finance'],1) ?>%</td>
              <td><?= round($u['kpi_sante'],1) ?>%</td>
              <td><?= round($u['kpi_karma'],1) ?>%</td>
              <td class="dim" style="font-size:11px;"><?= substr($u['last_seen'],0,16) ?></td>
              <td class="dim" style="font-size:11px;"><?= substr($u['created_at'],0,10) ?></td>
              <td>
                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                  <button class="btn-ev cyan sm" onclick="openUserModal(<?= htmlspecialchars(json_encode($u)) ?>)">◈ PROFIL</button>
                  <button class="btn-ev gold sm" onclick="analyzeUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['pseudo']) ?>')">⚡ IA</button>
                  <button class="btn-ev purple sm" onclick="showUserChat(<?= $u['id'] ?>, '<?= htmlspecialchars($u['pseudo']) ?>')">◉ CHAT</button>
                  <button class="btn-ev red sm" onclick="deleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['pseudo']) ?>')">✕ SUPR</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ========================
           CERTIFIÉS
           ======================== -->
      <div class="tab-pane" id="pane-certifs">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div><div class="section-title">MEMBRES CERTIFIÉS</div></div>
        </div>
        <div class="boutique-grid">
          <?php foreach ($last_users as $u): if (!$u['certified']) continue; ?>
          <div class="boutique-item">
            <div class="boutique-cat">✦ MEMBRE CERTIFIÉ</div>
            <div class="boutique-title"><?= htmlspecialchars($u['pseudo']) ?></div>
            <div class="dim" style="font-size:12px;margin-bottom:8px;"><?= htmlspecialchars($u['email']) ?></div>
            <div class="kpi-bars" style="margin-bottom:10px;">
              <?php foreach (['bon'=>$u['kpi_bonheur'],'fin'=>$u['kpi_finance'],'kar'=>$u['kpi_karma'],'inf'=>$u['kpi_influence']] as $k=>$v): ?>
              <div class="kpi-bar-row">
                <span class="kpi-bar-label"><?= $k ?></span>
                <div class="kpi-bar-track"><div class="kpi-bar-fill" style="width:<?= $v ?>%"></div></div>
                <span class="kpi-bar-val"><?= round($v) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:6px;">
              <button class="btn-ev gold sm" onclick="analyzeUser(<?= $u['id'] ?>,'<?= htmlspecialchars($u['pseudo']) ?>')">⚡ ANALYSE IA</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ========================
           MESSAGES
           ======================== -->
      <div class="tab-pane" id="pane-messages">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div><div class="section-title">HISTORIQUE CONVERSATIONS</div></div>
        </div>
        <div class="search-bar">
          <input type="text" class="search-input" id="msg-search" placeholder="🔍  FILTRER PAR UTILISATEUR OU CONTENU..." oninput="filterMessages(this.value)">
          <select class="ev-select" id="msg-filter-type" onchange="filterMessages(document.getElementById('msg-search').value)" style="max-width:160px;">
            <option value="">TOUS TYPES</option>
            <option value="user">MESSAGES USER</option>
            <option value="clone">RÉPONSES IA</option>
          </select>
        </div>
        <div class="table-scroll">
          <table class="elvita-table" id="msgs-table">
            <thead><tr>
              <th>DATE</th><th>UTILISATEUR</th><th>RÔLE</th><th>CONTENU</th>
              <th>MODÈLE</th><th>TOKENS</th><th>ACTIONS</th>
            </tr></thead>
            <tbody id="msgs-tbody">
            <?php foreach ($last_msgs as $m): ?>
            <tr data-user="<?= htmlspecialchars($m['pseudo']?:$m['email']) ?>"
                data-content="<?= htmlspecialchars($m['content']) ?>"
                data-type="<?= $m['sender_type'] ?>">
              <td class="dim" style="font-size:10px;white-space:nowrap;"><?= $m['created_at'] ?></td>
              <td><strong><?= htmlspecialchars($m['pseudo']?:$m['email']) ?></strong></td>
              <td><?= $m['role']==='user'
                  ? '<span class="badge-ev green">USER</span>'
                  : '<span class="badge-ev gold">CLONE IA</span>' ?></td>
              <td class="dim" style="max-width:300px;cursor:pointer;" onclick="expandMsg(this, '<?= htmlspecialchars(addslashes($m['content'])) ?>')">
                <?= htmlspecialchars(mb_substr($m['content'],0,80)) ?>...
              </td>
              <td class="dim mono" style="font-size:10px;"><?= $m['model_used']?:'-' ?></td>
              <td class="dim mono"><?= $m['tokens_used']?:'-' ?></td>
              <td>
                <button class="btn-ev gold sm" onclick="analyzeUser(<?= $m['user_id'] ?>, '<?= htmlspecialchars($m['pseudo']?:$m['email']) ?>')">⚡ IA</button>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ========================
           OPGA
           ======================== -->
      <div class="tab-pane" id="pane-opga">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div><div class="section-title">BASE OPGA / OPGV</div>
          <div class="section-sub">OFFRES & DEMANDES DÉTECTÉES PAR IA</div></div>
        </div>
        <div class="table-scroll">
          <table class="elvita-table">
            <thead><tr>
              <th>ID</th><th>MEMBRE</th><th>TYPE</th><th>TITRE</th><th>CATÉGORIE</th>
              <th>PRIX</th><th>STATUT</th><th>DATE</th><th>ACTIONS</th>
            </tr></thead>
            <tbody>
            <?php foreach ($last_opga as $o):
              $type_cls = ['achat'=>'cyan','vente'=>'green','location'=>'gold','emprunt'=>'red','auto-detecte'=>'purple'];
              $tc = $type_cls[$o['type']] ?? 'cyan';
            ?>
            <tr>
              <td class="mono">#<?= $o['id'] ?></td>
              <td><?= htmlspecialchars($o['pseudo']) ?></td>
              <td><span class="badge-ev <?= $tc ?>"><?= strtoupper($o['type']) ?></span></td>
              <td><strong><?= htmlspecialchars($o['titre']) ?></strong></td>
              <td class="dim"><?= htmlspecialchars($o['categorie']?:'-') ?></td>
              <td class="mono"><?= $o['prix_min']>0 ? number_format($o['prix_min'],2).'–'.number_format($o['prix_max'],2).' €' : '—' ?></td>
              <td><span class="badge-ev <?= $o['statut']==='actif'?'green':'red' ?>"><?= strtoupper($o['statut']) ?></span></td>
              <td class="dim" style="font-size:10px;"><?= substr($o['created_at'],0,10) ?></td>
              <td>
                <button class="btn-ev red sm" onclick="deleteOpga(<?= $o['id'] ?>)">✕</button>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ========================
           BOUTIQUE
           ======================== -->
      <div class="tab-pane" id="pane-boutique">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div><div class="section-title">BOUTIQUE ELVITA</div></div>
          <div class="section-actions">
            <button class="btn-ev gold" onclick="openAddBoutique()">+ AJOUTER PRODUIT</button>
          </div>
        </div>
        <div class="boutique-grid" id="boutique-grid">
          <?php foreach ($boutique_items as $item): ?>
          <div class="boutique-item <?= $item['actif']?'':'inactive' ?>">
            <div class="boutique-cat"><?= strtoupper(htmlspecialchars($item['categorie'])) ?></div>
            <div class="boutique-title"><?= htmlspecialchars($item['titre']) ?></div>
            <div class="dim" style="font-size:12px;margin-bottom:10px;"><?= htmlspecialchars(mb_substr($item['description'],0,60)) ?></div>
            <div class="boutique-price"><?= number_format($item['prix'],2) ?> <?= $item['devise'] ?></div>
            <div class="boutique-actions">
              <button class="btn-ev <?= $item['actif']?'red':'green' ?> sm"
                      onclick="toggleBoutique(<?= $item['id'] ?>, <?= $item['actif'] ?>, this)">
                <?= $item['actif'] ? '✕ DÉSACTIVER' : '✓ ACTIVER' ?>
              </button>
              <button class="btn-ev cyan sm" onclick="editBoutique(<?= htmlspecialchars(json_encode($item)) ?>)">✎ ÉDITER</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ========================
           IA PROMPTS EDITOR
           ======================== -->
      <div class="tab-pane" id="pane-ia-prompts">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div><div class="section-title">ÉDITEUR DE PROMPTS IA</div>
          <div class="section-sub">CONFIGURATION GLOBALE DES AGENTS ELVITA</div></div>
          <div class="section-actions">
            <button class="btn-ev gold" onclick="addPrompt()">+ NOUVEAU PROMPT</button>
          </div>
        </div>
        <div id="prompts-container">
          <?php foreach ($prompts_config as $pr): ?>
          <div class="prompt-card" id="prompt-<?= htmlspecialchars($pr['slug']) ?>">
            <div class="prompt-card-header">
              <div>
                <div class="prompt-slug"><?= htmlspecialchars($pr['slug']) ?></div>
                <div class="prompt-label"><?= htmlspecialchars($pr['label']) ?></div>
              </div>
              <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
                <span class="badge-ev purple"><?= htmlspecialchars($pr['model']) ?></span>
                <button class="btn-ev green sm" onclick="savePrompt('<?= $pr['id'] ?>')">✓ SAUVEGARDER</button>
              </div>
            </div>
            <div class="prompt-body">
              <textarea class="prompt-textarea" id="prompt-text-<?= $pr['id'] ?>"><?= htmlspecialchars($pr['content']) ?></textarea>
            </div>
            <div class="prompt-footer">
              <label style="font-family:var(--font-orb);font-size:8px;letter-spacing:2px;color:var(--text-dim);">MODÈLE IA</label>
              <select class="model-select" id="prompt-model-<?= $pr['id'] ?>">
                <?php
                $models = ['mistral-medium-2505','mistral-small-2603','mistral-large-2512','codestral-2508','magistral-medium-2509','pixtral-large-2411'];
                foreach ($models as $m):
                  $sel = $m === $pr['model'] ? 'selected' : '';
                ?>
                <option value="<?= $m ?>" <?= $sel ?>><?= $m ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn-ev red sm" style="margin-left:auto;" onclick="testPrompt(<?= $pr['id'] ?>)">⚡ TESTER</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ========================
           IA BATCH ANALYZE
           ======================== -->
      <div class="tab-pane" id="pane-ia-batch">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div><div class="section-title">ANALYSE BATCH IA</div>
          <div class="section-sub">ANALYSER PLUSIEURS PROFILS EN UNE SEULE PASSE</div></div>
        </div>
        <div class="data-card">
          <div class="data-card-header"><div class="data-card-title">⚡ SÉLECTION DES PROFILS</div></div>
          <div class="data-card-body">
            <div class="ev-form-row" style="margin-bottom:14px;">
              <div class="ev-field">
                <label>FILTRE</label>
                <select class="ev-select" id="batch-filter">
                  <option value="all">TOUS LES MEMBRES</option>
                  <option value="certified">CERTIFIÉS UNIQUEMENT</option>
                  <option value="active">ACTIFS (7 JOURS)</option>
                  <option value="low_bonheur">KPI BONHEUR FAIBLE</option>
                </select>
              </div>
              <div class="ev-field">
                <label>LIMITE</label>
                <input type="number" class="ev-input" id="batch-limit" value="5" min="1" max="20" style="max-width:80px;">
              </div>
              <button class="btn-ev gold" onclick="runBatchAnalysis()">⚡ LANCER L'ANALYSE BATCH</button>
            </div>
            <div id="batch-results"></div>
          </div>
        </div>
      </div>

      <!-- ========================
           VISITES
           ======================== -->
      <div class="tab-pane" id="pane-visites">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div><div class="section-title">JOURNAL DES VISITES</div></div>
        </div>
        <div class="table-scroll">
          <table class="elvita-table">
            <thead><tr><th>DATE</th><th>IP</th><th>PAGE</th><th>MEMBRE</th><th>USER AGENT</th></tr></thead>
            <tbody>
            <?php
            $vis = [];
            $vr = $db->query("SELECT v.*, u.pseudo FROM visites v LEFT JOIN users u ON v.user_id = u.id ORDER BY v.created_at DESC LIMIT 100");
            while ($row = $vr->fetchArray(SQLITE3_ASSOC)) $vis[] = $row;
            foreach ($vis as $v):
            ?>
            <tr>
              <td class="dim mono" style="font-size:10px;white-space:nowrap;"><?= $v['created_at'] ?></td>
              <td><span class="ip-badge" onclick="lookupIp('<?= htmlspecialchars($v['ip']) ?>')"><?= htmlspecialchars($v['ip']) ?></span></td>
              <td class="dim"><?= htmlspecialchars($v['page']) ?></td>
              <td><?= $v['pseudo'] ? '<span class="badge-ev cyan">'.htmlspecialchars($v['pseudo']).'</span>' : '<span style="color:var(--text-muted)">ANONYME</span>' ?></td>
              <td class="dim" style="font-size:10px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= htmlspecialchars(substr($v['user_agent'],0,80)) ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ========================
           AUDIT LOG
           ======================== -->
      <div class="tab-pane" id="pane-logs">
        <div class="section-header">
          <div class="section-header-line"></div>
          <div><div class="section-title">JOURNAL D'AUDIT ADMIN</div></div>
        </div>
        <div class="timeline" style="max-width:800px;">
          <?php foreach ($admin_logs as $log): ?>
          <div class="timeline-item">
            <div class="tl-dot <?= str_contains($log['action'],'delete')?'red':(str_contains($log['action'],'ai')?'gold':'') ?>"></div>
            <div class="tl-content">
              <div class="tl-action"><?= htmlspecialchars($log['action']) ?> — <span style="color:var(--text-dim)"><?= htmlspecialchars(mb_substr($log['details'],0,100)) ?></span></div>
              <div class="tl-time"><?= $log['created_at'] ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($admin_logs)): ?>
          <div style="font-family:var(--font-mono);color:var(--text-dim);font-size:12px;padding:20px 0;">Aucun log pour le moment.</div>
          <?php endif; ?>
        </div>
      </div>

    </main><!-- /main-content -->
  </div><!-- /admin-body -->
</div><!-- /admin-wrap -->

<!-- ============================================================
     MODALS
     ============================================================ -->

<!-- MODAL IA RAPPORT -->
<div class="modal-overlay" id="modal-ai">
  <div class="modal-panel">
    <div class="modal-header">
      <div class="modal-title" id="modal-ai-title">ANALYSE IA EN COURS</div>
      <button class="modal-close" onclick="closeModal('modal-ai')">✕</button>
    </div>
    <div class="modal-body" id="modal-ai-body">
      <div class="modal-loading"><div class="loader-ring"></div> MOTEUR ELVITA IA ACTIVÉ...</div>
    </div>
  </div>
</div>

<!-- MODAL USER DETAIL -->
<div class="modal-overlay" id="modal-user">
  <div class="modal-panel">
    <div class="modal-header">
      <div class="modal-title" id="modal-user-title">PROFIL MEMBRE</div>
      <button class="modal-close" onclick="closeModal('modal-user')">✕</button>
    </div>
    <div class="modal-body" id="modal-user-body"></div>
  </div>
</div>

<!-- MODAL CHAT HISTORIQUE -->
<div class="modal-overlay" id="modal-chat">
  <div class="modal-panel" style="max-width:600px;">
    <div class="modal-header">
      <div class="modal-title" id="modal-chat-title">CONVERSATIONS</div>
      <button class="modal-close" onclick="closeModal('modal-chat')">✕</button>
    </div>
    <div class="modal-body" id="modal-chat-body" style="max-height:60vh;overflow-y:auto;"></div>
  </div>
</div>

<!-- MODAL BOUTIQUE EDIT -->
<div class="modal-overlay" id="modal-boutique">
  <div class="modal-panel" style="max-width:500px;">
    <div class="modal-header">
      <div class="modal-title" id="modal-boutique-title">PRODUIT BOUTIQUE</div>
      <button class="modal-close" onclick="closeModal('modal-boutique')">✕</button>
    </div>
    <div class="modal-body">
      <form onsubmit="saveBoutique(event)">
        <input type="hidden" id="b-id" value="">
        <div class="ev-form-row">
          <div class="ev-field" style="flex:1;">
            <label>TITRE</label>
            <input type="text" class="ev-input" id="b-titre" required style="width:100%;">
          </div>
        </div>
        <div class="ev-form-row">
          <div class="ev-field" style="flex:1;">
            <label>DESCRIPTION</label>
            <textarea class="ev-input" id="b-desc" style="width:100%;height:80px;resize:vertical;"></textarea>
          </div>
        </div>
        <div class="ev-form-row">
          <div class="ev-field">
            <label>PRIX</label>
            <input type="number" class="ev-input" id="b-prix" step="0.01" min="0" required>
          </div>
          <div class="ev-field">
            <label>CATÉGORIE</label>
            <input type="text" class="ev-input" id="b-cat">
          </div>
          <div class="ev-field">
            <label>ACTIF</label>
            <select class="ev-select" id="b-actif">
              <option value="1">OUI</option>
              <option value="0">NON</option>
            </select>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;">
          <button type="button" class="btn-ev red" onclick="closeModal('modal-boutique')">ANNULER</button>
          <button type="submit" class="btn-ev gold">✓ SAUVEGARDER</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL MSG EXPAND -->
<div class="modal-overlay" id="modal-msg">
  <div class="modal-panel" style="max-width:560px;">
    <div class="modal-header">
      <div class="modal-title">MESSAGE COMPLET</div>
      <button class="modal-close" onclick="closeModal('modal-msg')">✕</button>
    </div>
    <div class="modal-body">
      <p class="modal-report" id="modal-msg-content" style="white-space:pre-wrap;"></p>
    </div>
  </div>
</div>

<?php endif; ?>

<!-- BOOTSTRAP 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- GSAP -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>

<script>
// ============================================================
//  ELVITA NEXUS — ADMIN JS ENGINE v4.0
// ============================================================

const notyf = new Notyf({ duration: 3000, position: { x:'right', y:'top' }, ripple: true });

// --- DATA ---
const STATS        = <?= $stats_json ?>;
const VISITS_DAY   = <?= $visits_day_json ?>;
const ALL_USERS    = <?= $users_json ?>;
const PROMPTS_CFG  = <?= $prompts_json ?>;

// --- CLOCK ---
function updateClock() {
  const el = document.getElementById('clock');
  if (el) el.textContent = new Date().toLocaleTimeString('fr-FR');
}
setInterval(updateClock, 1000);
updateClock();

// --- PARTICLES ---
<?php if ($logged_in): ?>
particlesJS('particles-js', {
  particles: {
    number: { value: 60 },
    color: { value: ['#00f0ff','#ffd700','#00ff88'] },
    shape: { type: 'circle' },
    opacity: { value: 0.25, random: true, anim: { enable: true, speed: 0.5, opacity_min: 0.05 } },
    size:    { value: 1.5,  random: true },
    line_linked: { enable: true, distance: 130, color: '#004466', opacity: 0.12, width: 0.8 },
    move:    { enable: true, speed: 0.6, direction: 'none', random: true, out_mode: 'out' }
  },
  interactivity: {
    detect_on: 'canvas',
    events: { onhover: { enable: true, mode: 'repulse' }, onclick: { enable: false } }
  },
  retina_detect: true
});
<?php endif; ?>

// --- TAB NAVIGATION ---
function showTab(name, el) {
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  if (el) el.classList.add('active');
  const pane = document.getElementById('pane-' + name);
  if (pane) pane.classList.add('active');

  // Lazy init charts
  if (name === 'dashboard') initCharts();
}

// --- CHARTS ---
let chartsInitialized = false;
function initCharts() {
  if (chartsInitialized) return;
  chartsInitialized = true;

  // Visit chart
  const vctx = document.getElementById('visitChart');
  if (vctx) {
    const labels = VISITS_DAY.map(d => d.day);
    const data   = VISITS_DAY.map(d => d.count);
    new Chart(vctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Visites',
          data,
          backgroundColor: 'rgba(0,240,255,0.15)',
          borderColor: '#00f0ff',
          borderWidth: 1,
          borderRadius: 2,
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          x: { ticks: { color: '#4a7a99', font: { family: 'Share Tech Mono', size: 10 } }, grid: { color: 'rgba(0,240,255,0.04)' } },
          y: { ticks: { color: '#4a7a99', font: { family: 'Share Tech Mono', size: 10 } }, grid: { color: 'rgba(0,240,255,0.04)' } }
        }
      }
    });
  }

  // KPI Radar
  const rctx = document.getElementById('kpiRadarChart');
  if (rctx && ALL_USERS.length) {
    const keys = ['kpi_bonheur','kpi_sante','kpi_finance','kpi_karma','kpi_amour','kpi_travail','kpi_confiance','kpi_influence'];
    const labels = ['Bonheur','Santé','Finance','Karma','Amour','Travail','Confiance','Influence'];
    const avgs = keys.map(k => {
      const vals = ALL_USERS.filter(u => u.role !== 'admin').map(u => parseFloat(u[k])||50);
      return vals.length ? Math.round(vals.reduce((a,b)=>a+b,0)/vals.length) : 50;
    });
    new Chart(rctx, {
      type: 'radar',
      data: {
        labels,
        datasets: [{
          data: avgs,
          backgroundColor: 'rgba(0,240,255,0.07)',
          borderColor: '#00f0ff',
          pointBackgroundColor: '#ffd700',
          borderWidth: 1.5,
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
          r: {
            min: 0, max: 100,
            ticks: { color: '#4a7a99', font: { size: 8 }, backdropColor: 'transparent' },
            grid: { color: 'rgba(0,240,255,0.06)' },
            pointLabels: { color: '#4a7a99', font: { family: 'Share Tech Mono', size: 9 } }
          }
        }
      }
    });
  }
}

// Init dashboard on load
initCharts();

// --- MODAL HELPERS ---
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

// --- ANALYZE USER (IA) ---
function analyzeUser(userId, pseudo) {
  document.getElementById('modal-ai-title').textContent = '⚡ ANALYSE IA :: ' + (pseudo||'#'+userId).toUpperCase();
  document.getElementById('modal-ai-body').innerHTML = '<div class="modal-loading"><div class="loader-ring"></div> CONNEXION AU MOTEUR ELVITA IA...</div>';
  openModal('modal-ai');

  fetch('../api/mistral.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'admin_analyze', target_user_id: userId})
  })
  .then(r => r.json())
  .then(data => {
    if (data.report) {
      document.getElementById('modal-ai-body').innerHTML =
        '<div class="modal-report">' + escHtml(data.report) + '</div>';
    } else {
      document.getElementById('modal-ai-body').innerHTML =
        '<div style="color:var(--red);font-family:var(--font-mono);font-size:12px;">⚠ ERREUR: ' + escHtml(data.error||'Inconnue') + '</div>';
    }
  })
  .catch(() => {
    document.getElementById('modal-ai-body').innerHTML = '<div style="color:var(--red);">Erreur réseau — vérifiez la connexion API.</div>';
  });
}

// --- USER DETAIL MODAL ---
function openUserModal(user) {
  document.getElementById('modal-user-title').textContent = '◈ PROFIL :: ' + (user.pseudo||user.email).toUpperCase();
  const kpis = ['kpi_bonheur','kpi_sante','kpi_finance','kpi_karma','kpi_amour','kpi_travail','kpi_confiance','kpi_influence'];
  const labels = ['Bonheur','Santé','Finance','Karma','Amour','Travail','Confiance','Influence'];
  const bars = kpis.map((k,i) => `
    <div class="kpi-bar-row">
      <span class="kpi-bar-label" style="width:60px;">${labels[i]}</span>
      <div class="kpi-bar-track" style="flex:1;"><div class="kpi-bar-fill" style="width:${user[k]||50}%"></div></div>
      <span class="kpi-bar-val">${Math.round(user[k]||50)}%</span>
    </div>`).join('');
  document.getElementById('modal-user-body').innerHTML = `
    <div class="user-detail-grid">
      <div class="user-detail-meta">
        <div class="ud-row"><span class="ud-key">ID</span><span class="ud-val mono">#${user.id}</span></div>
        <div class="ud-row"><span class="ud-key">EMAIL</span><span class="ud-val" style="font-size:13px;">${escHtml(user.email)}</span></div>
        <div class="ud-row"><span class="ud-key">PSEUDO</span><span class="ud-val">${escHtml(user.pseudo)}</span></div>
        <div class="ud-row"><span class="ud-key">RÔLE</span><span class="ud-val"><span class="badge-ev ${user.role==='admin'?'gold':'cyan'}">${user.role.toUpperCase()}</span></span></div>
        <div class="ud-row"><span class="ud-key">CERTIFIÉ</span><span class="ud-val"><span class="badge-ev ${user.certified?'green':'red'}">${user.certified?'✓ OUI':'✗ NON'}</span></span></div>
        <div class="ud-row"><span class="ud-key">INSCRIT</span><span class="ud-val" style="font-size:12px;">${user.created_at}</span></div>
        <div class="ud-row"><span class="ud-key">DERNIÈRE CONNEXION</span><span class="ud-val" style="font-size:12px;">${user.last_seen}</span></div>
      </div>
      <div>
        <div style="font-family:var(--font-orb);font-size:9px;letter-spacing:2px;color:var(--text-dim);margin-bottom:10px;">KPIs PROFIL</div>
        <div class="kpi-bars">${bars}</div>
        <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">
          <button class="btn-ev gold" onclick="analyzeUser(${user.id},'${escHtml(user.pseudo)}');closeModal('modal-user')">⚡ ANALYSE IA</button>
          <button class="btn-ev purple" onclick="showUserChat(${user.id},'${escHtml(user.pseudo)}');closeModal('modal-user')">◉ CONVERSATIONS</button>
        </div>
      </div>
    </div>`;
  openModal('modal-user');
}

// --- SHOW USER CHAT ---
function showUserChat(userId, pseudo) {
  document.getElementById('modal-chat-title').textContent = '◉ CONVERSATIONS :: ' + pseudo.toUpperCase();
  document.getElementById('modal-chat-body').innerHTML = '<div class="modal-loading"><div class="loader-ring"></div></div>';
  openModal('modal-chat');

  fetch('ajax.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'get_user_chat', user_id: userId})
  })
  .then(r => r.json())
  .then(data => {
    if (!data.messages || !data.messages.length) {
      document.getElementById('modal-chat-body').innerHTML = '<div style="color:var(--text-dim);font-family:var(--font-mono);font-size:12px;padding:20px;">Aucun message pour cet utilisateur.</div>';
      return;
    }
    let html = '';
    data.messages.forEach(m => {
      const cls = m.role === 'user' ? 'user' : '';
      html += `<div class="chat-bubble ${cls}">
        <div class="bubble-meta">${m.role==='user'?pseudo:'CLONE IA'} · ${m.created_at}</div>
        <div class="bubble-inner">${escHtml(m.content)}</div>
      </div>`;
    });
    document.getElementById('modal-chat-body').innerHTML = html;
  })
  .catch(() => {
    document.getElementById('modal-chat-body').innerHTML = '<div style="color:var(--red);">Erreur réseau</div>';
  });
}

// --- FILTERS ---
function filterUsers(q) {
  const role = document.getElementById('users-filter-role')?.value || '';
  const cert = document.getElementById('users-filter-cert')?.value || '';
  q = q.toLowerCase();
  document.querySelectorAll('#users-tbody tr').forEach(row => {
    const email = (row.dataset.email||'').toLowerCase();
    const pseudo = (row.dataset.pseudo||'').toLowerCase();
    const r = row.dataset.role || '';
    const c = row.dataset.cert || '';
    const textMatch = email.includes(q) || pseudo.includes(q);
    const roleMatch = !role || r === role;
    const certMatch = !cert || c === cert;
    row.style.display = (textMatch && roleMatch && certMatch) ? '' : 'none';
  });
}

function filterMessages(q) {
  const type = document.getElementById('msg-filter-type')?.value || '';
  q = q.toLowerCase();
  document.querySelectorAll('#msgs-tbody tr').forEach(row => {
    const user    = (row.dataset.user||'').toLowerCase();
    const content = (row.dataset.content||'').toLowerCase();
    const t       = row.dataset.type || '';
    const textMatch = user.includes(q) || content.includes(q);
    const typeMatch = !type || t === type;
    row.style.display = (textMatch && typeMatch) ? '' : 'none';
  });
}

// --- EXPAND MESSAGE ---
function expandMsg(td, content) {
  document.getElementById('modal-msg-content').textContent = content;
  openModal('modal-msg');
}

// --- TOGGLE CERTIFICATION ---
function toggleCert(userId, current, btn) {
  const newVal = current ? 0 : 1;
  fetch('ajax.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'toggle_cert', user_id: userId, value: newVal})
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      btn.textContent = newVal ? '✓ CERTIFIÉ' : '✗ NON CERTIFIÉ';
      btn.className = 'cert-toggle ' + (newVal ? '' : 'off');
      btn.onclick = () => toggleCert(userId, newVal, btn);
      notyf.success(newVal ? 'Utilisateur certifié ✓' : 'Certification retirée');
    } else {
      notyf.error(data.error || 'Erreur');
    }
  });
}

// --- DELETE USER ---
function deleteUser(userId, pseudo) {
  if (!confirm(`⚠ SUPPRIMER ${pseudo} (#${userId}) et toutes ses données ?`)) return;
  fetch('ajax.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete_user', user_id: userId})
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      document.querySelector(`#users-tbody tr[data-pseudo="${pseudo}"]`)?.remove();
      notyf.success('Utilisateur supprimé');
    } else notyf.error(data.error || 'Erreur');
  });
}

// --- DELETE OPGA ---
function deleteOpga(id) {
  if (!confirm('Supprimer cette OPGA #' + id + ' ?')) return;
  fetch('ajax.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete_opga', id})
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      notyf.success('OPGA supprimée');
      location.reload();
    } else notyf.error(data.error||'Erreur');
  });
}

// --- BOUTIQUE ---
function openAddBoutique() {
  document.getElementById('b-id').value = '';
  document.getElementById('b-titre').value = '';
  document.getElementById('b-desc').value = '';
  document.getElementById('b-prix').value = '';
  document.getElementById('b-cat').value = '';
  document.getElementById('b-actif').value = '1';
  document.getElementById('modal-boutique-title').textContent = '+ NOUVEAU PRODUIT';
  openModal('modal-boutique');
}
function editBoutique(item) {
  document.getElementById('b-id').value = item.id;
  document.getElementById('b-titre').value = item.titre;
  document.getElementById('b-desc').value = item.description;
  document.getElementById('b-prix').value = item.prix;
  document.getElementById('b-cat').value = item.categorie;
  document.getElementById('b-actif').value = item.actif;
  document.getElementById('modal-boutique-title').textContent = '✎ ÉDITER PRODUIT';
  openModal('modal-boutique');
}
function saveBoutique(e) {
  e.preventDefault();
  fetch('ajax.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      action: 'save_boutique',
      id: document.getElementById('b-id').value,
      titre: document.getElementById('b-titre').value,
      description: document.getElementById('b-desc').value,
      prix: document.getElementById('b-prix').value,
      categorie: document.getElementById('b-cat').value,
      actif: document.getElementById('b-actif').value,
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) { notyf.success('Produit sauvegardé ✓'); closeModal('modal-boutique'); setTimeout(()=>location.reload(),800); }
    else notyf.error(data.error||'Erreur');
  });
}
function toggleBoutique(id, current, btn) {
  const newVal = current ? 0 : 1;
  fetch('ajax.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'toggle_boutique', id, value: newVal})
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      btn.textContent = newVal ? '✕ DÉSACTIVER' : '✓ ACTIVER';
      btn.className = 'btn-ev ' + (newVal ? 'red' : 'green') + ' sm';
      btn.onclick = () => toggleBoutique(id, newVal, btn);
      btn.closest('.boutique-item').classList.toggle('inactive', !newVal);
      notyf.success(newVal ? 'Produit activé' : 'Produit désactivé');
    }
  });
}

// --- PROMPTS IA ---
function savePrompt(id) {
  const content = document.getElementById('prompt-text-' + id).value;
  const model   = document.getElementById('prompt-model-' + id).value;
  fetch('ajax.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'save_prompt', id, content, model})
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) notyf.success('Prompt sauvegardé ✓');
    else notyf.error(data.error||'Erreur');
  });
}
function testPrompt(id) {
  const content = document.getElementById('prompt-text-' + id).value;
  const model   = document.getElementById('prompt-model-' + id).value;
  document.getElementById('modal-ai-title').textContent = '⚡ TEST PROMPT :: #' + id;
  document.getElementById('modal-ai-body').innerHTML = '<div class="modal-loading"><div class="loader-ring"></div> ENVOI AU MOTEUR IA...</div>';
  openModal('modal-ai');
  fetch('ajax.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'test_prompt', content, model})
  })
  .then(r => r.json())
  .then(data => {
    document.getElementById('modal-ai-body').innerHTML =
      '<div class="modal-report">' + escHtml(data.result || data.error || 'Aucune réponse') + '</div>';
  });
}
function addPrompt() {
  const slug  = prompt('Slug du prompt (ex: opga_detect):');
  const label = prompt('Libellé:');
  if (!slug || !label) return;
  fetch('ajax.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'add_prompt', slug, label})
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) { notyf.success('Prompt ajouté'); location.reload(); }
    else notyf.error(data.error||'Erreur');
  });
}

// --- BATCH ANALYZE ---
function runBatchAnalysis() {
  const filter = document.getElementById('batch-filter').value;
  const limit  = parseInt(document.getElementById('batch-limit').value) || 5;
  const resEl  = document.getElementById('batch-results');
  resEl.innerHTML = '<div class="modal-loading"><div class="loader-ring"></div> ANALYSE BATCH EN COURS... (' + limit + ' profils)</div>';

  // Get matching users
  let users = ALL_USERS.filter(u => u.role !== 'admin');
  if (filter === 'certified') users = users.filter(u => u.certified);
  if (filter === 'low_bonheur') users = users.filter(u => parseFloat(u.kpi_bonheur) < 40);
  users = users.slice(0, limit);

  if (!users.length) {
    resEl.innerHTML = '<div style="color:var(--text-dim);font-family:var(--font-mono);font-size:12px;padding:20px;">Aucun profil correspondant.</div>';
    return;
  }

  let done = 0;
  const results = [];

  function analyzeNext(idx) {
    if (idx >= users.length) {
      resEl.innerHTML = results.map((r,i) => `
        <div class="prompt-card" style="margin-bottom:12px;">
          <div class="prompt-card-header">
            <div><div class="prompt-slug">#${users[i].id}</div><div class="prompt-label">${escHtml(users[i].pseudo||users[i].email)}</div></div>
          </div>
          <div class="prompt-body"><div class="modal-report" style="font-size:13px;">${escHtml(r)}</div></div>
        </div>`).join('');
      return;
    }
    const u = users[idx];
    resEl.innerHTML = `<div class="modal-loading"><div class="loader-ring"></div> Analyse ${idx+1}/${users.length} — ${escHtml(u.pseudo||u.email)}</div>`;
    fetch('../api/mistral.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'admin_analyze', target_user_id: u.id})
    })
    .then(r => r.json())
    .then(data => {
      results.push(data.report || (data.error||'Erreur'));
      setTimeout(() => analyzeNext(idx + 1), 1200); // Respect rate limit
    })
    .catch(() => { results.push('Erreur réseau'); analyzeNext(idx + 1); });
  }
  analyzeNext(0);
}

// --- OPEN BATCH ANALYZE (shortcut) ---
function openBatchAnalyze() { showTab('ia-batch', null); document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active')); }

// --- IP LOOKUP ---
function lookupIp(ip) {
  window.open('https://ipinfo.io/' + ip, '_blank');
}

// --- REFRESH DASHBOARD ---
function refreshDashboard() {
  notyf.success('Actualisation...');
  location.reload();
}

// --- AUTO REFRESH ---
let autoRefreshInterval = null;
function startAutoRefresh() {
  const btn = document.getElementById('auto-btn');
  if (autoRefreshInterval) {
    clearInterval(autoRefreshInterval);
    autoRefreshInterval = null;
    btn.textContent = '▶ AUTO-REFRESH';
    btn.classList.remove('red');
    btn.classList.add('green');
    notyf.success('Auto-refresh désactivé');
  } else {
    autoRefreshInterval = setInterval(refreshDashboard, 30000);
    btn.textContent = '■ ARRÊTER (30s)';
    btn.classList.remove('green');
    btn.classList.add('red');
    notyf.success('Auto-refresh toutes les 30s');
  }
}

// --- TERMINAL ANIMATION ---
function addTermLine(text, cls = '') {
  const t = document.getElementById('terminal');
  if (!t) return;
  const d = document.createElement('div');
  d.className = 'term-line' + (cls ? ' ' + cls : '');
  const ts = new Date().toISOString().replace('T',' ').substr(0,19);
  d.textContent = '[' + ts + '] ' + text;
  const cursor = t.querySelector('.term-cursor');
  if (cursor) t.insertBefore(d, cursor.parentElement);
  t.scrollTop = t.scrollHeight;
}

// --- GSAP ENTRANCE ANIMATIONS ---
gsap.from('.kpi-card', { duration: 0.6, opacity: 0, y: 20, stagger: 0.08, ease: 'power2.out' });
gsap.from('.topbar', { duration: 0.4, y: -64, opacity: 0, ease: 'power2.out' });
gsap.from('.sidebar', { duration: 0.5, x: -220, opacity: 0, ease: 'power2.out', delay: 0.2 });

// --- KPI COUNTER ANIMATION ---
document.querySelectorAll('.kpi-value').forEach(el => {
  const target = parseInt(el.textContent.replace(/\D/g,'')) || 0;
  if (target === 0 || isNaN(target)) return;
  let cur = 0;
  const step = Math.max(1, Math.floor(target / 40));
  const timer = setInterval(() => {
    cur = Math.min(cur + step, target);
    el.textContent = cur.toLocaleString();
    if (cur >= target) clearInterval(timer);
  }, 25);
});

// --- UTIL ---
function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Tippy tooltips
tippy('[data-tippy-content]', { theme: 'dark' });
</script>

</body>
</html>
