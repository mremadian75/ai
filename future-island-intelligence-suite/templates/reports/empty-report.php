<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/_partials.php';
?>
<section class="ves-report ves-report-empty" aria-labelledby="ves-report-empty-title">
  <div class="ves-report-kicker">F.I/UI/EMPTY</div>
  <h2 id="ves-report-empty-title"><?php echo esc_html($report['title'] ?: __('No usable signals found', 'ves')); ?></h2>
  <p><?php echo esc_html(__('The run completed or failed without enough usable evidence. No strategic claim has been generated from this result.', 'ves')); ?></p>
  <?php echo ves_report_list($report['warnings'], __('No provider warnings were attached.', 'ves')); ?>
  <div class="ves-report-actions">
    <button type="button" data-ves-report-action="retry-broader"><?php echo esc_html__('Retry with broader filters', 'ves'); ?></button>
    <button type="button" data-ves-report-action="copy-diagnostics"><?php echo esc_html__('Copy safe diagnostics', 'ves'); ?></button>
  </div>
</section>
