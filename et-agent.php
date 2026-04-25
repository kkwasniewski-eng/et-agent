<?php
/**
 * Plugin Name: ET Agent
 * Description: Agent monitorujący instalację WordPress dla CRM eTechnologie
 * Version: 1.0.4
 * Author: eTechnologie
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('ET_AGENT_VERSION', '1.0.4');
define('ET_AGENT_GITHUB_REPO', 'kkwasniewski-eng/et-agent');

/* BuddyBoss: whitelist ET-Agent REST endpoints from private API restriction */
add_filter('bb_enable_private_rest_apis_public_content', function ($endpoints) {
    $endpoints = trim($endpoints);
    if ($endpoints !== '') {
        $endpoints .= "\n";
    }
    $endpoints .= '/et-agent/v1';
    return $endpoints;
});

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

// Fix: GitHub ZIP extracts to folder like "et-agent-1.0.1" — rename to "et-agent"
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra) {
    if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
        return $source;
    }

    $expected_dir = trailingslashit($remote_source) . 'et-agent';
    if ($source === $expected_dir . '/') {
        return $source;
    }

    global $wp_filesystem;
    if ($wp_filesystem->move($source, $expected_dir)) {
        return trailingslashit($expected_dir);
    }

    return $source;
}, 10, 4);

/* =========================================================================
   Activation / Deactivation
   ========================================================================= */

register_activation_hook(__FILE__, function () {
    if (!get_option('et_agent_site_token')) {
        $token = bin2hex(random_bytes(32)); // 64 chars
        update_option('et_agent_site_token', $token, false);
    }

    if (!get_option('et_agent_crm_url')) {
        update_option('et_agent_crm_url', 'https://crm.etechnologie.info.pl/', false);
    }

    if (!wp_next_scheduled('et_agent_report_cron')) {
        wp_schedule_event(time(), 'twicedaily', 'et_agent_report_cron');
    }
    if (!wp_next_scheduled('et_agent_users_peak_cron')) {
        wp_schedule_event(time(), 'daily', 'et_agent_users_peak_cron');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('et_agent_report_cron');
    wp_clear_scheduled_hook('et_agent_users_peak_cron');
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

    register_rest_route($namespace, '/install-plugin', [
        'methods'             => 'POST',
        'callback'            => 'et_agent_install_plugin',
        'permission_callback' => $permission,
    ]);

    register_rest_route($namespace, '/site-test', [
        'methods'             => 'GET',
        'callback'            => 'et_agent_site_test',
        'permission_callback' => $permission,
    ]);

    register_rest_route($namespace, '/smtp-test', [
        'methods'             => 'POST',
        'callback'            => 'et_agent_smtp_test',
        'permission_callback' => $permission,
    ]);
});

/* =========================================================================
   SMTP test - send a probe email and capture wp_mail_failed errors
   ========================================================================= */

function et_agent_smtp_test(\WP_REST_Request $request): \WP_REST_Response {
    $body = $request->get_json_params();
    $recipient = $body['recipient'] ?? 'smtp-test@etechnologie.pl';
    $subject = $body['subject'] ?? 'ET Agent SMTP test - ' . parse_url(home_url(), PHP_URL_HOST);
    $message = $body['body'] ?? "ET Agent SMTP probe.\nSite: " . home_url() . "\nTime: " . gmdate('c');

    if (!is_email($recipient)) {
        return new \WP_REST_Response(['success' => false, 'error' => 'Invalid recipient email'], 400);
    }

    $captured_error = null;
    $error_listener = function ($wp_error) use (&$captured_error) {
        if (is_a($wp_error, 'WP_Error')) {
            $captured_error = $wp_error->get_error_message();
        }
    };
    add_action('wp_mail_failed', $error_listener);

    $detected_mailer = null;
    $mailer_inspector = function ($phpmailer) use (&$detected_mailer) {
        $detected_mailer = $phpmailer->Mailer ?? 'unknown';
        if ($detected_mailer === 'smtp') {
            $detected_mailer .= ' (' . ($phpmailer->Host ?? 'unknown') . ':' . ($phpmailer->Port ?? '?') . ')';
        }
    };
    add_action('phpmailer_init', $mailer_inspector);

    $started = microtime(true);
    $sent = wp_mail($recipient, $subject, $message);
    $elapsed_ms = (int) round((microtime(true) - $started) * 1000);

    remove_action('wp_mail_failed', $error_listener);
    remove_action('phpmailer_init', $mailer_inspector);

    if ($sent && !$captured_error) {
        return new \WP_REST_Response([
            'success'    => true,
            'mailer'     => $detected_mailer ?? 'unknown',
            'sent_to'    => $recipient,
            'subject'    => $subject,
            'elapsed_ms' => $elapsed_ms,
        ]);
    }

    return new \WP_REST_Response([
        'success'    => false,
        'error'      => $captured_error ?? 'wp_mail() returned false without raising wp_mail_failed',
        'mailer'     => $detected_mailer ?? 'unknown',
        'sent_to'    => $recipient,
        'elapsed_ms' => $elapsed_ms,
    ], 502);
}

/* =========================================================================
   Remote plugin install from GitHub
   ========================================================================= */

function et_agent_install_plugin(\WP_REST_Request $request): \WP_REST_Response {
    $body = $request->get_json_params();
    $github_url = $body['github_url'] ?? '';
    $github_token = $body['github_token']
        ?? (defined('ET_AGENT_GITHUB_TOKEN') ? ET_AGENT_GITHUB_TOKEN : '')
        ?: get_option('et_agent_github_token', '');

    if (!preg_match('#^https://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#', $github_url, $matches)) {
        return new \WP_REST_Response(['error' => 'Invalid GitHub URL'], 400);
    }

    $owner = $matches[1];
    $repo  = $matches[2];

    $base_headers = ['User-Agent' => 'ET-Agent/' . ET_AGENT_VERSION];
    if ($github_token) {
        $base_headers['Authorization'] = 'Bearer ' . $github_token;
    }

    // Try latest release ZIP first
    $download_url = null;
    $release_response = wp_remote_get(
        "https://api.github.com/repos/{$owner}/{$repo}/releases/latest",
        ['headers' => $base_headers + ['Accept' => 'application/vnd.github+json'], 'timeout' => 15]
    );

    if (!is_wp_error($release_response) && 200 === wp_remote_retrieve_response_code($release_response)) {
        $release_body = json_decode(wp_remote_retrieve_body($release_response), true);
        foreach ($release_body['assets'] ?? [] as $asset) {
            if (pathinfo($asset['name'], PATHINFO_EXTENSION) === 'zip') {
                $download_url = $github_token
                    ? "https://api.github.com/repos/{$owner}/{$repo}/releases/assets/{$asset['id']}"
                    : $asset['browser_download_url'];
                break;
            }
        }
        if (!$download_url && !empty($release_body['zipball_url'])) {
            $download_url = $release_body['zipball_url'];
        }
    }

    if (!$download_url) {
        $repo_info_response = wp_remote_get(
            "https://api.github.com/repos/{$owner}/{$repo}",
            ['headers' => $base_headers + ['Accept' => 'application/vnd.github+json'], 'timeout' => 15]
        );
        $default_branch = 'main';
        if (!is_wp_error($repo_info_response) && 200 === wp_remote_retrieve_response_code($repo_info_response)) {
            $info = json_decode(wp_remote_retrieve_body($repo_info_response), true);
            $default_branch = $info['default_branch'] ?? 'main';
        }
        $download_url = "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$default_branch}";
    }

    // Inject Authorization on all GitHub requests during install
    $auth_filter = null;
    if ($github_token) {
        $auth_filter = function ($args, $url) use ($github_token, $download_url) {
            $is_github = strpos($url, 'github.com') !== false || strpos($url, 'githubusercontent.com') !== false;
            if (!$is_github) {
                return $args;
            }
            $args['headers']['Authorization'] = 'Bearer ' . $github_token;
            $args['headers']['User-Agent'] = 'ET-Agent/' . ET_AGENT_VERSION;
            if ($url === $download_url && strpos($url, 'releases/assets/') !== false) {
                $args['headers']['Accept'] = 'application/octet-stream';
            }
            return $args;
        };
        add_filter('http_request_args', $auth_filter, 10, 2);
    }

    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';

    $skin     = new \WP_Ajax_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);
    $result   = $upgrader->install($download_url);

    if ($auth_filter) {
        remove_filter('http_request_args', $auth_filter, 10);
    }

    if (is_wp_error($result)) {
        return new \WP_REST_Response(['error' => $result->get_error_message()], 500);
    }

    $skin_errors = $skin->get_errors();
    if ($skin_errors && $skin_errors->has_errors()) {
        return new \WP_REST_Response([
            'error'      => $skin_errors->get_error_message(),
            'used_token' => (bool) $github_token,
        ], 409);
    }

    if ($result === false) {
        return new \WP_REST_Response(['error' => 'Installation failed'], 500);
    }

    return new \WP_REST_Response([
        'status'      => 'installed',
        'plugin_info' => $upgrader->plugin_info(),
        'used_token'  => (bool) $github_token,
    ]);
}

/* =========================================================================
   Remote site health test
   ========================================================================= */

function et_agent_site_test(\WP_REST_Request $request): \WP_REST_Response {
    $checks  = [];
    $overall = 'ok';

    // 1. Homepage loads
    $home = wp_remote_get(home_url('/'), ['timeout' => 10, 'sslverify' => false]);
    if (is_wp_error($home)) {
        $checks['homepage'] = ['status' => 'error', 'message' => $home->get_error_message()];
        $overall = 'error';
    } else {
        $code = wp_remote_retrieve_response_code($home);
        $checks['homepage'] = ['status' => ($code === 200) ? 'ok' : 'warning', 'message' => "HTTP {$code}"];
        if ($code !== 200) $overall = 'warning';
    }

    // 2. Admin loads (302 redirect to login is OK, 5xx is error)
    $admin = wp_remote_get(admin_url('/'), ['timeout' => 10, 'sslverify' => false, 'redirection' => 0]);
    if (is_wp_error($admin)) {
        $checks['admin'] = ['status' => 'error', 'message' => $admin->get_error_message()];
        $overall = 'error';
    } else {
        $code = wp_remote_retrieve_response_code($admin);
        $ok = ($code < 500);
        $checks['admin'] = ['status' => $ok ? 'ok' : 'error', 'message' => "HTTP {$code}"];
        if (!$ok) $overall = 'error';
    }

    // 3. Database
    global $wpdb;
    $db_result = $wpdb->get_var("SELECT 1");
    $checks['database'] = [
        'status'  => ($db_result == 1) ? 'ok' : 'error',
        'message' => ($db_result == 1) ? 'Connected' : 'Query failed',
    ];
    if ($db_result != 1) $overall = 'error';

    // 4. Recent fatals in debug.log (last hour)
    $debug_log = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($debug_log) && is_readable($debug_log)) {
        $lines = array_slice(file($debug_log), -50);
        $recent_fatals = 0;
        $one_hour_ago = time() - 3600;
        foreach ($lines as $line) {
            if (stripos($line, 'PHP Fatal') !== false) {
                if (preg_match('/\[(\d{2}-\w{3}-\d{4}\s[\d:]+)\s/', $line, $m)) {
                    $ts = strtotime($m[1]);
                    if ($ts && $ts > $one_hour_ago) $recent_fatals++;
                } else {
                    $recent_fatals++;
                }
            }
        }
        $checks['debug_log'] = [
            'status'  => $recent_fatals > 0 ? 'warning' : 'ok',
            'message' => $recent_fatals > 0 ? "{$recent_fatals} fatal(i) w ostatniej godzinie" : 'Brak błędów',
        ];
        if ($recent_fatals > 0 && $overall === 'ok') $overall = 'warning';
    } else {
        $checks['debug_log'] = ['status' => 'ok', 'message' => 'Brak debug.log'];
    }

    // 5. Active plugins sanity (files exist?)
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $active  = get_option('active_plugins', []);
    $all     = get_plugins();
    $missing = array_diff($active, array_keys($all));
    $checks['plugins'] = [
        'status'       => empty($missing) ? 'ok' : 'warning',
        'message'      => empty($missing)
            ? count($active) . ' aktywnych, wszystkie OK'
            : count($missing) . ' brakujących plików pluginów',
        'active_count' => count($active),
    ];
    if (!empty($missing) && $overall === 'ok') $overall = 'warning';

    return new \WP_REST_Response([
        'status'    => $overall,
        'checks'    => $checks,
        'timestamp' => gmdate('Y-m-d\TH:i:sP'),
    ]);
}

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
        'users_peak'  => get_option('et_agent_users_peak', []),
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

    // Method 1: du -sk (KB — universally supported), convert to MB
    if (function_exists('shell_exec')) {
        $output = @shell_exec("du -sk " . escapeshellarg($home_dir) . " 2>/dev/null");
        if ($output && preg_match('/^(\d+)/', $output, $m)) {
            $used_mb = (int) round((int) $m[1] / 1024);
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

/* --- Users peak tracking (daily cron) --- */
add_action('et_agent_users_peak_cron', 'et_agent_update_users_peak');

function et_agent_update_users_peak(): void {
    $current_count = (int) count_users()['total_users'];
    $month_key = date('Y-m');
    $peak = get_option('et_agent_users_peak', []);
    $peak[$month_key] = max($peak[$month_key] ?? 0, $current_count);
    update_option('et_agent_users_peak', $peak, false);
}

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
   Plugin list — action links (Generate / Regenerate Site Key)
   ========================================================================= */

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function (array $links): array {
    $token = get_option('et_agent_site_token', '');
    $label = $token ? 'Regeneruj Site Key' : 'Generuj Site Key';
    $url   = wp_nonce_url(
        admin_url('plugins.php?et_agent_generate_key=1'),
        'et_agent_generate_key'
    );
    array_unshift($links, sprintf(
        '<a href="%s" style="color:#d63638">%s</a>',
        esc_url($url),
        esc_html($label)
    ));

    $settings_url = admin_url('options-general.php?page=et-agent');
    array_unshift($links, sprintf(
        '<a href="%s">Ustawienia</a>',
        esc_url($settings_url)
    ));

    return $links;
});

add_action('admin_init', function () {
    if (empty($_GET['et_agent_generate_key'])) {
        return;
    }
    check_admin_referer('et_agent_generate_key');
    if (!current_user_can('manage_options')) {
        wp_die('Brak uprawnień.');
    }

    $token = bin2hex(random_bytes(32));
    update_option('et_agent_site_token', $token, false);

    add_settings_error(
        'general',
        'et_agent_key_generated',
        'Nowy Site Key został wygenerowany: ' . $token,
        'updated'
    );
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('plugins.php?settings-updated=true'));
    exit;
});

add_action('admin_notices', function () {
    if (!isset($_GET['settings-updated']) || $_SERVER['SCRIPT_NAME'] !== '/wp-admin/plugins.php') {
        return;
    }
    $errors = get_transient('settings_errors');
    if (!$errors) {
        return;
    }
    delete_transient('settings_errors');
    foreach ($errors as $error) {
        if ($error['code'] === 'et_agent_key_generated') {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($error['message'])
            );
        }
    }
});

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
        'default'           => 'https://crm.etechnologie.info.pl/',
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
                   class="regular-text" placeholder="https://crm.etechnologie.info.pl/" />
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
