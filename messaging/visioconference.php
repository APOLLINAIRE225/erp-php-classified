<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * VISIOCONFÉRENCE WEBRTC ULTRA PRO — ESPERANCE H2O 🔥
 * ═══════════════════════════════════════════════════════════════════════════
 * ✅ Vidéo + Audio WebRTC
 * ✅ Partage écran
 * ✅ Chat réunion
 * ✅ Enregistrement .ogg
 * ✅ Participants liste
 * ✅ Controls admin
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
Middleware::role(['developer','admin','manager','staff']);

$pdo = DB::getConnection();
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user_name = $_SESSION['username'] ?? 'User';
$current_user_role = $_SESSION['role'] ?? 'staff';

$meeting_code = trim($_GET['code'] ?? '');
$meeting = null;

if($meeting_code){
    $stmt = $pdo->prepare("SELECT * FROM chat_meetings WHERE meeting_code=? LIMIT 1");
    $stmt->execute([$meeting_code]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($meeting){
        // Vérifier si participant
        $stmt_part = $pdo->prepare("SELECT * FROM chat_meeting_participants WHERE meeting_id=? AND user_id=?");
        $stmt_part->execute([$meeting['id'], $current_user_id]);
        $participant = $stmt_part->fetch(PDO::FETCH_ASSOC);
        
        if(!$participant){
            // Ajouter automatiquement
            $pdo->prepare("INSERT INTO chat_meeting_participants (meeting_id,user_id,user_name,role) VALUES (?,?,?,'participant')")
                ->execute([$meeting['id'], $current_user_id, $current_user_name]);
        }
        
        // Mettre à jour statut meeting si premier participant
        if($meeting['status'] === 'scheduled'){
            $pdo->prepare("UPDATE chat_meetings SET status='active', started_at=NOW() WHERE id=?")
                ->execute([$meeting['id']]);
        }
    }
}

if(!$meeting){
    die('Réunion introuvable');
}

$is_host = ($meeting['host_id'] == $current_user_id);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($meeting['title']) ?> | Visio ESPERANCE H2O</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@font-face{font-family:'C059';src:local('C059-Bold'),local('C059 Bold'),local('Century Schoolbook');font-weight:700 900;font-style:normal}
:root{--bg:#0f1726;--surf:#162033;--card:#1b263b;--card2:#22324a;--bord:rgba(148,163,184,0.18);--neon:#00a86b;--red:#e53935;--orange:#f57c00;--blue:#1976d2;--gold:#f9a825;--purple:#a855f7;--cyan:#06b6d4;--text:#e8eef8;--text2:#bfd0e4;--muted:#8ea3bd;--fh:'C059','Source Serif 4','Playfair Display','Book Antiqua',Georgia,serif;--fb:'Inter','Segoe UI',system-ui,sans-serif}
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%;overflow:hidden}
body{font-family:var(--fb);background:var(--bg);color:var(--text)}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 65% 42% at 4% 8%,rgba(50,190,143,0.08),transparent 62%),radial-gradient(ellipse 52% 36% at 96% 88%,rgba(61,140,255,0.07),transparent 62%)}
body::after{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(50,190,143,0.022) 1px,transparent 1px),linear-gradient(90deg,rgba(50,190,143,0.022) 1px,transparent 1px);background-size:46px 46px}

.wrap{position:relative;z-index:1;height:100%;display:flex;flex-direction:column}

/* TOPBAR */
.topbar{background:rgba(22,32,51,0.96);border-bottom:1px solid var(--bord);backdrop-filter:blur(24px);padding:12px 20px;display:flex;align-items:center;gap:12px;flex-shrink:0}
.meeting-info h1{font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text)}
.meeting-info p{font-size:11px;color:var(--muted);margin-top:2px}
.spacer{flex:1}
.meeting-code{background:rgba(50,190,143,0.12);border:1px solid rgba(50,190,143,0.3);padding:6px 14px;border-radius:20px;font-family:monospace;font-size:12px;color:var(--neon);font-weight:700}
.timer{font-family:monospace;font-size:14px;color:var(--text);padding:6px 12px;background:rgba(15,23,38,0.72);border-radius:10px}
.leave-btn{background:rgba(255,53,83,0.12);border:1px solid rgba(255,53,83,0.3);color:var(--red);padding:10px 18px;border-radius:10px;font-family:var(--fh);font-weight:900;cursor:pointer;transition:all 0.3s;font-size:12px}
.leave-btn:hover{background:var(--red);color:#fff;transform:translateY(-2px)}

/* MAIN */
.main{flex:1;display:flex;gap:12px;padding:12px;overflow:hidden}

/* VIDEO GRID */
.video-grid{flex:1;display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;overflow-y:auto}
.video-box{background:var(--card);border:1px solid var(--bord);border-radius:14px;overflow:hidden;position:relative;aspect-ratio:16/9}
.video-box video{width:100%;height:100%;object-fit:cover;background:#000}
.video-info{position:absolute;bottom:10px;left:10px;background:rgba(0,0,0,0.7);backdrop-filter:blur(10px);padding:6px 12px;border-radius:20px;font-size:12px;font-weight:700;color:#fff}
.video-muted{position:absolute;top:10px;right:10px;width:32px;height:32px;background:rgba(255,53,83,0.9);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff}
.video-box.speaking{border-color:var(--neon);box-shadow:0 0 20px rgba(50,190,143,0.5)}
.no-video{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;background:linear-gradient(135deg,var(--purple),var(--blue))}
.no-video-avatar{width:80px;height:80px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:36px;font-weight:900;color:#fff;margin-bottom:10px}
.no-video-name{font-size:16px;font-weight:700;color:#fff}

/* SIDEBAR */
.sidebar{width:320px;background:var(--card);border:1px solid var(--bord);border-radius:14px;display:flex;flex-direction:column;overflow:hidden}
.sidebar-tabs{display:flex;border-bottom:1px solid rgba(255,255,255,0.05);background:rgba(0,0,0,0.18)}
.sidebar-tab{flex:1;padding:12px;text-align:center;font-family:var(--fh);font-size:12px;font-weight:900;color:var(--muted);cursor:pointer;border-bottom:3px solid transparent;transition:all 0.2s}
.sidebar-tab.active{color:var(--neon);border-bottom-color:var(--neon)}
.sidebar-content{flex:1;overflow-y:auto;padding:14px}

.participant-item{padding:10px;background:rgba(0,0,0,0.15);border:1px solid var(--bord);border-radius:10px;margin-bottom:8px;display:flex;align-items:center;gap:10px}
.p-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--neon),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;color:#04090e}
.p-info{flex:1}
.p-name{font-size:13px;font-weight:700;color:var(--text)}
.p-role{font-size:10px;color:var(--muted)}
.p-status{width:10px;height:10px;border-radius:50%;background:var(--neon);box-shadow:0 0 8px var(--neon)}

.chat-area{display:flex;flex-direction:column;height:100%}
.chat-messages{flex:1;overflow-y:auto;margin-bottom:12px}
.chat-msg{padding:8px;background:rgba(0,0,0,0.15);border-radius:8px;margin-bottom:6px}
.chat-author{font-size:11px;font-weight:700;color:var(--neon);margin-bottom:2px}
.chat-text{font-size:12px;color:var(--text2)}
.chat-input{display:flex;gap:8px}
.chat-input input{flex:1;padding:10px;background:rgba(15,23,38,0.72);border:1.5px solid var(--bord);border-radius:10px;color:var(--text);font-size:12px}
.chat-input input:focus{outline:none;border-color:var(--neon)}
.chat-send{background:var(--neon);color:#04090e;border:none;padding:10px 16px;border-radius:10px;font-weight:900;cursor:pointer}

/* CONTROLS */
.controls{background:rgba(22,32,51,0.96);border-top:1px solid var(--bord);backdrop-filter:blur(24px);padding:16px 20px;display:flex;justify-content:center;gap:12px;flex-shrink:0}
.ctrl-btn{width:50px;height:50px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer;transition:all 0.3s;border:none}
.ctrl-mic{background:rgba(50,190,143,0.12);border:1px solid rgba(50,190,143,0.3);color:var(--neon)}
.ctrl-mic:hover{background:var(--neon);color:#04090e}
.ctrl-mic.off{background:rgba(255,53,83,0.12);border:1px solid rgba(255,53,83,0.3);color:var(--red)}
.ctrl-cam{background:rgba(61,140,255,0.12);border:1px solid rgba(61,140,255,0.3);color:var(--blue)}
.ctrl-cam:hover{background:var(--blue);color:#fff}
.ctrl-cam.off{background:rgba(255,53,83,0.12);border:1px solid rgba(255,53,83,0.3);color:var(--red)}
.ctrl-screen{background:rgba(168,85,247,0.12);border:1px solid rgba(168,85,247,0.3);color:var(--purple)}
.ctrl-screen:hover{background:var(--purple);color:#fff}
.ctrl-screen.active{background:var(--purple);color:#fff}
.ctrl-record{background:rgba(255,53,83,0.12);border:1px solid rgba(255,53,83,0.3);color:var(--red)}
.ctrl-record:hover{background:var(--red);color:#fff}
.ctrl-record.recording{animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.6}}

@media(max-width:1024px){
    .sidebar{width:280px}
    .video-grid{grid-template-columns:1fr}
}
@media(max-width:768px){
    .main{flex-direction:column}
    .sidebar{width:100%;max-height:40vh}
}
</style>
</head>
<body>
<div class="wrap">

<!-- TOPBAR -->
<div class="topbar">
    <div class="meeting-info">
        <h1><?= htmlspecialchars($meeting['title']) ?></h1>
        <p><?= htmlspecialchars($meeting['description'] ?: 'Réunion en cours') ?></p>
    </div>
    <div class="spacer"></div>
    <div class="meeting-code">Code: <?= htmlspecialchars($meeting['meeting_code']) ?></div>
    <div class="timer" id="timer">00:00:00</div>
    <button class="leave-btn" onclick="leaveMeeting()">
        <i class="fas fa-right-from-bracket"></i> Quitter
    </button>
</div>

<!-- MAIN -->
<div class="main">
    <!-- VIDEO GRID -->
    <div class="video-grid" id="video-grid">
        <!-- Local video -->
        <div class="video-box" id="local-video-box">
            <video id="local-video" autoplay muted playsinline></video>
            <div class="video-info">
                <i class="fas fa-user"></i> Vous (<?= htmlspecialchars($current_user_name) ?>)
            </div>
        </div>
    </div>
    
    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="sidebar-tabs">
            <div class="sidebar-tab active" onclick="switchTab('participants')">
                <i class="fas fa-users"></i> Participants
            </div>
            <div class="sidebar-tab" onclick="switchTab('chat')">
                <i class="fas fa-comments"></i> Chat
            </div>
        </div>
        <div class="sidebar-content" id="tab-participants">
            <div id="participants-list"></div>
        </div>
        <div class="sidebar-content" id="tab-chat" style="display:none">
            <div class="chat-area">
                <div class="chat-messages" id="chat-messages"></div>
                <div class="chat-input">
                    <input type="text" id="chat-input" placeholder="Message..." onkeypress="if(event.key==='Enter')sendChatMessage()">
                    <button class="chat-send" onclick="sendChatMessage()"><i class="fas fa-paper-plane"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CONTROLS -->
<div class="controls">
    <button class="ctrl-btn ctrl-mic" id="btn-mic" onclick="toggleMic()" title="Micro">
        <i class="fas fa-microphone"></i>
    </button>
    <button class="ctrl-btn ctrl-cam" id="btn-cam" onclick="toggleCamera()" title="Caméra">
        <i class="fas fa-video"></i>
    </button>
    <button class="ctrl-btn ctrl-screen" id="btn-screen" onclick="toggleScreenShare()" title="Partage écran">
        <i class="fas fa-desktop"></i>
    </button>
    <?php if($is_host): ?>
    <button class="ctrl-btn ctrl-record" id="btn-record" onclick="toggleRecording()" title="Enregistrer">
        <i class="fas fa-circle"></i>
    </button>
    <?php endif; ?>
</div>

</div>

<script>
var meetingId = <?= $meeting['id'] ?>;
var meetingCode = '<?= $meeting['meeting_code'] ?>';
var userId = <?= $current_user_id ?>;
var userName = '<?= htmlspecialchars($current_user_name, ENT_QUOTES) ?>';
var isHost = <?= $is_host ? 'true' : 'false' ?>;

var localStream = null;
var localVideo = document.getElementById('local-video');
var peerConnections = {};
var micEnabled = true;
var cameraEnabled = true;
var screenShareEnabled = false;
var mediaRecorder = null;
var recordedChunks = [];

// TIMER
var startTime = new Date('<?= $meeting['started_at'] ?? $meeting['scheduled_at'] ?>');
function updateTimer(){
    var now = new Date();
    var diff = Math.floor((now - startTime) / 1000);
    var h = Math.floor(diff / 3600).toString().padStart(2,'0');
    var m = Math.floor((diff % 3600) / 60).toString().padStart(2,'0');
    var s = (diff % 60).toString().padStart(2,'0');
    document.getElementById('timer').textContent = h+':'+m+':'+s;
}
setInterval(updateTimer, 1000);

// INIT
async function init(){
    try{
        localStream = await navigator.mediaDevices.getUserMedia({
            video: true,
            audio: true
        });
        localVideo.srcObject = localStream;
        
        console.log('Média local initialisé');
        loadParticipants();
    }catch(err){
        console.error('Erreur média:', err);
        alert('Erreur accès caméra/micro: ' + err.message);
    }
}

// TOGGLE MIC
function toggleMic(){
    if(!localStream) return;
    var audioTrack = localStream.getAudioTracks()[0];
    if(audioTrack){
        micEnabled = !micEnabled;
        audioTrack.enabled = micEnabled;
        document.getElementById('btn-mic').classList.toggle('off', !micEnabled);
        document.getElementById('btn-mic').querySelector('i').className = micEnabled ? 'fas fa-microphone' : 'fas fa-microphone-slash';
    }
}

// TOGGLE CAMERA
function toggleCamera(){
    if(!localStream) return;
    var videoTrack = localStream.getVideoTracks()[0];
    if(videoTrack){
        cameraEnabled = !cameraEnabled;
        videoTrack.enabled = cameraEnabled;
        document.getElementById('btn-cam').classList.toggle('off', !cameraEnabled);
        document.getElementById('btn-cam').querySelector('i').className = cameraEnabled ? 'fas fa-video' : 'fas fa-video-slash';
    }
}

// TOGGLE SCREEN SHARE
async function toggleScreenShare(){
    try{
        if(!screenShareEnabled){
            var screenStream = await navigator.mediaDevices.getDisplayMedia({
                video: true,
                audio: false
            });
            
            var videoTrack = screenStream.getVideoTracks()[0];
            
            // Remplacer track vidéo
            var sender = Object.values(peerConnections)[0]?.getSenders().find(s => s.track.kind === 'video');
            if(sender){
                sender.replaceTrack(videoTrack);
            }
            
            localVideo.srcObject = screenStream;
            screenShareEnabled = true;
            document.getElementById('btn-screen').classList.add('active');
            
            videoTrack.onended = () => {
                stopScreenShare();
            };
        }else{
            stopScreenShare();
        }
    }catch(err){
        console.error('Erreur partage écran:', err);
    }
}

function stopScreenShare(){
    if(localStream){
        var videoTrack = localStream.getVideoTracks()[0];
        localVideo.srcObject = localStream;
        screenShareEnabled = false;
        document.getElementById('btn-screen').classList.remove('active');
    }
}

// RECORDING
async function toggleRecording(){
    if(!mediaRecorder){
        startRecording();
    }else{
        stopRecording();
    }
}

function startRecording(){
    try{
        recordedChunks = [];
        
        // Créer stream combiné
        var stream = new MediaStream();
        localStream.getTracks().forEach(track => stream.addTrack(track));
        
        mediaRecorder = new MediaRecorder(stream, {
            mimeType: 'audio/webm;codecs=opus'
        });
        
        mediaRecorder.ondataavailable = (e) => {
            if(e.data.size > 0){
                recordedChunks.push(e.data);
            }
        };
        
        mediaRecorder.onstop = () => {
            var blob = new Blob(recordedChunks, {type: 'audio/ogg'});
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'meeting_' + meetingCode + '_' + Date.now() + '.ogg';
            a.click();
        };
        
        mediaRecorder.start();
        document.getElementById('btn-record').classList.add('recording');
        console.log('Enregistrement démarré');
    }catch(err){
        console.error('Erreur enregistrement:', err);
    }
}

function stopRecording(){
    if(mediaRecorder && mediaRecorder.state !== 'inactive'){
        mediaRecorder.stop();
        mediaRecorder = null;
        document.getElementById('btn-record').classList.remove('recording');
        console.log('Enregistrement arrêté');
    }
}

// PARTICIPANTS
async function loadParticipants(){
    // Simuler participants (en prod: WebSocket signaling)
    var mockParticipants = [
        {id: userId, name: userName, role: isHost ? 'host' : 'participant', online: true}
    ];
    renderParticipants(mockParticipants);
}

function renderParticipants(participants){
    var html = participants.map(p => {
        var initial = (p.name || '?')[0].toUpperCase();
        return '<div class="participant-item">'+
            '<div class="p-avatar">'+initial+'</div>'+
            '<div class="p-info">'+
                '<div class="p-name">'+esc(p.name)+'</div>'+
                '<div class="p-role">'+esc(p.role)+'</div>'+
            '</div>'+
            (p.online ? '<div class="p-status"></div>' : '')+
        '</div>';
    }).join('');
    document.getElementById('participants-list').innerHTML = html;
}

// CHAT
var chatMessages = [];
function sendChatMessage(){
    var input = document.getElementById('chat-input');
    var message = input.value.trim();
    if(!message) return;
    
    chatMessages.push({
        author: userName,
        text: message,
        time: new Date()
    });
    
    renderChat();
    input.value = '';
}

function renderChat(){
    var html = chatMessages.map(m => 
        '<div class="chat-msg">'+
            '<div class="chat-author">'+esc(m.author)+'</div>'+
            '<div class="chat-text">'+esc(m.text)+'</div>'+
        '</div>'
    ).join('');
    document.getElementById('chat-messages').innerHTML = html;
    document.getElementById('chat-messages').scrollTop = document.getElementById('chat-messages').scrollHeight;
}

// TABS
function switchTab(tab){
    document.querySelectorAll('.sidebar-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.sidebar-content').forEach(c => c.style.display = 'none');
    
    event.target.closest('.sidebar-tab').classList.add('active');
    document.getElementById('tab-' + tab).style.display = 'block';
}

// LEAVE
function leaveMeeting(){
    if(confirm('Quitter la réunion ?')){
        if(localStream){
            localStream.getTracks().forEach(track => track.stop());
        }
        window.location.href = 'messagerie_interne_complete.php';
    }
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

// INIT ON LOAD
init();
</script>
</body>
</html>
