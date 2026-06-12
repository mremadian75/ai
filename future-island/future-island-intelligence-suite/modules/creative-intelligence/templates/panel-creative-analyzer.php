<?php
$workspace_context = isset( $workspace_context ) && is_array( $workspace_context ) ? $workspace_context : array();
$workspace_name    = sanitize_text_field( (string) ( $workspace_context['workspace_name'] ?? 'Standalone workspace' ) );
$workspace_mode    = sanitize_key( (string) ( $workspace_context['mode'] ?? 'standalone' ) );
$workspace_credits = sanitize_text_field( (string) ( $workspace_context['credits_label'] ?? 'Local estimate only' ) );
$core_available    = ! empty( $workspace_context['core_available'] );
?>
<div class="fici-panel" data-fici-panel>
    <section class="fici-card fici-input-card">
        <div class="fici-workspace-strip">
            <div>
                <span class="fici-eyebrow"><?php echo esc_html__( 'Workspace binding', FICI_TEXT_DOMAIN ); ?></span>
                <strong><?php echo esc_html( $workspace_name ); ?></strong>
                <p><?php echo esc_html( $core_available ? __( 'Connected to core SaaS bridge.', FICI_TEXT_DOMAIN ) : __( 'Standalone mode. Core hooks are ready but not connected yet.', FICI_TEXT_DOMAIN ) ); ?></p>
            </div>
            <div class="fici-workspace-meta">
                <span><?php echo esc_html( $workspace_mode ); ?></span>
                <span><?php echo esc_html( $workspace_credits ); ?></span>
            </div>
        </div>

        <div class="fici-section-head">
            <span class="fici-eyebrow">CREATIVE INTELLIGENCE</span>
            <h2>Analiza un asset creativo</h2>
            <p>Sube una imagen, screenshot creativo o vídeo corto. En vídeo, el navegador extrae key frames y los muestra antes de enviar el análisis AI.</p>
        </div>

        <form class="fici-form" data-fici-form>
            <label>
                Modo de análisis
                <select name="creative_mode">
                    <option value="analyze_my_creative" selected>Analizar mi creatividad</option>
                    <option value="analyze_competitor_creative">Analizar creatividad competidora</option>
                    <option value="improve_ad_creative">Mejorar anuncio</option>
                    <option value="extract_hooks">Extraer hooks</option>
                    <option value="generate_variations">Generar variaciones</option>
                    <option value="compare_creatives">Comparar creatividades</option>
                    <option value="build_creative_brief">Crear brief creativo</option>
                </select>
            </label>

            <label class="fici-dropzone" data-fici-dropzone>
                Archivo creativo
                <small>Formatos: JPG, PNG, WebP, GIF, MP4, MOV, WebM. Límite configurado: <span data-fici-max-size>20 MB</span>. Vídeo: máximo 60 s y hasta 4 frames extraídos antes del AI.</small>
                <input type="file" name="asset_file" accept="image/*,video/mp4,video/webm,video/quicktime" />
                <span class="fici-drop-hint">Arrastra un archivo aquí o haz clic para seleccionarlo.</span>
            </label>

            <div class="fici-preflight" data-fici-preflight hidden></div>
            <div class="fici-frame-preview" data-fici-frame-preview hidden></div>

            <label>
                Asset URL
                <input type="url" name="asset_url" placeholder="https://example.com/creative.jpg" />
            </label>

            <label>
                Objetivo
                <textarea name="goal" rows="3" placeholder="Ejemplo: analizar como anuncio de TikTok y proponer mejoras de hook, CTA y claridad."></textarea>
            </label>

            <label>
                Contexto
                <textarea name="context" rows="4" placeholder="Marca, audiencia, objetivo de campaña, producto, tono, restricciones..."></textarea>
            </label>

            <div class="fici-grid">
                <label>
                    Intensidad
                    <select name="analysis_mode">
                        <option value="quick">Rápido</option>
                        <option value="standard" selected>Estándar</option>
                        <option value="deep">Profundo</option>
                    </select>
                </label>

                <label>
                    Plataforma objetivo
                    <select name="target_platform">
                        <option value="generic">Genérica</option>
                        <option value="tiktok">TikTok</option>
                        <option value="instagram_reels">Instagram Reels</option>
                        <option value="youtube_shorts">YouTube Shorts</option>
                        <option value="meta_ads">Meta Ads</option>
                        <option value="linkedin">LinkedIn</option>
                    </select>
                </label>
            </div>

            <div class="fici-estimate" data-fici-estimate></div>
            <div class="fici-status" data-fici-status hidden></div>

            <button type="submit">Analizar creatividad</button>
        </form>
    </section>

    <aside class="fici-card fici-history-card">
        <div class="fici-section-head">
            <span class="fici-eyebrow">Workspace continuity</span>
            <h3>Análisis anteriores</h3>
            <p>Los análisis guardados quedan disponibles como referencia creativa del workspace.</p>
        </div>
        <div class="fici-history-list" data-fici-history>
            <div class="fici-muted">Loading previous analyses...</div>
        </div>
    </aside>

    <section class="fici-card fici-result" data-fici-result hidden></section>
</div>
