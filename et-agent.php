<?php
/**
 * Plugin Name: ET Agent
 * Description: Agent monitorujący instalację WordPress dla CRM eTechnologie
 * Version: 1.6.1
 * Author: eTechnologie
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('ET_AGENT_VERSION', '1.6.1');
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

// Fix: rename source folder to match the currently installed plugin folder
// (e.g. "et-agent" or "et-agent-main" depending on how the plugin was first
// installed — using the active basename keeps Plugin_Upgrader happy).
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra) {
    if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
        return $source;
    }

    $target_dir_name = dirname(plugin_basename(__FILE__));
    if ($target_dir_name === '.' || $target_dir_name === '') {
        $target_dir_name = 'et-agent';
    }
    $expected_dir = trailingslashit($remote_source) . $target_dir_name;

    if (rtrim($source, '/') === rtrim($expected_dir, '/')) {
        return $source;
    }

    global $wp_filesystem;
    if ($wp_filesystem->move($source, $expected_dir)) {
        return trailingslashit($expected_dir);
    }

    return $source;
}, 10, 4);

// Cleanup leftover et-agent-X.Y.Z/ folders that broken upgrades left behind.
// Runs on plugin activation and on each report cron tick.
function et_agent_cleanup_duplicate_folders(): void {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $current_basename = plugin_basename(__FILE__);
    $plugins_dir = WP_PLUGIN_DIR;
    if (!is_dir($plugins_dir)) {
        return;
    }

    foreach (scandir($plugins_dir) as $entry) {
        if (!preg_match('/^et-agent-\d+\.\d+\.\d+$/', $entry)) {
            continue;
        }
        $candidate = $plugins_dir . '/' . $entry;
        if (!is_dir($candidate)) {
            continue;
        }
        // Skip the currently active plugin folder, just in case.
        if (dirname($current_basename) === $entry) {
            continue;
        }
        // Safety: only remove if it really looks like an ET Agent leftover.
        $main_file = $candidate . '/et-agent.php';
        $weird_file = $candidate . '/et-agent\\et-agent.php';
        if (!file_exists($main_file) && !file_exists($weird_file)) {
            continue;
        }
        et_agent_rrmdir($candidate);
    }
}

function et_agent_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? et_agent_rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

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
    if (!wp_next_scheduled('et_agent_disk_measure_cron')) {
        // Stagger across ~2h window to avoid all sites measuring at once
        $jitter = mt_rand(0, 7200);
        wp_schedule_event(time() + $jitter, 'daily', 'et_agent_disk_measure_cron');
    }
    // First-time measurement on activation (in 30s, async)
    if (!wp_next_scheduled('et_agent_disk_measure_now')) {
        wp_schedule_single_event(time() + 30, 'et_agent_disk_measure_now');
    }

    if (!wp_next_scheduled('et_agent_log_rotate_cron')) {
        wp_schedule_event(time() + 300, 'daily', 'et_agent_log_rotate_cron');
    }

    et_agent_cleanup_duplicate_folders();

    // Auto-register with CRM (deferred 10s so REST API is fully bootstrapped)
    if (!wp_next_scheduled('et_agent_register_now')) {
        wp_schedule_single_event(time() + 10, 'et_agent_register_now');
    }
});

add_action('et_agent_register_now', 'et_agent_register_with_crm');

function et_agent_register_with_crm(): void {
    if (get_option('et_agent_registered_at')) {
        return; // already registered
    }

    $token = get_option('et_agent_site_token');
    $crm_url = rtrim(get_option('et_agent_crm_url', 'https://crm.etechnologie.info.pl/'), '/');
    if (!$token || !$crm_url) {
        return;
    }

    $response = wp_remote_post($crm_url . '/api/agent/register', [
        'timeout' => 20,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'body' => wp_json_encode([
            'url' => home_url(),
            'name' => get_bloginfo('name') ?: parse_url(home_url(), PHP_URL_HOST),
            'site_token' => $token,
        ]),
    ]);

    if (is_wp_error($response)) {
        update_option('et_agent_register_error', $response->get_error_message(), false);
        return;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code === 200 || $code === 201) {
        update_option('et_agent_registered_at', gmdate('c'), false);
        delete_option('et_agent_register_error');
    } else {
        $body = wp_remote_retrieve_body($response);
        update_option('et_agent_register_error', "HTTP $code: " . substr($body, 0, 300), false);
    }
}

// Defensive retry: if registration failed, retry on each admin page load (cheap)
add_action('admin_init', function () {
    if (!get_option('et_agent_registered_at') && get_option('et_agent_site_token')) {
        // Retry max once per hour
        $last = (int) get_option('et_agent_register_last_retry', 0);
        if (time() - $last > 3600) {
            update_option('et_agent_register_last_retry', time(), false);
            et_agent_register_with_crm();
        }
    }
});

add_action('et_agent_report_cron', 'et_agent_cleanup_duplicate_folders');

// Defensive scheduling — runs on every request, ensures crons are registered
// even when plugin was upgraded via Plugin_Upgrader (which does NOT call
// register_activation_hook). Cheap: each wp_next_scheduled is a single autoload
// option lookup.
add_action('init', function () {
    if (!wp_next_scheduled('et_agent_report_cron')) {
        wp_schedule_event(time(), 'twicedaily', 'et_agent_report_cron');
    }
    if (!wp_next_scheduled('et_agent_users_peak_cron')) {
        wp_schedule_event(time(), 'daily', 'et_agent_users_peak_cron');
    }
    if (!wp_next_scheduled('et_agent_disk_measure_cron')) {
        $jitter = mt_rand(0, 7200);
        wp_schedule_event(time() + $jitter, 'daily', 'et_agent_disk_measure_cron');
    }
    // First-time measurement if no value recorded yet
    if (get_option('et_agent_disk_used_mb_measured', null) === null
        && !wp_next_scheduled('et_agent_disk_measure_now')) {
        wp_schedule_single_event(time() + 60, 'et_agent_disk_measure_now');
    }
    if (!wp_next_scheduled('et_agent_log_rotate_cron')) {
        wp_schedule_event(time() + 300, 'daily', 'et_agent_log_rotate_cron');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('et_agent_report_cron');
    wp_clear_scheduled_hook('et_agent_users_peak_cron');
    wp_clear_scheduled_hook('et_agent_disk_measure_cron');
    wp_clear_scheduled_hook('et_agent_disk_measure_now');
    wp_clear_scheduled_hook('et_agent_log_rotate_cron');
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

    // GET /error-log?lines=N (default 20, max 200)
    register_rest_route($namespace, '/error-log', [
        'methods'             => 'GET',
        'callback'            => function (\WP_REST_Request $req) {
            $lines = max(1, min(200, (int) $req->get_param('lines') ?: 20));
            return new \WP_REST_Response(et_agent_collect_error_logs($lines));
        },
        'permission_callback' => $permission,
    ]);

    // POST /maintenance/enable - body: title, message, contact_email
    register_rest_route($namespace, '/maintenance/enable', [
        'methods'             => 'POST',
        'callback'            => 'et_agent_maintenance_enable',
        'permission_callback' => $permission,
    ]);

    // POST /maintenance/disable
    register_rest_route($namespace, '/maintenance/disable', [
        'methods'             => 'POST',
        'callback'            => 'et_agent_maintenance_disable',
        'permission_callback' => $permission,
    ]);

    // GET /maintenance/status
    register_rest_route($namespace, '/maintenance/status', [
        'methods'             => 'GET',
        'callback'            => 'et_agent_maintenance_status',
        'permission_callback' => $permission,
    ]);

    register_rest_route($namespace, '/install-plugin', [
        'methods'             => 'POST',
        'callback'            => 'et_agent_install_plugin',
        'permission_callback' => $permission,
    ]);

    register_rest_route($namespace, '/update-plugin', [
        'methods'             => 'POST',
        'callback'            => 'et_agent_update_plugin',
        'permission_callback' => $permission,
    ]);

    register_rest_route($namespace, '/delete-plugin', [
        'methods'             => 'POST',
        'callback'            => 'et_agent_delete_plugin',
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

    register_rest_route($namespace, '/disk-measure', [
        'methods'             => 'POST',
        'callback'            => function () {
            $mb = et_agent_measure_disk_usage();
            return new \WP_REST_Response([
                'used_mb'      => $mb,
                'measured_at'  => get_option('et_agent_disk_measured_at', null),
                'elapsed_s'    => get_option('et_agent_disk_measured_elapsed_s', null),
            ], $mb === null ? 500 : 200);
        },
        'permission_callback' => $permission,
    ]);
});

/* =========================================================================
   Error log collector - tail recent N lines from WP debug.log + php-error.log
   ========================================================================= */
function et_agent_tail_file(string $path, int $lines): ?array {
    if (!is_readable($path)) return null;
    $size = @filesize($path);
    if ($size === false) return null;

    // Read at most last 512 KB to avoid memory bloat on huge logs
    $max_bytes = 512 * 1024;
    $offset = max(0, $size - $max_bytes);
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    fseek($fh, $offset);
    $chunk = stream_get_contents($fh);
    fclose($fh);
    if ($chunk === false) return null;

    // If we started mid-line, drop the first partial line
    if ($offset > 0) {
        $nl = strpos($chunk, "\n");
        if ($nl !== false) $chunk = substr($chunk, $nl + 1);
    }

    $rows = preg_split("/\r\n|\n|\r/", rtrim($chunk, "\r\n"));
    if (count($rows) > $lines) $rows = array_slice($rows, -$lines);

    return [
        'path'      => $path,
        'size'      => $size,
        'truncated' => $size > $max_bytes,
        'lines'     => array_values($rows),
    ];
}

function et_agent_collect_error_logs(int $lines): array {
    $sources = [];

    // 1. WP_DEBUG_LOG: wp-content/debug.log (or custom if WP_DEBUG_LOG points to a path)
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $candidate = is_string(WP_DEBUG_LOG)
            ? WP_DEBUG_LOG
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/debug.log' : null);
        if ($candidate) $sources['wp_debug_log'] = $candidate;
    } elseif (defined('WP_CONTENT_DIR') && is_file(WP_CONTENT_DIR . '/debug.log')) {
        // WP_DEBUG_LOG off but file exists - still report it
        $sources['wp_debug_log'] = WP_CONTENT_DIR . '/debug.log';
    }

    // 2. php error_log from ini (host-level, often ~/logs/php-error.log on JDM)
    $ini_log = @ini_get('error_log');
    if ($ini_log && is_string($ini_log) && $ini_log !== '' && @is_readable($ini_log)) {
        $sources['php_error_log'] = $ini_log;
    }

    $result = ['lines_requested' => $lines, 'logs' => []];
    foreach ($sources as $key => $path) {
        $tail = et_agent_tail_file($path, $lines);
        if ($tail) $result['logs'][$key] = $tail;
        else $result['logs'][$key] = ['path' => $path, 'error' => 'unreadable or missing'];
    }

    if (empty($result['logs'])) {
        $result['note'] = 'No log sources detected. Check WP_DEBUG_LOG and php.ini error_log.';
    }
    return $result;
}

/* =========================================================================
   Maintenance mode - drop-in + .maintenance marker dla zawieszonych instalacji
   ========================================================================= */
function et_agent_maintenance_enable(\WP_REST_Request $req) {
    $title = sanitize_text_field((string) $req->get_param('title')) ?: 'Strona czasowo niedostępna';
    $message = (string) $req->get_param('message');
    if ($message === '') {
        $message = 'Strona jest tymczasowo niedostępna. Prosimy o kontakt z administratorem.';
    }
    $contact_email = sanitize_email((string) $req->get_param('contact_email'));

    $dropin_path = WP_CONTENT_DIR . '/maintenance.php';
    $marker_path = ABSPATH . '.maintenance';

    // Get site token to allow drop-in bypass for disable endpoint
    $site_token = get_option('et_agent_site_token', '');
    if (!$site_token) {
        return new \WP_REST_Response(['error' => 'Missing et_agent_site_token option'], 500);
    }

    // Drop-in: custom HTML 503 z brandingiem + bypass dla /maintenance/disable
    $dropin = et_agent_render_maintenance_dropin($title, $message, $contact_email, $site_token);
    if (false === @file_put_contents($dropin_path, $dropin)) {
        return new \WP_REST_Response(['error' => 'Cannot write drop-in', 'path' => $dropin_path], 500);
    }
    @chmod($dropin_path, 0644);

    // Marker: .maintenance z $upgrading = NOW + rok (NIE expire automatycznie)
    $year_ahead = time() + (365 * 24 * 3600);
    $marker = "<?php\n\$upgrading = {$year_ahead}; // ET Agent maintenance mode (no auto-expiry)\n";
    if (false === @file_put_contents($marker_path, $marker)) {
        @unlink($dropin_path);
        return new \WP_REST_Response(['error' => 'Cannot write .maintenance marker', 'path' => $marker_path], 500);
    }
    @chmod($marker_path, 0644);

    update_option('et_agent_maintenance_enabled_at', gmdate('c'));
    update_option('et_agent_maintenance_message', $message);
    update_option('et_agent_maintenance_contact', $contact_email);

    // Auto-purge JDM nginx fastcgi cache (bez tego cached HTTP 200 nadpisuje 503)
    $purge_result = et_agent_purge_host_cache();

    return new \WP_REST_Response([
        'enabled' => true,
        'dropin' => $dropin_path,
        'marker' => $marker_path,
        'enabled_at' => get_option('et_agent_maintenance_enabled_at'),
        'cache_purge' => $purge_result,
    ]);
}

function et_agent_maintenance_disable() {
    $marker_path = ABSPATH . '.maintenance';
    $dropin_path = WP_CONTENT_DIR . '/maintenance.php';

    $marker_removed = false;
    $dropin_removed = false;
    if (file_exists($marker_path)) $marker_removed = @unlink($marker_path);
    if (file_exists($dropin_path)) $dropin_removed = @unlink($dropin_path);

    delete_option('et_agent_maintenance_enabled_at');
    delete_option('et_agent_maintenance_message');
    delete_option('et_agent_maintenance_contact');

    // Auto-purge JDM nginx fastcgi cache (bez tego cached 503 nadpisuje 302)
    $purge_result = et_agent_purge_host_cache();

    return new \WP_REST_Response([
        'disabled' => true,
        'marker_removed' => $marker_removed,
        'dropin_removed' => $dropin_removed,
        'cache_purge' => $purge_result,
    ]);
}

function et_agent_maintenance_status() {
    $marker_path = ABSPATH . '.maintenance';
    $dropin_path = WP_CONTENT_DIR . '/maintenance.php';
    return new \WP_REST_Response([
        'enabled' => file_exists($marker_path),
        'marker_exists' => file_exists($marker_path),
        'dropin_exists' => file_exists($dropin_path),
        'enabled_at' => get_option('et_agent_maintenance_enabled_at'),
        'message' => get_option('et_agent_maintenance_message'),
        'contact' => get_option('et_agent_maintenance_contact'),
    ]);
}

/**
 * Host-specific cache purge. Currently supports JDM nginx fastcgi cache via
 * their local API (http://localhost:11597) authenticated with JDM_AUTH_TOKEN
 * defined in wp-config.php. Silently no-ops on other hostings.
 */
function et_agent_purge_host_cache(): array {
    // JDM hosting
    if (defined('JDM_AUTH_TOKEN') && JDM_AUTH_TOKEN) {
        $uid = function_exists('posix_getuid') ? (string) posix_getuid() : '0';
        $ch = curl_init('http://localhost:11597/wp/cache/purge');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['pattern' => '/']),
            CURLOPT_HTTPHEADER => [
                'X-JDM-UID: ' . $uid,
                'X-JDM-TOKEN: ' . trim(JDM_AUTH_TOKEN),
                'User-Agent: JDM WP Plugin',
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [
            'host' => 'jdm',
            'http_code' => $code,
            'response' => $body ? substr($body, 0, 200) : null,
            'ok' => $code === 200 && strpos((string) $body, 'success') !== false,
        ];
    }

    return ['host' => 'unknown', 'ok' => false, 'note' => 'No supported cache layer detected'];
}

function et_agent_render_maintenance_dropin(string $title, string $message, string $contact_email, string $site_token): string {
    $title_html = esc_html($title);
    $message_html = nl2br(esc_html($message));
    $contact_block = '';
    if ($contact_email) {
        $email_esc = esc_attr($contact_email);
        $email_html = esc_html($contact_email);
        $contact_block = '<p class="contact">Kontakt: <a href="mailto:' . $email_esc . '">' . $email_html . '</a></p>';
    }
    // Hardcoded into drop-in so /maintenance/disable can self-authorize without WP REST
    $token_escaped = addslashes($site_token);

    return <<<PHP
<?php
// Generated by ET Agent (et-agent plugin) - do not edit by hand.
// Removal: POST /wp-json/et-agent/v1/maintenance/disable z naglowkiem X-ET-Agent-Token

// === Bypass: gdy CRM wywoluje /maintenance/disable, ten drop-in samodzielnie wylacza maintenance ===
\$__et_uri = \$_SERVER['REQUEST_URI'] ?? '';
\$__et_auth = \$_SERVER['HTTP_X_ET_AGENT_TOKEN'] ?? '';
\$__et_token = '{$token_escaped}';
if (\$__et_auth === \$__et_token && \$__et_token !== '') {
    if (strpos(\$__et_uri, '/wp-json/et-agent/v1/maintenance/disable') !== false) {
        \$marker = ABSPATH . '.maintenance';
        \$dropin = __FILE__;
        \$marker_removed = file_exists(\$marker) ? @unlink(\$marker) : false;
        \$dropin_removed = @unlink(\$dropin);

        // JDM cache purge inline - load wp-config to get JDM_AUTH_TOKEN
        \$purge_ok = false;
        \$wp_config = ABSPATH . 'wp-config.php';
        if (file_exists(\$wp_config)) {
            \$config_src = @file_get_contents(\$wp_config);
            if (\$config_src && preg_match('/JDM_AUTH_TOKEN\\W+([a-f0-9]{20,80})/i', \$config_src, \$m)) {
                \$jdm_token = \$m[1];
                \$uid = function_exists('posix_getuid') ? (string) posix_getuid() : '0';
                \$ch = curl_init('http://localhost:11597/wp/cache/purge');
                curl_setopt_array(\$ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['pattern' => '/']),
                    CURLOPT_HTTPHEADER => ['X-JDM-UID: '.\$uid, 'X-JDM-TOKEN: '.\$jdm_token, 'User-Agent: JDM WP Plugin', 'Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                ]);
                \$resp = curl_exec(\$ch);
                curl_close(\$ch);
                \$purge_ok = \$resp && strpos(\$resp, 'success') !== false;
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['disabled' => true, 'method' => 'dropin_bypass', 'marker_removed' => \$marker_removed, 'dropin_removed' => \$dropin_removed, 'cache_purge_ok' => \$purge_ok]);
        exit;
    }
    if (strpos(\$__et_uri, '/wp-json/et-agent/v1/maintenance/status') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['enabled' => true, 'method' => 'dropin_bypass', 'marker_exists' => file_exists(ABSPATH . '.maintenance'), 'dropin_exists' => true]);
        exit;
    }
}

// === 503 maintenance page dla wszystkich pozostalych requestow (style: etechnologie.pl) ===
\$protocol = (!empty(\$_SERVER['SERVER_PROTOCOL']) && in_array(\$_SERVER['SERVER_PROTOCOL'], ['HTTP/1.1','HTTP/2','HTTP/2.0'], true))
    ? \$_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0';
header(\$protocol . ' 503 Service Unavailable', true, 503);
header('Content-Type: text/html; charset=utf-8');
header('Retry-After: 3600');
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>{$title_html}</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: 'Inter', -apple-system, "Segoe UI", Roboto, sans-serif; background: #f8fafc; margin: 0; min-height: 100vh; display: flex; flex-direction: column; color: #0f172a; -webkit-font-smoothing: antialiased; }
  header.site { padding: 24px 32px; border-bottom: 1px solid #e2e8f0; background: #ffffff; text-align: center; }
  header.site a { display: inline-block; }
  header.site img { display: block; height: 43px; width: auto; }
  main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 48px 24px; }
  .card { max-width: 560px; width: 100%; text-align: center; }
  h1 { margin: 0 0 16px; font-size: 32px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.2; color: #0f172a; }
  p { color: #475569; line-height: 1.65; margin: 0 0 16px; font-size: 17px; }
  p.contact { margin-top: 28px; padding-top: 24px; border-top: 1px solid #e2e8f0; font-size: 15px; color: #64748b; }
  a { color: #0f172a; text-decoration: none; font-weight: 600; border-bottom: 2px solid #f7941d; transition: color 0.15s; }
  a:hover { color: #f7941d; }
  .offer { margin-top: 32px; display: inline-flex; align-items: center; gap: 8px; padding: 14px 28px; background: #f7941d; color: white; border-radius: 8px; font-weight: 600; font-size: 16px; border-bottom: 0; transition: background 0.15s; }
  .offer:hover { background: #e07f0e; color: white; }
  .offer svg { width: 18px; height: 18px; }
  @media (max-width: 480px) {
    header.site { padding: 16px 20px; }
    header.site img { height: 36px; }
    h1 { font-size: 26px; }
    p { font-size: 16px; }
  }
</style>
</head>
<body>
  <header class="site">
    <a href="https://etechnologie.pl/" rel="noopener" style="border:0">
      <img src="https://etechnologie.pl/img/logo_etechnologie-tagline.svg" alt="eTechnologie" width="190" height="43">
    </a>
  </header>
  <main>
    <div class="card">
      <h1>{$title_html}</h1>
      <p>{$message_html}</p>
      <a class="offer" href="https://etechnologie.pl/platforma-elearningowa/" rel="noopener">
        Informacje na temat naszej oferty
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </a>
      {$contact_block}
    </div>
  </main>
</body>
</html>
PHP;
}

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
    $force = !empty($body['force']);
    $github_token = $body['github_token']
        ?? (defined('ET_AGENT_GITHUB_TOKEN') ? ET_AGENT_GITHUB_TOKEN : '')
        ?: get_option('et_agent_github_token', '');

    if (!preg_match('#^https://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#', $github_url, $matches)) {
        return new \WP_REST_Response(['error' => 'Invalid GitHub URL'], 400);
    }

    $owner = $matches[1];
    $repo  = $matches[2];

    $default_trusted = ['kkwasniewski-eng', 'eTechnologie-org'];
    $trusted_owners = defined('ET_AGENT_GITHUB_TRUSTED_OWNERS')
        ? array_map('trim', explode(',', ET_AGENT_GITHUB_TRUSTED_OWNERS))
        : $default_trusted;
    $trusted_owners = apply_filters('et_agent_trusted_github_owners', $trusted_owners);

    if (!in_array($owner, $trusted_owners, true)) {
        return new \WP_REST_Response([
            'error' => "Repo owner '{$owner}' nie jest na whitelist. Dozwolone: " . implode(', ', $trusted_owners),
        ], 403);
    }

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
        $auth_filter = function ($args, $url) use ($github_token) {
            $is_github = strpos($url, 'github.com') !== false || strpos($url, 'githubusercontent.com') !== false;
            if (!$is_github) {
                return $args;
            }
            $args['headers']['Authorization'] = 'Bearer ' . $github_token;
            $args['headers']['User-Agent'] = 'ET-Agent/' . ET_AGENT_VERSION;
            // GitHub API releases/assets endpoint zwraca JSON bez tego headera (a my chcemy binary).
            // Equality check on $download_url zawodzi bo WP normalizuje URL przed http_request_args.
            if (strpos($url, '/releases/assets/') !== false) {
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

    // Force source folder name to match repo name (zipball-based downloads have hash-suffix folders)
    $rename_filter = function ($source, $remote_source) use ($repo) {
        global $wp_filesystem;
        $current = basename(rtrim($source, '/\\'));
        if ($current === $repo) return $source;
        $new_source = trailingslashit($remote_source) . $repo . '/';
        if ($wp_filesystem && $wp_filesystem->move($source, $new_source, true)) {
            return $new_source;
        }
        return $source;
    };
    add_filter('upgrader_source_selection', $rename_filter, 10, 2);

    $skin     = new \WP_Ajax_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);
    $install_args = $force
        ? ['overwrite_package' => true, 'clear_destination' => true, 'abort_if_destination_exists' => false]
        : [];
    $result   = $upgrader->install($download_url, $install_args);

    remove_filter('upgrader_source_selection', $rename_filter, 10);

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
        'forced'      => $force,
    ]);
}

/* =========================================================================
   Remote plugin update (native WP source - works for premium plugins like Gravity Forms)
   ========================================================================= */

function et_agent_update_plugin(\WP_REST_Request $request): \WP_REST_Response {
    $body = $request->get_json_params();
    $plugin_basename = $body['plugin_basename'] ?? '';

    if (!preg_match('#^[a-zA-Z0-9_\-]+/[a-zA-Z0-9_\-]+\.php$#', $plugin_basename)) {
        return new \WP_REST_Response(['error' => 'Invalid plugin_basename (expected format: dir/file.php)'], 400);
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/update.php';

    $all_plugins = get_plugins();
    if (!isset($all_plugins[$plugin_basename])) {
        return new \WP_REST_Response(['error' => "Plugin '{$plugin_basename}' nie jest zainstalowany"], 404);
    }
    $old_version = $all_plugins[$plugin_basename]['Version'] ?? '?';

    // Force refresh updates transient (premium plugins like Gravity Forms register their own update_plugins filter)
    wp_clean_plugins_cache(true);
    wp_update_plugins();

    $update_plugins = get_site_transient('update_plugins');
    if (!isset($update_plugins->response[$plugin_basename])) {
        return new \WP_REST_Response([
            'status'      => 'no-update',
            'plugin'      => $plugin_basename,
            'old_version' => $old_version,
            'message'     => 'Brak dostępnego update (już aktualna wersja lub brak license)',
        ]);
    }

    $available_version = $update_plugins->response[$plugin_basename]->new_version ?? '?';

    $skin     = new \WP_Ajax_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);
    $result   = $upgrader->upgrade($plugin_basename);

    if (is_wp_error($result)) {
        return new \WP_REST_Response(['error' => $result->get_error_message(), 'old_version' => $old_version], 500);
    }

    $skin_errors = $skin->get_errors();
    if ($skin_errors && $skin_errors->has_errors()) {
        return new \WP_REST_Response([
            'error'       => $skin_errors->get_error_message(),
            'old_version' => $old_version,
        ], 409);
    }

    if ($result === false) {
        return new \WP_REST_Response(['error' => 'Update failed', 'old_version' => $old_version], 500);
    }

    // Re-read installed version to confirm
    wp_clean_plugins_cache(true);
    $all_plugins_after = get_plugins();
    $new_version = $all_plugins_after[$plugin_basename]['Version'] ?? $available_version;

    return new \WP_REST_Response([
        'status'      => 'updated',
        'plugin'      => $plugin_basename,
        'old_version' => $old_version,
        'new_version' => $new_version,
    ]);
}

function et_agent_delete_plugin(\WP_REST_Request $request): \WP_REST_Response {
    $body = $request->get_json_params();
    $slug = sanitize_key($body['slug'] ?? '');

    if (empty($slug)) {
        return new \WP_REST_Response(['error' => 'Missing slug'], 400);
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $all_plugins = get_plugins();
    $plugin_file = null;
    foreach ($all_plugins as $file => $data) {
        if (dirname($file) === $slug) {
            $plugin_file = $file;
            break;
        }
    }

    if (!$plugin_file) {
        return new \WP_REST_Response(['status' => 'not_found', 'slug' => $slug], 200);
    }

    if (is_plugin_active($plugin_file)) {
        deactivate_plugins($plugin_file);
    }

    WP_Filesystem();
    $result = delete_plugins([$plugin_file]);

    if (is_wp_error($result)) {
        return new \WP_REST_Response(['error' => $result->get_error_message()], 500);
    }

    return new \WP_REST_Response(['status' => 'deleted', 'plugin' => $plugin_file]);
}

/* =========================================================================
   Plugin row "Sprawdź aktualizacje" link + force-check transient invalidation
   ========================================================================= */

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = wp_nonce_url(admin_url('admin-post.php?action=et_agent_force_check'), 'et_agent_force_check');
    $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Sprawdź aktualizacje', 'et-agent') . '</a>';
    return $links;
});

add_action('admin_post_et_agent_force_check', function () {
    if (!current_user_can('update_plugins')) {
        wp_die('Forbidden');
    }
    check_admin_referer('et_agent_force_check');
    delete_transient('et_agent_latest_release');
    delete_site_transient('update_plugins');
    wp_update_plugins();
    wp_safe_redirect(admin_url('update-core.php?force-check=1&et_agent_checked=1'));
    exit;
});

// When WP itself is asked for force-check, also drop our cache so the next
// pre_set_site_transient_update_plugins fires a fresh GitHub query.
add_action('admin_init', function () {
    if (isset($_GET['force-check']) && current_user_can('update_plugins')) {
        delete_transient('et_agent_latest_release');
    }
});

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
            'basename'         => $file,
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
    $ours_count  = function_exists('et_agent_count_ours_users') ? et_agent_count_ours_users() : 0;
    $users_real  = max(0, $users_count - $ours_count);

    return [
        'wp_version'       => get_bloginfo('version'),
        'php_version'      => PHP_VERSION,
        'plugins'          => $plugins,
        'theme'            => [
            'name'    => $theme->get('Name'),
            'version' => $theme->get('Version'),
        ],
        'disk'             => $disk,
        'users_count'      => $users_count,
        'users_peak'       => get_option('et_agent_users_peak', []),
        // NEW od 1.5.0 — real = total - konta @etechnologie.pl (serwisowe).
        'users_ours_count' => $ours_count,
        'users_real_count' => $users_real,
        'users_real_peak'  => get_option('et_agent_users_real_peak', []),
        'site_url'         => get_site_url(),
        'admin_email'      => get_option('admin_email'),
    ];
}

/* =========================================================================
   Disk usage — measured by cron, read from option
   ========================================================================= */

function et_agent_get_disk_usage(): array {
    $total_mb = (int) get_option('et_agent_disk_quota_mb', 0) ?: null;

    $measured = get_option('et_agent_disk_used_mb_measured', null);
    $used_mb = ($measured !== null && $measured !== '') ? (int) $measured : null;

    // Fallback for hosts where shell_exec is enabled (VPS, dedicated) —
    // tries du -sk on demand. Disabled functions on shared hosting (jdm.pl)
    // skip this branch silently.
    if ($used_mb === null && function_exists('shell_exec')) {
        $home_dir = getenv('HOME') ?: dirname(dirname(ABSPATH));
        $output = @shell_exec("du -sk " . escapeshellarg($home_dir) . " 2>/dev/null");
        if ($output && preg_match('/^(\d+)/', $output, $m)) {
            $used_mb = (int) round((int) $m[1] / 1024);
        }
    }

    // We deliberately DO NOT fall back to disk_total_space() / disk_free_space()
    // on shared hosting they report the entire partition (TB scale) and CRM
    // would mistake it for client data.

    $free_mb = ($total_mb && $used_mb !== null) ? max(0, $total_mb - $used_mb) : null;

    return [
        'total_mb' => $total_mb,
        'used_mb'  => $used_mb,
        'free_mb'  => $free_mb,
        'measured_at' => get_option('et_agent_disk_measured_at', null),
    ];
}

function et_agent_measure_disk_usage(): ?int {
    // Build list of paths to scan, respecting open_basedir restrictions
    // common on shared hosting (e.g. jdm.pl).
    $candidates = [];
    $home_dir = getenv('HOME') ?: '';
    if ($home_dir && is_dir($home_dir) && is_readable($home_dir)) {
        $candidates[] = rtrim($home_dir, '/\\');
    } else {
        // Fallback: scan known user subdirs that open_basedir typically allows.
        // ABSPATH = .../public_html/ → parent is the user home root.
        $public_html = rtrim(ABSPATH, '/\\');
        $maybe_home = dirname($public_html);
        foreach ([$public_html, $maybe_home . '/logs', $maybe_home . '/.tmp'] as $p) {
            if (is_dir($p) && is_readable($p)) {
                $candidates[] = $p;
            }
        }
    }

    if (empty($candidates)) {
        update_option('et_agent_disk_measure_error', 'no readable paths', false);
        return null;
    }

    @set_time_limit(0);
    @ignore_user_abort(true);

    $bytes = 0;
    $files = 0;
    $started = microtime(true);

    foreach ($candidates as $dir) {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $dir,
                    \RecursiveDirectoryIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $file) {
                try {
                    if ($file->isFile() && !$file->isLink()) {
                        $bytes += $file->getSize();
                        $files++;
                    }
                } catch (\Throwable $e) {
                    // skip unreadable file
                }
            }
        } catch (\Throwable $e) {
            // skip unreadable root dir
        }
    }

    $mb = (int) round($bytes / 1048576);
    $elapsed = round(microtime(true) - $started, 1);

    update_option('et_agent_disk_used_mb_measured', $mb, false);
    update_option('et_agent_disk_measured_at', current_time('mysql'), false);
    update_option('et_agent_disk_measured_elapsed_s', $elapsed, false);
    update_option('et_agent_disk_measured_paths', implode(',', $candidates), false);
    update_option('et_agent_disk_measured_files', $files, false);
    delete_option('et_agent_disk_measure_error');

    return $mb;
}

add_action('et_agent_disk_measure_cron', 'et_agent_measure_disk_usage');
add_action('et_agent_disk_measure_now', 'et_agent_measure_disk_usage');

/* =========================================================================
   Log rotation — dzienna rotacja (gzip) przerośniętych logów.
   Zapobiega "runaway log" (np. uploads/wp-vimeo-videos/debug.log puchnący do GB).
   Próg 20 MB → kompresja do *.1.gz, oryginał usuwany (wtyczka odtworzy plik).
   ========================================================================= */

if (!defined('ET_AGENT_LOG_ROTATE_MAX')) {
    define('ET_AGENT_LOG_ROTATE_MAX', 20971520); // 20 MB
}

add_action('et_agent_log_rotate_cron', 'et_agent_rotate_logs');

function et_agent_log_rotate_targets(): array {
    $targets = [];
    if (defined('WP_CONTENT_DIR')) {
        $targets[] = WP_CONTENT_DIR . '/debug.log';
    }
    if (function_exists('wp_get_upload_dir')) {
        $up = wp_get_upload_dir();
        if (!empty($up['basedir'])) {
            $targets[] = trailingslashit($up['basedir']) . 'wp-vimeo-videos/debug.log';
        }
    }
    return apply_filters('et_agent_log_rotate_targets', $targets);
}

function et_agent_rotate_logs(): void {
    $max = ET_AGENT_LOG_ROTATE_MAX;
    foreach (et_agent_log_rotate_targets() as $f) {
        if (!is_string($f) || !is_file($f) || !is_writable($f)) {
            continue;
        }
        $size = @filesize($f);
        if ($size === false || $size < $max) {
            continue;
        }

        $rotated = false;
        if (function_exists('gzopen')) {
            $gz = $f . '.1.gz';
            @unlink($gz);
            $in  = @fopen($f, 'rb');
            $out = @gzopen($gz, 'wb9');
            if ($in && $out) {
                while (!feof($in)) {
                    gzwrite($out, fread($in, 262144));
                }
                fclose($in);
                gzclose($out);
                @unlink($f); // wtyczka odtworzy plik przy kolejnym zapisie
                $rotated = true;
            } else {
                if ($in) { fclose($in); }
                if ($out) { gzclose($out); }
            }
        }
        if (!$rotated) {
            $rotated = @rename($f, $f . '.1');
        }
        if ($rotated) {
            error_log('[et-agent] log-rotate: ' . $f . ' (' . $size . ' B)');
        }
    }
}

/* =========================================================================
   Simple History — retencja zdarzeń 90 dni (free default = 60).
   No-op gdy Simple History nie jest zainstalowane.
   ========================================================================= */

add_filter('simple_history/db_purge_days_interval', 'et_agent_sh_retention_days');
add_filter('simple_history_db_purge_days_interval', 'et_agent_sh_retention_days'); // deprecated alias

function et_agent_sh_retention_days($days) {
    return 90;
}

/* =========================================================================
   Endpoint callbacks
   ========================================================================= */

function et_agent_get_report(\WP_REST_Request $request): \WP_REST_Response {
    return new \WP_REST_Response(et_agent_collect_report());
}

function et_agent_auto_login(\WP_REST_Request $request): \WP_REST_Response {
    $body = $request->get_json_params();

    if (empty($body['user_id'])) {
        return new \WP_REST_Response(['error' => 'user_id parameter required'], 400);
    }

    $user_id = (int) $body['user_id'];
    if ($user_id < 1) {
        return new \WP_REST_Response(['error' => 'Invalid user_id'], 400);
    }

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

if (!function_exists('et_agent_count_ours_users')) {
    /**
     * Liczba kont WP konczacych sie na @etechnologie.pl (konta serwisowe/adminow ET).
     * Uzywane do wyliczenia real users = total - ours w raporcie peak (od v1.5.0).
     * Defensive: zwraca 0 gdy $wpdb niedostepny lub zapytanie pada — fatal w
     * helperze nie moze zabic daily cron-a na ~60 instalacjach.
     */
    function et_agent_count_ours_users(): int {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return 0;
        }
        try {
            // Suffix match (konczy sie na @etechnologie.pl) — bez false-positives typu
            // 'x@etechnologie.pl@evil.com'. Aliasy + foo+bar@etechnologie.pl dalej OK.
            $like = '%' . $wpdb->esc_like('@etechnologie.pl');
            $sql  = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE %s",
                $like
            );
            $count = $wpdb->get_var($sql);
            return ($count === null) ? 0 : (int) $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

function et_agent_update_users_peak(): void {
    $current_count = (int) count_users()['total_users'];
    $ours_count    = function_exists('et_agent_count_ours_users') ? et_agent_count_ours_users() : 0;
    $real_count    = max(0, $current_count - $ours_count);

    $month_key = date('Y-m');

    // Legacy total peak (backward compat — CRM nadal czyta to pole).
    $peak = get_option('et_agent_users_peak', []);
    if (!is_array($peak)) { $peak = []; }
    $peak[$month_key] = max((int) ($peak[$month_key] ?? 0), $current_count);
    update_option('et_agent_users_peak', $peak, false);

    // NEW od 1.5.0: real peak (total - konta @etechnologie.pl).
    $real_peak = get_option('et_agent_users_real_peak', []);
    if (!is_array($real_peak)) { $real_peak = []; }
    $real_peak[$month_key] = max((int) ($real_peak[$month_key] ?? 0), $real_count);
    update_option('et_agent_users_real_peak', $real_peak, false);
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
   Sentry error monitoring
   ========================================================================= */

define( 'ET_AGENT_SENTRY_MU_VERSION', '1.0.0' );
define( 'ET_AGENT_SENTRY_DSN_DEFAULT', 'https://5609d86d3ad1a0ec6eaa29a2dc463c8b@o4511619328770048.ingest.de.sentry.io/4511619343319120' );

function et_agent_find_wpconfig(): string {
    $a = ABSPATH . 'wp-config.php';
    if ( file_exists( $a ) ) return $a;
    $b = dirname( ABSPATH ) . '/wp-config.php';
    if ( file_exists( $b ) ) return $b;
    return '';
}

function et_agent_sentry_active(): bool {
    $path = et_agent_find_wpconfig();
    if ( ! $path ) return false;
    $src = @file_get_contents( $path );
    return $src !== false && (bool) preg_match(
        "/define\s*\(\s*['\"]ETECHNOLOGIE_SENTRY_DSN['\"].*\)/",
        $src
    );
}

function et_agent_sentry_write( string $dsn ): bool {
    $path = et_agent_find_wpconfig();
    if ( ! $path || ! is_writable( $path ) ) return false;
    $src = file_get_contents( $path );
    if ( $src === false ) return false;
    $src = preg_replace( "/^[^\n]*define\s*\(\s*['\"]ETECHNOLOGIE_SENTRY_DSN['\"][^\n]*\n?/m", '', $src );
    if ( $dsn !== '' ) {
        $line = "define( 'ETECHNOLOGIE_SENTRY_DSN', '" . addslashes( $dsn ) . "' ); // et-agent\n";
        foreach ( [ "/* That's all, stop editing!", '/* Stop editing!', '/** Absolute path' ] as $marker ) {
            $pos = strpos( $src, $marker );
            if ( $pos !== false ) {
                $src = substr_replace( $src, $line, $pos, 0 );
                break;
            }
        }
    }
    return file_put_contents( $path, $src ) !== false;
}

// Auto-install et-sentry mu-plugin on first admin pageload
add_action( 'admin_init', static function (): void {
    if ( get_option( 'et_sentry_mu_version' ) === ET_AGENT_SENTRY_MU_VERSION ) return;
    $mu_dir  = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    $bundled = plugin_dir_path( __FILE__ ) . 'mu-plugins-src/et-sentry.php';
    if ( ! is_readable( $bundled ) ) return;
    if ( ! is_dir( $mu_dir ) ) { @mkdir( $mu_dir, 0755, true ); }
    if ( ! is_dir( $mu_dir ) || ! is_writable( $mu_dir ) ) return;
    $src = file_get_contents( $bundled );
    if ( $src !== false && false !== @file_put_contents( $mu_dir . '/et-sentry.php', $src ) ) {
        update_option( 'et_sentry_mu_version', ET_AGENT_SENTRY_MU_VERSION );
    }
} );

// Full-site ET_Sentry init (no before_send filter — catches errors from all plugins/themes)
add_action( 'plugins_loaded', static function (): void {
    if ( ! defined( 'ETECHNOLOGIE_SENTRY_DSN' ) || ! ETECHNOLOGIE_SENTRY_DSN ) return;
    if ( ! defined( 'ET_SENTRY_LOADED' ) ) return;
    if ( defined( 'ETECHNOLOGIE_SENTRY_INITIALIZED' ) ) return;
    define( 'ETECHNOLOGIE_SENTRY_INITIALIZED', true );
    ET_Sentry::init( [
        'dsn'         => (string) ETECHNOLOGIE_SENTRY_DSN,
        'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
        'release'     => 'wordpress@' . get_bloginfo( 'version' ),
    ] );
    ET_Sentry::setTag( 'site', (string) wp_parse_url( get_site_url(), PHP_URL_HOST ) );
}, 1 );

// Handle Sentry enable/disable form
add_action( 'admin_init', static function (): void {
    if ( ! isset( $_POST['et_agent_sentry_action'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    check_admin_referer( 'et_agent_sentry' );
    $action = sanitize_text_field( $_POST['et_agent_sentry_action'] );
    if ( $action === 'enable' ) {
        $dsn = ET_AGENT_SENTRY_DSN_DEFAULT;
        if ( et_agent_sentry_write( $dsn ) ) {
            add_settings_error( 'et_agent_sentry', 'enabled', 'Monitoring Sentry włączony.', 'updated' );
        } else {
            add_settings_error( 'et_agent_sentry', 'wpconfig_fail',
                'Nie można zapisać do wp-config.php (sprawdź uprawnienia). Dodaj ręcznie: <code>define( \'ETECHNOLOGIE_SENTRY_DSN\', \'' . esc_html( $dsn ) . '\' );</code>',
                'error'
            );
        }
    } elseif ( $action === 'disable' ) {
        et_agent_sentry_write( '' );
        add_settings_error( 'et_agent_sentry', 'disabled', 'Monitoring Sentry wyłączony — linia usunięta z wp-config.php.', 'updated' );
    }
} );

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

        <hr />
        <h2>Monitoring błędów (Sentry)</h2>
        <?php settings_errors( 'et_agent_sentry' ); ?>
        <?php $sentry_active = et_agent_sentry_active(); ?>
        <p>Status: <?php echo $sentry_active
            ? '<strong style="color:#46b450">✅ Aktywny</strong>'
            : '<strong style="color:#dc3232">❌ Nieaktywny</strong>'; ?>
        </p>
        <form method="post">
            <?php wp_nonce_field( 'et_agent_sentry' ); ?>
            <p>
                <?php if ( ! $sentry_active ) : ?>
                <button type="submit" name="et_agent_sentry_action" value="enable"
                        class="button button-primary">
                    Włącz monitoring
                </button>
                <?php else : ?>
                <button type="submit" name="et_agent_sentry_action" value="disable"
                        class="button button-secondary"
                        onclick="return confirm('Wyłączyć monitoring Sentry?');">
                    Wyłącz monitoring
                </button>
                <?php endif; ?>
            </p>
        </form>
    </div>
    <?php
}
