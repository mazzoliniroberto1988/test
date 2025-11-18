<?php
/* API Endpoint: Login Utente (Aggiornato per "Ricordami") */
require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['username']) || empty($input['password'])) {
    send_json_response(['success' => false, 'message' => 'Username e password sono obbligatori.'], 400);
}

$username = $input['username'];
$password = $input['password'];
$ricordami = $input['ricordami'] ?? false; // Campo "Ricordami" dal frontend

try {
    $pdo = connect_db();
    $stmt = $pdo->prepare('SELECT * FROM utenti WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // --- LOGIN HA SUCCESSO ---
        
        // 1. Crea la sessione PHP standard
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_ruolo'] = $user['ruolo'];
        $_SESSION['user_nome'] = $user['nome'];
        session_regenerate_id(true);

        $token_da_inviare = null;

        // 2. Se l'utente ha spuntato "Ricordami", crea un token persistente
        if ($ricordami) {
            $selettore = bin2hex(random_bytes(16)); // 32 chars
            $validatore_raw = bin2hex(random_bytes(32)); // 64 chars
            $validatore_hash = password_hash($validatore_raw, PASSWORD_DEFAULT);
            $scadenza = (new DateTime())->modify('+30 days')->format('Y-m-d H:i:s');
            
            // Inserisci il token nel DB
            $stmt_token = $pdo->prepare('INSERT INTO sessioni_persistenti (id_utente, selettore, token_hash, scadenza) VALUES (?, ?, ?, ?)');
            $stmt_token->execute([$user['id'], $selettore, $validatore_hash, $scadenza]);
            
            // Prepara il token da inviare al browser
            $token_da_inviare = [
                'selettore' => $selettore,
                'validatore' => $validatore_raw // Inviamo il token non hashato!
            ];
        }

        // 3. Invia risposta positiva con dati utente e token (se creato)
        send_json_response([
            'success' => true,
            'message' => 'Login effettuato con successo.',
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'nome' => $user['nome'],
                'cognome' => $user['cognome'],
                'ruolo' => $user['ruolo']
            ],
            'token' => $token_da_inviare // Sarà null se "Ricordami" non è spuntato
        ]);

    } else {
        // --- LOGIN FALLITO ---
        send_json_response(['success' => false, 'message' => 'Credenziali non valide.'], 401);
    }

} catch (Exception $e) {
    error_log('Errore API Login: ' . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore durante il tentativo di login.'], 500);
}
?>