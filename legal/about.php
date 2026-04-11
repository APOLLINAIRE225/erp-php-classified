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
<title>À propos de l'entreprise | ESPERANCE H2O</title>
<meta name="description" content="Présentation de l'entreprise et de la plateforme ESPERANCE H2O.">
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
    --green:#16a34a;
    --green-soft:#edfbf3;
    --shadow:0 16px 40px rgba(0,0,0,.08);
    --radius:22px;
    --f:'DM Sans',sans-serif;
    --fh:'Syne',sans-serif;
}
body{
    font-family:var(--f);
    color:var(--text);
    background:
        radial-gradient(circle at 20% 0%, rgba(22,163,74,.09), transparent 28%),
        radial-gradient(circle at 100% 100%, rgba(232,134,10,.11), transparent 26%),
        var(--bg);
    min-height:100vh;
    padding:24px 16px 40px;
}
.shell{max-width:1040px;margin:0 auto}
.topbar,.hero,.grid article,.band,.footer{
    background:rgba(255,255,255,.92);border:1px solid var(--border);box-shadow:var(--shadow)
}
.topbar{
    display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;
    margin-bottom:18px;padding:14px 16px;border-radius:18px
}
.brand{display:flex;align-items:center;gap:12px}
.mark{
    width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,#14532d,#16a34a);
    display:grid;place-items:center;color:#fff;font-size:20px
}
.brand h1{font:800 1rem var(--fh)}
.brand p{font-size:.8rem;color:var(--muted)}
.back{
    display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;text-decoration:none;
    color:var(--text);background:var(--surface);border:1px solid var(--border);font-weight:700
}
.hero{
    border-radius:24px;padding:30px 24px;margin-bottom:18px;position:relative;overflow:hidden
}
.hero::after{
    content:"";position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;
    background:radial-gradient(circle, rgba(22,163,74,.22), transparent 68%)
}
.eyebrow{
    display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;
    background:var(--green-soft);color:#0f7a36;font-size:.8rem;font-weight:700;margin-bottom:16px
}
.hero h2{font:800 clamp(2rem,5vw,3.8rem)/1 var(--fh);max-width:10ch;margin-bottom:14px}
.hero p{max-width:72ch;color:var(--text2);line-height:1.85}
.grid{
    display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-bottom:18px
}
.grid article{
    border-radius:20px;padding:22px 18px
}
.grid h3,.band h3{font:800 1rem var(--fh);margin-bottom:10px}
.grid p,.band p,.band li{color:var(--text2);line-height:1.8}
.grid i{font-size:1.25rem;color:var(--green);margin-bottom:12px}
.band{
    border-radius:22px;padding:24px
}
.band ul{padding-left:18px;margin-top:10px}
.notice{
    margin-top:16px;padding:14px 16px;border-radius:16px;background:var(--amber-soft);color:#8b5407
}
.footer{
    border-radius:18px;margin-top:18px;padding:14px 16px;text-align:center;color:var(--muted);font-size:.88rem
}
@media (max-width:860px){
    .grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="shell">
    <div class="topbar">
        <div class="brand">
            <div class="mark"><i class="fas fa-building"></i></div>
            <div>
                <h1>ESPERANCE H2O</h1>
                <p>Présentation de l'entreprise et du portail</p>
            </div>
        </div>
        <a class="back" href="<?= htmlspecialchars(project_url('auth/login_unified.php')) ?>">
            <i class="fas fa-arrow-left"></i> Retour au portail
        </a>
    </div>

    <section class="hero">
        <div class="eyebrow"><i class="fas fa-circle-info"></i> À propos</div>
        <h2>Une plateforme pensée pour l’exploitation</h2>
        <p>
            ESPERANCE H2O centralise plusieurs fonctions métier dans une seule interface : accès sécurisé, espace
            client, gestion des commandes, stock, documents, finances, ressources humaines et communication interne.
            Le projet est conçu pour fluidifier le pilotage quotidien de l’entreprise et améliorer la coordination
            entre équipes, responsables et clients.
        </p>
    </section>

    <section class="grid">
        <article>
            <i class="fas fa-layer-group"></i>
            <h3>Plateforme unifiée</h3>
            <p>
                Le projet rassemble plusieurs modules métier dans un environnement cohérent afin d’éviter la
                dispersion des outils et de simplifier le suivi opérationnel.
            </p>
        </article>
        <article>
            <i class="fas fa-users-gear"></i>
            <h3>Usage multi-profils</h3>
            <p>
                Le portail gère différents niveaux d’accès : clients, employés, responsables et administrateurs, avec
                des parcours adaptés à chaque besoin.
            </p>
        </article>
        <article>
            <i class="fas fa-shield"></i>
            <h3>Orientation sécurité</h3>
            <p>
                L’architecture met l’accent sur la protection des accès, le contrôle des sessions et la continuité des
                opérations critiques.
            </p>
        </article>
    </section>

    <section class="band">
        <h3>Identité déclarée dans le système</h3>
        <p>
            Marque affichée : <strong><?= htmlspecialchars($profile['brand']) ?></strong>. Deux entités sont actuellement
            configurées dans la base applicative : <strong><?= htmlspecialchars($profile['entities'][0]['name']) ?></strong>
            et <strong><?= htmlspecialchars($profile['entities'][1]['name']) ?></strong>. Le point de contact commun
            enregistré est situé à <strong><?= htmlspecialchars($profile['primary_address']) ?></strong>, joignable au
            <a href="tel:<?= htmlspecialchars($profile['primary_phone']) ?>"><?= htmlspecialchars($profile['primary_phone']) ?></a>
            et à l’adresse <a href="mailto:<?= htmlspecialchars($profile['primary_email']) ?>"><?= htmlspecialchars($profile['primary_email']) ?></a>.
        </p>

        <div class="notice">
            Forme juridique : <?= htmlspecialchars($profile['legal_form']) ?>. Numéro d’immatriculation :
            <?= htmlspecialchars($profile['registration_id']) ?>. Directeur de publication :
            <?= htmlspecialchars($profile['publication_director']) ?>.
        </div>
    </section>

    <section class="band" style="margin-top:18px">
        <h3>Ce que couvre actuellement le projet</h3>
        <ul>
            <li>Connexion unifiée pour les différents types d’utilisateurs.</li>
            <li>Gestion clients, commandes et villes de livraison.</li>
            <li>Gestion du stock, des documents et de la facturation.</li>
            <li>Suivi RH, présence, paie et portails employés.</li>
            <li>Outils de notification, communication et supervision.</li>
        </ul>

        <div class="notice">
            Les mentions ci-dessus correspondent exactement aux données actuellement stockées dans le système.
            Les champs juridiques non présents en base sont indiqués comme non renseignés.
        </div>
    </section>

    <div class="footer">© <?= $year ?> <?= htmlspecialchars($profile['brand']) ?>. Plateforme de gestion interne et relation client.</div>
    <?= render_legal_footer(['theme' => 'light']) ?>
</div>
</body>
</html>
