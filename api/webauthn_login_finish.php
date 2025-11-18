<?php
/*
 * FASE 2 LOGIN: (Logica per 'webauthn-lib:^4.9')
 * MODIFICATO: Aggiunti 'use' statement mancanti
 * MODIFICATO: Aggiunti log di debug
 */

// *** INIZIO BLOCCO 'USE' ***
use Webauthn\Exception\InvalidDataException;
use Webauthn\Exception\AuthenticationException;
use Webauthn\AuthenticatorAssertionResponse;
// *** FINE BLOCCO 'USE' ***

require_once __DIR__ . '/webauthn_server.php';

debug_log("Checkpoint: login_finish.php - File caricato (server.php già incluso).");

if (empty($_SESSION['webauthn_request_options']) || empty($_SESSION['webauthn_user_data'])) {
    debug_log("ERRORE: login_finish.php - Sessione non valida o scaduta.");
    send_json_response(['success' => false, 'message' => 'Sessione non valida o scaduta.'], 401);
}
debug_log("Checkpoint: login_finish.php - Sessione valida.");

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

    $credentialSource = $assertionResponseValidator->check(
        $publicKeyCredential->getRawId(),
        $assertionResponse,
        $requestOptions,
        $request,
        (string) $user['id']
    );
    debug_log("Checkpoint: login_finish.php - Validatore 'check' superato.");

    $credentialRepository->updateCounter(
        $credentialSource->getPublicKeyCredentialId(),
        $credentialSource->getCounter()
    );
    debug_log("Checkpoint: login_finish.php - Contatore credenziale aggiornato.");

    // Crea la sessione di login
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_ruolo'] = $user['ruolo'];
    $_SESSION['user_nome'] = $user['nome'];
    session_regenerate_id(true);
    debug_log("Checkpoint: login_finish.php - Sessione PHP creata. Login completato.");
    
    send_json_response([
        'success' => true,
        'message' => 'Login biometrico effettuato.',
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'nome' => $user['nome'],
            'cognome' => $user['cognome'],
            'ruolo' => $user['ruolo']
        ]
    ]);

} catch (AuthenticationException $e) {
    debug_log("ERRORE (AuthenticationException): " . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Login biometrico fallito: ' . $e->getMessage()], 401);
} catch (InvalidDataException $e) {
    debug_log("ERRORE (InvalidDataException): " . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Dati non validi: ' . $e->getMessage()], 400);
} catch (Throwable $e) {
    debug_log("ERRORE FATALE (login_finish.php): " . $e->getMessage());
    error_log('WebAuthn Login Finish Error: ' . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Login biometrico fallito: ' . $e->getMessage()], 500);
}
?>