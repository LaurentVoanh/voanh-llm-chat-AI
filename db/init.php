<?php
// init.php - Initialisation de la base de données SQLite Elvita

function getDB() {
    $db_path = __DIR__ . '/elvita.db';
    $db = new SQLite3($db_path);
    $db->enableExceptions(true);
    
    // Activer WAL pour la performance
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA foreign_keys=ON;');
    
    // ---- TABLE UTILISATEURS ----
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        pseudo TEXT DEFAULT '',
        avatar TEXT DEFAULT '',
        certified INTEGER DEFAULT 0,
        sas_bypass INTEGER DEFAULT 0,
        kpi_bonheur REAL DEFAULT 50.0,
        kpi_sante REAL DEFAULT 50.0,
        kpi_finance REAL DEFAULT 50.0,
        kpi_karma REAL DEFAULT 50.0,
        kpi_amour REAL DEFAULT 50.0,
        kpi_travail REAL DEFAULT 50.0,
        kpi_confiance REAL DEFAULT 50.0,
        kpi_influence REAL DEFAULT 50.0,
        profile_json TEXT DEFAULT '{}',
        role TEXT DEFAULT 'user',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // ---- TABLE MESSAGES CHAT ----
    $db->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        session_id TEXT NOT NULL,
        role TEXT NOT NULL,
        sender_type TEXT DEFAULT 'user',
        content TEXT NOT NULL,
        model_used TEXT DEFAULT '',
        api_key_slot INTEGER DEFAULT 1,
        tokens_used INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )");
    
    // ---- TABLE SESSIONS ----
    $db->exec("CREATE TABLE IF NOT EXISTS sessions (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )");
    
    // ---- TABLE OPGA (Offres/Demandes d'Achat) ----
    $db->exec("CREATE TABLE IF NOT EXISTS opga (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        titre TEXT NOT NULL,
        description TEXT DEFAULT '',
        categorie TEXT DEFAULT '',
        prix_min REAL DEFAULT 0,
        prix_max REAL DEFAULT 0,
        devise TEXT DEFAULT 'EUR',
        statut TEXT DEFAULT 'actif',
        public INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id)
    )");
    
    // ---- TABLE BOUTIQUE ----
    $db->exec("CREATE TABLE IF NOT EXISTS boutique (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titre TEXT NOT NULL,
        description TEXT DEFAULT '',
        prix REAL DEFAULT 0,
        devise TEXT DEFAULT 'EUR',
        categorie TEXT DEFAULT '',
        image_url TEXT DEFAULT '',
        stock INTEGER DEFAULT -1,
        actif INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // ---- TABLE VISITES ----
    $db->exec("CREATE TABLE IF NOT EXISTS visites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT DEFAULT '',
        user_agent TEXT DEFAULT '',
        page TEXT DEFAULT '/',
        user_id INTEGER DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // ---- TABLE ADMIN LOG ----
    $db->exec("CREATE TABLE IF NOT EXISTS admin_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action TEXT NOT NULL,
        details TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Créer admin par défaut si absent
    $check = $db->querySingle("SELECT id FROM users WHERE role='admin' LIMIT 1");
    if (!$check) {
        $hash = password_hash('sylvain', PASSWORD_DEFAULT);
        $db->exec("INSERT INTO users (email, password, pseudo, role, certified) VALUES ('admin@elvita.net', '$hash', 'Grand Monarque', 'admin', 1)");
    }
    
    // Insérer quelques produits boutique par défaut
    $check2 = $db->querySingle("SELECT id FROM boutique LIMIT 1");
    if (!$check2) {
        $items = [
            ['Vidéo Dédicace Personnalisée', 'Message vidéo exclusif du Grand Monarque pour vous', 49.99, 'digital'],
            ['Formation Évolution Personnelle', 'Accès 30 jours au programme complet du Royaume', 99.99, 'formation'],
            ['Accès Club Football Sénégal', "Intégration au club de football du Royaume Elvita", 29.99, 'club'],
            ['Livre Numérique du Royaume', 'Les Enseignements du Grand Monarque - PDF + Audio', 19.99, 'digital'],
            ['Consultation Privée 1h', 'Session privée avec le Grand Monarque via chat sécurisé', 150.00, 'service'],
        ];
        foreach ($items as $item) {
            $stmt = $db->prepare("INSERT INTO boutique (titre, description, prix, categorie) VALUES (?, ?, ?, ?)");
            $stmt->bindValue(1, $item[0]);
            $stmt->bindValue(2, $item[1]);
            $stmt->bindValue(3, $item[2]);
            $stmt->bindValue(4, $item[3]);
            $stmt->execute();
        }
    }
    
    return $db;
}

// Enregistrer une visite
function logVisit($db, $page = '/', $user_id = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $db->prepare("INSERT INTO visites (ip, user_agent, page, user_id) VALUES (?, ?, ?, ?)");
    $stmt->bindValue(1, $ip);
    $stmt->bindValue(2, substr($ua, 0, 200));
    $stmt->bindValue(3, $page);
    $stmt->bindValue(4, $user_id);
    $stmt->execute();
}
