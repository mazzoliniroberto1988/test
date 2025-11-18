<?php
/* API Endpoint: Logout Utente (Aggiornato per "Ricordami") */
require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true);
$selettore_token = $input['selettore'] ?? null;

// 1. Se il client invia un selettore, cancella il token persistente dal DB
if ($selettore_token) {
    try {
        $pdo = connect_db();
        $stmt = $pdo->prepare('DELETE FROM sessioni_persistenti WHERE selettore = ?');
        $stmt->execute([$selettore_token]);
    } catch (Exception $e) {
        // Non bloccare il logout se fallisce, logga l'errore
        error_log('Errore cancellazione token: ' . $e->getMessage());
    }
}

// 2. Distruggi la sessione PHP (come prima)
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

// 3. Invia risposta di successo
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Logout effettuato con successo.']);
exit;
?>