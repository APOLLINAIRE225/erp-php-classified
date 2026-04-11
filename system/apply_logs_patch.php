<?php
require_once dirname(__DIR__, 2) . '/_php_classified/bootstrap_paths.php';

/**
 * PATCHEUR AUTOMATIQUE — caisse_complete_enhanced.php
 * Lance ce script UNE SEULE FOIS depuis le meme dossier.
 * Il modifie directement caisse_complete_enhanced.php
 */

$file = project_path('finance/caisse_complete_enhanced.php');
if (!file_exists($file)) die("❌ Fichier introuvable : $file\n");

$content = file_get_contents($file);
$original = $content;

// ════════════════════════════════════════════════════════
// PATCH 1 — Requête PHP : ajouter $logs_bons
// ════════════════════════════════════════════════════════
$old1 = '/* ── Logs ── */
$logs = [];
if ($location_set && $view_mode === \'logs\') {
    $st = $pdo->prepare("SELECT cl.*,u.username user_name FROM cash_log cl
        LEFT JOIN users u ON u.id=cl.user_id
        WHERE DATE(cl.created_at)=? ORDER BY cl.created_at DESC LIMIT 500");
    $st->execute([$date_filter]);
    $logs = $st->fetchAll(PDO::FETCH_ASSOC);
}';

$new1 = '/* ── Logs ── */
$logs = [];
$logs_bons = [];
if ($location_set && $view_mode === \'logs\') {

    /* ── 1. Logs classiques cash_log ── */
    $st = $pdo->prepare("SELECT cl.*,u.username user_name FROM cash_log cl
        LEFT JOIN users u ON u.id=cl.user_id
        WHERE DATE(cl.created_at)=? ORDER BY cl.created_at DESC LIMIT 500");
    $st->execute([$date_filter]);
    $logs = $st->fetchAll(PDO::FETCH_ASSOC);

    /* ── 2. Bons de livraison facturés ce jour ── */
    try {
        $st2 = $pdo->prepare("
            SELECT
                o.id           AS order_id,
                o.order_number,
                o.total_amount,
                o.status,
                o.invoiced_at,
                o.invoiced_by,
                c.name         AS client_name,
                c.phone        AS client_phone,
                GROUP_CONCAT(
                    CONCAT(oi.product_name, \' x\', oi.quantity)
                    ORDER BY oi.id SEPARATOR \' | \'
                ) AS articles,
                (SELECT COUNT(*) FROM caisse_logs cll
                 WHERE cll.order_id = o.id AND cll.action = \'STOCK_UPDATE\') AS nb_stock_ok,
                (SELECT COUNT(*) FROM caisse_logs cll
                 WHERE cll.order_id = o.id AND cll.action = \'STOCK_ERROR\')  AS nb_stock_err
            FROM orders o
            LEFT JOIN clients     c  ON c.id  = o.client_id
            LEFT JOIN order_items oi ON oi.order_id = o.id
            WHERE o.company_id  = ?
              AND o.city_id     = ?
              AND DATE(o.invoiced_at) = ?
            GROUP BY o.id
            ORDER BY o.invoiced_at DESC
        ");
        $st2->execute([$company_id, $city_id, $date_filter]);
        $logs_bons = $st2->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $logs_bons = [];
    }
}';

if (str_contains($content, $old1)) {
    $content = str_replace($old1, $new1, $content);
    echo "✅ PATCH 1 appliqué (requête PHP logs_bons)\n";
} else {
    echo "⚠️  PATCH 1 : bloc non trouvé (déjà patché ?)\n";
}

// ════════════════════════════════════════════════════════
// PATCH 2 — Vue HTML : remplacer le bloc logs complet
// ════════════════════════════════════════════════════════
// On remplace depuis le commentaire VUE LOGS jusqu au commentaire VUE RAPPORTS
$pattern = '/(\/\*[\s\S]*?VUE: LOGS[\s\S]*?\*\/\nelseif\(\$view_mode === \'logs\'\):[\s\S]*?)(?=\n<\?php \/\* ═+\s*VUE: RAPPORTS)/';

$new_html = '<?php /* ══════════════════════════════════════
         VUE: LOGS — bons facturés + actions système
    ══════════════════════════════════════ */
elseif($view_mode === \'logs\'): ?>

<!-- Filtre date -->
<div style="display:flex;justify-content:flex-end;margin-bottom:16px">
    <input type="date" value="<?= $date_filter ?>"
           onchange="window.location=\'?view=logs&date_filter=\'+this.value"
           class="f-input" style="width:auto;margin:0;padding:9px 14px">
</div>

<?php
$total_bons_fcfa = array_sum(array_column($logs_bons,\'total_amount\'));
$nb_bons_err     = count(array_filter($logs_bons, fn($b) => (int)$b[\'nb_stock_err\'] > 0));
$nb_bons_ok      = count($logs_bons) - $nb_bons_err;
?>

<!-- KPI bons -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px">
    <div class="ks">
        <div class="ks-ico" style="background:rgba(6,182,212,0.14);color:var(--cyan)"><i class="fas fa-truck"></i></div>
        <div><div class="ks-val" style="color:var(--cyan)"><?= count($logs_bons) ?></div><div class="ks-lbl">Bons facturés</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(255,208,96,0.14);color:var(--gold)"><i class="fas fa-coins"></i></div>
        <div><div class="ks-val" style="color:var(--gold)"><?= number_format($total_bons_fcfa,0,\',\',\' \') ?></div><div class="ks-lbl">Total bons (FCFA)</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(50,190,143,0.14);color:var(--neon)"><i class="fas fa-check-circle"></i></div>
        <div><div class="ks-val" style="color:var(--neon)"><?= $nb_bons_ok ?></div><div class="ks-lbl">Stock OK</div></div>
    </div>
    <div class="ks">
        <div class="ks-ico" style="background:rgba(<?= $nb_bons_err>0?\'255,53,83\':\'90,128,112\' ?>,0.14);color:var(--<?= $nb_bons_err>0?\'red\':\'muted\' ?>)"><i class="fas fa-exclamation-triangle"></i></div>
        <div><div class="ks-val" style="color:var(--<?= $nb_bons_err>0?\'red\':\'muted\' ?>)"><?= $nb_bons_err ?></div><div class="ks-lbl">Erreurs stock</div></div>
    </div>
</div>

<!-- BONS FACTURÉS -->
<div class="panel" style="margin-bottom:18px">
    <div class="ph">
        <div class="ph-title">
            <div class="dot c"></div>
            <i class="fas fa-truck"></i> &nbsp;Bons de livraison facturés
            <span style="font-family:var(--fb);font-size:11px;color:var(--muted);font-weight:500">— stock mis à jour automatiquement</span>
        </div>
        <span class="pbadge c"><?= count($logs_bons) ?> bon(s)</span>
    </div>
    <div class="pb">
    <?php if(empty($logs_bons)): ?>
        <div style="display:flex;align-items:center;gap:16px;padding:20px;background:rgba(6,182,212,0.04);border:1px dashed rgba(6,182,212,0.2);border-radius:12px">
            <i class="fas fa-truck" style="font-size:28px;color:var(--cyan);opacity:0.3"></i>
            <div>
                <div style="font-family:var(--fh);font-weight:900;color:var(--muted)">Aucun bon facturé ce jour</div>
                <div style="font-family:var(--fb);font-size:12px;color:var(--muted);opacity:0.7;margin-top:3px">Les bons ouverts via ticket.php apparaîtront ici.</div>
            </div>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table class="tbl">
            <thead><tr>
                <th>Heure</th><th>Bon N°</th><th>Client</th><th>Articles</th>
                <th>Total</th><th>Statut</th><th>Stock</th><th>Facturé par</th><th>Action</th>
            </tr></thead>
            <tbody>
            <?php foreach($logs_bons as $bon):
                $sok  = (int)$bon[\'nb_stock_ok\']; $serr = (int)$bon[\'nb_stock_err\'];
                $SL   = [\'confirmed\'=>\'A LIVRER\',\'delivering\'=>\'EN LIVRAISON\',\'done\'=>\'LIVRÉE\',\'cancelled\'=>\'ANNULÉE\',\'pending\'=>\'EN ATTENTE\'];
                $sl   = $SL[$bon[\'status\']] ?? strtoupper($bon[\'status\'] ?? \'\');
                $sc   = match($bon[\'status\']) {
                    \'done\'       => \'bdg-g\',
                    \'confirmed\'  => \'bdg-cyan\',
                    \'delivering\' => \'bdg-blue\',
                    \'cancelled\'  => \'bdg-r\',
                    default        => \'bdg-gold\'
                };
            ?>
            <tr style="<?= $serr > 0 ? \'background:rgba(255,53,83,0.04)\' : \'\'  ?>">
                <td style="color:var(--muted);font-family:var(--fh);font-size:13px;white-space:nowrap">
                    <strong><?= date(\'H:i:s\', strtotime($bon[\'invoiced_at\'])) ?></strong>
                </td>
                <td>
                    <strong style="color:var(--cyan)"><?= htmlspecialchars($bon[\'order_number\'] ?? \'BON-\'.$bon[\'order_id\']) ?></strong>
                    <br><small style="color:var(--muted);font-size:10px">#<?= $bon[\'order_id\'] ?></small>
                </td>
                <td>
                    <strong><?= htmlspecialchars($bon[\'client_name\'] ?? \'—\') ?></strong>
                    <?php if($bon[\'client_phone\']): ?>
                    <br><small style="color:var(--muted)"><?= htmlspecialchars($bon[\'client_phone\']) ?></small>
                    <?php endif; ?>
                </td>
                <td style="max-width:200px;font-size:12px;color:var(--text2);line-height:1.5">
                    <?= htmlspecialchars($bon[\'articles\'] ?? \'—\') ?>
                </td>
                <td><strong style="font-family:var(--fh);color:var(--gold);font-size:14px">
                    <?= number_format((float)$bon[\'total_amount\'], 0, \',\', \' \') ?> FCFA
                </strong></td>
                <td><span class="bdg <?= $sc ?>"><?= $sl ?></span></td>
                <td>
                    <?php if($serr > 0): ?>
                        <span class="bdg bdg-r"><i class="fas fa-exclamation-triangle"></i> <?= $serr ?> erreur(s)</span>
                    <?php elseif($sok > 0): ?>
                        <span class="bdg bdg-g"><i class="fas fa-check"></i> OK (<?= $sok ?> art.)</span>
                    <?php else: ?>
                        <span class="bdg bdg-gold"><i class="fas fa-question"></i> Non vérifié</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--neon);font-weight:700;font-size:12px">
                    <i class="fas fa-user-circle"></i> <?= htmlspecialchars($bon[\'invoiced_by\'] ?? \'—\') ?>
                </td>
                <td>
                    <a href="ticket.php?order_id=<?= $bon[\'order_id\'] ?>" target="_blank" class="btn btn-cyan btn-xs">
                        <i class="fas fa-eye"></i> Ticket
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<!-- LOGS SYSTÈME -->
<div class="panel">
    <div class="ph">
        <div class="ph-title"><div class="dot b"></div> Journal des actions système</div>
        <span class="pbadge b" style="background:rgba(61,140,255,0.12);color:var(--blue)"><?= count($logs) ?> entrée(s)</span>
    </div>
    <div class="pb">
        <div style="max-height:550px;overflow-y:auto">
            <?php foreach($logs as $lg):
                $lc = match(true) {
                    str_contains($lg[\'action_type\'],\'DELETE\')
                    || str_contains($lg[\'action_type\'],\'ERROR\')
                    || str_contains($lg[\'action_type\'],\'BREACH\') => \'bdg-r\',
                    str_contains($lg[\'action_type\'],\'SALE\')     => \'bdg-g\',
                    str_contains($lg[\'action_type\'],\'EXPORT\')   => \'bdg-blue\',
                    str_contains($lg[\'action_type\'],\'EXPENSE\')  => \'bdg-gold\',
                    str_contains($lg[\'action_type\'],\'APPRO\')    => \'bdg-cyan\',
                    str_contains($lg[\'action_type\'],\'STOCK\')
                    || str_contains($lg[\'action_type\'],\'INVOICE\') => \'bdg-orange\',
                    default => \'bdg-purple\'
                };
            ?>
            <div class="log-item">
                <span class="log-time"><?= date(\'H:i:s\', strtotime($lg[\'created_at\'])) ?></span>
                <span class="bdg <?= $lc ?>"><?= htmlspecialchars($lg[\'action_type\']) ?></span>
                <span class="log-desc">
                    <strong><?= htmlspecialchars($lg[\'user_name\'] ?? \'?\') ?></strong> —
                    <?= htmlspecialchars($lg[\'action_description\']) ?>
                    <?php if($lg[\'amount\']): ?>
                        &nbsp;<strong style="color:var(--gold)"><?= number_format($lg[\'amount\'],0,\',\',\' \') ?> FCFA</strong>
                    <?php endif; ?>
                    <?php if(!empty($lg[\'quantity\'])): ?>
                        &nbsp;<span style="color:var(--muted);font-size:11px">qté: <?= $lg[\'quantity\'] ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php if(empty($logs)): ?>
            <div style="text-align:center;padding:32px;color:var(--muted)">
                <i class="fas fa-history" style="font-size:36px;display:block;margin-bottom:10px;opacity:.15"></i>
                Aucune action système pour cette date
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>';

$count = 0;
$content = preg_replace($pattern, $new_html, $content, 1, $count);
if ($count > 0) {
    echo "✅ PATCH 2 appliqué (vue HTML logs)\n";
} else {
    echo "⚠️  PATCH 2 : pattern non trouvé\n";
}

// Backup + écriture
if ($content !== $original) {
    copy($file, $file . '.bak_' . date('YmdHis'));
    file_put_contents($file, $content);
    echo "✅ Fichier sauvegardé (backup .bak créé)\n";
    echo "🚀 Mise à jour terminée !\n";
} else {
    echo "ℹ️  Aucune modification — les patches sont peut-être déjà appliqués.\n";
}
