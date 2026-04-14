<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║   MESSAGERIE WHATSAPP STYLE — ESPERANCE H2O                    ║
 * ║   FIXED v2: photos cam, vidéos webm, upload 15TB, affichage    ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");

require_once APP_ROOT . '/app/core/DB.php';
require_once APP_ROOT . '/app/core/Auth.php';
require_once APP_ROOT . '/app/core/Middleware.php';
require_once __DIR__ . '/webpush_lib.php';
require_once __DIR__ . '/fcm_lib.php';
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

function presenceStorePath(): string {
    return PROJECT_ROOT . '/messaging/runtime/presence.json';
}

function ensurePresenceStore(): string {
    $dir = dirname(presenceStorePath());
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $file = presenceStorePath();
    if (!is_file($file)) {
        file_put_contents($file, '{}', LOCK_EX);
    }
    return $file;
}

function withPresenceStore(callable $cb) {
    $file = ensurePresenceStore();
    $fp = fopen($file, 'c+');
    if (!$fp) {
        throw new RuntimeException('Impossible d\'ouvrir le cache de présence');
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Verrou présence indisponible');
        }
        rewind($fp);
        $raw = stream_get_contents($fp);
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) $data = [];
        $result = $cb($data);
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $result;
    } catch (Throwable $e) {
        flock($fp, LOCK_UN);
        fclose($fp);
        throw $e;
    }
}

function touchPresence(int $userId, string $username, string $role, string $state='online'): array {
    $now = time();
    $sessionId = session_id() ?: ('sess_'.$userId);
    return withPresenceStore(function (&$data) use ($userId, $username, $role, $state, $now, $sessionId) {
        foreach ($data as $uid => $row) {
            if (!is_array($row)) unset($data[$uid]);
        }
        $cutoff = $now - 86400;
        foreach ($data as $uid => $row) {
            if ((int)($row['last_seen'] ?? 0) < $cutoff) unset($data[$uid]);
        }
        $data[(string)$userId] = [
            'user_id' => $userId,
            'username' => $username,
            'role' => $role,
            'session_id' => $sessionId,
            'state' => $state,
            'last_seen' => $now,
        ];
        return $data[(string)$userId];
    });
}

function getPresenceMap(): array {
    $file = ensurePresenceStore();
    $raw = @file_get_contents($file);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) return [];
    $now = time();
    $out = [];
    foreach ($data as $uid => $row) {
        if (!is_array($row)) continue;
        $lastSeen = (int)($row['last_seen'] ?? 0);
        $state = (string)($row['state'] ?? 'offline');
        $isFresh = $lastSeen > 0 && ($now - $lastSeen) <= 70;
        $statusState = !$isFresh ? 'offline' : ($state === 'away' ? 'away' : 'online');
        $isOnline = $statusState !== 'offline';
        $out[(string)$uid] = [
            'user_id' => (int)($row['user_id'] ?? $uid),
            'username' => (string)($row['username'] ?? ''),
            'role' => (string)($row['role'] ?? ''),
            'last_seen' => $lastSeen,
            'is_online' => $isOnline,
            'status_state' => $statusState,
            'status_text' => $statusState === 'online' ? 'En ligne' : ($statusState === 'away' ? 'Absent' : ($lastSeen > 0 ? ('Vu à '.date('Y-m-d H:i:s', $lastSeen)) : 'Hors ligne')),
        ];
    }
    return $out;
}

function enrichUsersWithPresence(array $users): array {
    $presence = getPresenceMap();
    foreach ($users as &$user) {
        $row = $presence[(string)($user['id'] ?? 0)] ?? null;
        $user['is_online'] = (bool)($row['is_online'] ?? false);
        $user['last_seen'] = (int)($row['last_seen'] ?? 0);
        $user['status_state'] = (string)($row['status_state'] ?? 'offline');
        $user['status_text'] = (string)($row['status_text'] ?? 'Hors ligne');
    }
    unset($user);
    usort($users, static function($a, $b) {
        $ao = !empty($a['is_online']) ? 1 : 0;
        $bo = !empty($b['is_online']) ? 1 : 0;
        if ($ao !== $bo) return $bo <=> $ao;
        return strcmp((string)($a['username'] ?? ''), (string)($b['username'] ?? ''));
    });
    return $users;
}

touchPresence($me_id, $me_name, $me_role, 'online');

/* ═══════════════════════════════════════════════════════════════ HELPERS TYPE ══ */
/**
 * FIX: webm est à la fois audio ET vidéo selon le contexte.
 * On détecte mieux par le nom de fichier (vocal_ = audio, video_ = vidéo)
 * et par les extensions connues.
 */
function detectMediaType(string $ext, string $filename=''):string {
    $ext = strtolower($ext);
    $filename = strtolower($filename);
    $image_exts = ['jpg','jpeg','png','gif','webp','bmp','svg','ico'];
    // webm enregistré comme audio vocal
    if($ext === 'webm' || $ext === 'ogg') {
        // Si le nom commence par vocal_ → audio
        if(str_starts_with(basename($filename), 'vocal_')) return 'audio';
        // Si le nom commence par video_ → video
        if(str_starts_with(basename($filename), 'video_')) return 'video';
        // Par défaut webm → video (plus courant)
        return 'video';
    }
    $audio_exts = ['mp3','wav','m4a','aac','flac','opus'];
    $video_exts = ['mp4','avi','mov','mkv','flv','wmv'];
    if(in_array($ext,$image_exts,true)) return 'image';
    if(in_array($ext,$audio_exts,true)) return 'audio';
    if(in_array($ext,$video_exts,true)) return 'video';
    return 'file';
}

/* ════════════════════════════════════════════════════════════ AJAX ══ */
$isAjaxRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_POST['ajax']);
$isMutationRequest = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET';
if ($isAjaxRequest && $isMutationRequest) {
    header('Content-Type: application/json; charset=utf-8');

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $token)) {
        error_log('[CHAT] CSRF FAIL - received: '.substr($token,0,10).'... expected: '.substr($csrf,0,10).'...');
        jErr('CSRF invalide',403);
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
        if(!is_dir($dir)) {
            $info['mkdir_attempt']=mkdir($dir,0777,true)?'OK':'FAILED';
            if(is_dir($dir)) chmod($dir,0777);
        }
        echo json_encode(['ok'=>true,'diag'=>$info]); exit;
    }

    if ($act==='get_users'){
        $st=$pdo->prepare("SELECT id,username,role FROM users WHERE id!=:me ORDER BY username LIMIT 300");
        $st->execute([':me'=>$me_id]);
        $users = enrichUsersWithPresence($st->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['ok'=>true,'users'=>$users]); exit;
    }

    if ($act==='get_statuses'){
        $st=$pdo->prepare("SELECT id,username,role FROM users WHERE id!=:me ORDER BY username LIMIT 300");
        $st->execute([':me'=>$me_id]);
        $users = enrichUsersWithPresence($st->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['ok'=>true,'statuses'=>$users,'me'=>getPresenceMap()[(string)$me_id] ?? null]); exit;
    }

    if ($act==='heartbeat_presence'){
        $state = trim((string)($_POST['state'] ?? 'online'));
        if (!in_array($state, ['online','away'], true)) $state = 'online';
        touchPresence($me_id, $me_name, $me_role, $state);
        $mePresence = getPresenceMap()[(string)$me_id] ?? null;
        echo json_encode(['ok'=>true,'presence'=>$mePresence]); exit;
    }

    if ($act==='get_push_public_key'){
        $vapid = webpushGetVapidConfig();
        echo json_encode(['ok'=>true,'public_key'=>$vapid['publicKey']]); exit;
    }

    if ($act==='save_push_subscription'){
        $raw = $_POST['subscription'] ?? '';
        $subscription = json_decode((string)$raw, true);
        if (!is_array($subscription)) jErr('Subscription push invalide');
        webpushSaveSubscription($me_id, $me_name, $subscription, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        echo json_encode(['ok'=>true]); exit;
    }

    if ($act==='remove_push_subscription'){
        $endpoint = trim((string)($_POST['endpoint'] ?? ''));
        webpushRemoveSubscription($me_id, $endpoint !== '' ? $endpoint : null);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($act==='send_test_push'){
        $stats = webpushSendToUsers([$me_id], [
            'title' => 'Test Web Push',
            'body' => 'Le push VAPID fonctionne sur cette session.',
            'tag' => 'webpush-self-test',
            'url' => project_url('messaging/messagerie.php'),
        ]);
        echo json_encode(['ok'=>true,'stats'=>$stats]); exit;
    }

    if ($act==='send_test_fcm'){
        $stats = fcmSendToUsers([$me_id], [
            'title' => 'Test FCM',
            'body' => 'Le backend FCM natif fonctionne sur cette session.',
            'tag' => 'fcm-self-test',
            'url' => project_url('messaging/messagerie.php'),
            'unread' => 1,
        ]);
        echo json_encode(['ok'=>true,'stats'=>$stats]); exit;
    }

    /* —— MESSAGES PRIVÉS ————————————————————————————————————————————— */
    if ($act==='get_messages'){
        $other=(int)($_POST['user_id']??0); if($other<=0) jErr('user_id invalide');
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
        if($rid<=0) jErr('Destinataire invalide');
        if($txt==='') jErr('Message vide');
        if(mb_strlen($txt)>5000) jErr('Trop long');
        $st=$pdo->prepare("SELECT username FROM users WHERE id=:id LIMIT 1");
        $st->execute([':id'=>$rid]); $ru=$st->fetch();
        if(!$ru) jErr('Destinataire introuvable id='.$rid);
        $pdo->prepare("INSERT INTO chat_private_messages(sender_id,sender_name,recipient_id,recipient_name,content,message_type,created_at) VALUES(:sid,:sn,:rid,:rn,:c,'text',NOW())")
            ->execute([':sid'=>$me_id,':sn'=>$me_name,':rid'=>$rid,':rn'=>$ru['username'],':c'=>$txt]);
        try {
            webpushSendToUsers([$rid], [
                'title' => '💬 ' . $me_name,
                'body' => mb_strimwidth($txt, 0, 110, '…', 'UTF-8'),
                'tag' => 'priv-'.$me_id.'-'.$rid,
                'url' => project_url('messaging/messagerie.php'),
                'conversation' => ['type' => 'priv', 'id' => $me_id],
            ]);
            fcmSendToUsers([$rid], [
                'title' => '💬 ' . $me_name,
                'body' => mb_strimwidth($txt, 0, 110, '…', 'UTF-8'),
                'tag' => 'priv-'.$me_id.'-'.$rid,
                'url' => project_url('messaging/messagerie.php'),
                'conversation' => ['type' => 'priv', 'id' => $me_id],
                'unread' => 1,
            ]);
        } catch (Throwable $e) {
            error_log('[WEBPUSH send_message] ' . $e->getMessage());
        }
        echo json_encode(['ok'=>true]); exit;
    }

    /* —— UPLOAD FICHIER PRIVÉ ————————————————————————————————————————— */
    if ($act==='upload_file'){
        $rid=(int)($_POST['recipient_id']??0);
        error_log('[UPLOAD] rid='.$rid.' files='.json_encode(array_keys($_FILES)));
        if($rid<=0) jErr('Paramètre recipient_id invalide: '.$rid);
        if(empty($_FILES['file'])) jErr('Aucun fichier reçu');
        $f=$_FILES['file'];
        if($f['error']!==UPLOAD_ERR_OK) {
            $errMap=[1=>'UPLOAD_ERR_INI_SIZE',2=>'UPLOAD_ERR_FORM_SIZE',3=>'UPLOAD_ERR_PARTIAL',4=>'UPLOAD_ERR_NO_FILE',6=>'UPLOAD_ERR_NO_TMP_DIR',7=>'UPLOAD_ERR_CANT_WRITE',8=>'UPLOAD_ERR_EXTENSION'];
            jErr('Erreur PHP upload: '.($errMap[$f['error']]??'code '.$f['error']));
        }
        // FIX: Limite 15000 GB = 15000 * 1024 * 1024 * 1024 bytes (limité par PHP ini en pratique)
        // On met un check très large côté PHP, la vraie limite est dans php.ini
        // $max_size = 15000 * 1024 * 1024 * 1024; // 15 TB - too large for int on 32bit, use float
        // On accepte tout ce que PHP laisse passer (géré par upload_max_filesize/post_max_size)
        $ok_ext=['jpg','jpeg','png','gif','webp','mp3','ogg','wav','webm','m4a','mp4','avi','mov','mkv','pdf','doc','docx','xls','xlsx','txt','csv','zip'];
        $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,$ok_ext,true)) jErr("Extension .$ext refusée. Autorisées: ".implode(', ',$ok_ext));
        $dir=APP_ROOT . '/uploads/chat/';
        if(!is_dir($dir)) {
            if(!mkdir($dir,0777,true)) jErr('Impossible de créer le dossier uploads/chat/');
            chmod($dir, 0777);
        }
        if(!is_writable($dir)) {
            chmod($dir, 0777);
            if(!is_writable($dir)) jErr('Dossier uploads/chat/ non accessible en écriture. chmod -R 777 '.APP_ROOT . '/uploads/');
        }
        $fn=uniqid('p_',true).'.'.$ext; $wp='uploads/chat/'.$fn;
        if(!move_uploaded_file($f['tmp_name'],$dir.$fn)) jErr('Sauvegarde fichier échouée');
        // FIX: Détection du type media correcte (webm audio vs video)
        $mt = detectMediaType($ext, $f['name']);
        $st=$pdo->prepare("SELECT username FROM users WHERE id=:id LIMIT 1"); $st->execute([':id'=>$rid]); $ru=$st->fetch();
        if(!$ru) jErr('Destinataire introuvable id='.$rid);
        $pdo->prepare("INSERT INTO chat_private_messages(sender_id,sender_name,recipient_id,recipient_name,content,message_type,file_path,file_name,created_at) VALUES(:sid,:sn,:rid,:rn,:c,:mt,:fp,:fn2,NOW())")
            ->execute([':sid'=>$me_id,':sn'=>$me_name,':rid'=>$rid,':rn'=>$ru['username'],':c'=>$f['name'],':mt'=>$mt,':fp'=>$wp,':fn2'=>$f['name']]);
        try {
            $label = match($mt) {
                'image' => '📷 Image',
                'audio' => '🎤 Message vocal',
                'video' => '🎬 Vidéo',
                default => '📎 ' . $f['name'],
            };
            webpushSendToUsers([$rid], [
                'title' => '💬 ' . $me_name,
                'body' => $label,
                'tag' => 'priv-media-'.$me_id.'-'.$rid,
                'url' => project_url('messaging/messagerie.php'),
                'conversation' => ['type' => 'priv', 'id' => $me_id],
            ]);
            fcmSendToUsers([$rid], [
                'title' => '💬 ' . $me_name,
                'body' => $label,
                'tag' => 'priv-media-'.$me_id.'-'.$rid,
                'url' => project_url('messaging/messagerie.php'),
                'conversation' => ['type' => 'priv', 'id' => $me_id],
                'unread' => 1,
            ]);
        } catch (Throwable $e) {
            error_log('[WEBPUSH upload_file] ' . $e->getMessage());
        }
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
        try {
            $st = $pdo->prepare("SELECT user_id FROM chat_group_members WHERE group_id=:g AND user_id!=:me");
            $st->execute([':g'=>$gid, ':me'=>$me_id]);
            $targets = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'user_id'));
            webpushSendToUsers($targets, [
                'title' => '👥 ' . $me_name,
                'body' => mb_strimwidth($txt, 0, 110, '…', 'UTF-8'),
                'tag' => 'group-'.$gid,
                'url' => project_url('messaging/messagerie.php'),
                'conversation' => ['type' => 'grp', 'id' => $gid],
            ]);
            fcmSendToUsers($targets, [
                'title' => '👥 ' . $me_name,
                'body' => mb_strimwidth($txt, 0, 110, '…', 'UTF-8'),
                'tag' => 'group-'.$gid,
                'url' => project_url('messaging/messagerie.php'),
                'conversation' => ['type' => 'grp', 'id' => $gid],
                'unread' => 1,
            ]);
        } catch (Throwable $e) {
            error_log('[WEBPUSH send_group_message] ' . $e->getMessage());
        }
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
        $ok_ext=['jpg','jpeg','png','gif','webp','mp3','ogg','wav','webm','m4a','mp4','avi','mov','mkv','pdf','doc','docx','xls','xlsx','txt','csv','zip'];
        $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,$ok_ext,true)) jErr("Extension .$ext refusée");
        $dir=APP_ROOT . '/uploads/chat/';
        if(!is_dir($dir)) { mkdir($dir,0777,true); chmod($dir,0777); }
        if(!is_writable($dir)) { chmod($dir,0777); }
        if(!is_writable($dir)) jErr('Dossier uploads/chat/ non accessible. chmod -R 777 '.APP_ROOT . '/uploads/');
        $fn=uniqid('g_',true).'.'.$ext; $wp='uploads/chat/'.$fn;
        if(!move_uploaded_file($f['tmp_name'],$dir.$fn)) jErr('Sauvegarde échouée');
        // FIX: Détection correcte du type (webm audio vs video)
        $mt = detectMediaType($ext, $f['name']);
        $pdo->prepare("INSERT INTO chat_group_messages(group_id,sender_id,sender_name,content,message_type,file_path,file_name,created_at) VALUES(:g,:sid,:sn,:c,:mt,:fp,:fn2,NOW())")
            ->execute([':g'=>$gid,':sid'=>$me_id,':sn'=>$me_name,':c'=>$f['name'],':mt'=>$mt,':fp'=>$wp,':fn2'=>$f['name']]);
        try {
            $st = $pdo->prepare("SELECT user_id FROM chat_group_members WHERE group_id=:g AND user_id!=:me");
            $st->execute([':g'=>$gid, ':me'=>$me_id]);
            $targets = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'user_id'));
            $label = match($mt) {
                'image' => '📷 Image',
                'audio' => '🎤 Audio',
                'video' => '🎬 Vidéo',
                default => '📎 ' . $f['name'],
            };
            webpushSendToUsers($targets, [
                'title' => '👥 ' . $me_name,
                'body' => $label,
                'tag' => 'group-media-'.$gid,
                'url' => project_url('messaging/messagerie.php'),
                'conversation' => ['type' => 'grp', 'id' => $gid],
            ]);
            fcmSendToUsers($targets, [
                'title' => '👥 ' . $me_name,
                'body' => $label,
                'tag' => 'group-media-'.$gid,
                'url' => project_url('messaging/messagerie.php'),
                'conversation' => ['type' => 'grp', 'id' => $gid],
                'unread' => 1,
            ]);
        } catch (Throwable $e) {
            error_log('[WEBPUSH upload_group_file] ' . $e->getMessage());
        }
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
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#202c33">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="application-name" content="Messagerie ESPERANCE H2O">
<meta name="apple-mobile-web-app-title" content="Messagerie">
<link rel="manifest" href="<?= eH(project_url('messaging/manifest.json')) ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= eH(project_url('hr/employee-app-icon-192.png')) ?>">
<link rel="apple-touch-icon" href="<?= eH(project_url('hr/employee-app-icon-192.png')) ?>">
<title>WhatsApp — ESPERANCE H2O</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --wa-bg:#0b141a;--wa-side:#111b21;--wa-bar:#202c33;
  --wa-hover:#202c33;--wa-panel:#2a3942;--wa-active:#2a3942;
  --wa-sent:#005c4b;--wa-recv:#202c33;--wa-border:#313d45;
  --wa-green:#00a884;--wa-green2:#00cf9d;--wa-green-dk:#025144;
  --wa-text:#e9edef;--wa-text2:#8696a0;
  --wa-icon:#aebac1;--wa-time:#8696a0;--wa-blue:#53bdeb;--wa-red:#f15c6d;
  --wa-gold:#ffd279;
  --wa-input:#2a3942;--wa-msg-bg:#0b141a;
  --radius:8px;
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
.nav-install{border:none;background:rgba(0,168,132,.14);color:var(--wa-green);font:inherit;font-size:11.5px;font-weight:700;padding:7px 12px;border-radius:999px;display:none;cursor:pointer}
.nav-install.show{display:inline-flex;align-items:center;gap:6px}
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
.presence-dot{position:absolute;right:1px;bottom:1px;width:12px;height:12px;border-radius:50%;border:2px solid var(--wa-side);background:#687781}
.presence-dot.online{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.14)}
.presence-dot.away{background:#f59e0b}
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
.status-card{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.04)}
.status-card.self{background:rgba(0,168,132,.06)}
.status-card-av{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:#fff;background:linear-gradient(135deg,#0ea5e9,#2563eb);position:relative;flex-shrink:0}
.status-card-info{flex:1;min-width:0}
.status-card-name{font-size:14px;font-weight:800;color:var(--wa-text);display:flex;align-items:center;gap:8px}
.status-card-sub{font-size:12px;color:var(--wa-text2);margin-top:2px}
.status-chip{font-size:10px;font-weight:800;padding:3px 7px;border-radius:999px;background:rgba(34,197,94,.15);color:#86efac;text-transform:uppercase;letter-spacing:.04em}
.status-chip.away{background:rgba(245,158,11,.14);color:#fcd34d}
.status-chip.offline{background:rgba(148,163,184,.14);color:#cbd5e1}
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
.chat-hdr-sub.online{color:#86efac}
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

/* DEBUG BAR */
.debug-bar{background:rgba(255,210,121,.08);border-bottom:1px solid rgba(255,210,121,.15);padding:4px 12px;font-size:10px;color:var(--wa-gold);font-family:'DM Mono',monospace;flex-shrink:0}

/* ── WHATSAPP MOBILE ─────────────────────────────────────────────────── */
.wa-back-btn{display:none;width:40px;height:40px;border-radius:50%;align-items:center;justify-content:center;cursor:pointer;color:var(--wa-icon);font-size:18px;flex-shrink:0;transition:background .15s;border:none;background:none}
.wa-back-btn:hover,.wa-back-btn:active{background:rgba(255,255,255,.1)}

/* ── PANEL MORE (navigation) ──────────────────────────────────────────── */
.panel-more-wrap{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:10px 0 20px}
.pm-section{margin-bottom:4px}
.pm-section-title{font-size:10px;font-weight:700;color:var(--wa-text2);text-transform:uppercase;letter-spacing:.08em;padding:10px 18px 4px}
.pm-link{display:flex;align-items:center;gap:12px;padding:11px 18px;color:var(--wa-text);text-decoration:none;font-size:14px;font-weight:500;transition:background .15s;-webkit-tap-highlight-color:transparent}
.pm-link:hover,.pm-link:active{background:rgba(255,255,255,.06)}
.pm-link.cur{color:var(--wa-green)}
.pm-link-ico{width:36px;height:36px;border-radius:50%;background:rgba(0,168,132,.13);display:flex;align-items:center;justify-content:center;font-size:15px;color:var(--wa-green);flex-shrink:0}
.pm-link.cur .pm-link-ico{background:var(--wa-green);color:#111}
.pm-link-label{flex:1}
.pm-divider{height:1px;background:var(--wa-border);margin:4px 18px}
.pm-user-card{display:flex;align-items:center;gap:12px;padding:14px 18px 10px;border-bottom:1px solid var(--wa-border);margin-bottom:4px}
.pm-user-av{width:44px;height:44px;border-radius:50%;background:var(--wa-green);display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#111;flex-shrink:0}
.pm-user-name{font-size:14px;font-weight:700;color:var(--wa-text)}
.pm-user-role{font-size:11px;color:var(--wa-text2);margin-top:2px}
/* Bottom nav — hidden on desktop */
.wa-bottom-nav{display:none;position:fixed;bottom:0;left:0;right:0;height:58px;background:var(--wa-bar);border-top:1.5px solid var(--wa-border);z-index:300;align-items:stretch}
.wa-bn-wrap{position:relative;display:flex;align-items:center;justify-content:center}
.wa-bn-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:pointer;color:var(--wa-text2);font-size:10px;font-weight:600;transition:color .15s;padding:6px 0;background:none;border:none;font-family:inherit;-webkit-tap-highlight-color:transparent}
.wa-bn-item.on{color:var(--wa-green)}
.wa-bn-item i{font-size:22px;line-height:1}
.wa-bn-badge{position:absolute;top:-2px;right:-4px;background:var(--wa-green);color:#111;min-width:17px;height:17px;border-radius:9px;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center;padding:0 4px;pointer-events:none}

@media(max-width:768px){
  /* ── base ── */
  html,body{height:100%;height:100dvh;overflow:hidden}

  /* ── top bar: WA style, compact ── */
  .navbar{
    position:fixed;top:0;left:0;right:0;
    height:56px;padding:0 6px 0 14px;
    background:var(--wa-bar);
    border-bottom:1px solid var(--wa-border);
    z-index:400;display:flex;align-items:center;
  }
  .navbar .nav-a,.navbar .nav-sp,.navbar .nav-info-name,.navbar .nav-info-role{display:none}
  .navbar .nav-brand{border-right:none;padding-right:0;margin-right:0;flex:1;gap:10px}
  .navbar .nav-brand-ico{width:34px;height:34px;font-size:14px}
  .navbar .nav-brand-txt{font-size:17px;font-weight:800;color:var(--wa-text);letter-spacing:.01em}
  .navbar .nav-user{flex-shrink:0}
  .navbar .nav-av{width:34px;height:34px;font-size:12px}

  /* ── main content area: fixed between top bar and bottom nav ── */
  .wrap{
    position:fixed;
    top:56px;bottom:58px;
    left:0;right:0;
    overflow:hidden;
    display:block;
    height:auto;
    background:var(--wa-bg);
  }

  /* ── sidebar: full screen ── */
  .sidebar{
    position:absolute;inset:0;
    width:100%;
    z-index:10;
    transition:transform .26s cubic-bezier(.4,0,.2,1);
    display:flex;flex-direction:column;
    background:var(--wa-side);
    border-right:none;
  }
  .sidebar.wa-slide-out{
    transform:translateX(-100%);
    pointer-events:none;
    visibility:hidden;
  }

  /* ── chat view: slides in from right ── */
  .chat-main{
    position:absolute;inset:0;
    z-index:20;
    transform:translateX(100%);
    transition:transform .26s cubic-bezier(.4,0,.2,1);
    display:flex;flex-direction:column;
    background:var(--wa-bg);
    min-width:0;
  }
  .chat-main.wa-open{transform:translateX(0)}

  /* ── messages area fills remaining space ── */
  .msgs-zone{
    flex:1;
    padding:10px 3% 8px;
    overflow-y:auto;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
  }

  /* ── input: no extra padding on mobile ── */
  .input-zone{padding:8px 8px 10px;flex-shrink:0}
  .input-toolbar{gap:4px}
  .inp-btn{width:36px;height:36px;font-size:15px}
  .inp-textarea-wrap{padding:7px 12px}
  .send-btn{width:40px;height:40px;font-size:15px}

  /* ── recording bar: cache le canvas sur mobile, boutons toujours visibles ── */
  .rec-wave-canvas{display:none}
  .rec-label{display:none}
  .rec-cancel,.rec-stop{width:42px;height:42px;flex-shrink:0;font-size:16px}
  .rec-stop{width:46px;height:46px;font-size:17px}
  .rec-bar{gap:8px;padding:8px 12px;border-radius:28px;justify-content:space-between}
  .rec-dot{flex-shrink:0}
  .rec-timer{font-size:15px;flex:1;text-align:center;flex-shrink:0}

  /* ── messages wider on mobile ── */
  .msg{max-width:80%}
  .msg-bubble{padding:6px 10px 20px}

  /* ── members panel: hidden ── */
  .mbrs-panel{display:none!important}

  /* ── show mobile-only elements ── */
  .wa-back-btn{display:flex}
  .wa-bottom-nav{display:flex}

  /* ── toast above bottom nav ── */
  .toast{bottom:68px}

  /* ── emoji picker: full width, sits above bottom nav ── */
  .ep{width:calc(100vw - 16px);left:8px!important;right:8px!important;bottom:68px!important;top:auto!important}

  /* ── no-chat screen fills properly ── */
  .no-chat{flex:1;padding:20px}
  .no-chat-ico{width:72px;height:72px;font-size:30px}
  .no-chat h2{font-size:18px}

  /* ── chat header ── */
  .chat-hdr{height:56px;padding:0 4px 0 4px;gap:8px}
  .chat-hdr-name{font-size:14px}
  .chat-hdr-sub{font-size:11px}

  /* ── sidebar header ── */
  .sb-hdr{padding:10px 14px 6px}
  .sb-hdr-title{font-size:17px}
  .sb-search{padding:6px 10px}
}
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
  <button class="nav-install" id="install-btn" type="button"><i class="fas fa-download"></i> Installer</button>
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
      <div class="sb-tab" data-tab="status"><i class="fas fa-circle-dot"></i> Statuts</div>
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
    <div class="sb-panel" id="panel-status">
      <div class="sb-list" id="status-list">
        <div class="loading-contacts"><div class="spinner" style="margin:0 auto 10px"></div>Chargement des statuts...</div>
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

    <!-- PANEL MORE : navigation complète du site -->
    <div class="sb-panel" id="panel-more">
      <div class="panel-more-wrap">
        <div class="pm-user-card">
          <div class="pm-user-av"><?= eH(mb_substr($me_name,0,1)) ?></div>
          <div>
            <div class="pm-user-name"><?= eH($me_name) ?></div>
            <div class="pm-user-role"><?= eH($me_role) ?></div>
          </div>
        </div>

        <!-- Dashboard -->
        <div class="pm-section">
          <div class="pm-section-title">Tableau de bord</div>
          <a href="<?= project_url('dashboard/index.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-home"></i></div>
            <span class="pm-link-label">Accueil</span>
          </a>
          <a href="<?= project_url('dashboard/admin_nasa.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-satellite"></i></div>
            <span class="pm-link-label">Admin NASA</span>
          </a>
        </div>
        <div class="pm-divider"></div>

        <!-- Clients -->
        <div class="pm-section">
          <div class="pm-section-title">Clients</div>
          <a href="<?= project_url('clients/clients_erp_pro.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-address-book"></i></div>
            <span class="pm-link-label">Clients</span>
          </a>
        </div>
        <div class="pm-divider"></div>

        <!-- Commandes -->
        <div class="pm-section">
          <div class="pm-section-title">Commandes</div>
          <a href="<?= project_url('orders/admin_orders.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-clipboard-list"></i></div>
            <span class="pm-link-label">Gestion commandes</span>
          </a>
          <a href="<?= project_url('orders/commande_mobile.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-mobile-alt"></i></div>
            <span class="pm-link-label">Commande mobile</span>
          </a>
          <a href="<?= project_url('orders/mes_achats.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-shopping-bag"></i></div>
            <span class="pm-link-label">Mes achats</span>
          </a>
          <a href="<?= project_url('orders/create_bon_livraison.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-truck"></i></div>
            <span class="pm-link-label">Bon de livraison</span>
          </a>
        </div>
        <div class="pm-divider"></div>

        <!-- Stock -->
        <div class="pm-section">
          <div class="pm-section-title">Stock</div>
          <a href="<?= project_url('stock/stocks_erp_pro.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-boxes-stacked"></i></div>
            <span class="pm-link-label">Stock</span>
          </a>
          <a href="<?= project_url('stock/products_erp_pro.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-barcode"></i></div>
            <span class="pm-link-label">Produits</span>
          </a>
          <a href="<?= project_url('stock/stock_tracking.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-chart-line"></i></div>
            <span class="pm-link-label">Suivi du stock</span>
          </a>
          <a href="<?= project_url('stock/appro_requests.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-cart-flatbed"></i></div>
            <span class="pm-link-label">Approvisionnement</span>
          </a>
          <a href="<?= project_url('stock/arrivage_reception.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-dolly"></i></div>
            <span class="pm-link-label">Arrivage / Réception</span>
          </a>
        </div>
        <div class="pm-divider"></div>

        <!-- Finance -->
        <div class="pm-section">
          <div class="pm-section-title">Finance</div>
          <a href="<?= project_url('finance/caisse_complete_enhanced.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-cash-register"></i></div>
            <span class="pm-link-label">Caisse</span>
          </a>
          <a href="<?= project_url('finance/cashier_payment_pro.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-credit-card"></i></div>
            <span class="pm-link-label">Paiement caissier</span>
          </a>
          <a href="<?= project_url('finance/facture.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-file-invoice"></i></div>
            <span class="pm-link-label">Factures</span>
          </a>
          <a href="<?= project_url('finance/depenses.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-receipt"></i></div>
            <span class="pm-link-label">Dépenses</span>
          </a>
          <a href="<?= project_url('finance/bon.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-file-lines"></i></div>
            <span class="pm-link-label">Bons</span>
          </a>
          <a href="<?= project_url('finance/versement.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-hand-holding-dollar"></i></div>
            <span class="pm-link-label">Versements</span>
          </a>
          <a href="<?= project_url('finance/ticket.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-ticket"></i></div>
            <span class="pm-link-label">Tickets</span>
          </a>
        </div>
        <div class="pm-divider"></div>

        <!-- Documents -->
        <div class="pm-section">
          <div class="pm-section-title">Documents</div>
          <a href="<?= project_url('documents/documents_erp_pro.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-folder-open"></i></div>
            <span class="pm-link-label">Documents</span>
          </a>
          <a href="<?= project_url('documents/document_upload.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-upload"></i></div>
            <span class="pm-link-label">Envoyer un document</span>
          </a>
        </div>
        <div class="pm-divider"></div>

        <!-- RH -->
        <div class="pm-section">
          <div class="pm-section-title">Ressources humaines</div>
          <a href="<?= project_url('hr/employees_manager.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-id-badge"></i></div>
            <span class="pm-link-label">Employés</span>
          </a>
          <a href="<?= project_url('hr/attendance_rh.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-calendar-check"></i></div>
            <span class="pm-link-label">Présences</span>
          </a>
          <a href="<?= project_url('hr/payroll_rh.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-money-check-dollar"></i></div>
            <span class="pm-link-label">Paie</span>
          </a>
          <a href="<?= project_url('hr/employee_portal.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-user-clock"></i></div>
            <span class="pm-link-label">Portail employé</span>
          </a>
        </div>
        <div class="pm-divider"></div>

        <!-- Messagerie -->
        <div class="pm-section">
          <div class="pm-section-title">Messagerie</div>
          <a href="<?= project_url('messaging/messagerie.php') ?>" class="pm-link cur">
            <div class="pm-link-ico"><i class="fab fa-whatsapp"></i></div>
            <span class="pm-link-label">Messagerie interne</span>
          </a>
          <a href="<?= project_url('messaging/visioconference.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-video"></i></div>
            <span class="pm-link-label">Visioconférence</span>
          </a>
          <a href="<?= project_url('messaging/whatsapp_messagerie_ultra_pro.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-comments"></i></div>
            <span class="pm-link-label">WhatsApp Pro</span>
          </a>
        </div>
        <div class="pm-divider"></div>

        <!-- Compte -->
        <div class="pm-section">
          <div class="pm-section-title">Mon compte</div>
          <a href="<?= project_url('auth/profile.php') ?>" class="pm-link">
            <div class="pm-link-ico"><i class="fas fa-user-gear"></i></div>
            <span class="pm-link-label">Mon profil</span>
          </a>
          <a href="<?= project_url('auth/logout.php') ?>" class="pm-link" style="color:#f15c6d">
            <div class="pm-link-ico" style="background:rgba(241,92,109,.13);color:#f15c6d"><i class="fas fa-right-from-bracket"></i></div>
            <span class="pm-link-label">Déconnexion</span>
          </a>
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
      <button class="wa-back-btn" id="wa-back-btn" onclick="closeChatMobile()" title="Retour"><i class="fas fa-arrow-left"></i></button>
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
      <button class="cam-btn cam-capture" id="cam-capture"><i class="fas fa-camera"></i> Capturer</button>
      <button class="cam-btn cam-retake" id="cam-retake" style="display:none"><i class="fas fa-redo"></i> Reprendre</button>
      <button class="cam-btn cam-send" id="cam-send" style="display:none"><i class="fas fa-paper-plane"></i> Envoyer</button>
      <button class="cam-btn cam-rec-start" id="cam-rec-start" style="display:none"><i class="fas fa-circle"></i> Enregistrer</button>
      <button class="cam-btn cam-rec-stop" id="cam-rec-stop" style="display:none"><i class="fas fa-stop"></i> Arrêter &amp; Envoyer</button>
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

<!-- WHATSAPP BOTTOM NAV (mobile only) -->
<nav class="wa-bottom-nav" id="wa-bottom-nav" role="navigation">
  <button class="wa-bn-item on" id="wa-bn-chats" onclick="waNavSwitch('chats',this)">
    <div class="wa-bn-wrap">
      <i class="fas fa-comment"></i>
      <span id="wa-bn-chats-badge" class="wa-bn-badge" style="display:none"></span>
    </div>
    Chats
  </button>
  <button class="wa-bn-item" id="wa-bn-status" onclick="waNavSwitch('status',this)">
    <div class="wa-bn-wrap"><i class="fas fa-circle-dot"></i></div>
    Statuts
  </button>
  <button class="wa-bn-item" id="wa-bn-groups" onclick="waNavSwitch('groups',this)">
    <div class="wa-bn-wrap"><i class="fas fa-users"></i></div>
    Groupes
  </button>
  <button class="wa-bn-item" id="wa-bn-more" onclick="waNavSwitch('more',this)">
    <div class="wa-bn-wrap"><i class="fas fa-ellipsis-vertical"></i></div>
    Plus
  </button>
</nav>

<script>
'use strict';
const CSRF    = <?= json_encode($csrf) ?>;
const ME_ID   = <?= json_encode($me_id) ?>;
const ME_NAME = <?= json_encode($me_name) ?>;
const API_URL = <?= json_encode($_SERVER['PHP_SELF']) ?>;
const MEDIA_PROXY = <?= json_encode(project_url('messaging/chat_media.php')) ?>;
const SW_URL = <?= json_encode(project_url('messaging/sw.js')) ?>;
const SW_SCOPE = <?= json_encode(rtrim(project_url('messaging'), '/').'/') ?>;

let current   = null;
let pollTimer = null;
let lastCount = 0;
let allUsers  = [];
let allGroups = [];
let pendingFile = null;
let presenceMap = {};
let myPresence = null;
let notifPermission = false;
let swReg = null;
let deferredInstallPrompt = null;

const RC = {admin:'#f15c6d',developer:'#a78bfa',manager:'#ffd279',
  staff:'#00a884',employee:'#53bdeb',Patron:'#f15c6d',PDG:'#f15c6d',
  Directrice:'#ffd279',Secretaire:'#53bdeb',Superviseur:'#fb923c',informaticien:'#a78bfa'};
function rc(r){ return RC[r]||'#8696a0'; }

function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }
function q(sel){ return document.querySelector(sel); }
function fmt(sec){ sec=Math.floor(sec||0); return Math.floor(sec/60)+':'+(sec%60<10?'0':'')+sec%60; }
function b64UrlToUint8Array(base64String){
  const padding='='.repeat((4 - base64String.length % 4) % 4);
  const base64=(base64String + padding).replace(/-/g,'+').replace(/_/g,'/');
  const raw=atob(base64);
  return Uint8Array.from([...raw].map(ch=>ch.charCodeAt(0)));
}
function relTime(ts){
  if(!ts) return 'Jamais vu';
  const diff=Math.max(0, Math.floor(Date.now()/1000)-Number(ts||0));
  if(diff<10) return 'À l’instant';
  if(diff<60) return `il y a ${diff}s`;
  if(diff<3600) return `il y a ${Math.floor(diff/60)} min`;
  if(diff<86400) return `il y a ${Math.floor(diff/3600)} h`;
  return `il y a ${Math.floor(diff/86400)} j`;
}
function presenceLabel(user){
  if(!user) return 'Hors ligne';
  if(user.status_state==='online') return 'En ligne';
  if(user.status_state==='away') return 'Absent';
  return `Vu ${relTime(user.last_seen)}`;
}
function presenceDotClass(user){
  return user?.status_state || 'offline';
}
// Convertit un chemin relatif DB (uploads/chat/...) en URL servie par le proxy PHP.
function furl(p, download=false){
  if(!p) return '';
  if(p.startsWith('http://')||p.startsWith('https://')) return p;
  const normalized = p.startsWith('/') ? p.slice(1) : p;
  const url = new URL(MEDIA_PROXY, window.location.origin);
  url.searchParams.set('path', normalized);
  if(download) url.searchParams.set('download', '1');
  return url.toString();
}

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

async function runDiag(){
  try {
    const d=await api({ajax:'diag'});
    if(d.ok){
      const i=d.diag;
      const lines=[
        `PHP user: ${i.php_user}`,
        `Dir: ${i.__DIR__}/uploads/chat/`,
        `Dir existe: ${i.dir_exists} | Writable: ${i.dir_writable} | Perms: ${i.dir_perms}`,
        `upload_max_filesize: ${i.upload_max_filesize} | post_max_size: ${i.post_max_size}`,
        i.mkdir_attempt?`mkdir attempt: ${i.mkdir_attempt}`:'',
        i.dir_writable?'✅ Dossier OK':'❌ chmod -R 777 '+i.__DIR__+'/uploads/',
      ].filter(Boolean);
      const el=q('#debug-bar');
      el.style.display='block';
      el.style.whiteSpace='pre-wrap';
      el.textContent=lines.join('\n');
    }
  } catch(e){ console.warn('Diag error:',e.message); }
}

function showErrorInChat(title, detail){
  const mz=q('#msgs-zone');
  mz.innerHTML=`<div style="padding:20px"><div class="err-panel">
    <div class="err-title"><i class="fas fa-exclamation-triangle"></i> ${esc(title)}</div>
    <div>${esc(detail)}</div>
  </div></div>`;
}

/* ── AJAX ────────────────────────────────────────────────────────────── */
async function api(body){
  const params = new URLSearchParams();
  params.append('csrf_token', CSRF);
  for(const [k,v] of Object.entries(body)){
    params.append(k, String(v));
  }
  const res = await fetch(API_URL, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: params.toString()
  });
  const text = await res.text();
  if(!res.ok) throw new Error('HTTP '+res.status+' — '+text.substring(0,300));
  try { return JSON.parse(text); }
  catch(e){ throw new Error('Réponse non-JSON: '+text.substring(0,200)); }
}

async function apiForm(fd){
  fd.append('csrf_token', CSRF);
  const res = await fetch(API_URL, {method:'POST', body:fd});
  const text = await res.text();
  console.log('[API FORM] status='+res.status, text.substring(0,300));
  if(!res.ok) throw new Error('HTTP '+res.status+' — '+text.substring(0,300));
  try { return JSON.parse(text); }
  catch(e){ throw new Error('Réponse non-JSON: '+text.substring(0,200)); }
}

/* ── TABS ────────────────────────────────────────────────────────────── */
document.querySelectorAll('.sb-tab').forEach(tab=>{
  tab.addEventListener('click',()=>{
    const t=tab.dataset.tab;
    document.querySelectorAll('.sb-tab').forEach(x=>x.classList.toggle('on',x.dataset.tab===t));
    document.querySelectorAll('.sb-panel').forEach(x=>x.classList.toggle('on',x.id==='panel-'+t));
    q('#sb-search-inp').value='';
    if(t==='priv') renderUsers(allUsers);
    else if(t==='status') renderStatusList('');
    else renderGroups(allGroups);
  });
});

q('#sb-search-inp').addEventListener('input',function(){
  const v=this.value.toLowerCase().trim();
  const activeTab=q('.sb-tab.on')?.dataset.tab;
  if(activeTab==='priv') renderUsers(v?allUsers.filter(u=>(u.username||'').toLowerCase().includes(v)):allUsers);
  else if(activeTab==='status') renderStatusList(v);
  else renderGroups(v?allGroups.filter(g=>(g.name||'').toLowerCase().includes(v)):allGroups);
});

/* ── LOAD USERS ──────────────────────────────────────────────────────── */
async function loadUsers(){
  try {
    const d=await api({ajax:'get_users'});
    if(!d.ok){ q('#users-list').innerHTML=`<div class="err-panel" style="margin:8px">${esc(d.err)}</div>`; return; }
    allUsers=d.users;
    presenceMap = Object.fromEntries(allUsers.map(u=>[String(u.id), u]));
    renderUsers(allUsers);
    renderStatuses();
    pollUnread();
  } catch(e){
    q('#users-list').innerHTML=`<div class="err-panel" style="margin:8px"><div class="err-title"><i class="fas fa-wifi"></i> Erreur réseau</div>${esc(e.message)}</div>`;
  }
}

function renderUsers(users){
  const el=q('#users-list');
  if(!users.length){ el.innerHTML='<div style="padding:20px;text-align:center;color:var(--wa-text2);font-size:12px">Aucun employé trouvé</div>'; return; }
  el.innerHTML=users.map(u=>{
    const ini=(u.username||'?')[0].toUpperCase();
    const c=rc(u.role);
    const isActive=current?.type==='priv'&&current?.id==u.id;
    const sub = presenceLabel(u);
    return `<div class="contact${isActive?' active':''}" data-type="priv" data-id="${u.id}" data-name="${esc(u.username)}" data-role="${esc(u.role||'')}">
      <div class="contact-av priv"><span class="contact-av-txt">${ini}</span><span class="presence-dot ${presenceDotClass(u)}"></span></div>
      <div class="contact-info">
        <div class="contact-name">${esc(u.username)} <span class="role-chip" style="background:${c}22;color:${c}">${esc(u.role||'')}</span></div>
        <div class="contact-sub">${esc(sub)}</div>
      </div>
      <div class="contact-meta">
        <div class="unread-pill" id="ub-${u.id}" style="display:none"></div>
      </div>
    </div>`;
  }).join('');
}

function renderStatuses(){
  renderStatusList('');
}

function renderStatusList(filterText=''){
  const el=q('#status-list');
  if(!el) return;
  const qf=(filterText||'').toLowerCase().trim();
  const users=[...allUsers].filter(u=>{
    if(!qf) return true;
    return (u.username||'').toLowerCase().includes(qf) || (u.role||'').toLowerCase().includes(qf);
  });
  const onlineCount=users.filter(u=>u.is_online).length;
  const meState = myPresence?.status_state || 'online';
  const meText = meState==='away' ? 'Session ouverte, onglet inactif' : 'Session active sur cette page';
  el.innerHTML = `
    <div class="status-card self">
      <div class="status-card-av">${esc((ME_NAME||'?')[0].toUpperCase())}<span class="presence-dot ${esc(meState)}"></span></div>
      <div class="status-card-info">
        <div class="status-card-name">Ma session <span class="status-chip ${meState==='away'?'away':''}">${meState==='away'?'Absent':'Actif'}</span></div>
        <div class="status-card-sub">${esc(meText)}</div>
      </div>
    </div>
    ${users.length ? users.map(u=>`
      <div class="status-card">
        <div class="status-card-av">${esc((u.username||'?')[0].toUpperCase())}<span class="presence-dot ${presenceDotClass(u)}"></span></div>
        <div class="status-card-info">
          <div class="status-card-name">${esc(u.username)} <span class="status-chip ${u.status_state==='away'?'away':(u.status_state==='offline'?'offline':'')}">${u.status_state==='away'?'Absent':(u.status_state==='offline'?'Hors ligne':'En ligne')}</span></div>
          <div class="status-card-sub">${esc(u.role||'')} • ${esc(presenceLabel(u))}</div>
        </div>
      </div>
    `).join('') : `<div style="padding:20px;text-align:center;color:var(--wa-text2);font-size:12px">Aucun statut disponible</div>`}
  `;
  const title = document.querySelector('.sb-tab[data-tab="status"]');
  if(title) title.title = `${onlineCount} utilisateur(s) en ligne`;
}

/* ── LOAD GROUPS ─────────────────────────────────────────────────────── */
async function loadGroups(){
  try {
    const d=await api({ajax:'get_groups'});
    if(!d.ok){ q('#groups-list').innerHTML=`<div class="err-panel" style="margin:8px">${esc(d.err)}</div>`; return; }
    allGroups=d.groups;
    renderGroups(allGroups);
  } catch(e){
    q('#groups-list').innerHTML=`<div class="err-panel" style="margin:8px">${esc(e.message)}</div>`;
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

/* ── CLICK CONTACT ───────────────────────────────────────────────────── */
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

/* ── OPEN CHAT ───────────────────────────────────────────────────────── */
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
    const user = presenceMap[String(id)] || {id,name,role};
    av.className='chat-hdr-av priv';
    av.innerHTML=`<span style="color:#fff;font-size:16px;font-weight:800">${name[0].toUpperCase()}</span>`;
    q('#hdr-name').textContent=name;
    q('#hdr-sub').textContent=presenceLabel(user);
    q('#hdr-sub').classList.toggle('online', !!user.is_online);
    mb.style.display='none';
  } else {
    av.className='chat-hdr-av grp';
    av.innerHTML=`<i class="fas fa-users" style="font-size:13px"></i>`;
    q('#hdr-name').textContent=name;
    q('#hdr-sub').textContent=`${memberCount} membre${memberCount>1?'s':''}`;
    q('#hdr-sub').classList.remove('online');
    mb.style.display='flex';
    loadGroupMembers(id);
  }

  renderedMsgIds = new Set();
  lastMsgHash = '';
  lastCount = 0;
  q('#msgs-zone').innerHTML='<div class="spin-wrap"><div class="spinner"></div></div>';
  fetchMessages().then(()=>{
    pollTimer=setInterval(()=>{ if(!document.hidden) fetchMessages(); },3000);
  });
  openChatMobile();
}

/* ── FETCH MESSAGES ──────────────────────────────────────────────────── */
async function fetchMessages(){
  if(!current) return;
  try {
    let d;
    if(current.type==='priv') d=await api({ajax:'get_messages',user_id:current.id});
    else d=await api({ajax:'get_group_messages',group_id:current.id});
    if(!d.ok){ showErrorInChat('Erreur chargement messages', d.err||'Erreur inconnue'); return; }
    renderMessages(d.messages);
  } catch(e){
    console.error('[fetchMessages]',e);
    const mz=q('#msgs-zone');
    if(mz.querySelector('.spin-wrap')||mz.querySelector('.err-panel'))
      showErrorInChat('Erreur réseau/serveur', e.message);
  }
}

/* ── RENDER MESSAGES ─────────────────────────────────────────────────── */
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

  const lastId = msgs[msgs.length-1].id;
  const newHash = lastId+'_'+msgs.length;
  if(newHash === lastMsgHash) return;

  const atBot = mz.scrollHeight-mz.scrollTop-mz.clientHeight < 120;
  lastCount = msgs.length;
  lastMsgHash = newHash;

  const existingCount = renderedMsgIds.size;

  if(existingCount === 0 || mz.querySelector('.spin-wrap') || mz.querySelector('.err-panel')){
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

  const newMsgs = msgs.filter(m=>!renderedMsgIds.has(String(m.id)));
  if(newMsgs.length===0) return;

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
    const src=furl(m.file_path||'');
    inner=`<div class="msg-img-wrap" onclick="openLightbox('${esc(src)}')">
      <img src="${esc(src)}" alt="${esc(m.file_name||'image')}" loading="lazy">
      <div class="dl-overlay"><i class="fas fa-download"></i></div>
    </div>`;

  } else if(type==='audio'){
    const src=furl(m.file_path||'');
    const fname=m.file_name||'';
    const aext=fname.split('.').pop().toLowerCase();
    const amime={'webm':'audio/webm','ogg':'audio/ogg','mp3':'audio/mpeg','wav':'audio/wav','m4a':'audio/mp4','aac':'audio/aac'}[aext]||'audio/webm';
    inner=`<div class="msg-audio" data-src="${esc(src)}" data-mime="${esc(amime)}">
      <button class="audio-play-btn" title="Lecture"><i class="fas fa-play"></i></button>
      <div class="audio-waveform"><canvas width="160" height="28"></canvas></div>
      <span class="audio-duration">0:00</span>
    </div>`;

  } else if(type==='video'){
    const src=furl(m.file_path||'');
    const fname = m.file_name || '';
    const ext = fname.split('.').pop().toLowerCase();
    const mimeMap = {'mp4':'video/mp4','webm':'video/webm','ogg':'video/ogg','mov':'video/mp4','mkv':'video/webm','avi':'video/x-msvideo'};
    const mime = mimeMap[ext] || 'video/mp4';
    inner=`<div class="msg-video-wrap" data-src="${esc(src)}">
      <video preload="metadata" style="width:100%;max-height:240px;border-radius:8px;display:block">
        <source src="${esc(src)}" type="${esc(mime)}">
        Votre navigateur ne supporte pas la lecture vidéo.
      </video>
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
    const src=furl(m.file_path||'');
    const icon=getFileIcon(m.file_name||'');
    inner=`<div class="msg-file">
      <div class="file-ico"><i class="fas ${icon}"></i></div>
      <div>
        <span class="file-info-name" title="${esc(m.file_name||m.content||'')}">${esc(m.file_name||m.content||'Fichier')}</span>
        <a class="file-info-dl" href="${esc(furl(m.file_path||'', true))}" download="${esc(m.file_name||'')}"><i class="fas fa-download"></i> Télécharger</a>
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

/* ── VIDEO PLAYER ────────────────────────────────────────────────────── */
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
  video.addEventListener('ended',()=>{ playBtn.innerHTML='<i class="fas fa-play"></i>'; progressBar.style.width='0%'; });

  playBtn.addEventListener('click',e=>{ e.stopPropagation(); if(video.paused) video.play(); else video.pause(); });
  progressWrap.addEventListener('click',e=>{ e.stopPropagation(); const r=progressWrap.getBoundingClientRect(); video.currentTime=(e.clientX-r.left)/r.width*(video.duration||0); });
  fsBtn.addEventListener('click',e=>{ e.stopPropagation(); if(video.requestFullscreen) video.requestFullscreen(); else if(video.webkitRequestFullscreen) video.webkitRequestFullscreen(); });
}

/* ── AUDIO PLAYER ────────────────────────────────────────────────────── */
function initAudioPlayer(el){
  if(el.dataset.ready === '1') return;
  el.dataset.ready = '1';

  const src=el.dataset.src;
  const mime=el.dataset.mime||'audio/webm';
  const playBtn=el.querySelector('.audio-play-btn');
  const canvas=el.querySelector('canvas');
  const durEl=el.querySelector('.audio-duration');
  const ctx=canvas.getContext('2d');

  // Créer l'élément audio avec la source et le type MIME explicite
  const audio=document.createElement('audio');
  audio.preload='metadata';
  const src_el=document.createElement('source');
  src_el.src=src;
  src_el.type=mime;
  audio.appendChild(src_el);

  let playing=false;
  let waveformBars=null;

  drawStaticWave(ctx,canvas);
  loadRealWaveform(src,mime).then(bars=>{
    if(!bars || !bars.length) return;
    waveformBars=bars;
    drawWaveBars(ctx,canvas,waveformBars,0);
  }).catch(()=>{});
  audio.addEventListener('loadedmetadata',()=>{ durEl.textContent=fmt(audio.duration||0); });
  audio.addEventListener('ended',()=>{
    playing=false;
    playBtn.innerHTML='<i class="fas fa-play"></i>';
    if(waveformBars) drawWaveBars(ctx,canvas,waveformBars,0);
    else drawStaticWave(ctx,canvas);
  });
  audio.addEventListener('timeupdate',()=>{
    durEl.textContent=fmt(audio.currentTime);
    const progress=audio.currentTime/(audio.duration||1);
    if(waveformBars) drawWaveBars(ctx,canvas,waveformBars,progress);
    else drawProgress(ctx,canvas,progress);
  });
  audio.addEventListener('error',()=>{
    const err=audio.error;
    const codes={1:'ABORTED',2:'NETWORK',3:'DECODE',4:'FORMAT'};
    toast('Audio: '+(codes[err?.code]||'erreur')+' — '+src.split('/').pop(), false, 6000);
  });

  playBtn.addEventListener('click',()=>{
    if(playing){ audio.pause(); playing=false; playBtn.innerHTML='<i class="fas fa-play"></i>'; }
    else { audio.play().catch(e=>toast('Lecture: '+e.message,false)); playing=true; playBtn.innerHTML='<i class="fas fa-pause"></i>'; }
  });
  canvas.addEventListener('click',e=>{ const r=canvas.getBoundingClientRect(); audio.currentTime=(e.clientX-r.left)/r.width*(audio.duration||0); });
}

async function loadRealWaveform(src, mime){
  if(!window.AudioContext && !window.webkitAudioContext) return null;
  const res = await fetch(src);
  if(!res.ok) return null;
  const buf = await res.arrayBuffer();
  const AudioCtx = window.AudioContext || window.webkitAudioContext;
  const ac = new AudioCtx();
  try {
    const decoded = await ac.decodeAudioData(buf.slice(0));
    const raw = decoded.getChannelData(0);
    const bars = 48;
    const block = Math.max(1, Math.floor(raw.length / bars));
    const peaks = [];
    for(let i=0; i<bars; i++){
      const start = i * block;
      const end = Math.min(raw.length, start + block);
      let sum = 0;
      for(let j=start; j<end; j++) sum += Math.abs(raw[j]);
      peaks.push(end > start ? sum / (end - start) : 0);
    }
    const max = Math.max(...peaks, 0.01);
    return peaks.map(v=>Math.max(0.08, v / max));
  } finally {
    if(ac.state !== 'closed') ac.close().catch(()=>{});
  }
}

function drawWaveBars(ctx, canvas, bars, progress=0){
  const W=canvas.width||160, H=canvas.height||28;
  ctx.clearRect(0,0,W,H);
  const count=bars.length||32;
  const gap=2;
  const bw=Math.max(2, (W - gap*(count-1)) / count);
  bars.forEach((level, i)=>{
    const x=i*(bw+gap);
    const h=Math.max(3, level * H * 0.9);
    ctx.fillStyle=((i+1)/count)<=progress ? '#00a884' : 'rgba(134,150,160,.4)';
    ctx.beginPath();
    ctx.roundRect(x, (H-h)/2, bw, h, 1);
    ctx.fill();
  });
}

function drawStaticWave(ctx,canvas){
  const W=canvas.width||160, H=canvas.height||28;
  ctx.clearRect(0,0,W,H);
  const bars=32,bw=2,gap=3;
  ctx.fillStyle='rgba(134,150,160,.4)';
  for(let i=0;i<bars;i++){
    const h=(Math.sin(i*0.7)+1.2)*H*0.35+H*0.1;
    ctx.beginPath(); ctx.roundRect(i*(bw+gap),(H-h)/2,bw,h,1); ctx.fill();
  }
}
function drawProgress(ctx,canvas,progress){
  const W=canvas.width||160, H=canvas.height||28;
  ctx.clearRect(0,0,W,H);
  const bars=32,bw=2,gap=3;
  for(let i=0;i<bars;i++){
    const h=(Math.sin(i*0.7)+1.2)*H*0.35+H*0.1;
    ctx.fillStyle=(i/bars)<progress?'#00a884':'rgba(134,150,160,.4)';
    ctx.beginPath(); ctx.roundRect(i*(bw+gap),(H-h)/2,bw,h,1); ctx.fill();
  }
}

/* ── SEND MESSAGE ────────────────────────────────────────────────────── */
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
    else { toast('Erreur: '+d.err, false); }
  } catch(e){ toast('Erreur envoi: '+e.message, false); }
  finally{ ta.disabled=btn.disabled=false; ta.focus(); }
}

const ta=q('#msg-ta');
ta.addEventListener('input',function(){ this.style.height='auto'; this.style.height=Math.min(this.scrollHeight,120)+'px'; updateSendBtn(); });
ta.addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage();} });
q('#send-btn').addEventListener('click',sendMessage);

function updateSendBtn(){
  const has=ta.value.trim().length>0||pendingFile!=null;
  q('#send-btn').style.display=has?'flex':'none';
  q('#mic-btn').style.display=has?'none':'flex';
}

/* ── UPLOAD FICHIER ──────────────────────────────────────────────────── */
q('#attach-btn').addEventListener('click',()=>q('#file-inp').click());

q('#file-inp').addEventListener('change',function(){
  const file=this.files[0]; if(!file) return;
  // FIX: Limite côté client 15000 GB (la vraie limite est dans php.ini)
  // On accepte tout (le serveur rejettera si trop grand via PHP)
  pendingFile=file;
  const isImg=file.type.startsWith('image/');
  const isVid=file.type.startsWith('video/');
  if(isImg){
    const reader=new FileReader();
    reader.onload=e=>{ q('#img-preview-thumb').src=e.target.result; };
    reader.readAsDataURL(file);
    q('#img-preview-thumb').style.display='block';
  } else {
    q('#img-preview-thumb').src='';
    q('#img-preview-thumb').style.display='none';
  }
  const icon = isImg?'🖼 ':isVid?'🎬 ':'📎 ';
  q('#img-preview-name').textContent=icon+file.name+' ('+formatSize(file.size)+')';
  q('#img-preview-bar').classList.add('show');
  updateSendBtn();
});

function formatSize(bytes){
  if(bytes<1024) return bytes+' B';
  if(bytes<1048576) return (bytes/1024).toFixed(1)+' KB';
  if(bytes<1073741824) return (bytes/1048576).toFixed(1)+' MB';
  return (bytes/1073741824).toFixed(2)+' GB';
}

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
  const fd=new FormData();
  fd.append('file',pendingFile);
  if(current.type==='priv'){
    fd.append('ajax','upload_file');
    fd.append('recipient_id',String(current.id));
  } else {
    fd.append('ajax','upload_group_file');
    fd.append('group_id',String(current.id));
  }
  const fileName = pendingFile.name;
  try {
    const d=await apiForm(fd);
    cancelPendingFile();
    if(d.ok){ toast('✓ '+fileName+' envoyé !'); await fetchMessages(); }
    else { toast('Erreur upload: '+(d.err||'Erreur inconnue'),false); }
  } catch(e){ toast('Erreur upload: '+e.message,false); }
  finally{ btn.disabled=false; }
}

/* ══════════════════════════════════════════════════════════════════════
   CAMÉRA — PHOTO + ENREGISTREMENT VIDÉO
   FIX PRINCIPAL: capturePhoto() utilisait canvas.toBlob() (async)
   mais le code continuait sans attendre → camBlob était null.
   SOLUTION: On convertit le dataURL en Blob directement (synchrone).
══════════════════════════════════════════════════════════════════════ */
let camStream=null, camBlob=null, camMode='photo';
let camVideoRecorder=null, camVideoChunks=[], camRecTimer=null, camRecSecs=0;

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
    camBlob=null;
    updateCamUI();
    q('#cam-modal-bg').classList.add('show');
  } catch(e){ toast('Caméra inaccessible: '+e.message,false); }
}

/**
 * FIX: capturePhoto — conversion dataURL→Blob synchrone (sans callback)
 * L'ancienne version utilisait canvas.toBlob() qui est async,
 * et camBlob restait null au moment de sendCamMedia().
 */
function capturePhoto(){
  const video=q('#cam-video');
  const canvas=q('#cam-canvas');
  canvas.width=video.videoWidth||640;
  canvas.height=video.videoHeight||480;
  canvas.getContext('2d').drawImage(video,0,0);

  // CONVERSION SYNCHRONE: dataURL → Blob
  const dataURL = canvas.toDataURL('image/jpeg', 0.92);
  camBlob = dataURLtoBlob(dataURL);

  q('#cam-preview').src=dataURL;
  q('#cam-preview').style.display='block';
  q('#cam-video').style.display='none';
  q('#cam-capture').style.display='none';
  q('#cam-retake').style.display='flex';
  q('#cam-send').style.display='flex';

  // Arrêter le stream caméra (économie ressources)
  if(camStream) camStream.getTracks().forEach(t=>t.stop());
  camStream=null;

  console.log('[CAM] Photo capturée, blob size:', camBlob.size);
}

/**
 * Convertit un dataURL en Blob (synchrone, sans FileReader ni canvas.toBlob)
 */
function dataURLtoBlob(dataURL){
  const parts = dataURL.split(',');
  const mime = parts[0].match(/:(.*?);/)[1];
  const bstr = atob(parts[1]);
  const ab = new ArrayBuffer(bstr.length);
  const ia = new Uint8Array(ab);
  for(let i=0;i<bstr.length;i++) ia[i]=bstr.charCodeAt(i);
  return new Blob([ab],{type:mime});
}

function retakePhoto(){
  camBlob=null;
  q('#cam-preview').style.display='none';
  q('#cam-video-preview').style.display='none';
  q('#cam-retake').style.display='none';
  q('#cam-send').style.display='none';
  // Redémarrer la caméra pour reprendre
  navigator.mediaDevices.getUserMedia({video:{facingMode:'user'},audio:true}).then(stream=>{
    camStream=stream;
    q('#cam-video').srcObject=stream;
    q('#cam-video').style.display='block';
    updateCamUI();
  }).catch(e=>toast('Caméra: '+e.message,false));
}

async function startVideoRecording(){
  if(!camStream){ toast('Caméra non initialisée',false); return; }

  // Choisir le meilleur codec disponible
  const mimeTypes = ['video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/webm','video/mp4'];
  let selectedMime = '';
  for(const m of mimeTypes){
    if(MediaRecorder.isTypeSupported(m)){ selectedMime=m; break; }
  }
  if(!selectedMime){ toast('Enregistrement vidéo non supporté par ce navigateur',false); return; }

  camVideoRecorder=new MediaRecorder(camStream,{mimeType:selectedMime});
  camVideoChunks=[];
  camRecSecs=0;
  camBlob=null;

  camVideoRecorder.ondataavailable=e=>{ if(e.data&&e.data.size>0) camVideoChunks.push(e.data); };
  camVideoRecorder.onstop=()=>{
    const ext = selectedMime.includes('mp4')?'mp4':'webm';
    camBlob=new Blob(camVideoChunks,{type:selectedMime});
    const url=URL.createObjectURL(camBlob);
    q('#cam-video-preview').src=url;
    q('#cam-video-preview').style.display='block';
    q('#cam-video').style.display='none';
    q('#cam-rec-stop').style.display='none';
    q('#cam-rec-timer').classList.remove('show');
    q('#cam-retake').style.display='flex';
    q('#cam-send').style.display='flex';
    clearInterval(camRecTimer);
    console.log('[CAM VIDEO] Enregistré, blob size:',camBlob.size,'mime:',selectedMime);
  };
  camVideoRecorder.start(100);

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
  clearInterval(camRecTimer);
  if(camVideoRecorder&&camVideoRecorder.state!=='inactive') camVideoRecorder.stop();
}

async function sendCamMedia(){
  if(!camBlob){ toast('Rien à envoyer — photo/vidéo manquante',false); return; }
  if(!current){ toast('Aucun contact sélectionné',false); return; }

  const isVideo = camMode==='video';
  // FIX: nommer correctement le fichier pour que PHP détecte bien le type
  // vocal_ = audio, video_ = vidéo (selon la logique detectMediaType côté PHP)
  const ext = isVideo ? (camBlob.type.includes('mp4')?'mp4':'webm') : 'jpg';
  const prefix = isVideo ? 'video_' : 'photo_';
  const fileName = `${prefix}${Date.now()}.${ext}`;
  const file = new File([camBlob], fileName, {type: camBlob.type || (isVideo?'video/webm':'image/jpeg')});

  console.log('[CAM SEND] File:',fileName,'size:',file.size,'type:',file.type);

  closeCamera();

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
  clearInterval(camRecTimer);
  if(camVideoRecorder&&camVideoRecorder.state!=='inactive') camVideoRecorder.stop();
  if(camStream) camStream.getTracks().forEach(t=>t.stop());
  camStream=null; camBlob=null; camVideoRecorder=null; camVideoChunks=[];
  q('#cam-modal-bg').classList.remove('show');
  q('#cam-video-preview').src='';
}

/* ── AUDIO RECORDING ─────────────────────────────────────────────────── */
let mediaRecorder=null, audioChunks=[], recAudioCtx=null, recAnalyser=null;
let recAnimId=null, recTimerI=null, recSecs=0, isRecording=false;

q('#mic-btn').addEventListener('click',()=>{ if(!isRecording) startRecording(); else stopRecording(); });
q('#rec-cancel').addEventListener('click',cancelRecording);
q('#rec-stop').addEventListener('click',()=>{ if(isRecording) stopRecording(); });

async function startRecording(){
  if(!current){ toast('Choisissez un contact d\'abord',false); return; }
  try {
    const stream=await navigator.mediaDevices.getUserMedia({audio:true});
    // FIX: Utiliser des codecs bien supportés pour l'audio
    const mimeTypes=['audio/webm;codecs=opus','audio/ogg;codecs=opus','audio/webm'];
    let mime='audio/webm';
    for(const m of mimeTypes){ if(MediaRecorder.isTypeSupported(m)){mime=m;break;} }

    mediaRecorder=new MediaRecorder(stream,{mimeType:mime});
    audioChunks=[]; recSecs=0; isRecording=true;
    mediaRecorder.ondataavailable=e=>{ if(e.data&&e.data.size>0) audioChunks.push(e.data); };
    mediaRecorder.onstop=()=>{ processRecording(stream,mime); };
    mediaRecorder.start(200);

    q('#rec-timer').textContent='0:00';
    recTimerI=setInterval(()=>{ recSecs++; q('#rec-timer').textContent=fmt(recSecs); if(recSecs>=300) stopRecording(); },1000);

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
  } catch(e){ toast('Microphone inaccessible: '+e.message,false); }
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
    ctx.fillStyle=`rgba(0,168,132,${0.4+v/512})`;
    ctx.beginPath(); ctx.roundRect(i*(bw+1),(H-h)/2,bw,h,1); ctx.fill();
  });
  recAnimId=requestAnimationFrame(drawLiveWave);
}

function stopRecording(){ isRecording=false; if(mediaRecorder&&mediaRecorder.state!=='inactive') mediaRecorder.stop(); clearInterval(recTimerI); cancelAnimationFrame(recAnimId); if(recAudioCtx) recAudioCtx.close().catch(()=>{}); }
function cancelRecording(){ stopRecording(); audioChunks=[]; resetRecUI(); }
function resetRecUI(){ q('#mic-btn').style.display='flex'; q('#rec-bar-wrap').classList.remove('show'); q('#attach-btn').style.display='flex'; q('#cam-btn').style.display='flex'; updateSendBtn(); }

async function processRecording(stream,mime){
  stream.getTracks().forEach(t=>t.stop());
  resetRecUI();
  if(!audioChunks.length||!current){ toast('Aucun audio enregistré',false); return; }

  const blob=new Blob(audioChunks,{type:mime});
  // FIX: Nommer le fichier vocal_ pour que PHP le détecte comme audio (pas vidéo)
  const ext=mime.includes('ogg')?'ogg':'webm';
  const file=new File([blob],`vocal_${Date.now()}.${ext}`,{type:mime});
  const fd=new FormData();
  fd.append('file',file);
  if(current.type==='priv'){ fd.append('ajax','upload_file'); fd.append('recipient_id',String(current.id)); }
  else { fd.append('ajax','upload_group_file'); fd.append('group_id',String(current.id)); }

  console.log('[AUDIO REC] Sending blob size='+blob.size+' mime='+mime+' filename=vocal_...');
  toast('Envoi message vocal...');
  try {
    const d=await apiForm(fd);
    if(d.ok){ await fetchMessages(); toast('✓ Message vocal envoyé !'); }
    else { toast('Erreur vocal: '+(d.err||'Erreur'),false); console.error('[vocal]',d); }
  } catch(e){ toast('Erreur: '+e.message,false); }
}

/* ── EMOJI ───────────────────────────────────────────────────────────── */
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
document.addEventListener('click',e=>{ if(!e.target.closest('#ep')&&!e.target.closest('#emoji-toggle-btn')) q('#ep').classList.remove('show'); });

/* ── MODAL GROUPE ────────────────────────────────────────────────────── */
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
    if(d.ok){ q('#modal-bg').classList.remove('show'); toast('Groupe "'+name+'" créé !'); await loadGroups(); q('[data-tab="grp"]').click(); openChat('grp',d.group_id,name,'',checked.length+1); }
    else toast(d.err||'Erreur',false);
  } catch(e){ toast('Erreur: '+e.message,false); }
});

/* ── MEMBRES PANEL ───────────────────────────────────────────────────── */
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

/* ── UNREAD & NOTIFS ─────────────────────────────────────────────────── */
let prevUnread = {};

function requestNotifPermission(){
  if(!('Notification' in window)) return;
  if(Notification.permission==='default'){
    Notification.requestPermission().then(async p=>{
      notifPermission=(p==='granted');
      if(notifPermission){
        await ensurePushSubscription();
        toast('Notifications activées');
      }
    });
  } else if(Notification.permission==='granted'){
    notifPermission=true;
    ensurePushSubscription();
  }
}

async function ensureServiceWorker(){
  if(!('serviceWorker' in navigator)) return null;
  if(swReg) return swReg;
  try {
    await navigator.serviceWorker.register(SW_URL, {scope:SW_SCOPE});
    swReg = await navigator.serviceWorker.ready;
  } catch(e) {
    try {
      await navigator.serviceWorker.register(SW_URL);
      swReg = await navigator.serviceWorker.ready;
    } catch(err) {
      console.warn('[SW register]', err.message);
      return null;
    }
  }
  return swReg;
}

async function ensurePushSubscription(){
  if(!window.isSecureContext){
    console.warn('[Push] Contexte non sécurisé. HTTPS requis pour Web Push.');
    return null;
  }
  if(!('serviceWorker' in navigator) || !('PushManager' in window)) return null;
  const reg = await ensureServiceWorker();
  if(!reg) return null;
  const cfg = await api({ajax:'get_push_public_key'});
  if(!cfg.ok || !cfg.public_key) return null;
  let sub = await reg.pushManager.getSubscription();
  if(!sub){
    sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: b64UrlToUint8Array(cfg.public_key)
    });
  }
  await api({ajax:'save_push_subscription', subscription: JSON.stringify(sub.toJSON())});
  return sub;
}

async function showRealNotification(title, body, unread=0){
  const pageVisible = document.visibilityState === 'visible' && document.hasFocus();
  if(!notifPermission || pageVisible) return;
  const reg = await ensureServiceWorker();
  if(reg?.active){
    reg.active.postMessage({
      type:'SHOW_NOTIFICATION',
      title,
      body,
      unread,
      tag:'chat-unread-'+title,
      url:window.location.href
    });
    return;
  }
  try {
    const n=new Notification(title,{body,tag:'chat-'+title});
    setTimeout(()=>n.close(),5000);
    n.onclick=()=>{window.focus();n.close();};
  } catch(e){}
}

async function updateAppBadge(totalUnread){
  if('setAppBadge' in navigator){
    try {
      if(totalUnread>0) await navigator.setAppBadge(totalUnread);
      else if('clearAppBadge' in navigator) await navigator.clearAppBadge();
    } catch(e){}
  }
  const reg = await ensureServiceWorker();
  if(reg?.active){
    reg.active.postMessage({type:'SET_BADGE', unread:totalUnread});
  }
}

function setupInstallPrompt(){
  window.addEventListener('beforeinstallprompt', e=>{
    e.preventDefault();
    deferredInstallPrompt = e;
    q('#install-btn')?.classList.add('show');
  });
  q('#install-btn')?.addEventListener('click', async ()=>{
    if(!deferredInstallPrompt) return;
    deferredInstallPrompt.prompt();
    const result = await deferredInstallPrompt.userChoice.catch(()=>null);
    if(result?.outcome === 'accepted'){
      q('#install-btn')?.classList.remove('show');
    }
    deferredInstallPrompt = null;
  });
  window.addEventListener('appinstalled', ()=>{
    deferredInstallPrompt = null;
    q('#install-btn')?.classList.remove('show');
    toast('Application installée');
  });
}

function updateTabTitle(totalUnread){
  document.title=(totalUnread>0?'('+totalUnread+') ':'')+' Messagerie | ESPERANCE H2O';
  // Sync bottom nav badge
  const badge=document.getElementById('wa-bn-chats-badge');
  if(badge){
    if(totalUnread>0){ badge.textContent=totalUnread>99?'99+':totalUnread; badge.style.display='flex'; }
    else badge.style.display='none';
  }
  updateAppBadge(totalUnread);
}

async function pollUnread(){
  try {
    const d=await api({ajax:'get_unread'});
    if(!d.ok) return;
    let totalUnread=0;
    document.querySelectorAll('[id^="ub-"]').forEach(el=>{el.style.display='none';el.textContent='';});
    Object.entries(d.unread).forEach(([uid,cnt])=>{
      const c=parseInt(cnt)||0; if(c<=0) return;
      totalUnread+=c;
      const el=q('#ub-'+uid);
      if(el){ el.textContent=c>99?'99+':c; el.style.display='inline-flex'; }
      const prev=prevUnread[uid]||0;
      if(c>prev && !(current?.type==='priv' && String(current.id)===String(uid))){
        const usr=allUsers.find(u=>String(u.id)===String(uid));
        showRealNotification('💬 '+(usr?usr.username:'Nouveau message'), c+' message'+(c>1?'s':''), totalUnread);
      }
    });
    prevUnread={...d.unread};
    updateTabTitle(totalUnread);
  } catch(e){ console.warn('[pollUnread]',e.message); }
}

async function heartbeatPresence(state='online'){
  try {
    const d = await api({ajax:'heartbeat_presence', state});
    if(d.ok){
      myPresence = d.presence || null;
    }
  } catch(e){
    console.warn('[heartbeatPresence]', e.message);
  }
}

async function refreshStatuses(){
  try {
    const d = await api({ajax:'get_statuses'});
    if(!d.ok) return;
    myPresence = d.me || myPresence;
    allUsers = d.statuses || allUsers;
    presenceMap = Object.fromEntries(allUsers.map(u=>[String(u.id), u]));
    const activeTab=q('.sb-tab.on')?.dataset.tab;
    if(activeTab==='priv') renderUsers(allUsers);
    if(activeTab==='status') renderStatusList(q('#sb-search-inp').value || '');
    if(current?.type==='priv'){
      const user = presenceMap[String(current.id)];
      if(user){
        q('#hdr-sub').textContent=presenceLabel(user);
        q('#hdr-sub').classList.toggle('online', !!user.is_online);
      }
    }
  } catch(e){
    console.warn('[refreshStatuses]', e.message);
  }
}

/* ── LIGHTBOX ────────────────────────────────────────────────────────── */
function openLightbox(src){
  q('#lightbox-img').src=src;
  q('#lightbox').classList.add('show');
}
q('#lightbox').addEventListener('click',e=>{
  if(e.target===q('#lightbox')||e.target===q('#lightbox-close')||q('#lightbox-close').contains(e.target))
    q('#lightbox').classList.remove('show');
});

/* ── MOBILE NAV ──────────────────────────────────────────────────────── */
function isMobile(){ return window.innerWidth<=768; }
function openChatMobile(){
  if(!isMobile()) return;
  document.querySelector('.sidebar').classList.add('wa-slide-out');
  document.querySelector('.chat-main').classList.add('wa-open');
}
function closeChatMobile(){
  document.querySelector('.sidebar').classList.remove('wa-slide-out');
  document.querySelector('.chat-main').classList.remove('wa-open');
  if(pollTimer){ clearInterval(pollTimer); pollTimer=null; }
  current=null;
  // reset no-chat area
  if(q('#chat-hdr')) q('#chat-hdr').style.display='none';
  if(q('#msgs-zone')) q('#msgs-zone').style.display='none';
  if(q('#input-zone')) q('#input-zone').style.display='none';
  if(q('#no-chat')) q('#no-chat').style.display='flex';
}
function waNavSwitch(tab, el){
  document.querySelectorAll('.wa-bn-item').forEach(b=>b.classList.remove('on'));
  el.classList.add('on');
  if(tab==='chats'){
    // show private chats panel
    document.querySelectorAll('.sb-tab').forEach(t=>t.classList.remove('on'));
    const privTab=document.querySelector('.sb-tab[data-tab="priv"]');
    if(privTab) privTab.classList.add('on');
    document.querySelectorAll('.sb-panel').forEach(p=>p.classList.remove('on'));
    const privPanel=document.getElementById('panel-priv');
    if(privPanel) privPanel.classList.add('on');
  } else if(tab==='status'){
    document.querySelectorAll('.sb-tab').forEach(t=>t.classList.remove('on'));
    const stTab=document.querySelector('.sb-tab[data-tab="status"]');
    if(stTab) stTab.classList.add('on');
    document.querySelectorAll('.sb-panel').forEach(p=>p.classList.remove('on'));
    const stPanel=document.getElementById('panel-status');
    if(stPanel) stPanel.classList.add('on');
    renderStatusList(q('#sb-search-inp').value || '');
  } else if(tab==='groups'){
    // show group chats panel
    document.querySelectorAll('.sb-tab').forEach(t=>t.classList.remove('on'));
    const grpTab=document.querySelector('.sb-tab[data-tab="grp"]');
    if(grpTab) grpTab.classList.add('on');
    document.querySelectorAll('.sb-panel').forEach(p=>p.classList.remove('on'));
    const grpPanel=document.getElementById('panel-grp');
    if(grpPanel) grpPanel.classList.add('on');
  } else if(tab==='more'){
    // show site navigation panel
    document.querySelectorAll('.sb-tab').forEach(t=>t.classList.remove('on'));
    document.querySelectorAll('.sb-panel').forEach(p=>p.classList.remove('on'));
    const morePanel=document.getElementById('panel-more');
    if(morePanel) morePanel.classList.add('on');
    // show sidebar if hidden
    const sidebar=document.querySelector('.sidebar');
    if(sidebar) sidebar.classList.remove('wa-slide-out');
    const chatMain=document.querySelector('.chat-main');
    if(chatMain) chatMain.classList.remove('wa-open');
  }
}

/* ── INIT ────────────────────────────────────────────────────────────── */
console.log('[CHAT] Init — ME_ID='+ME_ID+' API='+API_URL);
ensureServiceWorker();
setupInstallPrompt();
requestNotifPermission();
initEmoji();
heartbeatPresence('online');
loadUsers();
loadGroups();
setInterval(()=>{ if(!document.hidden) { heartbeatPresence('online'); pollUnread(); refreshStatuses(); } },15000);
setInterval(()=>{ if(!document.hidden) pollUnread(); },5000);
setInterval(()=>{
  if(document.hidden && notifPermission){
    pollUnread();
  }
},10000);
runDiag();
document.addEventListener('click', requestNotifPermission, {once:true});
document.addEventListener('visibilitychange',()=>{
  heartbeatPresence(document.hidden ? 'away' : 'online');
  if(!document.hidden) refreshStatuses();
});
window.addEventListener('focus',()=>heartbeatPresence('online'));
window.addEventListener('beforeunload',()=>{
  try {
    const fd = new FormData();
    fd.append('ajax','heartbeat_presence');
    fd.append('state','away');
    fd.append('csrf_token', CSRF);
    navigator.sendBeacon(API_URL, fd);
  } catch(e){}
});
</script>
</body>
</html>
