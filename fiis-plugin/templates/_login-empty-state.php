<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="ves-card ves-empty-state">
    <svg class="ves-empty-illustration" viewBox="0 0 80 80" width="80" height="80" aria-hidden="true">
        <circle cx="40" cy="40" r="28" fill="#a5b4fc" opacity=".25"/>
        <path d="M30 36v-4a10 10 0 0 1 20 0v4" stroke="#6366f1" stroke-width="2.5" stroke-linecap="round" fill="none"/>
        <rect x="26" y="36" width="28" height="22" rx="4" fill="none" stroke="#6366f1" stroke-width="2.5"/>
        <circle cx="40" cy="46" r="2.5" fill="#6366f1"/>
    </svg>
    <h3>Inicia sesión para continuar</h3>
    <p class="ves-meta">Esta sección requiere una cuenta. El contexto, la memoria y el consumo se asocian a tu usuario.</p>
    <?php if (class_exists('VES_Auth')) : ?>
        <div class="ves-empty-actions">
            <a class="ves-btn ves-btn-primary" href="<?php echo esc_url(VES_Auth::get_page_url('login')); ?>">Iniciar sesión</a>
            <a class="ves-btn ves-btn-secondary" href="<?php echo esc_url(VES_Auth::get_page_url('register')); ?>">Crear cuenta</a>
        </div>
    <?php endif; ?>
</div>
