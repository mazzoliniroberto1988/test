<?php
/*
 * API Endpoint: Gestione Utenti (Crea / Elimina)
 * AGGIORNATO: Logica Admin/Supervisor implementata.
 */
require_once 'config.php';

// --- CONTROLLO DI SICUREZZA FONDAMENTALE ---
// Solo Admin e Supervisor possono accedere. I dipendenti sono bloccati.
if (empty($_SESSION['user_id']) || empty($_SESSION['user_ruolo']) || $_SESSION['user_ruolo'] === 'employee') {
    send_json_response(['success' => false, 'message' => 'Accesso negato. Privilegi insufficienti.'], 403);
}

// Se siamo qui, l'utente è un admin o un supervisor.
$id_admin_loggato = $_SESSION['user_id'];
$creator_ruolo = $_SESSION['user_ruolo'];

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;

if (empty($action)) {
    send_json_response(['success' => false, 'message' => 'Azione non specificata.'], 400);
}

try {
    $pdo = connect_db();

    switch ($action) {
        // --- AZIONE: CREA UTENTE ---
        case 'create':
            $username = $input['username'] ?? null;
            $password = $input['password'] ?? null;
            $nome = $input['nome'] ?? null;
            $cognome = $input['cognome'] ?? null;
            $ruolo_da_creare = $input['ruolo'] ?? null;

            if (empty($username) || empty($password) || empty($nome) || empty($cognome)) {
                send_json_response(['success' => false, 'message' => 'Tutti i campi (tranne ruolo) sono obbligatori.'], 400);
            }

            // *** NUOVA LOGICA RUOLI ***
            if ($creator_ruolo === 'supervisor') {
                // Un supervisor può creare SOLO dipendenti.
                // Ignora qualsiasi ruolo inviato dal form e forza 'employee'.
                $ruolo_da_creare = 'employee';
            } else {
                // Un admin deve specificare un ruolo
                if (empty($ruolo_da_creare) || !in_array($ruolo_da_creare, ['employee', 'supervisor', 'admin'])) {
                     send_json_response(['success' => false, 'message' => 'Ruolo non valido specificato.'], 400);
                }
            }
            // *** FINE LOGICA RUOLI ***

            $stmt_check = $pdo->prepare('SELECT id FROM utenti WHERE username = ?');
            $stmt_check->execute([$username]);
            if ($stmt_check->fetch()) {
                send_json_response(['success' => false, 'message' => 'Questo username è già in uso.'], 409);
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $sql = 'INSERT INTO utenti (username, password_hash, nome, cognome, ruolo) VALUES (?, ?, ?, ?, ?)';
            $stmt_insert = $pdo->prepare($sql);
            $stmt_insert->execute([$username, $password_hash, $nome, $cognome, $ruolo_da_creare]);

            send_json_response(['success' => true, 'message' => 'Utente creato con successo.']);
            break;

        // --- AZIONE: ELIMINA UTENTE ---
        case 'delete':
            $user_id_to_delete = $input['user_id'] ?? null;
            if (empty($user_id_to_delete)) {
                send_json_response(['success' => false, 'message' => 'ID utente non specificato.'], 400);
            }
            
            // Impedisci di eliminare se stessi
            if ($user_id_to_delete == $id_admin_loggato) {
                send_json_response(['success' => false, 'message' => 'Non puoi eliminare il tuo stesso account.'], 403);
            }

            // *** NUOVA LOGICA RUOLI ***
            if ($creator_ruolo === 'supervisor') {
                // Controlla che il supervisor stia eliminando un dipendente
                $stmt_role = $pdo->prepare("SELECT ruolo FROM utenti WHERE id = ?");
                $stmt_role->execute([$user_id_to_delete]);
                $ruolo_da_eliminare = $stmt_role->fetchColumn();
                
                if (!$ruolo_da_eliminare) {
                     send_json_response(['success' => false, 'message' => 'Utente da eliminare non trovato.'], 404);
                }

                if ($ruolo_da_eliminare !== 'employee') {
                    send_json_response(['success' => false, 'message' => 'Accesso negato. I supervisor possono eliminare solo dipendenti.'], 403);
                }
            }
            // (Un admin può eliminare chiunque, tranne se stesso)
            // *** FINE LOGICA RUOLI ***

            $stmt_delete = $pdo->prepare('DELETE FROM utenti WHERE id = ?');
            $stmt_delete->execute([$user_id_to_delete]);

            if ($stmt_delete->rowCount() > 0) {
                send_json_response(['success' => true, 'message' => 'Utente eliminato con successo.']);
            } else {
                send_json_response(['success' => false, 'message' => 'Utente non trovato o già eliminato.'], 404);
            }
            break;

        default:
            send_json_response(['success' => false, 'message' => 'Azione non valida.'], 400);
            break;
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') { send_json_response(['success' => false, 'message' => 'Errore: Username già esistente.'], 409); }
    error_log('Errore API ManageUser: ' . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore interno del server.'], 500);
}
?>