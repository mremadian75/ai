<?php if (!defined('ABSPATH')) { exit; }
/* Composable section: recent activity placeholder (links into the app). */
?>
<div class="ves-dash-section">
    <div class="ves-dash-section-head"><h3><?php echo esc_html__('Actividad reciente','ves'); ?></h3></div>
    <div class="ves-empty-state" style="padding:22px">
        <?php echo esc_html__('Tus runs y memoria reciente aparecerán aquí.','ves'); ?>
        <a href="?fiis_page=memory"><?php echo esc_html__('Abrir Memory','ves'); ?></a>
    </div>
</div>
