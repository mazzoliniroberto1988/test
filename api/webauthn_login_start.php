<?php
/*
 * FASE 1 LOGIN: (Logica per 'webauthn-lib:^4.9')
 *
 * MODIFICATO: Correzione Definitiva (basata su documentazione v4.9).
 * La classe PublicKeyCredentialRequestOptions NON usa metodi setter
 * (set...) o builder (with...) a catena.
 *
 * Tutte le opzioni (challenge, allowedCredentials, timeout, rpId)
 * devono essere passate come argomenti al metodo statico ::create().
 */

// Pulisci il file di log per questa esecuzione
// file_put_contents(__DIR__ . '/debug_log.txt', ''); 

// *** INIZIO BLOCCO 'USE' ***
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialSource;
// *** FINE BLOCCO 'USE' ***

require_once __DIR__ . '/webauthn_server.php'; 

debug_log("Checkpoint: login_start.php - File caricato (server.php già incluso).");

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? null;
debug_log("Checkpoint: login_start.php - Input ricevuto: " . json_encode($input));


if (empty($username)) {
    debug_log("ERRORE: login_start.php - Username vuoto.");
    send_json_response(['success' => false, 'message' => 'Username richiesto.'], 400);
}
debug_log("Checkpoint: login_start.php - Username valido: $username");


try {
    debug_log("Checkpoint: login_start.php - Entrato nel blocco try.");
    
    $stmt = $pdo->prepare('SELECT id, nome, cognome, ruolo, username FROM utenti WHERE username = ?');
    debug_log("Checkpoint: login_start.php - Query 'utenti' preparata.");
    
    $stmt->execute([$username]);
    debug_log("Checkpoint: login_start.php - Query 'utenti' eseguita.");
    
    $user = $stmt->fetch();
    debug_log("Checkpoint: login_start.php - Dati utente fetch().");

    if (!$user) {
        debug_log("ERRORE: login_start.php - Utente '$username' non trovato nel DB.");
        send_json_response(['success' => false, 'message' => 'Utente non trovato.'], 404);
    }
    debug_log("Checkpoint: login_start.php - Utente trovato: " . json_encode($user));

    
    $userEntity = new PublicKeyCredentialUserEntity(
        $user['nome'], (string) $user['id'], $user['nome']
    );
    debug_log("Checkpoint: login_start.php - PublicKeyCredentialUserEntity creata.");

    $credentialSources = $credentialRepository->findAllForUserEntity($userEntity);
    debug_log("Checkpoint: login_start.php - Cercate credenziali (Trovate: " . count($credentialSources) . ")");

    $allowedCredentials = array_map(static function (PublicKeyCredentialSource $credential): PublicKeyCredentialDescriptor {
        return $credential->getPublicKeyCredentialDescriptor();
    }, $credentialSources);
    
    if (empty($allowedCredentials)) {
        debug_log("ERRORE: login_start.php - Nessuna credenziale 'allowedCredentials' trovata per l'utente.");
        send_json_response(['success'falsesage' => 'Nessuna biometria registrata per questo utente.'], 404);
    }
    debug_log("Checkpoint: login_start.php - 'allowedCredentials' create (count: " . count($allowedCredentials) . ").");

    // --- LOGICA v4.9.2 (Corretta) ---
    $challenge = random_bytes(32);
    debug_log("Checkpoint: login_start.php - Challenge generata.");
    
    // --- INIZIO CORREZIONE: Tutti i parametri vanno in ::create() ---
    // La v4.9 non usa metodi "setter" (set...) o "builder" (with...)
    // per questa classe. Tutto viene passato al costruttore statico.
    $requestOptions = PublicKeyCredentialRequestOptions::create(
        $challenge,                            // Arg 1: $challenge
        $allowedCredentials,                   // Arg 2: $allowCredentials
        30000,                                 // Arg 3: $timeout
        $rpEntity->getId(),                    // Arg 4: $rpId
        'required'                             // Arg 5: $userVerification
    );
    debug_log("Checkpoint: login_start.php - requestOptions create con ::create() (5 argomenti).");
    // --- FINE CORREZIONE ---
    
    $_SESSION['webauthn_request_options'] = $requestOptions;
    $_SESSION['webauthn_user_data'] = $user;
    debug_log("Checkpoint: login_start.php - Dati salvati in SESSIONE.");

    send_json_response(['success' => true, 'options' => $requestOptions]);

} catch (Throwable $e) {
    debug_log("ERRORE FATALE (login_start.php): " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nRiga: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString());
    error_log('WebAuthn Login Start Error: ' . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore server: ' . $e->getMessage()], 500);
}
?>