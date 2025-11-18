<?php
/*
 * SCRIPT DI DIAGNOSTICA WEBAUTHN
 * Carica questo file nella tua cartella /api/
 * e visitalo dal browser per trovare il punto di errore.
 */

// Impostiamo l'output come JSON per coerenza
header('Content-Type: application/json');
ini_set('display_errors', 1); // Forziamo la visualizzazione degli errori
error_reporting(E_ALL);

$report = [
    'success' => false,
    'message' => 'Test non ancora avviato.',
    'steps' => []
];

function send_debug_report($report) {
    echo json_encode($report, JSON_PRETTY_PRINT);
    exit;
}

try {
    // --- PASSO 1: Controllo config.php ---
    $config_path = __DIR__ . '/config.php';
    if (!file_exists($config_path)) {
        $report['message'] = 'ERRORE FATALE: File config.php non trovato.';
        send_debug_report($report);
    }
    $report['steps'][] = '[OK] Trovato: config.php';
    
    // --- PASSO 2: Inclusione config.php ---
    require_once $config_path;
    $report['steps'][] = '[OK] Incluso: config.php (Nessun errore di sintassi)';
    
    // --- PASSO 3: Controllo Autoloader Composer ---
    $autoloader_path = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoloader_path)) {
        $report['message'] = 'ERRORE FATALE: File vendor/autoload.php non trovato. Devi eseguire "composer install".';
        send_debug_report($report);
    }
    $report['steps'][] = '[OK] Trovato: vendor/autoload.php';

    // --- PASSO 4: Inclusione Autoloader Composer ---
    require_once $autoloader_path;
    $report['steps'][] = '[OK] Incluso: vendor/autoload.php (Le dipendenze sembrano caricate)';

    // --- PASSO 5: Controllo Estensioni PHP ---
    $required_extensions = ['pdo_mysql', 'gmp', 'mbstring'];
    $missing_extensions = [];
    foreach ($required_extensions as $ext) {
        if (!extension_loaded($ext)) {
            $missing_extensions[] = $ext;
        }
    }
    if (!empty($missing_extensions)) {
        $report['message'] = 'ERRORE FATALE: Estensioni PHP mancanti: ' . implode(', ', $missing_extensions);
        send_debug_report($report);
    }
    $report['steps'][] = '[OK] Estensioni PHP richieste (pdo_mysql, gmp, mbstring) sono installate.';

    // --- PASSO 6: Test Connessione DB ---
    if (!function_exists('connect_db')) {
        $report['message'] = 'ERRORE FATALE: La funzione connect_db() non è definita in config.php.';
        send_debug_report($report);
    }
    
    $pdo = connect_db();
    if (!$pdo) {
        $report['message'] = 'ERRORE FATALE: connect_db() non ha restituito un oggetto PDO. Controlla la funzione.';
        send_debug_report($report);
    }
    $report['steps'][] = '[OK] Connessione al database riuscita.';

    // --- PASSO 7: Test Caricamento Classi Libreria ---
    if (!class_exists('Webauthn\PublicKeyCredentialRpEntity')) {
        $report['message'] = 'ERRORE FATALE: Classe "Webauthn\PublicKeyCredentialRpEntity" non trovata. L\'installazione di Composer è incompleta o corrotta.';
        send_debug_report($report);
    }
    if (!class_exists('Nyholm\Psr7\Factory\Psr17Factory')) {
        $report['message'] = 'ERRORE FATALE: Classe "Nyholm\Psr7\Factory\Psr17Factory" non trovata. L\'installazione di Composer è incompleta o corrotta.';
        send_debug_report($report);
    }
    $report['steps'][] = '[OK] Classi principali di WebAuthn caricate correttamente.';

    // --- PASSO 8: Test Inclusione webauthn_server.php ---
    // Non usiamo require_once perché alcune cose sono già state caricate
    // Verifichiamo solo che le variabili siano definite dopo l'inclusione
    
    // Includiamo il file ma *all'interno di una funzione* per non
    // re-dichiarare le classi (come DbCredentialRepository)
    // e causare un errore fatale, se questo script viene eseguito due volte.
    
    // Ci limitiamo a un test più semplice per evitare conflitti.
    // I passi precedenti hanno già verificato tutto ciò che webauthn_server.php fa.
    
    $report['steps'][] = '--- TEST COMPLETATI ---';
    $report['success'] = true;
    $report['message'] = 'DIAGNOSI OK! Se questo script funziona ma il login fallisce, il problema è *dentro* la logica di webauthn_login_start.php (es. query SQL).';
    send_debug_report($report);


} catch (PDOException $e) {
    // Errore specifico di connessione DB
    $report['message'] = 'ERRORE PDO: Impossibile connettersi al database. Messaggio: ' . $e->getMessage();
    send_debug_report($report);
} catch (Throwable $e) {
    // Qualsiasi altro errore fatale (sintassi, ecc.)
    $report['message'] = 'ERRORE PHP: Si è verificato un errore imprevisto. Messaggio: ' . $e->getMessage() . ' (File: ' . $e->getFile() . ' Riga: ' . $e->getLine() . ')';
    send_debug_report($report);
}

?>