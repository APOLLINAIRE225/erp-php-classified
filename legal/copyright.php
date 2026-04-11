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
<title>Droits d'auteur | ESPERANCE H2O</title>
<meta name="description" content="Informations relatives aux droits d'auteur et à l'usage des contenus ESPERANCE H2O.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#0f172a;
    --surface:#111c31;
    --panel:#16233d;
    --border:rgba(255,255,255,.08);
    --text:#edf3ff;
    --text2:#b5c3dd;
    --muted:#7f90b0;
    --gold:#ffd166;
    --gold-soft:rgba(255,209,102,.12);
    --blue:#60a5fa;
    --shadow:0 22px 60px rgba(0,0,0,.32);
    --radius:22px;
    --f:'DM Sans',sans-serif;
    --fh:'Syne',sans-serif;
}
body{
    font-family:var(--f);
    color:var(--text);
    background:
        radial-gradient(circle at top left, rgba(96,165,250,.12), transparent 24%),
        radial-gradient(circle at 90% 10%, rgba(255,209,102,.10), transparent 18%),
        linear-gradient(180deg,#0f172a 0%, #0b1220 100%);
    min-height:100vh;
    padding:24px 16px 40px;
}
.shell{max-width:980px;margin:0 auto}
.topbar,.hero,.content,.footer{
    background:rgba(17,28,49,.92);border:1px solid var(--border);box-shadow:var(--shadow)
}
.topbar{
    display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;
    margin-bottom:18px;padding:14px 16px;border-radius:18px
}
.brand{display:flex;align-items:center;gap:12px}
.mark{
    width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,#7c5c00,#f59e0b);
    display:grid;place-items:center;color:#fff;font-size:20px
}
.brand h1{font:800 1rem var(--fh)}
.brand p{font-size:.8rem;color:var(--muted)}
.back{
    display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;text-decoration:none;
    color:var(--text);background:var(--panel);border:1px solid var(--border);font-weight:700
}
.hero{
    border-radius:24px;padding:28px 24px;margin-bottom:18px
}
.eyebrow{
    display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;
    background:var(--gold-soft);color:var(--gold);font-size:.8rem;font-weight:700;margin-bottom:16px
}
.hero h2{font:800 clamp(2rem,5vw,3.4rem)/1 var(--fh);margin-bottom:14px;max-width:12ch}
.hero p{max-width:72ch;color:var(--text2);line-height:1.85}
.content{
    border-radius:24px;padding:26px 24px
}
.section + .section{margin-top:22px;padding-top:22px;border-top:1px solid var(--border)}
.section h3{font:800 1.02rem var(--fh);margin-bottom:10px}
.section p,.section li{color:var(--text2);line-height:1.8}
.section ul{padding-left:18px}
.callout{
    margin-top:16px;padding:14px 16px;border-radius:16px;background:rgba(96,165,250,.10);
    border:1px solid rgba(96,165,250,.16);color:#cce0ff
}
.footer{
    border-radius:18px;margin-top:18px;padding:14px 16px;text-align:center;color:var(--muted);font-size:.88rem
}
</style>
</head>
<body>
<div class="shell">
    <div class="topbar">
        <div class="brand">
            <div class="mark"><i class="fas fa-copyright"></i></div>
            <div>
                <h1>ESPERANCE H2O</h1>
                <p>Droits d'auteur et conditions d'usage</p>
            </div>
        </div>
        <a class="back" href="<?= htmlspecialchars(project_url('auth/login_unified.php')) ?>">
            <i class="fas fa-arrow-left"></i> Retour au portail
        </a>
    </div>

    <section class="hero">
        <div class="eyebrow"><i class="fas fa-scale-balanced"></i> Propriété intellectuelle</div>
        <h2>Les contenus restent protégés</h2>
        <p>
            Sauf mention contraire, les éléments présents sur cette plateforme, notamment les interfaces, textes,
            structures, scripts, documents, logos, icônes, images et bases de données, sont protégés par le droit
            d’auteur et les règles applicables à la propriété intellectuelle.
        </p>
    </section>

    <section class="content">
        <div class="section">
            <h3>1. Titularité</h3>
            <p>
                Les droits relatifs au portail <?= htmlspecialchars($profile['brand']) ?> et à ses contenus sont
                revendiqués par les entités configurées dans le système, à savoir
                <?= htmlspecialchars($profile['entities'][0]['name']) ?> et
                <?= htmlspecialchars($profile['entities'][1]['name']) ?>, sous réserve des droits éventuels de tiers
                sur certains contenus ou bibliothèques utilisés avec autorisation.
            </p>
        </div>

        <div class="section">
            <h3>2. Usages autorisés</h3>
            <p>
                L’utilisation du site est limitée à un usage interne, professionnel ou client, strictement conforme à
                la destination de la plateforme. Toute copie, adaptation, extraction, republication ou diffusion non
                autorisée d’un élément substantiel est interdite.
            </p>
        </div>

        <div class="section">
            <h3>3. Marques et signes distinctifs</h3>
            <p>
                Les dénominations, logos, habillages graphiques et signes distinctifs associés à ESPERANCE H2O ne
                peuvent pas être reproduits ou exploités sans accord préalable.
            </p>
        </div>

        <div class="section">
            <h3>4. Signalement</h3>
            <p>
                Toute utilisation non autorisée, reproduction abusive ou atteinte présumée aux droits de propriété
                intellectuelle doit être signalée à l’entreprise afin d’être examinée et traitée dans les meilleurs
                délais.
            </p>
            <div class="callout">
                Réclamations : <a href="mailto:<?= htmlspecialchars($profile['primary_email']) ?>"><?= htmlspecialchars($profile['primary_email']) ?></a>,
                <a href="tel:<?= htmlspecialchars($profile['primary_phone']) ?>"><?= htmlspecialchars($profile['primary_phone']) ?></a>,
                adresse déclarée <?= htmlspecialchars($profile['primary_address']) ?>.
            </div>
        </div>
    </section>

    <div class="footer">© <?= $year ?> <?= htmlspecialchars($profile['brand']) ?>. Tous droits réservés.</div>
    <?= render_legal_footer(['theme' => 'dark']) ?>
</div>
</body>
</html>
