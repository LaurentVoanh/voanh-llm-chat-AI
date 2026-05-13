<?php
// admin/ajax.php — ELVITA NEXUS v4.0 :: AJAX Handler Admin

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../db/init.php';

// Vérification session admin
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$db = getDB();
$admin_id = $_SESSION['admin_id'];

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// ---- LOG ADMIN ----
function logAdmin($db, $action, $details = '') {
    $stmt = $db->prepare("INSERT INTO admin_log (action, details) VALUES (?, ?)");
    $stmt->bindValue(1, $action);
    $stmt->bindValue(2, substr($details, 0, 500));
    $stmt->execute();
}

// ---- TOGGLE CERTIFICATION ----
if ($action === 'toggle_cert') {
    $user_id = intval($input['user_id'] ?? 0);
    $value   = intval($input['value'] ?? 0);
    if (!$user_id) {
        echo json_encode(['error' => 'ID requis']);
        exit;
    }
    $db->exec("UPDATE users SET certified = $value WHERE id = $user_id");
    logAdmin($db, 'toggle_cert', "user_id=$user_id value=$value");
    echo json_encode(['success' => true]);
    exit;
}

// ---- DELETE USER ----
if ($action === 'delete_user') {
    $user_id = intval($input['user_id'] ?? 0);
    if (!$user_id || $user_id === $admin_id) {
        echo json_encode(['error' => 'ID invalide ou auto-suppression impossible']);
        exit;
    }
    $db->exec("DELETE FROM messages WHERE user_id = $user_id");
    $db->exec("DELETE FROM opga WHERE user_id = $user_id");
    $db->exec("DELETE FROM visites WHERE user_id = $user_id");
    $db->exec("DELETE FROM users WHERE id = $user_id AND role != 'admin'");
    logAdmin($db, 'delete_user', "user_id=$user_id");
    echo json_encode(['success' => true]);
    exit;
}

// ---- DELETE OPGA ----
if ($action === 'delete_opga') {
    $id = intval($input['id'] ?? 0);
    if (!$id) {
        echo json_encode(['error' => 'ID requis']);
        exit;
    }
    $db->exec("DELETE FROM opga WHERE id = $id");
    logAdmin($db, 'delete_opga', "id=$id");
    echo json_encode(['success' => true]);
    exit;
}

// ---- GET USER CHAT ----
if ($action === 'get_user_chat') {
    $user_id = intval($input['user_id'] ?? 0);
    if (!$user_id) {
        echo json_encode(['error' => 'ID requis']);
        exit;
    }
    $messages = [];
    $result = $db->query("SELECT role, content, sender_type, model_used, tokens_used, created_at FROM messages WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 60");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $messages[] = $row;
    }
    echo json_encode(['success' => true, 'messages' => array_reverse($messages)]);
    exit;
}

// ---- SAVE BOUTIQUE ----
if ($action === 'save_boutique') {
    $id    = intval($input['id'] ?? 0);
    $titre = trim($input['titre'] ?? '');
    $desc  = trim($input['description'] ?? '');
    $prix  = floatval($input['prix'] ?? 0);
    $cat   = trim($input['categorie'] ?? '');
    $actif = intval($input['actif'] ?? 1);
    if (empty($titre)) {
        echo json_encode(['error' => 'Titre requis']);
        exit;
    }
    if ($id) {
        $stmt = $db->prepare("UPDATE boutique SET titre=?, description=?, prix=?, categorie=?, actif=? WHERE id=?");
        $stmt->bindValue(1, $titre);
        $stmt->bindValue(2, $desc);
        $stmt->bindValue(3, $prix);
        $stmt->bindValue(4, $cat);
        $stmt->bindValue(5, $actif);
        $stmt->bindValue(6, $id);
        $stmt->execute();
        logAdmin($db, 'update_boutique', "id=$id titre=$titre");
    } else {
        $stmt = $db->prepare("INSERT INTO boutique (titre, description, prix, categorie, actif) VALUES (?,?,?,?,?)");
        $stmt->bindValue(1, $titre);
        $stmt->bindValue(2, $desc);
        $stmt->bindValue(3, $prix);
        $stmt->bindValue(4, $cat);
        $stmt->bindValue(5, $actif);
        $stmt->execute();
        logAdmin($db, 'add_boutique', "titre=$titre prix=$prix");
    }
    echo json_encode(['success' => true]);
    exit;
}

// ---- TOGGLE BOUTIQUE ----
if ($action === 'toggle_boutique') {
    $id    = intval($input['id'] ?? 0);
    $value = intval($input['value'] ?? 0);
    $db->exec("UPDATE boutique SET actif = $value WHERE id = $id");
    logAdmin($db, 'toggle_boutique', "id=$id active=$value");
    echo json_encode(['success' => true]);
    exit;
}

// ---- SAVE PROMPT IA ----
if ($action === 'save_prompt') {
    $id      = intval($input['id'] ?? 0);
    $content = trim($input['content'] ?? '');
    $model   = trim($input['model'] ?? 'mistral-medium-2505');
    if (!$id || empty($content)) {
        echo json_encode(['error' => 'ID et contenu requis']);
        exit;
    }
    $stmt = $db->prepare("UPDATE ia_prompts SET content=?, model=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmt->bindValue(1, $content);
    $stmt->bindValue(2, $model);
    $stmt->bindValue(3, $id);
    $stmt->execute();
    logAdmin($db, 'save_prompt', "id=$id model=$model");
    echo json_encode(['success' => true]);
    exit;
}

// ---- ADD PROMPT IA ----
if ($action === 'add_prompt') {
    $slug  = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($input['slug'] ?? '')));
    $label = trim($input['label'] ?? '');
    if (!$slug || !$label) {
        echo json_encode(['error' => 'Slug et libellé requis']);
        exit;
    }
    $check = $db->querySingle("SELECT id FROM ia_prompts WHERE slug='$slug'");
    if ($check) {
        echo json_encode(['error' => 'Ce slug existe déjà']);
        exit;
    }
    $stmt = $db->prepare("INSERT INTO ia_prompts (slug, label, content) VALUES (?, ?, ?)");
    $stmt->bindValue(1, $slug);
    $stmt->bindValue(2, $label);
    $stmt->bindValue(3, 'Entrez votre prompt ici...');
    $stmt->execute();
    logAdmin($db, 'add_prompt', "slug=$slug");
    echo json_encode(['success' => true]);
    exit;
}

// ---- TEST PROMPT IA ----
if ($action === 'test_prompt') {
    set_time_limit(120);
    $content = trim($input['content'] ?? '');
    $model   = trim($input['model'] ?? 'mistral-medium-2505');
    if (empty($content)) {
        echo json_encode(['error' => 'Prompt vide']);
        exit;
    }

    $api_keys = [
        '5qaRTgfdsRake',
        'o3rG1zgfdsShytu',
        'vEzQMKgfdsFruXkF',
    ];
    $api_key = $api_keys[0];

    $payload = json_encode([
        'model'      => $model,
        'messages'   => [
            ['role' => 'system', 'content' => $content],
            ['role' => 'user',   'content' => 'Test du prompt : fournis une réponse courte de démonstration.']
        ],
        'max_tokens' => 400,
        'temperature'=> 0.7,
    ]);

    $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        echo json_encode(['error' => 'Timeout ou erreur cURL']);
        exit;
    }
    $data   = json_decode($response, true);
    $result = $data['choices'][0]['message']['content'] ?? ('Erreur API: ' . ($data['message'] ?? 'Inconnue'));
    logAdmin($db, 'test_prompt', "model=$model");
    echo json_encode(['success' => true, 'result' => $result]);
    exit;
}

// ---- GET STATS (AJAX refresh) ----
if ($action === 'get_stats') {
    echo json_encode([
        'success' => true,
        'stats'   => [
            'total_users'     => $db->querySingle("SELECT COUNT(*) FROM users WHERE role != 'admin'"),
            'total_messages'  => $db->querySingle("SELECT COUNT(*) FROM messages"),
            'visites_today'   => $db->querySingle("SELECT COUNT(*) FROM visites WHERE DATE(created_at) = DATE('now')"),
            'tokens_used'     => $db->querySingle("SELECT COALESCE(SUM(tokens_used),0) FROM messages"),
            'active_sessions' => $db->querySingle("SELECT COUNT(DISTINCT session_id) FROM messages WHERE created_at >= datetime('now','-1 day')"),
        ]
    ]);
    exit;
}

echo json_encode(['error' => 'Action inconnue: ' . $action]);
