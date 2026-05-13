<?php
// api/auth.php - Authentification Elvita

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../db/init.php';

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$db = getDB();

// CONNEXION
if ($action === 'login') {
    $email = trim(strtolower($input['email'] ?? ''));
    $pass = $input['password'] ?? '';
    
    if (empty($email) || empty($pass)) {
        echo json_encode(['error' => 'Email et mot de passe requis']);
        exit;
    }
    
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bindValue(1, $email);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$user || !password_verify($pass, $user['password'])) {
        echo json_encode(['error' => 'Identifiants incorrects']);
        exit;
    }
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['chat_session'] = uniqid('sess_', true);
    
    // Mettre à jour last_seen
    $db->exec("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = {$user['id']}");
    
    // Log visite
    logVisit($db, '/app', $user['id']);
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'pseudo' => $user['pseudo'],
            'role' => $user['role'],
            'certified' => (bool)$user['certified'],
            'kpis' => [
                'bonheur' => round($user['kpi_bonheur'], 1),
                'sante' => round($user['kpi_sante'], 1),
                'finance' => round($user['kpi_finance'], 1),
                'karma' => round($user['kpi_karma'], 1),
                'amour' => round($user['kpi_amour'], 1),
                'travail' => round($user['kpi_travail'], 1),
                'confiance' => round($user['kpi_confiance'], 1),
                'influence' => round($user['kpi_influence'], 1),
            ]
        ]
    ]);
    exit;
}

// INSCRIPTION
if ($action === 'register') {
    $email = trim(strtolower($input['email'] ?? ''));
    $pass = $input['password'] ?? '';
    $pseudo = trim($input['pseudo'] ?? '');
    
    if (empty($email) || empty($pass)) {
        echo json_encode(['error' => 'Email et mot de passe requis']);
        exit;
    }
    
    if (strlen($pass) < 6) {
        echo json_encode(['error' => 'Mot de passe trop court (6 caractères min)']);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'Email invalide']);
        exit;
    }
    
    // Vérifier si l'email existe
    $check = $db->querySingle("SELECT id FROM users WHERE email = '$email'");
    if ($check) {
        echo json_encode(['error' => 'Cet email est déjà utilisé']);
        exit;
    }
    
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pseudo_safe = $db->escapeString($pseudo ?: explode('@', $email)[0]);
    
    $stmt = $db->prepare("INSERT INTO users (email, password, pseudo) VALUES (?, ?, ?)");
    $stmt->bindValue(1, $email);
    $stmt->bindValue(2, $hash);
    $stmt->bindValue(3, $pseudo_safe);
    $stmt->execute();
    
    $new_id = $db->lastInsertRowID();
    
    $_SESSION['user_id'] = $new_id;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = 'user';
    $_SESSION['chat_session'] = uniqid('sess_', true);
    
    logVisit($db, '/register', $new_id);
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $new_id,
            'email' => $email,
            'pseudo' => $pseudo ?: explode('@', $email)[0],
            'role' => 'user',
            'certified' => false,
            'kpis' => [
                'bonheur' => 50.0, 'sante' => 50.0, 'finance' => 50.0,
                'karma' => 50.0, 'amour' => 50.0, 'travail' => 50.0,
                'confiance' => 50.0, 'influence' => 50.0,
            ]
        ]
    ]);
    exit;
}

// DÉCONNEXION
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// VÉRIFIER SESSION
if ($action === 'check') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['authenticated' => false]);
        exit;
    }
    
    $user = $db->querySingle("SELECT * FROM users WHERE id = {$_SESSION['user_id']}", true);
    if (!$user) {
        session_destroy();
        echo json_encode(['authenticated' => false]);
        exit;
    }
    
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'pseudo' => $user['pseudo'],
            'role' => $user['role'],
            'certified' => (bool)$user['certified'],
            'kpis' => [
                'bonheur' => round($user['kpi_bonheur'], 1),
                'sante' => round($user['kpi_sante'], 1),
                'finance' => round($user['kpi_finance'], 1),
                'karma' => round($user['kpi_karma'], 1),
                'amour' => round($user['kpi_amour'], 1),
                'travail' => round($user['kpi_travail'], 1),
                'confiance' => round($user['kpi_confiance'], 1),
                'influence' => round($user['kpi_influence'], 1),
            ]
        ]
    ]);
    exit;
}

// GET BOUTIQUE
if ($action === 'boutique') {
    $items = [];
    $result = $db->query("SELECT * FROM boutique WHERE actif = 1 ORDER BY categorie, prix");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $items[] = $row;
    }
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// GET OPGA (liste publique)
if ($action === 'opga_list') {
    $items = [];
    $result = $db->query("SELECT o.*, u.pseudo FROM opga o JOIN users u ON o.user_id = u.id WHERE o.public = 1 AND o.statut = 'actif' ORDER BY o.created_at DESC LIMIT 50");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $items[] = $row;
    }
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

// CRÉER OPGA
if ($action === 'opga_create') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['error' => 'Non authentifié']);
        exit;
    }
    $uid = $_SESSION['user_id'];
    $type = $input['type'] ?? 'achat';
    $titre = trim($input['titre'] ?? '');
    $desc = trim($input['description'] ?? '');
    $cat = trim($input['categorie'] ?? '');
    $prix_min = floatval($input['prix_min'] ?? 0);
    $prix_max = floatval($input['prix_max'] ?? 0);
    $public = intval($input['public'] ?? 1);
    
    if (empty($titre)) {
        echo json_encode(['error' => 'Titre requis']);
        exit;
    }
    
    $stmt = $db->prepare("INSERT INTO opga (user_id, type, titre, description, categorie, prix_min, prix_max, public) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $uid);
    $stmt->bindValue(2, $type);
    $stmt->bindValue(3, $titre);
    $stmt->bindValue(4, $desc);
    $stmt->bindValue(5, $cat);
    $stmt->bindValue(6, $prix_min);
    $stmt->bindValue(7, $prix_max);
    $stmt->bindValue(8, $public);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'id' => $db->lastInsertRowID()]);
    exit;
}

echo json_encode(['error' => 'Action inconnue']);
