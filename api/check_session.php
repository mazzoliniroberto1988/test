<?php
/* API Endpoint: Controlla Sessione (Aggiornato per "Ricordami") */
require_once 'config.php';

function login_con_sessione_php($pdo, $user_id) {
    $stmt = $pdo->prepare('SELECT id, username, nome, cognome, ruolo FROM utenti WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user) {
        // Rinnova dati sessione
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_ruolo'] = $user['ruolo'];
        $_SESSION['user_nome'] = $user['nome'];
        send_json_response(['success' => true, 'user' => $user]);
    } else {
        // Utente sessione non più valido
        session_unset();
        session_destroy();
        send_json_response(['success' => false, 'message' => 'Sessione non valida.'], 401);
    }
}

function login_con_token($pdo, $selettore, $validatore_raw) {
    // 1. Trova il selettore nel DB
    $stmt = $pdo->prepare('SELECT * FROM sessioni_persistenti WHERE selettore = ?');
    $stmt->execute([$selettore]);
    $token_data = $stmt->fetch();

    if (!$token_data) {
        send_json_response(['success' => false, 'message' => 'Token non trovato.'], 401);
    }

    // 2. Controlla se il token è scaduto
    $now = new DateTime();
    $scadenza = new DateTime($token_data['scadenza']);
    if ($now > $scadenza) {
        // Token scaduto, cancellalo
        $pdo->prepare('DELETE FROM sessioni_persistenti WHERE id = ?')->execute([$token_data['id']]);
        send_json_response(['success' => false, 'message' => 'Token scaduto.'], 401);
    }

    // 3. Verifica il validatore
    if (password_verify($validatore_raw, $token_data['token_hash'])) {
        // TOKEN VALIDO! Fai il login
        
        // Ricrea la sessione PHP
        session_regenerate_id(true);
        $user_id = $token_data['id_utente'];
        
        // Richiama la funzione di login per popolare la sessione
        login_con_sessione_php($pdo, $user_id);
        
    } else {
        // Validatore non corretto (possibile attacco?)
        send_json_response(['success' => false, 'message' => 'Token non valido.'], 401);
    }
}

// --- Logica Principale ---
$pdo = connect_db();
$input = json_decode(file_get_contents('php://input'), true);

// Caso 1: Prova a loggare con un Token "Ricordami" inviato dal client
if (isset($input['selettore']) && isset($input['validatore'])) {
    try {
        login_con_token($pdo, $input['selettore'], $input['validatore']);
    } catch (Exception $e) {
        error_log('Errore Login Token: ' . $e->getMessage());
        send_json_response(['success' => false, 'message' => 'Errore verifica token.'], 500);
    }
} 
// Caso 2: Controlla la sessione PHP standard
elseif (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    try {
        login_con_sessione_php($pdo, $_SESSION['user_id']);
    } catch (Exception $e) {
        error_log('Errore Check Session: ' . $e->getMessage());
        send_json_response(['success' => false, 'message' => 'Errore verifica sessione.'], 500);
    }
} 
// Caso 3: Non è loggato in nessun modo
else {
    send_json_response(['success' => false, 'message' => 'Nessuna sessione attiva.'], 401);
}
?>