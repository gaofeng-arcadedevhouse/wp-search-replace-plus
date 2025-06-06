<?php
/**
 * Simple test file to check if plugin classes can be loaded
 * This file can be used to debug class loading issues
 */

// Simulate WordPress environment constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/path/to/wordpress/');
}

// Define plugin constants
define('WTSR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WTSR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WTSR_VERSION', '1.0.0');

echo "Testing plugin class loading...\n";

try {
    // Test loading database handler
    echo "Loading WTSR_Database_Handler...\n";
    require_once WTSR_PLUGIN_PATH . 'includes/class-database-handler.php';
    echo "✓ WTSR_Database_Handler loaded successfully\n";
    
    // Test loading file scanner
    echo "Loading WTSR_File_Scanner...\n";
    require_once WTSR_PLUGIN_PATH . 'includes/class-file-scanner.php';
    echo "✓ WTSR_File_Scanner loaded successfully\n";
    
    // Test loading backup handler
    echo "Loading WTSR_Backup_Handler...\n";
    require_once WTSR_PLUGIN_PATH . 'includes/class-backup-handler.php';
    echo "✓ WTSR_Backup_Handler loaded successfully\n";
    
    // Test loading search handler
    echo "Loading WTSR_Search_Handler...\n";
    require_once WTSR_PLUGIN_PATH . 'includes/class-search-handler.php';
    echo "✓ WTSR_Search_Handler loaded successfully\n";
    
    // Test loading replace handler
    echo "Loading WTSR_Replace_Handler...\n";
    require_once WTSR_PLUGIN_PATH . 'includes/class-replace-handler.php';
    echo "✓ WTSR_Replace_Handler loaded successfully\n";
    
    echo "\nAll classes loaded successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Error loading classes: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
} catch (ParseError $e) {
    echo "✗ Parse error in class files: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
} catch (Error $e) {
    echo "✗ Fatal error loading classes: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
} 