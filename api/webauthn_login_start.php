<?php
/*
 * FASE 1 LOGIN: (Logica per 'webauthn-lib:^4.9')
 *
 * MODIFICA DEFINITIVA (10:04):
 * L'encoding Base64URL viene ora fatto con la funzione
 * nativa PHP (base64_encode + strtr) per evitare
 * errori di encoding UTF-8 in json_encode.
 */

// Pulisci il file di log per questa esecuzione
// file_put_contents(__DIR__ . '/debug_log.txt', ''); 

// *** INIZIO BLOCCO 'USE' ***
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialSource;
// (Rimosso 'use ParagonIE...')
// *** FINE BLOCCO 'USE' ***

require_once __DIR__ . '/webauthn_server.php'; 

debug_log("Checkpoint: login_start.php - File caricato (server.php già incluso).");

// --- FUNZIONE HELPER PER BASE64URL (NATIVA) ---
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
// --- FINE FUNZIONE HELPER ---

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
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        debug_log("ERRORE: login_start.php - Utente '$username' non trovato nel DB.");
        send_json_response(['success' => false, 'message' => 'Utente non trovato.'], 404);
    }
    debug_log("Checkpoint: login_start.php - Utente trovato: " . json_encode($user));

    
    $userEntity = new PublicKeyCredentialUserEntity(
        $user['nome'], (string) $user['id'], $user['nome']
    );

    $credentialSources = $credentialRepository->findAllForUserEntity($userEntity);
    debug_log("Checkpoint: login_start.php - Cercate credenziali (Trovate: " . count($credentialSources) . ")");

    $allowedCredentials = array_map(static function (PublicKeyCredentialSource $credential): PublicKeyCredentialDescriptor {
        return $credential->getPublicKeyCredentialDescriptor();
    }, $credentialSources);
    
    if (empty($allowedCredentials)) {
        debug_log("ERRORE: login_start.php - Nessuna credenziale 'allowedCredentials' trovata per l'utente.");
        send_json_response(['success' => false, 'message' => 'Nessuna biometria registrata per questo utente.'], 404);
    }
    debug_log("Checkpoint: login_start.php - 'allowedCredentials' create (count: " . count($allowedCredentials) . ").");

    // --- LOGICA v4.9.2 (Corretta) ---
    $challenge = random_bytes(32);
    debug_log("Checkpoint: login_start.php - Challenge generata.");
    
    // Creiamo l'oggetto $requestOptions della libreria PHP (serve per la sessione)
    $requestOptions = PublicKeyCredentialRequestOptions::create(
        $challenge,                            // Arg 1: $challenge (binario)
        $rpEntity->getId(),                    // Arg 2: $rpId
        $allowedCredentials,                   // Arg 3: $allowCredentials
        'required',                            // Arg 4: $userVerification
        30000                                  // Arg 5: $timeout (intero)
    );

    debug_log("Checkpoint: login_start.php - Oggetto PHP requestOptions creato (non stampabile).");
    
    // Salviamo l'OGGETTO PHP nella sessione per il 'finish'
    $_SESSION['webauthn_request_options'] = $requestOptions;
    $_SESSION['webauthn_user_data'] = $user;
    debug_log("Checkpoint: login_start.php - Dati (OGGETTO PHP) salvati in SESSIONE.");

    // --- INIZIO COSTRUZIONE MANUALE RISPOSTA per simplewebauthn (JS) ---
    
    $options_js = [
        // --- QUESTA È LA CORREZIONE (USO DI base64url_encode) ---
        'challenge' => base64url_encode($requestOptions->getChallenge()), // <-- Conversione
        'timeout' => $requestOptions->getTimeout(),
        'rpId' => $requestOptions->getRpId(),
        'userVerification' => $requestOptions->getUserVerification(),
        'allowCredentials' => []
    ];

    // Converti i descrittori
    foreach ($requestOptions->getAllowCredentials() as $descriptor) {
        $options_js['allowCredentials'][] = [
            'type' => $descriptor->getType(),
            // --- QUESTA È LA CORREZIONE (USO DI base64url_encode) ---
            'id' => base64url_encode($descriptor->getId()), // <-- Conversione
            'transports' => $descriptor->getTransports()
        ];
    }

    $responseData = ['success' => true, 'options' => $options_js];
    // --- FINE COSTRUZIONE MANUALE ---

    debug_log("Checkpoint: login_start.php (DEBUG) - Invio al browser (Formato JS Manuale):\n" . json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    send_json_response($responseData); // Invia l'array $responseData mappato manualmente

} catch (Throwable $e) {
    debug_log("ERRORE FATALE (login_start.php): " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nRiga: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString());
    error_log('WebAuthn Login Start Error: ' . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore server: ' . $e->getMessage()], 500);
}
?>