<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WTSR_Replace_Handler {
    
    private $database_handler;
    private $file_scanner;
    
    public function __construct() {
        // Use lazy loading for dependencies
        $this->database_handler = null;
        $this->file_scanner = null;
    }
    
    private function getDatabaseHandler() {
        if ($this->database_handler === null) {
            try {
                error_log('WTSR: Initializing database handler');
                $this->database_handler = new WTSR_Database_Handler();
                error_log('WTSR: Database handler initialized successfully');
            } catch (Exception $e) {
                error_log('WTSR: Failed to initialize database handler: ' . $e->getMessage());
                throw new Exception(__('Failed to initialize database handler: ', 'worldteam-search-replace') . $e->getMessage());
            } catch (Error $e) {
                error_log('WTSR: Fatal error initializing database handler: ' . $e->getMessage());
                throw new Exception(__('Fatal error occurred while initializing database handler: ', 'worldteam-search-replace') . $e->getMessage());
            }
        }
        return $this->database_handler;
    }
    
    private function getFileScanner() {
        if ($this->file_scanner === null) {
            $this->file_scanner = new WTSR_File_Scanner();
        }
        return $this->file_scanner;
    }
    
    public function handleReplaceRequest() {
        error_log('WTSR: Replace request received');
        
        // Validate nonce and permissions first
        if (!current_user_can('manage_options')) {
            error_log('WTSR: Insufficient permissions for replace');
            wp_send_json_error(array(
                'message' => __('Insufficient permissions', 'worldteam-search-replace')
            ));
            return;
        }
        
        // Validate input
        $search_text = sanitize_textarea_field($_POST['search_text'] ?? '');
        $replace_text = sanitize_textarea_field($_POST['replace_text'] ?? '');
        $selected_items = $_POST['selected_items'] ?? array();
        $options = $this->parseReplaceOptions($_POST);
        
        error_log('WTSR: Replace parameters - Search: ' . $search_text . ', Replace: ' . $replace_text);
        error_log('WTSR: Selected items count: ' . (is_array($selected_items) ? count($selected_items) : 'not array'));
        
        if (empty($search_text)) {
            error_log('WTSR: Empty search text for replace');
            wp_send_json_error(array(
                'message' => __('Please enter search text', 'worldteam-search-replace')
            ));
            return;
        }
        
        if (empty($selected_items) || !is_array($selected_items)) {
            error_log('WTSR: No items selected for replace');
            wp_send_json_error(array(
                'message' => __('Please select items to replace', 'worldteam-search-replace')
            ));
            return;
        }
        
        try {
            error_log('WTSR: Starting replace operation');
            
            // Start transaction for database operations
            $db_handler = $this->getDatabaseHandler();
            if (!$db_handler || !$db_handler->isReady()) {
                throw new Exception(__('Database handler initialization failed', 'worldteam-search-replace'));
            }
            
            $db_handler->startTransaction();
            error_log('WTSR: Database transaction started');
            
            $results = $this->performReplace($search_text, $replace_text, $selected_items, $options);
            error_log('WTSR: Replace operation completed');
            
            // Log the operation
            $this->logOperation($search_text, $replace_text, $selected_items, $results);
            
            // Commit transaction
            $db_handler->commitTransaction();
            error_log('WTSR: Database transaction committed');
            
            wp_send_json_success(array(
                'message' => $this->generateSuccessMessage($results),
                'results' => $results,
                'backup_created' => $results['backup_file'] ?? null
            ));
            
        } catch (Exception $e) {
            error_log('WTSR: Replace failed with exception: ' . $e->getMessage());
            error_log('WTSR: Exception trace: ' . $e->getTraceAsString());
            
            // Rollback transaction
            try {
                $db_handler = $this->getDatabaseHandler();
                if ($db_handler && $db_handler->isReady()) {
                    $db_handler->rollbackTransaction();
                }
            } catch (Exception $rollback_exception) {
                error_log('WTSR: Rollback failed: ' . $rollback_exception->getMessage());
            }
            
            wp_send_json_error(array(
                'message' => sprintf(__('Replace failed: %s', 'worldteam-search-replace'), $e->getMessage())
            ));
        } catch (Error $e) {
            error_log('WTSR: Replace failed with fatal error: ' . $e->getMessage());
            error_log('WTSR: Error trace: ' . $e->getTraceAsString());
            
            wp_send_json_error(array(
                'message' => sprintf(__('Error occurred during replace: %s', 'worldteam-search-replace'), $e->getMessage())
            ));
        }
    }
    
    private function parseReplaceOptions($post_data) {
        return array(
            'case_sensitive' => isset($post_data['case_sensitive']) && $post_data['case_sensitive'] === '1',
            'regex_mode' => isset($post_data['regex_mode']) && $post_data['regex_mode'] === '1',
            'whole_words' => isset($post_data['whole_words']) && $post_data['whole_words'] === '1',
            'create_backup' => isset($post_data['create_backup']) && $post_data['create_backup'] === '1'
        );
    }
    
    private function performReplace($search_text, $replace_text, $selected_items, $options) {
        error_log('WTSR: performReplace called with ' . count($selected_items) . ' items');
        
        $results = array(
            'database_items' => 0,
            'file_items' => 0,
            'total_replacements' => 0,
            'errors' => array(),
            'backup_file' => null
        );
        
        // Separate database and file items
        $database_items = array();
        $file_items = array();
        
        foreach ($selected_items as $index => $item_data) {
            try {
                error_log('WTSR: Processing item ' . $index . ': ' . substr($item_data, 0, 100) . '...');
                
                if (empty($item_data) || !is_string($item_data)) {
                    error_log('WTSR: Invalid item data at index ' . $index);
                    continue;
                }
                
                $item = json_decode(stripslashes($item_data), true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $error_msg = 'JSON decode error: ' . json_last_error_msg();
                    error_log('WTSR: ' . $error_msg . ' for item: ' . $item_data);
                    $results['errors'][] = sprintf(__('Failed to parse item %d data: %s', 'worldteam-search-replace'), $index, $error_msg);
                    continue;
                }
                
                if (!is_array($item) || !isset($item['type'])) {
                    error_log('WTSR: Invalid item structure at index ' . $index);
                    $results['errors'][] = sprintf(__('Item %d has invalid data format', 'worldteam-search-replace'), $index);
                    continue;
                }
                
                if ($item['type'] === 'file') {
                    $file_items[] = $item;
                } else {
                    $database_items[] = $item;
                }
                
            } catch (Exception $e) {
                error_log('WTSR: Exception processing item ' . $index . ': ' . $e->getMessage());
                $results['errors'][] = sprintf(__('Error processing item %d: %s', 'worldteam-search-replace'), $index, $e->getMessage());
            }
        }
        
        error_log('WTSR: Separated items - Database: ' . count($database_items) . ', Files: ' . count($file_items));
        
        // Create backup if requested
        if ($options['create_backup']) {
            try {
                error_log('WTSR: Creating backup');
                $results['backup_file'] = $this->createBackup($database_items, $file_items);
                error_log('WTSR: Backup created: ' . $results['backup_file']);
            } catch (Exception $e) {
                error_log('WTSR: Backup creation failed: ' . $e->getMessage());
                $results['errors'][] = sprintf(__('Backup creation failed: %s', 'worldteam-search-replace'), $e->getMessage());
            }
        }
        
        // Replace in database items
        if (!empty($database_items)) {
            try {
                $db_result = $this->getDatabaseHandler()->replace($search_text, $replace_text, $database_items, $options);
                $results['database_items'] = $db_result['database_items'] ?? $db_result['replaced_items'] ?? 0;
                $results['operation_id'] = $db_result['operation_id'] ?? null;
                $results['errors'] = array_merge($results['errors'], $db_result['errors'] ?? array());
                error_log('WTSR: Database replace completed: ' . $results['database_items'] . ' items, operation_id: ' . $results['operation_id']);
            } catch (Exception $e) {
                error_log('WTSR: Database replace failed: ' . $e->getMessage());
                $results['errors'][] = sprintf(__('Database replace failed: %s', 'worldteam-search-replace'), $e->getMessage());
            }
        }
        
        // Replace in file items
        if (!empty($file_items)) {
            try {
                $file_result = $this->getFileScanner()->replace($search_text, $replace_text, $file_items, $options);
                $results['file_items'] = $file_result['replaced_files'] ?? 0;
                $results['errors'] = array_merge($results['errors'], $file_result['errors'] ?? array());
                error_log('WTSR: File replace completed: ' . $results['file_items'] . ' files');
            } catch (Exception $e) {
                error_log('WTSR: File replace failed: ' . $e->getMessage());
                $results['errors'][] = sprintf(__('File replace failed: %s', 'worldteam-search-replace'), $e->getMessage());
            }
        }
        
        $results['total_replacements'] = $results['database_items'] + $results['file_items'];
        
        return $results;
    }
    
    private function createBackup($database_items, $file_items) {
        $backup_dir = wp_upload_dir()['basedir'] . '/wtsr-backups';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }
        
        $backup_file = $backup_dir . '/backup-' . date('Y-m-d-H-i-s') . '.json';
        
        $backup_data = array(
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'database_items' => $database_items,
            'file_items' => $file_items,
            'site_url' => get_site_url(),
            'version' => '1.0.0'
        );
        
        if (file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT)) === false) {
            throw new Exception(__('Failed to write backup file', 'worldteam-search-replace'));
        }
        
        return $backup_file;
    }
    
    private function generateSuccessMessage($results) {
        $total = $results['total_replacements'];
        $errors = count($results['errors']);
        
        if ($total === 0 && $errors === 0) {
            return __('No items were replaced', 'worldteam-search-replace');
        }
        
        $message = sprintf(
            __('Successfully replaced %d items', 'worldteam-search-replace'),
            $total
        );
        
        if ($results['database_items'] > 0 && $results['file_items'] > 0) {
            $message .= sprintf(
                __(' (%d database items, %d files)', 'worldteam-search-replace'),
                $results['database_items'],
                $results['file_items']
            );
        } elseif ($results['database_items'] > 0) {
            $message .= sprintf(
                __(' (%d database items)', 'worldteam-search-replace'),
                $results['database_items']
            );
        } elseif ($results['file_items'] > 0) {
            $message .= sprintf(
                __(' (%d files)', 'worldteam-search-replace'),
                $results['file_items']
            );
        }
        
        if ($errors > 0) {
            $message .= sprintf(
                __(' Warning: %d errors occurred', 'worldteam-search-replace'),
                $errors
            );
        }
        
        if (isset($results['backup_file'])) {
            $message .= '. ' . __('Backup created', 'worldteam-search-replace');
        }
        
        return $message;
    }
    
    private function logOperation($search_text, $replace_text, $selected_items, $results) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wtsr_operations';
        
        // Check if table exists first
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            error_log('WTSR: Operations table does not exist, skipping logging');
            return;
        }
        
        $scope_data = array();
        foreach ($selected_items as $item_data) {
            try {
                $item = json_decode(stripslashes($item_data), true);
                if (isset($item['type'])) {
                    $scope_data[] = $item['type'];
                }
            } catch (Exception $e) {
                // Skip invalid items
            }
        }
        
        $data = array(
            'operation_type' => 'replace',
            'search_text' => $search_text,
            'replace_text' => $replace_text,
            'scope' => json_encode(array_unique($scope_data)),
            'affected_items' => $results['total_replacements'],
            'status' => count($results['errors']) > 0 ? 'partial' : 'completed',
            'user_id' => get_current_user_id(),
            'backup_file' => $results['backup_file'] ?? null,
            'completed_at' => current_time('mysql')
        );
        
        $result = $wpdb->insert($table_name, $data);
        
        if ($result === false) {
            error_log('WTSR: Failed to log operation: ' . $wpdb->last_error);
        } else {
            error_log('WTSR: Operation logged successfully');
        }
    }
    
    public function getOperationHistory($limit = 50) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wtsr_operations';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return array();
        }
        
        $sql = "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d";
        $results = $wpdb->get_results($wpdb->prepare($sql, $limit));
        
        return $results ?: array();
    }
    
    public function validateReplaceOperation($search_text, $replace_text, $selected_items, $options) {
        $warnings = array();
        
        // Check for dangerous replacements
        if ($this->isDangerousReplacement($search_text, $replace_text, $selected_items)) {
            $warnings[] = __('This replacement operation may affect critical content', 'worldteam-search-replace');
        }
        
        // Check for empty replacement
        if (empty($replace_text)) {
            $warnings[] = __('Replacement text is empty - this will delete the search text', 'worldteam-search-replace');
        }
        
        // Check for regex mode warnings
        if ($options['regex_mode']) {
            if (!$this->isValidRegex($search_text)) {
                $warnings[] = __('Invalid regular expression pattern', 'worldteam-search-replace');
            }
        }
        
        return $warnings;
    }
    
    private function isDangerousReplacement($search_text, $replace_text, $selected_items) {
        // Check for common dangerous patterns
        $dangerous_patterns = array(
            'http', 'https', 'www', 'wp-', 'admin', 'login',
            'class=', 'id=', '<div', '</div>', '<html', '</html>'
        );
        
        foreach ($dangerous_patterns as $pattern) {
            if (stripos($search_text, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function isValidRegex($pattern) {
        set_error_handler(function() {}, E_WARNING);
        $result = preg_match('/' . $pattern . '/', '');
        restore_error_handler();
        
        return $result !== false;
    }
    
    public function previewReplace($search_text, $replace_text, $item_data, $options) {
        $item = json_decode(stripslashes($item_data), true);
        
        if (!$item || !isset($item['type'])) {
            return array('error' => __('Invalid item data', 'worldteam-search-replace'));
        }
        
        if ($item['type'] === 'file') {
            return $this->previewFileReplace($item, $search_text, $replace_text, $options);
        } else {
            return $this->previewDatabaseReplace($item, $search_text, $replace_text, $options);
        }
    }
    
    private function previewFileReplace($item, $search_text, $replace_text, $options) {
        try {
            $content = file_get_contents($item['file_path']);
            $modified_content = $this->performTextReplace($content, $search_text, $replace_text, $options);
            
            return array(
                'original' => $content,
                'modified' => $modified_content,
                'changes' => $content !== $modified_content
            );
        } catch (Exception $e) {
            return array('error' => $e->getMessage());
        }
    }
    
    private function previewDatabaseReplace($item, $search_text, $replace_text, $options) {
        $previews = array();
        
        foreach ($item['matches'] as $match) {
            $original = $match['content'];
            $modified = $this->performTextReplace($original, $search_text, $replace_text, $options);
            
            $previews[] = array(
                'field' => $match['field'],
                'original' => $original,
                'modified' => $modified,
                'changes' => $original !== $modified
            );
        }
        
        return $previews;
    }
    
    private function performTextReplace($content, $search_text, $replace_text, $options) {
        if ($options['regex_mode']) {
            $pattern = '/' . str_replace('/', '\/', $search_text) . '/u';
            return preg_replace($pattern, $replace_text, $content);
        }
        
        if ($options['whole_words']) {
            $pattern = '/\b' . preg_quote($search_text, '/') . '\b/';
            $flags = $options['case_sensitive'] ? '' : 'i';
            return preg_replace($pattern . $flags, $replace_text, $content);
        }
        
        if ($options['case_sensitive']) {
            return str_replace($search_text, $replace_text, $content);
        } else {
            return str_ireplace($search_text, $replace_text, $content);
        }
    }
} 