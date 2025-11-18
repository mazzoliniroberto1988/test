<?php
/*
 * API Endpoint: Get Data (Centralino)
 * AGGIORNATO: Logica Supervisor implementata.
 * Admin vede tutto. Supervisor vede solo i dipendenti.
 */
require_once 'config.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['user_ruolo'])) {
    send_json_response(['success' => false, 'message' => 'Accesso non autorizzato.'], 401);
}
$id_utente_loggato = $_SESSION['user_id'];
$ruolo_utente_loggato = $_SESSION['user_ruolo'];

try {
    $pdo = connect_db();

    // --- CASO 1: DIPENDENTE (Invariato) ---
    if ($ruolo_utente_loggato === 'employee') {
        
        $stmt_last = $pdo->prepare(
            'SELECT tipo, timestamp FROM timbrature 
             WHERE id_utente = ? AND DATE(timestamp) = CURDATE() 
             ORDER BY timestamp DESC LIMIT 1'
        );
        $stmt_last->execute([$id_utente_loggato]);
        $last_timbratura = $stmt_last->fetch();

        $stmt_logs = $pdo->prepare(
            'SELECT tipo, timestamp FROM timbrature 
             WHERE id_utente = ? ORDER BY timestamp DESC LIMIT 20'
        );
        $stmt_logs->execute([$id_utente_loggato]);
        $recent_logs = $stmt_logs->fetchAll();

        send_json_response([
            'success' => true,
            'last_timbratura' => $last_timbratura ? $last_timbratura : null,
            'recent_logs' => $recent_logs
        ]);
    } 
    // --- CASO 2: ADMIN o SUPERVISOR ---
    elseif ($ruolo_utente_loggato === 'admin' || $ruolo_utente_loggato === 'supervisor') {

        $response_data = [];
        $sql_users_where = '';
        $sql_logs_user_filter = '';
        $params_logs = [];

        // *** NUOVA LOGICA DI FILTRO RUOLO ***
        if ($ruolo_utente_loggato === 'supervisor') {
            // I supervisor vedono solo i dipendenti
            $sql_users_where = " WHERE ruolo = 'employee' ";
            $sql_logs_user_filter = " WHERE u.ruolo = 'employee' ";
        }

        // 1. Ottieni la lista utenti (filtrata se supervisor)
        $sql_users = "SELECT id, username, nome, cognome, ruolo FROM utenti 
                      $sql_users_where ORDER BY cognome, nome ASC";
        $response_data['all_users'] = $pdo->query($sql_users)->fetchAll();

        // 2. Controlla se i filtri del form sono stati applicati
        $filtro_dipendente = $_GET['dipendente'] ?? null;
        $filtro_mese = $_GET['mese'] ?? null;
        $filtro_anno = $_GET['anno'] ?? null;

        if (!empty($filtro_dipendente) && !empty($filtro_mese) && !empty($filtro_anno)) {
            
            // Costruisci la query per i log
            $sql = "SELECT DAY(timestamp) as giorno, HOUR(timestamp) as ora, MINUTE(timestamp) as minuti, 
                           tipo, latitudine, longitudine
                    FROM timbrature t
                    JOIN utenti u ON t.id_utente = u.id"; // Join per filtrare per ruolo
            
            $where_conditions = [];
            
            // Aggiungi il filtro del ruolo (se supervisor)
            if ($ruolo_utente_loggato === 'supervisor') {
                $where_conditions[] = "u.ruolo = 'employee'";
            }
            
            // Aggiungi i filtri del form
            $where_conditions[] = "t.id_utente = ?";
            $params_logs[] = $filtro_dipendente;
            $where_conditions[] = "MONTH(t.timestamp) = ?";
            $params_logs[] = $filtro_mese;
            $where_conditions[] = "YEAR(t.timestamp) = ?";
            $params_logs[] = $filtro_anno;

            $sql .= " WHERE " . implode(' AND ', $where_conditions);
            $sql .= " ORDER BY t.timestamp ASC";
                    
            $stmt_logs = $pdo->prepare($sql);
            $stmt_logs->execute($params_logs);
            $timbrature_raw = $stmt_logs->fetchAll();

            // 4. Organizza i dati per la griglia (logica pivot)
            $giorni_nel_mese = cal_days_in_month(CAL_GREGORIAN, $filtro_mese, $filtro_anno);
            $timbrature_per_giorno = [];
            $max_colonne = 0;
            
            foreach ($timbrature_raw as $timestamp) {
                $giorno = (int)$timestamp['giorno'];
                $ora_format = str_pad($timestamp['ora'], 2, '0', STR_PAD_LEFT);
                $min_format = str_pad($timestamp['minuti'], 2, '0', STR_PAD_LEFT);
                
                $timbrature_per_giorno[$giorno][] = [
                    'orario' => $ora_format . ':' . $min_format,
                    'tipo' => $timestamp['tipo'],
                    'lat' => $timestamp['latitudine'],
                    'lon' => $timestamp['longitudine']
                ];
                $max_colonne = max($max_colonne, count($timbrature_per_giorno[$giorno]));
            }
            
            $response_data['grid_data'] = [
                'giorni_nel_mese' => $giorni_nel_mese,
                'max_colonne' => $max_colonne,
                'timbrature_per_giorno' => $timbrature_per_giorno
            ];
        } else {
            $response_data['grid_data'] = null; // Nessun filtro, nessun dato griglia
        }

        send_json_response(['success' => true, 'data' => $response_data]);
    } 
    else {
        send_json_response(['success' => false, 'message' => 'Ruolo utente non valido.'], 403);
    }
} catch (PDOException $e) {
    error_log('Errore API GetData: ' . $e->getMessage());
    send_json_response(['success' => false, 'message' => 'Errore durante il recupero dei dati.'], 500);
}
?>