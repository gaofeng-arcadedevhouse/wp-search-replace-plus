<?php
/**
 * Plugin Name: WorldTeam Search & Replace
 * Plugin URI: https://worldteam.com
 * Description: 强大的WordPress内容搜索替换工具，支持Posts、Pages、ACF字段和PHP模板文件的批量搜索替换功能
 * Version: 1.0.0
 * Author: WorldTeam
 * License: GPL v2 or later
 * Text Domain: worldteam-search-replace
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WTSR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WTSR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WTSR_VERSION', '1.0.0');

// Main plugin class
class WorldTeamSearchReplace {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init();
    }
    
    public function init() {
        // Hook into WordPress
        add_action('init', array($this, 'loadTextDomain'));
        add_action('admin_menu', array($this, 'addAdminMenu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueScripts'));
        add_action('wp_ajax_wtsr_search', array($this, 'ajaxSearch'));
        add_action('wp_ajax_wtsr_replace', array($this, 'ajaxReplace'));
        add_action('wp_ajax_wtsr_backup', array($this, 'ajaxBackup'));
        add_action('wp_ajax_wtsr_test', array($this, 'ajaxTest'));
        
        // Load required classes
        $this->loadClasses();
    }
    
    public function loadTextDomain() {
        load_plugin_textdomain('worldteam-search-replace', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function addAdminMenu() {
        // Only allow administrators
        if (!current_user_can('manage_options')) {
            return;
        }
        
        add_management_page(
            __('搜索替换工具', 'worldteam-search-replace'),
            __('搜索替换', 'worldteam-search-replace'),
            'manage_options',
            'worldteam-search-replace',
            array($this, 'adminPage')
        );
    }
    
    public function enqueueScripts($hook) {
        if ('tools_page_worldteam-search-replace' !== $hook) {
            return;
        }
        
        wp_enqueue_script('wtsr-admin', WTSR_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WTSR_VERSION, true);
        wp_enqueue_style('wtsr-admin', WTSR_PLUGIN_URL . 'assets/css/admin.css', array(), WTSR_VERSION);
        
        wp_localize_script('wtsr-admin', 'wtsr_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wtsr_nonce'),
            'strings' => array(
                'confirm_replace' => __('确认要执行替换操作吗？此操作不可撤销！', 'worldteam-search-replace'),
                'searching' => __('搜索中...', 'worldteam-search-replace'),
                'replacing' => __('替换中...', 'worldteam-search-replace'),
                'completed' => __('操作完成', 'worldteam-search-replace'),
                'error' => __('操作失败', 'worldteam-search-replace')
            )
        ));
    }
    
    public function adminPage() {
        include WTSR_PLUGIN_PATH . 'includes/admin-page.php';
    }
    
    public function ajaxSearch() {
        // Add debugging
        error_log('WTSR: AJAX search handler called');
        
        try {
            // Check nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wtsr_nonce')) {
                error_log('WTSR: Nonce verification failed');
                wp_send_json_error(array(
                    'message' => __('安全验证失败，请刷新页面重试', 'worldteam-search-replace')
                ));
                return;
            }
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                error_log('WTSR: Permission check failed');
                wp_send_json_error(array(
                    'message' => __('权限不足', 'worldteam-search-replace')
                ));
                return;
            }
            
            error_log('WTSR: Creating search handler');
            $search_handler = new WTSR_Search_Handler();
            
            error_log('WTSR: Calling handleSearchRequest');
            $search_handler->handleSearchRequest();
            
        } catch (Exception $e) {
            error_log('WTSR: Exception in AJAX handler: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => sprintf(__('处理请求时发生错误: %s', 'worldteam-search-replace'), $e->getMessage())
            ));
        } catch (Error $e) {
            error_log('WTSR: Fatal error in AJAX handler: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => sprintf(__('系统错误: %s', 'worldteam-search-replace'), $e->getMessage())
            ));
        }
    }
    
    public function ajaxReplace() {
        check_ajax_referer('wtsr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'worldteam-search-replace'));
        }
        
        $replace_handler = new WTSR_Replace_Handler();
        $replace_handler->handleReplaceRequest();
    }
    
    public function ajaxBackup() {
        check_ajax_referer('wtsr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'worldteam-search-replace'));
        }
        
        $backup_handler = new WTSR_Backup_Handler();
        $backup_handler->createBackup();
    }
    
    public function ajaxTest() {
        error_log('WTSR: Test AJAX endpoint called');
        
        // Check if user has proper permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => 'Insufficient permissions'
            ));
            return;
        }
        
        wp_send_json_success(array(
            'message' => 'AJAX连接正常',
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION
        ));
    }
    
    private function loadClasses() {
        // Load base classes first
        require_once WTSR_PLUGIN_PATH . 'includes/class-database-handler.php';
        require_once WTSR_PLUGIN_PATH . 'includes/class-file-scanner.php';
        require_once WTSR_PLUGIN_PATH . 'includes/class-backup-handler.php';
        
        // Load dependent classes
        require_once WTSR_PLUGIN_PATH . 'includes/class-search-handler.php';
        require_once WTSR_PLUGIN_PATH . 'includes/class-replace-handler.php';
    }
    
    // Load classes for activation hook
    public static function loadRequiredClasses() {
        // Only load the minimal required class for activation
        if (!class_exists('WTSR_Database_Handler')) {
            require_once WTSR_PLUGIN_PATH . 'includes/class-database-handler.php';
        }
    }
    
    // Check plugin requirements
    public static function checkRequirements() {
        $errors = array();
        
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            $errors[] = sprintf(__('需要WordPress 5.0或更高版本。当前版本: %s', 'worldteam-search-replace'), get_bloginfo('version'));
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $errors[] = sprintf(__('需要PHP 7.4或更高版本。当前版本: %s', 'worldteam-search-replace'), PHP_VERSION);
        }
        
        // Check required directories
        $required_dirs = array(
            WTSR_PLUGIN_PATH . 'includes/',
            WTSR_PLUGIN_PATH . 'assets/',
            WTSR_PLUGIN_PATH . 'assets/css/',
            WTSR_PLUGIN_PATH . 'assets/js/'
        );
        
        foreach ($required_dirs as $dir) {
            if (!is_dir($dir)) {
                $errors[] = sprintf(__('缺少必需目录: %s', 'worldteam-search-replace'), $dir);
            }
        }
        
        // Check required files
        $required_files = array(
            'includes/class-database-handler.php',
            'includes/class-file-scanner.php',
            'includes/class-backup-handler.php',
            'includes/class-search-handler.php',
            'includes/class-replace-handler.php',
            'includes/admin-page.php',
            'assets/css/admin.css',
            'assets/js/admin.js'
        );
        
        foreach ($required_files as $file) {
            $full_path = WTSR_PLUGIN_PATH . $file;
            if (!file_exists($full_path)) {
                $errors[] = sprintf(__('缺少必需文件: %s', 'worldteam-search-replace'), $file);
            }
        }
        
        return $errors;
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    WorldTeamSearchReplace::getInstance();
});

// Activation hook
register_activation_hook(__FILE__, function() {
    try {
        // Check plugin requirements first
        $requirement_errors = WorldTeamSearchReplace::checkRequirements();
        if (!empty($requirement_errors)) {
            $error_message = __('插件激活失败，不满足系统要求：', 'worldteam-search-replace') . "\n\n";
            $error_message .= implode("\n", $requirement_errors);
            
            wp_die(
                nl2br(esc_html($error_message)),
                __('插件激活错误', 'worldteam-search-replace'),
                array('back_link' => true)
            );
        }
        
        // Load required classes
        WorldTeamSearchReplace::loadRequiredClasses();
        
        // Check if class was loaded successfully
        if (!class_exists('WTSR_Database_Handler')) {
            throw new Exception(__('无法加载数据库处理器类', 'worldteam-search-replace'));
        }
        
        // Create necessary database tables and options
        WTSR_Database_Handler::createTables();
        
        // Set default options
        add_option('wtsr_plugin_version', WTSR_VERSION);
        add_option('wtsr_file_backups', array());
        
        // Create upload directory for backups
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/wtsr-backups';
        
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
            
            // Add security file
            $htaccess_content = "deny from all\n";
            file_put_contents($backup_dir . '/.htaccess', $htaccess_content);
            
            $index_content = "<?php\n// Silence is golden.\n";
            file_put_contents($backup_dir . '/index.php', $index_content);
        }
        
    } catch (Exception $e) {
        // Log error for debugging
        error_log('WorldTeam Search Replace activation failed: ' . $e->getMessage());
        
        // Deactivate the plugin
        deactivate_plugins(plugin_basename(__FILE__));
        
        // Show error message with more details
        $error_details = sprintf(
            __('插件激活失败：%s', 'worldteam-search-replace'),
            $e->getMessage()
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $error_details .= "\n\n" . __('错误堆栈：', 'worldteam-search-replace') . "\n" . $e->getTraceAsString();
        }
        
        wp_die(
            nl2br(esc_html($error_details)),
            __('插件激活错误', 'worldteam-search-replace'),
            array('back_link' => true)
        );
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Clean up if needed - but preserve data by default
}); 