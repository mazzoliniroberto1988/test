<?php
/*
 * FASE 2 REGISTRAZIONE: (Logica per 'webauthn-lib:^4.9')
 * MODIFICATO: Aggiunto 'use' statement
 * MODIFICATO: Aggiunto log di debug per scoprire i metodi
 * dell'oggetto $credentialSource.
 *
 * MODIFICATO: Aggiunti log di debug ultra-dettagliati.
 */

// *** INIZIO BLOCCO 'USE' ***
use Webauthn\Exception\InvalidDataException;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredentialSource; // <-- Assicuriamoci che sia importato
// *** FINE BLOCCO 'USE' ***

require_once __DIR__ . '/webauthn_server.php';
debug_log("Checkpoint: register_finish.php - (1/12) File caricato E webauthn_server.php INCLUSO.");


if (empty($_SESSION['user_id']) || empty($_SESSION['webauthn_creation_options'])) {
    debug_log("ERRORE: register_finish.php - Sessione non valida o scaduta.");
    send_json_response(['success' => false, 'message' => 'Sessione non valida o scaduta.'], 401);
}
debug_log("Checkpoint: register_finish.php - (2/12) Sessione valida.");

$creationOptions = $_SESSION['webauthn_creation_options'];
unset($_SESSION['webauthn_creation_options']);
debug_log("Checkpoint: register_finish.php - (3/12) Opzioni di creazione caricate e rimosse dalla sessione.");

try {
    debug_log("Checkpoint: register_finish.php - (4/12) Entrato nel blocco try.");
    
    $publicKeyCredential = $publicKeyCredentialLoader->load(
        file_get_contents('php://input')
    );
    debug_log("Checkpoint: register_finish.php - (5/12) Dati caricati da php://input (publicKeyCredentialLoader->load).");
    
    $attestationResponse = $publicKeyCredential->getResponse();
    debug_log("Checkpoint: register_finish.php - (6/12) getResponse() chiamato.");
    
    if (!$attestationResponse instanceof AuthenticatorAttestationResponse) {
        debug_log("ERRORE: register_finish.php - Risposta non è AuthenticatorAttestationResponse.");
        throw new InvalidDataException('Risposta di attestazione non valida.');
    }
    debug_log("Checkpoint: register_finish.php - (7/12) Risposta valida (Attestation).");

    debug_log("Checkpoint: register_finish.php - (8/12) Sto per chiamare attestationResponseValidator->check()...");
    $credentialSource = $attestationResponseValidator->check(
        $attestationResponse,
        $creationOptions,
        $request
    );
    debug_log("Checkpoint: register_finish.php - (9/12) Validatore 'check' superato.");

    // *** INIZIO BLOCCO DI DEBUG OGGETTO ***
    // BASTA ANDARE A TENTONI. ORA VEDIAMO COSA C'E' DENTRO.
    if ($credentialSource instanceof PublicKeyCredentialSource) {
        debug_log("DEBUG: L'oggetto \$credentialSource è di classe: " . get_class($credentialSource));
        debug_log("DEBUG: Metodi disponibili sull'oggetto: " . implode(', ', get_class_methods($credentialSource)));
    } else {
        debug_log("DEBUG: L'oggetto \$credentialSource NON è del tipo atteso.");
    }
    // *** FINE BLOCCO DI DEBUG OGGETTO ***
    
    debug_log("Checkpoint: register_finish.php - (10/12) Sto per chiamare credentialRepository->saveCredentialSource()...");
    $credentialRepository->saveCredentialSource($credentialSource);
    debug_log("Checkpoint: register_finish.php - (11/12) Credenziale salvata nel repository.");
    
    send_json_response(['success' => true, 'message' => 'Registrazione biometrica completata!']);
    debug_log("Checkpoint: register_finish.php - (12/12) Risposta JSON inviata.");

} catch (InvalidDataException $e) {
    debug_log("ERRORE (InvalidDataException): " . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Dati non validi: ' . $e->getMessage()], 400);
} catch (Throwable $e) {
    debug_log("ERRORE FATALE (register_finish.php): " . $e->getMessage());
    error_log('WebAuthn Register Finish Error: ' . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore server: ' . $e->getMessage()], 500);
}
?>