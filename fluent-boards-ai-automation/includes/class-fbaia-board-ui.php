<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * In-app Fluent Boards UI: a small, admin-only "AI Analyze" launcher injected onto Fluent
 * Boards screens via the official fluent_boards/after_enqueue_assets hook.
 *
 * It is deliberately decoupled from Fluent Boards' Vue internals (which change between
 * versions): it renders its own floating launcher + panel and calls the plugin's existing,
 * capability-protected REST endpoint. Assets load ONLY on Fluent Boards screens and ONLY for
 * users who can manage_options.
 */
class FBAIA_Board_UI
{
    public function register()
    {
        // Fires while Fluent Boards enqueues its own assets — i.e. only on its screens.
        add_action('fluent_boards/after_enqueue_assets', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = FBAIA_Helpers::get_settings();

        wp_enqueue_style('fbaia-board', FBAIA_URL . 'assets/board.css', [], FBAIA_VERSION);
        wp_enqueue_script('fbaia-board', FBAIA_URL . 'assets/board.js', [], FBAIA_VERSION, true);
        wp_localize_script('fbaia-board', 'FBAIABoard', [
            'restRoot'      => esc_url_raw(rest_url('fb-ai-automation/v1/')),
            'nonce'         => wp_create_nonce('wp_rest'),
            'reviewUrl'     => esc_url_raw(add_query_arg('tab', 'review', FBAIA_Helpers::admin_url())),
            'settingsUrl'   => esc_url_raw(add_query_arg('tab', 'automations', FBAIA_Helpers::admin_url())),
            'engineEnabled' => (($settings['enabled'] ?? 'no') === 'yes'),
            'i18n'          => [
                'launcher'  => __('AI', 'fluent-boards-ai-automation'),
                'title'     => __('AI Task Analysis', 'fluent-boards-ai-automation'),
                'taskId'    => __('Task ID', 'fluent-boards-ai-automation'),
                'analyze'   => __('Analyze', 'fluent-boards-ai-automation'),
                'close'     => __('Close', 'fluent-boards-ai-automation'),
                'working'   => __('Queuing analysis…', 'fluent-boards-ai-automation'),
                'analyzing' => __('Analyzing the task… this can take a few seconds.', 'fluent-boards-ai-automation'),
                'queued'    => __('Analysis queued. Open the review queue:', 'fluent-boards-ai-automation'),
                'stillRunning' => __('Still processing. It will appear in the review queue shortly.', 'fluent-boards-ai-automation'),
                'notQueued' => __('Not queued', 'fluent-boards-ai-automation'),
                'needId'    => __('Enter a valid task ID.', 'fluent-boards-ai-automation'),
                'engineOff' => __('Engine is OFF (safe mode). Enable it in Settings → Fluent Boards AI → Automations.', 'fluent-boards-ai-automation'),
                'review'    => __('Review queue', 'fluent-boards-ai-automation'),
                'settings'  => __('Settings', 'fluent-boards-ai-automation'),
                'priority'  => __('Priority', 'fluent-boards-ai-automation'),
                'confidence' => __('Confidence', 'fluent-boards-ai-automation'),
                'risk'      => __('Risk', 'fluent-boards-ai-automation'),
                'pendingReview' => __('Pending your review', 'fluent-boards-ai-automation'),
                'error'     => __('Request failed. Check the Logs tab.', 'fluent-boards-ai-automation'),
            ],
        ]);
    }
}
