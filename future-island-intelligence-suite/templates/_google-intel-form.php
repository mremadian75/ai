<?php if (!defined('ABSPATH')) { exit; } ?>
<?php $ves_google_modules = class_exists('VES_Google_Intel') ? VES_Google_Intel::public_modules() : []; ?>
<form class="ves-form ves-form-google" data-ajax="<?php echo esc_url($ajax_url); ?>" onsubmit="return false;">
    <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
    <input type="hidden" name="outputLanguage" value="<?php echo esc_attr($output_language ?? 'es'); ?>">
    <input type="hidden" name="platform" value="google_intel">
    <input type="hidden" name="analysisMode" value="summary">
    <input type="hidden" name="googleAutoAnalyze" value="1">
    <input type="hidden" name="googleFocus" value="">

    <div class="ves-field">
        <label class="ves-label">Fuente Google</label>
        <select class="ves-select" name="googleModule">
            <?php foreach ($ves_google_modules as $key => $module) : ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($module['label'] ?? $key); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="ves-hint">Elige una fuente segura. Los parámetros avanzados se gestionan automáticamente para reducir errores.</div>
    </div>

    <div class="ves-google-module-panels">
        <?php foreach ($ves_google_modules as $key => $module) : ?>
            <div class="ves-google-module-panel" data-google-module-panel="<?php echo esc_attr($key); ?>" <?php echo $key === 'search' ? '' : 'hidden'; ?>>
                <div class="ves-row ves-google-fields-grid">
                    <?php foreach (($module['fields'] ?? []) as $field) : ?>
                        <?php
                        $name = sanitize_key((string) ($field['name'] ?? ''));
                        if ($name === '') { continue; }
                        $type = sanitize_key((string) ($field['type'] ?? 'text'));
                        $label = (string) ($field['label'] ?? $name);
                        $required = !empty($field['required']);
                        $default = (string) ($field['default'] ?? '');
                        $placeholder = (string) ($field['placeholder'] ?? '');
                        $full = in_array($type, ['textarea'], true) ? ' is-full' : '';
                        ?>
                        <?php if (isset($field['ui_visible']) && !$field['ui_visible']) : ?>
                            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($default); ?>" <?php echo $key === 'search' ? '' : 'disabled'; ?>>
                            <?php continue; ?>
                        <?php endif; ?>
                        <div class="ves-field<?php echo esc_attr($full); ?>">
                            <?php if ($type === 'checkbox') : ?>
                                <label class="ves-checkline">
                                    <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($default === '1' || $default === 'true'); ?> <?php echo $key === 'search' ? '' : 'disabled'; ?>>
                                    <span><?php echo esc_html($label); ?></span>
                                </label>
                            <?php else : ?>
                                <label class="ves-label"><?php echo esc_html($label); ?></label>
                                <?php if ($type === 'textarea') : ?>
                                    <textarea class="ves-textarea" name="<?php echo esc_attr($name); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" <?php echo $required ? 'required' : ''; ?> <?php echo $key === 'search' ? '' : 'disabled'; ?>><?php echo esc_textarea($default); ?></textarea>
                                <?php elseif ($type === 'select') : ?>
                                    <select class="ves-select" name="<?php echo esc_attr($name); ?>" <?php echo $key === 'search' ? '' : 'disabled'; ?>>
                                        <?php foreach (($field['options'] ?? []) as $value => $text) : ?>
                                            <option value="<?php echo esc_attr((string) $value); ?>" <?php selected((string) $value, $default); ?>><?php echo esc_html((string) $text); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else : ?>
                                    <input class="ves-input" type="<?php echo $type === 'number' ? 'number' : 'text'; ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($default); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" <?php echo isset($field['min']) ? 'min="' . esc_attr((string) $field['min']) . '"' : ''; ?> <?php echo isset($field['max']) ? 'max="' . esc_attr((string) $field['max']) . '"' : ''; ?> <?php echo isset($field['step']) ? 'step="' . esc_attr((string) $field['step']) . '"' : ''; ?> <?php echo $required ? 'required' : ''; ?> <?php echo $key === 'search' ? '' : 'disabled'; ?>>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="ves-bottom-cta">
        <button type="button" class="ves-btn ves-btn-primary ves-start ves-bottom-start">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
            Ejecutar Google Intelligence
        </button>
    </div>
</form>
