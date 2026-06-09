<?php if (!defined('ABSPATH')) { exit; }
/* Composable section: credits / usage summary. */
$credits = '—';
if (is_user_logged_in() && class_exists('VES_Usage_Billing')) {
    try {
        if (method_exists('VES_Usage_Billing','is_unlimited_user') && VES_Usage_Billing::is_unlimited_user(get_current_user_id())) { $credits = '∞'; }
        else { $s = VES_Usage_Billing::get_summary(get_current_user_id()); if (is_array($s) && isset($s['balance'])) { $credits = number_format((float)$s['balance']); } }
    } catch (Throwable $e) { $credits = '—'; }
}
?>
<div class="ves-dash-stats">
    <div class="ves-stat-card"><div class="ves-stat-label"><?php echo esc_html__('Créditos','ves'); ?></div><div class="ves-stat-value"><?php echo esc_html($credits); ?></div><div class="ves-stat-trend"><?php echo esc_html__('Disponibles','ves'); ?></div></div>
    <div class="ves-stat-card"><div class="ves-stat-label"><?php echo esc_html__('Herramientas','ves'); ?></div><div class="ves-stat-value">11</div><div class="ves-stat-trend"><?php echo esc_html__('Módulos activos','ves'); ?></div></div>
    <div class="ves-stat-card"><div class="ves-stat-label"><?php echo esc_html__('Estado','ves'); ?></div><div class="ves-stat-value" style="font-size:18px;color:var(--ok,#16a34a)">● <?php echo esc_html__('Activo','ves'); ?></div><div class="ves-stat-trend"><?php echo esc_html__('Señal en vivo','ves'); ?></div></div>
</div>
