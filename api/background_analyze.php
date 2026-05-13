<?php
// api/background_analyze.php
// Arguments : job_id user_id

$job_id = $argv[1] ?? 0;
$user_id = $argv[2] ?? 0;
if (!$job_id || !$user_id) exit(1);

require_once __DIR__ . '/../db/init.php';
$db = getDB();

// Mettre à jour status = 'running'
$db->exec("UPDATE analyze_jobs SET status = 'running' WHERE id = $job_id");

// Inclure le fichier mistral.php pour réutiliser les fonctions (callMistral, updateKPIs, setUserMemory...)
require_once __DIR__ . '/mistral.php'; // attention : ce fichier contient du code qui s'exécute immédiatement (Session, etc.)
// Pour éviter les conflits, on ne peut pas inclure directement mistral.php car il exécute le code de routage.
// Mieux : copier les fonctions nécessaires dans ce script ou créer un fichier commun.

// Je vais plutôt recopier les fonctions minimales ici pour l'autonomie.
// Mais pour ne pas alourdir, voici une version compacte :

// Récupérer l'historique et lancer l'analyse Mistral
$history_result = $db->query("SELECT role, content FROM messages WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 30");
$msgs = [];
while ($row = $history_result->fetchArray(SQLITE3_ASSOC)) {
    $msgs[] = ($row['role'] === 'user' ? 'USER: ' : 'IA: ') . $row['content'];
}
$chat_history = implode("\n", array_reverse($msgs));

$analyze_prompt = "Tu es ELVITA ANALYZER. Analyse cette conversation et produis un rapport JSON STRICT.
HISTORIQUE:\n$chat_history
Réponds UNIQUEMENT en JSON :
{
  \"besoins_detectes\": [\"...\"],
  \"opga\": [{\"type\": \"achat|vente|location\", \"objet\": \"...\", \"budget_estime\": \"...\"}],
  \"etat_psycho\": \"...\",
  \"kpi_ajustements\": {\"bonheur\": 0, \"sante\": 0, \"finance\": 0, \"karma\": 0, \"amour\":0, \"travail\":0, \"confiance\":0, \"influence\":0},
  \"actions_recommandees\": [\"...\"],
  \"produits_boutique_pertinents\": [\"...\"],
  \"intention_principale\": \"...\"
}";

// Fonction callMistral (recopiée ici)
function callMistralBg($messages, $key_slot, $model, $max_tokens, $timeout) {
    $api_keys = [
        1 => '5qaRTgfdsRake',
        2 => 'o3rGgfdsXRShytu',
        3 => 'vEzQMgfdsuXkF',
    ];
    $api_key = $api_keys[$key_slot] ?? null;
    if (!$api_key) return null;
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $max_tokens,
        'temperature' => 0.75,
    ];
    $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
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
    curl_close($ch);
    if (!$response) return null;
    $data = json_decode($response, true);
    if ($http_code === 429 && $key_slot < 3) {
        return callMistralBg($messages, $key_slot + 1, $model, $max_tokens, $timeout);
    }
    return $data;
}

$response = callMistralBg([
    ['role' => 'system', 'content' => $analyze_prompt],
    ['role' => 'user', 'content' => 'Lance analyse complète.']
], 2, 'mistral-large-2411', 1500, 120);

if (!$response || !isset($response['choices'][0]['message']['content'])) {
    $error = 'Erreur API Mistral: timeout ou réponse invalide';
    $db->exec("UPDATE analyze_jobs SET status = 'failed', error = '$error', completed_at = CURRENT_TIMESTAMP WHERE id = $job_id");
    exit(1);
}

$analysis_text = $response['choices'][0]['message']['content'];
$json_start = strpos($analysis_text, '{');
$json_end = strrpos($analysis_text, '}');
if ($json_start === false || $json_end === false) {
    $error = 'Réponse JSON invalide';
    $db->exec("UPDATE analyze_jobs SET status = 'failed', error = '$error', completed_at = CURRENT_TIMESTAMP WHERE id = $job_id");
    exit(1);
}
$json_str = substr($analysis_text, $json_start, $json_end - $json_start + 1);
$analysis = json_decode($json_str, true);
if (!$analysis) {
    $error = 'Analyse JSON invalide';
    $db->exec("UPDATE analyze_jobs SET status = 'failed', error = '$error', completed_at = CURRENT_TIMESTAMP WHERE id = $job_id");
    exit(1);
}

// Mettre à jour les KPIs et la mémoire (reprendre les fonctions existantes)
// On appelle les fonctions via un include, mais pour éviter les conflits, on redéfinit rapidement
// Ou mieux : on exécute du SQL directement.
// Exemple de mise à jour KPIs :
if (isset($analysis['kpi_ajustements'])) {
    foreach ($analysis['kpi_ajustements'] as $kpi => $delta) {
        if (!is_numeric($delta) || $delta == 0) continue;
        $col = "kpi_$kpi";
        $db->exec("UPDATE users SET $col = MIN(100, MAX(0, $col + ($delta))) WHERE id = $user_id");
    }
}
if (!empty($analysis['besoins_detectes'])) {
    $value = implode(', ', $analysis['besoins_detectes']);
    $db->exec("DELETE FROM user_memory WHERE user_id = $user_id AND key = 'dernier_besoin'");
    $stmt = $db->prepare("INSERT INTO user_memory (user_id, key, value, confidence) VALUES (?, 'dernier_besoin', ?, 80)");
    $stmt->bindValue(1, $user_id);
    $stmt->bindValue(2, $value);
    $stmt->execute();
}
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

// Sauvegarder le résultat
$result_json = json_encode($analysis);
$db->exec("UPDATE analyze_jobs SET status = 'completed', result = '$result_json', completed_at = CURRENT_TIMESTAMP WHERE id = $job_id");

exit(0);