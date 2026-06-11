<?php
$fici_product_label = class_exists('VES_Branding') ? VES_Branding::product_name() : 'Intelligence Suite';
?>
<div class="wrap">
    <h1><?php echo esc_html(sprintf( /* translators: %s product name */ __( '%s — Creative Intelligence', FICI_TEXT_DOMAIN ), $fici_product_label )); ?></h1>
    <p><?php echo esc_html__( 'Configure the AI provider, upload limits, retention policy, and default credit costs for the Creative Intelligence add-on.', FICI_TEXT_DOMAIN ); ?></p>

    <form action="options.php" method="post">
        <?php
        settings_fields( FICI_Settings::PAGE_SLUG );
        do_settings_sections( FICI_Settings::PAGE_SLUG );
        submit_button();
        ?>
    </form>

    <hr />
    <h2><?php echo esc_html__( 'Frontend shortcode', FICI_TEXT_DOMAIN ); ?></h2>
    <p><code>[fici_creative_analyzer]</code></p>
</div>
