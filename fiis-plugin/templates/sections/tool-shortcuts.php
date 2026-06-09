<?php if (!defined('ABSPATH')) { exit; }
/* Composable section: tool shortcut grid. No shell, no run forms. */
$tools = [
    ['social','Social Media','Señales sociales y posts públicos'],
    ['seo','SEO / SEM / AEO','Keywords, dominios y autoridad'],
    ['google','Google Intelligence','Search, news y trends'],
    ['trend','Trend Finder','Tendencias con evidencia'],
    ['brand-audit','Brand Audit','Auditoría estratégica de marca'],
    ['ads','Ads Intelligence','Inteligencia competitiva de ads'],
];
?>
<div class="ves-dash-section">
    <div class="ves-dash-section-head"><h3><?php echo esc_html__('Herramientas de inteligencia', 'ves'); ?></h3></div>
    <div class="ves-dash-tools">
        <?php foreach ($tools as $t) : ?>
            <a class="ves-tool-card" href="?fiis_page=<?php echo esc_attr($t[0]); ?>">
                <span class="ves-tool-ico"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/></svg></span>
                <span><span class="ves-tool-name"><?php echo esc_html($t[1]); ?></span><span class="ves-tool-desc"><?php echo esc_html($t[2]); ?></span></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
