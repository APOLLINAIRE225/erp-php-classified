<?php
require __DIR__ . '/agent_erp/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!$isAuthenticated) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Non authentifié', 'redirect' => $agentErpPageUrl]);
    exit;
}

$act = $_GET['ajax'] ?? $_GET['action'] ?? '';

if ($act === 'stream') {
    $query = trim($_POST['q'] ?? $_GET['q'] ?? '');
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    if ($query === '' || !$pdo) {
        echo "data: " . json_encode(['done' => true, 'error' => 'Requête vide ou DB hors ligne']) . "\n\n";
        exit;
    }

    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    while (@ob_end_flush()) {
    }

    if (!can('chat.ask')) {
        echo "data: " . json_encode(['done' => true, 'error' => 'Permission insuffisante.']) . "\n\n";
        exit;
    }
    if (!rate_limit('search', 30, 60)) {
        echo "data: " . json_encode(['done' => true, 'error' => '⚠️ Trop de requêtes.']) . "\n\n";
        exit;
    }

    $start = microtime(true);
    $intent = detect_intent($query);
    $lang = detect_lang($query);
    $company = detect_company_scope($query);
    qx(
        "INSERT INTO agent_logs(user_id,user_role,question,ip_address,intent_type,lang_detected,company_scope)
         VALUES(?,?,?,?,?,?,?)",
        [$uid, $urole, $query, $_SERVER['REMOTE_ADDR'] ?? '', $intent, $lang, $company]
    );
    $logId = lid();
    $ctx = $_SESSION['chat_context'] ?? [];
    $results = agent_search($query, $ctx);

    if (!$results) {
        echo "data: " . json_encode(['found' => false, 'done' => true, 'log_id' => $logId, 'message' => "Je n'ai pas trouvé de réponse pour **\"" . addslashes($query) . "\"**.\nVoulez-vous m'apprendre comment faire ?", 'can_learn' => can('chat.learn')]) . "\n\n";
        exit;
    }

    $best = $results[0];
    qx("UPDATE agent_kb SET hits=hits+1 WHERE id=?", [$best['id']]);
    qx("UPDATE agent_logs SET kb_id=?,response_ms=? WHERE id=?", [$best['id'], (int) ((microtime(true) - $start) * 1000), $logId]);
    $prefix = get_tone_prefix($urole, $intent);
    $contextHint = '';
    if ($ctx) {
        $lastCat = end($ctx)['cat'] ?? '';
        if ($lastCat && $lastCat === ($best['category'] ?? 'general')) {
            $contextHint = "\n\n💡 *Suite de notre conversation sur **" . $lastCat . "***";
        }
    }
    $fullAnswer = $prefix . "\n\n" . ($best['answer'] ?? '') . $contextHint;
    $words = explode(' ', $fullAnswer);
    $buffer = '';
    foreach ($words as $i => $word) {
        $buffer .= ($i > 0 ? ' ' : '') . $word;
        if ($i % 4 === 3 || $i === count($words) - 1) {
            echo "data: " . json_encode(['chunk' => $buffer, 'i' => $i]) . "\n\n";
            flush();
            usleep(24000);
            $buffer = '';
        }
    }

    push_context($query, (string) ($best['answer'] ?? ''), (string) ($best['category'] ?? 'general'));
    $related = [];
    foreach (array_slice($results, 1) as $row) {
        $related[] = ['id' => $row['id'], 'question' => $row['question'], 'category' => $row['category']];
    }

    echo "data: " . json_encode([
        'done' => true,
        'log_id' => $logId,
        'kb_id' => $best['id'],
        'question' => $best['question'],
        'category' => $best['category'],
        'intent' => $intent,
        'lang' => $lang,
        'action_url' => $best['action_url'],
        'action_label' => $best['action_label'],
        'has_access' => can_access_entry($best['access_permissions'] ?? null, $best['access_roles'] ?? null, $urole),
        'related' => $related,
        'score' => $best['_score'] ?? 0,
    ]) . "\n\n";
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

if ($act === 'search') {
    $query = trim($_POST['q'] ?? '');
    if ($query === '') {
        echo json_encode(['found' => false, 'message' => 'Posez une question…']);
        exit;
    }
    if (!can('chat.ask')) {
        echo json_encode(['found' => false, 'message' => 'Permission insuffisante.']);
        exit;
    }
    if (!$pdo) {
        echo json_encode(['found' => false, 'message' => '⚠️ DB non disponible.']);
        exit;
    }
    if (!rate_limit('search', 30, 60)) {
        echo json_encode(['found' => false, 'message' => '⚠️ Trop de requêtes.']);
        exit;
    }
    $intent = detect_intent($query);
    $lang = detect_lang($query);
    $company = detect_company_scope($query);
    qx("INSERT INTO agent_logs(user_id,user_role,question,ip_address,intent_type,lang_detected,company_scope) VALUES(?,?,?,?,?,?,?)", [$uid, $urole, $query, $_SERVER['REMOTE_ADDR'] ?? '', $intent, $lang, $company]);
    $logId = lid();
    $ctx = $_SESSION['chat_context'] ?? [];
    $results = agent_search($query, $ctx);
    if (!$results) {
        echo json_encode(['found' => false, 'log_id' => $logId, 'message' => "Je n'ai pas trouvé de réponse pour **\"" . addslashes($query) . "\"**.\nVoulez-vous m'apprendre comment faire ?", 'can_learn' => can('chat.learn')]);
        exit;
    }
    $best = $results[0];
    qx("UPDATE agent_kb SET hits=hits+1 WHERE id=?", [$best['id']]);
    qx("UPDATE agent_logs SET kb_id=? WHERE id=?", [$best['id'], $logId]);
    push_context($query, (string) ($best['answer'] ?? ''), (string) ($best['category'] ?? 'general'));
    $related = [];
    foreach (array_slice($results, 1) as $row) {
        $related[] = ['id' => $row['id'], 'question' => $row['question'], 'category' => $row['category']];
    }
    echo json_encode([
        'found' => true,
        'log_id' => $logId,
        'kb_id' => $best['id'],
        'answer' => get_tone_prefix($urole, $intent) . "\n\n" . ($best['answer'] ?? ''),
        'question' => $best['question'],
        'category' => $best['category'],
        'intent' => $intent,
        'action_url' => $best['action_url'],
        'action_label' => $best['action_label'],
        'has_access' => can_access_entry($best['access_permissions'] ?? null, $best['access_roles'] ?? null, $urole),
        'related' => $related,
    ]);
    exit;
}

if ($act === 'learn') {
    if (!$pdo || !can('chat.learn')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    if (!rate_limit('learn', 5, 60)) {
        echo json_encode(['ok' => false, 'msg' => '⚠️ Trop de soumissions.']);
        exit;
    }
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $question = trim($d['question'] ?? '');
    $answer = trim($d['answer'] ?? '');
    $url = trim($d['url'] ?? '');
    $label = trim($d['label'] ?? '');
    $cat = trim($d['category'] ?? 'general');
    $intent = trim($d['intent'] ?? detect_intent($question));
    $permissions = trim($d['permissions'] ?? '');
    $company = trim($d['company_scope'] ?? detect_company_scope($question));
    if ($question === '' || $answer === '') {
        echo json_encode(['ok' => false, 'msg' => 'Question et réponse requises']);
        exit;
    }
    if (mb_strlen($answer) < 10) {
        echo json_encode(['ok' => false, 'msg' => 'Réponse trop courte (10 min.)']);
        exit;
    }
    $kws = implode(',', array_slice(extract_search_keywords($question . ' ' . $answer), 0, 50));
    $ex = q1("SELECT id,version FROM agent_kb WHERE LOWER(question)=?", [mb_strtolower($question, 'UTF-8')]);
    if ($ex) {
        kb_save_version((int) $ex['id'], (int) $uid, 'Auto-update via learn');
        $newVer = (int) $ex['version'] + 1;
        qx("UPDATE agent_kb SET answer=?,keywords=?,action_url=?,action_label=?,category=?,company_scope=?,access_permissions=?,intent_type=?,version=?,updated_by=?,updated_at=NOW() WHERE id=?", [$answer, $kws, $url, $label, $cat, $company, $permissions ?: null, $intent, $newVer, $uid, $ex['id']]);
        audit('kb_update', "KB #{$ex['id']} mise à jour: {$question}");
        echo json_encode(['ok' => true, 'id' => $ex['id'], 'msg' => "✅ Mis à jour (v{$newVer}) : \"{$question}\""]);
        exit;
    }
    qx("INSERT INTO agent_kb(keywords,question,answer,action_url,action_label,category,company_scope,access_permissions,intent_type,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)", [$kws, $question, $answer, $url, $label, $cat, $company, $permissions ?: null, $intent, $uid]);
    $newId = lid();
    audit('kb_create', "Nouvelle KB #{$newId}: {$question}");
    echo json_encode(['ok' => true, 'id' => $newId, 'msg' => "✅ Appris : \"{$question}\""]);
    exit;
}

if ($act === 'kb_edit') {
    if (!$pdo || !can('kb.manage')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int) ($d['id'] ?? 0);
    $row = q1("SELECT * FROM agent_kb WHERE id=?", [$id]);
    if (!$row) {
        echo json_encode(['ok' => false, 'msg' => 'Entrée introuvable']);
        exit;
    }
    kb_save_version($id, (int) $uid, 'Admin edit');
    $q2 = trim($d['question'] ?? $row['question']);
    $a2 = trim($d['answer'] ?? $row['answer']);
    $url = trim($d['url'] ?? ($row['action_url'] ?? ''));
    $label = trim($d['label'] ?? ($row['action_label'] ?? ''));
    $cat = trim($d['category'] ?? $row['category']);
    $permissions = trim($d['permissions'] ?? ($row['access_permissions'] ?? ''));
    $intent = trim($d['intent'] ?? ($row['intent_type'] ?? 'how_to'));
    $company = trim($d['company_scope'] ?? ($row['company_scope'] ?? 'general'));
    $keywords = implode(',', array_slice(extract_search_keywords($q2 . ' ' . $a2), 0, 50));
    $newVer = (int) ($row['version'] ?? 1) + 1;
    qx("UPDATE agent_kb SET question=?,answer=?,action_url=?,action_label=?,category=?,company_scope=?,access_permissions=?,intent_type=?,keywords=?,version=?,updated_by=?,updated_at=NOW() WHERE id=?", [$q2, $a2, $url, $label, $cat, $company, $permissions ?: null, $intent, $keywords, $newVer, $uid, $id]);
    audit('kb_edit', "KB #{$id} édité (v{$newVer})");
    echo json_encode(['ok' => true, 'msg' => "✅ Entrée #{$id} mise à jour (v{$newVer})."]);
    exit;
}

if ($act === 'kb_get') {
    if (!can('kb.manage')) {
        echo json_encode([]);
        exit;
    }
    echo json_encode(q1("SELECT * FROM agent_kb WHERE id=?", [(int) ($_GET['id'] ?? 0)]));
    exit;
}

if ($act === 'kb_history') {
    if (!can('kb.manage')) {
        echo json_encode([]);
        exit;
    }
    echo json_encode(q("SELECT h.*,u.username FROM agent_kb_history h LEFT JOIN agent_users u ON u.id=h.changed_by WHERE h.kb_id=? ORDER BY h.changed_at DESC LIMIT 15", [(int) ($_GET['id'] ?? 0)]));
    exit;
}

if ($act === 'kb_restore') {
    if (!can('kb.manage')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    $historyId = (int) ($_GET['hid'] ?? 0);
    $history = q1("SELECT * FROM agent_kb_history WHERE id=?", [$historyId]);
    if (!$history) {
        echo json_encode(['ok' => false, 'msg' => 'Version introuvable']);
        exit;
    }
    kb_save_version((int) $history['kb_id'], (int) $uid, 'Restore to v' . $history['version']);
    qx("UPDATE agent_kb SET question=?,answer=?,keywords=?,action_url=?,action_label=?,category=?,company_scope=?,access_roles=?,access_permissions=?,intent_type=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?", [$history['question'], $history['answer'], $history['keywords'], $history['action_url'], $history['action_label'], $history['category'], $history['company_scope'] ?? 'general', $history['access_roles'], $history['access_permissions'], $history['intent_type'] ?? 'how_to', $uid, $history['kb_id']]);
    audit('kb_restore', "KB #{$history['kb_id']} restauré à v{$history['version']}", true);
    echo json_encode(['ok' => true, 'msg' => "✅ KB #{$history['kb_id']} restauré à la version {$history['version']}."]);
    exit;
}

if ($act === 'context_clear') {
    if (!can('context.clear')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    $_SESSION['chat_context'] = [];
    echo json_encode(['ok' => true]);
    exit;
}

if ($act === 'suggest') {
    if (!$pdo || !can('kb.view')) {
        echo json_encode([]);
        exit;
    }
    $qs = trim($_GET['q'] ?? '');
    if (mb_strlen($qs, 'UTF-8') < 2) {
        echo json_encode([]);
        exit;
    }
    $like = '%' . $qs . '%';
    echo json_encode(q("SELECT id,question,category,intent_type FROM agent_kb WHERE question LIKE ? OR keywords LIKE ? ORDER BY hits DESC LIMIT 6", [$like, $like]));
    exit;
}

if ($act === 'feedback') {
    if (!can('feedback.send')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    $logId = (int) ($_GET['log_id'] ?? 0);
    $value = (int) ($_GET['val'] ?? 0);
    if ($logId) {
        qx("UPDATE agent_logs SET feedback=? WHERE id=? AND user_id=?", [$value, $logId, $uid]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($act === 'kb_list') {
    if (!$pdo || !can('kb.view')) {
        echo json_encode(['rows' => [], 'total' => 0, 'pages' => 0]);
        exit;
    }
    $cat = trim($_GET['cat'] ?? '');
    $intent = trim($_GET['intent'] ?? '');
    $company = trim($_GET['company_scope'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = 15;
    $offset = ($page - 1) * $limit;
    $where = "WHERE 1=1";
    $args = [];
    if ($cat !== '') {
        $where .= " AND category=?";
        $args[] = $cat;
    }
    if ($intent !== '') {
        $where .= " AND intent_type=?";
        $args[] = $intent;
    }
    if ($company !== '') {
        $where .= " AND company_scope=?";
        $args[] = $company;
    }
    if ($search !== '') {
        $where .= " AND (question LIKE ? OR keywords LIKE ?)";
        $like = '%' . $search . '%';
        $args[] = $like;
        $args[] = $like;
    }
    $total = (int) qv("SELECT COUNT(*) FROM agent_kb {$where}", $args);
    $rows = q("SELECT id,question,category,company_scope,hits,action_label,access_permissions,intent_type,version,created_at,updated_at FROM agent_kb {$where} ORDER BY hits DESC,id DESC LIMIT {$limit} OFFSET {$offset}", $args);
    echo json_encode(['rows' => $rows, 'total' => $total, 'pages' => (int) ceil($total / $limit), 'page' => $page]);
    exit;
}

if ($act === 'kb_delete') {
    if (!can('kb.delete')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    $id = (int) ($_GET['id'] ?? 0);
    kb_save_version($id, (int) $uid, 'Deleted');
    qx("DELETE FROM agent_kb WHERE id=?", [$id]);
    audit('kb_delete', "KB #{$id} supprimé", true);
    echo json_encode(['ok' => true]);
    exit;
}

if ($act === 'kb_export') {
    if (!can('kb.export')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    $fmt = $_GET['fmt'] ?? 'json';
    $rows = q("SELECT id,question,answer,keywords,action_url,action_label,category,company_scope,access_permissions,intent_type,version,hits,created_at FROM agent_kb ORDER BY id");
    if ($fmt === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="kb_export_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'question', 'answer', 'keywords', 'action_url', 'action_label', 'category', 'company_scope', 'access_permissions', 'intent_type', 'version', 'hits', 'created_at'], ';');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        fclose($out);
        exit;
    }
    header('Content-Disposition: attachment; filename="kb_export_' . date('Ymd_His') . '.json"');
    echo json_encode(['export_date' => date('Y-m-d H:i:s'), 'count' => count($rows), 'data' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($act === 'kb_import') {
    if (!can('kb.import')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    if (!isset($_FILES['file'])) {
        echo json_encode(['ok' => false, 'msg' => 'Aucun fichier']);
        exit;
    }
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    $content = (string) file_get_contents($_FILES['file']['tmp_name']);
    $imported = 0;
    $skipped = 0;
    if ($ext === 'json') {
        $data = json_decode($content, true);
        $rows = $data['data'] ?? $data;
        if (!is_array($rows)) {
            echo json_encode(['ok' => false, 'msg' => 'JSON invalide']);
            exit;
        }
    } elseif ($ext === 'csv') {
        $lines = array_map(static fn($line) => str_getcsv($line, ';'), array_filter(explode("\n", $content)));
        array_shift($lines);
        $rows = [];
        foreach ($lines as $line) {
            if (count($line) < 3) {
                continue;
            }
            $rows[] = [
                'question' => $line[1] ?? '',
                'answer' => $line[2] ?? '',
                'keywords' => $line[3] ?? '',
                'action_url' => $line[4] ?? '',
                'action_label' => $line[5] ?? '',
                'category' => $line[6] ?? 'general',
                'company_scope' => $line[7] ?? 'general',
                'access_permissions' => $line[8] ?? null,
                'intent_type' => $line[9] ?? 'how_to',
            ];
        }
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Format non supporté']);
        exit;
    }
    $existing = array_column(q("SELECT LOWER(question) q FROM agent_kb"), 'q');
    foreach ($rows as $row) {
        $question = trim($row['question'] ?? '');
        $answer = trim($row['answer'] ?? '');
        if ($question === '' || $answer === '') {
            $skipped++;
            continue;
        }
        if (in_array(mb_strtolower($question, 'UTF-8'), $existing, true)) {
            $skipped++;
            continue;
        }
        qx("INSERT INTO agent_kb(keywords,question,answer,action_url,action_label,category,company_scope,access_permissions,intent_type,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)", [$row['keywords'] ?? implode(',', extract_search_keywords($question . ' ' . $answer)), $question, $answer, $row['action_url'] ?? null, $row['action_label'] ?? null, $row['category'] ?? 'general', $row['company_scope'] ?? detect_company_scope($question), $row['access_permissions'] ?? null, $row['intent_type'] ?? 'how_to', $uid]);
        $imported++;
    }
    audit('kb_import', "Import KB: {$imported} entrées");
    echo json_encode(['ok' => true, 'msg' => "✅ {$imported} importées, {$skipped} ignorées."]);
    exit;
}

if ($act === 'my_history') {
    echo json_encode(q("SELECT al.question,al.feedback,al.created_at,al.intent_type,al.lang_detected,al.response_ms,al.company_scope,ak.question AS kb_q,ak.category FROM agent_logs al LEFT JOIN agent_kb ak ON ak.id=al.kb_id WHERE al.user_id=? ORDER BY al.created_at DESC LIMIT 30", [$uid]));
    exit;
}

if ($act === 'user_list') {
    if (!can('user.manage')) {
        echo json_encode([]);
        exit;
    }
    echo json_encode(q("SELECT id,username,role,full_name,avatar_color,is_active,must_change_password,last_login,login_attempts,created_at FROM agent_users ORDER BY id"));
    exit;
}

if ($act === 'user_save') {
    if (!can('user.manage')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int) ($d['id'] ?? 0);
    $username = trim($d['username'] ?? '');
    $role = trim($d['role'] ?? 'viewer');
    $fullName = trim($d['full_name'] ?? '');
    $avatarColor = trim($d['avatar_color'] ?? '#a855f7');
    $isActive = (int) ($d['is_active'] ?? 1);
    $newPass = trim($d['new_pass'] ?? '');
    $mustRotate = (int) ($d['must_change_password'] ?? 0);
    if ($id) {
        qx("UPDATE agent_users SET username=?,role=?,full_name=?,avatar_color=?,is_active=?,must_change_password=? WHERE id=?", [$username, $role, $fullName, $avatarColor, $isActive, $mustRotate, $id]);
        if ($newPass !== '') {
            qx("UPDATE agent_users SET password_hash=?,login_attempts=0,locked_until=NULL,must_change_password=? WHERE id=?", [password_hash($newPass, PASSWORD_DEFAULT), $mustRotate ?: 1, $id]);
        }
        audit('user_update', "User #{$id} updated");
        echo json_encode(['ok' => true, 'msg' => "✅ Utilisateur #{$id} mis à jour."]);
        exit;
    }
    if ($username === '' || $newPass === '') {
        echo json_encode(['ok' => false, 'msg' => 'Username et mot de passe requis.']);
        exit;
    }
    qx("INSERT INTO agent_users(username,password_hash,role,full_name,avatar_color,is_active,must_change_password) VALUES(?,?,?,?,?,?,?)", [$username, password_hash($newPass, PASSWORD_DEFAULT), $role, $fullName, $avatarColor, $isActive, $mustRotate ?: 1]);
    audit('user_create', "New user: {$username}");
    echo json_encode(['ok' => true, 'id' => lid(), 'msg' => "✅ Utilisateur \"{$username}\" créé."]);
    exit;
}

if ($act === 'user_delete') {
    if (!can('user.manage')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id === (int) $uid) {
        echo json_encode(['ok' => false, 'msg' => 'Impossible de supprimer votre propre compte.']);
        exit;
    }
    qx("DELETE FROM agent_users WHERE id=?", [$id]);
    audit('user_delete', "User #{$id} deleted", true);
    echo json_encode(['ok' => true]);
    exit;
}

if ($act === 'user_unlock') {
    if (!can('user.manage')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    qx("UPDATE agent_users SET login_attempts=0,locked_until=NULL WHERE id=?", [(int) ($_GET['id'] ?? 0)]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($act === 'audit_log') {
    if (!can('audit.view')) {
        echo json_encode([]);
        exit;
    }
    echo json_encode(q("SELECT a.*,u.username,u.full_name FROM agent_audit a LEFT JOIN agent_users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 80"));
    exit;
}

if ($act === 'permissions') {
    if (!can('user.manage')) {
        echo json_encode([]);
        exit;
    }
    echo json_encode(['permissions' => q("SELECT permission_key,label FROM agent_permissions ORDER BY permission_key"), 'role_permissions' => q("SELECT role_name,permission_key FROM agent_role_permissions ORDER BY role_name,permission_key")]);
    exit;
}

if ($act === 'stats') {
    if (!can('analytics.view')) {
        echo json_encode(['total_kb' => 0, 'total_asks' => 0, 'answered' => 0, 'unanswered' => 0, 'positive_fb' => 0, 'answer_rate' => 0, 'avg_ms' => 0, 'top_kb' => [], 'daily' => [], 'by_cat' => [], 'by_intent' => [], 'unanswered_q' => [], 'unanswered_groups' => [], 'satisfaction_by_role' => [], 'avg_by_intent' => [], 'top_by_company' => []]);
        exit;
    }
    $tk = (int) qv("SELECT COUNT(*) FROM agent_kb");
    $ta = (int) qv("SELECT COUNT(*) FROM agent_logs");
    $answered = (int) qv("SELECT COUNT(*) FROM agent_logs WHERE kb_id IS NOT NULL");
    $positive = (int) qv("SELECT COUNT(*) FROM agent_logs WHERE feedback=1");
    $avgMs = (int) qv("SELECT AVG(response_ms) FROM agent_logs WHERE response_ms > 0");
    echo json_encode([
        'total_kb' => $tk,
        'total_asks' => $ta,
        'answered' => $answered,
        'unanswered' => $ta - $answered,
        'positive_fb' => $positive,
        'answer_rate' => $ta > 0 ? round($answered / $ta * 100) : 0,
        'avg_ms' => $avgMs,
        'top_kb' => q("SELECT question,hits,category,intent_type,company_scope FROM agent_kb ORDER BY hits DESC LIMIT 5"),
        'daily' => q("SELECT DATE(created_at) d,COUNT(*) c FROM agent_logs WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY d"),
        'by_cat' => q("SELECT category,COUNT(*) cnt FROM agent_kb GROUP BY category ORDER BY cnt DESC"),
        'by_intent' => q("SELECT intent_type,COUNT(*) cnt FROM agent_logs WHERE intent_type IS NOT NULL GROUP BY intent_type ORDER BY cnt DESC"),
        'unanswered_q' => q("SELECT question,created_at,lang_detected FROM agent_logs WHERE kb_id IS NULL ORDER BY created_at DESC LIMIT 8"),
        'unanswered_groups' => q("SELECT LOWER(TRIM(question)) question_norm, MIN(question) question_sample, COUNT(*) cnt, MAX(created_at) last_seen FROM agent_logs WHERE kb_id IS NULL GROUP BY LOWER(TRIM(question)) ORDER BY cnt DESC, last_seen DESC LIMIT 8"),
        'satisfaction_by_role' => q("SELECT user_role, COUNT(*) total, SUM(CASE WHEN feedback=1 THEN 1 ELSE 0 END) positive, ROUND(SUM(CASE WHEN feedback=1 THEN 1 ELSE 0 END) / NULLIF(COUNT(CASE WHEN feedback IS NOT NULL THEN 1 END),0) * 100) rate FROM agent_logs WHERE feedback IS NOT NULL GROUP BY user_role ORDER BY rate DESC"),
        'avg_by_intent' => q("SELECT intent_type, ROUND(AVG(response_ms)) avg_ms, COUNT(*) cnt FROM agent_logs WHERE response_ms > 0 GROUP BY intent_type ORDER BY avg_ms ASC"),
        'top_by_company' => q("SELECT COALESCE(al.company_scope, ak.company_scope, 'general') company_scope, ak.question, COUNT(*) usage_count FROM agent_logs al LEFT JOIN agent_kb ak ON ak.id=al.kb_id WHERE al.kb_id IS NOT NULL GROUP BY COALESCE(al.company_scope, ak.company_scope, 'general'), al.kb_id, ak.question ORDER BY usage_count DESC LIMIT 10"),
        'site_index_count' => (int) qv("SELECT COUNT(*) FROM agent_site_index"),
    ]);
    exit;
}

if ($act === 'reindex_site') {
    if (!can('diag.view')) {
        echo json_encode(['ok' => false, 'msg' => 'Accès refusé']);
        exit;
    }
    require_ajax_csrf();
    $count = rebuild_site_index(true);
    audit('site_reindex', "Site index rebuilt: {$count} fichiers");
    echo json_encode(['ok' => true, 'count' => $count, 'msg' => "Index interne reconstruit ({$count} fichiers)."]);
    exit;
}

if ($act === 'diag') {
    if (!can('diag.view')) {
        echo json_encode(['error' => 'Accès refusé']);
        exit;
    }
    $info = [
        'php' => PHP_VERSION,
        'erp_path' => $erp_path,
        'erp_exists' => is_dir($erp_path),
        'db_connected' => ($pdo !== null),
        'db_name' => $db_name,
        'db_error' => $db_error,
        'kb_count' => (int) qv("SELECT COUNT(*) FROM agent_kb"),
        'logs_count' => (int) qv("SELECT COUNT(*) FROM agent_logs"),
        'users_count' => (int) qv("SELECT COUNT(*) FROM agent_users"),
        'history_count' => (int) qv("SELECT COUNT(*) FROM agent_kb_history"),
        'audit_count' => (int) qv("SELECT COUNT(*) FROM agent_audit"),
        'bootstrap_pending' => (int) qv("SELECT COUNT(*) FROM agent_bootstrap_tokens WHERE used_at IS NULL AND expires_at > NOW()"),
        'cfg_files' => [],
    ];
    foreach (["$erp_path/includes/config.php", "$erp_path/app/core/DB.php", "$erp_path/config.php"] as $cf) {
        $info['cfg_files'][$cf] = file_exists($cf);
    }
    echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'unknown action']);
