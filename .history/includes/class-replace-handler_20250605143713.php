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
            $this->database_handler = new WTSR_Database_Handler();
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
                'message' => __('权限不足', 'worldteam-search-replace')
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
                'message' => __('请输入搜索文本', 'worldteam-search-replace')
            ));
            return;
        }
        
        if (empty($selected_items) || !is_array($selected_items)) {
            error_log('WTSR: No items selected for replace');
            wp_send_json_error(array(
                'message' => __('请选择要替换的项目', 'worldteam-search-replace')
            ));
            return;
        }
        
        try {
            error_log('WTSR: Starting replace operation');
            
            // Start transaction for database operations
            $db_handler = $this->getDatabaseHandler();
            if (!$db_handler || !$db_handler->isReady()) {
                throw new Exception(__('数据库处理器初始化失败', 'worldteam-search-replace'));
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
                'message' => sprintf(__('替换失败: %s', 'worldteam-search-replace'), $e->getMessage())
            ));
        } catch (Error $e) {
            error_log('WTSR: Replace failed with fatal error: ' . $e->getMessage());
            error_log('WTSR: Error trace: ' . $e->getTraceAsString());
            
            wp_send_json_error(array(
                'message' => sprintf(__('替换过程中发生错误: %s', 'worldteam-search-replace'), $e->getMessage())
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
                    $results['errors'][] = sprintf(__('解析项目 %d 数据失败: %s', 'worldteam-search-replace'), $index, $error_msg);
                    continue;
                }
                
                if (!is_array($item) || !isset($item['type'])) {
                    error_log('WTSR: Invalid item structure at index ' . $index);
                    $results['errors'][] = sprintf(__('项目 %d 数据格式无效', 'worldteam-search-replace'), $index);
                    continue;
                }
                
                if ($item['type'] === 'file') {
                    $file_items[] = $item;
                } else {
                    $database_items[] = $item;
                }
                
            } catch (Exception $e) {
                error_log('WTSR: Exception processing item ' . $index . ': ' . $e->getMessage());
                $results['errors'][] = sprintf(__('处理项目 %d 时出错: %s', 'worldteam-search-replace'), $index, $e->getMessage());
            }
        }
        
        error_log('WTSR: Separated items - Database: ' . count($database_items) . ', Files: ' . count($file_items));
        
        // Create backup if requested
        if ($options['create_backup']) {
            try {
                error_log('WTSR: Creating backup');
                
                // Check if backup handler class exists
                if (!class_exists('WTSR_Backup_Handler')) {
                    error_log('WTSR: Backup handler class not found');
                    $results['errors'][] = __('备份处理器未找到，跳过备份创建', 'worldteam-search-replace');
                } else {
                    $backup_handler = new WTSR_Backup_Handler();
                    if (method_exists($backup_handler, 'createFullBackup')) {
                        $results['backup_file'] = $backup_handler->createFullBackup();
                        error_log('WTSR: Backup created: ' . $results['backup_file']);
                    } else {
                        error_log('WTSR: createFullBackup method not found');
                        $results['errors'][] = __('备份方法未找到，跳过备份创建', 'worldteam-search-replace');
                    }
                }
            } catch (Exception $e) {
                error_log('WTSR: Backup creation failed: ' . $e->getMessage());
                $results['errors'][] = sprintf(__('创建备份失败: %s', 'worldteam-search-replace'), $e->getMessage());
                // Continue with replacement even if backup fails
            } catch (Error $e) {
                error_log('WTSR: Backup creation fatal error: ' . $e->getMessage());
                $results['errors'][] = sprintf(__('备份创建过程中发生错误: %s', 'worldteam-search-replace'), $e->getMessage());
                // Continue with replacement even if backup fails
            }
        }
        
        // Replace in database
        if (!empty($database_items)) {
            try {
                error_log('WTSR: Processing database replacements');
                $db_results = $this->getDatabaseHandler()->replace($search_text, $replace_text, $database_items, $options);
                $results['database_items'] = $db_results['replaced_items'];
                $results['errors'] = array_merge($results['errors'], $db_results['errors']);
                error_log('WTSR: Database replacements completed: ' . $results['database_items'] . ' items');
            } catch (Exception $e) {
                error_log('WTSR: Database replacement failed: ' . $e->getMessage());
                $results['errors'][] = sprintf(__('数据库替换失败: %s', 'worldteam-search-replace'), $e->getMessage());
            }
        }
        
        // Replace in files
        if (!empty($file_items)) {
            try {
                error_log('WTSR: Processing file replacements');
                $file_results = $this->getFileScanner()->replace($search_text, $replace_text, $file_items, $options);
                $results['file_items'] = $file_results['replaced_files'];
                $results['errors'] = array_merge($results['errors'], $file_results['errors']);
                error_log('WTSR: File replacements completed: ' . $results['file_items'] . ' items');
            } catch (Exception $e) {
                error_log('WTSR: File replacement failed: ' . $e->getMessage());
                $results['errors'][] = sprintf(__('文件替换失败: %s', 'worldteam-search-replace'), $e->getMessage());
            }
        }
        
        $results['total_replacements'] = $results['database_items'] + $results['file_items'];
        error_log('WTSR: Total replacements: ' . $results['total_replacements']);
        
        return $results;
    }
    
    private function generateSuccessMessage($results) {
        $message = sprintf(
            __('替换完成！共替换了 %d 个项目', 'worldteam-search-replace'),
            $results['total_replacements']
        );
        
        if ($results['database_items'] > 0 && $results['file_items'] > 0) {
            $message .= sprintf(
                __(' (数据库: %d 项，文件: %d 项)', 'worldteam-search-replace'),
                $results['database_items'],
                $results['file_items']
            );
        } elseif ($results['database_items'] > 0) {
            $message .= sprintf(
                __(' (数据库: %d 项)', 'worldteam-search-replace'),
                $results['database_items']
            );
        } elseif ($results['file_items'] > 0) {
            $message .= sprintf(
                __(' (文件: %d 项)', 'worldteam-search-replace'),
                $results['file_items']
            );
        }
        
        if (!empty($results['errors'])) {
            $message .= sprintf(
                __(' 注意：有 %d 个项目替换失败', 'worldteam-search-replace'),
                count($results['errors'])
            );
        }
        
        if ($results['backup_file']) {
            $message .= __(' 已创建备份文件', 'worldteam-search-replace');
        }
        
        return $message;
    }
    
    private function logOperation($search_text, $replace_text, $selected_items, $results) {
        try {
            global $wpdb;
            
            $table_name = $wpdb->prefix . 'wtsr_operations';
            
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            if (!$table_exists) {
                error_log('WTSR: Operations table does not exist, creating it');
                WTSR_Database_Handler::createTables();
                
                // Check again
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
                if (!$table_exists) {
                    error_log('WTSR: Failed to create operations table, skipping operation log');
                    return;
                }
            }
            
            $scope_data = array();
            foreach ($selected_items as $item_data) {
                try {
                    $data = json_decode(stripslashes($item_data), true);
                    if ($data && is_array($data)) {
                        $scope_data[] = array(
                            'type' => $data['type'] ?? 'unknown',
                            'id' => $data['id'] ?? null,
                            'title' => $data['title'] ?? null
                        );
                    }
                } catch (Exception $e) {
                    error_log('WTSR: Error processing item for log: ' . $e->getMessage());
                }
            }
            
            $insert_result = $wpdb->insert(
                $table_name,
                array(
                    'operation_type' => 'replace',
                    'search_text' => $search_text,
                    'replace_text' => $replace_text,
                    'scope' => json_encode($scope_data),
                    'affected_items' => $results['total_replacements'],
                    'status' => empty($results['errors']) ? 'completed' : 'completed_with_errors',
                    'completed_at' => current_time('mysql'),
                    'user_id' => get_current_user_id(),
                    'backup_file' => $results['backup_file'] ?? null
                ),
                array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s')
            );
            
            if ($insert_result === false) {
                error_log('WTSR: Failed to log operation: ' . $wpdb->last_error);
            } else {
                error_log('WTSR: Operation logged successfully');
            }
            
        } catch (Exception $e) {
            error_log('WTSR: Exception while logging operation: ' . $e->getMessage());
        } catch (Error $e) {
            error_log('WTSR: Fatal error while logging operation: ' . $e->getMessage());
        }
    }
    
    public function getOperationHistory($limit = 50) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wtsr_operations';
        
        $sql = $wpdb->prepare(
            "SELECT * FROM $table_name 
             ORDER BY created_at DESC 
             LIMIT %d",
            $limit
        );
        
        $operations = $wpdb->get_results($sql);
        
        // Add user information
        foreach ($operations as &$operation) {
            if ($operation->user_id) {
                $user = get_user_by('id', $operation->user_id);
                $operation->user_name = $user ? $user->display_name : __('未知用户', 'worldteam-search-replace');
            } else {
                $operation->user_name = __('系统', 'worldteam-search-replace');
            }
            
            // Decode scope data
            $operation->scope_data = json_decode($operation->scope, true);
        }
        
        return $operations;
    }
    
    public function validateReplaceOperation($search_text, $replace_text, $selected_items, $options) {
        $validation_errors = array();
        
        // Check if search text is valid regex when in regex mode
        if ($options['regex_mode']) {
            if (@preg_match('/' . $search_text . '/', '') === false) {
                $validation_errors[] = __('无效的正则表达式', 'worldteam-search-replace');
            }
        }
        
        // Check for potentially dangerous replacements
        if ($this->isDangerousReplacement($search_text, $replace_text, $selected_items)) {
            $validation_errors[] = __('检测到可能危险的替换操作，请仔细检查', 'worldteam-search-replace');
        }
        
        // Check file permissions for file operations
        foreach ($selected_items as $item_data) {
            $item = json_decode(stripslashes($item_data), true);
            
            if ($item['type'] === 'file' && isset($item['file_path'])) {
                if (!is_writable($item['file_path'])) {
                    $validation_errors[] = sprintf(
                        __('文件 %s 不可写', 'worldteam-search-replace'),
                        $item['relative_path']
                    );
                }
            }
        }
        
        return $validation_errors;
    }
    
    private function isDangerousReplacement($search_text, $replace_text, $selected_items) {
        // Check for dangerous patterns
        $dangerous_patterns = array(
            'wp-config.php',
            'database',
            'password',
            'secret',
            'salt',
            'key',
            '<?php',
            'function ',
            'class ',
            'mysql',
            'admin',
            'root'
        );
        
        $search_lower = strtolower($search_text);
        $replace_lower = strtolower($replace_text);
        
        foreach ($dangerous_patterns as $pattern) {
            if (strpos($search_lower, $pattern) !== false || strpos($replace_lower, $pattern) !== false) {
                return true;
            }
        }
        
        // Check if replacing URLs in database
        if ((strpos($search_text, 'http://') !== false || strpos($search_text, 'https://') !== false) ||
            (strpos($replace_text, 'http://') !== false || strpos($replace_text, 'https://') !== false)) {
            return true;
        }
        
        return false;
    }
    
    public function previewReplace($search_text, $replace_text, $item_data, $options) {
        $item = json_decode(stripslashes($item_data), true);
        
        if ($item['type'] === 'file') {
            return $this->previewFileReplace($item, $search_text, $replace_text, $options);
        } else {
            return $this->previewDatabaseReplace($item, $search_text, $replace_text, $options);
        }
    }
    
    private function previewFileReplace($item, $search_text, $replace_text, $options) {
        $content = file_get_contents($item['file_path']);
        $lines = explode("\n", $content);
        $preview_lines = array();
        
        foreach ($item['matches'] as $match) {
            $line_number = $match['line_number'] - 1;
            $original_line = $lines[$line_number];
            
            // Perform replacement on this line
            $new_line = $this->performTextReplace($original_line, $search_text, $replace_text, $options);
            
            $preview_lines[] = array(
                'line_number' => $match['line_number'],
                'original' => $original_line,
                'modified' => $new_line,
                'context_before' => $match['context_before'],
                'context_after' => $match['context_after']
            );
        }
        
        return $preview_lines;
    }
    
    private function previewDatabaseReplace($item, $search_text, $replace_text, $options) {
        $previews = array();
        
        foreach ($item['matches'] as $match) {
            $original_content = $match['content'];
            $modified_content = $this->performTextReplace($original_content, $search_text, $replace_text, $options);
            
            $previews[] = array(
                'field' => $match['field'],
                'original' => $original_content,
                'modified' => $modified_content,
                'match_count' => $match['matches']
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