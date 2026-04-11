<?php
require __DIR__ . '/agent_erp/bootstrap.php';

if (!$isAuthenticated):
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Connexion — H²O AI · ESPERANCE H2O</title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?= h($agentErpCssUrl) ?>">
</head>
<body>
<div class="lwrap">
  <div class="lbox">
    <div class="ltop">
      <div class="lico"><i class="fas fa-robot"></i></div>
      <h1>H²O AI</h1>
      <p>ESPERANCE H2O · ERP Agent</p>
    </div>
    <div class="lcard">
      <h2><i class="fas fa-lock" style="color:var(--purple);margin-right:8px"></i>Connexion requise</h2>
      <?php if (!$db_ok): ?>
      <div class="warn"><i class="fas fa-database"></i><span>Base de données non disponible.<br><code>sudo systemctl start mariadb</code></span></div>
      <?php endif; ?>
      <?php if ($setup_success): ?>
      <div class="okmsg"><i class="fas fa-check-circle"></i><span><?= h($setup_success) ?></span></div>
      <?php endif; ?>
      <?php if ($setup_error): ?>
      <div class="err"><i class="fas fa-exclamation-circle"></i><span><?= h($setup_error) ?></span></div>
      <?php endif; ?>
      <?php if ($login_error): ?>
      <div class="err"><i class="fas fa-exclamation-circle"></i><span><?= h($login_error) ?></span></div>
      <?php endif; ?>

      <?php if ($setup_mode): ?>
      <form method="POST" autocomplete="off">
        <div class="warn"><i class="fas fa-user-shield"></i><span>Mode d'initialisation administrateur. Le token est à usage unique et expire automatiquement.</span></div>
        <div class="fg"><label>Token d'initialisation</label><input type="text" name="setup_token" required value="<?= h($_GET['setup_token'] ?? '') ?>"></div>
        <div class="fg"><label>Identifiant admin</label><input type="text" name="setup_user" required value="admin"></div>
        <div class="fg"><label>Nom complet</label><input type="text" name="setup_name" value="Administrateur"></div>
        <div class="fg"><label>Mot de passe fort</label><input type="password" name="setup_pass" required autocomplete="new-password"></div>
        <input type="hidden" name="do_setup" value="1">
        <button type="submit" class="lbtn"><i class="fas fa-shield-halved"></i> Initialiser l'admin</button>
      </form>
      <?php else: ?>
      <form method="POST" autocomplete="off">
        <div class="fg">
          <label>Nom d'utilisateur</label>
          <div class="fi"><input type="text" name="login_user" placeholder="Votre identifiant" required autofocus autocomplete="username" value="<?= h($_POST['login_user'] ?? '') ?>"><i class="fas fa-user ico"></i></div>
        </div>
        <div class="fg">
          <label>Mot de passe</label>
          <div class="fi"><input type="password" name="login_pass" id="lpass" placeholder="••••••••" required autocomplete="current-password"><i class="fas fa-eye ico" id="eyetog" onclick="togglePass()"></i></div>
        </div>
        <input type="hidden" name="do_login" value="1">
        <button type="submit" class="lbtn" <?= !$db_ok ? 'disabled' : '' ?>><i class="fas fa-sign-in-alt"></i> Se connecter</button>
      </form>
      <?php endif; ?>

      <div class="ldemo">
        <h4><i class="fas fa-info-circle"></i> Sécurité / Bootstrap</h4>
        <div class="drow"><span>RBAC fin activé</span><span class="dr">OK</span></div>
        <div class="drow"><span>Identifiants seedés en clair</span><span class="dr">OFF</span></div>
        <div class="drow"><span>Token bootstrap actif</span><span class="dr"><?= h($bootstrap_token_hint['token_preview'] ?? 'Aucun') ?></span></div>
        <?php if (!empty($bootstrap_token_hint['expires_at'])): ?>
        <div class="drow"><span>Expiration bootstrap</span><span class="dr"><?= h((string) $bootstrap_token_hint['expires_at']) ?></span></div>
        <?php endif; ?>
        <?php if (file_exists($agentErpSetupFile)): ?>
        <div class="drow"><span>Fichier bootstrap</span><span class="dr">SETUP_TOKEN.txt</span></div>
        <?php endif; ?>
      </div>
      <div class="clk2" id="clk2">--:--:--</div>
    </div>
  </div>
</div>
<script src="<?= h($agentErpJsUrl) ?>"></script>
</body>
</html>
<?php
exit;
endif;

$isAdmin = role_is_admin();
$permissionsCsv = implode(',', $_SESSION['permissions'] ?? []);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>H²O AI — ESPERANCE H2O</title>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?= h($agentErpCssUrl) ?>">
</head>
<body>
<div class="w">
  <div class="topbar">
    <div class="brand">
      <div class="bico"><i class="fas fa-robot"></i></div>
      <div>
        <h1>H²O AI</h1>
        <p>ERP Assistant · Refactor Pro</p>
        <span class="vbadge">Controller · RBAC · Search v2 · Analytics+</span>
      </div>
    </div>
    <div style="text-align:center">
      <div class="clk" id="clk">--:--:--</div>
      <div class="clks" id="clkd"></div>
    </div>
    <div class="topbar-actions">
      <a href="index.php" class="btn btn-p btn-sm" target="_blank" rel="noopener noreferrer"><i class="fas fa-rocket"></i> Admin</a>
      <a href="<?= h($agentErpControllerUrl) ?>?ajax=kb_export&fmt=json" class="btn btn-c btn-sm" target="_blank" rel="noopener noreferrer"><i class="fas fa-download"></i> Export JSON</a>
      <button onclick="printChat()" class="btn btn-b btn-sm"><i class="fas fa-file-pdf"></i></button>
      <div class="ubadge" onclick="openM('mprofil')">
        <div class="avtr" style="background:<?= h($ucolor) ?>"><?= strtoupper(substr($ufname, 0, 1)) ?></div>
        <span><?= h($ufname) ?></span>
        <span style="font-size:9px;background:rgba(255,255,255,.12);padding:2px 6px;border-radius:8px"><?= h(strtoupper($urole)) ?></span>
      </div>
      <a href="<?= h($agentErpPageUrl) ?>?logout=1" class="btn btn-r btn-sm" onclick="return confirm('Se déconnecter ?')"><i class="fas fa-sign-out-alt"></i></a>
    </div>
  </div>

  <?php if (!$db_ok): ?>
  <div class="warn" style="margin-bottom:12px"><i class="fas fa-exclamation-triangle"></i><span>Base de données non disponible. <?= h($db_error) ?></span></div>
  <?php else: ?>
  <div style="display:inline-flex;align-items:center;gap:8px;font-family:var(--fh);font-size:10px;font-weight:900;color:var(--neon);background:rgba(50,190,143,.07);border:1px solid rgba(50,190,143,.2);border-radius:9px;padding:5px 13px;margin-bottom:12px">
    <div style="width:5px;height:5px;border-radius:50%;background:var(--neon);box-shadow:0 0 7px var(--neon)"></div>
    DB: <strong><?= h($db_name) ?></strong> · <?= $total_kb ?> KB · <?= $siteIndexCount ?> pages indexées · <?= $total_asks ?> questions · <?= $ctx_count ?> contexte · mode interne total
  </div>
  <?php endif; ?>

  <div class="tnav">
    <a href="#" id="tab-chat" class="tn active" onclick="st('chat');return false"><i class="fas fa-comments"></i> Assistant</a>
    <a href="#" id="tab-kb" class="tn" onclick="st('kb');return false"><i class="fas fa-brain"></i> Base KB</a>
    <a href="#" id="tab-history" class="tn" onclick="st('history');return false"><i class="fas fa-history"></i> Historique</a>
    <a href="#" id="tab-stats" class="tn" onclick="st('stats');return false"><i class="fas fa-chart-bar"></i> Analytics</a>
    <div style="margin-left:auto;display:flex;gap:5px;flex-wrap:wrap">
      <?php if (can('chat.learn')): ?>
      <button onclick="openM('ml')" class="tn" style="border-color:rgba(255,208,96,.3);color:var(--gold)"><i class="fas fa-plus"></i> Enseigner</button>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
      <a href="#" id="tab-admin" class="tn" style="border-color:rgba(255,53,83,.3);color:var(--red)" onclick="st('admin');return false"><i class="fas fa-shield-alt"></i> Admin</a>
      <?php endif; ?>
    </div>
  </div>

  <div id="p-chat">
    <div class="layout">
      <div>
        <div class="chat" id="chatbox">
          <div class="chead">
            <div class="aav"><i class="fas fa-robot"></i></div>
            <div class="cst">
              <strong>H²O AI</strong>
              <span><div class="ondot"></div> Controller séparé · Search pondéré · RBAC fin</span>
            </div>
            <div style="margin-left:auto;display:flex;gap:5px">
              <button onclick="exportConv()" class="btn btn-b btn-sm no-print"><i class="fas fa-download"></i></button>
              <button onclick="clrChat()" class="btn btn-r btn-sm no-print"><i class="fas fa-broom"></i></button>
            </div>
          </div>
          <div class="msgs" id="msgs">
            <div class="msg agent">
              <div class="bbl">
                Bonjour <strong><?= h($ufname) ?></strong>.<br><br>
                L’agent fonctionne désormais avec un contrôleur dédié, un moteur de recherche pondéré, un RBAC fin et des analytics enrichis.<br><br>
                <?php if ($mustChangePassword): ?>
                <span style="color:var(--gold)">⚠️ Changement de mot de passe obligatoire avant usage prolongé.</span>
                <?php else: ?>
                <span style="color:var(--neon)"><?= $total_kb ?> procédures chargées.</span>
                <?php endif; ?>
              </div>
              <span class="mtime">Maintenant</span>
            </div>
          </div>
          <div class="cfoot">
            <?php if ($ctx_count > 0): ?>
            <div class="ctxbadge no-print" onclick="clearCtx()"><i class="fas fa-brain"></i> <?= $ctx_count ?> échanges en mémoire</div>
            <?php endif; ?>
            <div class="qgrid no-print" id="qgrid">
              <?php foreach (array_slice($top_q, 0, 4) as $qpill): ?>
              <div class="qpill" onclick='ask(<?= json_encode($qpill['question']) ?>)'><?= h(substr($qpill['question'], 0, 36)) ?></div>
              <?php endforeach; ?>
            </div>
            <div class="irow">
              <div style="position:relative;flex:1">
                <input type="text" id="ci" class="cinput" placeholder="Posez votre question métier ERP…" autocomplete="off" oninput="onInp()" onkeydown="onKey(event)">
                <div id="acd" class="ac"></div>
              </div>
              <button id="sb" class="sbtn" onclick="sendStream()"><i class="fas fa-paper-plane"></i></button>
            </div>
          </div>
        </div>
      </div>
      <div>
      <div class="kpis">
          <div class="ks"><div class="ksico" style="background:rgba(168,85,247,.16);color:var(--purple)"><i class="fas fa-brain"></i></div><div><div class="ksv" id="kkb"><?= $total_kb ?></div><div class="ksl">Entrées KB</div></div></div>
          <div class="ks"><div class="ksico" style="background:rgba(50,190,143,.16);color:var(--neon)"><i class="fas fa-comments"></i></div><div><div class="ksv" id="kasks"><?= $total_asks ?></div><div class="ksl">Questions</div></div></div>
          <div class="ks"><div class="ksico" style="background:rgba(6,182,212,.16);color:var(--cyan)"><i class="fas fa-sitemap"></i></div><div><div class="ksv" id="ksite"><?= $siteIndexCount ?></div><div class="ksl">Pages indexées</div></div></div>
        </div>
        <div class="panel">
          <div class="ph"><div class="pht"><div class="dot p"></div> Questions populaires</div><span class="pbadge p">Top</span></div>
          <div class="pb">
            <?php foreach ($top_q as $index => $item): ?>
            <div class="sbr" style="cursor:pointer" onclick='ask(<?= json_encode($item['question']) ?>)'>
              <div style="width:16px;font-family:var(--fh);font-size:11px;font-weight:900;color:var(--muted);text-align:center"><?= $index + 1 ?></div>
              <div class="sbn"><?= h(substr($item['question'], 0, 36)) ?></div>
              <div class="sbw"><div class="sbf" style="width:<?= min(100, max(5, (int) $item['hits'] * 16)) ?>%"></div></div>
              <div class="sbvl"><?= (int) $item['hits'] ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="panel">
          <div class="ph"><div class="pht"><div class="dot n"></div> Catégories</div></div>
          <div class="pb" style="display:flex;flex-wrap:wrap;gap:6px">
            <?php foreach ($cats as $cat): ?>
            <span class="cat <?= h($catMap = ['stock' => 'cs', 'finance' => 'cf', 'rh' => 'cr', 'admin' => 'ca', 'clients' => 'cc'][$cat['category']] ?? 'cg') ?>" style="font-size:10px;padding:4px 10px"><?= h($cat['category']) ?> <strong>(<?= (int) $cat['cnt'] ?>)</strong></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="p-kb" style="display:none">
    <div class="panel">
      <div class="ph">
        <div class="pht"><div class="dot p"></div> Base de connaissances</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <?php if (can('kb.import')): ?><button onclick="openM('mimport')" class="btn btn-b btn-sm"><i class="fas fa-upload"></i> Import</button><?php endif; ?>
          <?php if (can('kb.export')): ?><a href="<?= h($agentErpControllerUrl) ?>?ajax=kb_export&fmt=csv" class="btn btn-n btn-sm" target="_blank" rel="noopener noreferrer"><i class="fas fa-file-csv"></i> CSV</a><?php endif; ?>
          <?php if (can('chat.learn')): ?><button onclick="openM('ml')" class="btn btn-g btn-sm"><i class="fas fa-plus"></i> Ajouter</button><?php endif; ?>
          <button onclick="loadKB(1)" class="btn btn-c btn-sm"><i class="fas fa-sync"></i></button>
        </div>
      </div>
      <div class="pb">
        <div class="srchbar">
          <input type="text" id="kbsearch" placeholder="Rechercher…" oninput="debKB()">
          <select id="kbcat" onchange="loadKB(1)"><option value="">Toutes catégories</option><?php foreach ($cats as $cat): ?><option value="<?= h($cat['category']) ?>"><?= h($cat['category']) ?></option><?php endforeach; ?></select>
          <select id="kbintent" onchange="loadKB(1)"><option value="">Tous types</option><option value="how_to">how_to</option><option value="action">action</option><option value="info">info</option><option value="diagnostic">diagnostic</option><option value="doc">doc</option></select>
          <select id="kbcompany" onchange="loadKB(1)"><option value="">Toutes sociétés</option><option value="general">general</option><option value="esperance_h2o">esperance_h2o</option><option value="saint_james">saint_james</option><option value="coredesk_africa">coredesk_africa</option></select>
        </div>
        <div class="tblw">
          <table class="tbl">
            <thead><tr><th>#</th><th>Question</th><th>Catégorie</th><th>Société</th><th>Intent</th><th>v</th><th>Hits</th><th>Maj</th><th>Actions</th></tr></thead>
            <tbody id="kbtb"><tr><td colspan="9" style="text-align:center;padding:24px;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i></td></tr></tbody>
          </table>
        </div>
        <div id="kbpgn" class="pgn"></div>
        <div id="kbinfo" style="text-align:center;font-size:10px;color:var(--muted);margin-top:7px"></div>
      </div>
    </div>
  </div>

  <div id="p-history" style="display:none">
    <div class="panel">
      <div class="ph"><div class="pht"><div class="dot n"></div> Mes 30 dernières questions</div><button onclick="loadHistory()" class="btn btn-c btn-sm"><i class="fas fa-sync"></i></button></div>
      <div class="pb" id="histcont"><div style="text-align:center;padding:24px;color:var(--muted)"><i class="fas fa-spinner fa-spin fa-2x"></i></div></div>
    </div>
  </div>

  <div id="p-stats" style="display:none"><div id="sc" style="padding:30px;text-align:center;color:var(--muted)"><i class="fas fa-spinner fa-spin fa-2x"></i></div></div>

  <?php if ($isAdmin): ?>
  <div id="p-admin" style="display:none">
    <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">
      <button onclick="st('admin')" class="btn btn-sm btn-r"><i class="fas fa-table"></i> Logs</button>
      <button onclick="st('admin-users')" class="btn btn-sm btn-p"><i class="fas fa-users-cog"></i> Utilisateurs</button>
      <button onclick="st('admin-audit')" class="btn btn-sm btn-g"><i class="fas fa-shield-alt"></i> Audit</button>
      <button onclick="st('admin-diag')" class="btn btn-sm btn-c"><i class="fas fa-wrench"></i> Diagnostic</button>
    </div>
    <div class="grid-2">
      <div class="panel">
        <div class="ph"><div class="pht"><div class="dot r"></div> Logs récents</div></div>
        <div class="pb"><div class="tblw"><table class="tbl">
          <thead><tr><th>Question</th><th>Intent</th><th>Lang</th><th>Société</th></tr></thead>
          <tbody>
          <?php foreach (q("SELECT question,intent_type,lang_detected,company_scope FROM agent_logs ORDER BY created_at DESC LIMIT 10") as $row): ?>
          <tr><td><?= h(substr($row['question'], 0, 52)) ?></td><td><?= h($row['intent_type']) ?></td><td><?= h(strtoupper($row['lang_detected'])) ?></td><td><?= h($row['company_scope']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div></div>
      </div>
      <div class="panel">
        <div class="ph"><div class="pht"><div class="dot g"></div> Permissions connues</div><button onclick="reindexSite()" class="btn btn-c btn-sm"><i class="fas fa-rotate"></i> Réindexer le site</button></div>
        <div class="pb" style="display:flex;flex-wrap:wrap;gap:6px">
          <?php foreach ($permissionCatalog as $perm): ?>
          <span class="cat cc" style="font-size:10px;padding:4px 10px"><?= h($perm['permission_key']) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div id="p-admin-users" style="display:none">
    <div class="panel">
      <div class="ph"><div class="pht"><div class="dot p"></div> Gestion des utilisateurs</div><div style="display:flex;gap:6px"><button onclick="openUserModal(0)" class="btn btn-n btn-sm"><i class="fas fa-plus"></i> Ajouter</button><button onclick="loadUsers()" class="btn btn-c btn-sm"><i class="fas fa-sync"></i></button></div></div>
      <div class="pb"><div class="tblw"><table class="tbl"><thead><tr><th>#</th><th>Utilisateur</th><th>Rôle</th><th>Statut</th><th>Rotation</th><th>Dernière connexion</th><th>Actions</th></tr></thead><tbody id="usertb"><tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i></td></tr></tbody></table></div></div>
    </div>
  </div>

  <div id="p-admin-audit" style="display:none">
    <div class="panel">
      <div class="ph"><div class="pht"><div class="dot g"></div> Journal d'audit</div><button onclick="loadAudit()" class="btn btn-c btn-sm"><i class="fas fa-sync"></i></button></div>
      <div class="pb"><div class="tblw"><table class="tbl"><thead><tr><th>#</th><th>Utilisateur</th><th>Action</th><th>Détails</th><th>IP</th><th>Sensible</th><th>Date</th></tr></thead><tbody id="audittb"><tr><td colspan="7" style="text-align:center;padding:24px;color:var(--muted)"><i class="fas fa-spinner fa-spin"></i></td></tr></tbody></table></div></div>
    </div>
  </div>

  <div id="p-admin-diag" style="display:none">
    <div class="panel">
      <div class="ph"><div class="pht"><div class="dot r"></div> Diagnostic système</div><button onclick="runDiag()" class="btn btn-c btn-sm"><i class="fas fa-wrench"></i> Lancer</button></div>
      <div class="pb"><pre id="diagout" style="font-family:monospace;font-size:11px;color:var(--text2);white-space:pre-wrap;max-height:420px;overflow-y:auto">Cliquez Lancer…</pre></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if (can('chat.learn')): ?>
<div id="ml" class="modal">
  <div class="mbox">
    <h2 style="color:var(--gold)"><i class="fas fa-graduation-cap"></i> Enseigner une procédure</h2>
    <div class="fg"><label>Question *</label><input type="text" id="lq"></div>
    <div class="fg"><label>Réponse *</label><textarea id="la"></textarea></div>
    <div class="fgr">
      <div class="fg"><label>Catégorie</label><select id="lcat"><option value="general">general</option><option value="stock">stock</option><option value="finance">finance</option><option value="rh">rh</option><option value="admin">admin</option><option value="clients">clients</option></select></div>
      <div class="fg"><label>Intention</label><select id="lint"><option value="how_to">how_to</option><option value="action">action</option><option value="info">info</option><option value="diagnostic">diagnostic</option><option value="doc">doc</option></select></div>
    </div>
    <div class="fgr">
      <div class="fg"><label>Société</label><select id="lcompany"><option value="general">general</option><option value="esperance_h2o">esperance_h2o</option><option value="saint_james">saint_james</option><option value="coredesk_africa">coredesk_africa</option></select></div>
      <div class="fg"><label>Permissions requises</label><input type="text" id="lperm" placeholder="sales.create,stock.view"></div>
    </div>
    <div class="fgr">
      <div class="fg"><label>Lien ERP</label><input type="text" id="lu"></div>
      <div class="fg"><label>Libellé bouton</label><input type="text" id="ll"></div>
    </div>
    <div class="mbtns"><button onclick="subLearn()" class="btn btn-g"><i class="fas fa-save"></i> Enseigner</button><button onclick="closeM('ml')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button></div>
  </div>
</div>
<?php endif; ?>

<?php if (can('kb.manage')): ?>
<div id="medit" class="modal">
  <div class="mbox mbox-lg">
    <h2 style="color:var(--cyan)"><i class="fas fa-edit"></i> Modifier KB <span id="editIdBadge" style="font-size:12px;color:var(--muted)"></span></h2>
    <input type="hidden" id="eid">
    <div class="fg"><label>Question *</label><input type="text" id="eq"></div>
    <div class="fg"><label>Réponse *</label><textarea id="ea" style="min-height:120px"></textarea></div>
    <div class="fgr">
      <div class="fg"><label>Catégorie</label><select id="ecat"><option value="general">general</option><option value="stock">stock</option><option value="finance">finance</option><option value="rh">rh</option><option value="admin">admin</option><option value="clients">clients</option></select></div>
      <div class="fg"><label>Intention</label><select id="eint"><option value="how_to">how_to</option><option value="action">action</option><option value="info">info</option><option value="diagnostic">diagnostic</option><option value="doc">doc</option></select></div>
    </div>
    <div class="fgr">
      <div class="fg"><label>Société</label><select id="ecompany"><option value="general">general</option><option value="esperance_h2o">esperance_h2o</option><option value="saint_james">saint_james</option><option value="coredesk_africa">coredesk_africa</option></select></div>
      <div class="fg"><label>Permissions requises</label><input type="text" id="eperm"></div>
    </div>
    <div class="fgr">
      <div class="fg"><label>Lien ERP</label><input type="text" id="eu"></div>
      <div class="fg"><label>Libellé bouton</label><input type="text" id="el"></div>
    </div>
    <div id="ehistory"></div>
    <div class="mbtns"><button onclick="subEdit()" class="btn btn-c"><i class="fas fa-save"></i> Enregistrer</button><button onclick="loadKbHistory()" class="btn btn-b"><i class="fas fa-history"></i> Historique</button><button onclick="closeM('medit')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button></div>
  </div>
</div>
<?php endif; ?>

<?php if (can('kb.import')): ?>
<div id="mimport" class="modal">
  <div class="mbox">
    <h2 style="color:var(--blue)"><i class="fas fa-upload"></i> Importer KB</h2>
    <div class="fg"><label>Fichier *</label><input type="file" id="impfile" accept=".json,.csv"></div>
    <div id="impres" style="font-size:11px;margin-top:7px"></div>
    <div class="mbtns"><button onclick="subImport()" class="btn btn-b"><i class="fas fa-upload"></i> Importer</button><button onclick="closeM('mimport')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button></div>
  </div>
</div>
<?php endif; ?>

<?php if (can('user.manage')): ?>
<div id="muser" class="modal">
  <div class="mbox">
    <h2 style="color:var(--purple)" id="musertitle"><i class="fas fa-user-plus"></i> Ajouter</h2>
    <input type="hidden" id="ueid">
    <div class="fgr">
      <div class="fg"><label>Identifiant *</label><input type="text" id="uusr"></div>
      <div class="fg"><label>Nom complet</label><input type="text" id="ufn"></div>
    </div>
    <div class="fgr">
      <div class="fg"><label>Rôle</label><select id="urol"><option value="viewer">viewer</option><option value="staff">staff</option><option value="caissiere">caissiere</option><option value="manager">manager</option><option value="Directrice">Directrice</option><option value="PDG">PDG</option><option value="admin">admin</option><option value="developer">developer</option></select></div>
      <div class="fg"><label>Mot de passe <span id="passreqlbl">*</span></label><input type="password" id="unpas" autocomplete="new-password"></div>
    </div>
    <div class="fg"><label>Couleur avatar</label><div class="cpick" id="cpick"><?php foreach (['#a855f7', '#32be8f', '#ffd060', '#ff3553', '#3d8cff', '#06b6d4', '#ff9140', '#ec4899'] as $color): ?><span style="background:<?= $color ?>" data-c="<?= $color ?>" onclick="selColor('<?= $color ?>')"></span><?php endforeach; ?></div><input type="hidden" id="uclr" value="#a855f7"></div>
    <div class="fg" style="display:flex;align-items:center;gap:9px"><label style="margin:0">Compte actif</label><input type="checkbox" id="uact" checked style="width:auto;transform:scale(1.2)"></div>
    <div class="fg" style="display:flex;align-items:center;gap:9px"><label style="margin:0">Rotation mot de passe</label><input type="checkbox" id="urot" checked style="width:auto;transform:scale(1.2)"></div>
    <div class="mbtns"><button onclick="subUser()" class="btn btn-p"><i class="fas fa-save"></i> Enregistrer</button><button onclick="closeM('muser')" class="btn btn-r"><i class="fas fa-times"></i> Annuler</button></div>
  </div>
</div>
<?php endif; ?>

<div id="mprofil" class="modal">
  <div class="mbox">
    <h2 style="color:var(--purple)"><i class="fas fa-user-circle"></i> Mon Profil</h2>
    <div class="prcard">
      <div class="pravtr" style="background:<?= h($ucolor) ?>"><?= strtoupper(substr($ufname, 0, 1)) ?></div>
      <div><div style="font-family:var(--fh);font-size:16px;font-weight:900;color:var(--text)"><?= h($ufname) ?></div><div style="font-size:11px;color:var(--muted);margin-top:3px">@<?= h($uname) ?></div><div class="cat cr" style="margin-top:7px"><?= h(strtoupper($urole)) ?></div></div>
    </div>
    <?php if ($cp_msg): ?><div class="<?= $cp_ok ? 'okmsg' : 'err' ?>"><?= h($cp_msg) ?></div><?php endif; ?>
    <?php if ($mustChangePassword): ?><div class="warn"><i class="fas fa-key"></i><span>La rotation du mot de passe est obligatoire avant de continuer.</span></div><?php endif; ?>
    <form method="POST">
      <div class="fg"><label>Ancien mot de passe</label><input type="password" name="old_pass"></div>
      <div class="fgr"><div class="fg"><label>Nouveau</label><input type="password" name="new_pass"></div><div class="fg"><label>Confirmer</label><input type="password" name="new_pass2"></div></div>
      <input type="hidden" name="do_change_pass" value="1">
      <button type="submit" class="btn btn-p btn-full"><i class="fas fa-key"></i> Changer le mot de passe</button>
    </form>
    <div class="mbtns" style="margin-top:12px"><a href="<?= h($agentErpPageUrl) ?>?logout=1" class="btn btn-r"><i class="fas fa-sign-out-alt"></i> Déconnexion</a><button onclick="closeM('mprofil')" class="btn btn-n"><i class="fas fa-times"></i> Fermer</button></div>
  </div>
</div>

<div id="confOverlay" class="conf-overlay">
  <div class="conf-box">
    <h3><i class="fas fa-exclamation-triangle"></i> Action sensible</h3>
    <p id="confMsg">Confirmez-vous cette action ?</p>
    <div class="action-row">
      <button id="confOk" class="btn btn-r"><i class="fas fa-check"></i> Confirmer</button>
      <button onclick="closeConf()" class="btn btn-n"><i class="fas fa-times"></i> Annuler</button>
    </div>
  </div>
</div>

<div class="ts" id="ts"></div>

<script>
window.AGENT_ERP_CONFIG = <?= json_encode([
    'controllerUrl' => $agentErpControllerUrl,
    'csrfToken' => $_SESSION['csrf_token'],
    'mustChangePassword' => (bool) $mustChangePassword,
    'isAdmin' => (bool) $isAdmin,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= h($agentErpJsUrl) ?>"></script>
</body>
</html>
