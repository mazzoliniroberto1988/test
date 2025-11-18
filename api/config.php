<?php
/*
 * File di Configurazione Principale
 * MODIFICATO: Reinserita la funzione debug_log()
 */

// --- FUNZIONE DI DEBUG ---
// Scrive un messaggio in un file di log
function debug_log($message) {
    $log_file = __DIR__ . '/debug_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "--- [$timestamp] ---\n" . $message . "\n\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}
// --- FINE FUNZIONE DI DEBUG ---

debug_log("Checkpoint: config.php - File caricato.");

// Carica l'autoloader di Composer per usare la libreria WebAuthn
require_once __DIR__ . '/vendor/autoload.php';

debug_log("Checkpoint: config.php - 'vendor/autoload.php' incluso.");

// --- IMPOSTAZIONI DATABASE ---
define('DB_HOST', 'localhost');
define('DB_USERNAME', 'HJM54s6MFW');
define('DB_PASSWORD', 'A3efe3c8N+CA4Hv');
define('DB_NAME', 'DzTimbratore');
define('DB_CHARSET', 'utf8mb4');

// --- IMPOSTAZIONI APP ---
define('APP_TIMEZONE', 'Europe/Rome');
date_default_timezone_set(APP_TIMEZONE);

/* Funzione di Connessione al Database (PDO) */
function connect_db() {
    debug_log("Checkpoint: config.php - Funzione connect_db() chiamata.");
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
        debug_log("Checkpoint: config.php - Connessione DB (PDO) riuscita.");
        return $pdo;
    } catch (PDOException $e) {
        debug_log("ERRORE FATALE (connect_db): " . $e->getMessage());
        error_log('Errore di connessione DB: ' . $e->getMessage());
        send_json_response(['success' => false, 'message' => 'Errore interno del server.'], 500);
        exit;
    }
}

/* Funzione Helper per risposte JSON */
/* Funzione Helper per risposte JSON (MODIFICATA CON CONTROLLO ERRORI) */
function send_json_response($data, $http_code = 200) {
    
    // --- BLOCCO DI CONTROLLO JSON ---
$json_output = json_encode($data, JSON_INVALID_UTF8_IGNORE); // <-- QUESTA È LA CORREZIONE    
    if ($json_output === false) {
        // Se json_encode fallisce, logga il motivo
        $json_error = json_last_error_msg();
        debug_log("ERRORE FATALE (send_json_response): Impossibile codificare JSON. Errore: " . $json_error);
        
        // Invia una risposta di errore JSON valida
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Errore server: JSON encoding fallito.', 'json_error' => $json_error]);
    } else {
        // Se è ok, invia la risposta
        debug_log("Checkpoint: send_json_response() - Invio risposta: " . $json_output);
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($http_code);
        echo $json_output;
    }
    // --- FINE BLOCCO DI CONTROLLO ---
    
    exit;
}

// Abilita CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { 
    debug_log("Checkpoint: config.php - Richiesta OPTIONS gestita.");
    http_response_code(200); 
    exit; 
}

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => true,
        'cookie_samesite' => 'Lax'     // <-- CORRETTO
    ]);
    debug_log("Checkpoint: config.php - Sessione avviata (Lax).");
} else {
    debug_log("Checkpoint: config.php - Sessione già attiva.");
}
?>