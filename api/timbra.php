<?php
/* API Endpoint: Registra Timbratura */
require_once 'config.php';

// --- CONTROLLO DI SICUREZZA ---
if (empty($_SESSION['user_id'])) {
    send_json_response(['success' => false, 'message' => 'Accesso non autorizzato. Effettua il login.'], 401);
}
$id_utente_loggato = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$tipo = $input['tipo'] ?? null;
$latitudine = $input['latitudine'] ?? null;
$longitudine = $input['longitudine'] ?? null;

if (empty($tipo) || !in_array($tipo, ['entrata', 'uscita'])) {
    send_json_response(['success' => false, 'message' => 'Tipo di timbratura non valido.'], 400);
}

if ($latitudine === null || $longitudine === null) {
    send_json_response(['success' => false, 'message' => 'Posizione (latitudine e longitudine) obbligatoria.'], 400);
}

try {
    $pdo = connect_db();
    $sql = 'INSERT INTO timbrature (id_utente, tipo, latitudine, longitudine) VALUES (?, ?, ?, ?)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_utente_loggato, $tipo, $latitudine, $longitudine]);

    send_json_response([
        'success' => true,
        'message' => 'Timbratura di "' . htmlspecialchars($tipo) . '" registrata.'
    ]);
} catch (PDOException $e) {
    error_log('Errore API Timbra: ' . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore durante la registrazione.'], 500);
}
?>