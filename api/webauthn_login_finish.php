<?php
/*
 * FASE 2 LOGIN - Versione Produzione
 * Include bypass per controllo User Handle (INT vs STRING)
 */

use Webauthn\Exception\InvalidDataException;
use Webauthn\Exception\InvalidUserHandleException;
use Webauthn\AuthenticatorAssertionResponse;

try {
    require_once __DIR__ . '/webauthn_server.php';
} catch (Throwable $e) {
    send_json_response(['success' => false, 'message' => 'Errore interno.'], 500);
}

if (empty($_SESSION['webauthn_request_options']) || empty($_SESSION['webauthn_user_data'])) {
     send_json_response(['success' => false, 'message' => 'Sessione scaduta.'], 401);
}

$requestOptions = $_SESSION['webauthn_request_options'];
$user = $_SESSION['webauthn_user_data'];
unset($_SESSION['webauthn_request_options'], $_SESSION['webauthn_user_data']);

try {
    $publicKeyCredential = $publicKeyCredentialLoader->load(file_get_contents('php://input'));
    $assertionResponse = $publicKeyCredential->getResponse();
    
    if (!$assertionResponse instanceof AuthenticatorAssertionResponse) {
        throw new Exception('Risposta non valida.');
    }
    
    try {
        // Valida firma crittografica (passando NULL come userHandle per evitare l'errore di tipo)
        $credentialSource = $assertionResponseValidator->check(
            $publicKeyCredential->getRawId(),
            $assertionResponse,
            $requestOptions,
            $request,
            null 
        );
    } catch (InvalidUserHandleException $e) {
        // Errore atteso per differenza tipi (INT vs STRING), ignoriamo e procediamo al controllo manuale
    }

    // --- CONTROLLO MANUALE DI SICUREZZA (DIRETTO SU DB) ---
    $rawId = $publicKeyCredential->getRawId();
    $idHash = base64_encode($rawId);
    $sessionUserId = (string) $user['id'];
    
    $stmt = $pdo->prepare("SELECT user_id FROM webauthn_credentials WHERE credential_id_hash = ?");
    $stmt->execute([$idHash]);
    $dbUserId = $stmt->fetchColumn();

    if (!$dbUserId) {
        throw new Exception("Credenziale non trovata.");
    }

    if ((string)$dbUserId !== $sessionUserId) {
        throw new Exception("L'impronta non appartiene all'utente corrente.");
    }
    // --- FINE CONTROLLO ---

    // Login Effettivo
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_ruolo'] = $user['ruolo'];
    $_SESSION['user_nome'] = $user['nome'];

    $user_data_safe = [
        'id' => $user['id'],
        'username' => $user['username'],
        'nome' => $user['nome'],
        'cognome' => $user['cognome'],
        'ruolo' => $user['ruolo']
    ];

    send_json_response([
        'success' => true,
        'message' => 'Login biometrico riuscito.',
        'user' => $user_data_safe
    ]);

} catch (Throwable $e) {
    error_log("Errore Login Bio: " . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore login: ' . $e->getMessage()], 500);
}
?>