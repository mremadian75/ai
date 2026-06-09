<?php
// v0.9.31.8-p4.1 — Trend deep-dive report. Rebuilt to match the uploaded
// verano_silencioso_analisis.html design system. Data-driven from the $report
// schema: stats row from summary, anatomy from insights, observed evidence
// list from evidence, tensions from warnings/discard_reasons, brand openings
// from opportunities, validation plan from next_actions.
// Self-contained scoped styles; no external JS or icon-font dependency.
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/_partials.php';

$summary       = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$evidence      = is_array($report['evidence'] ?? null) ? $report['evidence'] : [];
$insights      = is_array($report['insights'] ?? null) ? $report['insights'] : [];
$opportunities = is_array($report['opportunities'] ?? null) ? $report['opportunities'] : [];
$next_actions  = is_array($report['next_actions'] ?? null) ? $report['next_actions'] : [];
$warnings      = is_array($report['warnings'] ?? null) ? $report['warnings'] : [];
$discard       = is_array($report['discard_reasons'] ?? null) ? $report['discard_reasons'] : [];

$evidence_count = (int) ($summary['evidence_count'] ?? count($evidence));
$usable_count   = (int) ($summary['usable_count'] ?? $evidence_count);
$conf           = ves_report_confidence_meta((string) ($summary['confidence'] ?? 'limited'));
$is_sample      = !empty($report['sample_mode']);
$platform_label = trim((string) ($report['platform'] ?? ''));
$platform_label = $platform_label !== '' ? str_replace(['_', '-'], ' ', $platform_label) : '—';
$tensions       = array_slice(array_merge($warnings, $discard), 0, 6);

$fmt = static function ($n) {
    if (!is_numeric($n)) { return "0"; }
    $n = (float) $n;
    if (floor($n) == $n) {
        $n = (int) $n;
        return function_exists("number_format_i18n") ? number_format_i18n($n) : number_format($n);
    }
    return function_exists("number_format_i18n") ? number_format_i18n($n, 1) : number_format($n, 1);
};
?>
<section class="ves-report ves-report-trend" aria-labelledby="ves-report-trend-title">
<style>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap');
.ves-report.ves-report-trend{padding:0;background:transparent;border:0;box-shadow:none;border-radius:var(--border-radius-lg,16px);overflow:hidden;}
.ves-report-trend{
  --r:#E24B4A; --r2:#A32D2D; --g:#1D9E75; --b:#378ADD; --pp:#7F77DD; --a:#BA7517; --nt:#888780;
  --ink:var(--color-text-primary); --sub:var(--color-text-secondary);
  --bg:var(--color-background-primary); --bg2:var(--color-background-secondary);
  --bd:var(--color-border-tertiary); --bd2:var(--color-border-secondary);
  --ff:'Space Grotesk',sans-serif; --fm:'Space Mono',monospace;
  font-family:var(--ff);background:var(--bg);border:0.5px solid var(--bd);border-radius:var(--border-radius-lg,16px);
}
.ves-report-trend *{box-sizing:border-box;margin:0;padding:0;}
.ves-report-trend .hero{padding:1.5rem 1.25rem 1.25rem;border-bottom:0.5px solid var(--bd);display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;}
.ves-report-trend .hero-kicker{font-family:var(--fm);font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--r);font-weight:700;margin-bottom:8px;}
.ves-report-trend .hero-title{font-size:26px;font-weight:800;color:var(--ink);line-height:1.1;letter-spacing:-1px;margin-bottom:.5rem;}
.ves-report-trend .hero-tagline{font-size:13px;color:var(--sub);max-width:520px;line-height:1.6;}
.ves-report-trend .hero-score{min-width:120px;border:0.5px solid var(--bd);border-radius:var(--border-radius-lg,14px);padding:14px;text-align:center;background:var(--bg2);flex-shrink:0;}
.ves-report-trend .hero-score strong{display:block;font-size:32px;font-weight:800;line-height:1;color:var(--ink);}
.ves-report-trend .hero-score span{font-family:var(--fm);font-size:9px;color:var(--sub);text-transform:uppercase;letter-spacing:.08em;}
.ves-report-trend .stats-row{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:0.5px solid var(--bd);}
.ves-report-trend .stat{padding:1rem 1.25rem;border-right:0.5px solid var(--bd);}
.ves-report-trend .stat:last-child{border-right:none;}
.ves-report-trend .stat-val{font-size:20px;font-weight:800;color:var(--ink);line-height:1;margin-bottom:4px;}
.ves-report-trend .stat-label{font-family:var(--fm);font-size:9px;color:var(--sub);text-transform:uppercase;letter-spacing:.5px;}
.ves-report-trend .section{padding:1.25rem;border-bottom:0.5px solid var(--bd);}
.ves-report-trend .section:last-child{border-bottom:none;}
.ves-report-trend .sec-head{display:flex;align-items:center;gap:8px;margin-bottom:1rem;flex-wrap:wrap;}
.ves-report-trend .sec-n{font-family:var(--fm);font-size:10px;color:var(--sub);}
.ves-report-trend .sec-t{font-size:13px;font-weight:700;color:var(--ink);letter-spacing:-.3px;}
.ves-report-trend .sec-meta{font-family:var(--fm);font-size:9px;color:var(--sub);margin-left:auto;}
.ves-report-trend .anatomy-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.ves-report-trend .anatomy-card{border:0.5px solid var(--bd);border-radius:var(--border-radius-lg,12px);padding:1rem;background:var(--bg2);}
.ves-report-trend .an-num{font-family:var(--fm);font-size:10px;color:var(--r);font-weight:700;margin-bottom:6px;}
.ves-report-trend .an-txt{font-size:11px;color:var(--ink);line-height:1.55;}
.ves-report-trend .ev-list{display:grid;gap:8px;}
.ves-report-trend .ev-item{display:flex;gap:10px;text-decoration:none;border:0.5px solid var(--bd);border-radius:var(--border-radius-md,10px);padding:.75rem .875rem;transition:border-color .15s;}
.ves-report-trend a.ev-item:hover{border-color:var(--r);}
.ves-report-trend .ev-rail{width:3px;border-radius:2px;flex-shrink:0;}
.ves-report-trend .ev-body{flex:1;min-width:0;}
.ves-report-trend .ev-top{display:flex;align-items:center;gap:8px;justify-content:space-between;margin-bottom:3px;}
.ves-report-trend .ev-title{font-size:12px;font-weight:700;color:var(--ink);line-height:1.3;}
.ves-report-trend .ev-tag{font-family:var(--fm);font-size:9px;padding:2px 7px;border-radius:9px;white-space:nowrap;}
.ves-report-trend .is-hot{background:rgba(226,75,74,.1);color:var(--r);}
.ves-report-trend .is-rise{background:rgba(29,158,117,.1);color:var(--g);}
.ves-report-trend .is-watch{background:rgba(186,117,23,.1);color:var(--a);}
.ves-report-trend .is-weak{background:rgba(136,135,128,.14);color:var(--nt);}
.ves-report-trend .ev-text{font-size:11px;color:var(--sub);line-height:1.5;}
.ves-report-trend .ev-metric{font-family:var(--fm);font-size:9px;color:var(--sub);margin-top:5px;}
.ves-report-trend .tension-box{background:rgba(186,117,23,.06);border:0.5px solid rgba(186,117,23,.25);border-radius:var(--border-radius-md,10px);padding:.875rem 1rem;}
.ves-report-trend .tension-row{display:flex;gap:8px;font-size:11px;color:var(--ink);line-height:1.5;margin-bottom:7px;}
.ves-report-trend .tension-row:last-child{margin-bottom:0;}
.ves-report-trend .tension-mark{color:var(--a);flex-shrink:0;font-weight:700;}
.ves-report-trend .open-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;}
.ves-report-trend .open-card{border:0.5px solid var(--bd);border-radius:var(--border-radius-lg,12px);padding:1rem;}
.ves-report-trend .open-n{font-family:var(--fm);font-size:10px;color:var(--g);font-weight:700;margin-bottom:6px;}
.ves-report-trend .open-txt{font-size:11px;color:var(--ink);line-height:1.55;}
.ves-report-trend .plan-list{display:grid;gap:7px;}
.ves-report-trend .plan-item{display:flex;gap:9px;background:var(--bg2);border-radius:var(--border-radius-md,8px);padding:.7rem .85rem;}
.ves-report-trend .plan-n{font-family:var(--fm);font-size:10px;font-weight:700;color:#fff;background:var(--r);width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.ves-report-trend .plan-txt{font-size:11px;color:var(--ink);line-height:1.45;}
.ves-report-trend .empty{font-size:11px;color:var(--sub);font-style:italic;}
.ves-report-trend .footer{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:1rem 1.25rem;border-top:0.5px solid var(--bd);}
.ves-report-trend .footer-note{font-family:var(--fm);font-size:10px;color:var(--sub);}
@media (max-width: 900px){
  .ves-report-trend .stats-row{grid-template-columns:repeat(2,1fr);}
  .ves-report-trend .anatomy-grid{grid-template-columns:1fr;}
  .ves-report-trend .open-grid{grid-template-columns:1fr;}
}
@media (max-width: 560px){
  .ves-report-trend .stats-row{grid-template-columns:1fr;}
  .ves-report-trend .hero{flex-direction:column;}
}
</style>

  <div class="hero">
    <div class="hero-left">
      <div class="hero-kicker">Trend deep dive · <?php echo esc_html($platform_label); ?></div>
      <h2 id="ves-report-trend-title" class="hero-title"><?php echo esc_html($report['title']); ?></h2>
      <p class="hero-tagline"><?php echo esc_html($report['subtitle']); ?></p>
    </div>
    <div class="hero-score">
      <strong><?php echo esc_html($fmt($usable_count)); ?></strong>
      <span>señales usables</span>
    </div>
  </div>

  <div class="stats-row">
    <div class="stat"><div class="stat-val"><?php echo esc_html($fmt($evidence_count)); ?></div><div class="stat-label">Evidencia total</div></div>
    <div class="stat"><div class="stat-val"><?php echo (int) $conf['pct']; ?>°</div><div class="stat-label">Confianza · <?php echo esc_html($conf['label']); ?></div></div>
    <div class="stat"><div class="stat-val"><?php echo esc_html($fmt(count($opportunities))); ?></div><div class="stat-label">Aperturas de marca</div></div>
    <div class="stat"><div class="stat-val"><?php echo esc_html($fmt(count($next_actions))); ?></div><div class="stat-label">Pasos de validación</div></div>
  </div>

  <div class="section">
    <div class="sec-head"><span class="sec-n">01</span><span class="sec-t">Anatomía de la tendencia</span></div>
    <?php if ($insights): ?>
    <div class="anatomy-grid">
      <?php foreach (array_slice($insights, 0, 6) as $i => $ins): ?>
        <div class="anatomy-card">
          <div class="an-num"><?php echo str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?></div>
          <div class="an-txt"><?php echo esc_html($ins); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="empty">Evidencia insuficiente para describir un patrón.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="sec-head">
      <span class="sec-n">02</span><span class="sec-t">Evidencia observada</span>
      <span class="sec-meta"><?php echo esc_html($fmt($evidence_count)); ?> registros</span>
    </div>
    <div class="ev-list">
      <?php
      $rows = array_slice($evidence, 0, 8);
      if ($rows):
        foreach ($rows as $item):
          $im   = ves_report_confidence_meta((string) ($item['confidence'] ?? 'observed'));
          $url  = esc_url((string) ($item['url'] ?? ''));
          $tag  = $url !== '' ? 'a' : 'div';
          $mlbl = trim((string) ($item['metric'] ?? ''));
          $mval = $item['metric_value'] ?? null;
      ?>
        <<?php echo $tag; ?> class="ev-item"<?php if ($url !== ''): ?> href="<?php echo $url; ?>" target="_blank" rel="noopener noreferrer"<?php endif; ?>>
          <span class="ev-rail" style="background:<?php echo esc_attr($im['color']); ?>" aria-hidden="true"></span>
          <div class="ev-body">
            <div class="ev-top">
              <span class="ev-title"><?php echo esc_html($item['title'] ?? 'Registro de evidencia'); ?></span>
              <span class="ev-tag <?php echo esc_attr($im['class']); ?>"><?php echo esc_html($im['label']); ?></span>
            </div>
            <?php if (!empty($item['text'])): ?>
              <div class="ev-text"><?php echo esc_html(ves_report_excerpt($item['text'], 240)); ?></div>
            <?php endif; ?>
            <?php if ($mlbl !== '' || $mval !== null): ?>
              <div class="ev-metric"><?php
                echo esc_html($mlbl !== '' ? $mlbl : 'Métrica');
                if ($mval !== null) { echo ': ' . esc_html($fmt($mval)); }
              ?></div>
            <?php endif; ?>
          </div>
        </<?php echo $tag; ?>>
      <?php endforeach; else: ?>
        <p class="empty">No hay registros de evidencia en este informe.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="section">
    <div class="sec-head"><span class="sec-n">03</span><span class="sec-t">Tensión y límites de la evidencia</span></div>
    <?php if ($tensions): ?>
    <div class="tension-box">
      <?php foreach ($tensions as $t): ?>
        <div class="tension-row"><span class="tension-mark" aria-hidden="true">!</span><span><?php echo esc_html($t); ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="empty">No se registraron tensiones ni descartes relevantes en esta lectura.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="sec-head"><span class="sec-n">04</span><span class="sec-t">Aperturas para marcas</span></div>
    <?php if ($opportunities): ?>
    <div class="open-grid">
      <?php foreach (array_slice($opportunities, 0, 6) as $i => $op): ?>
        <div class="open-card">
          <div class="open-n">Apertura <?php echo str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?></div>
          <div class="open-txt"><?php echo esc_html($op); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="empty">No se detecta ninguna apertura de marca todavía.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="sec-head"><span class="sec-n">05</span><span class="sec-t">Plan de validación</span></div>
    <div class="plan-list">
      <?php if ($next_actions): $n = 0; foreach (array_slice($next_actions, 0, 6) as $act): $n++; ?>
        <div class="plan-item"><span class="plan-n"><?php echo (int) $n; ?></span><span class="plan-txt"><?php echo esc_html($act); ?></span></div>
      <?php endforeach; else: ?>
        <p class="empty">Reúne más señales, compara palabras clave adyacentes y revisa la evidencia manualmente.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="footer">
    <span class="footer-note">Deep dive sobre <?php echo esc_html($fmt($evidence_count)); ?> registros · confianza <?php echo esc_html($conf['label']); ?><?php echo $is_sample ? ' · modo muestra' : ''; ?></span>
  </div>
</section>
