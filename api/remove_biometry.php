<?php
/* API Endpoint: Rimuovi Biometria Utente Corrente */
require_once 'config.php';

// 1. Verifica sessione
if (empty($_SESSION['user_id'])) {
    send_json_response(['success' => false, 'message' => 'Non sei loggato.'], 401);
}

$user_id = $_SESSION['user_id'];

try {
    $pdo = connect_db();
    
    // 2. Cancella tutte le credenziali WebAuthn associate a questo ID utente
    $stmt = $pdo->prepare("DELETE FROM webauthn_credentials WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    if ($stmt->rowCount() > 0) {
        send_json_response(['success' => true, 'message' => 'Dati biometrici rimossi correttamente.']);
    } else {
        send_json_response(['success' => false, 'message' => 'Nessun dato biometrico trovato da rimuovere.']);
    }

} catch (PDOException $e) {
    error_log("Errore Rimozione Bio: " . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore del database.'], 500);
}
?>