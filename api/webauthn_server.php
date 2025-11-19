<?php
/*
 * File Helper per inizializzare il server WebAuthn
 * Versione Produzione
 */

require_once __DIR__ . '/config.php';

use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialDescriptor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticationExtensions\ExtensionOutputCheckerHandler;
use Webauthn\TrustPath\EmptyTrustPath;
use Symfony\Component\Uid\Uuid; 

// --- Configurazione del tuo sito (Relying Party) ---
$rpEntity = new PublicKeyCredentialRpEntity(
    'DzTimbratore',
    'badge.dizetaimpianti.com'
);

// --- Repository per le chiavi ---
class DbCredentialRepository implements PublicKeyCredentialSourceRepository {
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    private function createSourceFromData(array $data): PublicKeyCredentialSource {
        $aaguid_string = $data['aaguid'] ?? '00000000-0000-0000-0000-000000000000';
        $aaguid_object = Uuid::fromString($aaguid_string);
        
        $publicKey = base64_decode($data['public_key_spki']);
        $transports = explode(',', $data['transports'] ?? '');
        $credentialId = base64_decode($data['credential_id_hash']);
        
        return PublicKeyCredentialSource::create(
            $credentialId,                  // Arg 1: ID
            $publicKey,                     // Arg 2: Chiave Pubblica
            $transports,
            $data['attestation_type'],
            new EmptyTrustPath(),
            $aaguid_object,
            (string) $data['user_id'],
            false,
            (int) $data['sign_count']
        );
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource {
        $credentialIdHash = base64_encode($publicKeyCredentialId);
        $stmt = $this->pdo->prepare('SELECT * FROM webauthn_credentials WHERE credential_id_hash = ?');
        $stmt->execute([$credentialIdHash]);
        $data = $stmt->fetch();
        return $data ? $this->createSourceFromData($data) : null;
    }
    
    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void {
        $raw_id_binario = $publicKeyCredentialSource->getPublicKeyCredentialId();
        $credentialIdHash = base64_encode($raw_id_binario);

        $user_id = $publicKeyCredentialSource->getUserHandle();
        $publicKey = $publicKeyCredentialSource->getCredentialPublicKey();
        $spki = base64_encode($publicKey);
        $count = $publicKeyCredentialSource->getCounter();
        $transports = implode(',', $publicKeyCredentialSource->getTransports());
        $attestationType = $publicKeyCredentialSource->getAttestationType();
        $aaguid = $publicKeyCredentialSource->getAttestedCredentialData()->getAaguid();
        
        $stmt = $this->pdo->prepare(
            'INSERT INTO webauthn_credentials (user_id, credential_id_hash, public_key_spki, sign_count, transports, attestation_type, aaguid) 
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user_id, $credentialIdHash, $spki, $count, $transports, $attestationType, $aaguid]);
    }
    
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array {
        $user_id = $publicKeyCredentialUserEntity->getId();
        $stmt = $this->pdo->prepare('SELECT * FROM webauthn_credentials WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $sources = [];
        while ($data = $stmt->fetch()) {
            $sources[] = $this->createSourceFromData($data);
        }
        return $sources;
    }
    
    public function updateCounter(string $publicKeyCredentialId, int $newCounter): void {
        $credentialIdHash = base64_encode($publicKeyCredentialId);
        $stmt = $this->pdo->prepare('UPDATE webauthn_credentials SET sign_count = ? WHERE credential_id_hash = ?');
        $stmt->execute([$newCounter, $credentialIdHash]);
    }
}

// --- Inizializzazione Servizi ---
try {
    $pdo = connect_db();
    $credentialRepository = new DbCredentialRepository($pdo);

    $attestationStatementSupportManager = new AttestationStatementSupportManager();
    $attestationStatementSupportManager->add(new NoneAttestationStatementSupport());

    $psr17Factory = new Psr17Factory();
    $creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
    $request = $creator->fromGlobals();

    $attestationObjectLoader = new AttestationObjectLoader($attestationStatementSupportManager);
    $publicKeyCredentialLoader = new PublicKeyCredentialLoader($attestationObjectLoader);

    $extensionOutputCheckerHandler = new ExtensionOutputCheckerHandler();

    $attestationResponseValidator = new AuthenticatorAttestationResponseValidator(
        $attestationStatementSupportManager,
        $credentialRepository,
        null,
        $extensionOutputCheckerHandler,
        null,
        null,
        $attestationObjectLoader
    );

    $assertionResponseValidator = new AuthenticatorAssertionResponseValidator(
        $credentialRepository
    );

} catch (Throwable $e) {
    error_log("Errore WebAuthn Server: " . $e->getMessage());
    if (function_exists('send_json_response')) {
        send_json_response(['success' => false, 'message' => 'Errore interno del server.'], 500);
    }
    exit;
}
?>