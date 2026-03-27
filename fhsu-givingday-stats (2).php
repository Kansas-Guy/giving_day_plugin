<?php
/**
 * Plugin Name: FHSU Giving Day Stats
 * Description: Pulls Giving Day donations from Advance and exposes live stats via REST + shortcode.
 * Version: 0.2.0
 */

if (!defined('ABSPATH')) exit;

class FHSU_GivingDay_Stats {
  const OPT_API_KEY        = 'fhsu_gd_api_key';
  const OPT_START_UNIX     = 'fhsu_gd_start_unix';
  const OPT_LAST_SYNC_UNIX = 'fhsu_gd_last_sync_unix';
  const OPT_CACHE          = 'fhsu_gd_stats_cache';
  const OPT_LAST_SYNC_RUN  = 'fhsu_gd_last_sync_run';
  const OPT_LAST_CRON_RUN  = 'fhsu_gd_last_cron_run';
  const OPT_CRON_TOKEN     = 'fhsu_gd_cron_token';

  const CRON_HOOK = 'fhsu_gd_sync_cron';

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
    add_action('admin_post_fhsu_gd_clear_database', [__CLASS__, 'handle_clear_database']);
    add_shortcode('givingday_donor_wall_db', [__CLASS__, 'shortcode_donor_wall_db']);
    add_shortcode('givingday_stats', [__CLASS__, 'shortcode_stats']);
    add_shortcode('givingday_total_raised', [__CLASS__, 'shortcode_total_raised']);
    add_shortcode('givingday_total_gifts', [__CLASS__, 'shortcode_total_gifts']);
    add_shortcode('givingday_donor_wall', [__CLASS__, 'shortcode_donor_wall']);
    add_shortcode('givingday_leaderboard', [__CLASS__, 'shortcode_leaderboard']);


    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_post_fhsu_gd_save_settings', [__CLASS__, 'handle_save_settings']);
    add_action('admin_post_fhsu_gd_sync_now', [__CLASS__, 'handle_sync_now']);
    add_action('admin_post_fhsu_gd_rebuild_cache', [__CLASS__, 'handle_rebuild_cache']);
    add_action('admin_post_fhsu_gd_reset', [__CLASS__, 'handle_reset']);
    add_action('admin_post_fhsu_gd_generate_cron_token', [__CLASS__, 'handle_generate_cron_token']);
    add_action('admin_post_fhsu_gd_external_cron', [__CLASS__, 'handle_external_cron']);
    add_action('admin_post_nopriv_fhsu_gd_external_cron', [__CLASS__, 'handle_external_cron']);
    add_action('init', [__CLASS__, 'ensure_cron_scheduled']);
    add_action(self::CRON_HOOK, [__CLASS__, 'run_sync']);
    add_filter('cron_schedules', [__CLASS__, 'add_cron_schedules']);
    add_action('admin_post_fhsu_gd_reschedule_cron', [__CLASS__, 'handle_reschedule_cron']);
    register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);
    register_deactivation_hook(__FILE__, [__CLASS__, 'on_deactivate']);
  }
  
  
  public static function reschedule_cron() {
      $ts = wp_next_scheduled(self::CRON_HOOK);
      while ($ts) {
        wp_unschedule_event($ts, self::CRON_HOOK);
        $ts = wp_next_scheduled(self::CRON_HOOK);
      }
    
      wp_schedule_event(time() + 60, 'fhsu_gd_every_two_minutes', self::CRON_HOOK);
    } 
   
    

  public static function on_activate() {
    self::create_tables();
    // Default cache
    if (get_option(self::OPT_CACHE) === false) {
      update_option(self::OPT_CACHE, [
        'updated_at' => time(),
        'total_raised' => 0,
        'total_gifts' => 0,
        'recent_donors' => [],
        'by_designation' => [],
      ], false);
    }

    // Default start unix if none set (leave blank for now; user sets it)
    if (get_option(self::OPT_START_UNIX) === false) {
      update_option(self::OPT_START_UNIX, '', false);
    }
    
    if (get_option(self::OPT_CRON_TOKEN) === false) {
      update_option(self::OPT_CRON_TOKEN, wp_generate_password(32, false, false), false);
    }

    // Schedule cron every 2 minutes (WP-Cron is best-effort; we’ll tune as needed)
    if (!wp_next_scheduled(self::CRON_HOOK)) {
      wp_schedule_event(time() + 60, 'fhsu_gd_every_two_minutes', self::CRON_HOOK);
    }
  }

  public static function on_deactivate() {
      $ts = wp_next_scheduled(self::CRON_HOOK);
      while ($ts) {
        wp_unschedule_event($ts, self::CRON_HOOK);
        $ts = wp_next_scheduled(self::CRON_HOOK);
      }
    }

  // Add a 2-minute interval
  public static function add_cron_schedules($schedules) {
    if (!isset($schedules['fhsu_gd_every_two_minutes'])) {
      $schedules['fhsu_gd_every_two_minutes'] = [
        'interval' => 120,
        'display'  => 'Every 2 minutes (Giving Day)',
      ];
    }
    return $schedules;
  }

  public static function admin_menu() {
    add_options_page(
      'Giving Day Stats',
      'Giving Day Stats',
      'manage_options',
      'fhsu-givingday-stats',
      [__CLASS__, 'settings_page']
    );
  }
  
  public static function ensure_cron_scheduled() {
      $next = wp_next_scheduled(self::CRON_HOOK);
    
      if (!$next) {
        $result = wp_schedule_event(time() + 60, 'fhsu_gd_every_two_minutes', self::CRON_HOOK);
    
        if ($result === false) {
          update_option('fhsu_gd_last_cron_error', 'Failed to schedule cron event', false);
        } else {
          delete_option('fhsu_gd_last_cron_error');
        }
      }
    }
    
      
    
  public static function settings_page() {
    if (!current_user_can('manage_options')) return;
    
    if (isset($_GET['reschedule_cron'])) {
      self::reschedule_cron();
      echo '<div class="notice notice-success"><p>Cron rescheduled.</p></div>';
    }

    $api_key    = get_option(self::OPT_API_KEY, '');
    $start_unix = get_option(self::OPT_START_UNIX, '');
    $last_sync  = get_option(self::OPT_LAST_SYNC_UNIX, '');
    $cache      = get_option(self::OPT_CACHE, []);

    $nonce_save = wp_create_nonce('fhsu_gd_save_settings');
    $nonce_sync = wp_create_nonce('fhsu_gd_sync_now');
    
    $last_cron_error = get_option('fhsu_gd_last_cron_error', '');

    if ($last_cron_error) {
      echo '<p style="color:red;"><strong>Last cron error:</strong> ' . esc_html($last_cron_error) . '</p>';
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'fhsu_gd_donations';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    
    echo '<h2>DB Table Check</h2>';
    if ($exists === $table) {
      echo '<p style="color:green;"><strong>Found table:</strong> <code>' . esc_html($table) . '</code></p>';
    } else {
      echo '<p style="color:red;"><strong>Missing table:</strong> <code>' . esc_html($table) . '</code></p>';
      echo '<p>Tip: Deactivate/activate the plugin to re-run activation, or run create_tables() manually.</p>';
    }
    if (isset($_GET['rebuilt'])) {
      echo '<div class="notice notice-success is-dismissible"><p><strong>Cache rebuilt from database.</strong></p></div>';
    }
    if (isset($_GET['db_cleared'])) {
      echo '<div class="notice notice-success is-dismissible"><p><strong>Donations database cleared.</strong></p></div>';
    }
    if (isset($_GET['token_regenerated'])) {
      echo '<div class="notice notice-success is-dismissible"><p><strong>External cron token regenerated.</strong> Update your cPanel cron URL.</p></div>';
    }
    
  
    $next_cron = wp_next_scheduled(self::CRON_HOOK);
    $last_sync_run = get_option(self::OPT_LAST_SYNC_RUN, '');
    $last_cron_run = get_option(self::OPT_LAST_CRON_RUN, '');
    $cron_token = get_option(self::OPT_CRON_TOKEN, '');
    if (!$cron_token) {
      $cron_token = wp_generate_password(32, false, false);
      update_option(self::OPT_CRON_TOKEN, $cron_token, false);
    }
    $external_cron_url = add_query_arg([
      'action' => 'fhsu_gd_external_cron',
      'token'  => $cron_token,
    ], admin_url('admin-post.php'));
    
    echo '<h2>Cron Status</h2>';
    
    if ($next_cron) {
      echo '<p><strong>Next scheduled run:</strong> ' . esc_html(date_i18n('Y-m-d H:i:s', $next_cron)) . '</p>';
    } else {
      echo '<p style="color:red;"><strong>No cron event is currently scheduled.</strong></p>';
    }
    
    echo '<p><strong>Last sync run (manual or cron):</strong> ' .
         ($last_sync_run ? esc_html(date_i18n('Y-m-d H:i:s', $last_sync_run)) : '(never)') .
         '</p>';
    
    echo '<p><strong>Last cron run:</strong> ' .
         ($last_cron_run ? esc_html(date_i18n('Y-m-d H:i:s', $last_cron_run)) : '(never)') .
         '</p>';

    echo '<p><strong>External cron URL (use this in cPanel):</strong><br><code>' . esc_html($external_cron_url) . '</code></p>';
    echo '<p class="description">If WP-Cron is disabled or unreliable on your host, schedule this URL in cPanel every 2–5 minutes.</p>';
    
    $nonce_gen_token = wp_create_nonce('fhsu_gd_generate_cron_token');
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
    echo '<input type="hidden" name="action" value="fhsu_gd_generate_cron_token" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce_gen_token) . '" />';
    submit_button('Regenerate External Cron Token', 'secondary', 'submit', false);
    echo '</form>';


    echo '<div class="wrap"><h1>Giving Day Stats</h1>';

    echo '<h2>Settings</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="fhsu_gd_save_settings" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce_save) . '" />';

    echo '<table class="form-table"><tbody>';

    echo '<tr><th scope="row"><label>Advance API Key</label></th><td>';
    echo '<input type="password" name="api_key" value="' . esc_attr($api_key) . '" style="width: 420px;" />';
    echo '<p class="description">Stored in WordPress options. Keep admin access locked down.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label>Start Unix Timestamp</label></th><td>';
    echo '<input type="text" name="start_unix" value="' . esc_attr($start_unix) . '" style="width: 220px;" />';
    echo '<p class="description">First-time pull starts here. For testing last year, set this to your test start. For March start this year, you said 1772345020.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Last Sync (cursor)</th><td>';
    echo '<code>' . esc_html($last_sync ?: '(none)') . '</code>';
    echo '<p class="description">This advances automatically based on max <code>u-at</code>.</p>';
    echo '</td></tr>';

    echo '</tbody></table>';

    submit_button('Save Settings');
    echo '</form>';

    echo '<h2>Actions</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
    echo '<input type="hidden" name="action" value="fhsu_gd_sync_now" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce_sync) . '" />';
    submit_button('Sync Now', 'primary', 'submit', false);
    echo '</form>';
    $nonce_reset = wp_create_nonce('fhsu_gd_reset');

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
    echo '<input type="hidden" name="action" value="fhsu_gd_reset" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce_reset) . '" />';
    submit_button('Reset Cache + Cursor', 'secondary', 'submit', false);
    echo '</form>';
    $nonce_rebuild = wp_create_nonce('fhsu_gd_rebuild_cache');

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
    echo '<input type="hidden" name="action" value="fhsu_gd_rebuild_cache" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce_rebuild) . '" />';
    submit_button('Rebuild Cache From Database', 'secondary', 'submit', false);
    echo '</form>';
    $nonce_clear_db = wp_create_nonce('fhsu_gd_clear_database');

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;" onsubmit="return confirm(\'This will permanently delete all stored donations. Continue?\');">';
    echo '<input type="hidden" name="action" value="fhsu_gd_clear_database" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce_clear_db) . '" />';
    submit_button('Clear Donations Database', 'delete', 'submit', false);
    echo '</form>';

    echo '<h2>REST + Shortcode</h2>';
    echo '<p>REST endpoint: <code>/wp-json/givingday/v1/stats</code></p>';
    echo '<p>Shortcode: <code>[givingday_stats]</code></p>';

    echo '<h2>Current Cached Stats (preview)</h2>';
    echo '<pre style="background:#fff;border:1px solid #ddd;padding:12px;max-height:360px;overflow:auto;">' . esc_html(json_encode($cache, JSON_PRETTY_PRINT)) . '</pre>';

    echo '</div>';
  }

  public static function handle_save_settings() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('fhsu_gd_save_settings');

    $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
    $start = isset($_POST['start_unix']) ? preg_replace('/\D+/', '', $_POST['start_unix']) : '';

    update_option(self::OPT_API_KEY, $api_key, false);
    update_option(self::OPT_START_UNIX, $start, false);

    // Optional: if start changes, you may want to reset cursor manually.
    // We'll leave cursor unchanged so you control it.
    wp_redirect(admin_url('options-general.php?page=fhsu-givingday-stats&saved=1'));
    exit;
  }

  public static function handle_sync_now() {
      if (!current_user_can('manage_options')) wp_die('Unauthorized');
      check_admin_referer('fhsu_gd_sync_now');
    
      $result = self::run_sync(true);
    
      if (is_wp_error($result)) {
        wp_die(
          '<h2>Giving Day Sync Error</h2><pre>' .
          esc_html($result->get_error_code() . ': ' . $result->get_error_message()) .
          '</pre>'
        );
      }
    
      wp_redirect(admin_url('options-general.php?page=fhsu-givingday-stats&synced=1'));
      exit;
    }
  
     public static function handle_rebuild_cache() {
      if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
      }
    
      check_admin_referer('fhsu_gd_rebuild_cache');
    
      // Rebuild cache purely from DB (no API)
      $cache = self::build_cache_from_db();
      update_option(self::OPT_CACHE, $cache, false);
    
      wp_redirect(
        admin_url('options-general.php?page=fhsu-givingday-stats&rebuilt=1')
      );
      exit;
    }

  
  public static function handle_reset() {
  if (!current_user_can('manage_options')) wp_die('Unauthorized');
  check_admin_referer('fhsu_gd_reset');

  // Reset cursor and cache
  delete_option(self::OPT_LAST_SYNC_UNIX);

  update_option(self::OPT_CACHE, [
    'updated_at' => time(),
    'total_raised' => 0,
    'total_gifts' => 0,
    'recent_donors' => [],
    'by_designation' => [],
  ], false);

  wp_redirect(admin_url('options-general.php?page=fhsu-givingday-stats&reset=1'));
  exit;
}

  public static function handle_clear_database() {
  if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
  }

  check_admin_referer('fhsu_gd_clear_database');

  global $wpdb;
  $table = $wpdb->prefix . 'fhsu_gd_donations';

  $wpdb->query("TRUNCATE TABLE $table");

  // Also clear cache + cursor so everything is in sync
  delete_option(self::OPT_LAST_SYNC_UNIX);
  update_option(self::OPT_CACHE, [
    'updated_at' => time(),
    'total_raised' => 0,
    'total_gifts' => 0,
    'recent_donors' => [],
    'by_designation' => [],
  ], false);

  wp_redirect(admin_url('options-general.php?page=fhsu-givingday-stats&db_cleared=1'));
  exit;
}

  public static function handle_generate_cron_token() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('fhsu_gd_generate_cron_token');

    update_option(self::OPT_CRON_TOKEN, wp_generate_password(32, false, false), false);

    wp_redirect(admin_url('options-general.php?page=fhsu-givingday-stats&token_regenerated=1'));
    exit;
  }

  public static function handle_external_cron() {
    $expected = (string)get_option(self::OPT_CRON_TOKEN, '');
    $provided = isset($_REQUEST['token']) ? sanitize_text_field((string)$_REQUEST['token']) : '';

    if (!$expected || !$provided || !hash_equals($expected, $provided)) {
      status_header(403);
      wp_die('Forbidden');
    }

    $result = self::run_sync(false);

    if (is_wp_error($result)) {
      status_header(500);
      wp_die('Giving Day sync failed: ' . esc_html($result->get_error_message()));
    }

    wp_die('OK');
  }

  private static function create_tables() {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $table = $wpdb->prefix . 'fhsu_gd_donations';
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    donation_id varchar(40) NOT NULL,
    amount decimal(12,2) NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL DEFAULT '',
    created_at datetime NULL,
    updated_at datetime NULL,
    beneficiary_id varchar(40) NULL,
    beneficiary_name varchar(255) NULL,
    donor_public tinyint(1) NOT NULL DEFAULT 1,
    donor_first varchar(120) NULL,
    donor_last varchar(120) NULL,
    donor_display_type varchar(40) NULL,
    writein_project varchar(255) NULL,
    raw_json longtext NULL,
    PRIMARY KEY  (donation_id),
    KEY updated_at (updated_at),
    KEY beneficiary_id (beneficiary_id),
    KEY status (status),
    KEY created_at (created_at)
  ) $charset;";

  dbDelta($sql);
  
  error_log('GD create_tables ran for: ' . $table);
  error_log('GD last error: ' . $wpdb->last_error);
  error_log('GD last query: ' . $wpdb->last_query);
 
}


  public static function register_routes() {
    register_rest_route('givingday/v1', '/stats', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'get_stats'],
      'permission_callback' => '__return_true',
    ]);
    register_rest_route('givingday/v1', '/leaderboard', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'get_leaderboard'],
      'permission_callback' => '__return_true',
    ]);
    register_rest_route('givingday/v1', '/donors', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'get_donors'],
      'permission_callback' => '__return_true',
    ]);
  }
  
  public static function get_leaderboard($request) {
      $metric = $request->get_param('metric') ?: 'raised'; // 'raised' or 'gifts'
      $limit  = (int)($request->get_param('limit') ?: 5);
    
      $cache = get_option(self::OPT_CACHE);
      $by_des = $cache['by_designation'] ?? [];
    
      if (!is_array($by_des)) return [];
    
      $rows = array_values($by_des);
    
      usort($rows, function($a, $b) use ($metric) {
        if ($metric === 'gifts') {
          return ($b['total_gifts'] ?? 0) <=> ($a['total_gifts'] ?? 0);
        }
        return ($b['total_raised'] ?? 0) <=> ($a['total_raised'] ?? 0);
      });
    
      $rows = array_slice($rows, 0, max(1, min(20, $limit)));
    
      return array_map(function($r) {
        return [
          'beneficiary_id' => $r['beneficiary_id'],
          'designation'    => $r['designation'],
          'total_raised'   => round((float)$r['total_raised'], 2),
          'total_gifts'    => (int)$r['total_gifts'],
        ];
      }, $rows);
    }
    
  public static function get_donors($request) {
      global $wpdb;
      $t = $wpdb->prefix . 'fhsu_gd_donations';
    
      $page = max(1, (int)($request->get_param('page') ?: 1));
      $per  = (int)($request->get_param('per_page') ?: 50);
      $per  = max(1, min(200, $per)); // safety cap
      $offset = ($page - 1) * $per;
    
      $beneficiary_id = (string)($request->get_param('beneficiary_id') ?: '');
    
      // Base WHERE
      $where = "WHERE status='paid'";
      $args  = [];
    
      if ($beneficiary_id !== '') {
        $where .= " AND beneficiary_id=%s";
        $args[] = $beneficiary_id;
      }
    
      // Total count for pagination
      $sql_total = "SELECT COUNT(*) FROM $t $where";
      $total = (int)$wpdb->get_var($wpdb->prepare($sql_total, $args));
    
      $total_pages = (int)ceil(($total ?: 0) / $per);
    
      // Page items
      $sql_items = "
        SELECT donor_public, donor_first, donor_last, donor_display_type,
               amount, beneficiary_name, writein_project, created_at
        FROM $t
        $where
        ORDER BY created_at DESC
        LIMIT %d OFFSET %d
      ";
    
      $items_args = array_merge($args, [$per, $offset]);
      $rows = $wpdb->get_results($wpdb->prepare($sql_items, $items_args), ARRAY_A);
    
      $items = array_map(function($r){
        // Name handling
        $name = 'Anonymous';
        if ((int)$r['donor_public'] === 1) {
          $first = trim((string)($r['donor_first'] ?? ''));
          $last  = trim((string)($r['donor_last'] ?? ''));
    
          // "alias" style: First + Last initial
          if (($r['donor_display_type'] ?? '') === 'alias') {
            $name = $first ?: 'Anonymous';
            if ($last !== '') $name .= ' ' . strtoupper(substr($last, 0, 1)) . '.';
            $name = trim($name);
          } else {
            $full = trim($first . ' ' . $last);
            if ($full !== '') $name = $full;
          }
        }
    
        // Designation handling
        $designation = trim((string)($r['beneficiary_name'] ?? ''));
        if (!empty($r['writein_project'])) {
          $designation .= ' — ' . $r['writein_project'];
        }
    
        return [
          'name' => $name,
          'amount' => (float)$r['amount'],          // NOTE: if you later store "hide amount", apply it here
          'designation' => $designation,
          'time' => $r['created_at'],
        ];
      }, $rows);
    
      return rest_ensure_response([
        'page' => $page,
        'per_page' => $per,
        'total' => $total,
        'total_pages' => $total_pages,
        'items' => $items,
      ]);
    }


  public static function get_stats() {
    $cache = get_option(self::OPT_CACHE);
    if (!$cache) $cache = [];
    return rest_ensure_response($cache);
  }

  public static function shortcode_stats() {
    $endpoint = esc_url(rest_url('givingday/v1/stats'));

    ob_start(); ?>
      <div class="givingday-stats" data-endpoint="<?php echo $endpoint; ?>">
        <div class="gd-row">
          <div><strong>Total Raised:</strong> <span class="gd-total-raised">$0</span></div>
          <div><strong>Total Gifts:</strong> <span class="gd-total-gifts">0</span></div>
        </div>

        <h4 style="margin-top:12px;">Recent Donors</h4>
        <ul class="gd-recent-donors"></ul>
      </div>

      <script>
      (function(){
        const root = document.currentScript.previousElementSibling;
        if (!root || !root.classList.contains('givingday-stats')) return;
        const endpoint = root.getAttribute('data-endpoint');

        function formatMoney(n){
          try {
            return new Intl.NumberFormat('en-US', {style:'currency', currency:'USD'}).format(n || 0);
          } catch(e) {
            return '$' + (n || 0);
          }
        }

        async function refresh(){
          try {
            const res = await fetch(endpoint, {cache:'no-store'});
            const data = await res.json();

            root.querySelector('.gd-total-raised').textContent = formatMoney(data.total_raised);
            root.querySelector('.gd-total-gifts').textContent  = (data.total_gifts ?? 0);

            const list = root.querySelector('.gd-recent-donors');
            list.innerHTML = '';
            (data.recent_donors || []).slice(0, 10).forEach(d => {
              const li = document.createElement('li');
              const name = d.name || 'Anonymous';
              const amt  = formatMoney(d.amount);
              const des  = d.designation ? ` — ${d.designation}` : '';
              li.textContent = `${name}: ${amt}${des}`;
              list.appendChild(li);
            });
          } catch(e) {}
        }

        refresh();
        setInterval(refresh, 60000);
      })();
      </script>
    <?php
    return ob_get_clean();
  }
  
  public static function shortcode_donor_wall_db($atts = []) {
      $atts = shortcode_atts([
        'per_page' => 50,
        'beneficiary_id' => '',
      ], $atts, 'givingday_donor_wall_db');
    
      $per = max(1, min(200, (int)$atts['per_page']));
      $beneficiary_id = sanitize_text_field($atts['beneficiary_id']);
    
      $endpoint = add_query_arg([
        'per_page' => $per,
        'beneficiary_id' => $beneficiary_id,
      ], rest_url('givingday/v1/donors'));
    
      ob_start(); ?>
        <div class="gd-donorwall" data-endpoint="<?php echo esc_url($endpoint); ?>" data-page="1">
          <ul class="gd-donorwall-list"></ul>
          <div class="gd-donorwall-controls">
            <button type="button" class="gd-prev" disabled>Prev</button>
            <span class="gd-pageinfo"></span>
            <button type="button" class="gd-next">Next</button>
          </div>
        </div>
    
        <script>
        (function(){
          const root = document.currentScript.previousElementSibling;
          if (!root) return;
    
          const endpointBase = root.getAttribute('data-endpoint');
          const list = root.querySelector('.gd-donorwall-list');
          const prev = root.querySelector('.gd-prev');
          const next = root.querySelector('.gd-next');
          const info = root.querySelector('.gd-pageinfo');
    
          function money(n){
            return new Intl.NumberFormat('en-US', {style:'currency', currency:'USD', minimumFractionDigits:0, maximumFractionDigits:0}).format(n || 0);
          }
          
          function escapeHtml(str){
              return String(str ?? '')
                .replaceAll('&','&amp;')
                .replaceAll('<','&lt;')
                .replaceAll('>','&gt;')
                .replaceAll('"','&quot;')
                .replaceAll("'","&#039;");
            }

    
          async function load(page){
            try{
              const url = endpointBase + (endpointBase.includes('?') ? '&' : '?') + 'page=' + page;
              const res = await fetch(url, {cache:'no-store'});
              const data = await res.json();
    
              list.innerHTML = '';
              (data.items || []).forEach(item => {
                const li = document.createElement('li');
                const amt = (item.amount === null || item.amount === undefined)
                  ? 'Undisclosed'
                  : money(item.amount);
                
                li.className = 'gd-dw-card';
                li.innerHTML = `
                  <div class="gd-dw-top">
                    <div class="gd-dw-name">${escapeHtml(item.name)}</div>
                    <div class="gd-dw-amt">${escapeHtml(amt)}</div>
                  </div>
                
                  <div class="gd-dw-bottom">
                    <div class="gd-dw-des">${escapeHtml(item.designation)}</div>
                  </div>
                `;

                list.appendChild(li);
              });
    
              root.setAttribute('data-page', data.page || page);
              info.textContent = `Page ${data.page} of ${data.total_pages}`;
              prev.disabled = (data.page <= 1);
              next.disabled = (data.page >= data.total_pages);
            } catch(e){}
          }
    
          prev.addEventListener('click', () => load(Math.max(1, (parseInt(root.getAttribute('data-page')||'1',10) - 1))));
          next.addEventListener('click', () => load((parseInt(root.getAttribute('data-page')||'1',10) + 1)));
    
          load(1);
        })();
        </script>
      <?php
      return ob_get_clean();
    }

  
  public static function shortcode_total_raised($atts = []) {
  $endpoint = esc_url(rest_url('givingday/v1/stats'));

  ob_start(); ?>
    <span class="gd-total-raised-only" data-endpoint="<?php echo $endpoint; ?>">$0</span>
    <script>
    (function(){
      const el = document.currentScript.previousElementSibling;
      if (!el) return;
      const endpoint = el.getAttribute('data-endpoint');

      function formatMoney(n){
        try {
            return new Intl.NumberFormat('en-US', {
              style: 'currency',
              currency: 'USD',
              minimumFractionDigits: 0,
              maximumFractionDigits: 0
            }).format(n || 0);
          } catch(e) {
            return '$' + Math.round(n || 0);
          }
        }


      async function refresh(){
        try{
          const res = await fetch(endpoint, {cache:'no-store'});
          const data = await res.json();
          el.textContent = formatMoney(data.total_raised);
        } catch(e){}
      }
      refresh();
      setInterval(refresh, 60000);
    })();
    </script>
  <?php
  return ob_get_clean();
}

  public static function shortcode_leaderboard($atts = []) {
      $atts = shortcode_atts([
        'metric' => 'raised', // raised | gifts
        'limit'  => 5,
        'title'  => '',
      ], $atts, 'givingday_leaderboard');
    
      $metric = ($atts['metric'] === 'gifts') ? 'gifts' : 'raised';
      $limit  = max(1, min(20, (int)$atts['limit']));
      $title  = sanitize_text_field($atts['title']);
    
      $endpoint = esc_url(
        add_query_arg([
          'metric' => $metric,
          'limit'  => $limit,
        ], rest_url('givingday/v1/leaderboard'))
      );
    
      ob_start(); ?>
    
      <div class="gd-leaderboard"
           data-endpoint="<?php echo $endpoint; ?>"
           data-metric="<?php echo esc_attr($metric); ?>">
    
        <?php if ($title): ?>
          <h3><?php echo esc_html($title); ?></h3>
        <?php endif; ?>
    
        <ol class="gd-leaderboard-list"></ol>
      </div>
    
      <script>
      (function(){
        const root = document.currentScript.previousElementSibling;
        if (!root) return;
    
        const endpoint = root.getAttribute('data-endpoint');
        const metric = root.getAttribute('data-metric');
        const list = root.querySelector('.gd-leaderboard-list');
    
        function formatMoney(n){
          return new Intl.NumberFormat('en-US', {
            style:'currency',
            currency:'USD',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
          }).format(n || 0);
        }
    
        async function load(){
          try {
            const res = await fetch(endpoint, {cache:'no-store'});
            const rows = await res.json();
    
            list.innerHTML = '';
            rows.forEach(r => {
              const li = document.createElement('li');
              const value = metric === 'gifts'
                ? `${r.total_gifts} gifts`
                : formatMoney(r.total_raised);
    
              li.innerHTML = `
                <strong>${r.designation}</strong>
                <span>${value}</span>
              `;
              list.appendChild(li);
            });
          } catch(e){}
        }
    
        load();
        setInterval(load, 60000);
      })();
      </script>
    
      <?php
      return ob_get_clean();
    }
    
    
    public static function shortcode_total_gifts($atts = []) {
      $endpoint = esc_url(rest_url('givingday/v1/stats'));
    
      ob_start(); ?>
        <span class="gd-total-gifts-only" data-endpoint="<?php echo $endpoint; ?>">0</span>
        <script>
        (function(){
          const el = document.currentScript.previousElementSibling;
          if (!el) return;
          const endpoint = el.getAttribute('data-endpoint');
    
          async function refresh(){
            try{
              const res = await fetch(endpoint, {cache:'no-store'});
              const data = await res.json();
              el.textContent = (data.total_gifts ?? 0);
            } catch(e){}
          }
          refresh();
          setInterval(refresh, 60000);
        })();
        </script>
      <?php
      return ob_get_clean();
    }

public static function shortcode_donor_wall($atts = []) {
  $atts = shortcode_atts([
    'limit' => 50,
    'show_amount' => 'true',
    'show_designation' => 'true',
  ], $atts, 'givingday_donor_wall');

  $limit = max(1, min(200, (int)$atts['limit']));
  $show_amount = ($atts['show_amount'] === 'true');
  $show_designation = ($atts['show_designation'] === 'true');

  $endpoint = esc_url(rest_url('givingday/v1/stats'));

  ob_start(); ?>
    <ul class="gd-donor-wall" 
        data-endpoint="<?php echo $endpoint; ?>"
        data-limit="<?php echo esc_attr($limit); ?>"
        data-show-amount="<?php echo esc_attr($show_amount ? '1' : '0'); ?>"
        data-show-designation="<?php echo esc_attr($show_designation ? '1' : '0'); ?>">
    </ul>

    <script>
    (function(){
      const list = document.currentScript.previousElementSibling;
      if (!list) return;

      const endpoint = list.getAttribute('data-endpoint');
      const limit = parseInt(list.getAttribute('data-limit') || '50', 10);
      const showAmount = list.getAttribute('data-show-amount') === '1';
      const showDesignation = list.getAttribute('data-show-designation') === '1';

      function formatMoney(n){
          try {
            return new Intl.NumberFormat('en-US', {
              style: 'currency',
              currency: 'USD',
              minimumFractionDigits: 0,
              maximumFractionDigits: 0
            }).format(n || 0);
          } catch(e) {
            return '$' + Math.round(n || 0);
          }
        }


      async function refresh(){
        try{
          const res = await fetch(endpoint, {cache:'no-store'});
          const data = await res.json();
          const donors = (data.recent_donors || []).slice(0, limit);

          list.innerHTML = '';
          donors.forEach(d => {
            const li = document.createElement('li');
            const name = d.name || 'Anonymous';

            let parts = [name];

            if (showAmount) parts.push(formatMoney(d.amount));
            if (showDesignation && d.designation) parts.push(d.designation);

            li.textContent = parts.join(' — ');
            list.appendChild(li);
          });
        } catch(e){}
      }

      refresh();
      setInterval(refresh, 60000);
    })();
    </script>
  <?php
  return ob_get_clean();
}

  /** =======================
   *  Sync logic
   *  ======================= */

  public static function run_sync($manual = false) {
      update_option(self::OPT_LAST_SYNC_RUN, time(), false);
    
      if (!$manual) {
        update_option(self::OPT_LAST_CRON_RUN, time(), false);
      }
    
      $api_key = get_option(self::OPT_API_KEY, '');
      $start_unix = get_option(self::OPT_START_UNIX, '');
    
      if (!$api_key) {
        return new WP_Error('gd_no_key', 'Missing API key');
      }
      if (!$start_unix || !ctype_digit((string)$start_unix)) {
        return new WP_Error('gd_no_start', 'Missing/invalid start unix timestamp');
      }
    
      $cursor = get_option(self::OPT_LAST_SYNC_UNIX, '');
      if (!$cursor || !ctype_digit((string)$cursor)) {
        $cursor = (int)$start_unix;
      } else {
        $cursor = (int)$cursor;
      }
    
      $donations = self::fetch_donations_since($api_key, $cursor);
      if (is_wp_error($donations)) return $donations;
    
      $cache = get_option(self::OPT_CACHE, [
        'updated_at' => time(),
        'total_raised' => 0,
        'total_gifts' => 0,
        'recent_donors' => [],
        'by_designation' => [],
      ]);
    
      $result = self::apply_donations_to_cache($cache, $donations, $cursor);
    
      $cache_from_db = self::build_cache_from_db();
      update_option(self::OPT_CACHE, $cache_from_db, false);
      update_option(self::OPT_LAST_SYNC_UNIX, (string)$result['new_cursor'], false);
    
      return true;
    }
    
private static function fetch_donations_since($api_key, $start_unix) {
  $all = [];
  $page = 0;

  // We'll compute pages_total from count/per-page when available.
  $pages_total = null;

  while (true) {
    $json = self::fetch_donations_page($api_key, $start_unix, $page);
    if (is_wp_error($json)) return $json;

    $data = $json['data'] ?? [];
    $meta = $json['meta'] ?? [];

    // If the API ever returns an empty page, we're done.
    if (empty($data)) break;

    $all = array_merge($all, $data);

    // Prefer count/per-page if present (more consistent than meta.pages).
    if ($pages_total === null) {
      $per_page = isset($meta['per-page']) ? (int)$meta['per-page'] : 100;
      $count    = isset($meta['count']) ? (int)$meta['count'] : null;

      if ($count !== null && $per_page > 0) {
        $pages_total = (int)ceil($count / $per_page);
      } elseif (isset($meta['pages'])) {
        // Fallback to meta.pages if count not provided
        $pages_total = (int)$meta['pages'];
      } else {
        // As a last resort, assume at least this page exists and continue until empty
        $pages_total = PHP_INT_MAX;
      }
    }

    $page++;

    // Stop condition based on computed total pages
    if ($pages_total !== null && $page >= $pages_total) break;
  }

  return $all;
}


  private static function fetch_donations_page($api_key, $start_unix, $page = 0) {
    $base = 'https://api.amploadvance.com/v1';
    $url  = add_query_arg([
      'last_updated_start' => (int)$start_unix,
      'page' => (int)$page,
    ], $base . '/donations');

    $resp = wp_remote_get($url, [
      'timeout' => 20,
      'headers' => [
        'Authorization' => 'Token token=' . $api_key,
        'Accept' => 'application/json',
      ],
    ]);

    if (is_wp_error($resp)) return $resp;

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);

    if ($code < 200 || $code >= 300) {
      return new WP_Error('advance_api_http', 'Advance API HTTP error: ' . $code, [
        'url' => $url,
        'body' => $body
      ]);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
      return new WP_Error('advance_api_json', 'Invalid JSON response', [
        'url' => $url,
        'body' => $body
      ]);
    }

    return $json;
  }
  
  private static function upsert_donation($d) {
  global $wpdb;
  $table = $wpdb->prefix . 'fhsu_gd_donations';

  // Safety: must have an ID
  $donation_id = (string)($d['id'] ?? '');
  if ($donation_id === '') return;

  $attrs = $d['attributes'] ?? [];

  // Core fields
  $status = (string)($attrs['status'] ?? '');
  $amount = (float)($attrs['amount'] ?? 0);

  // Timestamps (store UTC)
  $created_at = !empty($attrs['created-at'])
    ? gmdate('Y-m-d H:i:s', strtotime($attrs['created-at']))
    : null;

  $updated_at = !empty($attrs['u-at'])
    ? gmdate('Y-m-d H:i:s', strtotime($attrs['u-at']))
    : null;

  // Fund / campaign
  $beneficiary_id   = (string)($attrs['beneficiary-id'] ?? '');
  $beneficiary_name = (string)($attrs['beneficiary-name'] ?? '');

  // Donor info (minimal)
  $donor_public = !empty($attrs['public']) ? 1 : 0;
  $donor_first  = (string)($attrs['donor-first-name'] ?? '');
  $donor_last   = (string)($attrs['donor-last-name'] ?? '');
  $display_type = (string)($attrs['display-type'] ?? '');

  // Custom fields
  $writein = $attrs['custom-fields']['writein-project'] ?? null;
  $writein = is_string($writein) ? $writein : null;

  // Full raw record (future-proofing)
  $raw_json = wp_json_encode($d);

  // Upsert using primary key (donation_id)
  $wpdb->replace(
    $table,
    [
      'donation_id'       => $donation_id,
      'amount'            => $amount,
      'status'            => $status,
      'created_at'        => $created_at,
      'updated_at'        => $updated_at,
      'beneficiary_id'    => $beneficiary_id,
      'beneficiary_name'  => $beneficiary_name,
      'donor_public'      => $donor_public,
      'donor_first'       => $donor_first,
      'donor_last'        => $donor_last,
      'donor_display_type'=> $display_type,
      'writein_project'   => $writein,
      'raw_json'          => $raw_json,
    ],
    [
      '%s',  // donation_id
      '%f',  // amount
      '%s',  // status
      '%s',  // created_at
      '%s',  // updated_at
      '%s',  // beneficiary_id
      '%s',  // beneficiary_name
      '%d',  // donor_public
      '%s',  // donor_first
      '%s',  // donor_last
      '%s',  // display_type
      '%s',  // writein_project
      '%s',  // raw_json
    ]
  );
}

  private static function build_cache_from_db() {
  global $wpdb;
  $t = $wpdb->prefix . 'fhsu_gd_donations';

  $total_raised = (float) $wpdb->get_var("
    SELECT COALESCE(SUM(amount),0)
    FROM $t
    WHERE status='paid'
  ");

  $total_gifts = (int) $wpdb->get_var("
    SELECT COUNT(*)
    FROM $t
    WHERE status='paid'
  ");

  // Group totals by designation/fund.
  // Important: aggregate at fund level (beneficiary) so leaderboard stays
  // consistent with donor wall expectations even when write-in variants exist.
  $rows = $wpdb->get_results("
    SELECT
      beneficiary_id,
      beneficiary_name,
      COUNT(*) as total_gifts,
      COALESCE(SUM(amount),0) as total_raised
    FROM $t
    WHERE status='paid'
    GROUP BY beneficiary_id, beneficiary_name
    ORDER BY total_raised DESC
  ", ARRAY_A);

  $by_des = [];
  foreach ($rows as $r) {
    $label = trim((string)($r['beneficiary_name'] ?? ''));
    if ($label === '') $label = 'Unspecified';

    // Prefer beneficiary_id as the stable aggregation key.
    // Fallback to normalized label so naming/case differences don't split rows.
    $key = trim((string)($r['beneficiary_id'] ?? ''));
    if ($key === '') {
      $key = 'name:' . strtolower(preg_replace('/\s+/', ' ', $label));
    }

    if (!isset($by_des[$key])) {
      $by_des[$key] = [
        'beneficiary_id' => (string)($r['beneficiary_id'] ?? ''),
        'designation' => $label,
        'total_raised' => 0,
        'total_gifts' => 0,
      ];
    }

    $by_des[$key]['total_raised'] += (float)$r['total_raised'];
    $by_des[$key]['total_gifts'] += (int)$r['total_gifts'];
  }

  // Recent donors (latest 50)
  $recent_rows = $wpdb->get_results("
    SELECT donor_public, donor_first, donor_last, donor_display_type,
           amount, beneficiary_name, writein_project, created_at
    FROM $t
    WHERE status='paid'
    ORDER BY created_at DESC
    LIMIT 50
  ", ARRAY_A);

  $recent = array_map(function($r){
    $name = 'Anonymous';

    if ((int)$r['donor_public'] === 1) {
      $first = trim((string)($r['donor_first'] ?? ''));
      $last  = trim((string)($r['donor_last'] ?? ''));

      // If display-type is alias but we don't have a separate alias field,
      // show First + Last initial (or First only)
      if (($r['donor_display_type'] ?? '') === 'alias') {
        $name = $first ?: 'Anonymous';
        if ($last !== '') $name .= ' ' . strtoupper(substr($last, 0, 1)) . '.';
        $name = trim($name);
      } else {
        $full = trim($first . ' ' . $last);
        if ($full !== '') $name = $full;
      }
    }

    $designation = trim((string)($r['beneficiary_name'] ?? ''));
    if (!empty($r['writein_project'])) $designation .= ' — ' . $r['writein_project'];

    return [
      'name' => $name,
      'amount' => (float)$r['amount'],
      'designation' => $designation,
      'time' => $r['created_at'] ? strtotime($r['created_at'] . ' UTC') : time(),
    ];
  }, $recent_rows);

  return [
    'updated_at' => time(),
    'total_raised' => round($total_raised, 2),
    'total_gifts' => $total_gifts,
    'recent_donors' => $recent,
    'by_designation' => $by_des,
  ];
}


  private static function apply_donations_to_cache($cache, $donations, $cursor_unix) {
    $total_raised = (float)($cache['total_raised'] ?? 0);
    $total_gifts  = (int)($cache['total_gifts'] ?? 0);
    $recent       = is_array($cache['recent_donors'] ?? null) ? $cache['recent_donors'] : [];
    $by_des       = is_array($cache['by_designation'] ?? null) ? $cache['by_designation'] : [];

    $new_cursor = (int)$cursor_unix;

    foreach ($donations as $d) {
        
      self::upsert_donation($d);
      
      $attrs = $d['attributes'] ?? [];
      if (($attrs['status'] ?? '') !== 'paid') continue;

      $amount = (float)($attrs['amount'] ?? 0);
      $total_raised += $amount;
      $total_gifts += 1;

      $u_at_iso = $attrs['u-at'] ?? null;
      if ($u_at_iso) {
        $ts = strtotime($u_at_iso);
        if ($ts && $ts > $new_cursor) $new_cursor = $ts;
      }

      $created_iso = $attrs['created-at'] ?? null;
      $created_ts = $created_iso ? strtotime($created_iso) : time();

      $public = (bool)($attrs['public'] ?? true);
      $name = 'Anonymous';
      if ($public) {
        $first = trim((string)($attrs['donor-first-name'] ?? ''));
        $last  = trim((string)($attrs['donor-last-name'] ?? ''));
        $full  = trim($first . ' ' . $last);
        if ($full !== '') $name = $full;
      }

      $designation = trim((string)($attrs['beneficiary-name'] ?? ''));
      // Optional: if you want to show write-in project for "Support Another Area..."
      $writein = $attrs['custom-fields']['writein-project'] ?? null;
      if ($writein) {
        $designation = $designation ? ($designation . ' — ' . $writein) : (string)$writein;
      }

      // Aggregate by designation (beneficiary-id preferred)
      $benef_id = (string)($attrs['beneficiary-id'] ?? $designation);
      if (!isset($by_des[$benef_id])) {
        $by_des[$benef_id] = [
          'beneficiary_id' => $benef_id,
          'designation' => $designation ?: 'Unspecified',
          'total_raised' => 0,
          'total_gifts' => 0,
        ];
      }
      $by_des[$benef_id]['total_raised'] += $amount;
      $by_des[$benef_id]['total_gifts']  += 1;

      $recent[] = [
        'name' => $name,
        'amount' => $amount,
        'designation' => $designation,
        'time' => $created_ts,
      ];
    }

    // Keep recent donors sorted by time desc and capped
    usort($recent, function($a, $b) {
      return (int)($b['time'] ?? 0) <=> (int)($a['time'] ?? 0);
    });
    $recent = array_slice($recent, 0, 50);

    $cache['updated_at'] = time();
    $cache['total_raised'] = round($total_raised, 2);
    $cache['total_gifts']  = $total_gifts;
    $cache['recent_donors'] = $recent;
    $cache['by_designation'] = $by_des;

    return [
      'cache' => $cache,
      'new_cursor' => $new_cursor,
    ];
  }
}

FHSU_GivingDay_Stats::init();
