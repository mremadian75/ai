<?php
// v0.9.31.8-p4.1 — PULSO signal dashboard. Rebuilt to match the uploaded
// pulso_trend_platform.html design system. Fully data-driven: every value is
// derived from the $report schema (summary, evidence, insights, opportunities,
// next_actions). Self-contained scoped styles; no external JS or icon-font
// dependency. $report is passed by VES_Report_Renderer.
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
$platform_label = trim((string) ($report['platform'] ?? ''));
$platform_label = $platform_label !== '' ? strtoupper(str_replace(['_', '-'], ' ', $platform_label)) : 'SEÑALES';

// Confidence distribution across evidence items — the real "domain intensity".
$buckets = [];
foreach ($evidence as $item) {
    $ck = strtolower((string) ($item['confidence'] ?? 'observed'));
    $buckets[$ck] = ($buckets[$ck] ?? 0) + 1;
}
arsort($buckets);
$bucket_total = max(1, array_sum($buckets));

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
<section class="ves-report ves-report-pulso" aria-labelledby="ves-report-pulso-title">
<style>
@import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap');
.ves-report.ves-report-pulso{padding:0;background:transparent;border:0;box-shadow:none;border-radius:var(--border-radius-lg,16px);overflow:hidden;}
.ves-report-pulso{
  --p:#E24B4A; --pm:#A32D2D; --g:#1D9E75; --b:#378ADD; --pp:#7F77DD; --a:#BA7517; --pk:#D4537E; --nt:#888780;
  --ink:var(--color-text-primary); --sub:var(--color-text-secondary);
  --bg:var(--color-background-primary); --bg2:var(--color-background-secondary);
  --bd:var(--color-border-tertiary); --bd2:var(--color-border-secondary);
  --ff:'Syne',sans-serif; --fm:'DM Mono',monospace;
  font-family:var(--ff);background:var(--bg);border:0.5px solid var(--bd);border-radius:var(--border-radius-lg,16px);
}
.ves-report-pulso *{box-sizing:border-box;margin:0;padding:0;}
.ves-report-pulso .topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:1rem 1.25rem .75rem;border-bottom:0.5px solid var(--bd);}
.ves-report-pulso .logo{font-size:22px;font-weight:800;letter-spacing:-1px;color:var(--ink);display:flex;align-items:center;gap:8px;}
.ves-report-pulso .logo-dot{width:10px;height:10px;border-radius:50%;background:var(--p);display:inline-block;animation:vesPulsoBeat 1.4s ease-in-out infinite;}
@keyframes vesPulsoBeat{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(.7);}}
.ves-report-pulso .badge-live{font-family:var(--fm);font-size:10px;background:var(--p);color:#fff;padding:3px 8px;border-radius:4px;letter-spacing:.5px;}
.ves-report-pulso .badge-sample{background:var(--a);}
.ves-report-pulso .topmeta{font-family:var(--fm);font-size:11px;color:var(--sub);}
.ves-report-pulso .grid-main{display:grid;grid-template-columns:210px 1fr 200px;gap:0;}
.ves-report-pulso .sidebar{border-right:0.5px solid var(--bd);padding:1rem;display:flex;flex-direction:column;gap:4px;}
.ves-report-pulso .src-label{font-family:var(--fm);font-size:10px;color:var(--sub);letter-spacing:.5px;margin:8px 0 4px;text-transform:uppercase;}
.ves-report-pulso .src-item{display:flex;align-items:center;justify-content:space-between;padding:6px 8px;border-radius:var(--border-radius-md,8px);}
.ves-report-pulso .src-item:hover{background:var(--bg2);}
.ves-report-pulso .src-name{font-size:12px;color:var(--ink);display:flex;align-items:center;gap:6px;}
.ves-report-pulso .src-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;}
.ves-report-pulso .src-val{font-family:var(--fm);font-size:10px;color:var(--sub);}
.ves-report-pulso .center-panel{padding:1rem 1.25rem;min-width:0;}
.ves-report-pulso .panel-header{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:1rem;}
.ves-report-pulso .panel-title{font-size:14px;font-weight:700;color:var(--ink);letter-spacing:-.3px;}
.ves-report-pulso .panel-sub{font-family:var(--fm);font-size:10px;color:var(--sub);}
.ves-report-pulso .metrics-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:1.25rem;}
.ves-report-pulso .met{background:var(--bg2);border-radius:var(--border-radius-md,8px);padding:.75rem;}
.ves-report-pulso .met-label{font-family:var(--fm);font-size:10px;color:var(--sub);margin-bottom:4px;}
.ves-report-pulso .met-val{font-size:22px;font-weight:800;color:var(--ink);line-height:1;}
.ves-report-pulso .met-foot{font-family:var(--fm);font-size:10px;margin-top:5px;color:var(--sub);}
.ves-report-pulso .spread{margin-bottom:1.25rem;}
.ves-report-pulso .spread-title{font-family:var(--fm);font-size:10px;color:var(--sub);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;}
.ves-report-pulso .spread-bar{display:flex;height:14px;border-radius:7px;overflow:hidden;background:var(--bg2);}
.ves-report-pulso .spread-seg{height:100%;}
.ves-report-pulso .spread-legend{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;}
.ves-report-pulso .legend-item{display:flex;align-items:center;gap:5px;font-family:var(--fm);font-size:10px;color:var(--sub);}
.ves-report-pulso .legend-dot{width:7px;height:7px;border-radius:2px;}
.ves-report-pulso .trend-cards{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;}
.ves-report-pulso .tc{display:block;text-decoration:none;background:var(--bg);border:0.5px solid var(--bd);border-radius:var(--border-radius-lg,12px);padding:.875rem;transition:border-color .15s;}
.ves-report-pulso a.tc:hover{border-color:var(--p);}
.ves-report-pulso .tc-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
.ves-report-pulso .tc-cat{font-family:var(--fm);font-size:9px;color:var(--sub);text-transform:uppercase;letter-spacing:.5px;}
.ves-report-pulso .tc-score{font-family:var(--fm);font-size:10px;font-weight:500;padding:2px 8px;border-radius:10px;white-space:nowrap;}
.ves-report-pulso .is-hot{background:rgba(226,75,74,.12);color:var(--p);}
.ves-report-pulso .is-rise{background:rgba(29,158,117,.12);color:var(--g);}
.ves-report-pulso .is-watch{background:rgba(186,117,23,.12);color:var(--a);}
.ves-report-pulso .is-weak{background:rgba(136,135,128,.14);color:var(--nt);}
.ves-report-pulso .tc-title{font-size:12px;font-weight:700;color:var(--ink);margin-bottom:4px;line-height:1.3;}
.ves-report-pulso .tc-desc{font-size:11px;color:var(--sub);line-height:1.45;}
.ves-report-pulso .tc-foot{font-family:var(--fm);font-size:9px;color:var(--sub);margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;}
.ves-report-pulso .tc-metric{padding:2px 6px;border-radius:4px;background:var(--bg2);}
.ves-report-pulso .empty{font-size:11px;color:var(--sub);font-style:italic;padding:1rem 0;}
.ves-report-pulso .right-panel{border-left:0.5px solid var(--bd);padding:1rem;display:flex;flex-direction:column;gap:14px;}
.ves-report-pulso .rp-title{font-family:var(--fm);font-size:10px;color:var(--sub);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;}
.ves-report-pulso .signal-row{display:flex;gap:7px;margin-bottom:9px;font-size:11px;color:var(--ink);line-height:1.4;}
.ves-report-pulso .sig-n{font-family:var(--fm);font-size:10px;color:var(--p);flex-shrink:0;font-weight:500;}
.ves-report-pulso .alert-item{padding:8px;border-radius:var(--border-radius-md,8px);background:var(--bg2);margin-bottom:6px;border-left:2px solid var(--g);}
.ves-report-pulso .alert-txt{font-size:11px;color:var(--ink);line-height:1.4;}
.ves-report-pulso .bottom-bar{display:flex;align-items:center;gap:8px;padding:.75rem 1.25rem;border-top:0.5px solid var(--bd);flex-wrap:wrap;}
.ves-report-pulso .bb-label{font-family:var(--fm);font-size:10px;color:var(--sub);flex-shrink:0;}
.ves-report-pulso .action-chip{display:flex;align-items:center;gap:6px;padding:5px 10px;border-radius:20px;border:0.5px solid var(--bd);font-size:11px;color:var(--ink);}
.ves-report-pulso .chip-n{font-family:var(--fm);font-size:9px;width:15px;height:15px;border-radius:50%;background:var(--p);color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
@media (max-width: 900px){
  .ves-report-pulso .grid-main{grid-template-columns:1fr;}
  .ves-report-pulso .sidebar{border-right:0;border-bottom:0.5px solid var(--bd);}
  .ves-report-pulso .right-panel{border-left:0;border-top:0.5px solid var(--bd);}
}
@media (max-width: 560px){
  .ves-report-pulso .metrics-row{grid-template-columns:repeat(2,1fr);}
  .ves-report-pulso .trend-cards{grid-template-columns:1fr;}
}
</style>

  <div class="topbar">
    <span class="logo"><span class="logo-dot" aria-hidden="true"></span> PULSO
      <span class="badge-live <?php echo $is_sample ? 'badge-sample' : ''; ?>"><?php echo $is_sample ? 'MUESTRA' : 'LIVE'; ?></span>
    </span>
    <h2 id="ves-report-pulso-title" class="panel-title" style="flex:1 1 100%;order:3;"><?php echo esc_html($report['title']); ?></h2>
    <span class="topmeta"><?php echo esc_html($platform_label); ?> · <?php echo esc_html($fmt($evidence_count)); ?> señales</span>
  </div>

  <div class="grid-main">
    <div class="sidebar">
      <span class="src-label">Cobertura de evidencia</span>
      <?php if ($buckets): ?>
        <?php foreach ($buckets as $ck => $count): $bm = ves_report_confidence_meta($ck); ?>
          <div class="src-item">
            <span class="src-name"><span class="src-dot" style="background:<?php echo esc_attr($bm['color']); ?>"></span><?php echo esc_html($bm['label']); ?></span>
            <span class="src-val"><?php echo esc_html($fmt($count)); ?></span>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="empty">Sin evidencia clasificada.</p>
      <?php endif; ?>
      <span class="src-label">Lectura general</span>
      <div class="src-item">
        <span class="src-name"><span class="src-dot" style="background:<?php echo esc_attr($conf['color']); ?>"></span>Confianza</span>
        <span class="src-val"><?php echo esc_html($conf['label']); ?></span>
      </div>
      <div class="src-item">
        <span class="src-name"><span class="src-dot" style="background:var(--b)"></span>Usables</span>
        <span class="src-val"><?php echo esc_html($fmt($usable_count)); ?></span>
      </div>
    </div>

    <div class="center-panel">
      <div class="panel-header">
        <span class="panel-title">Señales activas en este informe</span>
        <span class="panel-sub"><?php echo esc_html($report['subtitle']); ?></span>
      </div>

      <div class="metrics-row">
        <div class="met">
          <div class="met-label">Evidencia total</div>
          <div class="met-val"><?php echo esc_html($fmt($evidence_count)); ?></div>
          <div class="met-foot"><?php echo esc_html($fmt($usable_count)); ?> usables</div>
        </div>
        <div class="met">
          <div class="met-label">Temperatura</div>
          <div class="met-val"><?php echo (int) $conf['pct']; ?>°</div>
          <div class="met-foot"><?php echo esc_html($conf['label']); ?></div>
        </div>
        <div class="met">
          <div class="met-label">Lecturas</div>
          <div class="met-val"><?php echo esc_html($fmt(count($insights))); ?></div>
          <div class="met-foot">interpretaciones</div>
        </div>
        <div class="met">
          <div class="met-label">Oportunidades</div>
          <div class="met-val"><?php echo esc_html($fmt(count($opportunities))); ?></div>
          <div class="met-foot">para marcas</div>
        </div>
      </div>

      <?php if ($buckets): ?>
      <div class="spread">
        <div class="spread-title">Reparto de evidencia por confianza</div>
        <div class="spread-bar" role="img" aria-label="Reparto de la evidencia según su nivel de confianza">
          <?php foreach ($buckets as $ck => $count): $bm = ves_report_confidence_meta($ck);
            $w = round(($count / $bucket_total) * 100, 2); ?>
            <span class="spread-seg" style="width:<?php echo esc_attr($w); ?>%;background:<?php echo esc_attr($bm['color']); ?>"></span>
          <?php endforeach; ?>
        </div>
        <div class="spread-legend">
          <?php foreach ($buckets as $ck => $count): $bm = ves_report_confidence_meta($ck); ?>
            <span class="legend-item"><span class="legend-dot" style="background:<?php echo esc_attr($bm['color']); ?>"></span><?php echo esc_html($bm['label']); ?> · <?php echo esc_html($fmt($count)); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="trend-cards">
        <?php
        $cards = array_slice($evidence, 0, 6);
        if ($cards):
          foreach ($cards as $item):
            $im   = ves_report_confidence_meta((string) ($item['confidence'] ?? 'observed'));
            $url  = esc_url((string) ($item['url'] ?? ''));
            $tag  = $url !== '' ? 'a' : 'div';
            $mlbl = trim((string) ($item['metric'] ?? ''));
            $mval = $item['metric_value'] ?? null;
        ?>
          <<?php echo $tag; ?> class="tc"<?php if ($url !== ''): ?> href="<?php echo $url; ?>" target="_blank" rel="noopener noreferrer"<?php endif; ?>>
            <div class="tc-top">
              <span class="tc-cat"><?php echo esc_html($im['label']); ?></span>
              <span class="tc-score <?php echo esc_attr($im['class']); ?>"><?php echo (int) $im['pct']; ?>°</span>
            </div>
            <div class="tc-title"><?php echo esc_html($item['title'] ?? 'Señal'); ?></div>
            <?php if (!empty($item['text'])): ?>
              <div class="tc-desc"><?php echo esc_html(ves_report_excerpt($item['text'], 160)); ?></div>
            <?php endif; ?>
            <?php if ($mlbl !== '' || $mval !== null): ?>
              <div class="tc-foot">
                <span class="tc-metric"><?php
                  echo esc_html($mlbl !== '' ? $mlbl : 'Métrica');
                  if ($mval !== null) { echo ': ' . esc_html($fmt($mval)); }
                ?></span>
              </div>
            <?php endif; ?>
          </<?php echo $tag; ?>>
        <?php endforeach; else: ?>
          <p class="empty">No hay señales individuales en este informe.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="right-panel">
      <div class="rp-section">
        <div class="rp-title">Lectura del analista</div>
        <?php if ($insights): $n = 0; foreach (array_slice($insights, 0, 5) as $ins): $n++; ?>
          <div class="signal-row"><span class="sig-n"><?php echo str_pad((string) $n, 2, '0', STR_PAD_LEFT); ?></span><span><?php echo esc_html($ins); ?></span></div>
        <?php endforeach; else: ?>
          <p class="empty">Sin interpretación todavía.</p>
        <?php endif; ?>
      </div>
      <div class="rp-section">
        <div class="rp-title">Oportunidades</div>
        <?php if ($opportunities): foreach (array_slice($opportunities, 0, 4) as $op): ?>
          <div class="alert-item"><div class="alert-txt"><?php echo esc_html($op); ?></div></div>
        <?php endforeach; else: ?>
          <p class="empty">Ninguna concluida aún.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="bottom-bar">
    <span class="bb-label">PRÓXIMAS ACCIONES</span>
    <?php if ($next_actions): $n = 0; foreach (array_slice($next_actions, 0, 5) as $act): $n++; ?>
      <span class="action-chip"><span class="chip-n"><?php echo (int) $n; ?></span><?php echo esc_html(ves_report_excerpt($act, 64)); ?></span>
    <?php endforeach; else: ?>
      <span class="action-chip">Ampliar la fuente o sumar evidencia más fuerte</span>
    <?php endif; ?>
  </div>
</section>
