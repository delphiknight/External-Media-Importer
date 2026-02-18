<?php
/**
 * Plugin Name:       External Media Importer
 * Plugin URI:        https://gogelia.ge
 * Description:       Import external media files into WordPress media library with full control.
 * Version:           1.0.29
 * Requires at least: 5.0
 * Requires PHP:      7.2
 * Author:            Dimitri Gogelia
 * Author URI:        https://gogelia.ge
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       external-media-importer
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

class External_Media_Importer {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'external_media_log';
        
        // Hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_emi_scan_post', array($this, 'ajax_scan_post'));
        add_action('wp_ajax_emi_scan_all_posts', array($this, 'ajax_scan_all_posts'));
        add_action('wp_ajax_emi_scan_single_post', array($this, 'ajax_scan_single_post'));
        add_action('wp_ajax_emi_scan_batch_posts', array($this, 'ajax_scan_batch_posts'));
        add_action('wp_ajax_emi_import_file', array($this, 'ajax_import_file'));
        add_action('wp_ajax_emi_clear_dead_links', array($this, 'ajax_clear_dead_links'));
        add_action('wp_ajax_emi_get_failed_imports', array($this, 'ajax_get_failed_imports'));
        add_action('wp_ajax_emi_delete_log_entry', array($this, 'ajax_delete_log_entry'));
        add_action('wp_ajax_emi_dry_run', array($this, 'ajax_dry_run'));
        add_action('wp_ajax_emi_get_logs', array($this, 'ajax_get_logs'));
        add_action('admin_init', array($this, 'handle_csv_export'));

        // Load translations
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Uninstall hook (static method — runs without class instance)
        register_uninstall_hook(__FILE__, array(__CLASS__, 'uninstall'));
    }
    
    public function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            post_title text NOT NULL,
            original_url text NOT NULL,
            new_attachment_id bigint(20) DEFAULT NULL,
            status varchar(50) NOT NULL,
            error_message text,
            processed_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_id (post_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Add default options
        add_option('emi_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx,zip,mp4,mp3');
        add_option('emi_external_url', '');
        add_option('emi_batch_size', 50);
    }

    /**
     * Clean up all plugin data on uninstall.
     * Drops the log table and deletes all plugin options.
     */
    public static function uninstall() {
        global $wpdb;

        // Drop the log table
        $table_name = $wpdb->prefix . 'external_media_log';
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

        // Delete plugin options
        delete_option('emi_file_types');
        delete_option('emi_external_url');
        delete_option('emi_batch_size');
        delete_option('emi_capability');
    }

    private function get_required_cap() {
        $cap = get_option('emi_capability', 'manage_options');
        $allowed = array('manage_options', 'edit_posts');
        return in_array($cap, $allowed, true) ? $cap : 'manage_options';
    }

    /**
     * Load plugin text domain for translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain('external-media-importer', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function add_admin_menu() {
        $cap = $this->get_required_cap();

        add_menu_page(
            __('External Media Importer', 'external-media-importer'),
            __('Media Importer', 'external-media-importer'),
            $cap,
            'external-media-importer',
            array($this, 'render_admin_page'),
            'dashicons-download',
            30
        );

        add_submenu_page(
            'external-media-importer',
            __('Import Dashboard', 'external-media-importer'),
            __('Dashboard', 'external-media-importer'),
            $cap,
            'external-media-dashboard',
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            'external-media-importer',
            __('Import Logs', 'external-media-importer'),
            __('Logs', 'external-media-importer'),
            $cap,
            'external-media-logs',
            array($this, 'render_logs_page')
        );

        add_submenu_page(
            'external-media-importer',
            __('Settings', 'external-media-importer'),
            __('Settings', 'external-media-importer'),
            $cap,
            'external-media-settings',
            array($this, 'render_settings_page')
        );
    }
    
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'external-media') === false) {
            return;
        }
        
        $plugin_url = plugin_dir_url(__FILE__);

        // Select2
        wp_enqueue_style('select2-css', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', array(), '4.0.13');
        wp_enqueue_script('select2-js', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '4.0.13', true);

        wp_enqueue_style('emi-admin-css', $plugin_url . 'assets/admin.css', array('select2-css'), '1.0.29');
        wp_enqueue_script('emi-admin-js', $plugin_url . 'assets/admin.js', array('jquery', 'select2-js'), '1.0.29', true);
        
        wp_localize_script('emi-admin-js', 'emiAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonces' => array(
                'scan_all'        => wp_create_nonce('emi_scan_all_posts'),
                'scan_single'     => wp_create_nonce('emi_scan_single_post'),
                'scan_batch'      => wp_create_nonce('emi_scan_batch_posts'),
                'scan_post'       => wp_create_nonce('emi_scan_post'),
                'import_file'     => wp_create_nonce('emi_import_file'),
                'clear_dead'      => wp_create_nonce('emi_clear_dead_links'),
                'get_failed'      => wp_create_nonce('emi_get_failed_imports'),
                'delete_log'      => wp_create_nonce('emi_delete_log_entry'),
                'dry_run'         => wp_create_nonce('emi_dry_run'),
            ),
            'batchSize' => (int) get_option('emi_batch_size', 50),
            'i18n' => array(
                // Scan form
                'scanning'              => __('Scanning...', 'external-media-importer'),
                'scanPostsBtn'          => __('Scan Posts for External Files', 'external-media-importer'),
                'quickScan'             => __('Quick Scan', 'external-media-importer'),
                'noSavedScan'           => __('No saved scan found.', 'external-media-importer'),
                'enterPostIdOrUrl'      => __('Please enter a post ID or URL.', 'external-media-importer'),
                'selectOneStatus'       => __('Please select at least one post status.', 'external-media-importer'),
                'noPostsFound'          => __('No posts found with potential external files for this post type.', 'external-media-importer'),
                'selectOneFile'         => __('Please select at least one file.', 'external-media-importer'),
                'selectOneFileImport'   => __('Please select at least one file to import', 'external-media-importer'),

                // Progress text (with %d/%d placeholders)
                'postsScanned'          => __('%1$d / %2$d posts scanned', 'external-media-importer'),
                'postsScannedResumed'   => __('%1$d / %2$d posts scanned (resumed)', 'external-media-importer'),
                'filesChecked'          => __('%1$d / %2$d files checked', 'external-media-importer'),
                'filesProcessed'        => __('%1$d / %2$d files processed', 'external-media-importer'),

                // Scan results
                'noExternalFiles'       => __('No posts found with external files.', 'external-media-importer'),
                'noExternalFilesInPost' => __('No external files found in post:', 'external-media-importer'),
                'noActionablePosts'     => __('No actionable posts found.', 'external-media-importer'),
                'foundPostsWithFiles'   => __('Found %d post(s) with external files', 'external-media-importer'),
                'postsSkippedDead'      => __('%d post(s) skipped — all external links were previously found dead or already imported.', 'external-media-importer'),
                'clearDeadLinkHistory'  => __('Clear dead link history', 'external-media-importer'),

                // Resume
                'resumeInfo'            => __('Previous scan interrupted: %1$d / %2$d posts scanned (%3$d%%), %4$d post(s) with files found so far.', 'external-media-importer'),

                // Buttons
                'importSelectedFiles'   => __('Import Selected Files', 'external-media-importer'),
                'importSelectedCount'   => __('Import Selected Files (%d)', 'external-media-importer'),
                'dryRunBtn'             => __('Dry Run (Check Sizes)', 'external-media-importer'),
                'dryRunBtnCount'        => __('Dry Run (Check Sizes) (%d)', 'external-media-importer'),
                'checking'              => __('Checking...', 'external-media-importer'),
                'loading'               => __('Loading...', 'external-media-importer'),

                // Dry run
                'dryRunTotal'           => __('Total: %1$d file(s), %2$s', 'external-media-importer'),

                // Import log messages
                'processing'            => __('Processing: %1$s (Post: %2$s)', 'external-media-importer'),
                'importSuccess'         => __('Successfully imported: %1$s (Attachment ID: %2$d)', 'external-media-importer'),
                'urlReplaced'           => __('URL replaced in post content', 'external-media-importer'),
                'skippedFile'           => __('Skipped: %1$s - %2$s', 'external-media-importer'),
                'errorImporting'        => __('Error importing %1$s: %2$s', 'external-media-importer'),
                'ajaxError'             => __('AJAX Error for %1$s: %2$s', 'external-media-importer'),
                'importCompleted'       => __('Import process completed!', 'external-media-importer'),
                'importCompletedAlert'  => __('Import completed! Check the logs for details. You can rescan to see if there are any remaining external files.', 'external-media-importer'),

                // Table headers
                'thPostId'              => __('Post ID', 'external-media-importer'),
                'thPostTitle'           => __('Post Title', 'external-media-importer'),
                'thExternalFiles'       => __('External Files (select to import)', 'external-media-importer'),

                // Badges
                'alreadyImported'       => __('Already imported (ID: %d)', 'external-media-importer'),
                'deadLink'              => __('Dead link', 'external-media-importer'),

                // Retry
                'confirmRetry'          => __('Retry all failed imports? Old error log entries will be removed and files will be re-imported.', 'external-media-importer'),
                'retryingFiles'         => __('Retrying %d file(s)...', 'external-media-importer'),
                'retryCompleted'        => __('Retry process completed!', 'external-media-importer'),
                'noFailedImports'       => __('No failed imports found.', 'external-media-importer'),
                'retryFailedBtn'        => __('Retry Failed Imports', 'external-media-importer'),
                'failedFetchErrors'     => __('Failed to fetch error entries.', 'external-media-importer'),
                'doneReload'            => __('Done! Reload page to see updated logs.', 'external-media-importer'),
                'retryImported'         => __('Imported: %1$s (Attachment ID: %2$d)', 'external-media-importer'),
                'retryFailed'           => __('Failed again: %1$s - %2$s', 'external-media-importer'),

                // Dead links
                'confirmClearDead'      => __('Clear all dead link records? Posts with dead links will appear again on next scan.', 'external-media-importer'),
                'clearing'              => __('Clearing...', 'external-media-importer'),
                'pleaseRescan'          => __('Please re-scan to see them.', 'external-media-importer'),
                'ajaxErrorRetry'        => __('AJAX error. Please try again.', 'external-media-importer'),

                // Generic
                'error'                 => __('Error:', 'external-media-importer'),
                'ajaxErrorGeneric'      => __('AJAX Error:', 'external-media-importer'),
            )
        ));
    }
    
    public function render_admin_page() {
        $file_types = get_option('emi_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx,zip,mp4,mp3');

        // Get all post types
        $post_types = get_post_types(array('public' => true), 'objects');
        ?>
        <div class="wrap">
            <h1><?php _e('External Media Importer', 'external-media-importer'); ?></h1>

            <div class="emi-container">
                <div class="emi-section">
                    <h2><?php _e('Scan Posts for External Files', 'external-media-importer'); ?></h2>
                    <form id="emi-scan-form">
                        <table class="form-table">
                            <tr>
                                <th><label for="post_type"><?php _e('Post Type:', 'external-media-importer'); ?></label></th>
                                <td>
                                    <select name="post_type" id="post_type" class="regular-text">
                                        <?php foreach ($post_types as $post_type): ?>
                                            <option value="<?php echo esc_attr($post_type->name); ?>">
                                                <?php echo esc_html($post_type->label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php _e('Select post type to scan for external media files', 'external-media-importer'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('Post Status:', 'external-media-importer'); ?></th>
                                <td>
                                    <fieldset>
                                        <label><input type="checkbox" name="post_status[]" value="publish" class="emi-post-status" checked> <?php _e('Published', 'external-media-importer'); ?></label><br>
                                        <label><input type="checkbox" name="post_status[]" value="draft" class="emi-post-status"> <?php _e('Draft', 'external-media-importer'); ?></label><br>
                                        <label><input type="checkbox" name="post_status[]" value="pending" class="emi-post-status"> <?php _e('Pending Review', 'external-media-importer'); ?></label><br>
                                        <label><input type="checkbox" name="post_status[]" value="private" class="emi-post-status"> <?php _e('Private', 'external-media-importer'); ?></label><br>
                                        <label><input type="checkbox" name="post_status[]" value="future" class="emi-post-status"> <?php _e('Scheduled', 'external-media-importer'); ?></label>
                                    </fieldset>
                                    <p class="description"><?php _e('Select which post statuses to include in the scan', 'external-media-importer'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <p>
                            <button type="submit" class="button button-primary" id="scan-posts-btn">
                                <?php _e('Scan Posts for External Files', 'external-media-importer'); ?>
                            </button>
                        </p>
                    </form>
                </div>

                <div class="emi-section">
                    <h2><?php _e('Quick Scan by Post ID or URL', 'external-media-importer'); ?></h2>
                    <form id="emi-quick-scan-form">
                        <table class="form-table">
                            <tr>
                                <th><label for="emi-quick-scan-input"><?php _e('Post ID or URL:', 'external-media-importer'); ?></label></th>
                                <td>
                                    <input type="text" id="emi-quick-scan-input" class="regular-text" placeholder="<?php esc_attr_e('e.g. 123 or https://example.com/my-post/', 'external-media-importer'); ?>">
                                    <p class="description"><?php _e('Enter a post ID or full post URL to scan a single post for external files', 'external-media-importer'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <p>
                            <button type="submit" class="button button-secondary" id="quick-scan-btn">
                                <?php _e('Quick Scan', 'external-media-importer'); ?>
                            </button>
                        </p>
                    </form>
                </div>

                <div class="emi-section" id="emi-resume-section" style="display:none;">
                    <h2><?php _e('Resume Interrupted Scan', 'external-media-importer'); ?></h2>
                    <p id="emi-resume-info"></p>
                    <p>
                        <button type="button" class="button button-primary" id="emi-resume-btn"><?php _e('Resume Scan', 'external-media-importer'); ?></button>
                        <button type="button" class="button" id="emi-resume-dismiss"><?php _e('Dismiss', 'external-media-importer'); ?></button>
                    </p>
                </div>

                <div class="emi-section" id="posts-section" style="display:none;">
                    <h2><?php _e('Posts with External Files', 'external-media-importer'); ?></h2>
                    <p class="description"><?php _e('Select posts and files to import', 'external-media-importer'); ?></p>

                    <div id="scan-progress" style="display:none; margin-bottom: 20px;">
                        <p><strong><?php _e('Scanning posts...', 'external-media-importer'); ?></strong></p>
                        <div class="progress-bar-container">
                            <div class="progress-bar" id="scan-progress-bar"></div>
                        </div>
                        <p id="scan-progress-text"><?php _e('0 / 0 posts scanned', 'external-media-importer'); ?></p>
                    </div>

                    <div id="posts-list"></div>

                    <div class="import-controls" style="margin-top: 20px; display:none;" id="import-controls">
                        <button type="button" class="button button-primary" id="import-selected-btn" disabled>
                            <?php _e('Import Selected Files', 'external-media-importer'); ?>
                        </button>
                        <button type="button" class="button" id="dry-run-btn" disabled>
                            <?php _e('Dry Run (Check Sizes)', 'external-media-importer'); ?>
                        </button>
                        <button type="button" class="button" id="select-all-files-btn"><?php _e('Select All Files', 'external-media-importer'); ?></button>
                        <button type="button" class="button" id="deselect-all-files-btn"><?php _e('Deselect All Files', 'external-media-importer'); ?></button>
                        <span id="dry-run-summary" style="display:none; margin-left: 10px; font-weight: 600;"></span>
                    </div>

                    <div id="dry-run-progress" style="display:none; margin-top: 20px;">
                        <h3><?php _e('Dry Run Progress', 'external-media-importer'); ?></h3>
                        <div class="progress-bar-container">
                            <div class="progress-bar" id="dry-run-bar"></div>
                        </div>
                        <p id="dry-run-text"><?php _e('0 / 0 files checked', 'external-media-importer'); ?></p>
                    </div>

                    <div id="import-progress" style="display:none; margin-top: 20px;">
                        <h3><?php _e('Import Progress', 'external-media-importer'); ?></h3>
                        <div class="progress-bar-container">
                            <div class="progress-bar" id="progress-bar"></div>
                        </div>
                        <p id="progress-text"><?php _e('0 / 0 files processed', 'external-media-importer'); ?></p>
                        <div id="import-log"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .emi-container { max-width: 1200px; }
            .emi-section { background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .file-item { padding: 10px; border: 1px solid #ddd; margin: 10px 0; background: #f9f9f9; }
            .file-item:hover { background: #f0f0f0; }
            .file-item input[type="checkbox"] { margin-right: 10px; }
            .file-item .file-url { color: #666; font-size: 12px; word-break: break-all; }
            .progress-bar-container { width: 100%; height: 30px; background: #f0f0f0; border: 1px solid #ddd; border-radius: 3px; overflow: hidden; }
            .progress-bar { height: 100%; background: #2271b1; transition: width 0.3s; text-align: center; line-height: 30px; color: #fff; font-weight: bold; }
            #import-log { margin-top: 15px; max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff; }
            .log-entry { padding: 5px; margin: 5px 0; border-left: 4px solid #ddd; padding-left: 10px; }
            .log-entry.success { border-color: #46b450; background: #f0fff0; }
            .log-entry.error { border-color: #dc3232; background: #fff0f0; }
            .log-entry.skipped { border-color: #ffb900; background: #fffbf0; }
            
            .posts-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            .posts-table th { background: #f0f0f0; padding: 12px; text-align: left; border-bottom: 2px solid #ddd; font-weight: 600; }
            .posts-table td { padding: 12px; border-bottom: 1px solid #eee; vertical-align: top; }
            .posts-table tr:hover { background: #f9f9f9; }
            .post-row { border-bottom: 1px solid #ddd; }
            .post-header { background: #f8f9fa; font-weight: 600; }
            .file-checkbox-label { display: block; padding: 5px 0; cursor: pointer; }
            .file-checkbox-label:hover { background: #f0f0f0; padding-left: 5px; }
            .external-url { color: #666; font-size: 11px; font-family: monospace; display: block; margin-top: 3px; word-break: break-all; }
        </style>
        <?php
    }
    
    public function render_logs_page() {
        global $wpdb;

        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Status filter
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $where = '';
        if (in_array($status_filter, array('success', 'error', 'skipped'), true)) {
            $where = $wpdb->prepare(" WHERE status = %s", $status_filter);
        }

        $total_items = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}{$where}"
        );
        $total_pages = ceil($total_items / $per_page);

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}{$where} ORDER BY processed_date DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        // Count by status for filter links
        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt FROM {$this->table_name} GROUP BY status",
            OBJECT_K
        );
        $count_all = $total_items;
        if ($status_filter) {
            $count_all = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        }

        $base_url = admin_url('admin.php?page=external-media-logs');
        ?>
        <div class="wrap">
            <h1><?php _e('Import Logs', 'external-media-importer'); ?></h1>

            <ul class="subsubsub">
                <li>
                    <a href="<?php echo esc_url($base_url); ?>"
                       class="<?php echo $status_filter === '' ? 'current' : ''; ?>">
                        <?php _e('All', 'external-media-importer'); ?> <span class="count">(<?php echo esc_html($count_all); ?>)</span>
                    </a> |
                </li>
                <?php
                $statuses = array('success' => __('Success', 'external-media-importer'), 'error' => __('Error', 'external-media-importer'), 'skipped' => __('Skipped', 'external-media-importer'));
                $last_key = array_key_last($statuses);
                foreach ($statuses as $key => $label):
                    $cnt = isset($counts[$key]) ? (int) $counts[$key]->cnt : 0;
                ?>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('status', $key, $base_url)); ?>"
                       class="<?php echo $status_filter === $key ? 'current' : ''; ?>">
                        <?php echo esc_html($label); ?>
                        <span class="count">(<?php echo esc_html($cnt); ?>)</span>
                    </a><?php echo $key !== $last_key ? ' |' : ''; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($total_items > 0): ?>
            <div style="float: right; margin-top: 5px;">
                <?php
                $error_count = isset($counts['error']) ? (int) $counts['error']->cnt : 0;
                if ($error_count > 0):
                ?>
                <button type="button" class="button" id="emi-retry-failed-btn">
                    <?php echo esc_html(sprintf(__('Retry Failed Imports (%d)', 'external-media-importer'), $error_count)); ?>
                </button>
                <?php endif; ?>
                <?php
                $export_url = wp_nonce_url(
                    add_query_arg(array(
                        'emi_export_csv' => '1',
                        'status' => $status_filter ?: '',
                    ), admin_url('admin.php')),
                    'emi_export_csv'
                );
                ?>
                <a href="<?php echo esc_url($export_url); ?>" class="button">
                    <?php echo esc_html(sprintf(__('Export %s Logs to CSV', 'external-media-importer'), $status_filter ? ucfirst($status_filter) : __('All', 'external-media-importer'))); ?>
                </a>
            </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th><?php _e('ID', 'external-media-importer'); ?></th>
                        <th><?php _e('Post ID', 'external-media-importer'); ?></th>
                        <th><?php _e('Post Title', 'external-media-importer'); ?></th>
                        <th><?php _e('Original URL', 'external-media-importer'); ?></th>
                        <th><?php _e('Status', 'external-media-importer'); ?></th>
                        <th><?php _e('Attachment ID', 'external-media-importer'); ?></th>
                        <th><?php _e('Error Message', 'external-media-importer'); ?></th>
                        <th><?php _e('Date', 'external-media-importer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="8"><?php _e('No logs found', 'external-media-importer'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log->id); ?></td>
                                <td>
                                    <a href="<?php echo get_edit_post_link($log->post_id); ?>" target="_blank">
                                        <?php echo esc_html($log->post_id); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($log->post_title); ?></td>
                                <td style="max-width: 300px; word-break: break-all;">
                                    <?php echo esc_html($log->original_url); ?>
                                </td>
                                <td>
                                    <span class="status-<?php echo esc_attr($log->status); ?>">
                                        <?php echo esc_html(ucfirst($log->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($log->new_attachment_id): ?>
                                        <a href="<?php echo get_edit_post_link($log->new_attachment_id); ?>" target="_blank">
                                            <?php echo esc_html($log->new_attachment_id); ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log->error_message); ?></td>
                                <td><?php echo esc_html($log->processed_date); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div id="emi-retry-progress" style="display: none; margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                <h3><?php _e('Retry Progress', 'external-media-importer'); ?></h3>
                <div style="width: 100%; height: 30px; background: #f0f0f0; border: 1px solid #ddd; border-radius: 3px; overflow: hidden;">
                    <div id="emi-retry-bar" style="height: 100%; background: #2271b1; transition: width 0.3s; text-align: center; line-height: 30px; color: #fff; font-weight: bold; width: 0%;">0%</div>
                </div>
                <p id="emi-retry-text"><?php _e('0 / 0 files processed', 'external-media-importer'); ?></p>
                <div id="emi-retry-log" style="margin-top: 15px; max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fff;"></div>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php echo esc_html(sprintf(_n('%s item', '%s items', $total_items, 'external-media-importer'), number_format($total_items))); ?>
                    </span>
                    <?php
                    $pagination_args = array(
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $current_page,
                        'total'   => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    );
                    echo paginate_links($pagination_args);
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <style>
            .status-success { color: #46b450; font-weight: bold; }
            .status-error { color: #dc3232; font-weight: bold; }
            .status-skipped { color: #ffb900; font-weight: bold; }
        </style>
        <?php
    }
    
    /**
     * Handle CSV export of logs via direct download.
     * Triggered by admin_init when emi_export_csv GET parameter is present.
     */
    public function handle_csv_export() {
        if (!isset($_GET['emi_export_csv']) || $_GET['emi_export_csv'] !== '1') {
            return;
        }

        if (!current_user_can($this->get_required_cap())) {
            wp_die(__('Insufficient permissions', 'external-media-importer'));
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'emi_export_csv')) {
            wp_die(__('Invalid security token', 'external-media-importer'));
        }

        global $wpdb;

        // Apply same status filter as the logs page
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $where = '';
        if (in_array($status_filter, array('success', 'error', 'skipped'), true)) {
            $where = $wpdb->prepare(" WHERE status = %s", $status_filter);
        }

        $logs = $wpdb->get_results(
            "SELECT * FROM {$this->table_name}{$where} ORDER BY processed_date DESC"
        );

        // Generate filename with date and optional status
        $filename = 'emi-logs';
        if ($status_filter) {
            $filename .= '-' . $status_filter;
        }
        $filename .= '-' . date('Y-m-d') . '.csv';

        // Send CSV headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // CSV header row
        fputcsv($output, array(
            'ID',
            'Post ID',
            'Post Title',
            'Original URL',
            'Status',
            'Attachment ID',
            'Error Message',
            'Date'
        ));

        // Data rows
        foreach ($logs as $log) {
            fputcsv($output, array(
                $log->id,
                $log->post_id,
                $log->post_title,
                $log->original_url,
                $log->status,
                $log->new_attachment_id ?: '',
                $log->error_message ?: '',
                $log->processed_date
            ));
        }

        fclose($output);
        exit;
    }

    public function render_settings_page() {
        global $wpdb;

        if (isset($_POST['emi_save_settings']) && check_admin_referer('emi_settings_nonce')) {
            update_option('emi_file_types', sanitize_text_field($_POST['file_types']));
            // Sanitize each URL individually, then store as comma-separated string
            $raw_urls = isset($_POST['external_url']) ? sanitize_text_field($_POST['external_url']) : '';
            $url_parts = array_filter(array_map('trim', explode(',', $raw_urls)));
            $sanitized_urls = array_map('esc_url_raw', $url_parts);
            update_option('emi_external_url', implode(',', array_filter($sanitized_urls)));
            $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
            $batch_size = max(10, min(200, $batch_size)); // Clamp between 10 and 200
            update_option('emi_batch_size', $batch_size);
            $capability = isset($_POST['capability']) ? sanitize_text_field($_POST['capability']) : 'manage_options';
            $allowed_caps = array('manage_options', 'edit_posts');
            update_option('emi_capability', in_array($capability, $allowed_caps, true) ? $capability : 'manage_options');
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved!', 'external-media-importer') . '</p></div>';
        }

        // Handle clear logs action
        if (isset($_POST['emi_clear_logs']) && check_admin_referer('emi_clear_logs_nonce')) {
            $wpdb->query("TRUNCATE TABLE {$this->table_name}");
            echo '<div class="notice notice-success"><p>' . esc_html__('All import logs cleared.', 'external-media-importer') . '</p></div>';
        }

        $file_types = get_option('emi_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx,zip,mp4,mp3');
        $external_url_raw = get_option('emi_external_url', '');
        $saved_urls = array_filter(array_map('trim', explode(',', $external_url_raw)));
        $batch_size = (int) get_option('emi_batch_size', 50);
        $capability = get_option('emi_capability', 'manage_options');
        $log_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        $saved_extensions = array_filter(array_map('trim', explode(',', $file_types)));

        $predefined = array(
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'webp', 'svg',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf',
            'zip', 'rar', '7z', 'tar', 'gz',
            'mp4', 'mp3', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'wav', 'ogg', 'webm'
        );

        // Merge predefined + any custom saved extensions
        $all_options = array_unique(array_merge($predefined, $saved_extensions));
        sort($all_options);
        ?>
        <div class="wrap">
            <h1><?php _e('External Media Importer Settings', 'external-media-importer'); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field('emi_settings_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="emi_file_types_select"><?php _e('Allowed File Types:', 'external-media-importer'); ?></label></th>
                        <td>
                            <select id="emi_file_types_select" multiple="multiple" style="width: 100%; max-width: 600px;">
                                <?php foreach ($all_options as $ext): ?>
                                    <option value="<?php echo esc_attr($ext); ?>"
                                        <?php echo in_array($ext, $saved_extensions) ? 'selected' : ''; ?>>
                                        <?php echo esc_html($ext); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="file_types" id="emi_file_types_hidden"
                                   value="<?php echo esc_attr($file_types); ?>">
                            <p class="description"><?php _e('Select file extensions or type custom ones. Press Enter to add a new extension.', 'external-media-importer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="emi_external_urls_select"><?php _e('External Server URLs:', 'external-media-importer'); ?></label></th>
                        <td>
                            <select id="emi_external_urls_select" multiple="multiple" style="width: 100%; max-width: 600px;">
                                <?php foreach ($saved_urls as $url): ?>
                                    <option value="<?php echo esc_attr($url); ?>" selected>
                                        <?php echo esc_html($url); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="external_url" id="emi_external_urls_hidden"
                                   value="<?php echo esc_attr($external_url_raw); ?>">
                            <p class="description"><?php _e('Base URLs of external servers (optional). Type a URL and press Enter to add. Leave empty to scan all external URLs.', 'external-media-importer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="batch_size"><?php _e('Scan Batch Size:', 'external-media-importer'); ?></label></th>
                        <td>
                            <input type="number" name="batch_size" id="batch_size"
                                   value="<?php echo esc_attr($batch_size); ?>"
                                   min="10" max="200" step="10"
                                   class="small-text">
                            <p class="description"><?php _e('Number of posts to scan per AJAX request (10-200). Lower values use less memory; higher values scan faster. Default: 50', 'external-media-importer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="capability"><?php _e('Required Permission:', 'external-media-importer'); ?></label></th>
                        <td>
                            <select name="capability" id="capability">
                                <option value="manage_options" <?php selected($capability, 'manage_options'); ?>><?php _e('Administrators Only (manage_options)', 'external-media-importer'); ?></option>
                                <option value="edit_posts" <?php selected($capability, 'edit_posts'); ?>><?php _e('Editors and above (edit_posts)', 'external-media-importer'); ?></option>
                            </select>
                            <p class="description"><?php _e('Minimum user role required to access all plugin features. Default: Administrators Only', 'external-media-importer'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="emi_save_settings" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'external-media-importer'); ?>">
                </p>
            </form>

            <hr>
            <h2><?php _e('Data Management', 'external-media-importer'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('emi_clear_logs_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Import Logs', 'external-media-importer'); ?></th>
                        <td>
                            <p style="margin-bottom: 10px;">
                                <?php echo esc_html(sprintf(_n('Currently %s log entry in the database.', 'Currently %s log entries in the database.', $log_count, 'external-media-importer'), number_format($log_count))); ?>
                            </p>
                            <button type="submit" name="emi_clear_logs" value="1" class="button"
                                    <?php echo $log_count === 0 ? 'disabled' : ''; ?>
                                    onclick="return confirm('<?php echo esc_js(__('Clear ALL import logs? This cannot be undone.', 'external-media-importer')); ?>');">
                                <?php _e('Clear All Logs', 'external-media-importer'); ?>
                            </button>
                            <p class="description"><?php _e('Permanently delete all import log entries. This does not affect imported files.', 'external-media-importer'); ?></p>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        <?php
    }

    public function render_dashboard_page() {
        if (!current_user_can($this->get_required_cap())) {
            return;
        }

        global $wpdb;

        // Status counts
        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt FROM {$this->table_name} GROUP BY status",
            OBJECT_K
        );

        $total     = 0;
        $success   = isset($counts['success']) ? (int) $counts['success']->cnt : 0;
        $errors    = isset($counts['error']) ? (int) $counts['error']->cnt : 0;
        $skipped   = isset($counts['skipped']) ? (int) $counts['skipped']->cnt : 0;
        $total     = $success + $errors + $skipped;

        $success_pct = $total > 0 ? round(($success / $total) * 100, 1) : 0;
        $error_pct   = $total > 0 ? round(($errors / $total) * 100, 1) : 0;
        $skipped_pct = $total > 0 ? round(($skipped / $total) * 100, 1) : 0;

        // Unique posts affected
        $unique_posts = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$this->table_name}"
        );

        // Disk space: sum file sizes of successfully imported attachments
        $disk_size = get_transient('emi_dashboard_disk_size');
        if ($disk_size === false) {
            $attachment_ids = $wpdb->get_col(
                "SELECT new_attachment_id FROM {$this->table_name} WHERE status = 'success' AND new_attachment_id IS NOT NULL"
            );

            $disk_size = 0;
            if (!empty($attachment_ids)) {
                $upload_dir = wp_upload_dir();
                $base_dir = $upload_dir['basedir'];

                foreach ($attachment_ids as $att_id) {
                    $file = get_post_meta($att_id, '_wp_attached_file', true);
                    if ($file) {
                        $filepath = $base_dir . '/' . $file;
                        if (file_exists($filepath)) {
                            $disk_size += filesize($filepath);
                        }
                    }
                }
            }

            set_transient('emi_dashboard_disk_size', $disk_size, HOUR_IN_SECONDS);
        }

        // Top 5 errors
        $top_errors = $wpdb->get_results(
            "SELECT error_message, COUNT(*) as cnt
             FROM {$this->table_name}
             WHERE status IN ('error', 'skipped') AND error_message != ''
             GROUP BY error_message
             ORDER BY cnt DESC
             LIMIT 5"
        );

        ?>
        <div class="wrap">
            <h1><?php _e('Import Dashboard', 'external-media-importer'); ?></h1>

            <div class="emi-container">
                <div class="emi-section">
                    <h2><?php _e('Overview', 'external-media-importer'); ?></h2>
                    <div class="emi-stats-grid">
                        <div class="emi-stat-card">
                            <div class="stat-number"><?php echo esc_html(number_format($total)); ?></div>
                            <div class="stat-label"><?php _e('Total Imports', 'external-media-importer'); ?></div>
                        </div>
                        <div class="emi-stat-card stat-success">
                            <div class="stat-number"><?php echo esc_html(number_format($success)); ?></div>
                            <div class="stat-label"><?php _e('Successful', 'external-media-importer'); ?></div>
                            <div class="stat-pct"><?php echo esc_html($success_pct); ?>%</div>
                        </div>
                        <div class="emi-stat-card stat-error">
                            <div class="stat-number"><?php echo esc_html(number_format($errors)); ?></div>
                            <div class="stat-label"><?php _e('Failed', 'external-media-importer'); ?></div>
                            <div class="stat-pct"><?php echo esc_html($error_pct); ?>%</div>
                        </div>
                        <div class="emi-stat-card stat-skipped">
                            <div class="stat-number"><?php echo esc_html(number_format($skipped)); ?></div>
                            <div class="stat-label"><?php _e('Skipped (Dead)', 'external-media-importer'); ?></div>
                            <div class="stat-pct"><?php echo esc_html($skipped_pct); ?>%</div>
                        </div>
                    </div>
                </div>

                <div class="emi-section">
                    <h2><?php _e('Storage & Coverage', 'external-media-importer'); ?></h2>
                    <div class="emi-stats-grid emi-stats-grid-2">
                        <div class="emi-stat-card stat-info">
                            <div class="stat-number"><?php echo esc_html(size_format($disk_size)); ?></div>
                            <div class="stat-label"><?php _e('Disk Space Used', 'external-media-importer'); ?></div>
                            <div class="stat-detail"><?php echo esc_html(sprintf(_n('%s imported file', '%s imported files', $success, 'external-media-importer'), number_format($success))); ?></div>
                        </div>
                        <div class="emi-stat-card stat-info">
                            <div class="stat-number"><?php echo esc_html(number_format($unique_posts)); ?></div>
                            <div class="stat-label"><?php _e('Posts Affected', 'external-media-importer'); ?></div>
                            <div class="stat-detail"><?php _e('Unique posts with import activity', 'external-media-importer'); ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($top_errors)): ?>
                <div class="emi-section">
                    <h2><?php _e('Top Errors', 'external-media-importer'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 80px;"><?php _e('Count', 'external-media-importer'); ?></th>
                                <th><?php _e('Error Message', 'external-media-importer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_errors as $err): ?>
                            <tr>
                                <td><strong><?php echo esc_html(number_format((int) $err->cnt)); ?></strong></td>
                                <td><?php echo esc_html($err->error_message); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if ($total === 0): ?>
                <div class="emi-section">
                    <p style="text-align: center; color: #666; font-style: italic; padding: 20px 0;">
                        <?php printf(__('No import data yet. Start by scanning posts on the %smain page%s.', 'external-media-importer'), '<a href="' . esc_url(admin_url('admin.php?page=external-media-importer')) . '">', '</a>'); ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function ajax_scan_all_posts() {
        try {
            check_ajax_referer('emi_scan_all_posts', 'nonce');

            if (!current_user_can($this->get_required_cap())) {
                wp_send_json_error(__('Insufficient permissions', 'external-media-importer'));
                return;
            }

            global $wpdb;

            $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';
            $file_types = get_option('emi_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx,zip,mp4,mp3');
            $extensions = array_filter(array_map('trim', explode(',', $file_types)));

            // Post statuses — default to 'publish' if none provided
            $allowed_statuses = array('publish', 'draft', 'pending', 'private', 'future');
            $post_statuses = isset($_POST['post_statuses']) ? array_map('sanitize_text_field', $_POST['post_statuses']) : array('publish');
            $post_statuses = array_intersect($post_statuses, $allowed_statuses);
            if (empty($post_statuses)) {
                $post_statuses = array('publish');
            }

            // Build SQL pre-filter: post_content must contain 'http' AND at least one extension
            // This skips posts that can't possibly have external file URLs
            $like_clauses = array();
            foreach ($extensions as $ext) {
                $like_clauses[] = $wpdb->prepare(
                    "post_content LIKE %s",
                    '%.' . $wpdb->esc_like($ext) . '%'
                );
            }

            $extensions_where = '(' . implode(' OR ', $like_clauses) . ')';

            // Build status IN clause with proper escaping
            $status_placeholders = implode(', ', array_fill(0, count($post_statuses), '%s'));

            $query_args = array_merge(
                array($post_type),
                $post_statuses,
                array('%http%')
            );

            $post_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = %s
                 AND post_status IN ({$status_placeholders})
                 AND post_content LIKE %s
                 AND {$extensions_where}
                 ORDER BY ID ASC",
                $query_args
            ));

            wp_send_json_success(array(
                'post_ids' => array_map('intval', $post_ids),
                'total' => count($post_ids)
            ));
        } catch (Exception $e) {
            wp_send_json_error(__('Error:', 'external-media-importer') . ' ' . $e->getMessage());
        }
    }
    
    public function ajax_scan_single_post() {
        try {
            check_ajax_referer('emi_scan_single_post', 'nonce');

            if (!current_user_can($this->get_required_cap())) {
                wp_send_json_error(__('Insufficient permissions', 'external-media-importer'));
                return;
            }

            $post_id = 0;

            // Accept either post_id (numeric) or post_url (string)
            if (!empty($_POST['post_id'])) {
                $post_id = intval($_POST['post_id']);
            } elseif (!empty($_POST['post_url'])) {
                $url = esc_url_raw($_POST['post_url']);
                $post_id = url_to_postid($url);
                if (!$post_id) {
                    wp_send_json_error(__('Could not find a post matching this URL. Make sure the URL is a valid post/page permalink on this site.', 'external-media-importer'));
                    return;
                }
            }

            if (!$post_id || !get_post($post_id)) {
                wp_send_json_error(__('Invalid post ID', 'external-media-importer'));
                return;
            }
            
            $post = get_post($post_id);
            $content = $post->post_content;
            
            // Get settings
            $file_types = get_option('emi_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx,zip,mp4,mp3');
            $external_urls = array_filter(array_map('trim', explode(',', get_option('emi_external_url', ''))));

            $extensions = array_map('trim', explode(',', $file_types));
            $upload_dir = wp_upload_dir();

            $files = $this->extract_external_urls(
                $content,
                $extensions,
                $external_urls,
                $upload_dir['baseurl']
            );
            $files = $this->mark_imported_files($post_id, $files);

            wp_send_json_success(array(
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'post_url' => get_permalink($post_id),
                'files' => $files,
                'has_files' => !empty($files)
            ));
        } catch (Exception $e) {
            wp_send_json_error(__('Error:', 'external-media-importer') . ' ' . $e->getMessage());
        }
    }

    public function ajax_scan_batch_posts() {
        try {
            check_ajax_referer('emi_scan_batch_posts', 'nonce');

            if (!current_user_can($this->get_required_cap())) {
                wp_send_json_error(__('Insufficient permissions', 'external-media-importer'));
                return;
            }

            $post_ids = isset($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : array();
            $post_ids = array_filter($post_ids);

            if (empty($post_ids)) {
                wp_send_json_error(__('No post IDs provided', 'external-media-importer'));
                return;
            }

            // Get settings once for the entire batch
            $file_types = get_option('emi_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx,zip,mp4,mp3');
            $external_urls = array_filter(array_map('trim', explode(',', get_option('emi_external_url', ''))));
            $upload_dir = wp_upload_dir();
            $extensions = array_map('trim', explode(',', $file_types));

            // Fetch all posts in one query
            $posts = get_posts(array(
                'post__in' => $post_ids,
                'post_type' => 'any',
                'post_status' => 'any',
                'posts_per_page' => count($post_ids),
                'orderby' => 'none'
            ));

            $results = array();
            $skipped_post_count = 0;

            foreach ($posts as $post) {
                $files = $this->extract_external_urls(
                    $post->post_content,
                    $extensions,
                    $external_urls,
                    $upload_dir['baseurl']
                );
                $files = $this->mark_imported_files($post->ID, $files);

                if (!empty($files)) {
                    // Check if ALL files in this post are already resolved (imported or dead)
                    $has_actionable = false;
                    foreach ($files as $file) {
                        if (!$file['imported'] && !$file['dead_link']) {
                            $has_actionable = true;
                            break;
                        }
                    }

                    if ($has_actionable) {
                        $results[] = array(
                            'post_id' => $post->ID,
                            'post_title' => $post->post_title,
                            'post_url' => get_permalink($post->ID),
                            'files' => $files,
                            'has_files' => true
                        );
                    } else {
                        $skipped_post_count++;
                    }
                }
            }

            wp_send_json_success(array(
                'posts' => $results,
                'skipped_dead_links' => $skipped_post_count
            ));
        } catch (Exception $e) {
            wp_send_json_error(__('Error:', 'external-media-importer') . ' ' . $e->getMessage());
        }
    }

    public function ajax_scan_post() {
        check_ajax_referer('emi_scan_post', 'nonce');
        
        if (!current_user_can($this->get_required_cap())) {
            wp_send_json_error(__('Insufficient permissions', 'external-media-importer'));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(__('Invalid post ID', 'external-media-importer'));
        }
        
        $post = get_post($post_id);
        $content = $post->post_content;
        
        // Get settings
        $file_types = get_option('emi_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx,zip,mp4,mp3');
        $external_urls = array_filter(array_map('trim', explode(',', get_option('emi_external_url', ''))));

        $extensions = array_map('trim', explode(',', $file_types));
        $upload_dir = wp_upload_dir();

        $files = $this->extract_external_urls(
            $content,
            $extensions,
            $external_urls,
            $upload_dir['baseurl']
        );
        $files = $this->mark_imported_files($post_id, $files);

        wp_send_json_success(array(
            'files' => $files,
            'post_title' => $post->post_title,
            'post_id' => $post_id
        ));
    }
    
    public function ajax_import_file() {
        check_ajax_referer('emi_import_file', 'nonce');
        
        if (!current_user_can($this->get_required_cap())) {
            wp_send_json_error(__('Insufficient permissions', 'external-media-importer'));
        }
        
        $url = esc_url_raw($_POST['url']);
        $post_id = intval($_POST['post_id']);
        
        if (!$url || !$post_id) {
            wp_send_json_error(__('Missing required parameters', 'external-media-importer'));
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(__('Post not found', 'external-media-importer'));
        }

        // Check if this URL was already successfully imported for this post
        $existing_attachment = $this->is_already_imported($post_id, $url);
        if ($existing_attachment !== false) {
            wp_send_json_success(array(
                'status' => 'skipped',
                'message' => sprintf(__('Already imported (Attachment ID: %d)', 'external-media-importer'), $existing_attachment)
            ));
        }

        // Check if file exists on remote server
        $response = wp_remote_head($url, array('timeout' => 10));
        
        if (is_wp_error($response)) {
            $this->log_import($post_id, $post->post_title, $url, null, 'error', $response->get_error_message());
            wp_send_json_error(__('Failed to check remote file:', 'external-media-importer') . ' ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->log_import($post_id, $post->post_title, $url, null, 'skipped', 'File not found on remote server (HTTP ' . $response_code . ')');
            wp_send_json_success(array(
                'status' => 'skipped',
                'message' => __('File no longer exists on remote server', 'external-media-importer')
            ));
        }
        
        // Download file
        $tmp_file = download_url($url);
        
        if (is_wp_error($tmp_file)) {
            $this->log_import($post_id, $post->post_title, $url, null, 'error', $tmp_file->get_error_message());
            wp_send_json_error(__('Failed to download file:', 'external-media-importer') . ' ' . $tmp_file->get_error_message());
        }
        
        // Prepare file array
        $file_array = array(
            'name' => basename(parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp_file
        );
        
        // Get post date for organizing uploads
        $post_date = $post->post_date;
        
        // Temporarily filter upload directory (store reference for clean removal)
        $upload_dir_callback = function($uploads) use ($post_date) {
            $time = strtotime($post_date);
            $y = date('Y', $time);
            $m = date('m', $time);

            $uploads['path'] = $uploads['basedir'] . '/' . $y . '/' . $m;
            $uploads['url'] = $uploads['baseurl'] . '/' . $y . '/' . $m;
            $uploads['subdir'] = '/' . $y . '/' . $m;

            return $uploads;
        };
        add_filter('upload_dir', $upload_dir_callback);
        
        // Import to media library
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $attachment_id = media_handle_sideload($file_array, $post_id);
        
        // Remove only our upload_dir filter, preserving any other filters on this hook
        remove_filter('upload_dir', $upload_dir_callback);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            $this->log_import($post_id, $post->post_title, $url, null, 'error', $attachment_id->get_error_message());
            wp_send_json_error(__('Failed to import file:', 'external-media-importer') . ' ' . $attachment_id->get_error_message());
        }
        
        // Set attachment date to match post date
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_date' => $post_date,
            'post_date_gmt' => get_gmt_from_date($post_date)
        ));
        
        // Get the new attachment URL
        $new_url = wp_get_attachment_url($attachment_id);

        // Update post content - replace all variants of the old URL with new URL
        $post_content = $post->post_content;
        $updated_content = $this->replace_url_variants($post_content, $url, $new_url);

        // Only update if content actually changed
        if ($updated_content !== $post_content) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $updated_content
            ));
        }
        
        // Log success
        $this->log_import($post_id, $post->post_title, $url, $attachment_id, 'success', null);
        
        wp_send_json_success(array(
            'status' => 'success',
            'message' => __('File imported successfully', 'external-media-importer'),
            'attachment_id' => $attachment_id,
            'attachment_url' => $new_url,
            'url_replaced' => ($updated_content !== $post_content)
        ));
    }
    
    /**
     * Clear all dead-link (skipped due to HTTP error) records from the log table.
     */
    public function ajax_clear_dead_links() {
        check_ajax_referer('emi_clear_dead_links', 'nonce');

        if (!current_user_can($this->get_required_cap())) {
            wp_send_json_error(__('Insufficient permissions', 'external-media-importer'));
        }

        global $wpdb;

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE status = 'skipped' AND error_message LIKE %s",
            '%not found on remote server%'
        ));

        wp_send_json_success(array(
            'deleted' => intval($deleted),
            'message' => sprintf(_n('%d dead link record cleared.', '%d dead link records cleared.', intval($deleted), 'external-media-importer'), intval($deleted))
        ));
    }

    /**
     * Get all failed (error) import log entries for retry.
     */
    public function ajax_get_failed_imports() {
        check_ajax_referer('emi_get_failed_imports', 'nonce');

        if (!current_user_can($this->get_required_cap())) {
            wp_send_json_error(__('Insufficient permissions', 'external-media-importer'));
        }

        global $wpdb;

        $failed = $wpdb->get_results(
            "SELECT id, post_id, post_title, original_url, error_message, processed_date
             FROM {$this->table_name}
             WHERE status = 'error'
             ORDER BY processed_date DESC"
        );

        $items = array();
        foreach ($failed as $entry) {
            $items[] = array(
                'log_id'        => intval($entry->id),
                'post_id'       => intval($entry->post_id),
                'post_title'    => $entry->post_title,
                'url'           => $entry->original_url,
                'error_message' => $entry->error_message,
                'date'          => $entry->processed_date
            );
        }

        wp_send_json_success(array(
            'items' => $items,
            'total' => count($items)
        ));
    }

    /**
     * Delete a specific log entry by ID (used before retrying to avoid duplicates).
     */
    public function ajax_delete_log_entry() {
        check_ajax_referer('emi_delete_log_entry', 'nonce');

        if (!current_user_can($this->get_required_cap())) {
            wp_send_json_error(__('Insufficient permissions', 'external-media-importer'));
        }

        $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
        if (!$log_id) {
            wp_send_json_error(__('Missing log ID', 'external-media-importer'));
        }

        global $wpdb;

        $wpdb->delete($this->table_name, array('id' => $log_id), array('%d'));

        wp_send_json_success();
    }

    /**
     * Dry run: fetch file sizes via HEAD requests without downloading.
     * Accepts an array of URLs and returns size + content type for each.
     */
    public function ajax_dry_run() {
        check_ajax_referer('emi_dry_run', 'nonce');

        if (!current_user_can($this->get_required_cap())) {
            wp_send_json_error(__('Insufficient permissions', 'external-media-importer'));
        }

        $urls = isset($_POST['urls']) ? array_map('esc_url_raw', $_POST['urls']) : array();
        if (empty($urls)) {
            wp_send_json_error(__('No URLs provided', 'external-media-importer'));
        }

        $results = array();
        $total_size = 0;

        foreach ($urls as $url) {
            $response = wp_remote_head($url, array('timeout' => 10));

            if (is_wp_error($response)) {
                $results[$url] = array(
                    'size' => null,
                    'size_formatted' => 'Error',
                    'content_type' => null,
                    'error' => $response->get_error_message()
                );
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                $results[$url] = array(
                    'size' => null,
                    'size_formatted' => 'HTTP ' . $code,
                    'content_type' => null,
                    'error' => 'HTTP ' . $code
                );
                continue;
            }

            $size = (int) wp_remote_retrieve_header($response, 'content-length');
            $content_type = wp_remote_retrieve_header($response, 'content-type');

            $results[$url] = array(
                'size' => $size,
                'size_formatted' => $size > 0 ? size_format($size) : 'Unknown',
                'content_type' => $content_type ?: null,
                'error' => null
            );

            if ($size > 0) {
                $total_size += $size;
            }
        }

        wp_send_json_success(array(
            'files' => $results,
            'total_size' => $total_size,
            'total_size_formatted' => size_format($total_size),
            'file_count' => count($urls)
        ));
    }

    /**
     * Extract external file URLs from post content.
     *
     * Uses five strategies:
     * 1. Regex scan for raw URLs ending with a file extension
     * 2. <a href> tag parsing for URLs containing a file extension in the path
     * 3. <img src>, <video src>, <audio src>, <source src> tag parsing
     * 4. <img srcset>, <source srcset> attribute parsing (responsive image variants)
     * 5. CSS background-image: url(...) parsing (inline styles and style blocks)
     *
     * @param string $content        The post content to scan.
     * @param array  $extensions     File extensions to look for.
     * @param array  $external_urls   Optional base URL filters (empty array = no filter).
     * @param string $upload_baseurl The local wp-content/uploads base URL.
     * @return array Array of associative arrays with 'url' and 'filename' keys.
     */
    private function extract_external_urls($content, $extensions, $external_urls, $upload_baseurl) {
        $files = array();
        $seen_urls = array();

        // Sort extensions by length descending so 'docx' matches before 'doc', etc.
        usort($extensions, function($a, $b) { return strlen($b) - strlen($a); });
        $ext_group = implode('|', $extensions);

        // --- Strategy 1: Raw URL regex (existing behavior) ---
        $raw_pattern = '/https?:\/\/[^\s<>"\']+\.(' . $ext_group . ')/i';
        preg_match_all($raw_pattern, $content, $raw_matches);

        if (!empty($raw_matches[0])) {
            foreach ($raw_matches[0] as $url) {
                if ($this->should_include_url($url, $external_urls, $upload_baseurl)) {
                    $normalized = $this->normalize_url($url);
                    if (!isset($seen_urls[$normalized])) {
                        $seen_urls[$normalized] = true;
                        $files[] = array(
                            'url'      => $url,
                            'filename' => basename(parse_url($url, PHP_URL_PATH)),
                        );
                    }
                }
            }
        }

        // --- Strategy 2: <a href> tag parsing ---
        // --- Strategy 3: <img>, <video>, <audio>, <source> tag parsing ---
        // Matches src attributes from media tags
        $tag_patterns = array(
            '/<a\s[^>]*href=["\']([^"\']+)["\']/i',
            '/<img\s[^>]*src=["\']([^"\']+)["\']/i',
            '/<video\s[^>]*src=["\']([^"\']+)["\']/i',
            '/<audio\s[^>]*src=["\']([^"\']+)["\']/i',
            '/<source\s[^>]*src=["\']([^"\']+)["\']/i',
        );

        $ext_check_pattern = '/\.(' . $ext_group . ')(\b|$)/i';

        foreach ($tag_patterns as $tag_pattern) {
            preg_match_all($tag_pattern, $content, $tag_matches);

            if (!empty($tag_matches[1])) {
                foreach ($tag_matches[1] as $url) {
                    // Must be an absolute HTTP(S) URL
                    if (stripos($url, 'http') !== 0) {
                        continue;
                    }

                    // Check if the URL path contains a matching extension
                    $path = parse_url($url, PHP_URL_PATH);
                    if (!$path || !preg_match($ext_check_pattern, $path)) {
                        continue;
                    }

                    if ($this->should_include_url($url, $external_urls, $upload_baseurl)) {
                        $normalized = $this->normalize_url($url);
                        if (!isset($seen_urls[$normalized])) {
                            $seen_urls[$normalized] = true;
                            $files[] = array(
                                'url'      => $url,
                                'filename' => basename($path),
                            );
                        }
                    }
                }
            }
        }

        // --- Strategy 4: srcset attribute parsing ---
        // Matches srcset on <img> and <source> tags (used in <picture> elements)
        $srcset_patterns = array(
            '/<img\s[^>]*srcset=["\']([^"\']+)["\']/i',
            '/<source\s[^>]*srcset=["\']([^"\']+)["\']/i',
        );

        foreach ($srcset_patterns as $srcset_pattern) {
            preg_match_all($srcset_pattern, $content, $srcset_matches);

            if (!empty($srcset_matches[1])) {
                foreach ($srcset_matches[1] as $srcset_value) {
                    // srcset format: "url 300w, url 768w, url 1024w" or "url 1x, url 2x"
                    $entries = explode(',', $srcset_value);
                    foreach ($entries as $entry) {
                        $entry = trim($entry);
                        // Extract URL (first part before space + descriptor)
                        $parts = preg_split('/\s+/', $entry, 2);
                        $url = $parts[0];

                        if (stripos($url, 'http') !== 0) {
                            continue;
                        }

                        $path = parse_url($url, PHP_URL_PATH);
                        if (!$path || !preg_match($ext_check_pattern, $path)) {
                            continue;
                        }

                        if ($this->should_include_url($url, $external_urls, $upload_baseurl)) {
                            $normalized = $this->normalize_url($url);
                            if (!isset($seen_urls[$normalized])) {
                                $seen_urls[$normalized] = true;
                                $files[] = array(
                                    'url'      => $url,
                                    'filename' => basename($path),
                                );
                            }
                        }
                    }
                }
            }
        }

        // --- Strategy 5: CSS background-image: url(...) parsing ---
        // Matches inline style attributes and <style> blocks in post content
        $bg_pattern = '/background-image:\s*url\(\s*[\'"]?(https?:\/\/[^\'")\s]+)[\'"]?\s*\)/i';
        preg_match_all($bg_pattern, $content, $bg_matches);

        if (!empty($bg_matches[1])) {
            foreach ($bg_matches[1] as $url) {
                $path = parse_url($url, PHP_URL_PATH);
                if (!$path || !preg_match($ext_check_pattern, $path)) {
                    continue;
                }

                if ($this->should_include_url($url, $external_urls, $upload_baseurl)) {
                    $normalized = $this->normalize_url($url);
                    if (!isset($seen_urls[$normalized])) {
                        $seen_urls[$normalized] = true;
                        $files[] = array(
                            'url'      => $url,
                            'filename' => basename($path),
                        );
                    }
                }
            }
        }

        return $files;
    }

    /**
     * Determine whether a URL should be included in scan results.
     *
     * @param string $url            The URL to check.
     * @param array  $external_urls  Array of base URL filters (empty = allow all).
     * @param string $upload_baseurl The local wp-content/uploads base URL.
     * @return bool
     */
    private function should_include_url($url, $external_urls, $upload_baseurl) {
        // Filter by external URL prefixes if configured
        if (!empty($external_urls)) {
            $matches_any = false;
            foreach ($external_urls as $base_url) {
                if (strpos($url, $base_url) === 0) {
                    $matches_any = true;
                    break;
                }
            }
            if (!$matches_any) {
                return false;
            }
        }
        // Skip URLs pointing to the local uploads directory
        if (strpos($url, $upload_baseurl) !== false) {
            return false;
        }
        return true;
    }

    /**
     * Normalize a URL for deduplication purposes.
     */
    private function normalize_url($url) {
        return rtrim($url, '/');
    }

    /**
     * Replace all variants of an old URL with the new URL in post content.
     *
     * Handles edge cases:
     * - Exact match (original behavior)
     * - HTML entity-encoded (&amp; instead of &)
     * - URL-encoded characters (%20, etc.)
     * - Protocol-relative URLs (//example.com/...)
     *
     * @param string $content The post content.
     * @param string $old_url The original external URL.
     * @param string $new_url The new local attachment URL.
     * @return string Updated content with all URL variants replaced.
     */
    private function replace_url_variants($content, $old_url, $new_url) {
        // Build list of URL variants to replace (order matters: most specific first)
        $replacements = array();

        // 1. Exact match
        $replacements[$old_url] = $new_url;

        // 2. HTML entity-encoded variant (&amp; for &)
        $html_encoded = str_replace('&', '&amp;', $old_url);
        if ($html_encoded !== $old_url) {
            $replacements[$html_encoded] = str_replace('&', '&amp;', $new_url);
        }

        // 3. URL-encoded variant (decode the old URL and add as alternative)
        $decoded = rawurldecode($old_url);
        if ($decoded !== $old_url) {
            $replacements[$decoded] = $new_url;
        }

        // 4. Fully encoded variant (encode the path portion)
        $parsed = parse_url($old_url);
        if (isset($parsed['path'])) {
            $encoded_path = implode('/', array_map('rawurlencode', explode('/', $parsed['path'])));
            if ($encoded_path !== $parsed['path']) {
                $encoded_url = str_replace($parsed['path'], $encoded_path, $old_url);
                $replacements[$encoded_url] = $new_url;
            }
        }

        // 5. Protocol-relative variant (//example.com/... instead of https://example.com/...)
        if (preg_match('#^https?://#i', $old_url)) {
            $protocol_relative = preg_replace('#^https?:#i', '', $old_url);
            $replacements[$protocol_relative] = preg_replace('#^https?:#i', '', $new_url);
        }

        // Apply all replacements (longest keys first to avoid partial matches)
        uksort($replacements, function($a, $b) { return strlen($b) - strlen($a); });

        foreach ($replacements as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }

        return $content;
    }

    /**
     * Enrich a list of scanned files with import status from the log table.
     *
     * @param int   $post_id The post ID.
     * @param array $files   Array of file arrays with 'url' and 'filename' keys.
     * @return array Files with added 'imported' (bool) and 'attachment_id' (int|null) keys.
     */
    private function mark_imported_files($post_id, $files) {
        global $wpdb;

        if (empty($files)) {
            return $files;
        }

        // Get all successfully imported URLs for this post in one query
        $imported = $wpdb->get_results($wpdb->prepare(
            "SELECT original_url, new_attachment_id FROM {$this->table_name}
             WHERE post_id = %d AND status = 'success'",
            $post_id
        ), OBJECT_K);

        // Get all dead-link (skipped due to HTTP error) URLs for this post
        $skipped = $wpdb->get_results($wpdb->prepare(
            "SELECT original_url, error_message FROM {$this->table_name}
             WHERE post_id = %d AND status = 'skipped'
             AND error_message LIKE %s",
            $post_id,
            '%not found on remote server%'
        ), OBJECT_K);

        foreach ($files as &$file) {
            if (isset($imported[$file['url']])) {
                $file['imported'] = true;
                $file['attachment_id'] = intval($imported[$file['url']]->new_attachment_id);
                $file['dead_link'] = false;
                $file['dead_link_message'] = null;
            } elseif (isset($skipped[$file['url']])) {
                $file['imported'] = false;
                $file['attachment_id'] = null;
                $file['dead_link'] = true;
                $file['dead_link_message'] = $skipped[$file['url']]->error_message;
            } else {
                $file['imported'] = false;
                $file['attachment_id'] = null;
                $file['dead_link'] = false;
                $file['dead_link_message'] = null;
            }
        }
        unset($file);

        return $files;
    }

    /**
     * Check if a URL has already been successfully imported for a given post.
     *
     * @param int    $post_id The post ID.
     * @param string $url     The original external URL.
     * @return int|false Attachment ID if already imported, false otherwise.
     */
    private function is_already_imported($post_id, $url) {
        global $wpdb;

        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT new_attachment_id FROM {$this->table_name}
             WHERE post_id = %d AND original_url = %s AND status = 'success'
             LIMIT 1",
            $post_id,
            $url
        ));

        return $attachment_id ? intval($attachment_id) : false;
    }

    private function log_import($post_id, $post_title, $original_url, $attachment_id, $status, $error_message) {
        global $wpdb;
        
        $wpdb->insert(
            $this->table_name,
            array(
                'post_id' => $post_id,
                'post_title' => $post_title,
                'original_url' => $original_url,
                'new_attachment_id' => $attachment_id,
                'status' => $status,
                'error_message' => $error_message
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s')
        );
    }
}

// Initialize plugin
new External_Media_Importer();
