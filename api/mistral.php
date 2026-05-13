<?php
/**
 * api/mistral.php - Moteur IA Elvita ADVANCED v5.0
 * ARCHITECTURE MULTI-AGENTS AVEC CHAIN-OF-THOUGHT, VALIDATION CRITIQUE, ET APPRENTISSAGE PAR RENFORCEMENT
 * 100% Mistral API - Aucune dépendance externe supplémentaire
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

error_reporting(0);
ini_set('display_errors', 0);

// ---------- CONFIGURATION AVANCÉE ----------
define('API_KEYS', [
    1 => '5qaRTjgfdsake',      // Slot 1: Chat principal
    2 => 'o3rG1gfdsShytu',     // Slot 2: Analyse rapide
    3 => 'vEzQMgfdjFruXkF',    // Slot 3: Admin/Critique
]);

define('MISTRAL_URL', 'https://api.mistral.ai/v1/chat/completions');

// Modèles par type de tâche
define('MODEL_CHAT_GENERAL', 'mistral-medium-2505');
define('MODEL_CHAT_PHILOSOPHER', 'mistral-medium-2505');
define('MODEL_CHAT_COACH', 'mistral-medium-2505');
define('MODEL_CHAT_ANALYST', 'mistral-large-2411');
define('MODEL_CHAT_MERCHANT', 'mistral-medium-2505');
define('MODEL_CHAT_THERAPIST', 'mistral-medium-2505');
define('MODEL_CRITIC', 'mistral-small-latest');
define('MODEL_SUMMARIZER', 'mistral-small-latest');
define('MODEL_ADMIN', 'magistral-medium-2509');

// Configuration agents
define('AUTO_ANALYSE_INTERVAL', 7);
define('API_TIMEOUT', 120);
define('MAX_HISTORY_MESSAGES', 15);
define('MEMORY_LIMIT', 8);
define('CRITIC_THRESHOLD', 7.0);

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'chat';
$user_id = $_SESSION['user_id'] ?? null;
$session_id = $_SESSION['chat_session'] ?? uniqid('sess_', true);
$_SESSION['chat_session'] = $session_id;

if (!$user_id) {
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

require_once __DIR__ . '/../db/init.php';

// ---------- INITIALISATION BASE DE DONNÉES ----------
function initAdvancedTables($db) {
    // Tables existantes
    $db->exec("CREATE TABLE IF NOT EXISTS user_memory (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        key TEXT NOT NULL,
        value TEXT,
        confidence INTEGER DEFAULT 50,
        embedding TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_user ON user_memory(user_id, key)");
    
    $db->exec("CREATE TABLE IF NOT EXISTS user_refinements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        refinement_key TEXT NOT NULL,
        refinement_value TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // NOUVELLES TABLES POUR FONCTIONNALITÉS AVANCÉES
    $db->exec("CREATE TABLE IF NOT EXISTS feedback_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        reward REAL DEFAULT 0,
        interaction_type TEXT NOT NULL,
        agent_used TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(message_id) REFERENCES messages(id)
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS agent_performance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        agent_name TEXT UNIQUE NOT NULL,
        total_conversations INTEGER DEFAULT 0,
        avg_satisfaction REAL DEFAULT 5.0,
        avg_response_time REAL DEFAULT 0,
        most_used_triggers TEXT DEFAULT '[]',
        last_optimized DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS conversation_branches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_message_id INTEGER,
        user_id INTEGER NOT NULL,
        branch_label TEXT,
        branch_prompt TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS dynamic_agents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        role TEXT NOT NULL,
        system_prompt TEXT NOT NULL,
        triggers TEXT DEFAULT '[]',
        temperature REAL DEFAULT 0.7,
        model TEXT DEFAULT 'mistral-medium-2505',
        active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS knowledge_base (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        content TEXT NOT NULL,
        tags TEXT DEFAULT '[]',
        category TEXT DEFAULT 'general',
        source TEXT DEFAULT 'ai_extracted',
        usage_count INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS user_achievements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        achievement_type TEXT NOT NULL,
        xp_earned INTEGER DEFAULT 0,
        level_unlocked INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS daily_quests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        quest_type TEXT NOT NULL,
        progress INTEGER DEFAULT 0,
        target INTEGER NOT NULL,
        completed INTEGER DEFAULT 0,
        reward_xp INTEGER DEFAULT 0,
        reward_kpi_json TEXT DEFAULT '{}',
        date_assigned DATE DEFAULT CURRENT_DATE,
        UNIQUE(user_id, quest_type, date_assigned)
    )");
    
    // Ajouter colonnes manquantes aux tables existantes
    try { $db->exec("ALTER TABLE users ADD COLUMN last_auto_analyse DATETIME"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN msg_count_since_analyse INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN total_xp INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN current_level INTEGER DEFAULT 1"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE messages ADD COLUMN model_used TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE messages ADD COLUMN tokens_used INTEGER"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE messages ADD COLUMN api_key_slot INTEGER"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE messages ADD COLUMN agent_used TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE messages ADD COLUMN critic_score REAL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE messages ADD COLUMN was_regenerated INTEGER DEFAULT 0"); } catch (Exception $e) {}
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_messages_user_session ON messages(user_id, session_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_feedback_user ON feedback_log(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_achievements_user ON user_achievements(user_id)");
}

$db = getDB();
initAdvancedTables($db);

// ========== FONCTIONS CORE AVANCÉES ==========

/**
 * Appel Mistral avec retry automatique et fallback entre clés API
 */
function callMistral($messages, $key_slot, $model, $max_tokens, $temperature = 0.7, $timeout = API_TIMEOUT) {
    $api_key = API_KEYS[$key_slot] ?? null;
    if (!$api_key) return null;
    
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $max_tokens,
        'temperature' => $temperature,
        'top_p' => 0.95,
    ];
    
    $ch = curl_init(MISTRAL_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);
    
    if ($curl_err || !$response) return null;
    
    $data = json_decode($response, true);
    
    // Retry avec clé suivante en cas de rate-limit
    if ($http_code === 429 && $key_slot < 3) {
        return callMistral($messages, $key_slot + 1, $model, $max_tokens, $temperature, $timeout);
    }
    
    return $data;
}

/**
 * Temperature adaptative selon le contexte et l'humeur utilisateur
 */
function getAdaptiveTemperature($message, $user_state, $agent_type = 'general') {
    $base_temps = [
        'philosopher' => 0.8,
        'coach' => 0.6,
        'analyst' => 0.4,
        'merchant' => 0.5,
        'therapist' => 0.7,
        'general' => 0.7
    ];
    
    $temp = $base_temps[$agent_type] ?? 0.7;
    
    // Ajustement selon le type de question
    if (preg_match('/imagine|créatif|poème|histoire|fiction/i', $message)) $temp += 0.15;
    if (preg_match('/prix|fait|chiffre|donnée|technique|calcul/i', $message)) $temp -= 0.25;
    if (preg_match('/urgence|vite|maintenant|immédiat/i', $message)) $temp -= 0.1;
    
    // Ajustement selon KPIs
    if (isset($user_state['kpi_bonheur']) && $user_state['kpi_bonheur'] < 40) $temp -= 0.15; // Plus empathique
    if (isset($user_state['kpi_confiance']) && $user_state['kpi_confiance'] > 80) $temp += 0.1; // Plus audacieux
    
    return max(0.2, min(0.95, $temp));
}

/**
 * Routeur intelligent vers l'agent spécialisé optimal
 */
function routeToAgent($message, $user_context, $db) {
    global $AGENT_CONFIGS;
    
    $scores = [];
    $message_lower = strtolower($message);
    
    // Score chaque agent selon les triggers
    foreach ($AGENT_CONFIGS as $name => $config) {
        $score = 0;
        foreach ($config['triggers'] as $trigger) {
            if (stripos($message_lower, $trigger) !== false) {
                $score += 2;
            }
        }
        
        // Bonus selon KPIs
        foreach ($config['priority_boost'] as $kpi => $params) {
            if (isset($user_context[$kpi]) && $user_context[$kpi] < $params['threshold']) {
                $score += $params['boost'];
            }
        }
        
        // Bonus historique (agent déjà utilisé avec succès)
        $perf = $db->querySingle("SELECT avg_satisfaction FROM agent_performance WHERE agent_name = '$name'", true);
        if ($perf && $perf['avg_satisfaction'] > 7.5) $score += 1;
        
        $scores[$name] = $score;
    }
    
    // Vérifier agents dynamiques
    $dynamic_agents = $db->query("SELECT name, triggers, temperature FROM dynamic_agents WHERE active = 1");
    while ($da = $dynamic_agents->fetchArray(SQLITE3_ASSOC)) {
        $triggers = json_decode($da['triggers'], true) ?? [];
        $score = 0;
        foreach ($triggers as $trigger) {
            if (stripos($message_lower, $trigger) !== false) $score += 2;
        }
        if ($score > 0) {
            $scores[$da['name']] = $score;
            $AGENT_CONFIGS[$da['name']] = [
                'model' => 'mistral-medium-2505',
                'key_slot' => 1,
                'temperature_base' => $da['temperature'],
                'system_prompt' => $db->querySingle("SELECT system_prompt FROM dynamic_agents WHERE name = '{$da['name']}'")
            ];
        }
    }
    
    arsort($scores);
    $best_agent = key($scores);
    
    // Si score trop bas → agent généraliste
    if (!isset($scores[$best_agent]) || $scores[$best_agent] < 2) {
        $best_agent = 'philosopher';
    }
    
    return $best_agent;
}

/**
 * Build du system prompt avec Chain-of-Thought et Few-Shot examples
 */
function buildAdvancedSystemPrompt($db, $user_id, $kpis, $memory, $refinements, $agent_type, $conversation_context) {
    $user = $db->querySingle("SELECT * FROM users WHERE id = $user_id", true);
    
    // Prompt de base selon l'agent
    $base_prompts = [
        'philosopher' => "Tu es VOANH PHILOSOPHER, conseillère d'élite du Groupe Vo Anh spécialisée dans la réflexion profonde. Tu incarnes une voix d'une érudition rare, dans la tradition des grands humanistes européens.",
        'coach' => "Tu es VOANH COACH, mentor d'action du Groupe Vo Anh. Ton rôle est de transformer la réflexion en actes concrets. Tu es direct, motivant, orienté résultats.",
        'analyst' => "Tu es VOANH ANALYST, expert en décryptage systémique. Tu décomposes les problèmes complexes en éléments clairs et actionnables.",
        'merchant' => "Tu es VOANH MERCHANT, conseiller commercial d'excellence du Groupe Vo Anh. Tu guides vers les meilleures opportunités avec intégrité et précision.",
        'therapist' => "Tu es VOANH THERAPIST, accompagnante psycho-émotionnelle du Groupe Vo Anh. Tu écoutes avec empathie profonde et guides vers l'apaisement."
    ];
    
    $base = $base_prompts[$agent_type] ?? $base_prompts['philosopher'];
    
    // Mémoire formatée
    $memory_str = "";
    foreach ($memory as $item) {
        $memory_str .= "- {$item['key']}: {$item['value']}\n";
    }
    if (empty($memory_str)) $memory_str = "Aucune information précédente enregistrée.";
    
    // Préférences utilisateur
    $refinements_str = "";
    foreach ($refinements as $key => $value) {
        $refinements_str .= "- $key : $value\n";
    }
    
    // Construction du prompt complet avec CoT
    $prompt = "$base

[CONTEXTE UTILISATEUR]
KPIs actuels: " . json_encode($kpis) . "
Mémoire pertinente: 
$memory_str
Préférences actives:
" . (empty($refinements_str) ? "Aucune" : $refinements_str) . "

[MÉTHODE DE RÉPONSE OBLIGATOIRE - CHAIN OF THOUGHT]
Avant de répondre, tu DOIS suivre cette réflexion interne (ne jamais afficher cette partie):
1. Quel est le VRAI besoin derrière la question de l'utilisateur?
2. Quels KPIs sont impactés positivement ou négativement?
3. Quelle référence culturelle/historique/philosophique éclairerait ce sujet?
4. Quelle objection pourrait avoir l'utilisateur? Comment la anticiper?
5. Quelle action concrète et immédiate proposer?

[FORMAT DE RÉPONSE EXIGÉ]
- Commence par une phrase d'ouverture qui replace la question dans une perspective vaste
- Développe en trois mouvements: (1) diagnostic profond, (2) analyse enrichie d'exemples, (3) proposition d'action
- Termine par une question ouverte provocante ou une invitation à l'action
- Minimum 35 phrases, prose continue, jamais de listes à puces
- Vocabulaire précis, métaphores originales, ponctuation riche

[ENGAGEMENTS]
- Tu combats la bêtise, le fascisme, le racisme, la haine marchande
- Tu défends l'émancipation individuelle et collective
- Tu ne proposes de produits que si pertinents et demandés
- Tu ne parles jamais de ton fonctionnement technique

Termine souvent par: \"Et maintenant, quelle sera votre première action d'élite?\"";

    return $prompt;
}

/**
 * Compression intelligente de l'historique de conversation
 */
function compressConversationHistory($db, $messages, $max_keep = 5) {
    if (count($messages) <= $max_keep) return $messages;
    
    $recent = array_slice($messages, -$max_keep);
    $old = array_slice($messages, 0, -$max_keep);
    
    // Résumer les anciens messages
    $summary_parts = [];
    foreach ($old as $msg) {
        $role = $msg['role'] === 'user' ? 'Utilisateur' : 'IA';
        $preview = substr($msg['content'], 0, 80);
        $summary_parts[] = "[$role: $preview...]";
    }
    
    $summary_msg = [
        'role' => 'system',
        'content' => "RÉSUMÉ DES ÉCHANGES PRÉCÉDENTS: " . implode(" | ", $summary_parts)
    ];
    
    return array_merge([$summary_msg], $recent);
}

/**
 * Agent critique pour valider/améliorer les réponses
 */
function validateResponseWithCritic($question, $response, $user_context) {
    $critic_prompt = "Tu es VOANH CRITIC, validateur exigeant de réponses IA.

Question utilisateur: $question
Réponse proposée: $response

Évalue sur 5 critères (note 1-10 pour chacun):
1. PERTINENCE: Répond-il vraiment au besoin profond?
2. PROFONDEUR: Assez érudit ou trop superficiel?
3. ACTIONNABILITÉ: Y a-t-il une action concrète proposée?
4. EMPATHIE: Ton adapté à l'état émotionnel utilisateur?
5. ORIGINALITÉ: Évite les clichés et lieux communs?

Calcule la moyenne. Si moyenne < 7, propose 3 améliorations spécifiques.

Réponds UNIQUEMENT en JSON:
{\"scores\": {\"pertinence\": X, \"profondeur\": X, \"action\": X, \"empathie\": X, \"originalite\": X}, \"average\": X.X, \"valid\": bool, \"improvements\": [\"...\", \"...\", \"...\"]}";

    $validation = callMistral([
        ['role' => 'system', 'content' => $critic_prompt],
        ['role' => 'user', 'content' => 'Valide cette réponse']
    ], 3, MODEL_CRITIC, 600, 0.3, 60);
    
    if (!$validation || !isset($validation['choices'][0]['message']['content'])) {
        return ['average' => 8.0, 'valid' => true, 'improvements' => []];
    }
    
    $content = $validation['choices'][0]['message']['content'];
    $json_start = strpos($content, '{');
    $json_end = strrpos($content, '}');
    
    if ($json_start === false || $json_end === false) {
        return ['average' => 8.0, 'valid' => true, 'improvements' => []];
    }
    
    $json_str = substr($content, $json_start, $json_end - $json_start + 1);
    $result = json_decode($json_str, true);
    
    return $result ?? ['average' => 8.0, 'valid' => true, 'improvements' => []];
}

/**
 * Génération de suggestions et branches de conversation
 */
function generateBranchingOptions($db, $user_id, $conversation_history, $last_response) {
    $context = implode("\n", array_slice($conversation_history, -8));
    
    $prompt = "À partir de cette conversation, génère 3 directions possibles pour continuer:

Conversation récente:
$context

Dernière réponse IA:
$last_response

Pour CHAQUE direction, fournis:
- Un label court (3-5 mots)
- Un prompt de relance précis

Réponds UNIQUEMENT en JSON:
{\"branches\": [{\"label\": \"...\", \"prompt\": \"...\"}, {\"label\": \"...\", \"prompt\": \"...\"}, {\"label\": \"...\", \"prompt\": \"...\"}]}";

    $response = callMistral([
        ['role' => 'system', 'content' => 'Tu es un générateur de branches conversationnelles.'],
        ['role' => 'user', 'content' => $prompt]
    ], 2, MODEL_SUMMARIZER, 500, 0.7, 45);
    
    if (!$response || !isset($response['choices'][0]['message']['content'])) {
        return [
            'branches' => [
                ['label' => 'Approfondir', 'prompt' => 'Peux-tu développer ce point plus en détail?'],
                ['label' => 'Exemple concret', 'prompt' => 'Donne-moi un exemple pratique d\'application'],
                ['label' => 'Action immédiate', 'prompt' => 'Que puis-je faire dès maintenant?']
            ]
        ];
    }
    
    $content = $response['choices'][0]['message']['content'];
    $json_start = strpos($content, '{');
    $json_end = strrpos($content, '}');
    
    if ($json_start === false || $json_end === false) {
        return ['branches' => []];
    }
    
    $json_str = substr($content, $json_start, $json_end - $json_start + 1);
    return json_decode($json_str, true) ?? ['branches' => []];
}

/**
 * Tracking du feedback implicite pour apprentissage par renforcement
 */
function trackImplicitFeedback($db, $message_id, $user_id, $interaction_type, $agent_used = '') {
    $rewards = [
        'copy_message' => 0.8,
        'long_read_time' => 0.6,
        'follow_up_question' => 0.5,
        'click_suggestion' => 0.7,
        'click_branch' => 0.6,
        'negative_feedback' => -1.0,
        'quick_exit' => -0.5,
        'regenerate_request' => -0.3
    ];
    
    $reward = $rewards[$interaction_type] ?? 0;
    
    $stmt = $db->prepare("INSERT INTO feedback_log (message_id, user_id, reward, interaction_type, agent_used) VALUES (?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $message_id);
    $stmt->bindValue(2, $user_id);
    $stmt->bindValue(3, $reward);
    $stmt->bindValue(4, $interaction_type);
    $stmt->bindValue(5, $agent_used);
    $stmt->execute();
    
    // Ajuster préférences de style
    adjustUserStylePreferences($db, $user_id, $interaction_type, $message_id);
    
    // Mettre à jour performance agent
    if (!empty($agent_used)) {
        updateAgentPerformance($db, $agent_used, $reward);
    }
}

function adjustUserStylePreferences($db, $user_id, $interaction, $message_id) {
    $last_msg = $db->querySingle("SELECT content FROM messages WHERE id = $message_id", true);
    if (!$last_msg) return;
    
    // Analyse simplifiée du style
    $content = $last_msg['content'];
    $style_features = [
        'length' => (strlen($content) > 2000) ? 'long' : ((strlen($content) > 1000) ? 'medium' : 'short'),
        'tone' => preg_match('/philosoph|sagesse|réflexion/i', $content) ? 'philosophical' : (preg_match('/action|objectif|plan/i', $content) ? 'action_oriented' : 'neutral'),
        'references' => preg_match('/cite|rappelle|comme|i.e./i', $content) ? 'with_references' : 'direct'
    ];
    
    $delta = ($interaction === 'positive' || in_array($interaction, ['copy_message', 'long_read_time', 'click_suggestion'])) ? 0.2 : -0.1;
    
    foreach ($style_features as $feature => $value) {
        $db->exec("INSERT INTO user_refinements (user_id, refinement_key, refinement_value) 
                   VALUES ($user_id, 'style_$feature', '$value')
                   ON CONFLICT DO NOTHING");
    }
}

function updateAgentPerformance($db, $agent_name, $reward) {
    $existing = $db->querySingle("SELECT id, total_conversations, avg_satisfaction FROM agent_performance WHERE agent_name = '$agent_name'", true);
    
    if ($existing) {
        $new_count = $existing['total_conversations'] + 1;
        $new_avg = (($existing['avg_satisfaction'] * $existing['total_conversations']) + ($reward * 10)) / $new_count;
        
        $db->exec("UPDATE agent_performance 
                   SET total_conversations = $new_count, 
                       avg_satisfaction = $new_avg,
                       last_optimized = CURRENT_TIMESTAMP
                   WHERE agent_name = '$agent_name'");
    } else {
        $db->exec("INSERT INTO agent_performance (agent_name, total_conversations, avg_satisfaction) 
                   VALUES ('$agent_name', 1, " . ($reward * 10) . ")");
    }
}

/**
 * Extraction et enrichissement de la knowledge base
 */
function extractKnowledgeFromConversation($db, $conversation) {
    $extraction_prompt = "Extrais de cette conversation:
1. Faits généraux applicables à tous les utilisateurs
2. Citations/métaphores mémorables
3. Frameworks mentaux réutilisables
4. Actions qui ont fonctionné

Réponds en JSON:
{\"knowledge_items\": [{\"title\": \"...\", \"content\": \"...\", \"tags\": [\"...\"], \"category\": \"...\"}]}";

    $result = callMistral([
        ['role' => 'system', 'content' => 'Tu es un extracteur de connaissances.'],
        ['role' => 'user', 'content' => $extraction_prompt . "\n\nConversation:\n$conversation"]
    ], 2, MODEL_SUMMARIZER, 800, 0.5, 50);
    
    if (!$result) return;
    
    $content = $result['choices'][0]['message']['content'];
    $json_start = strpos($content, '{');
    $json_end = strrpos($content, '}');
    
    if ($json_start === false) return;
    
    $json_str = substr($content, $json_start, $json_end - $json_start + 1);
    $knowledge = json_decode($json_str, true);
    
    if (!isset($knowledge['knowledge_items'])) return;
    
    foreach ($knowledge['knowledge_items'] as $item) {
        $stmt = $db->prepare("INSERT INTO knowledge_base (title, content, tags, category) VALUES (?, ?, ?, ?)");
        $stmt->bindValue(1, $item['title'] ?? 'Insight');
        $stmt->bindValue(2, $item['content'] ?? '');
        $stmt->bindValue(3, json_encode($item['tags'] ?? []));
        $stmt->bindValue(4, $item['category'] ?? 'general');
        $stmt->execute();
    }
}

/**
 * Calcul du niveau utilisateur et quêtes
 */
function getUserLevel($db, $user_id) {
    $total_xp = $db->querySingle("SELECT COALESCE(SUM(xp_earned), 0) FROM user_achievements WHERE user_id = $user_id");
    $total_xp = max($total_xp, $db->querySingle("SELECT COALESCE(total_xp, 0) FROM users WHERE id = $user_id"));
    
    $level = floor(sqrt($total_xp / 100)) + 1;
    $next_level_xp = pow($level, 2) * 100;
    $current_level_min_xp = pow($level - 1, 2) * 100;
    $progress = $total_xp - $current_level_min_xp;
    $needed = $next_level_xp - $current_level_min_xp;
    $progress_percent = $needed > 0 ? ($progress / $needed) * 100 : 100;
    
    $titles = ['Novice', 'Explorateur', 'Apprenti', 'Compagnon', 'Expert', 'Maître', 'Sage', 'Visionnaire', 'Architecte', 'Luminaire'];
    $title = $titles[min($level - 1, count($titles) - 1)];
    
    return [
        'level' => $level,
        'total_xp' => $total_xp,
        'next_level_xp' => $next_level_xp,
        'progress_percent' => round($progress_percent, 1),
        'title' => $title
    ];
}

function assignDailyQuests($db, $user_id) {
    $today = date('Y-m-d');
    $existing = $db->querySingle("SELECT COUNT(*) FROM daily_quests WHERE user_id = $user_id AND date_assigned = '$today'");
    
    if ($existing >= 3) return;
    
    $quest_types = [
        ['type' => 'deep_conversation', 'target' => 10, 'xp' => 100, 'kpi' => ['confiance' => 5, 'influence' => 3]],
        ['type' => 'action_taken', 'target' => 1, 'xp' => 150, 'kpi' => ['bonheur' => 8, 'travail' => 5]],
        ['type' => 'new_topic', 'target' => 3, 'xp' => 75, 'kpi' => ['karma' => 4, 'amour' => 3]],
        ['type' => 'feedback_given', 'target' => 2, 'xp' => 50, 'kpi' => ['influence' => 5]]
    ];
    
    shuffle($quest_types);
    
    foreach (array_slice($quest_types, 0, 3 - $existing) as $quest) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO daily_quests (user_id, quest_type, target, reward_xp, reward_kpi_json, date_assigned) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $quest['type']);
        $stmt->bindValue(3, $quest['target']);
        $stmt->bindValue(4, $quest['xp']);
        $stmt->bindValue(5, json_encode($quest['kpi']));
        $stmt->bindValue(6, $today);
        $stmt->execute();
    }
}

// ========== GESTION DES ACTIONS ==========

// Action principale: CHAT
if ($action === 'chat') {
    $user_msg = trim($input['message'] ?? '');
    if (empty($user_msg)) {
        echo json_encode(['error' => 'Message vide']);
        exit;
    }
    
    // Sauvegarder refinements si fournis
    if (isset($input['refinements']) && is_array($input['refinements'])) {
        foreach ($input['refinements'] as $key => $value) {
            $stmt = $db->prepare("INSERT INTO user_refinements (user_id, refinement_key, refinement_value) VALUES (?, ?, ?)");
            $stmt->bindValue(1, $user_id);
            $stmt->bindValue(2, $key);
            $stmt->bindValue(3, $value);
            $stmt->execute();
        }
    }
    
    // Récupérer état utilisateur
    $user = $db->querySingle("SELECT * FROM users WHERE id = $user_id", true);
    $kpis = [
        'bonheur' => round($user['kpi_bonheur'] ?? 70, 1),
        'sante' => round($user['kpi_sante'] ?? 70, 1),
        'finance' => round($user['kpi_finance'] ?? 70, 1),
        'karma' => round($user['kpi_karma'] ?? 70, 1),
        'amour' => round($user['kpi_amour'] ?? 70, 1),
        'travail' => round($user['kpi_travail'] ?? 70, 1),
        'confiance' => round($user['kpi_confiance'] ?? 70, 1),
        'influence' => round($user['kpi_influence'] ?? 70, 1),
    ];
    
    // Router vers agent spécialisé
    $selected_agent = routeToAgent($user_msg, $kpis, $db);
    $agent_config = $AGENT_CONFIGS[$selected_agent] ?? $AGENT_CONFIGS['philosopher'];
    
    // Récupérer mémoire et préférences
    $memory = $db->querySingle("SELECT key, value FROM user_memory WHERE user_id = $user_id AND confidence > 30 ORDER BY confidence DESC, updated_at DESC LIMIT " . MEMORY_LIMIT, true);
    $memory_items = [];
    if ($memory) {
        $result = $db->query("SELECT key, value FROM user_memory WHERE user_id = $user_id AND confidence > 30 ORDER BY confidence DESC, updated_at DESC LIMIT " . MEMORY_LIMIT);
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) $memory_items[] = $row;
    }
    
    $refinements = [];
    $ref_result = $db->query("SELECT refinement_key, refinement_value FROM user_refinements WHERE user_id = $user_id ORDER BY created_at DESC");
    while ($row = $ref_result->fetchArray(SQLITE3_ASSOC)) {
        $refinements[$row['refinement_key']] = $row['refinement_value'];
    }
    
    // Historique compressé
    $history_result = $db->query("SELECT role, content FROM messages WHERE user_id = $user_id AND session_id = '$session_id' ORDER BY created_at DESC LIMIT " . MAX_HISTORY_MESSAGES);
    $history = [];
    while ($row = $history_result->fetchArray(SQLITE3_ASSOC)) $history[] = $row;
    $history = array_reverse($history);
    $compressed_history = compressConversationHistory($db, $history, 6);
    
    // Build system prompt avancé
    $system_prompt = buildAdvancedSystemPrompt($db, $user_id, $kpis, $memory_items, $refinements, $selected_agent, $history);
    
    // Préparer messages API
    $api_messages = [['role' => 'system', 'content' => $system_prompt]];
    foreach ($compressed_history as $h) {
        $api_messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $api_messages[] = ['role' => 'user', 'content' => $user_msg];
    
    // Temperature adaptative
    $temperature = getAdaptiveTemperature($user_msg, $kpis, $selected_agent);
    
    // Sauvegarder message utilisateur
    $stmt = $db->prepare("INSERT INTO messages (user_id, session_id, role, sender_type, content, api_key_slot, agent_used) VALUES (?, ?, 'user', 'user', ?, ?, ?)");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $session_id);
    $stmt->bindValue(3, $user_msg);
    $stmt->bindValue(4, $agent_config['key_slot']);
    $stmt->bindValue(5, $selected_agent);
    $stmt->execute();
    $user_msg_id = $db->lastInsertRowID();
    
    // Appel IA principal
    $response = callMistral($api_messages, $agent_config['key_slot'], $agent_config['model'], 1400, $temperature, API_TIMEOUT);
    
    if (!$response || !isset($response['choices'][0]['message']['content'])) {
        echo json_encode(['error' => 'Reconnexion galactique en cours...', 'raw' => $response]);
        exit;
    }
    
    $ai_response = $response['choices'][0]['message']['content'];
    $tokens_used = $response['usage']['total_tokens'] ?? 0;
    
    // Validation par agent critique (optionnel, toutes les 3 réponses)
    $msg_count = $db->querySingle("SELECT COUNT(*) FROM messages WHERE user_id = $user_id AND session_id = '$session_id'");
    $critic_score = null;
    $was_regenerated = 0;
    
    if ($msg_count % 3 === 0 && strlen($ai_response) > 200) {
        $validation = validateResponseWithCritic($user_msg, $ai_response, $kpis);
        $critic_score = $validation['average'] ?? 8.0;
        
        if (isset($validation['valid']) && !$validation['valid'] && !empty($validation['improvements'])) {
            // Régénérer avec feedback
            $improvement_prompt = "Améliore ta réponse précédente en considérant ces points: " . implode(', ', $validation['improvements']);
            $api_messages[] = ['role' => 'assistant', 'content' => $ai_response];
            $api_messages[] = ['role' => 'user', 'content' => $improvement_prompt];
            
            $retry_response = callMistral($api_messages, $agent_config['key_slot'], $agent_config['model'], 1400, $temperature, API_TIMEOUT);
            if ($retry_response && isset($retry_response['choices'][0]['message']['content'])) {
                $ai_response = $retry_response['choices'][0]['message']['content'];
                $was_regenerated = 1;
                $tokens_used += $retry_response['usage']['total_tokens'] ?? 0;
            }
        }
        
        // Track feedback pour RL
        trackImplicitFeedback($db, $user_msg_id, $user_id, 'critic_evaluated', $selected_agent);
    }
    
    // Sauvegarder réponse IA
    $stmt = $db->prepare("INSERT INTO messages (user_id, session_id, role, sender_type, content, model_used, tokens_used, api_key_slot, agent_used, critic_score, was_regenerated) VALUES (?, ?, 'assistant', 'clone', ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $session_id);
    $stmt->bindValue(3, $ai_response);
    $stmt->bindValue(4, $agent_config['model']);
    $stmt->bindValue(5, $tokens_used);
    $stmt->bindValue(6, $agent_config['key_slot']);
    $stmt->bindValue(7, $selected_agent);
    $stmt->bindValue(8, $critic_score);
    $stmt->bindValue(9, $was_regenerated);
    $stmt->execute();
    $ai_msg_id = $db->lastInsertRowID();
    
    // Générer options de branches conversationnelles
    $conv_for_branches = [];
    foreach (array_slice($history, -6) as $h) {
        $conv_for_branches[] = $h['role'] . ': ' . substr($h['content'], 0, 150);
    }
    $conv_for_branches[] = 'user: ' . $user_msg;
    $conv_for_branches[] = 'assistant: ' . substr($ai_response, 0, 200);
    
    $branchesData = generateBranchingOptions($db, $user_id, $conv_for_branches, $ai_response);
    
    // Générer suggestions rapides
    $suggestions_prompt = "Génère 3 suggestions d'actions immédiates (moins de 8 mots chacune) basées sur cette conversation:
Dernier échange:
User: $user_msg
IA: " . substr($ai_response, 0, 300) . "

Réponds en JSON: {\"suggestions\": [\"...\", \"...\", \"...\"]}";

    $sugg_response = callMistral([
        ['role' => 'system', 'content' => 'Générateur de suggestions concises.'],
        ['role' => 'user', 'content' => $suggestions_prompt]
    ], 2, MODEL_SUMMARIZER, 300, 0.7, 30);
    
    $suggestions = ["Approfondir ce point", "Demander un exemple", "Proposer une action"];
    if ($sugg_response && isset($sugg_response['choices'][0]['message']['content'])) {
        $content = $sugg_response['choices'][0]['message']['content'];
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');
        if ($json_start !== false && $json_end !== false) {
            $json_str = substr($content, $json_start, $json_end - $json_start + 1);
            $sugg_data = json_decode($json_str, true);
            if (isset($sugg_data['suggestions'])) $suggestions = $sugg_data['suggestions'];
        }
    }
    
    // Mettre à jour compteurs
    $db->exec("UPDATE users SET msg_count_since_analyse = COALESCE(msg_count_since_analyse, 0) + 1 WHERE id = $user_id");
    
    // Assigner quêtes quotidiennes
    assignDailyQuests($db, $user_id);
    
    // Extraire connaissance occasionnellement
    if (rand(1, 10) === 1) {
        extractKnowledgeFromConversation($db, implode("\n", array_slice($conv_for_branches, -10)));
    }
    
    // Retour JSON
    echo json_encode([
        'success' => true,
        'message' => $ai_response,
        'kpis' => $kpis,
        'tokens' => $tokens_used,
        'session_id' => $session_id,
        'agent_used' => $selected_agent,
        'critic_score' => $critic_score,
        'was_regenerated' => $was_regenerated,
        'suggestions' => $suggestions,
        'branches' => $branchesData['branches'] ?? [],
        'level_info' => getUserLevel($db, $user_id)
    ]);
    exit;
}

// Action: TRACK FEEDBACK (pour apprentissage par renforcement)
if ($action === 'track_feedback') {
    $message_id = intval($input['message_id'] ?? 0);
    $interaction_type = $input['type'] ?? '';
    $agent_used = $input['agent'] ?? '';
    
    if (!$message_id || empty($interaction_type)) {
        echo json_encode(['error' => 'Paramètres requis manquants']);
        exit;
    }
    
    trackImplicitFeedback($db, $message_id, $user_id, $interaction_type, $agent_used);
    
    // Mettre à jour progression quête
    $quest_updates = [
        'copy_message' => ['deep_conversation' => 1],
        'long_read_time' => ['deep_conversation' => 1],
        'click_suggestion' => ['action_taken' => 1],
        'click_branch' => ['new_topic' => 1]
    ];
    
    if (isset($quest_updates[$interaction_type])) {
        foreach ($quest_updates[$interaction_type] as $quest_type => $progress) {
            $db->exec("UPDATE daily_quests SET progress = progress + $progress WHERE user_id = $user_id AND quest_type = '$quest_type' AND date_assigned = CURRENT_DATE AND completed = 0");
        }
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// Action: ANALYSE COMPLÈTE (synchrone)
if ($action === 'analyze') {
    set_time_limit(180);
    while (ob_get_level()) ob_end_clean();
    ob_start();
    
    $history_result = $db->query("SELECT role, content FROM messages WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 25");
    $msgs = [];
    while ($row = $history_result->fetchArray(SQLITE3_ASSOC)) {
        $msgs[] = ($row['role'] === 'user' ? 'USER: ' : 'IA: ') . $row['content'];
    }
    $chat_history = implode("\n", array_reverse($msgs));
    
    $analyze_prompt = "Tu es VOANH ANALYZER. Analyse cette conversation et produis un rapport JSON STRICT.
HISTORIQUE:
$chat_history

Réponds UNIQUEMENT en JSON (commence par { et fini par }):
{
  \"besoins_detectes\": [\"...\"],
  \"opga\": [{\"type\": \"achat|vente|location\", \"objet\": \"...\", \"budget_estime\": \"...\"}],
  \"etat_psycho\": \"...\",
  \"kpi_ajustements\": {\"bonheur\": 0, \"sante\": 0, \"finance\": 0, \"karma\": 0, \"amour\":0, \"travail\":0, \"confiance\":0, \"influence\":0},
  \"actions_recommandees\": [\"...\"],
  \"produits_boutique_pertinents\": [\"...\"],
  \"intention_principale\": \"...\"
}";
    
    $response = callMistral([
        ['role' => 'system', 'content' => $analyze_prompt],
        ['role' => 'user', 'content' => 'Lance analyse complète.']
    ], 2, MODEL_CHAT_ANALYST, 1600, 0.5, 140);
    
    if (!$response || !isset($response['choices'][0]['message']['content'])) {
        ob_clean();
        echo json_encode(['error' => 'Échec de l\'analyse : l\'IA n\'a pas répondu dans les temps.']);
        exit;
    }
    
    $analysis_text = $response['choices'][0]['message']['content'];
    $analysis_text = preg_replace('/^[^{]*/', '', $analysis_text);
    $analysis_text = preg_replace('/}[^}]*$/', '}', $analysis_text);
    
    $analysis = json_decode($analysis_text, true);
    
    if (!$analysis) {
        ob_clean();
        echo json_encode(['error' => 'Analyse JSON invalide', 'raw' => substr($analysis_text, 0, 500)]);
        exit;
    }
    
    // Appliquer ajustements KPI
    if (isset($analysis['kpi_ajustements'])) {
        foreach ($analysis['kpi_ajustements'] as $kpi => $delta) {
            if (is_numeric($delta) && $delta != 0) {
                $col = "kpi_$kpi";
                try {
                    $db->exec("UPDATE users SET $col = MIN(100, MAX(0, $col + ($delta))) WHERE id = $user_id");
                } catch (Exception $e) {}
            }
        }
    }
    
    // Enregistrer en mémoire
    if (!empty($analysis['besoins_detectes'])) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO user_memory (user_id, key, value, confidence, updated_at) VALUES (?, 'dernier_besoin', ?, 80, CURRENT_TIMESTAMP)");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, implode(', ', $analysis['besoins_detectes']));
        $stmt->execute();
    }
    
    if (!empty($analysis['intention_principale'])) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO user_memory (user_id, key, value, confidence, updated_at) VALUES (?, 'intention_principale', ?, 75, CURRENT_TIMESTAMP)");
        $stmt->bindValue(1, $user_id);
        $stmt->bindValue(2, $analysis['intention_principale']);
        $stmt->execute();
    }
    
    // Créer OPGA auto-détectées
    if (!empty($analysis['opga'])) {
        foreach ($analysis['opga'] as $opga) {
            $stmt = $db->prepare("INSERT INTO opga (user_id, type, titre, description, statut) VALUES (?, ?, ?, ?, 'auto-detecte')");
            $stmt->bindValue(1, $user_id);
            $stmt->bindValue(2, $opga['type'] ?? 'achat');
            $stmt->bindValue(3, $opga['objet'] ?? 'OPGA auto');
            $stmt->bindValue(4, 'Budget: ' . ($opga['budget_estime'] ?? '?'));
            $stmt->execute();
        }
    }
    
    $db->exec("UPDATE users SET msg_count_since_analyse = 0, last_auto_analyse = CURRENT_TIMESTAMP WHERE id = $user_id");
    
    ob_clean();
    echo json_encode(['success' => true, 'analysis' => $analysis]);
    exit;
}

// Action: ADMIN ANALYZE
if ($action === 'admin_analyze') {
    $admin = $db->querySingle("SELECT role FROM users WHERE id = $user_id", true);
    if (!$admin || $admin['role'] !== 'admin') {
        echo json_encode(['error' => 'Accès refusé']);
        exit;
    }
    
    $target_user_id = intval($input['target_user_id'] ?? 0);
    if (!$target_user_id) {
        echo json_encode(['error' => 'ID utilisateur requis']);
        exit;
    }
    
    $target = $db->querySingle("SELECT * FROM users WHERE id = $target_user_id", true);
    $msgs_result = $db->query("SELECT role, content, created_at FROM messages WHERE user_id = $target_user_id ORDER BY created_at DESC LIMIT 50");
    $msgs = [];
    while ($row = $msgs_result->fetchArray(SQLITE3_ASSOC)) {
        $msgs[] = "[{$row['created_at']}] {$row['role']}: {$row['content']}";
    }
    $history = implode("\n", array_reverse($msgs));
    
    $admin_prompt = "Tu es VOANH ADMIN INTELLIGENCE - audit utilisateur.
PROFIL: Email: {$target['email']}, Pseudo: {$target['pseudo']}, Certifié: {$target['certified']}
KPIs: Bonheur={$target['kpi_bonheur']}%, Santé={$target['kpi_sante']}%, Finance={$target['kpi_finance']}%
CONVERSATIONS:
$history

Analyse complète pour le Grand Monarque.";
    
    $response = callMistral([['role' => 'system', 'content' => $admin_prompt], ['role' => 'user', 'content' => 'Rapport']], 3, MODEL_ADMIN, 1600, 0.6, API_TIMEOUT);
    $report = $response['choices'][0]['message']['content'] ?? 'Erreur de génération';
    
    $db->exec("INSERT INTO admin_log (action, details) VALUES ('admin_ai_analyze', 'Analyse IA user_id=$target_user_id')");
    
    echo json_encode(['success' => true, 'report' => $report, 'user' => ['email' => $target['email'], 'pseudo' => $target['pseudo']]]);
    exit;
}

echo json_encode(['error' => 'Action inconnue']);
