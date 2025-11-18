<?php
/*
 * FASE 1 REGISTRAZIONE: (Logica per 'webauthn-lib:^4.9')
 * MODIFICATO: Corretto l'errore 'Argument #6 ($attestation)... array given'.
 * Invertiti gli argomenti #6 (attestation) e #7 (excludeCredentials).
 *
 * MODIFICATO: Aggiunti log di debug ultra-dettagliati.
 */

// *** INIZIO BLOCCO 'USE' ***
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialSource;
// *** FINE BLOCCO 'USE' ***

require_once __DIR__ . '/webauthn_server.php';
debug_log("Checkpoint: register_start.php - (1/13) File caricato E webauthn_server.php INCLUSO.");

if (empty($_SESSION['user_id'])) {
    debug_log("ERRORE: register_start.php - Accesso non autorizzato (user_id vuoto).");
    send_json_response(['success' => false, 'message' => 'Accesso non autorizzato.'], 401);
}
$user_id = $_SESSION['user_id'];
$username = $_SESSION['user_nome'];
debug_log("Checkpoint: register_start.php - (2/13) Sessione valida. User ID: $user_id, Username: $username");

try {
    debug_log("Checkpoint: register_start.php - (3/13) Entrato nel blocco try.");
    
    $userEntity = new PublicKeyCredentialUserEntity(
        $username, (string) $user_id, $username
    );
    debug_log("Checkpoint: register_start.php - (4/13) UserEntity creata.");

    $credentialSources = $credentialRepository->findAllForUserEntity($userEntity);
    debug_log("Checkpoint: register_start.php - (5/13) Cercate credenziali (Trovate: " . count($credentialSources) . ")");
    
    $excludeCredentials = array_map(static function (PublicKeyCredentialSource $credential): PublicKeyCredentialDescriptor {
        return $credential->getPublicKeyCredentialDescriptor();
    }, $credentialSources);
    debug_log("Checkpoint: register_start.php - (6/13) 'excludeCredentials' create (count: " . count($excludeCredentials) . ").");

    // --- LOGICA v4.9.2 (Manuale) ---
    $challenge = random_bytes(32);
    debug_log("Checkpoint: register_start.php - (7/13) Challenge generata.");
    
    $publicKeyCredentialParametersList = [
        new PublicKeyCredentialParameters('public-key', -7) // ES256
    ];
    debug_log("Checkpoint: register_start.php - (8/13) publicKeyCredentialParametersList creata.");

    $authenticatorSelection = new AuthenticatorSelectionCriteria(
        'platform',
        'required',
        'required'
    );
    debug_log("Checkpoint: register_start.php - (9/13) Oggetto AuthenticatorSelectionCriteria creato.");

    // *** INIZIO CORREZIONE: ORDINE COSTRUTTORE (8 ARGOMENTI) ***
    // L'errore "Argument #6 ($attestation)... array given"
    // ci dice che l'Arg #6 è $attestation (stringa), non l'array.
    debug_log("Checkpoint: register_start.php - (10/13) Sto per creare PublicKeyCredentialCreationOptions...");
    $creationOptions = new PublicKeyCredentialCreationOptions(
        $rpEntity,                      // Arg 1: rp
        $userEntity,                    // Arg 2: user
        $challenge,                     // Arg 3: challenge
        $publicKeyCredentialParametersList, // Arg 4: pubKeyCredParams
        $authenticatorSelection,        // Arg 5: authenticatorSelection
        'none',                         // Arg 6: attestation            <-- SPOSTATO (string)
        $excludeCredentials,            // Arg 7: excludeCredentials     <-- SPOSTATO (array)
        30000                           // Arg 8: timeout                (int)
    );
    // *** FINE CORREZIONE ***
        
    debug_log("Checkpoint: register_start.php - (11/13) PublicKeyCredentialCreationOptions create (Costruttore 8 argomenti).");
    // --- FINE LOGICA v4.9.2 ---
    
    $_SESSION['webauthn_creation_options'] = $creationOptions;
    debug_log("Checkpoint: register_start.php - (12/13) Opzioni di creazione salvate in sessione.");
    
    send_json_response(['success' => true, 'options' => $creationOptions]);
    debug_log("Checkpoint: register_start.php - (13/13) Risposta JSON inviata.");

} catch (Throwable $e) {
    debug_log("ERRORE FATALE (register_start.php): " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nRiga: " . $e->getLine());
    error_log('WebAuthn Register Start Error: ' . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore server: ' . $e->getMessage()], 500);
}
?>