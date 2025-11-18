<?php
/*
 * API Endpoint: Genera Excel
 * Genera un file .xls basato sui filtri, ricalcando la logica
 * del vecchio file 'generaExcel.php'.
 */

// **INIZIO CORREZIONE**
// Sostituisco il vecchio session_start() e il controllo
// con l'inclusione di config.php e il controllo di sessione corretto.
require_once 'config.php';

// Sicurezza: Solo gli admin possono accedere (controllo corretto)
if (empty($_SESSION['user_id']) || empty($_SESSION['user_ruolo']) || $_SESSION['user_ruolo'] !== 'admin') {
    http_response_code(403);
    die('Accesso negato. (Sessione non valida o ruolo non admin)');
}
// **FINE CORREZIONE**


// 1. Recupera i filtri dalla richiesta GET
$dipendente_id = $_GET['dipendente'] ?? null;
$mese = $_GET['mese'] ?? null;
$anno = $_GET['anno'] ?? null;

if (empty($dipendente_id) || empty($mese) || empty($anno)) {
    die('Parametri mancanti: dipendente, mese e anno sono obbligatori.');
}

// Array dei mesi per il titolo
$mesi_nomi = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile', 5 => 'Maggio', 6 => 'Giugno',
    7 => 'Luglio', 8 => 'Agosto', 9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];
$nome_mese = $mesi_nomi[(int)$mese] ?? 'MeseSconosciuto';

try {
    $pdo = connect_db();
    
    // 2. Recupera il nome del dipendente
    $stmt_user = $pdo->prepare('SELECT nome, cognome FROM utenti WHERE id = ?');
    $stmt_user->execute([$dipendente_id]);
    $utente = $stmt_user->fetch();
    $nomeDipendenteSel = $utente ? $utente['cognome'] . ' ' . $utente['nome'] : 'UtenteSconosciuto';

    // 3. Recupera i dati delle timbrature (logica identica a index2.php/generaExcel.php)
    $giorni_nel_mese = cal_days_in_month(CAL_GREGORIAN, $mese, $anno);
    
    $sql = "SELECT DAY(timestamp) as giorno, HOUR(timestamp) as ora, MINUTE(timestamp) as minuti, 
                   tipo, latitudine, longitudine
            FROM timbrature 
            WHERE id_utente = ? AND MONTH(timestamp) = ? AND YEAR(timestamp) = ?
            ORDER BY timestamp ASC";
            
    $stmt_logs = $pdo->prepare($sql);
    $stmt_logs->execute([$dipendente_id, $mese, $anno]);
    $timbrature_raw = $stmt_logs->fetchAll();

    // 4. Organizza i dati per giorno (come nel tuo vecchio script)
    $timbrature_per_giorno = [];
    $max_colonne = 0;
    
    foreach ($timbrature_raw as $timestamp) {
        $giorno = (int)$timestamp['giorno'];
        
        // Formattazione ora e minuti
        $timestamp['ora'] = str_pad($timestamp['ora'], 2, '0', STR_PAD_LEFT);
        $timestamp['minuti'] = str_pad($timestamp['minuti'], 2, '0', STR_PAD_LEFT);
        
        $timbrature_per_giorno[$giorno][] = $timestamp;
        $max_colonne = max($max_colonne, count($timbrature_per_giorno[$giorno]));
    }
    
    // 5. Imposta gli header per il download del file Excel
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: inline; filename=\"$nomeDipendenteSel $nome_mese-$anno.xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    // 6. Genera l'HTML della tabella (come nel tuo vecchio script)
    echo '<table border="1" style="border-collapse: collapse; font-family: Arial, sans-serif;">';
    echo '<thead>';
    echo '<tr style="background-color: #f0f5fa; font-weight: bold;">';
    echo '<th style="padding: 8px; border: 1px solid #000;">Giorno</th>';
    // Assicurati che ci sia almeno 1 colonna, anche se non ci sono timbrature
    $max_colonne = $max_colonne > 0 ? $max_colonne : 1; 
    for ($i = 1; $i <= $max_colonne; $i++) {
        echo '<th style="background-color: #d0ecff; padding: 8px; border: 1px solid #000;">Timbrata ' . $i . '</th>';
    }
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    for ($g = 1; $g <= $giorni_nel_mese; $g++) {
        echo '<tr>';
        echo '<td style="background-color: #f0f5fa; font-weight: bold; padding: 8px; border: 1px solid #000;">' . $g . '</td>';
        
        $timbrate = $timbrature_per_giorno[$g] ?? [];
        
        for ($i = 0; $i < $max_colonne; $i++) {
            $color = '#ffffff'; // Bianco di default
            $testo = '';
            
            if (isset($timbrate[$i])) {
                $color = ($timbrate[$i]['tipo'] == 'entrata') ? '#d4edda' : '#f8d7da'; // Verde/Rosso
                $testo = $timbrate[$i]['ora'] . ':' . $timbrate[$i]['minuti'];
            }
            
            echo '<td style="background-color: ' . $color . '; padding: 8px; border: 1px solid #000; text-align: center;">';
            echo $testo;
            echo '</td>';
        }
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    exit;

} catch (PDOException $e) {
    error_log('Errore Genera Excel: ' . $e->getMessage());
    die('Errore durante la generazione del report.');
}
?>