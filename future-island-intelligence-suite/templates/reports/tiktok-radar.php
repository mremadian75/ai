<?php
// v0.9.31.8-p4.1 — TikTok short-form radar. Rebuilt to match the uploaded
// tiktok_tendencias_2026.html design system. Data-driven from the $report
// schema: insights drive the eje/pillar strip, evidence drives the trend
// cards, opportunities and next_actions drive the closing sections.
// Self-contained scoped styles; no external JS or icon-font dependency.
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/_partials.php';

$summary       = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$evidence      = is_array($report['evidence'] ?? null) ? $report['evidence'] : [];
$insights      = is_array($report['insights'] ?? null) ? $report['insights'] : [];
$opportunities = is_array($report['opportunities'] ?? null) ? $report['opportunities'] : [];
$next_actions  = is_array($report['next_actions'] ?? null) ? $report['next_actions'] : [];

$evidence_count = (int) ($summary['evidence_count'] ?? count($evidence));
$usable_count   = (int) ($summary['usable_count'] ?? $evidence_count);
$conf           = ves_report_confidence_meta((string) ($summary['confidence'] ?? 'limited'));
$is_sample      = !empty($report['sample_mode']);

$fmt = static function ($n) {
    if (!is_numeric($n)) { return "0"; }
    $n = (float) $n;
    if (floor($n) == $n) {
        $n = (int) $n;
        return function_exists("number_format_i18n") ? number_format_i18n($n) : number_format($n);
    }
    return function_exists("number_format_i18n") ? number_format_i18n($n, 1) : number_format($n, 1);
};
// Accent rotation for trend card icon tiles.
$accents = ['#E24B4A', '#7F77DD', '#378ADD', '#1D9E75', '#BA7517', '#D4537E'];
?>
<section class="ves-report ves-report-tiktok" aria-labelledby="ves-report-tiktok-title">
<style>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap');
.ves-report.ves-report-tiktok{padding:0;background:transparent;border:0;box-shadow:none;border-radius:var(--border-radius-lg,16px);overflow:hidden;}
.ves-report-tiktok{
  --tk:#E24B4A; --tk2:#A32D2D; --g:#1D9E75; --b:#378ADD; --pp:#7F77DD; --a:#BA7517; --pk:#D4537E; --nt:#888780;
  --ink:var(--color-text-primary); --sub:var(--color-text-secondary);
  --bg:var(--color-background-primary); --bg2:var(--color-background-secondary);
  --bd:var(--color-border-tertiary); --bd2:var(--color-border-secondary);
  --ff:'Space Grotesk',sans-serif; --fm:'Space Mono',monospace;
  font-family:var(--ff);background:var(--bg);border:0.5px solid var(--bd);border-radius:var(--border-radius-lg,16px);
}
.ves-report-tiktok *{box-sizing:border-box;margin:0;padding:0;}
.ves-report-tiktok .topnav{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:.875rem 1.25rem;border-bottom:0.5px solid var(--bd);}
.ves-report-tiktok .tk-logo{display:flex;align-items:center;gap:8px;}
.ves-report-tiktok .tk-icon{width:30px;height:30px;border-radius:7px;background:var(--tk);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px;flex-shrink:0;}
.ves-report-tiktok .tk-title{font-size:14px;font-weight:700;color:var(--ink);letter-spacing:-.3px;}
.ves-report-tiktok .tk-sub{font-family:var(--fm);font-size:9px;color:var(--sub);}
.ves-report-tiktok .report-badge{font-family:var(--fm);font-size:9px;padding:4px 10px;border-radius:12px;background:rgba(226,75,74,.1);color:var(--tk);border:0.5px solid rgba(226,75,74,.25);}
.ves-report-tiktok .badge-sample{background:rgba(186,117,23,.12);color:var(--a);border-color:rgba(186,117,23,.3);}
.ves-report-tiktok .topmeta{font-family:var(--fm);font-size:9px;color:var(--sub);}
.ves-report-tiktok .pillars{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:0.5px solid var(--bd);}
.ves-report-tiktok .pillar{padding:1.25rem;border-right:0.5px solid var(--bd);}
.ves-report-tiktok .pillar:last-child{border-right:none;}
.ves-report-tiktok .pl-num{font-family:var(--fm);font-size:10px;color:var(--sub);margin-bottom:6px;}
.ves-report-tiktok .pl-name{font-size:12px;font-weight:700;color:var(--ink);line-height:1.4;letter-spacing:-.2px;}
.ves-report-tiktok .section{padding:1.25rem;border-bottom:0.5px solid var(--bd);}
.ves-report-tiktok .section:last-child{border-bottom:none;}
.ves-report-tiktok .sec-head{display:flex;align-items:center;gap:8px;margin-bottom:1rem;flex-wrap:wrap;}
.ves-report-tiktok .sec-n{font-family:var(--fm);font-size:10px;color:var(--sub);}
.ves-report-tiktok .sec-t{font-size:13px;font-weight:700;color:var(--ink);letter-spacing:-.3px;}
.ves-report-tiktok .sec-meta{font-family:var(--fm);font-size:9px;color:var(--sub);margin-left:auto;}
.ves-report-tiktok .trends-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.ves-report-tiktok .tc{display:flex;flex-direction:column;text-decoration:none;border:0.5px solid var(--bd);border-radius:var(--border-radius-lg,12px);padding:1rem;transition:border-color .15s;}
.ves-report-tiktok a.tc:hover{border-color:var(--tk);}
.ves-report-tiktok .tc-top{display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;}
.ves-report-tiktok .tc-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;font-size:13px;color:#fff;}
.ves-report-tiktok .tc-meta{flex:1;min-width:0;}
.ves-report-tiktok .tc-hash{font-family:var(--fm);font-size:11px;font-weight:700;color:var(--ink);line-height:1.3;}
.ves-report-tiktok .tc-cat{font-family:var(--fm);font-size:9px;color:var(--sub);}
.ves-report-tiktok .tc-heat{font-family:var(--fm);font-size:10px;padding:2px 7px;border-radius:10px;white-space:nowrap;}
.ves-report-tiktok .is-hot{background:rgba(226,75,74,.1);color:var(--tk);}
.ves-report-tiktok .is-rise{background:rgba(29,158,117,.1);color:var(--g);}
.ves-report-tiktok .is-watch{background:rgba(186,117,23,.1);color:var(--a);}
.ves-report-tiktok .is-weak{background:rgba(136,135,128,.14);color:var(--nt);}
.ves-report-tiktok .tc-desc{font-size:11px;color:var(--sub);line-height:1.5;flex:1;margin-bottom:8px;}
.ves-report-tiktok .tc-nums{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.ves-report-tiktok .tc-pub{font-family:var(--fm);font-size:10px;color:var(--ink);}
.ves-report-tiktok .tc-trend{font-family:var(--fm);font-size:9px;color:var(--sub);}
.ves-report-tiktok .cards-list{display:grid;gap:8px;}
.ves-report-tiktok .op-card{background:var(--bg2);border-radius:var(--border-radius-md,8px);padding:.75rem .875rem;border-left:2px solid var(--tk);}
.ves-report-tiktok .op-card .op-idea{font-size:11px;color:var(--ink);line-height:1.5;}
.ves-report-tiktok .hooks-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;}
.ves-report-tiktok .hook{display:flex;gap:8px;background:var(--bg2);border-radius:var(--border-radius-md,8px);padding:.75rem;}
.ves-report-tiktok .hook-n{font-family:var(--fm);font-size:10px;font-weight:700;color:var(--tk);flex-shrink:0;}
.ves-report-tiktok .hook-txt{font-size:11px;color:var(--ink);line-height:1.45;}
.ves-report-tiktok .empty{font-size:11px;color:var(--sub);font-style:italic;}
.ves-report-tiktok .bottom{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:1rem 1.25rem;border-top:0.5px solid var(--bd);}
.ves-report-tiktok .bottom-note{font-family:var(--fm);font-size:10px;color:var(--sub);}
@media (max-width: 900px){
  .ves-report-tiktok .pillars{grid-template-columns:1fr;}
  .ves-report-tiktok .pillar{border-right:none;border-bottom:0.5px solid var(--bd);}
  .ves-report-tiktok .pillar:last-child{border-bottom:none;}
  .ves-report-tiktok .trends-grid{grid-template-columns:repeat(2,1fr);}
}
@media (max-width: 560px){
  .ves-report-tiktok .trends-grid{grid-template-columns:1fr;}
  .ves-report-tiktok .hooks-grid{grid-template-columns:1fr;}
}
</style>

  <div class="topnav">
    <div class="tk-logo">
      <div class="tk-icon" aria-hidden="true">TT</div>
      <div>
        <h2 id="ves-report-tiktok-title" class="tk-title"><?php echo esc_html($report['title']); ?></h2>
        <div class="tk-sub"><?php echo esc_html($report['subtitle']); ?></div>
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <span class="report-badge <?php echo $is_sample ? 'badge-sample' : ''; ?>"><?php echo $is_sample ? 'Modo muestra' : 'Radar short-form'; ?></span>
      <span class="topmeta"><?php echo esc_html($fmt($evidence_count)); ?> señales · <?php echo esc_html($conf['label']); ?></span>
    </div>
  </div>

  <?php if ($insights): ?>
  <div class="pillars">
    <?php foreach (array_slice($insights, 0, 3) as $i => $ins): ?>
      <div class="pillar">
        <div class="pl-num">EJE <?php echo str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?></div>
        <div class="pl-name"><?php echo esc_html($ins); ?></div>
      </div>
    <?php endforeach; ?>
    <?php for ($pad = count($insights); $pad < 3; $pad++): ?>
      <div class="pillar"><div class="pl-num">EJE <?php echo str_pad((string) ($pad + 1), 2, '0', STR_PAD_LEFT); ?></div><div class="pl-name"><span class="empty">Sin lectura para este eje todavía.</span></div></div>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

  <div class="section">
    <div class="sec-head">
      <span class="sec-n">01</span>
      <span class="sec-t">Señales activas</span>
      <span class="sec-meta"><?php echo esc_html($fmt($usable_count)); ?> usables de <?php echo esc_html($fmt($evidence_count)); ?></span>
    </div>
    <div class="trends-grid">
      <?php
      $cards = array_slice($evidence, 0, 6);
      if ($cards):
        foreach ($cards as $idx => $item):
          $im   = ves_report_confidence_meta((string) ($item['confidence'] ?? 'observed'));
          $url  = esc_url((string) ($item['url'] ?? ''));
          $tag  = $url !== '' ? 'a' : 'div';
          $acc  = $accents[$idx % count($accents)];
          $mlbl = trim((string) ($item['metric'] ?? ''));
          $mval = $item['metric_value'] ?? null;
          $title = (string) ($item['title'] ?? 'Señal');
          $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($title, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($title, 0, 1));
      ?>
        <<?php echo $tag; ?> class="tc"<?php if ($url !== ''): ?> href="<?php echo $url; ?>" target="_blank" rel="noopener noreferrer"<?php endif; ?>>
          <div class="tc-top">
            <div class="tc-icon" style="background:<?php echo esc_attr($acc); ?>" aria-hidden="true"><?php echo esc_html($initial); ?></div>
            <div class="tc-meta">
              <div class="tc-hash"><?php echo esc_html($title); ?></div>
              <div class="tc-cat"><?php echo esc_html($im['label']); ?></div>
            </div>
            <span class="tc-heat <?php echo esc_attr($im['class']); ?>"><?php echo (int) $im['pct']; ?>°</span>
          </div>
          <?php if (!empty($item['text'])): ?>
            <div class="tc-desc"><?php echo esc_html(ves_report_excerpt($item['text'], 200)); ?></div>
          <?php endif; ?>
          <div class="tc-nums">
            <?php if ($mlbl !== '' || $mval !== null): ?>
              <span class="tc-pub"><?php
                echo esc_html($mlbl !== '' ? $mlbl : 'Métrica');
                if ($mval !== null) { echo ': ' . esc_html($fmt($mval)); }
              ?></span>
            <?php endif; ?>
            <span class="tc-trend">Confianza <?php echo esc_html($im['label']); ?></span>
          </div>
        </<?php echo $tag; ?>>
      <?php endforeach; else: ?>
        <p class="empty">No hay señales short-form en este informe.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="section">
    <div class="sec-head">
      <span class="sec-n">02</span>
      <span class="sec-t">Oportunidades para marcas</span>
    </div>
    <div class="cards-list">
      <?php if ($opportunities): foreach (array_slice($opportunities, 0, 5) as $op): ?>
        <div class="op-card"><div class="op-idea"><?php echo esc_html($op); ?></div></div>
      <?php endforeach; else: ?>
        <p class="empty">No se concluye ninguna oportunidad sin evidencia más fuerte.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="section">
    <div class="sec-head">
      <span class="sec-n">03</span>
      <span class="sec-t">Hooks y próximos tests</span>
    </div>
    <div class="hooks-grid">
      <?php if ($next_actions): $n = 0; foreach (array_slice($next_actions, 0, 6) as $act): $n++; ?>
        <div class="hook"><span class="hook-n"><?php echo str_pad((string) $n, 2, '0', STR_PAD_LEFT); ?></span><span class="hook-txt"><?php echo esc_html($act); ?></span></div>
      <?php endforeach; else: ?>
        <p class="empty">Sin recomendación de hook sin evidencia más sólida.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="bottom">
    <span class="bottom-note">Radar generado sobre <?php echo esc_html($fmt($evidence_count)); ?> señales · confianza <?php echo esc_html($conf['label']); ?></span>
  </div>
</section>
