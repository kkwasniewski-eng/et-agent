<?php
/**
 * Plugin Name: ET Agent
 * Description: Agent monitorujący instalację WordPress dla CRM eTechnologie
 * Version: 1.0.0
 * Author: eTechnologie
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('ET_AGENT_VERSION', '1.0.0');
define('ET_AGENT_GITHUB_REPO', 'kkwasniewski-eng/et-agent');

/* =========================================================================
   Auto-updater via GitHub Releases
   ========================================================================= */

add_filter('pre_set_site_transient_update_plugins', 'et_agent_check_update');

function et_agent_check_update($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_file = plugin_basename(__FILE__);

    if (!isset($transient->checked[$plugin_file])) {
        return $transient;
    }

    $release = et_agent_get_latest_release();
    if (!$release) {
        return $transient;
    }

    $latest  = ltrim($release['tag_name'], 'v');
    $current = $transient->checked[$plugin_file];

    if (version_compare($latest, $current, '>')) {
        $transient->response[$plugin_file] = (object) [
            'id'          => ET_AGENT_GITHUB_REPO,
            'slug'        => 'et-agent',
            'plugin'      => $plugin_file,
            'new_version' => $latest,
            'url'         => 'https://github.com/' . ET_AGENT_GITHUB_REPO,
            'package'     => $release['download_url'],
        ];
    }

    return $transient;
}

function et_agent_get_latest_release(): ?array {
    $cached = get_transient('et_agent_latest_release');
    if ($cached !== false) {
        return $cached ?: null;
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/' . ET_AGENT_GITHUB_REPO . '/releases/latest',
        [
            'headers' => ['User-Agent' => 'ET-Agent-Updater/' . ET_AGENT_VERSION],
            'timeout' => 10,
        ]
    );

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        set_transient('et_agent_latest_release', [], 6 * HOUR_IN_SECONDS);
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    $download_url = null;
    foreach ($body['assets'] ?? [] as $asset) {
        if (pathinfo($asset['name'], PATHINFO_EXTENSION) === 'zip') {
            $download_url = $asset['browser_download_url'];
            break;
        }
    }

    if (!$download_url) {
        set_transient('et_agent_latest_release', [], 6 * HOUR_IN_SECONDS);
        return null;
    }

    $release = [
        'tag_name'     => $body['tag_name'],
        'download_url' => $download_url,
    ];

    set_transient('et_agent_latest_release', $release, 12 * HOUR_IN_SECONDS);

    return $release;
}

// Wyczyść cache po ręcznym sprawdzeniu aktualizacji
add_action('upgrader_process_complete', function () {
    delete_transient('et_agent_latest_release');
});

/* =========================================================================
   Activation / Deactivation
   ========================================================================= */

register_activation_hook(__FILE__, function () {
    if (!get_option('et_agent_site_token')) {
        $token = bin2hex(random_bytes(32)); // 64 chars
        update_option('et_agent_site_token', $token, false);
    }

    if (!wp_next_scheduled('et_agent_report_cron')) {
        wp_schedule_event(time(), 'twicedaily', 'et_agent_report_cron');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('et_agent_report_cron');
});

/* =========================================================================
   REST API
   ========================================================================= */

add_action('rest_api_init', function () {
    $namespace = 'et-agent/v1';

    $permission = function (\WP_REST_Request $request): bool {
        $token = $request->get_header('X-ET-Agent-Token');
        return $token && hash_equals(get_option('et_agent_site_token', ''), $token);
    };

    // GET /report
    register_rest_route($namespace, '/report', [
        'methods'             => 'GET',
        'callback'            => 'et_agent_get_report',
        'permission_callback' => $permission,
    ]);

    // POST /auto-login
    register_rest_route($namespace, '/auto-login', [
        'methods'             => 'POST',
        'callback'            => 'et_agent_auto_login',
        'permission_callback' => $permission,
    ]);

    // POST /set-quota
    register_rest_route($namespace, '/set-quota', [
        'methods'             => 'POST',
        'callback'            => function (\WP_REST_Request $request) {
            $body = $request->get_json_params();
            $quota = isset($body['quota_mb']) ? (int) $body['quota_mb'] : 0;
            update_option('et_agent_disk_quota_mb', $quota);
            delete_transient('et_agent_disk_used_mb');
            return new \WP_REST_Response([
                'quota_mb' => $quota,
                'disk' => et_agent_get_disk_usage(),
            ]);
        },
        'permission_callback' => $permission,
    ]);

    // GET /health
    register_rest_route($namespace, '/health', [
        'methods'             => 'GET',
        'callback'            => function () {
            return new \WP_REST_Response([
                'status'    => 'ok',
                'timestamp' => gmdate('Y-m-d\TH:i:sP'),
            ]);
        },
        'permission_callback' => $permission,
    ]);
});

/* =========================================================================
   Report data collector
   ========================================================================= */

function et_agent_collect_report(): array {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins    = get_plugins();
    $update_plugins = get_site_transient('update_plugins');
    $plugins        = [];

    foreach ($all_plugins as $file => $data) {
        $slug             = dirname($file) ?: $file;
        $update_available = null;

        if (isset($update_plugins->response[$file])) {
            $update_available = $update_plugins->response[$file]->new_version ?? null;
        }

        $plugins[] = [
            'slug'             => $slug,
            'name'             => $data['Name'] ?? '',
            'version'          => $data['Version'] ?? '',
            'active'           => is_plugin_active($file),
            'update_available' => $update_available,
        ];
    }

    $theme = wp_get_theme();

    $disk = et_agent_get_disk_usage();

    $users_count = (int) count_users()['total_users'];

    return [
        'wp_version'  => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'plugins'     => $plugins,
        'theme'       => [
            'name'    => $theme->get('Name'),
            'version' => $theme->get('Version'),
        ],
        'disk'        => $disk,
        'users_count' => $users_count,
        'site_url'    => get_site_url(),
        'admin_email' => get_option('admin_email'),
    ];
}

/* =========================================================================
   Disk usage — hosting-aware
   ========================================================================= */

function et_agent_get_disk_usage(): array {
    $total_mb = (int) get_option('et_agent_disk_quota_mb', 0) ?: null;

    // Try cached value first (du can be slow on large dirs)
    $cached = get_transient('et_agent_disk_used_mb');
    if ($cached !== false) {
        $used_mb = (int) $cached;
        $free_mb = ($total_mb && $used_mb) ? ($total_mb - $used_mb) : null;
        return ['total_mb' => $total_mb, 'used_mb' => $used_mb, 'free_mb' => $free_mb];
    }

    // Calculate disk usage
    $used_mb = null;
    $home_dir = getenv('HOME') ?: dirname(dirname(ABSPATH));

    // Method 1: du -sm (fast, works on most hosts)
    if (function_exists('shell_exec')) {
        $output = @shell_exec("du -sm " . escapeshellarg($home_dir) . " 2>/dev/null");
        if ($output && preg_match('/^(\d+)/', $output, $m)) {
            $used_mb = (int) $m[1];
        }
    }

    // Method 2: Fallback to disk_total_space/disk_free_space (shows server disk, not quota)
    if ($used_mb === null) {
        $disk_total = @disk_total_space(ABSPATH);
        $disk_free  = @disk_free_space(ABSPATH);
        if ($disk_total && $disk_free) {
            if (!$total_mb) {
                $total_mb = (int) round($disk_total / 1048576);
            }
            $used_mb = (int) round(($disk_total - $disk_free) / 1048576);
        }
    }

    // Cache for 1 hour
    if ($used_mb !== null) {
        set_transient('et_agent_disk_used_mb', $used_mb, 3600);
    }

    $free_mb = ($total_mb && $used_mb !== null) ? ($total_mb - $used_mb) : null;

    return ['total_mb' => $total_mb, 'used_mb' => $used_mb, 'free_mb' => $free_mb];
}

/* =========================================================================
   Endpoint callbacks
   ========================================================================= */

function et_agent_get_report(\WP_REST_Request $request): \WP_REST_Response {
    return new \WP_REST_Response(et_agent_collect_report());
}

function et_agent_auto_login(\WP_REST_Request $request): \WP_REST_Response {
    $body    = $request->get_json_params();
    $user_id = isset($body['user_id']) ? (int) $body['user_id'] : 1;

    $user = get_user_by('id', $user_id);
    if (!$user) {
        return new \WP_REST_Response(['error' => 'Użytkownik nie istnieje.'], 404);
    }

    $token = bin2hex(random_bytes(32));
    set_transient('et_agent_autologin_' . $token, $user_id, 60);

    return new \WP_REST_Response([
        'url' => add_query_arg('et-autologin', $token, site_url('/')),
    ]);
}

/* =========================================================================
   Auto-login handler (init)
   ========================================================================= */

add_action('init', function () {
    if (empty($_GET['et-autologin'])) {
        return;
    }

    $token   = sanitize_text_field($_GET['et-autologin']);
    $key     = 'et_agent_autologin_' . $token;
    $user_id = get_transient($key);
    delete_transient($key);

    if (!$user_id) {
        wp_die('Link wygasł lub jest nieprawidłowy.', 'ET Agent', ['response' => 403]);
    }

    wp_set_auth_cookie((int) $user_id, false);
    wp_safe_redirect(admin_url());
    exit;
});

/* =========================================================================
   Cron — send report to CRM
   ========================================================================= */

add_action('et_agent_report_cron', 'et_agent_send_report');

function et_agent_send_report(): void {
    $crm_url = get_option('et_agent_crm_url', '');
    $token   = get_option('et_agent_site_token', '');

    if (!$crm_url || !$token) {
        update_option('et_agent_last_report', [
            'time'   => current_time('mysql'),
            'status' => 'error',
            'message' => 'Brak konfiguracji CRM URL lub tokenu.',
        ], false);
        return;
    }

    $report   = et_agent_collect_report();
    $endpoint = rtrim($crm_url, '/') . '/api/agent/report';

    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Content-Type'     => 'application/json',
            'X-ET-Agent-Token' => $token,
        ],
        'body'    => wp_json_encode($report),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        $result = [
            'time'    => current_time('mysql'),
            'status'  => 'error',
            'message' => $response->get_error_message(),
        ];
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $result = [
            'time'    => current_time('mysql'),
            'status'  => ($code >= 200 && $code < 300) ? 'ok' : 'error',
            'message' => "HTTP {$code}",
        ];
    }

    update_option('et_agent_last_report', $result, false);
}

/* =========================================================================
   Settings page
   ========================================================================= */

add_action('admin_menu', function () {
    add_options_page(
        'ET Agent',
        'ET Agent',
        'manage_options',
        'et-agent',
        'et_agent_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('et_agent_settings', 'et_agent_crm_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ]);

    register_setting('et_agent_settings', 'et_agent_disk_quota_mb', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ]);

    add_settings_section(
        'et_agent_main',
        'Konfiguracja agenta',
        '__return_null',
        'et-agent'
    );

    add_settings_field(
        'et_agent_site_token',
        'Site Token',
        function () {
            $token = get_option('et_agent_site_token', '');
            ?>
            <input type="text" id="et-agent-token" value="<?php echo esc_attr($token); ?>"
                   class="regular-text" readonly />
            <button type="button" class="button" onclick="
                var inp = document.getElementById('et-agent-token');
                inp.select();
                navigator.clipboard.writeText(inp.value).then(function(){
                    alert('Token skopiowany do schowka.');
                });
            ">Kopiuj</button>
            <p class="description">Token generowany automatycznie przy aktywacji pluginu.</p>
            <?php
        },
        'et-agent',
        'et_agent_main'
    );

    add_settings_field(
        'et_agent_crm_url',
        'CRM URL',
        function () {
            $url = get_option('et_agent_crm_url', '');
            ?>
            <input type="url" name="et_agent_crm_url" value="<?php echo esc_attr($url); ?>"
                   class="regular-text" placeholder="https://crm.etechnologie.pl" />
            <p class="description">Adres CRM, do którego agent wysyła raporty.</p>
            <?php
        },
        'et-agent',
        'et_agent_main'
    );

    add_settings_field(
        'et_agent_disk_quota_mb',
        'Quota dysku (MB)',
        function () {
            $quota = get_option('et_agent_disk_quota_mb', 0);
            ?>
            <input type="number" name="et_agent_disk_quota_mb" value="<?php echo esc_attr($quota); ?>"
                   class="small-text" min="0" step="1" />
            <p class="description">Limit dysku z panelu hostingu w MB (np. 5120 = 5 GB). 0 = brak limitu.</p>
            <?php
        },
        'et-agent',
        'et_agent_main'
    );

    add_settings_field(
        'et_agent_last_report',
        'Ostatni raport',
        function () {
            $last = get_option('et_agent_last_report', []);
            if (empty($last)) {
                echo '<p>Raport nie był jeszcze wysyłany.</p>';
                return;
            }
            $status_label = ($last['status'] ?? '') === 'ok' ? '✅ OK' : '❌ Błąd';
            printf(
                '<p>%s — %s<br><code>%s</code></p>',
                esc_html($last['time'] ?? '—'),
                $status_label,
                esc_html($last['message'] ?? '')
            );
        },
        'et-agent',
        'et_agent_main'
    );

    // Handle manual report send
    if (isset($_POST['et_agent_send_now']) && check_admin_referer('et_agent_settings-options')) {
        et_agent_send_report();
        add_settings_error('et_agent_settings', 'report_sent', 'Raport został wysłany.', 'updated');
    }
});

function et_agent_settings_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>ET Agent</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('et_agent_settings');
            do_settings_sections('et-agent');
            submit_button('Zapisz ustawienia');
            ?>
        </form>

        <hr />

        <form method="post">
            <?php wp_nonce_field('et_agent_settings-options'); ?>
            <p>
                <button type="submit" name="et_agent_send_now" value="1" class="button button-secondary">
                    Wyślij raport teraz
                </button>
            </p>
        </form>
    </div>
    <?php
}
