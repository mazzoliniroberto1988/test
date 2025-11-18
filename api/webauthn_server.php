<?php
/*
 * File Helper per inizializzare il server WebAuthn
 * Logica corretta per la libreria 'web-auth/webauthn-lib:^4.9'
 *
 * MODIFICATO: FIX 1-2 (Base64) per credential_id_hash.
 * MODIFICATO: FIX 3-4 (Tipo AAGUID e Uuid::fromString).
 * MODIFICATO: FIX 5 (Ordine Argomenti Finale)
 * - Spostato $counter (int) da Arg #4 a Arg #9.
 * - Spostato $attestationType (string) da Arg #9 a Arg #4.
 * - Come richiesto dall'errore fatale "Argument #9 ($counter) must be of type int".
 */

require_once __DIR__ . '/config.php';

debug_log("Checkpoint: webauthn_server.php - File caricato (config.php già incluso).");

// ... (tutte le dichiarazioni 'use' esistenti) ...
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
use Webauthn\Exception\InvalidDataException;
use Webauthn\AuthenticationException;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticationExtensions\AuthenticationExtensionsClientInputs;
use Webauthn\TokenBinding\TokenBindingHandler;
use Webauthn\AuthenticationExtensions\ExtensionOutputCheckerHandler;
use Psr\EventDispatcher\EventDispatcherInterface;
use Webauthn\CeremonyStep\CeremonyStepManager;

// *** AGGIUNTE PER IL REPOSITORY CORRETTO ***
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\AttestedCredentialData;
use Symfony\Component\Uid\Uuid; 

debug_log("Checkpoint: webauthn_server.php - Tutte le classi 'use' sono state dichiarate.");

// --- Configurazione del tuo sito (Relying Party) ---
$rpEntity = new PublicKeyCredentialRpEntity(
    'DzTimbratore',
    'badge.dizetaimpianti.com'
);

// --- Repository per le chiavi (RISCRITTO) ---
class DbCredentialRepository implements PublicKeyCredentialSourceRepository {
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    // Funzione helper interna per convertire i dati del DB in un oggetto
    private function createSourceFromData(array $data): PublicKeyCredentialSource {
        debug_log("Checkpoint: createSourceFromData - Inizio.");
        
        // --- FIX 4: CONVERTI AAGUID DA STRINGA A OGGETTO UUID ---
        debug_log("Checkpoint: createSourceFromData (DEBUG-UUID) - Estraggo aaguid come stringa.");
        $aaguid_string = $data['aaguid'] ?? '00000000-0000-0000-0000-000000000000';
        debug_log("Checkpoint: createSourceFromData (DEBUG-UUID) - AAGUID (stringa): $aaguid_string");
        debug_log("Checkpoint: createSourceFromData (DEBUG-UUID) - Sto per creare Uuid::fromString()");
        $aaguid_object = Uuid::fromString($aaguid_string);
        debug_log("Checkpoint: createSourceFromData (DEBUG-UUID) - Oggetto Uuid creato.");
        
        $publicKey = base64_decode($data['public_key_spki']);
        $transports = explode(',', $data['transports'] ?? '');
        
        // --- FIX 2: DECODIFICA BASE64 ---
        debug_log("Checkpoint: createSourceFromData (DEBUG-BASE64) - Decodifico 'credential_id_hash' da Base64.");
        $credentialId = base64_decode($data['credential_id_hash']);
        
        // --- FIX 5: ORDINE ARGOMENTI FINALE (COME DA ERRORE) ---
        debug_log("Checkpoint: createSourceFromData (DEBUG-FIX5) - Chiamata a ::create() con ordine definitivo.");
        return PublicKeyCredentialSource::create(
            $publicKey,                     // Arg 1: credentialPublicKey
            $credentialId,                  // Arg 2: publicKeyCredentialId
            $transports,                    // Arg 3: transports
            $data['attestation_type'],      // Arg 4: attestationType (string) <-- SPOSTATO
            new EmptyTrustPath(),           // Arg 5: trustPath
            $aaguid_object,                 // Arg 6: aaguid (Uuid object)
            (string) $data['user_id'],      // Arg 7: userHandle (string)
            false,                          // Arg 8: otherUI (bool)
            (int) $data['sign_count']       // Arg 9: counter (int)          <-- SPOSTATO
        );
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource {
        // --- FIX 1 (Lettura): Dobbiamo codificare l'ID binario per cercarlo nel DB ---
        $credentialIdHash = base64_encode($publicKeyCredentialId);
        debug_log("Checkpoint: findOneByCredentialId (DEBUG-BASE64) - Cerco ID codificato: " . $credentialIdHash);
        
        $stmt = $this->pdo->prepare('SELECT * FROM webauthn_credentials WHERE credential_id_hash = ?');
        $stmt->execute([$credentialIdHash]); // Cerca il Base64
        $data = $stmt->fetch();
        if (!$data) { 
            debug_log("Checkpoint: findOneByCredentialId - Nessun record trovato.");
            return null; 
        }
        
        return $this->createSourceFromData($data);
    }
    
    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void {
        debug_log("Checkpoint: DbCredentialRepository - saveCredentialSource chiamato.");
        
        $raw_id_binario = $publicKeyCredentialSource->getPublicKeyCredentialId();
        
        // --- FIX 1 (Scrittura): CODIFICA BASE64 ---
        debug_log("Checkpoint: DbCredentialRepository (DEBUG-BASE64) - Codifico 'raw_id' binario in Base64.");
        $credentialIdHash = base64_encode($raw_id_binario);
        debug_log("Checkpoint: DbCredentialRepository (DEBUG-BASE64) - ID codificato: " . $credentialIdHash);

        $user_id = $publicKeyCredentialSource->getUserHandle();
        $publicKey = $publicKeyCredentialSource->getCredentialPublicKey();
        $spki = base64_encode($publicKey); // Già corretto (è sempre stato base64)
        $count = $publicKeyCredentialSource->getCounter();
        $transports = implode(',', $publicKeyCredentialSource->getTransports());
        
        $attestationType = $publicKeyCredentialSource->getAttestationType();
        
        debug_log("Checkpoint: DbCredentialRepository (DEBUG-AAGUID) - Sto per chiamare ->getAttestedCredentialData()->getAaguid()");
        $aaguid = $publicKeyCredentialSource->getAttestedCredentialData()->getAaguid(); // Questo restituisce GIA' la stringa
        debug_log("Checkpoint: DbCredentialRepository (DEBUG-AAGUID) - AAGUID (stringa) ottenuta: $aaguid");
        
        debug_log("Checkpoint: DbCredentialRepository - Dati pronti per INSERT (UserID: $user_id, AAGUID: $aaguid, ID_HASH_BASE64: $credentialIdHash).");
        
        $stmt = $this->pdo->prepare(
            'INSERT INTO webauthn_credentials (user_id, credential_id_hash, public_key_spki, sign_count, transports, attestation_type, aaguid) 
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user_id, $credentialIdHash, $spki, $count, $transports, $attestationType, $aaguid]); // Salva il Base64
        debug_log("Checkpoint: DbCredentialRepository - INSERT eseguito.");
    }
    
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array {
        $user_id = $publicKeyCredentialUserEntity->getId();
        debug_log("Checkpoint: findAllForUserEntity - Ricerca per UserID: $user_id");
        $stmt = $this->pdo->prepare('SELECT * FROM webauthn_credentials WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $sources = [];
        while ($data = $stmt->fetch()) {
            debug_log("Checkpoint: findAllForUserEntity - Trovato record, chiamo createSourceFromData.");
            $sources[] = $this->createSourceFromData($data);
        }
        debug_log("Checkpoint: findAllForUserEntity - Fine ciclo. Credenziali trovate: " . count($sources));
        return $sources;
    }
    
    public function updateCounter(string $publicKeyCredentialId, int $newCounter): void {
        // --- FIX 1 (Update): Dobbiamo codificare l'ID binario per cercarlo nel DB ---
        $credentialIdHash = base64_encode($publicKeyCredentialId);
        debug_log("Checkpoint: updateCounter (DEBUG-BASE64) - Aggiorno contatore per ID codificato: " . $credentialIdHash);
        
        $stmt = $this->pdo->prepare('UPDATE webauthn_credentials SET sign_count = ? WHERE credential_id_hash = ?');
        $stmt->execute([$newCounter, $credentialIdHash]); // Cerca il Base64
    }
}
debug_log("Checkpoint: webauthn_server.php - Classe DbCredentialRepository (RISCRITTA) definita.");


// --- Inizializzazione dei servizi (invariata) ---
try {
    $pdo = connect_db();
    debug_log("Checkpoint: webauthn_server.php - Variabile \$pdo inizializzata.");

    $credentialRepository = new DbCredentialRepository($pdo);
    debug_log("Checkpoint: webauthn_server.php - DbCredentialRepository creato.");

    $attestationStatementSupportManager = new AttestationStatementSupportManager();
    $attestationStatementSupportManager->add(new NoneAttestationStatementSupport());
    debug_log("Checkpoint: webauthn_server.php - SupportManager creato.");

    $psr17Factory = new Psr17Factory();
    $creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
    $request = $creator->fromGlobals();
    debug_log("Checkpoint: webauthn_server.php - Richiesta PSR7 creata.");

    $attestationObjectLoader = new AttestationObjectLoader($attestationStatementSupportManager);
    $publicKeyCredentialLoader = new PublicKeyCredentialLoader($attestationObjectLoader);
    debug_log("Checkpoint: webauthn_server.php - Loader creati.");

    $extensionOutputCheckerHandler = new ExtensionOutputCheckerHandler();
    debug_log("Checkpoint: webauthn_server.php - ExtensionOutputCheckerHandler creato.");

    $attestationResponseValidator = new AuthenticatorAttestationResponseValidator(
        $attestationStatementSupportManager,
        $credentialRepository,
        null,
        $extensionOutputCheckerHandler,
        null,
        null,
        $attestationObjectLoader
    );
    debug_log("Checkpoint: webauthn_server.php - Validatore di Attestazione creato (Firma Corretta a 7 argomenti).");

    $assertionResponseValidator = new AuthenticatorAssertionResponseValidator(
        $credentialRepository
    );
    debug_log("Checkpoint: webauthn_server.php - Validatore di Asserzione (Login) creato.");

} catch (Throwable $e) {
    debug_log("ERRORE FATALE (webauthn_server.php): Impossibile inizializzare i servizi. Messaggio: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nRiga: " . $e->getLine());
    if (function_exists('send_json_response')) {
        send_json_response(['success' => false, 'message' => 'Errore server critico: ' . $e->getMessage()], 500);
    }
    exit;
}

debug_log("Checkpoint: webauthn_server.php - Fine del file, servizi essenziali inizializzati.");
?>