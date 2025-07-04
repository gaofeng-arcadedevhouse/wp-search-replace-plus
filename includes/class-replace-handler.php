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
        error_log('WTSR: Replace handler called');
        
        try {
            $post_data = $_POST;
            
            // Validate required fields
            if (empty($post_data['replace_data'])) {
                error_log('WTSR: No replace data provided');
                wp_send_json_error(array(
                    'message' => __('No replace data provided', 'worldteam-search-replace')
                ));
                return;
            }
            
            if (empty($post_data['replace_text'])) {
                error_log('WTSR: Replace text cannot be empty');
                wp_send_json_error(array(
                    'message' => __('Replace text cannot be empty', 'worldteam-search-replace')
                ));
                return;
            }
            
            // Validate search text is provided
            if (empty($post_data['search_text'])) {
                error_log('WTSR: Search text cannot be empty');
                wp_send_json_error(array(
                    'message' => __('Search text cannot be empty', 'worldteam-search-replace')
                ));
                return;
            }
            
            // Decode the replace data
            $replace_data = json_decode(stripslashes($post_data['replace_data']), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('WTSR: JSON decode error - ' . json_last_error_msg());
                wp_send_json_error(array(
                    'message' => __('Invalid replace data format', 'worldteam-search-replace')
                ));
                return;
            }
            
            error_log('WTSR: Replace data decoded successfully, items count: ' . count($replace_data));
            
            // Extract parameters for performReplace
            $search_text = sanitize_text_field($post_data['search_text']);
            $replace_text = sanitize_textarea_field($post_data['replace_text']);
            $selected_items = $replace_data;
            $options = $this->parseReplaceOptions($post_data);
            
            // Perform the replace operation
            $results = $this->performReplace($search_text, $replace_text, $selected_items, $options);
            
            // Return success response
            wp_send_json_success(array(
                'message' => $this->formatSuccessMessage($results),
                'stats' => $this->generateStats($results),
                'results' => $results
            ));
            
        } catch (Exception $e) {
            error_log('WTSR: Exception in replace handler: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => sprintf(__('Replace operation failed: %s', 'worldteam-search-replace'), $e->getMessage())
            ));
        }
    }
    
    private function parseReplaceOptions($post_data) {
        return array(
            'case_sensitive' => isset($post_data['case_sensitive']) && $post_data['case_sensitive'] === '1',
            'regex_mode' => isset($post_data['regex_mode']) && $post_data['regex_mode'] === '1',
            'whole_words' => isset($post_data['whole_words']) && $post_data['whole_words'] === '1'
        );
    }
    
    private function performReplace($search_text, $replace_text, $selected_items, $options) {
        error_log('WTSR: performReplace called with ' . count($selected_items) . ' items');
        
        $results = array(
            'success' => true,
            'database_updated' => 0,
            'files_updated' => 0,
            'errors' => array()
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
        
        // Replace in database items
        if (!empty($database_items)) {
            try {
                $db_result = $this->getDatabaseHandler()->replace($search_text, $replace_text, $database_items, $options);
                $results['database_updated'] = $db_result['replaced_items'];
                $results['errors'] = array_merge($results['errors'], $db_result['errors']);
                error_log('WTSR: Database replace completed: ' . $db_result['replaced_items'] . ' items');
            } catch (Exception $e) {
                error_log('WTSR: Database replace failed: ' . $e->getMessage());
                $results['errors'][] = sprintf(__('Database replace failed: %s', 'worldteam-search-replace'), $e->getMessage());
            }
        }
        
        // Replace in file items
        if (!empty($file_items)) {
            try {
                $file_result = $this->getFileScanner()->replace($search_text, $replace_text, $file_items, $options);
                $results['files_updated'] = $file_result['replaced_files'];
                $results['errors'] = array_merge($results['errors'], $file_result['errors']);
                error_log('WTSR: File replace completed: ' . $file_result['replaced_files'] . ' files');
            } catch (Exception $e) {
                error_log('WTSR: File replace failed: ' . $e->getMessage());
                $results['errors'][] = sprintf(__('File replace failed: %s', 'worldteam-search-replace'), $e->getMessage());
            }
        }
        
        $results['total_replacements'] = $results['database_updated'] + $results['files_updated'];
        
        return $results;
    }
    
    private function formatSuccessMessage($results) {
        $message = __('Replace operation completed successfully', 'worldteam-search-replace');
        
        if ($results['total_replacements'] > 0) {
            $message .= sprintf(
                __(': %d total replacements made', 'worldteam-search-replace'),
                $results['total_replacements']
            );
            
            if ($results['database_updated'] > 0 && $results['files_updated'] > 0) {
                $message .= sprintf(
                    __(' (%d database items, %d files)', 'worldteam-search-replace'),
                    $results['database_updated'],
                    $results['files_updated']
                );
            } elseif ($results['database_updated'] > 0) {
                $message .= sprintf(
                    __(' (%d database items)', 'worldteam-search-replace'),
                    $results['database_updated']
                );
            } elseif ($results['files_updated'] > 0) {
                $message .= sprintf(
                    __(' (%d files)', 'worldteam-search-replace'),
                    $results['files_updated']
                );
            }
        } else {
            $message = __('Replace operation completed, but no changes were made', 'worldteam-search-replace');
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
    
    private function generateStats($results) {
        return array(
            'total_replacements' => $results['total_replacements'],
            'database_updated' => $results['database_updated'],
            'files_updated' => $results['files_updated'],
            'errors_count' => count($results['errors']),
            'has_errors' => !empty($results['errors'])
        );
    }
} 