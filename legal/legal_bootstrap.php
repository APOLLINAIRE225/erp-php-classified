<?php

if (!function_exists('legal_company_profile')) {
    function legal_company_profile(): array
    {
        return [
            'brand' => 'ESPERANCE H2O',
            'entities' => [
                [
                    'name' => 'ESPERANCEH20 CT',
                    'address' => 'SONGON',
                    'phone' => '+2250574015048',
                    'email' => 'esperanceh20@gmail.com',
                    'created_at' => '2026-02-02 18:07:05',
                ],
                [
                    'name' => 'ESPERANCEH20 DT',
                    'address' => 'songon',
                    'phone' => '+2250574015048',
                    'email' => 'esperanceh20@gmail.com',
                    'created_at' => '2026-02-13 22:33:18',
                ],
            ],
            'primary_address' => 'SONGON',
            'primary_phone' => '+2250574015048',
            'primary_email' => 'esperanceh20@gmail.com',
            'timezone_label' => 'Afrique/Abidjan',
            'legal_form' => 'Non renseignée dans le système',
            'registration_id' => 'Non renseigné dans le système',
            'publication_director' => 'Non renseigné dans le système',
            'host' => 'Infrastructure locale du projet',
        ];
    }
}

if (!function_exists('render_legal_footer')) {
    function render_legal_footer(array $options = []): string
    {
        $theme = $options['theme'] ?? 'light';
        $compact = !empty($options['compact']);
        $profile = legal_company_profile();
        $year = date('Y');

        $isDark = $theme === 'dark';
        $bg = $isDark ? 'rgba(17,28,49,.78)' : 'rgba(255,255,255,.78)';
        $border = $isDark ? 'rgba(255,255,255,.10)' : 'rgba(26,31,46,.08)';
        $text = $isDark ? '#edf3ff' : '#1a1f2e';
        $muted = $isDark ? '#9fb0d0' : '#677287';
        $accent = $isDark ? '#ffd166' : '#e8860a';
        $shadow = $isDark ? '0 18px 50px rgba(0,0,0,.28)' : '0 18px 42px rgba(15,23,42,.08)';
        $wrapStyle = $compact ? 'margin-top:16px;' : 'margin-top:24px;';

        return '
<style>
.legalbar{'.$wrapStyle.'position:relative;z-index:2}
.legalbar-box{
    max-width:1080px;margin:0 auto;padding:16px 18px;border-radius:18px;
    background:'.$bg.';border:1px solid '.$border.';box-shadow:'.$shadow.';
    backdrop-filter:blur(12px)
}
.legalbar-top{
    display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap
}
.legalbar-title{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:'.$muted.';margin-bottom:6px}
.legalbar-copy{font-size:13px;line-height:1.7;color:'.$muted.';max-width:760px}
.legalbar-copy strong{color:'.$text.'}
.legalbar-links{display:flex;gap:8px;flex-wrap:wrap}
.legalbar-link{
    display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;
    text-decoration:none;color:'.$text.';border:1px solid '.$border.';
    background:rgba(255,255,255,'.($isDark ? '0.03' : '0.55').');font-size:12px;font-weight:800
}
.legalbar-meta{
    margin-top:12px;padding-top:12px;border-top:1px solid '.$border.';
    display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:'.$muted.'
}
.legalbar-meta a{color:'.$accent.';text-decoration:none;font-weight:800}
@media (max-width:640px){
    .legalbar-box{padding:14px}
    .legalbar-links{width:100%}
    .legalbar-link{flex:1;justify-content:center}
}
</style>
<div class="legalbar">
  <div class="legalbar-box">
    <div class="legalbar-top">
      <div>
        <div class="legalbar-title">Informations légales</div>
        <div class="legalbar-copy">
          <strong>'.$profile['brand'].'</strong> opère actuellement avec les entités
          <strong>'.$profile['entities'][0]['name'].'</strong> et <strong>'.$profile['entities'][1]['name'].'</strong>,
          contact principal <a href="mailto:'.$profile['primary_email'].'" style="color:'.$accent.';text-decoration:none;font-weight:800">'.$profile['primary_email'].'</a>
          et <a href="tel:'.$profile['primary_phone'].'" style="color:'.$accent.';text-decoration:none;font-weight:800">'.$profile['primary_phone'].'</a>.
        </div>
      </div>
      <div class="legalbar-links">
        <a class="legalbar-link" href="'.htmlspecialchars(project_url('legal/privacy.php')).'"><i class="fas fa-user-shield"></i> Confidentialité</a>
        <a class="legalbar-link" href="'.htmlspecialchars(project_url('legal/about.php')).'"><i class="fas fa-building"></i> À propos</a>
        <a class="legalbar-link" href="'.htmlspecialchars(project_url('legal/copyright.php')).'"><i class="fas fa-copyright"></i> Droits d\'auteur</a>
      </div>
    </div>
    <div class="legalbar-meta">
      <span>Adresse : '.$profile['primary_address'].'</span>
      <span>Fuseau métier : '.$profile['timezone_label'].'</span>
      <span>© '.$year.' '.$profile['brand'].'</span>
    </div>
  </div>
</div>';
    }
}
