<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║   MESSAGERIE WHATSAPP STYLE — ESPERANCE H2O                    ║
 * ║   FIXED: CSRF bug, upload 400, errors visible, video recording  ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
session_start();
ini_set('display_errors', 1);  // SHOW ERRORS FOR DEBUGGING
error_reporting(E_ALL);
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
use App\Core\DB; use App\Core\Auth; use App\Core\Middleware;

Auth::check();
Middleware::role(['developer','admin','manager','staff','employee','Patron','PDG','Directrice','Secretaire','Superviseur','informaticien']);

$pdo = DB::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$me_id   = (int)($_SESSION['user_id'] ?? 0);
$me_name = $_SESSION['username'] ?? 'User';
$me_role = $_SESSION['role']    ?? 'staff';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];

function jErr(string $m, int $c=400):void{
    http_response_code($c);
    echo json_encode(['ok'=>false,'err'=>$m]);
    error_log('[CHAT ERROR '.$c.'] '.$m);
    exit;
}
function eH(?string $s):string{ return htmlspecialchars($s??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

/* ════════════════════════════════════════════════════════════ AJAX ══ */
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    // ── CSRF CHECK ──────────────────────────────────────────────────────
    // For multipart/form-data (file uploads), token comes from POST field
    // For application/x-www-form-urlencoded, also from POST field
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $token)) {
        error_log('[CHAT] CSRF FAIL - received: '.substr($token,0,10).'... expected: '.substr($csrf,0,10).'...');
        jErr('CSRF invalide - token: '.substr($token,0,8).'... attendu: '.substr($csrf,0,8).'...',403);
    }

    $act = $_POST['ajax'] ?? '';
    error_log('[CHAT] Action: '.$act.' user:'.$me_id);

    try {

    /* —— DIAGNOSTIC ——————————————————————————————————————————————————— */
    if ($act==='diag'){
        $dir=APP_ROOT . '/uploads/chat/';
        $parent=APP_ROOT . '/uploads/';
        $info=[
            'php_user'=>function_exists('posix_getpwuid')?posix_getpwuid(posix_geteuid())['name']:(get_current_user()?:'unknown'),
            'dir_exists'=>is_dir($dir),
            'parent_exists'=>is_dir($parent),
            'dir_writable'=>is_writable($dir),
            'parent_writable'=>is_writable($parent),
            'dir_perms'=>is_dir($dir)?substr(sprintf('%o',fileperms($dir)),-4):'N/A',
            'parent_perms'=>is_dir($parent)?substr(sprintf('%o',fileperms($parent)),-4):'N/A',
            '__DIR__'=>__DIR__,
            'upload_max_filesize'=>ini_get('upload_max_filesize'),
            'post_max_size'=>ini_get('post_max_size'),
            'fix_command'=>'chmod -R 777 '.APP_ROOT . '/uploads/',
        ];
        // Tenter de créer le dossier si absent
        if(!is_dir($dir)) {
            $info['mkdir_attempt']=mkdir($dir,0777,true)?'OK':'FAILED';
            if(is_dir($dir)) chmod($dir,0777);
        }
        echo json_encode(['ok'=>true,'diag'=>$info]); exit;
    }


    if ($act==='get_users'){
        $st=$pdo->prepare("SELECT id,username,role FROM users WHERE id!=:me ORDER BY username LIMIT 300");
        $st->execute([':me'=>$me_id]);
        echo json_encode(['ok'=>true,'users'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    /* —— MESSAGES PRIVÉS ————————————————————————————————————————————— */
    if ($act==='get_messages'){
        $other=(int)($_POST['user_id']??0); if($other<=0) jErr('user_id invalide');
        // FIX HY093: PDO MySQL ne supporte pas les params nommés répétés.
        // Utilisation de ? positionnels: [me_CASE, me_WHERE1, ot_WHERE1, ot_WHERE2, me_WHERE2]
        $st=$pdo->prepare("
            SELECT id,sender_id,sender_name,recipient_id,content,
                   COALESCE(message_type,'text') AS message_type,
                   file_path,file_name,is_read,created_at,
                   CASE WHEN sender_id=? THEN 'sent' ELSE 'received' END AS dir
            FROM chat_private_messages
            WHERE (sender_id=? AND recipient_id=? AND IFNULL(deleted_by_sender,0)=0)
               OR (sender_id=? AND recipient_id=? AND IFNULL(deleted_by_recipient,0)=0)
            ORDER BY created_at ASC LIMIT 500");
        $st->execute([$me_id, $me_id, $other, $other, $me_id]);
        $msgs=$st->fetchAll(PDO::FETCH_ASSOC);
        $pdo->prepare("UPDATE chat_private_messages SET is_read=1,read_at=NOW() WHERE recipient_id=? AND sender_id=? AND IFNULL(is_read,0)=0")->execute([$me_id,$other]);
        echo json_encode(['ok'=>true,'messages'=>$msgs]); exit;
    }

    /* —— SEND PRIVÉ ——————————————————————————————————————————————————— */
    if ($act==='send_message'){
        $rid=(int)($_POST['recipient_id']??0); $txt=trim($_POST['content']??'');
        if($rid<=0) jErr('Destinataire invalide (recipient_id='.$rid.')');
        if($txt==='') jErr('Message vide');
        if(mb_strlen($txt)>5000) jErr('Trop long ('.mb_strlen($txt).' chars)');
        $st=$pdo->prepare("SELECT username FROM users WHERE id=:id LIMIT 1");
        $st->execute([':id'=>$rid]); $ru=$st->fetch();
        if(!$ru) jErr('Destinataire introuvable id='.$rid);
        $pdo->prepare("INSERT INTO chat_private_messages(sender_id,sender_name,recipient_id,recipient_name,content,message_type,created_at) VALUES(:sid,:sn,:rid,:rn,:c,'text',NOW())")
            ->execute([':sid'=>$me_id,':sn'=>$me_name,':rid'=>$rid,':rn'=>$ru['username'],':c'=>$txt]);
        echo json_encode(['ok'=>true]); exit;
    }

    /* —— UPLOAD FICHIER PRIVÉ ————————————————————————————————————————— */
    if ($act==='upload_file'){
        $rid=(int)($_POST['recipient_id']??0);
        error_log('[UPLOAD] rid='.$rid.' files='.json_encode(array_keys($_FILES)));
        if($rid<=0) jErr('Paramètre recipient_id invalide: '.$rid);
        if(empty($_FILES['file'])) jErr('Aucun fichier reçu - vérifiez le champ file');
        $f=$_FILES['file'];
        if($f['error']!==UPLOAD_ERR_OK) {
            $errMap=[1=>'UPLOAD_ERR_INI_SIZE',2=>'UPLOAD_ERR_FORM_SIZE',3=>'UPLOAD_ERR_PARTIAL',4=>'UPLOAD_ERR_NO_FILE',6=>'UPLOAD_ERR_NO_TMP_DIR',7=>'UPLOAD_ERR_CANT_WRITE',8=>'UPLOAD_ERR_EXTENSION'];
            jErr('Erreur PHP upload: '.($errMap[$f['error']]??'code '.$f['error']));
        }
        if($f['size'] > 15*1024*1024*1024) {
            jErr('Fichier trop grand: '.round($f['size']/1024/1024,1).' Mo (max 15,000 Mo)');
        }
        $ok_ext=['jpg','jpeg','png','gif','webp','mp3','ogg','wav','webm','m4a','mp4','avi','mov','pdf','doc','docx','xls','xlsx','txt','csv','zip'];
        $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,$ok_ext,true)) jErr("Extension .$ext refusée. Autorisées: ".implode(', ',$ok_ext));
        // PAS de finfo (cause Warning JIT qui casse le JSON)
        // Détection du type par extension uniquement (fiable et suffisant)
        $dir=APP_ROOT . '/uploads/chat/';
        if(!is_dir($dir)) {
            if(!mkdir($dir,0777,true)) jErr('Impossible de créer le dossier uploads/chat/ — vérifiez les permissions du dossier parent');
            chmod($dir, 0777);
        }
        if(!is_writable($dir)) {
            // Tenter de corriger les permissions
            chmod($dir, 0777);
            if(!is_writable($dir)) jErr('Dossier uploads/chat/ non accessible en écriture. Exécutez: chmod -R 777 '.APP_ROOT . '/uploads/ ou chown www-data:www-data '.APP_ROOT . '/uploads/');
        }
        $fn=uniqid('p_',true).'.'.$ext; $wp='uploads/chat/'.$fn;
        if(!move_uploaded_file($f['tmp_name'],$dir.$fn)) jErr('Sauvegarde fichier échouée vers '.$dir.$fn);
        // Détection type par extension (ogg/webm audio, mp4/avi video, etc.)
        $audio_exts=['mp3','ogg','wav','m4a','aac','flac','opus','webm'];
        $video_exts=['mp4','avi','mov','mkv','flv','wmv'];
        $image_exts=['jpg','jpeg','png','gif','webp','bmp','svg','ico'];
        $mt='file';
        if(in_array($ext,$image_exts,true)) $mt='image';
        elseif(in_array($ext,$audio_exts,true)) $mt='audio';
        elseif(in_array($ext,$video_exts,true)) $mt='video';
        $st=$pdo->prepare("SELECT username FROM users WHERE id=:id LIMIT 1"); $st->execute([':id'=>$rid]); $ru=$st->fetch();
        if(!$ru) jErr('Destinataire introuvable id='.$rid);
        $pdo->prepare("INSERT INTO chat_private_messages(sender_id,sender_name,recipient_id,recipient_name,content,message_type,file_path,file_name,created_at) VALUES(:sid,:sn,:rid,:rn,:c,:mt,:fp,:fn2,NOW())")
            ->execute([':sid'=>$me_id,':sn'=>$me_name,':rid'=>$rid,':rn'=>$ru['username'],':c'=>$f['name'],':mt'=>$mt,':fp'=>$wp,':fn2'=>$f['name']]);
        echo json_encode(['ok'=>true,'path'=>$wp,'type'=>$mt]); exit;
    }

    /* —— NON LUS ————————————————————————————————————————————————————— */
    if ($act==='get_unread'){
        $st=$pdo->prepare("SELECT sender_id,COUNT(*) c FROM chat_private_messages WHERE recipient_id=:me AND IFNULL(is_read,0)=0 GROUP BY sender_id");
        $st->execute([':me'=>$me_id]);
        $u=[]; foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) $u[$r['sender_id']]=(int)$r['c'];
        echo json_encode(['ok'=>true,'unread'=>$u]); exit;
    }

    /* —— GROUPES ————————————————————————————————————————————————————— */
    if ($act==='get_groups'){
        $st=$pdo->prepare("SELECT g.id,g.name,(SELECT COUNT(*) FROM chat_group_members WHERE group_id=g.id) AS mc FROM chat_groups g INNER JOIN chat_group_members m ON m.group_id=g.id AND m.user_id=:me ORDER BY g.name");
        $st->execute([':me'=>$me_id]);
        echo json_encode(['ok'=>true,'groups'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }
    if ($act==='create_group'){
        $name=trim($_POST['name']??''); if($name==='') jErr('Nom requis');
        $members=json_decode($_POST['members']??'[]',true); if(!is_array($members)) $members=[];
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO chat_groups(name,created_by) VALUES(:n,:cb)")->execute([':n'=>$name,':cb'=>$me_id]);
        $gid=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare("INSERT IGNORE INTO chat_group_members(group_id,user_id) VALUES(:g,:u)");
        foreach(array_unique(array_merge([$me_id],array_map('intval',$members))) as $uid) $ins->execute([':g'=>$gid,':u'=>$uid]);
        $pdo->commit();
        echo json_encode(['ok'=>true,'group_id'=>$gid,'name'=>$name]); exit;
    }
    if ($act==='get_group_messages'){
        $gid=(int)($_POST['group_id']??0); if($gid<=0) jErr('group_id invalide');
        $st=$pdo->prepare("SELECT 1 FROM chat_group_members WHERE group_id=:g AND user_id=:me LIMIT 1");
        $st->execute([':g'=>$gid,':me'=>$me_id]); if(!$st->fetch()) jErr('Accès refusé',403);
        // FIX HY093: ? positionnels [me_CASE, g_WHERE]
        $st=$pdo->prepare("SELECT id,group_id,sender_id,sender_name,content,COALESCE(message_type,'text') AS message_type,file_path,file_name,created_at,CASE WHEN sender_id=? THEN 'sent' ELSE 'received' END AS dir FROM chat_group_messages WHERE group_id=? ORDER BY created_at ASC LIMIT 500");
        $st->execute([$me_id,$gid]);
        echo json_encode(['ok'=>true,'messages'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }
    if ($act==='send_group_message'){
        $gid=(int)($_POST['group_id']??0); $txt=trim($_POST['content']??'');
        if($gid<=0) jErr('group_id invalide'); if($txt==='') jErr('Message vide');
        $st=$pdo->prepare("SELECT 1 FROM chat_group_members WHERE group_id=:g AND user_id=:me LIMIT 1");
        $st->execute([':g'=>$gid,':me'=>$me_id]); if(!$st->fetch()) jErr('Accès refusé',403);
        $pdo->prepare("INSERT INTO chat_group_messages(group_id,sender_id,sender_name,content,message_type,created_at) VALUES(:g,:sid,:sn,:c,'text',NOW())")
            ->execute([':g'=>$gid,':sid'=>$me_id,':sn'=>$me_name,':c'=>$txt]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='upload_group_file'){
        $gid=(int)($_POST['group_id']??0);
        if($gid<=0) jErr('Paramètre group_id invalide: '.$gid);
        if(empty($_FILES['file'])) jErr('Aucun fichier reçu');
        $st=$pdo->prepare("SELECT 1 FROM chat_group_members WHERE group_id=:g AND user_id=:me LIMIT 1");
        $st->execute([':g'=>$gid,':me'=>$me_id]); if(!$st->fetch()) jErr('Accès refusé',403);
        $f=$_FILES['file'];
        if($f['error']!==UPLOAD_ERR_OK) {
            $errMap=[1=>'UPLOAD_ERR_INI_SIZE',2=>'UPLOAD_ERR_FORM_SIZE',3=>'UPLOAD_ERR_PARTIAL',4=>'UPLOAD_ERR_NO_FILE',6=>'UPLOAD_ERR_NO_TMP_DIR',7=>'UPLOAD_ERR_CANT_WRITE'];
            jErr('Erreur upload: '.($errMap[$f['error']]??'code '.$f['error']));
        }
        if($f['size'] > 15*1024*1024*1024) {
            jErr('Fichier trop grand: '.round($f['size']/1024/1024,1).' Mo (max 15,000 Mo)');
        }
        $ok_ext=['jpg','jpeg','png','gif','webp','mp3','ogg','wav','webm','m4a','mp4','avi','mov','pdf','doc','docx','xls','xlsx','txt','csv','zip'];
        $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,$ok_ext,true)) jErr("Extension .$ext refusée");
        // PAS de finfo (cause Warning JIT qui casse le JSON)
        // Détection du type par extension uniquement (fiable et suffisant)
        $dir=APP_ROOT . '/uploads/chat/';
        if(!is_dir($dir)) { mkdir($dir,0777,true); chmod($dir,0777); }
        if(!is_writable($dir)) { chmod($dir,0777); }
        if(!is_writable($dir)) jErr('Dossier uploads/chat/ non accessible en écriture. Exécutez: chmod -R 777 '.APP_ROOT . '/uploads/');
        $fn=uniqid('g_',true).'.'.$ext; $wp='uploads/chat/'.$fn;
        if(!move_uploaded_file($f['tmp_name'],$dir.$fn)) jErr('Sauvegarde échouée');
        // Détection type par extension uniquement
        $audio_exts=['mp3','ogg','wav','m4a','aac','flac','opus','webm'];
        $video_exts=['mp4','avi','mov','mkv','flv','wmv'];
        $image_exts=['jpg','jpeg','png','gif','webp','bmp','svg','ico'];
        $mt='file';
        if(in_array($ext,$image_exts,true)) $mt='image';
        elseif(in_array($ext,$audio_exts,true)) $mt='audio';
        elseif(in_array($ext,$video_exts,true)) $mt='video';
        $pdo->prepare("INSERT INTO chat_group_messages(group_id,sender_id,sender_name,content,message_type,file_path,file_name,created_at) VALUES(:g,:sid,:sn,:c,:mt,:fp,:fn2,NOW())")
            ->execute([':g'=>$gid,':sid'=>$me_id,':sn'=>$me_name,':c'=>$f['name'],':mt'=>$mt,':fp'=>$wp,':fn2'=>$f['name']]);
        echo json_encode(['ok'=>true,'path'=>$wp,'type'=>$mt]); exit;
    }
    if ($act==='get_group_members'){
        $gid=(int)($_POST['group_id']??0); if($gid<=0) jErr('group_id invalide');
        $st=$pdo->prepare("SELECT u.id,u.username,u.role FROM chat_group_members m INNER JOIN users u ON u.id=m.user_id WHERE m.group_id=:g ORDER BY u.username");
        $st->execute([':g'=>$gid]);
        echo json_encode(['ok'=>true,'members'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }

    jErr('Action inconnue: '.$act);
    } catch(PDOException $e){
        error_log('[CHAT PDO] '.$e->getMessage());
        jErr('DB Error: '.$e->getMessage(),500);
    } catch(Exception $e){
        error_log('[CHAT Exception] '.$e->getMessage());
        jErr($e->getMessage(),500);
    }
}

/* ══════════════════════════════════════════════════════════════ STATS ══ */
try {
    $st=$pdo->prepare("SELECT (SELECT COUNT(*) FROM users WHERE id!=?) tu,(SELECT COUNT(*) FROM chat_private_messages WHERE recipient_id=? AND IFNULL(is_read,0)=0) um,(SELECT COUNT(*) FROM chat_groups g INNER JOIN chat_group_members m ON m.group_id=g.id WHERE m.user_id=?) mg");
    $st->execute([$me_id,$me_id,$me_id]);
    $stats=$st->fetch(PDO::FETCH_ASSOC);
} catch(Exception $e){ $stats=['tu'=>0,'um'=>0,'mg'=>0]; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Messagerie | ESPERANCE H2O</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --wa-bg:#111b21;--wa-side:#202c33;--wa-panel:#2a3942;--wa-hover:#2a3942;
  --wa-active:#2a3942;--wa-input:#2a3942;--wa-msg-bg:#1f2c34;
  --wa-sent:#005c4b;--wa-recv:#202c33;--wa-border:#313d45;
  --wa-green:#00a884;--wa-green2:#00cf9d;--wa-text:#e9edef;--wa-text2:#8696a0;
  --wa-icon:#aebac1;--wa-time:#8696a0;--wa-blue:#53bdeb;--wa-red:#f15c6d;
  --wa-gold:#ffd279;--radius:8px;
}
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%;overflow:hidden;font-family:'DM Sans',sans-serif;background:var(--wa-bg);color:var(--wa-text)}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--wa-border);border-radius:4px}
.navbar{height:48px;background:var(--wa-side);border-bottom:1px solid var(--wa-border);display:flex;align-items:center;padding:0 16px;gap:6px;flex-shrink:0;z-index:100}
.nav-brand{display:flex;align-items:center;gap:9px;margin-right:12px;padding-right:12px;border-right:1px solid var(--wa-border)}
.nav-brand-ico{width:28px;height:28px;background:linear-gradient(135deg,var(--wa-green),var(--wa-green2));border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#111}
.nav-brand-txt{font-size:12px;font-weight:800;color:var(--wa-text);letter-spacing:.02em}
.nav-a{display:flex;align-items:center;gap:5px;padding:5px 10px;border-radius:7px;text-decoration:none;color:var(--wa-text2);font-size:11.5px;font-weight:600;transition:all .15s;white-space:nowrap}
.nav-a:hover{background:rgba(255,255,255,.05);color:var(--wa-text)}
.nav-a.cur{background:rgba(0,168,132,.12);color:var(--wa-green)}
.nav-a i{font-size:11px}
.nav-sp{flex:1}
.nav-user{display:flex;align-items:center;gap:8px}
.nav-av{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff}
.nav-info-name{font-size:11px;font-weight:700;color:var(--wa-text)}
.nav-info-role{font-size:9px;color:var(--wa-text2);text-transform:uppercase}
.wrap{height:calc(100% - 48px);display:flex;overflow:hidden}
.sidebar{width:360px;background:var(--wa-side);border-right:1px solid var(--wa-border);display:flex;flex-direction:column;flex-shrink:0}
.sb-hdr{padding:12px 16px 8px;display:flex;align-items:center;gap:8px;flex-shrink:0}
.sb-hdr-title{font-size:19px;font-weight:800;flex:1}
.sb-icon-btn{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--wa-icon);transition:background .15s;font-size:15px}
.sb-icon-btn:hover{background:rgba(255,255,255,.06)}
.sb-tabs{display:flex;border-bottom:1px solid var(--wa-border);flex-shrink:0}
.sb-tab{flex:1;padding:10px 6px;text-align:center;font-size:12px;font-weight:700;color:var(--wa-text2);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s;letter-spacing:.04em;text-transform:uppercase}
.sb-tab.on{color:var(--wa-green);border-bottom-color:var(--wa-green)}
.sb-search{padding:8px 12px;flex-shrink:0}
.sb-search-inner{display:flex;align-items:center;gap:8px;background:var(--wa-panel);border-radius:9px;padding:7px 12px}
.sb-search-inner i{color:var(--wa-text2);font-size:12px}
.sb-search-inner input{background:none;border:none;outline:none;color:var(--wa-text);font-size:13px;width:100%;font-family:inherit}
.sb-search-inner input::placeholder{color:var(--wa-text2)}
.sb-list{flex:1;overflow-y:auto}
.sb-panel{display:none;flex-direction:column;height:100%}
.sb-panel.on{display:flex}
.contact{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:background .12s;border-bottom:1px solid rgba(255,255,255,.03);position:relative}
.contact:hover{background:var(--wa-hover)}
.contact.active{background:var(--wa-active)}
.contact-av{width:46px;height:46px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;position:relative}
.contact-av.priv{background:linear-gradient(135deg,#667eea,#764ba2)}
.contact-av.grp{background:linear-gradient(135deg,var(--wa-green),#006a52)}
.contact-av-txt{color:#fff}
.contact-info{flex:1;min-width:0}
.contact-name{font-size:14px;font-weight:700;color:var(--wa-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.contact-sub{font-size:12px;color:var(--wa-text2);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.contact-meta{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0}
.unread-pill{background:var(--wa-green);color:#111;min-width:18px;height:18px;border-radius:9px;font-size:10px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;padding:0 5px}
.role-chip{font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;text-transform:uppercase;opacity:.9}
.btn-newgrp{display:flex;align-items:center;gap:10px;padding:12px 16px;cursor:pointer;background:rgba(0,168,132,.06);border-bottom:1px solid rgba(0,168,132,.1);transition:background .15s}
.btn-newgrp:hover{background:rgba(0,168,132,.12)}
.btn-newgrp-ico{width:42px;height:42px;border-radius:50%;background:rgba(0,168,132,.15);display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--wa-green)}
.btn-newgrp-txt{font-size:13.5px;font-weight:700;color:var(--wa-green)}
.chat-main{flex:1;display:flex;flex-direction:column;background:var(--wa-msg-bg);min-width:0;position:relative}
.chat-main::before{content:'';position:absolute;inset:0;opacity:.04;pointer-events:none;background-image:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%2300a884' fill-opacity='1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C%2Fg%3E%3C%2Fg%3E%3C%2Fsvg%3E")}
.no-chat{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;padding:30px}
.no-chat-ico{width:96px;height:96px;border-radius:50%;background:rgba(0,168,132,.08);display:flex;align-items:center;justify-content:center;font-size:40px;color:var(--wa-green);margin-bottom:6px}
.no-chat h2{font-size:22px;font-weight:800;color:var(--wa-text);letter-spacing:-.02em}
.no-chat p{font-size:13px;color:var(--wa-text2);max-width:300px;line-height:1.6}
.chat-hdr{height:58px;background:var(--wa-side);border-bottom:1px solid var(--wa-border);display:flex;align-items:center;gap:12px;padding:0 16px;flex-shrink:0;z-index:10}
.chat-hdr-av{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;flex-shrink:0;cursor:pointer}
.chat-hdr-av.priv{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
.chat-hdr-av.grp{background:linear-gradient(135deg,var(--wa-green),#006a52);color:#fff;font-size:13px}
.chat-hdr-info{flex:1;min-width:0}
.chat-hdr-name{font-size:15px;font-weight:800;color:var(--wa-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chat-hdr-sub{font-size:11.5px;color:var(--wa-text2);margin-top:1px}
.chat-hdr-actions{display:flex;gap:4px}
.hdr-btn{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--wa-icon);transition:background .15s;font-size:15px}
.hdr-btn:hover{background:rgba(255,255,255,.07)}
.msgs-zone{flex:1;overflow-y:auto;padding:16px 8%;display:flex;flex-direction:column;gap:3px}
.msgs-zone::-webkit-scrollbar{width:4px}
.date-sep{text-align:center;margin:10px 0}
.date-sep span{background:var(--wa-panel);color:var(--wa-text2);font-size:11px;font-weight:600;padding:4px 12px;border-radius:8px}
.msg{display:flex;flex-direction:column;max-width:65%;animation:popIn .18s ease;margin-bottom:2px}
@keyframes popIn{from{opacity:0;transform:scale(.97) translateY(4px)}to{opacity:1;transform:none}}
.msg.sent{align-self:flex-end}
.msg.recv{align-self:flex-start}
.msg-grp-sender{font-size:11px;font-weight:700;color:var(--wa-green);margin-bottom:3px;padding-left:12px}
.msg-bubble{padding:6px 12px 22px;border-radius:var(--radius);position:relative;min-width:80px}
.msg.sent .msg-bubble{background:var(--wa-sent);border-radius:8px 0 8px 8px}
.msg.recv .msg-bubble{background:var(--wa-recv);border-radius:0 8px 8px 8px}
.msg-text{font-size:14px;line-height:1.55;color:var(--wa-text);white-space:pre-wrap;word-break:break-word}
.msg-meta{position:absolute;bottom:4px;right:8px;display:flex;align-items:center;gap:3px}
.msg-time{font-size:10px;color:var(--wa-time)}
.tick{font-size:12px;color:var(--wa-text2)}
.tick.read{color:var(--wa-blue)}

/* IMAGE */
.msg-img-wrap{border-radius:6px;overflow:hidden;margin-bottom:4px;max-width:280px;cursor:zoom-in;position:relative}
.msg-img-wrap img{width:100%;max-height:240px;object-fit:cover;display:block;transition:filter .2s}
.msg-img-wrap:hover img{filter:brightness(.9)}
.msg-img-wrap .dl-overlay{position:absolute;bottom:6px;right:6px;background:rgba(0,0,0,.5);border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;opacity:0;transition:opacity .2s}
.msg-img-wrap:hover .dl-overlay{opacity:1}

/* AUDIO */
.msg-audio{display:flex;align-items:center;gap:10px;padding:4px 0 4px;min-width:220px}
.audio-play-btn{width:38px;height:38px;border-radius:50%;background:var(--wa-green);border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;color:#111;font-size:14px;transition:all .15s}
.audio-play-btn:hover{background:var(--wa-green2);transform:scale(1.05)}
.audio-waveform{flex:1;height:28px;cursor:pointer;position:relative}
.audio-waveform canvas{width:100%;height:100%}
.audio-duration{font-size:11px;color:var(--wa-text2);min-width:32px;text-align:right}

/* VIDEO PLAYER INTÉGRÉ */
.msg-video-wrap{border-radius:8px;overflow:hidden;max-width:320px;background:#000;position:relative}
.msg-video-wrap video{width:100%;max-height:240px;display:block;border-radius:8px}
.msg-video-controls{display:flex;align-items:center;gap:6px;padding:6px 8px;background:rgba(0,0,0,.6);position:absolute;bottom:0;left:0;right:0;opacity:0;transition:opacity .2s}
.msg-video-wrap:hover .msg-video-controls{opacity:1}
.vid-btn{background:none;border:none;color:#fff;cursor:pointer;font-size:13px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:4px;transition:background .15s}
.vid-btn:hover{background:rgba(255,255,255,.2)}
.vid-progress{flex:1;height:3px;background:rgba(255,255,255,.3);border-radius:2px;cursor:pointer;position:relative}
.vid-progress-bar{height:100%;background:var(--wa-green);border-radius:2px;pointer-events:none;transition:width .1s}
.vid-time{font-size:10px;color:rgba(255,255,255,.8);white-space:nowrap;font-family:'DM Mono',monospace;min-width:60px;text-align:right}
.vid-fullscreen-btn{font-size:11px}
.video-thumb-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.3);cursor:pointer;border-radius:8px}
.video-thumb-overlay i{font-size:40px;color:rgba(255,255,255,.9);text-shadow:0 2px 10px rgba(0,0,0,.5);transition:transform .15s}
.video-thumb-overlay:hover i{transform:scale(1.1)}

/* FILE */
.msg-file{display:flex;align-items:center;gap:10px;padding:4px 0;min-width:180px}
.file-ico{width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--wa-icon);flex-shrink:0}
.file-info-name{font-size:12.5px;font-weight:700;color:var(--wa-text);word-break:break-all;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.file-info-dl{font-size:11px;color:var(--wa-green);text-decoration:none;display:block;margin-top:2px}
.file-info-dl:hover{text-decoration:underline}

/* INPUT ZONE */
.input-zone{background:var(--wa-side);border-top:1px solid var(--wa-border);padding:10px 14px;flex-shrink:0}
.input-toolbar{display:flex;align-items:center;gap:6px}
.inp-btn{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--wa-icon);font-size:16px;transition:all .15s;border:none;background:none;flex-shrink:0}
.inp-btn:hover{background:rgba(255,255,255,.06);color:var(--wa-text)}
.inp-btn.green{color:var(--wa-green)}
.inp-btn.green:hover{background:rgba(0,168,132,.12)}
.inp-textarea-wrap{flex:1;background:var(--wa-input);border-radius:22px;display:flex;align-items:center;padding:8px 14px;gap:8px}
.inp-ta{flex:1;background:none;border:none;outline:none;color:var(--wa-text);font-size:14px;resize:none;max-height:120px;min-height:24px;line-height:1.5;font-family:inherit;overflow-y:auto}
.inp-ta::placeholder{color:var(--wa-text2)}
.send-btn{width:44px;height:44px;border-radius:50%;background:var(--wa-green);border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#111;font-size:17px;flex-shrink:0;transition:all .18s;box-shadow:0 2px 10px rgba(0,168,132,.3)}
.send-btn:hover{background:var(--wa-green2);transform:scale(1.06)}
.send-btn:disabled{opacity:.4;cursor:not-allowed;transform:none}

/* RECORDING BAR */
.rec-bar-wrap{display:none;align-items:center;gap:6px;margin-bottom:8px}
.rec-bar-wrap.show{display:flex}
.rec-bar{display:flex;align-items:center;gap:10px;flex:1;background:rgba(241,92,109,.1);border-radius:22px;padding:8px 14px;border:1px solid rgba(241,92,109,.2)}
.rec-dot{width:10px;height:10px;border-radius:50%;background:var(--wa-red);animation:pulse .8s infinite;flex-shrink:0}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.rec-timer{font-size:14px;font-weight:700;color:var(--wa-red);font-family:'DM Mono',monospace;min-width:42px}
.rec-wave-canvas{flex:1;height:36px}
.rec-label{font-size:11px;color:var(--wa-text2);font-weight:600}
.rec-cancel{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--wa-icon);font-size:13px;transition:all .15s;border:none}
.rec-cancel:hover{background:rgba(241,92,109,.2);color:var(--wa-red)}
.rec-stop{width:32px;height:32px;border-radius:50%;background:var(--wa-green);display:flex;align-items:center;justify-content:center;cursor:pointer;color:#111;font-size:13px;transition:all .15s;border:none}
.rec-stop:hover{background:var(--wa-green2)}

/* IMAGE PREVIEW */
.img-preview-bar{display:none;padding:8px 14px;border-top:1px solid var(--wa-border);background:var(--wa-panel)}
.img-preview-bar.show{display:flex;align-items:center;gap:10px}
.img-preview-thumb{width:56px;height:56px;border-radius:8px;object-fit:cover}
.img-preview-name{flex:1;font-size:12px;color:var(--wa-text2);word-break:break-all}
.img-preview-rm{cursor:pointer;color:var(--wa-red);font-size:14px;padding:4px}

/* EMOJI */
.ep{position:fixed;background:var(--wa-side);border:1px solid var(--wa-border);border-radius:14px;padding:12px;box-shadow:0 8px 40px rgba(0,0,0,.6);display:none;z-index:600;width:300px}
.ep.show{display:block}
.ep-cats{display:flex;gap:4px;margin-bottom:10px;overflow-x:auto;padding-bottom:2px}
.ep-cat{font-size:18px;cursor:pointer;padding:5px;border-radius:8px;transition:background .12s;flex-shrink:0}
.ep-cat.on,.ep-cat:hover{background:rgba(255,255,255,.08)}
.ep-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:3px;max-height:200px;overflow-y:auto}
.ep-em{font-size:20px;cursor:pointer;padding:5px;border-radius:7px;text-align:center;transition:all .12s;line-height:1}
.ep-em:hover{background:rgba(255,255,255,.08);transform:scale(1.15)}

/* MODAL */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:800;display:none;align-items:center;justify-content:center}
.modal-bg.show{display:flex}
.modal{background:var(--wa-side);border:1px solid var(--wa-border);border-radius:16px;padding:24px;width:440px;max-width:96vw;max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.7)}
.modal h2{font-size:17px;font-weight:800;margin-bottom:18px;display:flex;align-items:center;gap:9px;color:var(--wa-text)}
.modal h2 i{color:var(--wa-green)}
.form-g{margin-bottom:14px}
.form-g label{display:block;font-size:10px;font-weight:700;color:var(--wa-text2);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px}
.form-g input{width:100%;padding:10px 14px;background:var(--wa-panel);border:1.5px solid var(--wa-border);border-radius:10px;color:var(--wa-text);font-size:13.5px;font-family:inherit}
.form-g input:focus{outline:none;border-color:var(--wa-green)}
.mbr-list{max-height:240px;overflow-y:auto;border:1px solid var(--wa-border);border-radius:10px}
.mbr-row{display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;transition:background .12s}
.mbr-row:hover{background:rgba(255,255,255,.04)}
.mbr-row input[type=checkbox]{width:15px;height:15px;accent-color:var(--wa-green);cursor:pointer}
.mbr-row-av{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0}
.mbr-row-name{font-size:13px;font-weight:600;color:var(--wa-text)}
.mbr-row-role{font-size:10px;color:var(--wa-text2)}
.modal-btns{display:flex;gap:8px;justify-content:flex-end;margin-top:18px}
.btn-cancel{padding:9px 18px;border-radius:10px;border:1px solid var(--wa-border);background:transparent;color:var(--wa-text2);font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;font-family:inherit}
.btn-cancel:hover{color:var(--wa-text)}
.btn-ok{padding:9px 20px;border-radius:10px;border:none;background:var(--wa-green);color:#111;font-size:13px;font-weight:800;cursor:pointer;transition:all .15s;font-family:inherit}
.btn-ok:hover{background:var(--wa-green2)}

/* CAMERA MODAL */
.cam-modal{width:520px}
.cam-mode-tabs{display:flex;gap:8px;margin-bottom:14px}
.cam-mode-tab{flex:1;padding:8px;border-radius:8px;border:1.5px solid var(--wa-border);background:transparent;color:var(--wa-text2);font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px}
.cam-mode-tab.on{border-color:var(--wa-green);color:var(--wa-green);background:rgba(0,168,132,.1)}
.cam-video{width:100%;border-radius:10px;display:block;background:#000;max-height:300px}
.cam-canvas{display:none}
.cam-actions{display:flex;justify-content:center;gap:12px;margin-top:14px;flex-wrap:wrap}
.cam-btn{padding:10px 22px;border-radius:24px;border:none;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:7px;transition:all .15s}
.cam-capture{background:var(--wa-green);color:#111}
.cam-capture:hover{background:var(--wa-green2)}
.cam-rec-start{background:var(--wa-red);color:#fff}
.cam-rec-start:hover{background:#e04558}
.cam-rec-stop{background:rgba(255,255,255,.12);color:var(--wa-text)}
.cam-rec-stop:hover{background:rgba(255,255,255,.2)}
.cam-retake{background:rgba(255,255,255,.07);color:var(--wa-text)}
.cam-retake:hover{background:rgba(255,255,255,.12)}
.cam-send{background:#005c4b;color:var(--wa-text)}
.cam-send:hover{background:var(--wa-sent)}
.cam-rec-timer{font-size:13px;font-weight:700;color:var(--wa-red);font-family:'DM Mono',monospace;display:none;padding:8px 0;text-align:center}
.cam-rec-timer.show{display:block}

/* MEMBERS PANEL */
.mbrs-panel{width:240px;background:var(--wa-side);border-left:1px solid var(--wa-border);display:none;flex-direction:column;flex-shrink:0}
.mbrs-panel.show{display:flex}
.mbrs-panel-hdr{padding:14px 16px;border-bottom:1px solid var(--wa-border);font-size:14px;font-weight:800;display:flex;align-items:center;justify-content:space-between}
.mbrs-panel-hdr i{color:var(--wa-green)}
.mbrs-panel-close{cursor:pointer;color:var(--wa-text2);font-size:13px;padding:4px;border-radius:50%}
.mbrs-panel-close:hover{background:rgba(255,255,255,.06);color:var(--wa-text)}
.mbrs-panel-list{flex:1;overflow-y:auto;padding:8px}
.mbr-item{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px}
.mbr-item-av{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--wa-green),#006a52);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff}
.mbr-item-name{font-size:12.5px;font-weight:600;color:var(--wa-text)}
.mbr-item-role{font-size:10px;color:var(--wa-text2)}

/* LIGHTBOX */
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:900;display:none;align-items:center;justify-content:center;cursor:zoom-out}
.lightbox.show{display:flex}
.lightbox img{max-width:90vw;max-height:90vh;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,.8)}
.lightbox-close{position:fixed;top:18px;right:22px;color:#fff;font-size:24px;cursor:pointer;opacity:.7;transition:opacity .15s}
.lightbox-close:hover{opacity:1}

/* ERROR PANEL */
.err-panel{background:rgba(241,92,109,.12);border:1px solid rgba(241,92,109,.3);border-radius:10px;padding:12px 16px;margin:8px;font-size:12px;color:var(--wa-red);font-family:'DM Mono',monospace;word-break:break-all;line-height:1.6;white-space:pre-wrap}
.err-panel .err-title{font-size:13px;font-weight:800;margin-bottom:6px;display:flex;align-items:center;gap:6px}

/* TOAST */
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);padding:9px 18px;border-radius:24px;font-size:12.5px;font-weight:700;z-index:1000;display:none;align-items:center;gap:8px;box-shadow:0 4px 20px rgba(0,0,0,.5);white-space:nowrap;max-width:90vw}
.toast.show{display:flex}
.toast.ok{background:var(--wa-green);color:#111}
.toast.err{background:var(--wa-red);color:#fff}

/* SPINNER */
.spin-wrap{flex:1;display:flex;align-items:center;justify-content:center}
.spinner{width:30px;height:30px;border:3px solid var(--wa-border);border-top-color:var(--wa-green);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading-contacts{padding:20px;text-align:center;color:var(--wa-text2);font-size:12px}

/* DEBUG INFO */
.debug-bar{background:rgba(255,210,121,.08);border-bottom:1px solid rgba(255,210,121,.15);padding:4px 12px;font-size:10px;color:var(--wa-gold);font-family:'DM Mono',monospace;flex-shrink:0}
</style>
</head>
<body>

<nav class="navbar">
  <div class="nav-brand">
    <div class="nav-brand-ico"><i class="fab fa-whatsapp"></i></div>
    <span class="nav-brand-txt">ESPERANCE H2O</span>
  </div>
  <a href="<?= project_url('dashboard/index.php') ?>" class="nav-a"><i class="fas fa-home"></i> Accueil</a>
  <a href="<?= project_url('dashboard/admin_nasa.php') ?>" class="nav-a"><i class="fas fa-satellite"></i> Admin</a>
  <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="nav-a"><i class="fas fa-cash-register"></i> Caisse</a>
  <a href="<?= project_url('documents/documents_erp_pro.php') ?>" class="nav-a"><i class="fas fa-file-alt"></i> Documents</a>
  <a href="<?= project_url('messaging/messagerie.php') ?>" class="nav-a cur"><i class="fab fa-whatsapp"></i> Messagerie</a>
  <div class="nav-sp"></div>
  <div class="nav-user">
    <div class="nav-av"><?= eH(mb_substr($me_name,0,1)) ?></div>
    <div>
      <div class="nav-info-name"><?= eH($me_name) ?></div>
      <div class="nav-info-role"><?= eH($me_role) ?></div>
    </div>
  </div>
</nav>

<div class="wrap">
  <!-- SIDEBAR -->
  <div class="sidebar">
    <div class="sb-hdr">
      <span class="sb-hdr-title">Messages</span>
      <div class="sb-icon-btn" id="new-grp-btn" title="Nouveau groupe"><i class="fas fa-users"></i></div>
      <div class="sb-icon-btn" title="Recherche"><i class="fas fa-magnifying-glass"></i></div>
    </div>
    <div class="sb-tabs">
      <div class="sb-tab on" data-tab="priv"><i class="fas fa-user"></i> Privé</div>
      <div class="sb-tab" data-tab="grp"><i class="fas fa-users"></i> Groupes</div>
    </div>
    <div class="sb-search">
      <div class="sb-search-inner">
        <i class="fas fa-magnifying-glass"></i>
        <input id="sb-search-inp" placeholder="Rechercher..." autocomplete="off">
      </div>
    </div>
    <div class="sb-panel on" id="panel-priv">
      <div class="sb-list" id="users-list">
        <div class="loading-contacts"><div class="spinner" style="margin:0 auto 10px"></div>Chargement...</div>
      </div>
    </div>
    <div class="sb-panel" id="panel-grp">
      <div class="sb-list">
        <div class="btn-newgrp" id="btn-newgrp2">
          <div class="btn-newgrp-ico"><i class="fas fa-plus"></i></div>
          <span class="btn-newgrp-txt">Créer un nouveau groupe</span>
        </div>
        <div id="groups-list">
          <div class="loading-contacts"><div class="spinner" style="margin:0 auto 10px"></div>Chargement...</div>
        </div>
      </div>
    </div>
  </div>

  <!-- CHAT MAIN -->
  <div class="chat-main">
    <div class="no-chat" id="no-chat">
      <div class="no-chat-ico"><i class="fab fa-whatsapp"></i></div>
      <h2>Messagerie Interne</h2>
      <p>Sélectionnez une conversation dans la liste pour commencer à discuter</p>
      <small style="color:var(--wa-text2);font-size:11px;margin-top:8px">🔒 Messages chiffrés de bout en bout</small>
    </div>

    <div class="chat-hdr" id="chat-hdr" style="display:none">
      <div class="chat-hdr-av" id="hdr-av"></div>
      <div class="chat-hdr-info">
        <div class="chat-hdr-name" id="hdr-name"></div>
        <div class="chat-hdr-sub" id="hdr-sub"></div>
      </div>
      <div class="chat-hdr-actions">
        <div class="hdr-btn" id="hdr-members-btn" style="display:none" title="Membres du groupe"><i class="fas fa-users"></i></div>
        <div class="hdr-btn" title="Plus d'options"><i class="fas fa-ellipsis-vertical"></i></div>
      </div>
    </div>

    <!-- DEBUG BAR — affiche l'URL et CSRF pour debugger -->
    <div class="debug-bar" id="debug-bar" style="display:none"></div>

    <div class="msgs-zone" id="msgs-zone" style="display:none"></div>

    <div class="img-preview-bar" id="img-preview-bar">
      <img class="img-preview-thumb" id="img-preview-thumb" src="" alt="">
      <span class="img-preview-name" id="img-preview-name"></span>
      <span class="img-preview-rm" id="img-preview-rm" title="Annuler"><i class="fas fa-times"></i></span>
    </div>

    <div class="input-zone" id="input-zone" style="display:none">
      <div class="rec-bar-wrap" id="rec-bar-wrap">
        <div class="rec-bar">
          <div class="rec-dot"></div>
          <span class="rec-label" id="rec-label">🎙 Enregistrement...</span>
          <div class="rec-timer" id="rec-timer">0:00</div>
          <canvas class="rec-wave-canvas" id="rec-wave-canvas"></canvas>
          <button class="rec-cancel" id="rec-cancel" title="Annuler"><i class="fas fa-times"></i></button>
          <button class="rec-stop" id="rec-stop" title="Envoyer"><i class="fas fa-paper-plane"></i></button>
        </div>
      </div>
      <div class="input-toolbar">
        <button class="inp-btn" id="emoji-toggle-btn" title="Emoji" type="button"><i class="fas fa-face-smile"></i></button>
        <button class="inp-btn green" id="cam-btn" title="Caméra / Vidéo" type="button"><i class="fas fa-camera"></i></button>
        <input type="file" id="file-inp" style="display:none" accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip">
        <button class="inp-btn" id="attach-btn" title="Joindre fichier" type="button"><i class="fas fa-paperclip"></i></button>
        <div class="inp-textarea-wrap">
          <textarea class="inp-ta" id="msg-ta" placeholder="Tapez un message" rows="1"></textarea>
        </div>
        <button class="inp-btn" id="mic-btn" title="Message vocal" type="button"><i class="fas fa-microphone"></i></button>
        <button class="send-btn" id="send-btn" type="button" style="display:none"><i class="fas fa-paper-plane"></i></button>
      </div>
    </div>
  </div>

  <!-- MEMBERS PANEL -->
  <div class="mbrs-panel" id="mbrs-panel">
    <div class="mbrs-panel-hdr">
      <span><i class="fas fa-users"></i> Membres</span>
      <span class="mbrs-panel-close" id="mbrs-panel-close"><i class="fas fa-times"></i></span>
    </div>
    <div class="mbrs-panel-list" id="mbrs-panel-list"></div>
  </div>
</div>

<!-- EMOJI PICKER -->
<div class="ep" id="ep">
  <div class="ep-cats" id="ep-cats"></div>
  <div class="ep-grid" id="ep-grid"></div>
</div>

<!-- MODAL GROUPE -->
<div class="modal-bg" id="modal-bg">
  <div class="modal">
    <h2><i class="fas fa-users"></i> Créer un groupe</h2>
    <div class="form-g">
      <label>Nom du groupe *</label>
      <input id="grp-name-inp" placeholder="Ex : Équipe technique, Direction...">
    </div>
    <div class="form-g">
      <label>Ajouter des membres</label>
      <div class="mbr-list" id="modal-mbr-list"></div>
    </div>
    <div class="modal-btns">
      <button class="btn-cancel" id="modal-cancel">Annuler</button>
      <button class="btn-ok" id="modal-ok"><i class="fas fa-check"></i> Créer</button>
    </div>
  </div>
</div>

<!-- MODAL CAMÉRA (Photo + Vidéo) -->
<div class="modal-bg" id="cam-modal-bg">
  <div class="modal cam-modal">
    <h2><i class="fas fa-camera" style="color:var(--wa-green)"></i> Caméra</h2>

    <!-- Mode tabs -->
    <div class="cam-mode-tabs">
      <button class="cam-mode-tab on" id="tab-photo" type="button"><i class="fas fa-camera"></i> Photo</button>
      <button class="cam-mode-tab" id="tab-video" type="button"><i class="fas fa-video"></i> Vidéo</button>
    </div>

    <video class="cam-video" id="cam-video" autoplay playsinline muted></video>
    <canvas class="cam-canvas" id="cam-canvas"></canvas>
    <img id="cam-preview" style="display:none;width:100%;border-radius:10px" alt="Preview">
    <video id="cam-video-preview" style="display:none;width:100%;border-radius:10px;max-height:260px" controls></video>

    <div class="cam-rec-timer" id="cam-rec-timer">⏺ 0:00</div>

    <div class="cam-actions">
      <!-- Photo mode -->
      <button class="cam-btn cam-capture" id="cam-capture"><i class="fas fa-camera"></i> Capturer</button>
      <button class="cam-btn cam-retake" id="cam-retake" style="display:none"><i class="fas fa-redo"></i> Reprendre</button>
      <button class="cam-btn cam-send" id="cam-send" style="display:none"><i class="fas fa-paper-plane"></i> Envoyer</button>
      <!-- Video mode -->
      <button class="cam-btn cam-rec-start" id="cam-rec-start" style="display:none"><i class="fas fa-circle"></i> Enregistrer</button>
      <button class="cam-btn cam-rec-stop" id="cam-rec-stop" style="display:none"><i class="fas fa-stop"></i> Arrêter &amp; Envoyer</button>
      <!-- Commun -->
      <button class="cam-btn cam-retake" id="cam-close-btn"><i class="fas fa-times"></i> Fermer</button>
    </div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox">
  <span class="lightbox-close" id="lightbox-close"><i class="fas fa-times"></i></span>
  <img id="lightbox-img" src="" alt="">
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<script>
'use strict';
/* ── CONFIG ─────────────────────────────────────────────────────────── */
const CSRF    = <?= json_encode($csrf) ?>;
const ME_ID   = <?= json_encode($me_id) ?>;
const ME_NAME = <?= json_encode($me_name) ?>;
const API_URL = <?= json_encode($_SERVER['PHP_SELF']) ?>;

/* ── STATE ──────────────────────────────────────────────────────────── */
let current   = null;
let pollTimer = null;
let lastCount = 0;
let allUsers  = [];
let allGroups = [];
let pendingFile = null;

/* ── ROLE COLORS ────────────────────────────────────────────────────── */
const RC = {admin:'#f15c6d',developer:'#a78bfa',manager:'#ffd279',
  staff:'#00a884',employee:'#53bdeb',Patron:'#f15c6d',PDG:'#f15c6d',
  Directrice:'#ffd279',Secretaire:'#53bdeb',Superviseur:'#fb923c',informaticien:'#a78bfa'};
function rc(r){ return RC[r]||'#8696a0'; }

/* ── HELPERS ────────────────────────────────────────────────────────── */
function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }
function q(sel){ return document.querySelector(sel); }
function fmt(sec){ sec=Math.floor(sec||0); return Math.floor(sec/60)+':'+(sec%60<10?'0':'')+sec%60; }

function toast(msg, ok=true, duration=null){
  const el=q('#toast');
  el.className='toast show '+(ok?'ok':'err');
  el.innerHTML=`<i class="fas fa-${ok?'check-circle':'exclamation-circle'}"></i> ${esc(msg)}`;
  clearTimeout(el._t);
  el._t=setTimeout(()=>el.classList.remove('show'), duration||(ok?2500:7000));
}

function showDebug(msg){
  const el=q('#debug-bar');
  el.style.display='block';
  el.textContent='[DEBUG] '+msg;
}

// Bouton diagnostic uploads
async function runDiag(){
  try {
    const d=await api({ajax:'diag'});
    if(d.ok){
      const i=d.diag;
      const lines=[
        `PHP user: ${i.php_user}`,
        `Dir: ${i.__DIR__}/uploads/chat/`,
        `Dir existe: ${i.dir_exists} | Parent existe: ${i.parent_exists}`,
        `Dir writable: ${i.dir_writable} | Perms: ${i.dir_perms}`,
        `Parent writable: ${i.parent_writable} | Perms: ${i.parent_perms}`,
        `upload_max_filesize: ${i.upload_max_filesize} | post_max_size: ${i.post_max_size}`,
        i.mkdir_attempt?`mkdir attempt: ${i.mkdir_attempt}`:'',
        ``,
        `SI NON WRITABLE → exécuter sur le serveur:`,
        `  ${i.fix_command}`,
      ].filter(Boolean);
      const el=q('#debug-bar');
      el.style.display='block';
      el.style.whiteSpace='pre-wrap';
      el.textContent=lines.join('\n');
      console.log('[DIAG]',d.diag);
    }
  } catch(e){ toast('Diag error: '+e.message,false); }
}

function showErrorInChat(title, detail){
  const mz=q('#msgs-zone');
  mz.innerHTML=`<div style="padding:20px"><div class="err-panel">
    <div class="err-title"><i class="fas fa-exclamation-triangle"></i> ${esc(title)}</div>
    <div>${esc(detail)}</div>
  </div></div>`;
}

/* ── AJAX — FIXED CSRF ──────────────────────────────────────────────── */
// CRITICAL FIX: URLSearchParams n'accepte qu'un objet simple.
// Le csrf_token est inclus UNE SEULE FOIS dans le body.
async function api(body){
  const params = new URLSearchParams();
  params.append('csrf_token', CSRF);
  for(const [k,v] of Object.entries(body)){
    params.append(k, String(v));
  }
  console.log('[API] POST action='+body.ajax, Object.fromEntries(params));
  const res = await fetch(API_URL, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: params.toString()
  });
  const text = await res.text();
  console.log('[API] Response status='+res.status, text.substring(0,200));
  if(!res.ok) throw new Error('HTTP '+res.status+' — '+text.substring(0,300));
  try { return JSON.parse(text); }
  catch(e){ throw new Error('Réponse non-JSON: '+text.substring(0,200)); }
}

// CRITICAL FIX: Pour FormData (upload), on ajoute le csrf_token dans le FormData directement.
// PAS de header Content-Type (le browser le met automatiquement avec le boundary).
async function apiForm(fd){
  fd.append('csrf_token', CSRF);
  console.log('[API FORM] Uploading file...');
  const res = await fetch(API_URL, {method:'POST', body:fd});
  const text = await res.text();
  console.log('[API FORM] Response status='+res.status, text.substring(0,200));
  if(!res.ok) throw new Error('HTTP '+res.status+' — '+text.substring(0,300));
  try { return JSON.parse(text); }
  catch(e){ throw new Error('Réponse non-JSON: '+text.substring(0,200)); }
}

/* ══════════════════════════════════════════════════════════════════════
   TABS
══════════════════════════════════════════════════════════════════════ */
document.querySelectorAll('.sb-tab').forEach(tab=>{
  tab.addEventListener('click',()=>{
    const t=tab.dataset.tab;
    document.querySelectorAll('.sb-tab').forEach(x=>x.classList.toggle('on',x.dataset.tab===t));
    document.querySelectorAll('.sb-panel').forEach(x=>x.classList.toggle('on',x.id==='panel-'+t));
    q('#sb-search-inp').value='';
    if(t==='priv') renderUsers(allUsers); else renderGroups(allGroups);
  });
});

/* SEARCH */
q('#sb-search-inp').addEventListener('input',function(){
  const v=this.value.toLowerCase().trim();
  const activeTab=q('.sb-tab.on')?.dataset.tab;
  if(activeTab==='priv') renderUsers(v?allUsers.filter(u=>(u.username||'').toLowerCase().includes(v)):allUsers);
  else renderGroups(v?allGroups.filter(g=>(g.name||'').toLowerCase().includes(v)):allGroups);
});

/* ══════════════════════════════════════════════════════════════════════
   LOAD USERS
══════════════════════════════════════════════════════════════════════ */
async function loadUsers(){
  try {
    const d=await api({ajax:'get_users'});
    if(!d.ok){
      q('#users-list').innerHTML=`<div class="err-panel" style="margin:8px"><div class="err-title"><i class="fas fa-exclamation-triangle"></i> Erreur chargement</div>${esc(d.err)}</div>`;
      return;
    }
    allUsers=d.users;
    renderUsers(allUsers);
    pollUnread();
  } catch(e){
    q('#users-list').innerHTML=`<div class="err-panel" style="margin:8px"><div class="err-title"><i class="fas fa-wifi"></i> Erreur réseau</div>${esc(e.message)}</div>`;
    console.error('[loadUsers]',e);
  }
}

function renderUsers(users){
  const el=q('#users-list');
  if(!users.length){ el.innerHTML='<div style="padding:20px;text-align:center;color:var(--wa-text2);font-size:12px">Aucun employé trouvé</div>'; return; }
  el.innerHTML=users.map(u=>{
    const ini=(u.username||'?')[0].toUpperCase();
    const c=rc(u.role);
    const isActive=current?.type==='priv'&&current?.id==u.id;
    return `<div class="contact${isActive?' active':''}" data-type="priv" data-id="${u.id}" data-name="${esc(u.username)}" data-role="${esc(u.role||'')}">
      <div class="contact-av priv"><span class="contact-av-txt">${ini}</span></div>
      <div class="contact-info">
        <div class="contact-name">${esc(u.username)} <span class="role-chip" style="background:${c}22;color:${c}">${esc(u.role||'')}</span></div>
        <div class="contact-sub">Cliquez pour discuter</div>
      </div>
      <div class="contact-meta">
        <div class="unread-pill" id="ub-${u.id}" style="display:none"></div>
      </div>
    </div>`;
  }).join('');
}

/* ══════════════════════════════════════════════════════════════════════
   LOAD GROUPS
══════════════════════════════════════════════════════════════════════ */
async function loadGroups(){
  try {
    const d=await api({ajax:'get_groups'});
    if(!d.ok){ q('#groups-list').innerHTML=`<div class="err-panel" style="margin:8px">${esc(d.err)}</div>`; return; }
    allGroups=d.groups;
    renderGroups(allGroups);
  } catch(e){
    q('#groups-list').innerHTML=`<div class="err-panel" style="margin:8px"><div class="err-title">Erreur</div>${esc(e.message)}</div>`;
    console.error('[loadGroups]',e);
  }
}

function renderGroups(groups){
  const el=q('#groups-list');
  if(!groups.length){ el.innerHTML='<div style="padding:20px;text-align:center;color:var(--wa-text2);font-size:12px">Aucun groupe — créez-en un !</div>'; return; }
  el.innerHTML=groups.map(g=>{
    const isActive=current?.type==='grp'&&current?.id==g.id;
    return `<div class="contact${isActive?' active':''}" data-type="grp" data-id="${g.id}" data-name="${esc(g.name)}" data-mc="${g.mc||0}">
      <div class="contact-av grp"><span class="contact-av-txt"><i class="fas fa-users" style="font-size:14px"></i></span></div>
      <div class="contact-info">
        <div class="contact-name">${esc(g.name)}</div>
        <div class="contact-sub">${g.mc||0} membre${(g.mc||0)>1?'s':''}</div>
      </div>
    </div>`;
  }).join('');
}

/* ══════════════════════════════════════════════════════════════════════
   CLICK CONTACT — EVENT DELEGATION
══════════════════════════════════════════════════════════════════════ */
document.querySelector('.sidebar').addEventListener('click',e=>{
  const row=e.target.closest('.contact');
  if(!row) return;
  const type=row.dataset.type, id=parseInt(row.dataset.id), name=row.dataset.name;
  if(current&&current.type===type&&current.id===id) return;
  document.querySelectorAll('.contact').forEach(x=>x.classList.remove('active'));
  row.classList.add('active');
  if(type==='priv'){ const ub=q('#ub-'+id); if(ub){ub.style.display='none';ub.textContent='';} }
  openChat(type,id,name,row.dataset.role||'',parseInt(row.dataset.mc)||0);
});

/* ══════════════════════════════════════════════════════════════════════
   OPEN CHAT
══════════════════════════════════════════════════════════════════════ */
function openChat(type,id,name,role,memberCount){
  current={type,id,name,role};
  lastCount=0;
  if(pollTimer) clearInterval(pollTimer);

  q('#no-chat').style.display='none';
  q('#chat-hdr').style.display='flex';
  q('#msgs-zone').style.display='flex';
  q('#input-zone').style.display='block';
  q('#mbrs-panel').classList.remove('show');

  const av=q('#hdr-av'), mb=q('#hdr-members-btn');
  if(type==='priv'){
    av.className='chat-hdr-av priv';
    av.innerHTML=`<span style="color:#fff;font-size:16px;font-weight:800">${name[0].toUpperCase()}</span>`;
    q('#hdr-name').textContent=name;
    q('#hdr-sub').textContent=role||'Employé';
    mb.style.display='none';
  } else {
    av.className='chat-hdr-av grp';
    av.innerHTML=`<i class="fas fa-users" style="font-size:13px"></i>`;
    q('#hdr-name').textContent=name;
    q('#hdr-sub').textContent=`${memberCount} membre${memberCount>1?'s':''}`;
    mb.style.display='flex';
    loadGroupMembers(id);
  }

  showDebug(`Chat ouvert: type=${type} id=${id} — API: ${API_URL} — CSRF: ${CSRF.substring(0,8)}...`);

  // Reset du cache de rendu pour le nouveau chat
  renderedMsgIds = new Set();
  lastMsgHash = '';
  lastCount = 0;
  q('#msgs-zone').innerHTML='<div class="spin-wrap"><div class="spinner"></div></div>';
  fetchMessages().then(()=>{
    pollTimer=setInterval(()=>{ if(!document.hidden) fetchMessages(); },3000);
  });
}

/* ══════════════════════════════════════════════════════════════════════
   FETCH MESSAGES
══════════════════════════════════════════════════════════════════════ */
async function fetchMessages(){
  if(!current) return;
  try {
    let d;
    if(current.type==='priv') d=await api({ajax:'get_messages',user_id:current.id});
    else d=await api({ajax:'get_group_messages',group_id:current.id});
    if(!d.ok){
      showErrorInChat('Erreur chargement messages', d.err||'Erreur inconnue');
      return;
    }
    renderMessages(d.messages);
  } catch(e){
    console.error('[fetchMessages]',e);
    const mz=q('#msgs-zone');
    if(mz.querySelector('.spin-wrap')||mz.querySelector('.err-panel'))
      showErrorInChat('Erreur réseau/serveur', e.message);
  }
}

/* ══════════════════════════════════════════════════════════════════════
   RENDER MESSAGES
══════════════════════════════════════════════════════════════════════ */
// Stockage des IDs déjà rendus pour éviter de rebuilder inutilement
let renderedMsgIds = new Set();
let lastMsgHash = '';

function renderMessages(msgs){
  const mz=q('#msgs-zone');
  if(!msgs||!msgs.length){
    if(lastMsgHash!=='empty'){
      lastMsgHash='empty'; renderedMsgIds=new Set(); lastCount=0;
      mz.innerHTML='<div class="spin-wrap"><div style="text-align:center;color:var(--wa-text2);font-size:13px"><i class="fab fa-whatsapp" style="font-size:32px;display:block;margin-bottom:10px;opacity:.2"></i>Pas encore de messages.<br>Dites bonjour ! 👋</div></div>';
    }
    return;
  }

  // Hash rapide = dernier id + count → si identique, NE PAS rebuilder
  const lastId = msgs[msgs.length-1].id;
  const newHash = lastId+'_'+msgs.length;
  if(newHash === lastMsgHash) return; // rien de nouveau, on ne touche à rien

  const atBot = mz.scrollHeight-mz.scrollTop-mz.clientHeight < 120;
  const hasNew = msgs.length > lastCount;
  lastCount = msgs.length;
  lastMsgHash = newHash;

  // Rebuild complet seulement si c'est le premier chargement
  // ou si on doit ajouter seulement de nouveaux messages à la fin
  const existingCount = renderedMsgIds.size;

  if(existingCount === 0 || mz.querySelector('.spin-wrap') || mz.querySelector('.err-panel')){
    // Premier chargement : rebuild complet
    renderedMsgIds = new Set();
    let html='', lastDate='';
    msgs.forEach(m=>{
      const d=new Date(m.created_at);
      const ds=d.toLocaleDateString('fr-FR',{day:'numeric',month:'long',year:'numeric'});
      if(ds!==lastDate){ html+=`<div class="date-sep"><span>${esc(ds)}</span></div>`; lastDate=ds; }
      html+=buildMsgHTML(m);
      renderedMsgIds.add(String(m.id));
    });
    mz.innerHTML=html;
    mz.querySelectorAll('.msg-audio[data-src]').forEach(el=>initAudioPlayer(el));
    mz.querySelectorAll('.msg-video-wrap').forEach(el=>initVideoPlayer(el));
    mz.scrollTop=mz.scrollHeight;
    return;
  }

  // Ajouter uniquement les nouveaux messages à la fin (pas de rebuild)
  const newMsgs = msgs.filter(m=>!renderedMsgIds.has(String(m.id)));
  if(newMsgs.length===0) return;

  // Trouver la dernière date déjà affichée
  const dateSeps = mz.querySelectorAll('.date-sep span');
  let lastRenderedDate = dateSeps.length ? dateSeps[dateSeps.length-1].textContent : '';

  newMsgs.forEach(m=>{
    const d=new Date(m.created_at);
    const ds=d.toLocaleDateString('fr-FR',{day:'numeric',month:'long',year:'numeric'});
    if(ds!==lastRenderedDate){
      const sep=document.createElement('div');
      sep.className='date-sep';
      sep.innerHTML=`<span>${esc(ds)}</span>`;
      mz.appendChild(sep);
      lastRenderedDate=ds;
    }
    const div=document.createElement('div');
    div.innerHTML=buildMsgHTML(m);
    const msgEl=div.firstElementChild;
    mz.appendChild(msgEl);
    // Init players pour ce nouveau message
    msgEl.querySelectorAll('.msg-audio[data-src]').forEach(el=>initAudioPlayer(el));
    msgEl.querySelectorAll('.msg-video-wrap').forEach(el=>initVideoPlayer(el));
    renderedMsgIds.add(String(m.id));
  });

  if(atBot) mz.scrollTop=mz.scrollHeight;
}

function buildMsgHTML(m){
  const dir=m.dir||'received';
  const type=m.message_type||'text';
  const d=new Date(m.created_at);
  const time=d.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
  const isGrp=current?.type==='grp';
  const senderLbl=(isGrp&&dir==='received')?`<div class="msg-grp-sender">${esc(m.sender_name||'')}</div>`:'';
  const tick=dir==='sent'?`<span class="tick${m.is_read==1?' read':''}" title="${m.is_read==1?'Lu':'Envoyé'}"><i class="fas fa-check${m.is_read==1?'-double':''}" style="font-size:11px"></i></span>`:'';
  let inner='';

  if(type==='text'){
    inner=`<div class="msg-text">${esc(m.content)}</div>`;

  } else if(type==='image'){
    const src=esc(m.file_path||'');
    inner=`<div class="msg-img-wrap" onclick="openLightbox('${src}')">
      <img src="${src}" alt="${esc(m.file_name||'image')}" loading="lazy">
      <div class="dl-overlay"><i class="fas fa-download"></i></div>
    </div>`;

  } else if(type==='audio'){
    inner=`<div class="msg-audio" data-src="${esc(m.file_path||'')}">
      <button class="audio-play-btn" title="Lecture"><i class="fas fa-play"></i></button>
      <div class="audio-waveform"><canvas width="160" height="28"></canvas></div>
      <span class="audio-duration">0:00</span>
    </div>`;

  } else if(type==='video'){
    // Player vidéo intégré avec contrôles custom
    const src=esc(m.file_path||'');
    inner=`<div class="msg-video-wrap" data-src="${src}">
      <video src="${src}" preload="metadata" style="width:100%;max-height:240px;border-radius:8px;display:block"></video>
      <div class="video-thumb-overlay" onclick="this.style.display='none';this.previousElementSibling.play();this.nextElementSibling.style.opacity=1">
        <i class="fas fa-play-circle"></i>
      </div>
      <div class="msg-video-controls">
        <button class="vid-btn vid-play-btn" title="Lecture/Pause"><i class="fas fa-play"></i></button>
        <div class="vid-progress">
          <div class="vid-progress-bar" style="width:0%"></div>
        </div>
        <span class="vid-time">0:00 / 0:00</span>
        <button class="vid-btn vid-fullscreen-btn" title="Plein écran"><i class="fas fa-expand"></i></button>
      </div>
    </div>`;

  } else {
    const icon=getFileIcon(m.file_name||'');
    inner=`<div class="msg-file">
      <div class="file-ico"><i class="fas ${icon}"></i></div>
      <div>
        <span class="file-info-name" title="${esc(m.file_name||m.content||'')}">${esc(m.file_name||m.content||'Fichier')}</span>
        <a class="file-info-dl" href="${esc(m.file_path||'')}" download="${esc(m.file_name||'')}"><i class="fas fa-download"></i> Télécharger</a>
      </div>
    </div>`;
  }

  return `<div class="msg ${esc(dir)}">
    ${senderLbl}
    <div class="msg-bubble">
      ${inner}
      <div class="msg-meta"><span class="msg-time">${time}</span>${tick}</div>
    </div>
  </div>`;
}

function getFileIcon(name){
  const e=(name.split('.').pop()||'').toLowerCase();
  if(['pdf'].includes(e)) return 'fa-file-pdf';
  if(['doc','docx'].includes(e)) return 'fa-file-word';
  if(['xls','xlsx'].includes(e)) return 'fa-file-excel';
  if(['zip','rar','7z'].includes(e)) return 'fa-file-zipper';
  if(['mp3','wav','ogg','m4a','webm'].includes(e)) return 'fa-file-audio';
  if(['mp4','avi','mov'].includes(e)) return 'fa-file-video';
  return 'fa-file';
}

/* ══════════════════════════════════════════════════════════════════════
   VIDEO PLAYER INTÉGRÉ
══════════════════════════════════════════════════════════════════════ */
function initVideoPlayer(wrap){
  const video=wrap.querySelector('video');
  const playBtn=wrap.querySelector('.vid-play-btn');
  const progressWrap=wrap.querySelector('.vid-progress');
  const progressBar=wrap.querySelector('.vid-progress-bar');
  const timeEl=wrap.querySelector('.vid-time');
  const fsBtn=wrap.querySelector('.vid-fullscreen-btn');

  video.addEventListener('loadedmetadata',()=>{
    timeEl.textContent=fmt(0)+' / '+fmt(video.duration);
  });

  video.addEventListener('timeupdate',()=>{
    const pct=video.duration?video.currentTime/video.duration*100:0;
    progressBar.style.width=pct+'%';
    timeEl.textContent=fmt(video.currentTime)+' / '+fmt(video.duration);
  });

  video.addEventListener('play',()=>{
    playBtn.innerHTML='<i class="fas fa-pause"></i>';
    const overlay=wrap.querySelector('.video-thumb-overlay');
    if(overlay) overlay.style.display='none';
  });
  video.addEventListener('pause',()=>{ playBtn.innerHTML='<i class="fas fa-play"></i>'; });
  video.addEventListener('ended',()=>{
    playBtn.innerHTML='<i class="fas fa-play"></i>';
    progressBar.style.width='0%';
  });

  playBtn.addEventListener('click',e=>{
    e.stopPropagation();
    if(video.paused) video.play(); else video.pause();
  });

  progressWrap.addEventListener('click',e=>{
    e.stopPropagation();
    const r=progressWrap.getBoundingClientRect();
    video.currentTime=(e.clientX-r.left)/r.width*(video.duration||0);
  });

  fsBtn.addEventListener('click',e=>{
    e.stopPropagation();
    if(video.requestFullscreen) video.requestFullscreen();
    else if(video.webkitRequestFullscreen) video.webkitRequestFullscreen();
  });
}

/* ══════════════════════════════════════════════════════════════════════
   AUDIO PLAYER
══════════════════════════════════════════════════════════════════════ */
function initAudioPlayer(el){
  const src=el.dataset.src;
  const playBtn=el.querySelector('.audio-play-btn');
  const canvas=el.querySelector('canvas');
  const durEl=el.querySelector('.audio-duration');
  const ctx=canvas.getContext('2d');
  const audio=new Audio(src);
  let playing=false;

  drawStaticWave(ctx,canvas);

  audio.addEventListener('loadedmetadata',()=>{ durEl.textContent=fmt(audio.duration||0); });
  audio.addEventListener('ended',()=>{ playing=false; playBtn.innerHTML='<i class="fas fa-play"></i>'; drawStaticWave(ctx,canvas); });
  audio.addEventListener('timeupdate',()=>{
    durEl.textContent=fmt(audio.currentTime);
    drawProgress(ctx,canvas,audio.currentTime/(audio.duration||1));
  });
  audio.addEventListener('error',()=>{ toast('Erreur lecture audio: '+src,false); });

  playBtn.addEventListener('click',()=>{
    if(playing){ audio.pause(); playing=false; playBtn.innerHTML='<i class="fas fa-play"></i>'; }
    else { audio.play().catch(e=>toast('Lecture impossible: '+e.message,false)); playing=true; playBtn.innerHTML='<i class="fas fa-pause"></i>'; }
  });
  canvas.addEventListener('click',e=>{
    const r=canvas.getBoundingClientRect();
    audio.currentTime=(e.clientX-r.left)/r.width*(audio.duration||0);
  });
}

function drawStaticWave(ctx,canvas){
  const W=canvas.width||160, H=canvas.height||28;
  ctx.clearRect(0,0,W,H);
  const bars=32,bw=2,gap=3;
  ctx.fillStyle='rgba(134,150,160,.4)';
  for(let i=0;i<bars;i++){
    const h=(Math.sin(i*0.7)+1.2)*H*0.35+H*0.1;
    const x=i*(bw+gap);
    ctx.beginPath(); ctx.roundRect(x,(H-h)/2,bw,h,1); ctx.fill();
  }
}
function drawProgress(ctx,canvas,progress){
  const W=canvas.width||160, H=canvas.height||28;
  ctx.clearRect(0,0,W,H);
  const bars=32,bw=2,gap=3;
  for(let i=0;i<bars;i++){
    const h=(Math.sin(i*0.7)+1.2)*H*0.35+H*0.1;
    const x=i*(bw+gap);
    ctx.fillStyle=(i/bars)<progress?'#00a884':'rgba(134,150,160,.4)';
    ctx.beginPath(); ctx.roundRect(x,(H-h)/2,bw,h,1); ctx.fill();
  }
}

/* ══════════════════════════════════════════════════════════════════════
   SEND MESSAGE — FIXED
══════════════════════════════════════════════════════════════════════ */
async function sendMessage(){
  if(!current){ toast('Aucun contact sélectionné',false); return; }
  const ta=q('#msg-ta'), btn=q('#send-btn');
  const txt=ta.value.trim();

  if(pendingFile){ await sendPendingFile(); return; }
  if(!txt) return;

  ta.disabled=btn.disabled=true;
  try {
    let d;
    if(current.type==='priv') d=await api({ajax:'send_message',recipient_id:current.id,content:txt});
    else d=await api({ajax:'send_group_message',group_id:current.id,content:txt});

    if(d.ok){ ta.value=''; ta.style.height='auto'; updateSendBtn(); await fetchMessages(); }
    else { toast('Erreur: '+d.err, false); console.error('[send_message]',d); }
  } catch(e){
    toast('Erreur envoi: '+e.message, false);
    console.error('[sendMessage]',e);
  }
  finally{ ta.disabled=btn.disabled=false; ta.focus(); }
}

const ta=q('#msg-ta');
ta.addEventListener('input',function(){
  this.style.height='auto';
  this.style.height=Math.min(this.scrollHeight,120)+'px';
  updateSendBtn();
});
ta.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage();} });
q('#send-btn').addEventListener('click',sendMessage);

function updateSendBtn(){
  const has=ta.value.trim().length>0||pendingFile!=null;
  q('#send-btn').style.display=has?'flex':'none';
  q('#mic-btn').style.display=has?'none':'flex';
}

/* ══════════════════════════════════════════════════════════════════════
   UPLOAD FICHIER — FIXED
══════════════════════════════════════════════════════════════════════ */
q('#attach-btn').addEventListener('click',()=>q('#file-inp').click());

q('#file-inp').addEventListener('change',function(){
  const file=this.files[0]; if(!file) return;
  if(file.size>20*1024*1024){ toast('Fichier trop grand (max 20 Mo)',false); this.value=''; return; }
  pendingFile=file;
  const isImg=file.type.startsWith('image/');
  if(isImg){
    const reader=new FileReader();
    reader.onload=e=>{ q('#img-preview-thumb').src=e.target.result; };
    reader.readAsDataURL(file);
  } else {
    q('#img-preview-thumb').src='';
  }
  q('#img-preview-name').textContent=(isImg?'🖼 ':'📎 ')+file.name;
  q('#img-preview-bar').classList.add('show');
  updateSendBtn();
  console.log('[FILE] Selected:',file.name,file.type,file.size);
});

q('#img-preview-rm').addEventListener('click',cancelPendingFile);

function cancelPendingFile(){
  pendingFile=null;
  q('#file-inp').value='';
  q('#img-preview-bar').classList.remove('show');
  updateSendBtn();
}

async function sendPendingFile(){
  if(!pendingFile||!current) return;
  const btn=q('#send-btn');
  btn.disabled=true;
  toast('Envoi de "'+pendingFile.name+'"...');

  // CRITICAL FIX: FormData built correctly, csrf added INSIDE apiForm()
  const fd=new FormData();
  fd.append('file',pendingFile);
  if(current.type==='priv'){
    fd.append('ajax','upload_file');
    fd.append('recipient_id',String(current.id));
  } else {
    fd.append('ajax','upload_group_file');
    fd.append('group_id',String(current.id));
  }

  console.log('[UPLOAD] Sending to',API_URL,'type='+current.type,'id='+current.id,'file='+pendingFile.name);

  try {
    const d=await apiForm(fd);
    cancelPendingFile();
    if(d.ok){ toast('✓ '+pendingFile?.name||'Fichier'+' envoyé !'); await fetchMessages(); }
    else { toast('Erreur upload: '+(d.err||'Erreur inconnue'),false); console.error('[upload]',d); }
  } catch(e){
    toast('Erreur upload: '+e.message,false);
    console.error('[sendPendingFile]',e);
  }
  finally{ btn.disabled=false; }
}

/* ══════════════════════════════════════════════════════════════════════
   CAMÉRA — PHOTO + ENREGISTREMENT VIDÉO
══════════════════════════════════════════════════════════════════════ */
let camStream=null, camBlob=null, camMode='photo';
let camVideoRecorder=null, camVideoChunks=[], camRecTimer=null, camRecSecs=0;

// MODE TABS
q('#tab-photo').addEventListener('click',()=>{ camMode='photo'; updateCamUI(); });
q('#tab-video').addEventListener('click',()=>{ camMode='video'; updateCamUI(); });

function updateCamUI(){
  q('#tab-photo').classList.toggle('on',camMode==='photo');
  q('#tab-video').classList.toggle('on',camMode==='video');
  q('#cam-capture').style.display=camMode==='photo'?'flex':'none';
  q('#cam-rec-start').style.display=camMode==='video'?'flex':'none';
}

q('#cam-btn').addEventListener('click',openCamera);
q('#cam-close-btn').addEventListener('click',closeCamera);
q('#cam-capture').addEventListener('click',capturePhoto);
q('#cam-retake').addEventListener('click',retakePhoto);
q('#cam-send').addEventListener('click',sendCamMedia);
q('#cam-rec-start').addEventListener('click',startVideoRecording);
q('#cam-rec-stop').addEventListener('click',stopVideoRecording);

async function openCamera(){
  if(!current){ toast('Choisissez un contact d\'abord',false); return; }
  try {
    camStream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user'},audio:true});
    q('#cam-video').srcObject=camStream;
    q('#cam-video').style.display='block';
    q('#cam-preview').style.display='none';
    q('#cam-video-preview').style.display='none';
    q('#cam-retake').style.display='none';
    q('#cam-send').style.display='none';
    q('#cam-rec-stop').style.display='none';
    q('#cam-rec-timer').classList.remove('show');
    updateCamUI();
    q('#cam-modal-bg').classList.add('show');
  } catch(e){ toast('Caméra inaccessible: '+e.message,false); console.error('[camera]',e); }
}

function capturePhoto(){
  const video=q('#cam-video');
  const canvas=q('#cam-canvas');
  canvas.width=video.videoWidth;
  canvas.height=video.videoHeight;
  canvas.getContext('2d').drawImage(video,0,0);
  canvas.toBlob(b=>{ camBlob=b; },'image/jpeg',0.92);
  q('#cam-preview').src=canvas.toDataURL('image/jpeg',0.92);
  q('#cam-preview').style.display='block';
  q('#cam-video').style.display='none';
  q('#cam-capture').style.display='none';
  q('#cam-retake').style.display='flex';
  q('#cam-send').style.display='flex';
  if(camStream) camStream.getTracks().forEach(t=>t.stop());
}

function retakePhoto(){
  camBlob=null;
  q('#cam-preview').style.display='none';
  q('#cam-video-preview').style.display='none';
  q('#cam-retake').style.display='none';
  q('#cam-send').style.display='none';
  openCamera();
}

async function startVideoRecording(){
  if(!camStream){ toast('Caméra non initialisée',false); return; }
  const mime=MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus')?'video/webm;codecs=vp9,opus':
             MediaRecorder.isTypeSupported('video/webm')?'video/webm':'video/mp4';
  camVideoRecorder=new MediaRecorder(camStream,{mimeType:mime});
  camVideoChunks=[];
  camRecSecs=0;

  camVideoRecorder.ondataavailable=e=>{ if(e.data.size>0) camVideoChunks.push(e.data); };
  camVideoRecorder.onstop=()=>{
    const blob=new Blob(camVideoChunks,{type:mime});
    camBlob=blob;
    const url=URL.createObjectURL(blob);
    q('#cam-video-preview').src=url;
    q('#cam-video-preview').style.display='block';
    q('#cam-video').style.display='none';
    q('#cam-rec-stop').style.display='none';
    q('#cam-rec-timer').classList.remove('show');
    q('#cam-retake').style.display='flex';
    q('#cam-send').style.display='flex';
    clearInterval(camRecTimer);
  };
  camVideoRecorder.start(100);

  // Timer
  q('#cam-rec-timer').classList.add('show');
  q('#cam-rec-timer').textContent='⏺ 0:00';
  camRecTimer=setInterval(()=>{
    camRecSecs++;
    q('#cam-rec-timer').textContent='⏺ '+fmt(camRecSecs);
    if(camRecSecs>=120){ stopVideoRecording(); toast('Durée max 2 min atteinte'); }
  },1000);

  q('#cam-rec-start').style.display='none';
  q('#cam-rec-stop').style.display='flex';
}

function stopVideoRecording(){
  if(camVideoRecorder&&camVideoRecorder.state!=='inactive') camVideoRecorder.stop();
  clearInterval(camRecTimer);
}

async function sendCamMedia(){
  if(!camBlob||!current){ toast('Rien à envoyer',false); return; }
  closeCamera();
  const isVideo=camMode==='video';
  const ext=isVideo?'webm':'jpg';
  const mimeType=isVideo?(camBlob.type||'video/webm'):'image/jpeg';
  const file=new File([camBlob],`${isVideo?'video':'photo'}_${Date.now()}.${ext}`,{type:mimeType});
  const fd=new FormData();
  fd.append('file',file);
  if(current.type==='priv'){ fd.append('ajax','upload_file'); fd.append('recipient_id',String(current.id)); }
  else { fd.append('ajax','upload_group_file'); fd.append('group_id',String(current.id)); }
  toast('Envoi '+(isVideo?'vidéo':'photo')+'...');
  try {
    const d=await apiForm(fd);
    if(d.ok){ await fetchMessages(); toast('✓ '+(isVideo?'Vidéo':'Photo')+' envoyée !'); }
    else { toast('Erreur: '+(d.err||'Erreur upload'),false); console.error('[camSend]',d); }
  } catch(e){ toast('Erreur: '+e.message,false); console.error('[sendCamMedia]',e); }
}

function closeCamera(){
  if(camVideoRecorder&&camVideoRecorder.state!=='inactive') camVideoRecorder.stop();
  if(camStream) camStream.getTracks().forEach(t=>t.stop());
  clearInterval(camRecTimer);
  camStream=null; camBlob=null; camVideoRecorder=null; camVideoChunks=[];
  q('#cam-modal-bg').classList.remove('show');
  q('#cam-video-preview').src='';
}

/* ══════════════════════════════════════════════════════════════════════
   AUDIO RECORDING — FIXED
══════════════════════════════════════════════════════════════════════ */
let mediaRecorder=null, audioChunks=[], recAudioCtx=null, recAnalyser=null;
let recAnimId=null, recTimerI=null, recSecs=0, isRecording=false;

q('#mic-btn').addEventListener('click',()=>{
  if(!isRecording) startRecording(); else stopRecording();
});
q('#rec-cancel').addEventListener('click',cancelRecording);
q('#rec-stop').addEventListener('click',()=>{ if(isRecording) stopRecording(); });

async function startRecording(){
  if(!current){ toast('Choisissez un contact d\'abord',false); return; }
  try {
    const stream=await navigator.mediaDevices.getUserMedia({audio:true});
    const mime=MediaRecorder.isTypeSupported('audio/webm;codecs=opus')?'audio/webm;codecs=opus':
               MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')?'audio/ogg;codecs=opus':'audio/webm';
    mediaRecorder=new MediaRecorder(stream,{mimeType:mime});
    audioChunks=[]; recSecs=0; isRecording=true;

    mediaRecorder.ondataavailable=e=>{ if(e.data.size>0) audioChunks.push(e.data); };
    mediaRecorder.onstop=()=>{ processRecording(stream,mime); };
    mediaRecorder.start(200);

    q('#rec-timer').textContent='0:00';
    recTimerI=setInterval(()=>{
      recSecs++;
      q('#rec-timer').textContent=fmt(recSecs);
      if(recSecs>=300) stopRecording();
    },1000);

    recAudioCtx=new (window.AudioContext||window.webkitAudioContext)();
    recAnalyser=recAudioCtx.createAnalyser();
    recAudioCtx.createMediaStreamSource(stream).connect(recAnalyser);
    recAnalyser.fftSize=64;
    drawLiveWave();

    q('#mic-btn').style.display='none';
    q('#rec-bar-wrap').classList.add('show');
    q('#attach-btn').style.display='none';
    q('#cam-btn').style.display='none';
    q('#send-btn').style.display='none';

  } catch(e){ toast('Microphone inaccessible: '+e.message,false); console.error('[rec]',e); }
}

function drawLiveWave(){
  if(!recAnalyser) return;
  const canvas=q('#rec-wave-canvas');
  const ctx=canvas.getContext('2d');
  const W=canvas.offsetWidth||200, H=canvas.offsetHeight||36;
  canvas.width=W; canvas.height=H;
  const buf=new Uint8Array(recAnalyser.frequencyBinCount);
  recAnalyser.getByteFrequencyData(buf);
  ctx.clearRect(0,0,W,H);
  const bars=buf.length, bw=Math.max(2,W/bars-1);
  buf.forEach((v,i)=>{
    const h=Math.max(2,(v/255)*H);
    const x=i*(bw+1);
    ctx.fillStyle=`rgba(0,168,132,${0.4+v/512})`;
    ctx.beginPath(); ctx.roundRect(x,(H-h)/2,bw,h,1); ctx.fill();
  });
  recAnimId=requestAnimationFrame(drawLiveWave);
}

function stopRecording(){
  isRecording=false;
  if(mediaRecorder&&mediaRecorder.state!=='inactive') mediaRecorder.stop();
  clearInterval(recTimerI);
  cancelAnimationFrame(recAnimId);
  if(recAudioCtx) recAudioCtx.close().catch(()=>{});
}

function cancelRecording(){
  stopRecording();
  audioChunks=[];
  resetRecUI();
}

function resetRecUI(){
  q('#mic-btn').style.display='flex';
  q('#rec-bar-wrap').classList.remove('show');
  q('#attach-btn').style.display='flex';
  q('#cam-btn').style.display='flex';
  updateSendBtn();
}

async function processRecording(stream,mime){
  stream.getTracks().forEach(t=>t.stop());
  resetRecUI();
  if(!audioChunks.length||!current){ toast('Aucun audio enregistré',false); return; }

  const blob=new Blob(audioChunks,{type:mime});
  const ext=mime.includes('ogg')?'ogg':'webm';
  const file=new File([blob],`vocal_${Date.now()}.${ext}`,{type:mime});
  const fd=new FormData();
  fd.append('file',file);
  if(current.type==='priv'){ fd.append('ajax','upload_file'); fd.append('recipient_id',String(current.id)); }
  else { fd.append('ajax','upload_group_file'); fd.append('group_id',String(current.id)); }

  console.log('[AUDIO REC] Sending blob size='+blob.size+' mime='+mime);
  toast('Envoi message vocal...');
  try {
    const d=await apiForm(fd);
    if(d.ok){ await fetchMessages(); toast('✓ Message vocal envoyé !'); }
    else { toast('Erreur vocal: '+(d.err||'Erreur'),false); console.error('[vocal]',d); }
  } catch(e){ toast('Erreur: '+e.message,false); console.error('[processRecording]',e); }
}

/* ══════════════════════════════════════════════════════════════════════
   EMOJI
══════════════════════════════════════════════════════════════════════ */
const ECATS={
  '😊':[...`😀😃😄😁😆😅😂🤣😊😇🙂🙃😉😌😍🥰😘😗😙😋😛😝😜🤪🤨🧐🤓😎🤩🥳😏😒😞😔😟😕🙁😣😖😫😩🥺😢😭😤😠😡🤬🤯😳🥵🥶😱😨😰😥😓🤗🤔🤫🤥😶😐😑😬🙄😯😦😧😮😲🥱😴🤤😪😵🤐🥴🤢🤮🤧😷🤒🤕🤑🤠😈👿👻💀👽🤖`],
  '👍':[...`👋🤚🖐✋🖖👌🤌🤏✌🤞🤟🤘🤙👈👉👆🖕👇☝👍👎✊👊🤛🤜👏🙌👐🤲🤝🙏✍💅🤳💪🦾`],
  '❤️':[...`❤🧡💛💚💙💜🖤🤍🤎💔💕💞💓💗💖💘💝💟☮✝☯🕉☦🛐⭐💥🔥🌟✨`],
  '🐶':[...`🐶🐱🐭🐹🐰🦊🐻🐼🐨🐯🦁🐮🐷🐸🐵🙈🙉🙊🐔🐧🐦🐤🦆🦅🦉🦇🐺🐗🐴🦄🐝🐛🦋🐌🐞🐜🐢🐍🦎🦖🦕🐙🦑🦐🦞🦀🐡🐠🐟🐬🐳🐋🦈🐊🐅🐆🦓🐘🦛🦏🐪🐫🦒🦘🐃🐂🐄🐎🐖🐏🐑🐕🐈🐓🦚🦜🕊`],
  '🍎':[...`🍏🍎🍊🍋🍌🍉🍇🍓🫐🍒🍑🥭🍍🥥🥝🍅🍆🥑🥦🥬🥒🌶🌽🥕🥐🥯🍞🥖🧀🥚🍳🥞🧇🥓🍔🍟🍕🥙🌮🌯🍝🍜🍲🍛🍣🍱🥟🍤🍙🍚🍘🍥🧁🍰🎂🍭🍬🍫🍿🍩🍪🌰🥜🍯🧃🥤🧋🍵☕🍺🍻🍷🍸🍹🍾`],
  '⚽':[...`⚽🏀🏈⚾🥎🎾🏐🏉🥏🎱🏓🏸🥊🥋🎯🎣🎿🛷🥌🎲🎰🎳🧩🎮🎯`],
  '🌍':[...`🌍🌎🌏🌐🗺🧭⛰🌋🏔🏕🏖🏗🏘🏙🌆🌇🌉🌃🏛🏟🏠🏡🏢🏣🏤🏥🏦🏨🏩🏪🏫🏬🏭🏯🏰🗼🗽⛪🕌🛕⛩🗾🎌🚀🛸🛩✈🚁🚂🚃🚄🚅🚆🚇🚊🚝🚞🚋🚌🚍🚎🏎🚐🚑🚒🚓🚔🚕🚖🚗🚘🚙🛻🚚🚛🚜🏍🛵🚲🛴🛹🛼⛵🚤🛥🛳⛴🚢⚓`],
};

function initEmoji(){
  const cats=q('#ep-cats'), grid=q('#ep-grid');
  const keys=Object.keys(ECATS);
  function showCat(k){
    cats.querySelectorAll('.ep-cat').forEach(c=>c.classList.toggle('on',c.dataset.k===k));
    grid.innerHTML=ECATS[k].map(em=>`<div class="ep-em">${em}</div>`).join('');
  }
  cats.innerHTML=keys.map(k=>`<div class="ep-cat" data-k="${k}">${k}</div>`).join('');
  cats.addEventListener('click',e=>{ const t=e.target.closest('.ep-cat'); if(t) showCat(t.dataset.k); });
  grid.addEventListener('click',e=>{
    const t=e.target.closest('.ep-em'); if(!t) return;
    const ta2=q('#msg-ta'); if(!ta2) return;
    const p=ta2.selectionStart, em=t.textContent;
    ta2.value=ta2.value.slice(0,p)+em+ta2.value.slice(p);
    ta2.selectionStart=ta2.selectionEnd=p+em.length;
    ta2.focus(); updateSendBtn();
    q('#ep').classList.remove('show');
  });
  showCat(keys[0]);
}

q('#emoji-toggle-btn').addEventListener('click',function(){
  const ep=q('#ep'), r=this.getBoundingClientRect();
  ep.style.left=r.left+'px';
  ep.style.bottom=(window.innerHeight-r.top+8)+'px';
  ep.classList.toggle('show');
});
document.addEventListener('click',e=>{
  if(!e.target.closest('#ep')&&!e.target.closest('#emoji-toggle-btn')) q('#ep').classList.remove('show');
});

/* ══════════════════════════════════════════════════════════════════════
   MODAL GROUPE
══════════════════════════════════════════════════════════════════════ */
function openCreateGroup(){
  q('#grp-name-inp').value='';
  q('#modal-mbr-list').innerHTML=allUsers.map(u=>`
    <label class="mbr-row">
      <input type="checkbox" value="${u.id}">
      <div class="mbr-row-av">${(u.username||'?')[0].toUpperCase()}</div>
      <div>
        <div class="mbr-row-name">${esc(u.username)}</div>
        <div class="mbr-row-role">${esc(u.role||'')}</div>
      </div>
    </label>`).join('');
  q('#modal-bg').classList.add('show');
}
[q('#new-grp-btn'),q('#btn-newgrp2')].forEach(b=>b.addEventListener('click',openCreateGroup));
q('#modal-cancel').addEventListener('click',()=>q('#modal-bg').classList.remove('show'));
q('#modal-ok').addEventListener('click',async()=>{
  const name=q('#grp-name-inp').value.trim();
  const checked=[...document.querySelectorAll('#modal-mbr-list input:checked')].map(c=>c.value);
  if(!name){ toast('Nom du groupe requis',false); return; }
  try {
    const d=await api({ajax:'create_group',name,members:JSON.stringify(checked)});
    if(d.ok){
      q('#modal-bg').classList.remove('show');
      toast('Groupe "'+name+'" créé !');
      await loadGroups();
      q('[data-tab="grp"]').click();
      openChat('grp',d.group_id,name,'',checked.length+1);
    } else toast(d.err||'Erreur',false);
  } catch(e){ toast('Erreur: '+e.message,false); console.error('[createGroup]',e); }
});

/* ══════════════════════════════════════════════════════════════════════
   MEMBRES PANEL
══════════════════════════════════════════════════════════════════════ */
q('#hdr-members-btn').addEventListener('click',()=>q('#mbrs-panel').classList.toggle('show'));
q('#mbrs-panel-close').addEventListener('click',()=>q('#mbrs-panel').classList.remove('show'));

async function loadGroupMembers(gid){
  try {
    const d=await api({ajax:'get_group_members',group_id:gid});
    if(!d.ok) return;
    q('#mbrs-panel-list').innerHTML=d.members.map(m=>`
      <div class="mbr-item">
        <div class="mbr-item-av">${(m.username||'?')[0].toUpperCase()}</div>
        <div>
          <div class="mbr-item-name">${esc(m.username)}</div>
          <div class="mbr-item-role">${esc(m.role||'')}</div>
        </div>
      </div>`).join('');
  } catch(e){ console.error('[loadGroupMembers]',e); }
}

/* ══════════════════════════════════════════════════════════════════════
   NOTIFICATIONS & UNREAD
══════════════════════════════════════════════════════════════════════ */
let prevUnread = {}; // Pour détecter les nouveaux messages non lus
let notifPermission = false;

// Demander la permission de notification au premier clic
function requestNotifPermission(){
  if('Notification' in window && Notification.permission==='default'){
    Notification.requestPermission().then(p=>{
      notifPermission=(p==='granted');
      if(notifPermission) toast('🔔 Notifications activées !');
    });
  } else if(Notification.permission==='granted'){
    notifPermission=true;
  }
}

function sendBrowserNotif(title, body, icon=''){
  if(!notifPermission||document.hasFocus()) return;
  try {
    const n=new Notification(title,{body,icon,badge:icon,tag:'chat-'+title});
    setTimeout(()=>n.close(),5000);
    n.onclick=()=>{ window.focus(); n.close(); };
  } catch(e){}
}

function updateTabTitle(totalUnread){
  if(totalUnread>0){
    document.title='('+totalUnread+') Messagerie | ESPERANCE H2O';
  } else {
    document.title='Messagerie | ESPERANCE H2O';
  }
}

async function pollUnread(){
  try {
    const d=await api({ajax:'get_unread'});
    if(!d.ok) return;
    let totalUnread=0;
    document.querySelectorAll('[id^="ub-"]').forEach(el=>{el.style.display='none';el.textContent='';});
    Object.entries(d.unread).forEach(([uid,cnt])=>{
      const c=parseInt(cnt)||0;
      if(c<=0) return;
      totalUnread+=c;
      const el=q('#ub-'+uid);
      if(el){ el.textContent=c>99?'99+':c; el.style.display='inline-flex'; }
      // Notif si nouveau message non lu de cet utilisateur
      const prev=prevUnread[uid]||0;
      if(c>prev && !(current?.type==='priv' && String(current.id)===String(uid))){
        // Trouver le nom de l'expéditeur
        const usr=allUsers.find(u=>String(u.id)===String(uid));
        const name=usr?usr.username:'Nouveau message';
        sendBrowserNotif('💬 '+name, c+' message'+(c>1?'s':'')+ ' non lu'+(c>1?'s':''));
      }
    });
    // Détecter messages group non lus (approximation: messages reçus dans les 30 dernières secondes)
    prevUnread={...d.unread};
    updateTabTitle(totalUnread);
  } catch(e){ console.warn('[pollUnread]',e.message); }
}

/* ══════════════════════════════════════════════════════════════════════
   LIGHTBOX
══════════════════════════════════════════════════════════════════════ */
function openLightbox(src){
  q('#lightbox-img').src=src;
  q('#lightbox').classList.add('show');
}
q('#lightbox').addEventListener('click',e=>{
  if(e.target===q('#lightbox')||e.target===q('#lightbox-close')||q('#lightbox-close').contains(e.target))
    q('#lightbox').classList.remove('show');
});

/* ══════════════════════════════════════════════════════════════════════
   INIT
══════════════════════════════════════════════════════════════════════ */
console.log('[CHAT] Init — ME_ID='+ME_ID+' API='+API_URL+' CSRF='+CSRF.substring(0,8)+'...');
requestNotifPermission();
initEmoji();
loadUsers();
loadGroups();
// Polling non-lus toutes les 5 secondes (plus réactif pour les notifs)
setInterval(()=>{ if(!document.hidden) pollUnread(); },5000);
// Lancer le diagnostic uploads au démarrage (visible en console)
runDiag();
// Activer les notifs au premier clic utilisateur (contournement autoplay policy)
document.addEventListener('click', requestNotifPermission, {once:true});
</script>
</body>
</html>
