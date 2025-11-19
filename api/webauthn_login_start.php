<?php
/*
 * FASE 1 LOGIN - Versione Produzione
 */

use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialSource;

require_once __DIR__ . '/webauthn_server.php'; 

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? null;

if (empty($username)) {
    send_json_response(['success' => false, 'message' => 'Username richiesto.'], 400);
}

try {
    $stmt = $pdo->prepare('SELECT id, nome, cognome, ruolo, username FROM utenti WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        send_json_response(['success' => false, 'message' => 'Utente non trovato.'], 404);
    }
    
    $userEntity = new PublicKeyCredentialUserEntity(
        $user['nome'], (string) $user['id'], $user['nome']
    );

    $credentialSources = $credentialRepository->findAllForUserEntity($userEntity);
    
    if (empty($credentialSources)) {
        send_json_response(['success' => false, 'message' => 'Nessuna biometria registrata per questo utente.'], 404);
    }

    $allowedCredentials = array_map(static function (PublicKeyCredentialSource $credential): PublicKeyCredentialDescriptor {
        return $credential->getPublicKeyCredentialDescriptor();
    }, $credentialSources);

    $challenge = random_bytes(32);
    
    $requestOptions = PublicKeyCredentialRequestOptions::create(
        $challenge,
        $rpEntity->getId(),
        $allowedCredentials,
        'required',
        30000
    );
    
    $_SESSION['webauthn_request_options'] = $requestOptions;
    $_SESSION['webauthn_user_data'] = $user;

    // Costruzione risposta JSON compatibile
    $options_js = [
        'challenge' => base64url_encode($requestOptions->getChallenge()),
        'timeout' => $requestOptions->getTimeout(),
        'rpId' => $requestOptions->getRpId(),
        'userVerification' => $requestOptions->getUserVerification(),
        'allowCredentials' => []
    ];

    foreach ($requestOptions->getAllowCredentials() as $descriptor) {
        $options_js['allowCredentials'][] = [
            'type' => 'public-key',
            'id' => base64url_encode($descriptor->getId()),
            'transports' => $descriptor->getTransports()
        ];
    }

    send_json_response(['success' => true, 'options' => $options_js]);

} catch (Throwable $e) {
    error_log('WebAuthn Login Start Error: ' . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore server.'], 500);
}
?>