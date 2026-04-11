<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ══════════════════════════════════════════════════════════════════
 * RÉINITIALISATION SAISONNIÈRE — ESPERANCE H2O
 * PAGE ULTRA-CRITIQUE · Accès developer uniquement
 * 
 * Ce que fait cette page :
 *  1. Sélection Société + Ville
 *  2. Aperçu complet des données qui SERONT effacées
 *  3. Backup automatique AVANT tout effacement :
 *     - Tables backup : stock_movements_backup, orders_backup,
 *       order_items_backup, invoices_backup (si existe)
 *     - + Export SQL dans une table d'archives horodatée
 *  4. Saisie du nom de la nouvelle saison
 *  5. Double confirmation : code PIN + mot clé tapé
 *  6. Exécution transactionnelle :
 *     - Backup → Réinit stock_movements (type exit/entry/adjustment)
 *     - Réinit orders + order_items + invoices (par ville)
 *     - Conservation des mouvements 'initial' (stock de base)
 *     - Log de la réinitialisation dans reset_logs
 *  7. Rapport final détaillé
 * ══════════════════════════════════════════════════════════════════
 */

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';

use App\Core\DB;
use App\Core\Auth;
use App\Core\Middleware;

Auth::check();
Middleware::role(['developer']); // DEVELOPER ONLY

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");
$pdo->exec("SET SESSION wait_timeout=600");
$pdo->exec("SET SESSION interactive_timeout=600");

$user_id   = $_SESSION['user_id']   ?? 0;
$user_name = $_SESSION['username']  ?? 'Dev';

/* ── Créer tables nécessaires ── */
$pdo->exec("CREATE TABLE IF NOT EXISTS reset_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    company_id    INT NOT NULL,
    city_id       INT NOT NULL,
    season_name   VARCHAR(255) NOT NULL,
    reset_by      INT NOT NULL,
    reset_by_name VARCHAR(120) NOT NULL,
    stats         JSON,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_city (company_id, city_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS season_backups (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_id      INT NOT NULL,
    city_id         INT NOT NULL,
    season_name     VARCHAR(255) NOT NULL,
    backup_table    VARCHAR(120) NOT NULL,
    rows_backed_up  INT NOT NULL DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_company_city (company_id, city_id),
    INDEX idx_season (season_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Référentiels ── */
$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ── Sélection localisation ── */
$company_id = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);
$city_id    = (int)($_GET['city_id']    ?? $_POST['city_id']    ?? 0);
$cities = [];
if ($company_id) {
    $st = $pdo->prepare("SELECT id,name FROM cities WHERE company_id=? ORDER BY name");
    $st->execute([$company_id]);
    $cities = $st->fetchAll(PDO::FETCH_ASSOC);
}
$location_set = ($company_id > 0 && $city_id > 0);

/* ── Noms ── */
$company_name = '';
$city_name    = '';
if ($location_set) {
    $r = $pdo->prepare("SELECT name FROM companies WHERE id=?"); $r->execute([$company_id]); $company_name = $r->fetchColumn()??'';
    $r = $pdo->prepare("SELECT name FROM cities WHERE id=?");    $r->execute([$city_id]);    $city_name    = $r->fetchColumn()??'';
}

/* ══════════════════════════════════════════════════════
   HANDLER AJAX
══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    // Supprimer notices/warnings dans les réponses JSON
    error_reporting(E_ERROR | E_PARSE);
    // Intercepter toutes les erreurs PHP pour éviter de corrompre le JSON
    set_error_handler(function($errno, $errstr, $errfile, $errline){
        // Ignorer les notices (E_NOTICE=8, E_DEPRECATED=8192) — seulement erreurs fatales
        if($errno & (E_NOTICE | E_DEPRECATED | E_STRICT | E_USER_NOTICE)) return false;
        ob_clean();
        echo json_encode(['success'=>false,'message'=>"Erreur PHP [$errno]: $errstr (ligne $errline)"]);
        exit;
    });
    ob_start(); // Buffer pour attraper tout output parasite
    $ajax = $_POST['ajax_action'];

    /* ── preview : stats avant réinit ── */
    if ($ajax === 'preview') {
        try {
            $coid = (int)($_POST['company_id'] ?? 0);
            $ciid = (int)($_POST['city_id']    ?? 0);
            if (!$coid || !$ciid) throw new Exception('Localisation manquante');

            $stats = [];

            /* stock_movements */
            $s = $pdo->prepare("SELECT type, COUNT(*) nb, COALESCE(SUM(quantity),0) total_qty FROM stock_movements WHERE company_id=? AND city_id=? GROUP BY type");
            $s->execute([$coid,$ciid]); $stats['movements'] = $s->fetchAll(PDO::FETCH_ASSOC);
            $s2 = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE company_id=? AND city_id=?");
            $s2->execute([$coid,$ciid]); $stats['movements_total'] = (int)$s2->fetchColumn();

            /* orders */
            try {
                $s = $pdo->prepare("SELECT status, COUNT(*) nb, COALESCE(SUM(total_amount),0) total FROM orders WHERE company_id=? AND city_id=? GROUP BY status");
                $s->execute([$coid,$ciid]); $stats['orders'] = $s->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $oe){ $stats['orders'] = []; }
            $s2 = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total_amount),0) FROM orders WHERE company_id=? AND city_id=?");
            $s2->execute([$coid,$ciid]); $r2 = $s2->fetch(PDO::FETCH_NUM);
            $stats['orders_total'] = (int)$r2[0]; $stats['orders_amount'] = (float)$r2[1];

            /* order_items */
            $s = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE company_id=? AND city_id=?)");
            $s->execute([$coid,$ciid]); $stats['items_total'] = (int)$s->fetchColumn();

            /* invoices si existe */
            $inv_exists = $pdo->query("SHOW TABLES LIKE 'invoices'")->rowCount() > 0;
            if ($inv_exists) {
                try {
                    /* Détecter la colonne montant dynamiquement */
                    $inv_cols = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
                    $amt_col = null;
                    foreach(['total_amount','amount','total','montant','grand_total','invoice_amount'] as $candidate){
                        if(in_array($candidate, $inv_cols)){$amt_col=$candidate;break;}
                    }
                    if($amt_col){
                        $s = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(`$amt_col`),0) FROM invoices WHERE company_id=? AND city_id=?");
                    } else {
                        $s = $pdo->prepare("SELECT COUNT(*), 0 FROM invoices WHERE company_id=? AND city_id=?");
                    }
                    $s->execute([$coid,$ciid]); $ri = $s->fetch(PDO::FETCH_NUM);
                    $stats['invoices_total'] = (int)$ri[0]; $stats['invoices_amount'] = (float)$ri[1];
                } catch(Exception $ie) {
                    $stats['invoices_total'] = 0; $stats['invoices_amount'] = 0;
                }
            }

            /* arrivages si existe */
            $arr_exists = $pdo->query("SHOW TABLES LIKE 'arrivages'")->rowCount() > 0;
            if ($arr_exists) {
                $s = $pdo->prepare("SELECT COUNT(*) FROM arrivages WHERE company_id=? AND city_id=?");
                $s->execute([$coid,$ciid]); $stats['arrivages_total'] = (int)$s->fetchColumn();
            }

            /* stock actuel par produit (initial seulement) */
            $s = $pdo->prepare("SELECT p.name, SUM(CASE WHEN sm.type IN('initial','entry') THEN sm.quantity ELSE 0 END) - SUM(CASE WHEN sm.type='exit' THEN sm.quantity ELSE 0 END) AS stock_actuel,
                SUM(CASE WHEN sm.type='initial' THEN sm.quantity ELSE 0 END) AS stock_initial
                FROM stock_movements sm JOIN products p ON p.id=sm.product_id
                WHERE sm.company_id=? AND sm.city_id=? GROUP BY sm.product_id HAVING stock_actuel > 0 ORDER BY stock_actuel DESC LIMIT 20");
            $s->execute([$coid,$ciid]); $stats['products_stock'] = $s->fetchAll(PDO::FETCH_ASSOC);

            /* backups existants */
            $s = $pdo->prepare("SELECT season_name, backup_table, rows_backed_up, created_at FROM season_backups WHERE company_id=? AND city_id=? ORDER BY created_at DESC LIMIT 5");
            $s->execute([$coid,$ciid]); $stats['past_backups'] = $s->fetchAll(PDO::FETCH_ASSOC);

            ob_end_clean();
            echo json_encode(['success'=>true,'stats'=>$stats]);
        } catch(Exception $e) { ob_end_clean(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    /* ── execute : VRAIE réinit ── */
    if ($ajax === 'execute_reset') {
        set_time_limit(300);
        ob_start();
        try {
            $coid        = (int)($_POST['company_id']   ?? 0);
            $ciid        = (int)($_POST['city_id']      ?? 0);
            $season_name = trim($_POST['season_name']   ?? '');
            $confirm_pin = trim($_POST['confirm_pin']   ?? '');
            $confirm_word= strtoupper(trim($_POST['confirm_word'] ?? ''));

            if (!$coid || !$ciid)   throw new Exception('Localisation manquante');
            if (!$season_name)      throw new Exception('Nom de saison obligatoire');
            if ($confirm_pin !== '7531') throw new Exception('Code PIN incorrect');
            if ($confirm_word !== 'NOUVELLE SAISON') throw new Exception('Mot de confirmation incorrect — tapez exactement : NOUVELLE SAISON');

            $timestamp = date('Ymd_His');

            // ── Définir TOUS les noms de tables backup ──
            $bk_sm  = "backup_sm_{$coid}_{$ciid}_{$timestamp}";
            $bk_ord = "backup_orders_{$coid}_{$ciid}_{$timestamp}";
            $bk_oit = "backup_order_items_{$coid}_{$ciid}_{$timestamp}";
            $bk_inv = null;
            $bk_arr = null;
            $bk_ait = null;

            // ── PHASE 1 : Créer toutes les tables backup AVANT la transaction ──
            // (DDL cause un implicit commit en MySQL/MariaDB — pas compatible avec les transactions)
            $report = ['backed_up'=>[], 'deleted'=>[], 'season'=>$season_name, 'timestamp'=>$timestamp];

            /* Créer les tables backup (DDL = auto-commit implicite, hors transaction) */
            $pdo->exec("CREATE TABLE IF NOT EXISTS `$bk_sm` LIKE stock_movements");
            $pdo->exec("ALTER TABLE `$bk_sm` ADD COLUMN IF NOT EXISTS backup_season VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS backed_up_at DATETIME DEFAULT CURRENT_TIMESTAMP");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `$bk_ord` LIKE orders");
            $pdo->exec("ALTER TABLE `$bk_ord` ADD COLUMN IF NOT EXISTS backup_season VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS backed_up_at DATETIME DEFAULT CURRENT_TIMESTAMP");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `$bk_oit` LIKE order_items");
            $pdo->exec("ALTER TABLE `$bk_oit` ADD COLUMN IF NOT EXISTS backup_season VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS backed_up_at DATETIME DEFAULT CURRENT_TIMESTAMP");

            $inv_exists = $pdo->query("SHOW TABLES LIKE 'invoices'")->rowCount() > 0;
            $arr_exists = $pdo->query("SHOW TABLES LIKE 'arrivages'")->rowCount() > 0;
            $ait_exists = $arr_exists && $pdo->query("SHOW TABLES LIKE 'arrivage_items'")->rowCount() > 0;

            if ($inv_exists) {
                $bk_inv = "backup_invoices_{$coid}_{$ciid}_{$timestamp}";
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `$bk_inv` LIKE invoices");
                    $pdo->exec("ALTER TABLE `$bk_inv` ADD COLUMN IF NOT EXISTS backup_season VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS backed_up_at DATETIME DEFAULT CURRENT_TIMESTAMP");
                } catch(Exception $ie) { $inv_exists = false; }
            }
            if ($arr_exists) {
                $bk_arr = "backup_arrivages_{$coid}_{$ciid}_{$timestamp}";
                $bk_ait = "backup_arrivage_items_{$coid}_{$ciid}_{$timestamp}";
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `$bk_arr` LIKE arrivages");
                    $pdo->exec("ALTER TABLE `$bk_arr` ADD COLUMN IF NOT EXISTS backup_season VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS backed_up_at DATETIME DEFAULT CURRENT_TIMESTAMP");
                    if($ait_exists){
                        $pdo->exec("CREATE TABLE IF NOT EXISTS `$bk_ait` LIKE arrivage_items");
                        $pdo->exec("ALTER TABLE `$bk_ait` ADD COLUMN IF NOT EXISTS backup_season VARCHAR(255) DEFAULT NULL, ADD COLUMN IF NOT EXISTS backed_up_at DATETIME DEFAULT CURRENT_TIMESTAMP");
                    }
                } catch(Exception $ae) { $arr_exists = false; }
            }

            // ── PHASE 2 : Transaction pour INSERT (backup data) + DELETE ──
            $pdo->beginTransaction();

            /* ════ ÉTAPE 1 : BACKUP stock_movements ════ (tables créées en phase 1) */
            $nb = $pdo->prepare("INSERT INTO `$bk_sm` SELECT *, ?, NOW() FROM stock_movements WHERE company_id=? AND city_id=?");
            $nb->execute([$season_name, $coid, $ciid]);
            $rows_sm = $nb->rowCount();
            $pdo->prepare("INSERT INTO season_backups(company_id,city_id,season_name,backup_table,rows_backed_up) VALUES(?,?,?,?,?)")
                ->execute([$coid,$ciid,$season_name,$bk_sm,$rows_sm]);
            $report['backed_up'][] = ['table'=>$bk_sm,'rows'=>$rows_sm,'label'=>'Mouvements stock'];

            /* ════ ÉTAPE 2 : BACKUP orders + order_items ════ */
            $nb = $pdo->prepare("INSERT INTO `$bk_ord` SELECT *, ?, NOW() FROM orders WHERE company_id=? AND city_id=?");
            $nb->execute([$season_name,$coid,$ciid]);
            $rows_ord = $nb->rowCount();
            $pdo->prepare("INSERT INTO season_backups(company_id,city_id,season_name,backup_table,rows_backed_up) VALUES(?,?,?,?,?)")
                ->execute([$coid,$ciid,$season_name,$bk_ord,$rows_ord]);
            $report['backed_up'][] = ['table'=>$bk_ord,'rows'=>$rows_ord,'label'=>'Commandes'];

            /* order_items */
            $nb = $pdo->prepare("INSERT INTO `$bk_oit` SELECT oi.*, ?, NOW() FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.company_id=? AND o.city_id=?");
            $nb->execute([$season_name,$coid,$ciid]);
            $rows_oit = $nb->rowCount();
            $pdo->prepare("INSERT INTO season_backups(company_id,city_id,season_name,backup_table,rows_backed_up) VALUES(?,?,?,?,?)")
                ->execute([$coid,$ciid,$season_name,$bk_oit,$rows_oit]);
            $report['backed_up'][] = ['table'=>$bk_oit,'rows'=>$rows_oit,'label'=>'Articles commandes'];

            /* ════ ÉTAPE 3 : BACKUP invoices si existe ════ */
            if ($inv_exists) {
                try {
                    $nb = $pdo->prepare("INSERT INTO `$bk_inv` SELECT *, ?, NOW() FROM invoices WHERE company_id=? AND city_id=?");
                    $nb->execute([$season_name,$coid,$ciid]);
                    $rows_inv = $nb->rowCount();
                    $pdo->prepare("INSERT INTO season_backups(company_id,city_id,season_name,backup_table,rows_backed_up) VALUES(?,?,?,?,?)")
                        ->execute([$coid,$ciid,$season_name,$bk_inv,$rows_inv]);
                    $report['backed_up'][] = ['table'=>$bk_inv,'rows'=>$rows_inv,'label'=>'Factures'];
                } catch(Exception $ie) { /* invoices structure varies */ }
            }

            /* ════ ÉTAPE 4 : BACKUP arrivages si existe ════ */
            if ($arr_exists) {
                try {
                    $nb = $pdo->prepare("INSERT INTO `$bk_arr` SELECT *, ?, NOW() FROM arrivages WHERE company_id=? AND city_id=?");
                    $nb->execute([$season_name,$coid,$ciid]);
                    $rows_arr = $nb->rowCount();
                    $pdo->prepare("INSERT INTO season_backups(company_id,city_id,season_name,backup_table,rows_backed_up) VALUES(?,?,?,?,?)")
                        ->execute([$coid,$ciid,$season_name,$bk_arr,$rows_arr]);
                    $report['backed_up'][] = ['table'=>$bk_arr,'rows'=>$rows_arr,'label'=>'Arrivages'];
                    /* arrivage_items */
                    if($ait_exists){
                    $nb = $pdo->prepare("INSERT INTO `$bk_ait` SELECT ai.*, ?, NOW() FROM arrivage_items ai JOIN arrivages a ON a.id=ai.arrivage_id WHERE a.company_id=? AND a.city_id=?");
                    $nb->execute([$season_name,$coid,$ciid]);
                    $rows_ait = $nb->rowCount();
                    $pdo->prepare("INSERT INTO season_backups(company_id,city_id,season_name,backup_table,rows_backed_up) VALUES(?,?,?,?,?)")
                        ->execute([$coid,$ciid,$season_name,$bk_ait,$rows_ait]);
                    $report['backed_up'][] = ['table'=>$bk_ait,'rows'=>$rows_ait,'label'=>'Articles arrivages'];
                    } // end if($ait_exists)
                } catch(Exception $ae) {}
            }

            /* ════ ÉTAPE 5 : SUPPRESSION ════ */
            /* order_items d'abord (FK) */
            $d = $pdo->prepare("DELETE oi FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.company_id=? AND o.city_id=?");
            $d->execute([$coid,$ciid]); $report['deleted'][] = ['label'=>'Articles de commandes','rows'=>$d->rowCount()];

            /* orders */
            $d = $pdo->prepare("DELETE FROM orders WHERE company_id=? AND city_id=?");
            $d->execute([$coid,$ciid]); $report['deleted'][] = ['label'=>'Commandes','rows'=>$d->rowCount()];

            /* invoices */
            if ($inv_exists) {
                try {
                    $d = $pdo->prepare("DELETE FROM invoices WHERE company_id=? AND city_id=?");
                    $d->execute([$coid,$ciid]); $report['deleted'][] = ['label'=>'Factures','rows'=>$d->rowCount()];
                } catch(Exception $ie) {}
            }

            /* arrivage_items + arrivages */
            if ($arr_exists) {
                try {
                    $d = $pdo->prepare("DELETE ai FROM arrivage_items ai JOIN arrivages a ON a.id=ai.arrivage_id WHERE a.company_id=? AND a.city_id=?");
                    $d->execute([$coid,$ciid]); $report['deleted'][] = ['label'=>'Articles arrivages','rows'=>$d->rowCount()];
                    $d = $pdo->prepare("DELETE FROM arrivages WHERE company_id=? AND city_id=?");
                    $d->execute([$coid,$ciid]); $report['deleted'][] = ['label'=>'Arrivages','rows'=>$d->rowCount()];
                } catch(Exception $ae) {}
            }

            /* stock_movements : supprimer exit, entry, adjustment — garder initial */
            $d = $pdo->prepare("DELETE FROM stock_movements WHERE company_id=? AND city_id=? AND type IN('exit','entry','adjustment')");
            $d->execute([$coid,$ciid]); $report['deleted'][] = ['label'=>'Mouvements stock (exit/entry/adjustment)','rows'=>$d->rowCount()];

            /* ════ ÉTAPE 6 : STOCK INITIAL NOUVELLE SAISON ════ */
            /* Recalculer le stock actuel après les deletions et créer 1 mouvement 'initial' par produit */
            /* Le stock actuel = ce qui restait = stock_movements initial encore présents */
            /* On va mettre à jour les mouvements 'initial' avec le stock réel au moment de la clôture */
            /* D'abord récupérer le stock final (depuis le backup) */
            $st_backup = $pdo->query("SELECT product_id, SUM(CASE WHEN type IN('initial','entry') THEN quantity ELSE 0 END) - SUM(CASE WHEN type='exit' THEN quantity ELSE 0 END) AS stock_final
                FROM `$bk_sm` WHERE company_id=$coid AND city_id=$ciid GROUP BY product_id HAVING stock_final > 0");
            $final_stocks = $st_backup->fetchAll(PDO::FETCH_ASSOC);

            /* Supprimer les anciens 'initial' */
            $pdo->prepare("DELETE FROM stock_movements WHERE company_id=? AND city_id=? AND type='initial'")->execute([$coid,$ciid]);

            /* Insérer nouveau 'initial' = stock final de la saison précédente */
            $ins = $pdo->prepare("INSERT INTO stock_movements (product_id,reference,company_id,city_id,type,quantity,movement_date) VALUES(?,?,?,?,'initial',?,NOW())");
            $new_initials = 0;
            foreach ($final_stocks as $fs) {
                if ((int)$fs['stock_final'] > 0) {
                    $ins->execute([$fs['product_id'],"INIT-SAISON-$season_name",$coid,$ciid,(int)$fs['stock_final']]);
                    $new_initials++;
                }
            }
            $report['new_initials'] = $new_initials;
            $report['deleted'][] = ['label'=>'Anciens stocks initiaux (réinitialisés)','rows'=>$new_initials];

            /* ════ ÉTAPE 7 : LOG ════ */
            $pdo->prepare("INSERT INTO reset_logs(company_id,city_id,season_name,reset_by,reset_by_name,stats) VALUES(?,?,?,?,?,?)")
                ->execute([$coid,$ciid,$season_name,$user_id,$user_name,json_encode($report)]);

            $pdo->commit();
            ob_end_clean();
            $report['success'] = true;
            $report['message'] = "Réinitialisation de la saison « $season_name » effectuée avec succès.";
            echo json_encode($report);
        } catch(Exception $e) {
            if ($pdo->inTransaction()) try { $pdo->rollBack(); } catch(Exception $rb){}
            ob_end_clean();
            echo json_encode(['success'=>false,'message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);
        }
        exit;
    }
    echo json_encode(['success'=>false,'message'=>'Action inconnue']); exit;
}

/* ── Historique réinits ── */
$history = [];
if ($location_set) {
    $st = $pdo->prepare("SELECT rl.*, co.name company_name, ci.name city_name FROM reset_logs rl LEFT JOIN companies co ON co.id=rl.company_id LEFT JOIN cities ci ON ci.id=rl.city_id WHERE rl.company_id=? AND rl.city_id=? ORDER BY rl.created_at DESC LIMIT 10");
    $st->execute([$company_id, $city_id]);
    $history = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Réinitialisation Saisonnière — ESPERANCE H2O</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ════════════════════════════════════════════════════════════════
   RÉINITIALISATION SAISONNIÈRE — Design : DANGER/CRITIQUE
   Rouge industriel · Bebas Neue · DM Mono · Style terminal
════════════════════════════════════════════════════════════════ */
:root{
    --bg:#080305;--surf:#0f0308;--card:#140508;--card2:#1a060a;
    --bord:rgba(255,40,70,0.12);--bord2:rgba(255,40,70,0.30);--bord3:rgba(255,40,70,0.55);
    --red:#ff2846;--red2:#cc1030;--orange:#ff6b00;--gold:#ffd60a;
    --green:#00e87a;--cyan:#00d4ff;--blue:#0a84ff;
    --muted:#6b2535;--text:#ffe8ec;--text2:#cc8898;
    --shadow:0 0 40px rgba(255,40,70,0.18),0 8px 32px rgba(0,0,0,0.8);
    --glow-red:0 0 28px rgba(255,40,70,0.55);
    --glow-green:0 0 28px rgba(0,232,122,0.45);
    --fh:'Bebas Neue',sans-serif;
    --fb:'DM Mono','Courier New',monospace;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:var(--fb);font-weight:500;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden;}

/* ── BACKGROUND ── */
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background:
        radial-gradient(ellipse 70% 50% at 10% 10%, rgba(255,40,70,0.06) 0%,transparent 60%),
        radial-gradient(ellipse 55% 40% at 90% 90%, rgba(255,107,0,0.05) 0%,transparent 60%);}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background-image:linear-gradient(rgba(255,40,70,0.018) 1px,transparent 1px),linear-gradient(90deg,rgba(255,40,70,0.018) 1px,transparent 1px);
    background-size:55px 55px;}
canvas#bg-canvas{position:fixed;inset:0;z-index:0;pointer-events:none;opacity:.3;}

.wrap{position:relative;z-index:1;max-width:1200px;margin:0 auto;padding:20px 16px 80px;}

/* ── SCROLLBAR ── */
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-thumb{background:rgba(255,40,70,.3);border-radius:4px;}
::-webkit-scrollbar-thumb:hover{background:var(--red);}

/* ── KEYFRAMES ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
@keyframes scanH{0%{left:-100%}100%{left:110%}}
@keyframes scanV{0%{top:-100%}100%{top:110%}}
@keyframes pulse-red{0%,100%{box-shadow:0 0 14px rgba(255,40,70,.3)}50%{box-shadow:0 0 48px rgba(255,40,70,.75),0 0 90px rgba(255,40,70,.3)}}
@keyframes blink{0%,100%{opacity:1}49%{opacity:1}50%,98%{opacity:0}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes progress{from{width:0}to{width:100%}}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
@keyframes shake{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-8px)}40%,80%{transform:translateX(8px)}}
@keyframes slideIn{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:translateX(0)}}
@keyframes zoomIn{from{opacity:0;transform:scale(.85)}to{opacity:1;transform:scale(1)}}
@keyframes countUp{from{transform:scale(0.6);opacity:0}to{transform:scale(1);opacity:1}}
@keyframes successBurst{0%{transform:scale(0);opacity:1}100%{transform:scale(3);opacity:0}}

/* ── TOPBAR ── */
.topbar{
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;
    background:rgba(20,5,8,0.96);border:1px solid var(--bord2);
    border-radius:18px;padding:16px 26px;margin-bottom:22px;
    box-shadow:var(--shadow);position:relative;overflow:hidden;
    backdrop-filter:blur(20px);
}
.topbar::before{content:'';position:absolute;top:0;left:-100%;width:30%;height:1px;
    background:linear-gradient(90deg,transparent,var(--red),transparent);animation:scanH 5s linear infinite;}
.topbar::after{content:'';position:absolute;bottom:0;left:0;right:0;height:1px;
    background:linear-gradient(90deg,transparent,rgba(255,40,70,.3),transparent);}
.brand{display:flex;align-items:center;gap:14px;}
.brand-ico{
    width:52px;height:52px;border-radius:14px;
    background:linear-gradient(135deg,var(--red),var(--orange));
    display:flex;align-items:center;justify-content:center;font-size:26px;
    box-shadow:var(--glow-red);animation:pulse-red 3.5s ease-in-out infinite;flex-shrink:0;
}
.brand-txt h1{font-family:var(--fh);font-size:22px;letter-spacing:2px;color:var(--text);line-height:1;}
.brand-txt p{font-size:9px;font-weight:700;color:var(--red);letter-spacing:3px;text-transform:uppercase;margin-top:4px;}
.danger-badge{
    display:flex;align-items:center;gap:8px;padding:10px 20px;border-radius:30px;
    background:rgba(255,40,70,.08);border:1.5px solid var(--bord3);
    font-family:var(--fh);font-size:16px;letter-spacing:2px;color:var(--red);
    text-shadow:0 0 14px rgba(255,40,70,.5);
}
.danger-badge i{animation:pulse-red 2s infinite;}
.user-pill{display:flex;align-items:center;gap:9px;padding:9px 16px;border-radius:24px;
    background:rgba(255,40,70,.07);border:1px solid var(--bord2);font-size:11px;font-weight:700;color:var(--text2);}

/* ── ALERT BANNER ── */
.alert-banner{
    background:linear-gradient(135deg,rgba(255,40,70,.1),rgba(255,107,0,.08));
    border:2px solid var(--bord3);border-radius:16px;padding:20px 24px;margin-bottom:20px;
    display:flex;align-items:flex-start;gap:16px;
    box-shadow:0 0 30px rgba(255,40,70,.12);
}
.alert-banner i{font-size:28px;color:var(--red);flex-shrink:0;margin-top:2px;animation:float 3s ease-in-out infinite;}
.alert-banner h2{font-family:var(--fh);font-size:18px;letter-spacing:1px;color:var(--red);margin-bottom:6px;}
.alert-banner p{font-size:11px;font-weight:600;color:var(--text2);line-height:1.8;}
.alert-banner ul{list-style:none;margin-top:8px;}
.alert-banner ul li{font-size:11px;font-weight:600;color:var(--text2);padding:2px 0;display:flex;align-items:center;gap:8px;}
.alert-banner ul li::before{content:'▸';color:var(--red);flex-shrink:0;}

/* ── STEP INDICATOR ── */
.steps-wrap{display:flex;align-items:center;gap:0;margin-bottom:24px;overflow-x:auto;padding-bottom:4px;}
.step-item{display:flex;align-items:center;gap:0;flex-shrink:0;}
.step-dot-w{display:flex;flex-direction:column;align-items:center;gap:5px;}
.step-dot{
    width:36px;height:36px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-family:var(--fh);font-size:16px;letter-spacing:0;
    border:2px solid var(--bord);background:var(--card);color:var(--muted);
    transition:all .4s;
}
.step-dot.done{background:rgba(0,232,122,.12);border-color:var(--green);color:var(--green);box-shadow:0 0 16px rgba(0,232,122,.3);}
.step-dot.active{background:rgba(255,40,70,.12);border-color:var(--red);color:var(--red);box-shadow:var(--glow-red);animation:pulse-red 2s infinite;}
.step-lbl{font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;text-align:center;white-space:nowrap;}
.step-lbl.active{color:var(--red);}
.step-lbl.done{color:var(--green);}
.step-line{width:50px;height:2px;background:var(--bord);margin:0 6px;flex-shrink:0;transition:background .4s;}
.step-line.done{background:var(--green);}

/* ── PANELS ── */
.panel-step{display:none;animation:fadeUp .35s ease;}
.panel-step.show{display:block;}

/* ── GLASS CARD ── */
.gcard{
    background:rgba(20,5,8,0.88);border:1px solid var(--bord2);border-radius:20px;
    overflow:hidden;margin-bottom:18px;
    backdrop-filter:blur(18px);box-shadow:var(--shadow);
    transition:border-color .3s;
}
.gcard:hover{border-color:var(--bord3);}
.gh{
    display:flex;align-items:center;gap:12px;padding:15px 22px;
    border-bottom:1px solid rgba(255,255,255,.04);background:rgba(0,0,0,.25);
    position:relative;overflow:hidden;
}
.gh::after{content:'';position:absolute;bottom:0;left:0;right:0;height:1px;
    background:linear-gradient(90deg,transparent,var(--red),transparent);opacity:.35;}
.gh-ico{width:34px;height:34px;border-radius:10px;background:rgba(255,40,70,.1);border:1px solid var(--bord2);
    display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--red);flex-shrink:0;}
.gh-title{font-family:var(--fh);font-size:16px;letter-spacing:1.5px;color:var(--text);}
.gb{padding:22px;}

/* ── LOCATION SELECTOR ── */
.loc-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;}
.fg label{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.3px;display:block;margin-bottom:7px;}
.fg select,.fg input,.fg textarea{
    width:100%;padding:11px 14px;
    background:rgba(0,0,0,.4);border:1.5px solid var(--bord);
    border-radius:11px;color:var(--text);font-family:var(--fb);font-size:13px;font-weight:600;
    appearance:none;transition:border-color .28s,box-shadow .28s;
}
.fg select:focus,.fg input:focus,.fg textarea:focus{
    outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(255,40,70,.08);
}
.fg select option{background:#140508;color:var(--text);}
.fg input::placeholder{color:var(--muted);}

/* ── STATS PREVIEW ── */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:16px;}
.stat-box{
    background:rgba(255,40,70,.04);border:1px solid var(--bord2);border-radius:14px;
    padding:16px;text-align:center;transition:all .3s;
}
.stat-box:hover{border-color:var(--bord3);background:rgba(255,40,70,.07);}
.stat-val{font-family:var(--fh);font-size:34px;letter-spacing:1px;color:var(--red);line-height:1;margin-bottom:4px;}
.stat-lbl{font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;}
.stat-sub{font-size:10px;font-weight:600;color:var(--text2);margin-top:4px;}

/* ── TABLE PREVIEW ── */
.tbl{width:100%;border-collapse:collapse;}
.tbl th{font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;
    padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.05);text-align:left;background:rgba(0,0,0,.2);}
.tbl td{font-size:12px;font-weight:600;color:var(--text2);padding:11px 12px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle;}
.tbl tbody tr:hover{background:rgba(255,40,70,.03);}
.tbl tr:last-child td{border-bottom:none;}

/* ── BADGE ── */
.bdg{font-size:9px;font-weight:700;padding:3px 9px;border-radius:9px;display:inline-flex;align-items:center;gap:4px;}
.bdg-r{background:rgba(255,40,70,.12);color:var(--red);}
.bdg-g{background:rgba(0,232,122,.12);color:var(--green);}
.bdg-o{background:rgba(255,107,0,.12);color:var(--orange);}
.bdg-gold{background:rgba(255,214,10,.12);color:var(--gold);}
.bdg-c{background:rgba(0,212,255,.12);color:var(--cyan);}
.bdg-b{background:rgba(10,132,255,.12);color:var(--blue);}

/* ── BUTTONS ── */
.btn{
    display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:11px;
    border:1.5px solid transparent;cursor:pointer;font-family:var(--fb);font-size:10px;font-weight:700;
    letter-spacing:.8px;text-transform:uppercase;transition:all .28s;text-decoration:none;white-space:nowrap;
}
.btn:active{transform:scale(.97);}
.btn-r{background:rgba(255,40,70,.08);border-color:rgba(255,40,70,.3);color:var(--red);}
.btn-r:hover{background:var(--red);color:#fff;box-shadow:var(--glow-red);}
.btn-g{background:rgba(0,232,122,.08);border-color:rgba(0,232,122,.3);color:var(--green);}
.btn-g:hover{background:var(--green);color:#001a0a;box-shadow:var(--glow-green);}
.btn-muted{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.1);color:var(--text2);}
.btn-muted:hover{background:rgba(255,255,255,.08);}
.btn-gold{background:rgba(255,214,10,.08);border-color:rgba(255,214,10,.3);color:var(--gold);}
.btn-gold:hover{background:var(--gold);color:#000;}
.btn-full{width:100%;justify-content:center;padding:13px;}
.btn-DESTROY{
    background:linear-gradient(135deg,var(--red),var(--red2));color:#fff;border:none;
    font-family:var(--fh);font-size:15px;letter-spacing:2px;padding:16px;
    box-shadow:0 4px 24px rgba(255,40,70,.4);
}
.btn-DESTROY:hover{box-shadow:0 6px 40px rgba(255,40,70,.65);transform:translateY(-2px);}
.btn-DESTROY:disabled{opacity:.4;cursor:not-allowed;transform:none;}

/* ── CONFIRMATION FORM ── */
.conf-box{
    background:rgba(255,40,70,.04);border:2px solid var(--bord3);border-radius:18px;
    padding:28px;margin-bottom:20px;position:relative;overflow:hidden;
}
.conf-box::before{content:'';position:absolute;top:0;left:-100%;width:35%;height:1px;
    background:linear-gradient(90deg,transparent,var(--red),transparent);animation:scanH 3.5s linear infinite;}
.conf-title{font-family:var(--fh);font-size:20px;letter-spacing:2px;color:var(--red);margin-bottom:18px;
    display:flex;align-items:center;gap:10px;}
.pin-input{
    font-family:var(--fh);font-size:36px;letter-spacing:8px;text-align:center;
    background:rgba(0,0,0,.5);border:2px solid var(--bord2);border-radius:14px;
    color:var(--red);padding:14px;width:100%;
    transition:border-color .28s;
}
.pin-input:focus{outline:none;border-color:var(--red);box-shadow:var(--glow-red);}
.word-input{
    font-family:var(--fh);font-size:22px;letter-spacing:3px;text-align:center;
    background:rgba(0,0,0,.5);border:2px solid var(--bord2);border-radius:14px;
    color:var(--red);padding:14px;width:100%;
    transition:border-color .28s;
}
.word-input:focus{outline:none;border-color:var(--red);box-shadow:var(--glow-red);}
.word-input.valid{border-color:var(--green);color:var(--green);}
.hint-txt{font-size:11px;font-weight:700;color:var(--muted);text-align:center;margin-top:7px;}

/* ── WHAT HAPPENS ── */
.timeline{list-style:none;}
.timeline li{display:flex;gap:14px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.04);}
.timeline li:last-child{border-bottom:none;}
.tl-num{width:26px;height:26px;border-radius:50%;background:rgba(255,40,70,.1);border:1px solid var(--bord2);
    display:flex;align-items:center;justify-content:center;font-family:var(--fh);font-size:13px;color:var(--red);flex-shrink:0;margin-top:1px;}
.tl-title{font-size:12px;font-weight:700;color:var(--text);margin-bottom:3px;}
.tl-sub{font-size:11px;font-weight:600;color:var(--muted);line-height:1.6;}

/* ── PROGRESS ── */
.prog-wrap{background:rgba(0,0,0,.4);border-radius:8px;overflow:hidden;margin:14px 0;height:8px;}
.prog-bar{height:100%;background:linear-gradient(90deg,var(--red),var(--orange));border-radius:8px;
    animation:progress 3s ease-in-out;box-shadow:0 0 12px rgba(255,40,70,.5);}

/* ── LOADING OVERLAY ── */
.loading-overlay{
    display:none;position:fixed;inset:0;z-index:2000;
    background:rgba(8,3,5,.96);backdrop-filter:blur(20px);
    flex-direction:column;align-items:center;justify-content:center;gap:20px;
}
.loading-overlay.show{display:flex;}
.ld-ico{font-size:70px;animation:float 2s ease-in-out infinite;}
.ld-title{font-family:var(--fh);font-size:32px;letter-spacing:3px;color:var(--red);}
.ld-sub{font-size:13px;font-weight:700;color:var(--text2);text-align:center;line-height:1.8;}
.ld-steps{display:flex;flex-direction:column;gap:8px;margin-top:10px;min-width:340px;}
.ld-step{display:flex;align-items:center;gap:12px;padding:10px 16px;border-radius:11px;
    background:rgba(255,40,70,.04);border:1px solid var(--bord);font-size:11px;font-weight:700;color:var(--muted);
    transition:all .3s;}
.ld-step.active{background:rgba(255,40,70,.1);border-color:var(--bord2);color:var(--red);}
.ld-step.done{background:rgba(0,232,122,.05);border-color:rgba(0,232,122,.2);color:var(--green);}
.ld-step i{width:18px;text-align:center;flex-shrink:0;}
.sp{width:16px;height:16px;border:2px solid rgba(255,255,255,.15);border-top-color:currentColor;
    border-radius:50%;animation:spin .7s linear infinite;display:inline-block;flex-shrink:0;}

/* ── SUCCESS ── */
.success-screen{display:none;text-align:center;padding:28px;}
.success-screen.show{display:block;animation:zoomIn .5s ease;}
.ss-ico{font-size:88px;display:block;margin-bottom:20px;animation:float 3s ease-in-out infinite;}
.ss-title{font-family:var(--fh);font-size:36px;letter-spacing:3px;color:var(--green);margin-bottom:10px;}
.ss-sub{font-size:13px;font-weight:600;color:var(--text2);line-height:1.8;margin-bottom:24px;}

/* ── BACKUP TABLE LIST ── */
.bk-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;
    padding:10px 14px;border-radius:11px;border:1px solid var(--bord);background:rgba(0,0,0,.2);margin-bottom:7px;}
.bk-name{font-size:11px;font-weight:700;color:var(--cyan);font-family:var(--fb);}
.bk-rows{font-size:10px;font-weight:700;color:var(--green);}
.bk-date{font-size:9px;color:var(--muted);}

/* ── HISTORY LOG ── */
.hist-row{display:flex;align-items:flex-start;gap:14px;padding:14px 0;border-bottom:1px solid rgba(255,255,255,.04);}
.hist-dot{width:10px;height:10px;border-radius:50%;background:var(--red);flex-shrink:0;margin-top:4px;box-shadow:0 0 8px var(--red);}
.hist-season{font-family:var(--fh);font-size:16px;letter-spacing:1px;color:var(--text);}
.hist-meta{font-size:10px;font-weight:700;color:var(--muted);margin-top:3px;}
.hist-ts{font-size:9px;color:var(--muted);margin-top:2px;}

/* ── SECTION TITLE ── */
.sec-t{display:flex;align-items:center;gap:14px;margin-bottom:14px;}
.sec-t h3{font-family:var(--fh);font-size:16px;letter-spacing:1.5px;color:var(--text);}
.sec-t::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--bord2),transparent);}
.sec-line{width:4px;height:20px;background:linear-gradient(to bottom,var(--red),var(--orange));border-radius:4px;flex-shrink:0;}

/* ── TERM BOX ── */
.term-box{background:rgba(0,0,0,.55);border:1px solid rgba(0,232,122,.15);border-radius:12px;padding:16px;
    font-family:var(--fb);font-size:11px;color:rgba(0,232,122,.7);line-height:2;max-height:200px;overflow-y:auto;}
.term-box span.red{color:var(--red);}
.term-box span.gold{color:var(--gold);}
.term-box span.dim{color:var(--muted);}
.cursor-blink::after{content:'█';animation:blink 1.2s step-end infinite;color:var(--green);margin-left:2px;}

/* ── TOAST ── */
.tstack{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.toast{background:rgba(20,5,8,.97);border:1px solid var(--bord2);border-radius:12px;padding:12px 16px;min-width:230px;
    display:flex;align-items:center;gap:10px;box-shadow:0 8px 32px rgba(0,0,0,.7);animation:slideIn .4s ease;backdrop-filter:blur(20px);}
.toast.out{animation:none;opacity:0;transition:opacity .3s;}
.tico{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.ttxt strong{font-size:10px;font-weight:700;display:block;}
.ttxt span{font-size:9px;font-weight:600;color:var(--muted);}

/* ── RESPONSIVE ── */
@media(max-width:768px){
    .loc-grid{grid-template-columns:1fr;}
    .stat-grid{grid-template-columns:1fr 1fr;}
    .topbar{padding:14px 18px;}
    .steps-wrap{gap:2px;}
    .step-line{width:28px;}
}
@media(max-width:480px){
    .stat-grid{grid-template-columns:1fr;}
    .brand-txt h1{font-size:18px;}
}
</style>
</head>
<body>
<canvas id="bg-canvas"></canvas>

<div class="wrap">

<!-- ════ TOPBAR ════ -->
<div class="topbar">
    <div class="brand">
        <div class="brand-ico">⚠️</div>
        <div class="brand-txt">
            <h1>RÉINITIALISATION SAISONNIÈRE</h1>
            <p>ESPERANCE H2O · Zone sécurisée · Developeur seulement</p>
        </div>
    </div>
    <div class="danger-badge"><i class="fas fa-shield-halved"></i> ACCÈS RESTREINT</div>
    <div class="user-pill"><i class="fas fa-user-secret" style="color:var(--red)"></i> <?= htmlspecialchars($user_name) ?> · DEVELOPER</div>
</div>

<!-- ════ ALERTE CRITIQUE ════ -->
<div class="alert-banner">
    <i class="fas fa-triangle-exclamation"></i>
    <div>
        <h2>⚠ OPÉRATION IRRÉVERSIBLE — LIRE ATTENTIVEMENT AVANT DE CONTINUER</h2>
        <p>Cette page vous permet de clore une saison et d'en démarrer une nouvelle pour une magasin spécifique.</p>
        <ul>
            <li>Un <strong>backup complet</strong> est créé automatiquement AVANT toute suppression</li>
            <li>Tous les <strong>mouvements de stock</strong> (entrées/sorties/ajustements) seront effacés</li>
            <li>Toutes les <strong>commandes, factures et arrivages</strong> de la ville seront archivés puis effacés</li>
            <li>Le <strong>stock actuel au moment de la clôture</strong> devient le stock initial de la nouvelle saison</li>
            <li>Les données des <strong>autres villes</strong> ne sont pas affectées</li>
            <li>Un <strong>code PIN + mot de confirmation</strong> est requis avant exécution</li>
        </ul>
    </div>
</div>

<!-- ════ STEP INDICATOR ════ -->
<div class="steps-wrap" id="steps-wrap">
    <div class="step-item">
        <div class="step-dot-w"><div class="step-dot active" id="sdot-1">1</div><div class="step-lbl active" id="slbl-1">Localisation</div></div>
    </div>
    <div class="step-line" id="sline-1"></div>
    <div class="step-item">
        <div class="step-dot-w"><div class="step-dot" id="sdot-2">2</div><div class="step-lbl" id="slbl-2">Aperçu</div></div>
    </div>
    <div class="step-line" id="sline-2"></div>
    <div class="step-item">
        <div class="step-dot-w"><div class="step-dot" id="sdot-3">3</div><div class="step-lbl" id="slbl-3">Saison</div></div>
    </div>
    <div class="step-line" id="sline-3"></div>
    <div class="step-item">
        <div class="step-dot-w"><div class="step-dot" id="sdot-4">4</div><div class="step-lbl" id="slbl-4">Confirmation</div></div>
    </div>
    <div class="step-line" id="sline-4"></div>
    <div class="step-item">
        <div class="step-dot-w"><div class="step-dot" id="sdot-5">✓</div><div class="step-lbl" id="slbl-5">Exécution</div></div>
    </div>
</div>

<!-- ══════════════════════════
     ÉTAPE 1 : LOCALISATION
══════════════════════════ -->
<div class="panel-step show" id="step-1">
<div class="gcard">
    <div class="gh"><div class="gh-ico"><i class="fas fa-map-marker-alt"></i></div><div class="gh-title">ÉTAPE 1 — SÉLECTION DU MAGASIN</div></div>
    <div class="gb">
        <p style="font-size:11px;font-weight:700;color:var(--text2);margin-bottom:18px">Sélectionnez la société et la et le magasin dont vous souhaitez réinitialiser la saison. Seules les données de cette ville seront affectées.</p>
        <div class="loc-grid">
            <div class="fg">
                <label><i class="fas fa-building"></i> Société *</label>
                <select id="sel-company" onchange="onCompanyChange()">
                    <option value="">— Sélectionner —</option>
                    <?php foreach($companies as $c): ?>
                    <option value="<?=$c['id']?>" <?=$company_id==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fas fa-city"></i> Ville / Magasin *</label>
                <select id="sel-city" <?=!$company_id?'disabled':''?>>
                    <option value="">— Sélectionner —</option>
                    <?php foreach($cities as $city): ?>
                    <option value="<?=$city['id']?>" <?=$city_id==$city['id']?'selected':''?>><?=htmlspecialchars($city['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:12px;margin-top:6px">
            <button onclick="goPreview()" class="btn btn-r"><i class="fas fa-arrow-right"></i> VOIR L'APERÇU DES DONNÉES</button>
        </div>
    </div>
</div>

<?php if(!empty($history)): ?>
<div class="gcard">
    <div class="gh"><div class="gh-ico"><i class="fas fa-history"></i></div><div class="gh-title">HISTORIQUE DES RÉINITIALISATIONS</div></div>
    <div class="gb">
    <?php foreach($history as $h): $stats=json_decode($h['stats']??'{}',true)??[]; ?>
    <div class="hist-row">
        <div class="hist-dot"></div>
        <div>
            <div class="hist-season">📅 <?=htmlspecialchars($h['season_name'])?></div>
            <div class="hist-meta">
                <span class="bdg bdg-c"><?=htmlspecialchars($h['company_name']??'')?></span>
                <span class="bdg bdg-b" style="margin-left:4px"><?=htmlspecialchars($h['city_name']??'')?></span>
                &nbsp;— Par <strong style="color:var(--text)"><?=htmlspecialchars($h['reset_by_name'])?></strong>
            </div>
            <div class="hist-ts"><i class="fas fa-clock" style="font-size:8px"></i> <?=date('d/m/Y à H:i',strtotime($h['created_at']))?></div>
            <?php if(!empty($stats['backed_up'])): ?>
            <div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:5px">
                <?php foreach($stats['backed_up'] as $bk): ?>
                <span class="bdg bdg-g"><i class="fas fa-database"></i> <?=htmlspecialchars($bk['label'])?> (<?=$bk['rows']?> lignes)</span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
</div><!-- /step-1 -->

<!-- ══════════════════════════
     ÉTAPE 2 : APERÇU
══════════════════════════ -->
<div class="panel-step" id="step-2">
<div class="gcard" id="preview-loading" style="display:none">
    <div class="gb" style="text-align:center;padding:40px">
        <div class="sp" style="width:40px;height:40px;border-width:3px;color:var(--red);margin:0 auto 16px"></div>
        <div style="font-family:var(--fh);font-size:20px;color:var(--red);letter-spacing:2px">ANALYSE EN COURS…</div>
    </div>
</div>
<div id="preview-content"></div>
<div style="display:flex;gap:12px;flex-wrap:wrap">
    <button onclick="goStep(1)" class="btn btn-muted"><i class="fas fa-arrow-left"></i> Retour</button>
    <button onclick="goStep(3)" class="btn btn-r" id="btn-go-3"><i class="fas fa-arrow-right"></i> CONTINUER — NOMMER LA SAISON</button>
</div>
</div><!-- /step-2 -->

<!-- ══════════════════════════
     ÉTAPE 3 : NOM SAISON
══════════════════════════ -->
<div class="panel-step" id="step-3">
<div class="gcard">
    <div class="gh"><div class="gh-ico"><i class="fas fa-tag"></i></div><div class="gh-title">ÉTAPE 3 — NOM DE LA NOUVELLE SAISON</div></div>
    <div class="gb">
        <p style="font-size:11px;font-weight:700;color:var(--text2);margin-bottom:18px">Ce nom apparaîtra dans tous les backups et logs. Choisissez un nom clair et unique.</p>
        <div class="fg" style="max-width:520px;margin-bottom:8px">
            <label>Nom de la nouvelle saison *</label>
            <input type="text" id="season-name" placeholder="Ex: SAISON 2026 · S2-ABIDJAN · INVENTAIRE-FEVRIER-2026" maxlength="120">
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
            <?php
            $yr = date('Y');
            $suggestions = ["SAISON $yr","S1-$yr","S2-$yr","INVENTAIRE ".date('M Y'),"NOUVELLE SAISON $yr"];
            foreach($suggestions as $s):
            ?><button onclick="document.getElementById('season-name').value='<?=$s?>'" class="btn btn-muted" style="font-size:9px;padding:6px 12px"><?=$s?></button><?php endforeach;?>
        </div>

        <!-- CE QUI SE PASSERA -->
        <div class="sec-t"><div class="sec-line"></div><h3>CE QUI VA SE PASSER</h3></div>
        <ul class="timeline">
            <li><div class="tl-num">1</div><div><div class="tl-title">💾 Backup complet automatique</div><div class="tl-sub">Toutes les données sont copiées dans des tables horodatées avant toute suppression. Aucune perte possible.</div></div></li>
            <li><div class="tl-num">2</div><div><div class="tl-title">🗑 Effacement des transactions</div><div class="tl-sub">Commandes, articles de commandes, factures, arrivages — toutes les transactions de la ville sont supprimées.</div></div></li>
            <li><div class="tl-num">3</div><div><div class="tl-title">📊 Réinitialisation des mouvements stock</div><div class="tl-sub">Les entrées, sorties et ajustements sont supprimés. Seul le stock final (calculé au moment de la clôture) est conservé comme nouveau stock initial.</div></div></li>
            <li><div class="tl-num">4</div><div><div class="tl-title">🌱 Initialisation nouvelle saison</div><div class="tl-sub">Un mouvement "INIT-SAISON-[nom]" est créé par produit avec la quantité restante. Votre stock repart de zéro propre.</div></div></li>
            <li><div class="tl-num">5</div><div><div class="tl-title">📝 Log de traçabilité</div><div class="tl-sub">Un enregistrement complet de l'opération est conservé indéfiniment dans reset_logs avec statistiques détaillées.</div></div></li>
        </ul>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:14px">
            <button onclick="goStep(2)" class="btn btn-muted"><i class="fas fa-arrow-left"></i> Retour</button>
            <button onclick="goConfirm()" class="btn btn-r"><i class="fas fa-arrow-right"></i> CONTINUER — CONFIRMATION</button>
        </div>
    </div>
</div>
</div><!-- /step-3 -->

<!-- ══════════════════════════
     ÉTAPE 4 : CONFIRMATION
══════════════════════════ -->
<div class="panel-step" id="step-4">
<div class="gcard">
    <div class="gh"><div class="gh-ico"><i class="fas fa-lock"></i></div><div class="gh-title">ÉTAPE 4 — DOUBLE CONFIRMATION DE SÉCURITÉ</div></div>
    <div class="gb">

        <!-- Récapitulatif -->
        <div style="background:rgba(255,40,70,.04);border:1px solid var(--bord2);border-radius:14px;padding:16px;margin-bottom:20px">
            <div style="font-family:var(--fh);font-size:14px;letter-spacing:1px;color:var(--text);margin-bottom:10px">RÉCAPITULATIF DE L'OPÉRATION</div>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                <div><span style="font-size:9px;color:var(--muted)">SOCIÉTÉ</span><br><strong id="recap-company" style="color:var(--text);font-size:13px">—</strong></div>
                <div style="width:1px;background:var(--bord)"></div>
                <div><span style="font-size:9px;color:var(--muted)">VILLE</span><br><strong id="recap-city" style="color:var(--text);font-size:13px">—</strong></div>
                <div style="width:1px;background:var(--bord)"></div>
                <div><span style="font-size:9px;color:var(--muted)">NOUVELLE SAISON</span><br><strong id="recap-season" style="color:var(--red);font-size:13px">—</strong></div>
            </div>
        </div>

        <div class="conf-box">
            <div class="conf-title"><i class="fas fa-key"></i> AUTHENTIFICATION REQUISE</div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
                <div>
                    <div style="font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.3px;margin-bottom:8px;text-align:center">CODE PIN DEVELOPER</div>
                    <input type="password" class="pin-input" id="conf-pin" placeholder="****" maxlength="4" oninput="checkPin()">
                    <div class="hint-txt" id="pin-hint">Entrez le code PIN à 4 chiffres</div>
                </div>
                <div>
                    <div style="font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.3px;margin-bottom:8px;text-align:center">MOT DE CONFIRMATION</div>
                    <input type="text" class="word-input" id="conf-word" placeholder='NOUVELLE SAISON' oninput="checkWord()" autocomplete="off">
                    <div class="hint-txt" id="word-hint">Tapez exactement : <strong style="color:var(--gold)">NOUVELLE SAISON</strong></div>
                </div>
            </div>

            <!-- Terminal feedback -->
            <div class="term-box" id="term-box">
                <span class="dim">[SYSTÈME]</span> Prêt. En attente de confirmation...<br>
                <span class="dim">[SÉCURITÉ]</span> Double vérification PIN + mot de confirmation requise<br>
                <span class="dim">[BACKUP]</span> Tables de sauvegarde seront créées automatiquement<br>
                <span class="cursor-blink"></span>
            </div>
        </div>

        <button onclick="executeReset()" class="btn btn-DESTROY btn-full" id="btn-execute" disabled>
            <i class="fas fa-power-off"></i> LANCER LA RÉINITIALISATION DE SAISON
        </button>
        <div style="text-align:center;margin-top:10px">
            <button onclick="goStep(3)" class="btn btn-muted btn-sm"><i class="fas fa-arrow-left"></i> Retour</button>
        </div>
    </div>
</div>
</div><!-- /step-4 -->

<!-- ══════════════════════════
     ÉTAPE 5 : RÉSULTAT
══════════════════════════ -->
<div class="panel-step" id="step-5">
<div class="gcard">
    <div class="gh"><div class="gh-ico" style="background:rgba(0,232,122,.1);border-color:rgba(0,232,122,.3)"><i class="fas fa-check-circle" style="color:var(--green)"></i></div>
        <div class="gh-title" style="color:var(--green)">RÉINITIALISATION TERMINÉE</div></div>
    <div class="gb">
        <div class="success-screen show" id="success-screen">
            <span class="ss-ico">🎊</span>
            <div class="ss-title">NOUVELLE SAISON ACTIVÉE !</div>
            <div class="ss-sub" id="ss-sub">Tous les backups ont été créés et les données réinitialisées avec succès.</div>
        </div>
        <div id="report-content"></div>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:20px">
            <a href="<?= project_url('stock/stock_update_fixed.php') ?>" class="btn btn-g"><i class="fas fa-warehouse"></i> GESTION STOCK</a>
            <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="btn btn-gold"><i class="fas fa-cash-register"></i> CAISSE</a>
            <a href="<?= project_url('dashboard/index.php') ?>" class="btn btn-muted"><i class="fas fa-home"></i> TABLEAU DE BORD</a>
            <button onclick="location.reload()" class="btn btn-r btn-sm"><i class="fas fa-redo"></i> Nouvelle réinitialisation</button>
        </div>
    </div>
</div>
</div><!-- /step-5 -->

</div><!-- /wrap -->

<!-- ════ LOADING OVERLAY ════ -->
<div class="loading-overlay" id="loading-overlay">
    <div class="ld-ico">⚙️</div>
    <div class="ld-title">RÉINITIALISATION EN COURS</div>
    <div class="ld-sub">Ne fermez pas cette page.<br>L'opération peut prendre quelques secondes.</div>
    <div class="ld-steps">
        <div class="ld-step active" id="ld-s1"><i class="fas fa-database"></i> Backup stock_movements</div>
        <div class="ld-step" id="ld-s2"><i class="fas fa-copy"></i> Backup commandes &amp; factures</div>
        <div class="ld-step" id="ld-s3"><i class="fas fa-truck"></i> Backup arrivages</div>
        <div class="ld-step" id="ld-s4"><i class="fas fa-trash"></i> Effacement des transactions</div>
        <div class="ld-step" id="ld-s5"><i class="fas fa-leaf"></i> Initialisation nouvelle saison</div>
        <div class="ld-step" id="ld-s6"><i class="fas fa-check-circle"></i> Finalisation &amp; log</div>
    </div>
    <div class="prog-wrap" style="width:min(400px,90vw)">
        <div class="prog-bar"></div>
    </div>
</div>

<div class="tstack" id="tstack"></div>

<script>
const SELF = '<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>';
let selectedCompanyId = <?= $company_id ?: 0 ?>;
let selectedCityId    = <?= $city_id    ?: 0 ?>;
let selectedCompanyName = '<?= addslashes($company_name) ?>';
let selectedCityName    = '<?= addslashes($city_name) ?>';
let currentStep = 1;
let previewStats = null;
let pinOk = false, wordOk = false;

/* ── CANVAS PARTICLES ── */
(function(){
    const c=document.getElementById('bg-canvas');if(!c)return;
    const ctx=c.getContext('2d');
    function resize(){c.width=window.innerWidth;c.height=window.innerHeight;}
    resize();window.addEventListener('resize',resize);
    const pts=[];for(let i=0;i<35;i++)pts.push({x:Math.random()*c.width,y:Math.random()*c.height,r:Math.random()*.9+.2,dx:(Math.random()-.5)*.25,dy:(Math.random()-.5)*.2,o:Math.random()*.18+.04});
    function draw(){ctx.clearRect(0,0,c.width,c.height);
        pts.forEach(p=>{ctx.beginPath();ctx.arc(p.x,p.y,p.r,0,Math.PI*2);ctx.fillStyle=`rgba(255,40,70,${p.o})`;ctx.fill();p.x+=p.dx;p.y+=p.dy;if(p.x<0)p.x=c.width;if(p.x>c.width)p.x=0;if(p.y<0)p.y=c.height;if(p.y>c.height)p.y=0;});
        for(let i=0;i<pts.length;i++)for(let j=i+1;j<pts.length;j++){const dx=pts[i].x-pts[j].x,dy=pts[i].y-pts[j].y,d=Math.sqrt(dx*dx+dy*dy);if(d<110){ctx.beginPath();ctx.moveTo(pts[i].x,pts[i].y);ctx.lineTo(pts[j].x,pts[j].y);ctx.strokeStyle=`rgba(255,40,70,${(1-d/110)*.03})`;ctx.lineWidth=0.5;ctx.stroke();}}
        requestAnimationFrame(draw);}
    draw();
})();

/* ── TOAST ── */
function toast(msg,type='success',sub=''){
    const C={success:'var(--green)',error:'var(--red)',info:'var(--cyan)',warn:'var(--gold)'};
    const IC={success:'fa-check-circle',error:'fa-times-circle',info:'fa-info-circle',warn:'fa-exclamation-triangle'};
    const stack=document.getElementById('tstack');if(!stack)return;
    const el=document.createElement('div');el.className='toast';
    el.innerHTML=`<div class="tico" style="background:${C[type]}22;color:${C[type]}"><i class="fas ${IC[type]}"></i></div><div class="ttxt"><strong style="color:${C[type]}">${msg}</strong>${sub?`<span>${sub}</span>`:''}</div>`;
    stack.prepend(el);setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),320);},4500);
}

/* ── STEPS NAV ── */
function goStep(n){
    document.querySelectorAll('.panel-step').forEach(p=>p.classList.remove('show'));
    document.getElementById('step-'+n)?.classList.add('show');
    // Update step indicators
    for(let i=1;i<=5;i++){
        const dot=document.getElementById('sdot-'+i);
        const lbl=document.getElementById('slbl-'+i);
        const line=document.getElementById('sline-'+i);
        if(!dot)continue;
        dot.classList.remove('active','done');lbl.classList.remove('active','done');
        if(line)line.classList.remove('done');
        if(i<n){dot.classList.add('done');dot.textContent='✓';lbl.classList.add('done');if(line)line.classList.add('done');}
        else if(i===n){dot.classList.add('active');if(dot.textContent==='✓')dot.textContent=i;lbl.classList.add('active');}
        else{if(dot.textContent==='✓')dot.textContent=i;}
    }
    currentStep=n;
    window.scrollTo({top:0,behavior:'smooth'});
}

/* ── COMPANY CHANGE ── */
function onCompanyChange(){
    const coid=document.getElementById('sel-company').value;
    selectedCompanyId=+coid;
    const city=document.getElementById('sel-city');
    city.innerHTML='<option value="">Chargement…</option>';
    city.disabled=true;
    if(!coid)return;
    // Load cities via same page
    fetch(`${SELF}?company_id=${coid}`,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(()=>{window.location.href=`${SELF}?company_id=${coid}`;}).catch(()=>{});
}

/* ── GO PREVIEW ── */
async function goPreview(){
    const coid=+document.getElementById('sel-company').value;
    const ciid=+document.getElementById('sel-city').value;
    if(!coid||!ciid){toast('Sélectionnez société et ville','warn');return;}
    selectedCompanyId=coid;selectedCityId=ciid;
    selectedCompanyName=document.getElementById('sel-company').selectedOptions[0]?.text||'';
    selectedCityName=document.getElementById('sel-city').selectedOptions[0]?.text||'';
    document.getElementById('preview-loading').style.display='block';
    document.getElementById('preview-content').innerHTML='';
    goStep(2);
    const fd=new FormData();fd.append('ajax_action','preview');fd.append('company_id',coid);fd.append('city_id',ciid);
    try{
        const res=await fetch(SELF,{method:'POST',body:fd});const data=await res.json();
        document.getElementById('preview-loading').style.display='none';
        if(!data.success){toast(data.message||'Erreur','error');goStep(1);return;}
        previewStats=data.stats;
        renderPreview(data.stats);
    }catch(e){toast('Erreur réseau','error');goStep(1);}
}

function renderPreview(s){
    const fmt=n=>Number(n).toLocaleString('fr-FR');
    let html='';
    // Stats boxes
    html+=`<div class="stat-grid">
        <div class="stat-box"><div class="stat-val">${s.movements_total||0}</div><div class="stat-lbl">Mouvements stock</div></div>
        <div class="stat-box"><div class="stat-val">${s.orders_total||0}</div><div class="stat-lbl">Commandes</div><div class="stat-sub">${fmt(s.orders_amount||0)} CFA</div></div>
        <div class="stat-box"><div class="stat-val">${s.items_total||0}</div><div class="stat-lbl">Articles commandes</div></div>
        ${s.invoices_total!==undefined?`<div class="stat-box"><div class="stat-val">${s.invoices_total}</div><div class="stat-lbl">Factures</div><div class="stat-sub">${fmt(s.invoices_amount||0)} CFA</div></div>`:''}
        ${s.arrivages_total!==undefined?`<div class="stat-box"><div class="stat-val">${s.arrivages_total}</div><div class="stat-lbl">Arrivages</div></div>`:''}
    </div>`;

    // Movements by type
    if(s.movements&&s.movements.length){
        html+=`<div class="sec-t" style="margin-top:20px"><div class="sec-line"></div><h3>MOUVEMENTS PAR TYPE</h3></div>
        <div style="overflow-x:auto;margin-bottom:16px"><table class="tbl"><thead><tr><th>Type</th><th>Lignes</th><th>Qté totale</th><th>Action</th></tr></thead><tbody>`;
        const typeColors={'exit':'bdg-r','entry':'bdg-g','initial':'bdg-gold','adjustment':'bdg-o'};
        s.movements.forEach(m=>{
            const c=typeColors[m.type]||'bdg-c';
            const kept=m.type==='initial'?'<span class="bdg bdg-gold">CONSERVÉ</span>':'<span class="bdg bdg-r">SUPPRIMÉ</span>';
            html+=`<tr><td><span class="bdg ${c}">${m.type.toUpperCase()}</span></td><td><strong>${m.nb}</strong></td><td>${fmt(m.total_qty)}</td><td>${kept}</td></tr>`;
        });
        html+=`</tbody></table></div>`;
    }

    // Orders by status
    if(s.orders&&s.orders.length){
        html+=`<div class="sec-t"><div class="sec-line"></div><h3>COMMANDES PAR STATUT</h3></div>
        <div style="overflow-x:auto;margin-bottom:16px"><table class="tbl"><thead><tr><th>Statut</th><th>Nombre</th><th>Montant</th></tr></thead><tbody>`;
        const stColors={pending:'bdg-gold',confirmed:'bdg-c',delivering:'bdg-b',done:'bdg-g',cancelled:'bdg-r'};
        s.orders.forEach(o=>{
            html+=`<tr><td><span class="bdg ${stColors[o.status]||'bdg-c'}">${o.status.toUpperCase()}</span></td><td>${o.nb}</td><td>${fmt(o.total)} CFA</td></tr>`;
        });
        html+=`</tbody></table></div>`;
    }

    // Stock actuel produits
    if(s.products_stock&&s.products_stock.length){
        html+=`<div class="sec-t"><div class="sec-line"></div><h3>STOCK ACTUEL (→ NOUVEAU STOCK INITIAL)</h3></div>
        <p style="font-size:11px;font-weight:700;color:var(--text2);margin-bottom:12px">Ces quantités deviendront le stock initial de la nouvelle saison.</p>
        <div style="overflow-x:auto;margin-bottom:16px"><table class="tbl"><thead><tr><th>Produit</th><th>Stock initial</th><th>Stock actuel (après mvts)</th></tr></thead><tbody>`;
        s.products_stock.forEach(p=>{
            html+=`<tr><td><strong style="color:var(--text)">${p.name}</strong></td><td>${p.stock_initial}</td><td><strong style="color:var(--green)">${p.stock_actuel}</strong></td></tr>`;
        });
        html+=`</tbody></table></div>`;
    }

    // Backups existants
    if(s.past_backups&&s.past_backups.length){
        html+=`<div class="sec-t"><div class="sec-line"></div><h3>BACKUPS EXISTANTS</h3></div>`;
        s.past_backups.forEach(b=>{
            html+=`<div class="bk-row"><div><span class="bk-name"><i class="fas fa-database"></i> ${b.backup_table}</span><span class="bk-date" style="margin-left:10px">${b.created_at?new Date(b.created_at).toLocaleString('fr-FR'):''}</span></div><div><span class="bk-rows"><i class="fas fa-check-circle"></i> ${b.rows_backed_up} lignes</span></div></div>`;
        });
    }

    document.getElementById('preview-content').innerHTML=`<div class="gcard" style="animation:fadeUp .4s ease">
        <div class="gh"><div class="gh-ico"><i class="fas fa-chart-bar"></i></div><div class="gh-title">APERÇU — ${selectedCompanyName} · ${selectedCityName}</div></div>
        <div class="gb">${html}</div></div>`;
}

/* ── GO CONFIRM ── */
function goConfirm(){
    const s=document.getElementById('season-name').value.trim();
    if(!s||s.length<3){toast('Nom de saison trop court (minimum 3 caractères)','warn');document.getElementById('season-name').style.animation='shake .4s ease';setTimeout(()=>document.getElementById('season-name').style.animation='',500);return;}
    // Update recap
    document.getElementById('recap-company').textContent=selectedCompanyName;
    document.getElementById('recap-city').textContent=selectedCityName;
    document.getElementById('recap-season').textContent=s;
    document.getElementById('conf-pin').value='';
    document.getElementById('conf-word').value='';
    pinOk=false;wordOk=false;
    updateExecBtn();
    termLog(`[SAISON] "${s}" sélectionnée`,'gold');
    termLog('[ATTENTE] Entrez le code PIN et le mot de confirmation','dim');
    goStep(4);
}

/* ── PIN / WORD CHECK ── */
function checkPin(){
    const v=document.getElementById('conf-pin').value;
    const hint=document.getElementById('pin-hint');
    pinOk=(v==='7531');
    hint.style.color=pinOk?'var(--green)':'var(--muted)';
    hint.textContent=pinOk?'✓ Code correct':'Entrez le code PIN à 4 chiffres';
    if(pinOk)termLog('[PIN] Code PIN accepté ✓','gold');
    updateExecBtn();
}
function checkWord(){
    const v=document.getElementById('conf-word').value.toUpperCase().trim();
    const inp=document.getElementById('conf-word');
    const hint=document.getElementById('word-hint');
    wordOk=(v==='NOUVELLE SAISON');
    inp.classList.toggle('valid',wordOk);
    hint.style.color=wordOk?'var(--green)':'var(--muted)';
    hint.textContent=wordOk?'✓ Confirmation acceptée':'Tapez exactement : NOUVELLE SAISON';
    if(wordOk)termLog('[MOT] Confirmation acceptée ✓','gold');
    updateExecBtn();
}
function updateExecBtn(){
    const btn=document.getElementById('btn-execute');
    const ok=pinOk&&wordOk;
    btn.disabled=!ok;
    if(ok){termLog('[SYSTÈME] Prêt à exécuter la réinitialisation.','gold');termLog('[DANGER] Appuyez sur LANCER pour démarrer...','red');}
}

function termLog(msg,cls='dim'){
    const tb=document.getElementById('term-box');if(!tb)return;
    const cursor=tb.querySelector('.cursor-blink');
    const span=document.createElement('span');
    span.className=cls;span.textContent=msg;
    if(cursor)tb.insertBefore(span,cursor);else tb.appendChild(span);
    tb.appendChild(document.createElement('br'));
    tb.scrollTop=tb.scrollHeight;
}

/* ── EXECUTE RESET ── */
async function executeReset(){
    if(!pinOk||!wordOk){toast('Confirmations manquantes','error');return;}
    const season=document.getElementById('season-name').value.trim();
    if(!season){toast('Nom de saison manquant','error');return;}

    // Confirm final
    if(!confirm(`⚠️ DERNIÈRE CHANCE !\n\nVous allez réinitialiser :\n• Société : ${selectedCompanyName}\n• Ville : ${selectedCityName}\n• Saison : ${season}\n\nLes backups seront créés AVANT l'effacement.\n\nContinuer ?`)) return;

    // Show loading
    document.getElementById('loading-overlay').classList.add('show');
    document.getElementById('btn-execute').disabled=true;

    // Animate loading steps
    const ldSteps=['ld-s1','ld-s2','ld-s3','ld-s4','ld-s5','ld-s6'];
    let si=0;
    const ldInterval=setInterval(()=>{
        if(si>0&&si<=ldSteps.length)document.getElementById(ldSteps[si-1]).classList.replace('active','done');
        if(si<ldSteps.length){document.getElementById(ldSteps[si]).classList.add('active');si++;}
        else clearInterval(ldInterval);
    },600);

    const fd=new FormData();
    fd.append('ajax_action','execute_reset');
    fd.append('company_id',selectedCompanyId);
    fd.append('city_id',selectedCityId);
    fd.append('season_name',season);
    fd.append('confirm_pin',document.getElementById('conf-pin').value);
    fd.append('confirm_word',document.getElementById('conf-word').value);

    try{
        const res=await fetch(SELF,{method:'POST',body:fd});
        const rawText=await res.text();
        let data;
        try{
            data=JSON.parse(rawText);
        }catch(je){
            console.error('Réponse non-JSON du serveur:',rawText.substring(0,500));
            throw new Error('Réponse invalide du serveur. Vérifiez error_log PHP.\n'+rawText.substring(0,200));
        }
        clearInterval(ldInterval);
        ldSteps.forEach(id=>{const el=document.getElementById(id);if(el)el.classList.replace('active','done');});
        setTimeout(()=>{
            document.getElementById('loading-overlay').classList.remove('show');
            if(data.success){
                toast('Réinitialisation réussie !','success');
                renderReport(data);
                goStep(5);
            } else {
                toast(data.message||'Erreur lors de la réinitialisation','error');
                document.getElementById('btn-execute').disabled=false;
            }
        },800);
    }catch(e){
        clearInterval(ldInterval);
        document.getElementById('loading-overlay').classList.remove('show');
        document.getElementById('btn-execute').disabled=false;
        toast('Erreur : '+e.message,'error','Vérifiez la console (F12)');
        console.error('executeReset error:', e);
    }
}

function renderReport(data){
    const fmt=n=>Number(n||0).toLocaleString('fr-FR');
    document.getElementById('ss-sub').textContent=`Saison « ${data.season} » démarrée · ${data.timestamp?.replace('_',' ') || ''} · Backups créés dans la base de données.`;

    let html=`<div style="margin-top:16px">`;
    html+=`<div class="sec-t"><div class="sec-line"></div><h3 style="color:var(--green)">BACKUPS CRÉÉS</h3></div>`;
    if(data.backed_up?.length){
        data.backed_up.forEach(b=>{
            html+=`<div class="bk-row"><div><span class="bk-name"><i class="fas fa-database"></i> ${b.table}</span><br><span style="font-size:10px;color:var(--text2)">${b.label}</span></div><span class="bk-rows"><i class="fas fa-check-circle"></i> ${fmt(b.rows)} lignes sauvegardées</span></div>`;
        });
    }

    html+=`<div class="sec-t" style="margin-top:20px"><div class="sec-line"></div><h3 style="color:var(--red)">DONNÉES EFFACÉES</h3></div>`;
    if(data.deleted?.length){
        data.deleted.forEach(d=>{
            html+=`<div style="display:flex;justify-content:space-between;padding:8px 12px;border-radius:9px;border:1px solid var(--bord);margin-bottom:6px;background:rgba(0,0,0,.2)">
                <span style="font-size:11px;font-weight:700;color:var(--text2)">${d.label}</span>
                <span class="bdg bdg-r">${fmt(d.rows)} lignes</span></div>`;
        });
    }

    html+=`<div class="sec-t" style="margin-top:20px"><div class="sec-line"></div><h3 style="color:var(--gold)">NOUVELLE SAISON</h3></div>
    <div style="padding:14px;border-radius:12px;border:1px solid rgba(0,232,122,.25);background:rgba(0,232,122,.04)">
        <div style="font-size:13px;font-weight:700;color:var(--green)"><i class="fas fa-leaf"></i> ${data.new_initials||0} produit(s) initialisés avec leur stock de clôture</div>
        <div style="font-size:11px;color:var(--muted);margin-top:5px">Référence: INIT-SAISON-${data.season}</div>
    </div></div>`;

    document.getElementById('report-content').innerHTML=html;
}

/* ── INIT si localisation déjà définie ── */
<?php if($location_set): ?>
// Auto-preview si déjà localisé
// (pas automatique, on laisse l'user cliquer)
<?php endif; ?>
</script>
</body>
</html>
