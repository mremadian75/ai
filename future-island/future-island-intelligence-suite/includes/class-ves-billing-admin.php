<?php
if (!defined('ABSPATH')) { exit; }

final class VES_Billing_Admin {
    public static function register() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
    }

    public static function admin_menu() {
        add_submenu_page(
            'options-general.php',
            'VE Billing Requests',
            'VE Billing Requests',
            'manage_options',
            'ves-billing-requests',
            [__CLASS__, 'render_page']
        );
    }

    private static function render_notice() {
        // v0.9.8.0: prefer the cookie-token transient (set via VES_Usage_Billing::flash_notice)
        // and fall back to query args only for legacy admin redirects produced by this same page.
        if (class_exists('VES_Usage_Billing')) {
            $rendered = VES_Usage_Billing::render_notice_for_admin();
            if (is_string($rendered) && $rendered !== '') {
                return $rendered;
            }
        }
        return '';
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $plans = class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_available_plans() : [];
        $packs = class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_credit_packs() : [];
        $requests = class_exists('VES_Usage_Billing') ? VES_Usage_Billing::get_topup_requests_admin(80) : [];
        echo '<div class="wrap"><h1>VE Billing Requests</h1>';
        echo self::render_notice();
        echo '<p>Desde aquí puedes revisar solicitudes manuales de recarga, ver el catálogo de packs y recordar los límites de cada plan.</p>';

        echo '<h2>Planes activos</h2>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Plan</th><th>Créditos/mes</th><th>Monitores activos</th><th>Límites clave</th></tr></thead><tbody>';
        foreach ($plans as $plan) {
            $limits = [];
            foreach ((array) ($plan['limits'] ?? []) as $k => $v) {
                $limits[] = esc_html($k . ': ' . (int) $v);
            }
            echo '<tr><td><strong>' . esc_html((string) ($plan['label'] ?? 'Plan')) . '</strong><br><span class="description">' . esc_html((string) ($plan['description'] ?? '')) . '</span></td><td>' . esc_html(number_format_i18n((float) ($plan['monthly_credits'] ?? 0), 0)) . '</td><td>' . esc_html((string) (int) ($plan['max_active_monitors'] ?? 0)) . '</td><td>' . implode(' · ', $limits) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px">Credit packs</h2>';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Pack</th><th>Créditos</th><th>Precio manual</th><th>Estado</th></tr></thead><tbody>';
        foreach ($packs as $pack) {
            echo '<tr><td><strong>' . esc_html((string) ($pack['label'] ?? 'Pack')) . '</strong><br><span class="description">' . esc_html((string) ($pack['description'] ?? '')) . '</span></td><td>' . esc_html(number_format_i18n((float) ($pack['credits'] ?? 0), 0)) . '</td><td>' . esc_html((string) ($pack['price_label'] ?? 'Manual')) . '</td><td>' . esc_html((string) ($pack['status'] ?? 'inactive')) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px">Solicitudes de recarga</h2>';
        if (empty($requests)) {
            echo '<p>No hay solicitudes todavía.</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Usuario</th><th>Pack</th><th>Estado</th><th>Nota</th><th>Fechas</th><th>Acciones</th></tr></thead><tbody>';
        foreach ($requests as $request) {
            echo '<tr>';
            echo '<td>#' . esc_html((string) (int) $request['id']) . '</td>';
            echo '<td><strong>' . esc_html((string) ($request['user_display'] ?? '')) . '</strong><br><a href="mailto:' . esc_attr((string) ($request['user_email'] ?? '')) . '">' . esc_html((string) ($request['user_email'] ?? '')) . '</a></td>';
            echo '<td>' . esc_html((string) ($request['pack_label'] ?? 'Pack')) . '<br><span class="description">' . esc_html(number_format_i18n((float) ($request['credits'] ?? 0), 0)) . ' créditos · ' . esc_html((string) ($request['price_label'] ?? '')) . '</span></td>';
            echo '<td><strong>' . esc_html((string) ($request['status'] ?? 'pending')) . '</strong></td>';
            echo '<td>' . esc_html((string) ($request['user_note'] ?? '')) . '</td>';
            echo '<td><div>Solicitada: ' . esc_html((string) ($request['requested_at'] ?? '')) . '</div>';
            if (!empty($request['decided_at'])) {
                echo '<div class="description">Resuelta: ' . esc_html((string) $request['decided_at']) . '</div>';
            }
            echo '</td><td>';
            if (($request['status'] ?? '') === 'pending') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:8px">';
                echo '<input type="hidden" name="action" value="ves_approve_topup">';
                echo '<input type="hidden" name="request_id" value="' . esc_attr((string) (int) $request['id']) . '">';
                wp_nonce_field('ves_approve_topup');
                echo '<input type="text" name="admin_note" class="regular-text" placeholder="Nota opcional" style="margin-bottom:6px">';
                echo '<br><button type="submit" class="button button-primary">Aprobar</button>';
                echo '</form>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="ves_reject_topup">';
                echo '<input type="hidden" name="request_id" value="' . esc_attr((string) (int) $request['id']) . '">';
                wp_nonce_field('ves_reject_topup');
                echo '<input type="text" name="admin_note" class="regular-text" placeholder="Motivo opcional" style="margin-bottom:6px">';
                echo '<br><button type="submit" class="button">Rechazar</button>';
                echo '</form>';
            } else {
                echo '<span class="description">Ya procesada</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
