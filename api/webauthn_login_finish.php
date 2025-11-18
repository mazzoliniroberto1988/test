<?php
/*
 * FASE 2 LOGIN: (Logica per 'webauthn-lib:^4.9')
 *
 * MODIFICA (10:20):
 * L'errore 'Invalid user handle' è confermato.
 * I log di debug precedenti fallivano perché tentavano di
 * stampare dati binari.
 *
 * SOLUZIONE: Si usa bin2hex() per stampare in modo sicuro
 * gli user handle prima del confronto.
 */

// --- LOGGER DI EMERGENZA ---
$log_file_emergency = __DIR__ . '/debug_log.txt';
$timestamp_emergency = date('Y-m-d H:i:s');
$log_entry_emergency = "--- [$timestamp_emergency] ---\nCheckpoint: login_finish.php - ESECUZIONE AVVIATA (File chiamato).\n\n";
file_put_contents($log_file_emergency, $log_entry_emergency, FILE_APPEND | LOCK_EX);
// --- FINE BLOCCO DI DEBUG --- 


// *** INIZIO BLOCCO 'USE' (COMPLETO) ***
use Webauthn\Exception\InvalidDataException;
use Webauthn\Exception\AuthenticationException;
use Webauthn\Exception\InvalidUserHandleException;
use Webauthn\AuthenticatorAssertionResponse;
// *** FINE BLOCCO 'USE' ***

try {
    require_once __DIR__ . '/webauthn_server.php';
    debug_log("Checkpoint: login_finish.php - webauthn_server.php CARICATO CORRETTAMENTE.");
} catch (Throwable $e) {
    $log_entry_fatal = "--- [$timestamp_emergency] ---\nERRORE FATALE (login_finish.php) durante require_once: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nRiga: " . $e->getLine() . "\n\n";
    file_put_contents($log_file_emergency, $log_entry_fatal, FILE_APPEND | LOCK_EX);
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Errore fatale durante il caricamento di webauthn_server.php: ' . $e->getMessage()]);
    exit;
}

debug_log("Checkpoint: login_finish.php - File caricato (server.php già incluso).");

// Loggiamo in modo più verboso lo stato della sessione
if (empty($_SESSION['webauthn_request_options'])) {
     debug_log("ERRORE: login_finish.php - Sessione non valida: 'webauthn_request_options' è VUOTO.");
     send_json_response(['success' => false, 'message' => 'Sessione non valida o scaduta (codice: 1).'], 401);
}
if (empty($_SESSION['webauthn_user_data'])) {
     debug_log("ERRORE: login_finish.php - Sessione non valida: 'webauthn_user_data' è VUOTO.");
     send_json_response(['success' => false, 'message' => 'Sessione non valida o scaduta (codice: 2).'], 401);
}

debug_log("Checkpoint: login_finish.php - Sessione valida. Dati presenti.");

$requestOptions = $_SESSION['webauthn_request_options'];
$user = $_SESSION['webauthn_user_data'];
unset($_SESSION['webauthn_request_options'], $_SESSION['webauthn_user_data']);

try {
    debug_log("Checkpoint: login_finish.php - Entrato nel blocco try.");
    
    $publicKeyCredential = $publicKeyCredentialLoader->load(
        file_get_contents('php://input')
    );
    debug_log("Checkpoint: login_finish.php - Dati caricati da php://input.");
    
    $assertionResponse = $publicKeyCredential->getResponse();
    if (!$assertionResponse instanceof AuthenticatorAssertionResponse) {
        debug_log("ERRORE: login_finish.php - Risposta non è AuthenticatorAssertionResponse.");
        throw new InvalidDataException('Risposta di asserzione non valida.');
    }
    debug_log("Checkpoint: login_finish.php - Risposta valida (Assertion).");

    // --- BLOCCO DI DEBUG SICURO (CON BIN2HEX) ---
    $userIdFromSession = (string) $user['id'];
    $userHandleFromAuth_raw = $assertionResponse->getUserHandle(); // Questo è binario o null

    debug_log("--- CHECKPOINT DI CONFRONTO USER HANDLE (Safe Log) ---");
    debug_log("ID UTENTE (da SESSIONE / stringa): '" . $userIdFromSession . "'");
    
    if ($userHandleFromAuth_raw === null) {
        debug_log("ID UT