<?php
require_once dirname(__DIR__) . '/bootstrap_paths.php';
require_once __DIR__ . '/legal_bootstrap.php';

$profile = legal_company_profile();
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Politique de confidentialité | ESPERANCE H2O</title>
<meta name="description" content="Politique de confidentialité de la plateforme ESPERANCE H2O.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#eef0f4;
    --surface:#ffffff;
    --panel:#f5f6f9;
    --border:#dde1e9;
    --text:#1a1f2e;
    --text2:#4a5368;
    --muted:#8a93a8;
    --amber:#e8860a;
    --amber-soft:#fdf3e3;
    --blue:#2563eb;
    --blue-soft:#eff4ff;
    --shadow:0 16px 40px rgba(0,0,0,.08);
    --radius:22px;
    --f:'DM Sans',sans-serif;
    --fh:'Syne',sans-serif;
}
body{
    font-family:var(--f);
    color:var(--text);
    background:
        radial-gradient(circle at top left, rgba(37,99,235,.08), transparent 34%),
        radial-gradient(circle at bottom right, rgba(232,134,10,.10), transparent 28%),
        var(--bg);
    min-height:100vh;
    padding:24px 16px 40px;
}
.shell{max-width:980px;margin:0 auto}
.topbar{
    display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;
    margin-bottom:18px;padding:14px 16px;background:rgba(255,255,255,.8);border:1px solid var(--border);
    border-radius:18px;backdrop-filter:blur(12px)
}
.brand{display:flex;align-items:center;gap:12px}
.mark{
    width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,#1e3a5f,#2563eb);
    display:grid;place-items:center;color:#fff;font-size:20px;box-shadow:0 10px 24px rgba(37,99,235,.24)
}
.brand h1{font:800 1rem var(--fh);letter-spacing:-.02em}
.brand p{font-size:.8rem;color:var(--muted)}
.back{
    display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;text-decoration:none;
    color:var(--text);background:var(--surface);border:1px solid var(--border);font-weight:700
}
.hero,.content{
    background:rgba(255,255,255,.92);
    border:1px solid var(--border);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
}
.hero{padding:28px 24px;margin-bottom:18px}
.eyebrow{
    display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;
    background:var(--amber-soft);color:#a45b06;font-size:.8rem;font-weight:700;margin-bottom:16px
}
.hero h2{font:800 clamp(2rem,5vw,3.5rem)/1 var(--fh);margin-bottom:12px;max-width:12ch}
.hero p{max-width:70ch;color:var(--text2);line-height:1.8}
.content{padding:26px 24px}
.section + .section{margin-top:24px;padding-top:24px;border-top:1px solid var(--border)}
.section h3{font:800 1.05rem var(--fh);margin-bottom:10px}
.section p,.section li{color:var(--text2);line-height:1.8}
.section ul{padding-left:18px}
.note{
    margin-top:16px;padding:14px 16px;border-radius:16px;background:var(--blue-soft);
    border:1px solid rgba(37,99,235,.14);color:#2149a6
}
.footer{margin-top:18px;text-align:center;color:var(--muted);font-size:.88rem}
@media (max-width:640px){
    .hero,.content{padding:20px 18px}
    .brand h1{font-size:.95rem}
}
</style>
</head>
<body>
<div class="shell">
    <div class="topbar">
        <div class="brand">
            <div class="mark"><i class="fas fa-shield-halved"></i></div>
            <div>
                <h1>ESPERANCE H2O</h1>
                <p>Portail de gestion interne et client</p>
            </div>
        </div>
        <a class="back" href="<?= htmlspecialchars(project_url('auth/login_unified.php')) ?>">
            <i class="fas fa-arrow-left"></i> Retour au portail
        </a>
    </div>

    <section class="hero">
        <div class="eyebrow"><i class="fas fa-user-shield"></i> Politique de confidentialité</div>
        <h2>Protection des données personnelles</h2>
        <p>
            Cette page décrit la manière dont la plateforme ESPERANCE H2O collecte, utilise, protège et conserve
            les données nécessaires à l’authentification, à la gestion des commandes, à la relation client et à
            l’administration interne.
        </p>
    </section>

    <section class="content">
        <div class="section">
            <h3>0. Responsable du traitement</h3>
            <p>
                La plateforme est exploitée sous la marque <?= htmlspecialchars($profile['brand']) ?> avec les entités
                enregistrées dans le système <?= htmlspecialchars($profile['entities'][0]['name']) ?> et
                <?= htmlspecialchars($profile['entities'][1]['name']) ?>. Point de contact déclaré :
                <?= htmlspecialchars($profile['primary_address']) ?>, téléphone
                <a href="tel:<?= htmlspecialchars($profile['primary_phone']) ?>"><?= htmlspecialchars($profile['primary_phone']) ?></a>,
                email <a href="mailto:<?= htmlspecialchars($profile['primary_email']) ?>"><?= htmlspecialchars($profile['primary_email']) ?></a>.
            </p>
        </div>

        <div class="section">
            <h3>1. Données concernées</h3>
            <p>
                Selon votre profil, la plateforme peut traiter les informations suivantes : nom, adresse email,
                numéro de téléphone, société, ville de livraison, identifiants de connexion, traces de session,
                historiques opérationnels, données de commandes et informations liées au personnel.
            </p>
        </div>

        <div class="section">
            <h3>2. Finalités du traitement</h3>
            <ul>
                <li>Permettre la connexion sécurisée aux espaces client, employé et administration.</li>
                <li>Gérer les commandes, la livraison, les documents, le stock et les opérations financières.</li>
                <li>Assurer le suivi des activités internes, la sécurité applicative et la prévention de la fraude.</li>
                <li>Améliorer la qualité de service et la continuité opérationnelle.</li>
            </ul>
        </div>

        <div class="section">
            <h3>3. Base d’utilisation</h3>
            <p>
                Les données sont utilisées uniquement dans le cadre du fonctionnement de l’entreprise, de la gestion
                de ses services et de la sécurisation de la plateforme. Les informations demandées sont limitées à ce
                qui est utile pour exécuter ces missions.
            </p>
        </div>

        <div class="section">
            <h3>4. Sécurité</h3>
            <p>
                Le projet intègre plusieurs mécanismes de protection, notamment la gestion de session, le hachage des
                mots de passe, la limitation des tentatives de connexion et des contrôles techniques côté serveur.
                Aucun système n’étant infaillible, des mesures complémentaires peuvent être ajoutées à tout moment.
            </p>
        </div>

        <div class="section">
            <h3>5. Conservation</h3>
            <p>
                Les données sont conservées pendant la durée nécessaire à l’exploitation du service, au suivi
                administratif, à la relation commerciale, à la sécurité et au respect des obligations de l’entreprise.
            </p>
        </div>

        <div class="section">
            <h3>6. Partage des informations</h3>
            <p>
                Les données ne doivent être accessibles qu’aux personnes autorisées ou aux prestataires techniques
                intervenant pour l’hébergement, la maintenance ou l’exploitation du service, dans la limite du besoin.
            </p>
        </div>

        <div class="section">
            <h3>7. Vos droits</h3>
            <p>
                Vous pouvez demander l’accès, la rectification ou la mise à jour des informations vous concernant.
                Toute demande doit être adressée au point de contact actuellement renseigné dans le système :
                <a href="mailto:<?= htmlspecialchars($profile['primary_email']) ?>"><?= htmlspecialchars($profile['primary_email']) ?></a>
                ou <a href="tel:<?= htmlspecialchars($profile['primary_phone']) ?>"><?= htmlspecialchars($profile['primary_phone']) ?></a>.
            </p>
            <div class="note">
                Adresse de référence enregistrée : <?= htmlspecialchars($profile['primary_address']) ?>.
            </div>
        </div>
    </section>

    <div class="footer">© <?= $year ?> <?= htmlspecialchars($profile['brand']) ?>. Tous droits réservés.</div>
    <?= render_legal_footer(['theme' => 'light']) ?>
</div>
</body>
</html>
