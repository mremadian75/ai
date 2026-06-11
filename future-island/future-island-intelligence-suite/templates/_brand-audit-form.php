<?php if (!defined('ABSPATH')) { exit; } ?>
<form class="ves-form ves-form-brand-audit" data-ajax="<?php echo esc_url($ajax_url); ?>" onsubmit="return false;">
    <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
    <input type="hidden" name="outputLanguage" value="<?php echo esc_attr($output_language ?? 'es'); ?>">
    <input type="hidden" name="platform" value="brand_audit">

    <div class="ves-brand-audit-grid">
        <div class="ves-field ves-field-wide ves-form-section-title"><strong>Datos de marca</strong></div>

        <div class="ves-field">
            <label class="ves-label">Nombre de marca <span class="ves-tip" tabindex="0" data-tip="Nombre público de la marca que quieres auditar.">?</span></label>
            <input class="ves-input" type="text" name="brandName" maxlength="160" placeholder="Tu marca" required>
        </div>

        <div class="ves-field">
            <label class="ves-label">URL del sitio web <span class="ves-tip" tabindex="0" data-tip="Opcional. Se usa para evidencias del sitio web; las URLs privadas o internas están bloqueadas.">?</span></label>
            <input class="ves-input" type="url" name="websiteUrl" placeholder="https://example.com">
        </div>

        <div class="ves-field">
            <label class="ves-label">País / mercado</label>
            <select class="ves-select" name="brandCountry">
                <option value="ES" selected>España</option>
                <option value="None">Mundial</option>
                <option value="US">Estados Unidos</option>
                <option value="GB">Reino Unido</option>
                <option value="FR">Francia</option>
                <option value="DE">Alemania</option>
                <option value="IT">Italia</option>
                <option value="PT">Portugal</option>
                <option value="MX">México</option>
            </select>
        </div>

        <div class="ves-field">
            <label class="ves-label">Industria / categoría <span class="ves-tip" tabindex="0" data-tip="Sector o categoría del producto/servicio. Orienta la selección de fuentes y el análisis.">?</span></label>
            <input class="ves-input" type="text" name="brandIndustry" maxlength="160" placeholder="Ej. cerveza, parafarmacia, automoción">
        </div>

        <div class="ves-field ves-field-wide">
            <label class="ves-label">Objetivo de la auditoría <span class="ves-tip" tabindex="0" data-tip="¿Qué quieres descubrir? Ej: detectar percepción, gaps de contenido u oportunidades para una presentación comercial.">?</span></label>
            <textarea class="ves-textarea" name="brandAuditGoal" rows="3" maxlength="800" placeholder="Ej. Detectar percepción, gaps de contenido y oportunidades para una presentación comercial."></textarea>
        </div>

        <div class="ves-field ves-field-wide ves-form-section-title"><strong>Canales propios</strong></div>

        <div class="ves-field">
            <label class="ves-label">Instagram <span class="ves-tip" tabindex="0" data-tip="URL del perfil público en Instagram. Ej: https://instagram.com/marca">?</span></label>
            <input class="ves-input" type="url" name="instagramUrl" placeholder="https://instagram.com/marca">
        </div>

        <div class="ves-field">
            <label class="ves-label">TikTok <span class="ves-tip" tabindex="0" data-tip="URL del perfil en TikTok. Ej: https://tiktok.com/@marca">?</span></label>
            <input class="ves-input" type="url" name="tiktokUrl" placeholder="https://tiktok.com/@marca">
        </div>

        <div class="ves-field">
            <label class="ves-label">YouTube</label>
            <input class="ves-input" type="url" name="youtubeUrl" placeholder="https://youtube.com/@marca">
        </div>

        <div class="ves-field">
            <label class="ves-label">X / Twitter</label>
            <input class="ves-input" type="url" name="twitterUrl" placeholder="https://x.com/marca">
        </div>

        <div class="ves-field">
            <label class="ves-label">Facebook</label>
            <input class="ves-input" type="url" name="facebookUrl" placeholder="https://facebook.com/marca">
        </div>

        <div class="ves-field">
            <label class="ves-label">LinkedIn</label>
            <input class="ves-input" type="url" name="linkedinUrl" placeholder="https://linkedin.com/company/marca">
        </div>

        <div class="ves-field">
            <label class="ves-label">Google Maps <span class="ves-tip" tabindex="0" data-tip="URL pública del negocio en Google Maps para recopilar reseñas y presencia local.">?</span></label>
            <input class="ves-input" type="url" name="googleMapsUrl" placeholder="https://maps.google.com/...">
        </div>

        <div class="ves-field ves-field-wide">
            <label class="ves-label">Hashtags <span class="ves-tip" tabindex="0" data-tip="Hashtags principales de la marca o campaña. Separados por coma.">?</span></label>
            <input class="ves-input" type="text" name="brandHashtags" placeholder="#marca, #campaña, #producto">
        </div>

        <div class="ves-field ves-field-wide ves-form-section-title"><strong>Competidores y salida</strong></div>

        <div class="ves-field ves-field-wide">
            <label class="ves-label">Competidores <span class="ves-tip" tabindex="0" data-tip="Nombres o dominios de competidores a incluir en el análisis comparativo. Separados por coma.">?</span></label>
            <input class="ves-input" type="text" name="brandCompetitors" placeholder="Competidor 1, Competidor 2, Competidor 3">
        </div>

        <div class="ves-field">
            <label class="ves-label">Profundidad <span class="ves-tip" tabindex="0" data-tip="Ligero: rápido y económico. Estándar: equilibrado. Profundo: máxima evidencia, mayor coste de créditos.">?</span></label>
            <select class="ves-select" name="brandAuditDepth">
                <option value="light">Ligero</option>
                <option value="standard" selected>Estándar</option>
                <option value="deep">Profundo</option>
            </select>
        </div>

        <div class="ves-field">
            <label class="ves-label">Formato de salida</label>
            <select class="ves-select" name="brandAuditOutput">
                <option value="report">Solo informe</option>
                <option value="deck">Para presentación</option>
                <option value="report_deck" selected>Informe + Presentación</option>
            </select>
        </div>

        <div class="ves-field ves-field-wide ves-brand-audit-estimate-panel">
            <label class="ves-label">Estimación de créditos <span class="ves-tip" tabindex="0" data-tip="La estimación es aproximada. El uso final varía según fuentes disponibles, volumen de evidencia y pasos de IA.">?</span></label>
            <button type="button" class="ves-btn ves-btn-secondary ves-brand-audit-estimate-btn" data-loading-text="Estimando...">Estimar antes de ejecutar</button>
            <div class="ves-credit-estimate-host" data-ves-brand-audit-estimate-host></div>
        </div>
    </div>
</form>
