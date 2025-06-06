<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WTSR_Backup_Handler {
    
    private $backup_dir;
    
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/wtsr-backups';
        
        // Create backup directory if it doesn't exist
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            
            // Add .htaccess for security
            $htaccess_content = "deny from all\n";
            file_put_contents($this->backup_dir . '/.htaccess', $htaccess_content);
        }
    }
    
    public function createBackup() {
        check_ajax_referer('wtsr_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'worldteam-search-replace'));
        }
        
        try {
            $backup_file = $this->createFullBackup();
            
            wp_send_json_success(array(
                'message' => __('备份创建成功', 'worldteam-search-replace'),
                'backup_file' => $backup_file,
                'backup_size' => $this->formatFileSize(filesize($backup_file))
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => sprintf(__('备份创建失败: %s', 'worldteam-search-replace'), $e->getMessage())
            ));
        }
    }
    
    public function createFullBackup() {
        $timestamp = date('Y-m-d_H-i-s');
        $backup_filename = "wtsr_full_backup_{$timestamp}.sql";
        $backup_file = $this->backup_dir . '/' . $backup_filename;
        
        // Create database backup
        $this->createDatabaseBackup($backup_file);
        
        // Record backup in database
        $this->recordBackup($backup_filename, 'full', filesize($backup_file));
        
        return $backup_file;
    }
    
    public function createDatabaseBackup($backup_file) {
        global $wpdb;
        
        $tables = array();
        
        // Get all WordPress tables
        $result = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}%'", ARRAY_N);
        foreach ($result as $row) {
            $tables[] = $row[0];
        }
        
        if (empty($tables)) {
            throw new Exception(__('没有找到要备份的数据表', 'worldteam-search-replace'));
        }
        
        $sql_content = $this->generateSQLDump($tables);
        
        if (file_put_contents($backup_file, $sql_content) === false) {
            throw new Exception(__('无法写入备份文件', 'worldteam-search-replace'));
        }
    }
    
    private function generateSQLDump($tables) {
        global $wpdb;
        
        $sql_content = "-- WorldTeam Search & Replace Backup\n";
        $sql_content .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $sql_content .= "-- WordPress Version: " . get_bloginfo('version') . "\n\n";
        
        $sql_content .= "SET foreign_key_checks = 0;\n";
        $sql_content .= "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
        
        foreach ($tables as $table) {
            $sql_content .= $this->dumpTable($table);
        }
        
        $sql_content .= "SET foreign_key_checks = 1;\n";
        
        return $sql_content;
    }
    
    private function dumpTable($table) {
        global $wpdb;
        
        $sql = "\n-- Table: {$table}\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        
        // Get table structure
        $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
        if ($create_table) {
            $sql .= $create_table[1] . ";\n\n";
        }
        
        // Get table data
        $rows = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
        
        if (!empty($rows)) {
            $sql .= "INSERT INTO `{$table}` VALUES\n";
            $insert_statements = array();
            
            foreach ($rows as $row) {
                $values = array();
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $wpdb->_real_escape($value) . "'";
                    }
                }
                $insert_statements[] = '(' . implode(', ', $values) . ')';
            }
            
            $sql .= implode(",\n", $insert_statements) . ";\n\n";
        }
        
        return $sql;
    }
    
    public function createSelectiveBackup($items) {
        $timestamp = date('Y-m-d_H-i-s');
        $backup_filename = "wtsr_selective_backup_{$timestamp}.sql";
        $backup_file = $this->backup_dir . '/' . $backup_filename;
        
        $sql_content = "-- WorldTeam Search & Replace Selective Backup\n";
        $sql_content .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        
        $sql_content .= "SET foreign_key_checks = 0;\n";
        $sql_content .= "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
        
        // Group items by type and table
        $posts_to_backup = array();
        $meta_to_backup = array();
        $comments_to_backup = array();
        
        foreach ($items as $item) {
            switch ($item['type']) {
                case 'post':
                    $posts_to_backup[] = $item['id'];
                    break;
                case 'acf':
                case 'meta':
                    $meta_to_backup[] = array(
                        'post_id' => $item['id'],
                        'meta_key' => $item['meta_key'] ?? $item['field_key']
                    );
                    break;
                case 'comment':
                    $comments_to_backup[] = $item['id'];
                    break;
            }
        }
        
        // Backup posts
        if (!empty($posts_to_backup)) {
            $sql_content .= $this->backupPosts($posts_to_backup);
        }
        
        // Backup meta
        if (!empty($meta_to_backup)) {
            $sql_content .= $this->backupPostMeta($meta_to_backup);
        }
        
        // Backup comments
        if (!empty($comments_to_backup)) {
            $sql_content .= $this->backupComments($comments_to_backup);
        }
        
        $sql_content .= "SET foreign_key_checks = 1;\n";
        
        if (file_put_contents($backup_file, $sql_content) === false) {
            throw new Exception(__('无法写入备份文件', 'worldteam-search-replace'));
        }
        
        // Record backup
        $this->recordBackup($backup_filename, 'selective', filesize($backup_file));
        
        return $backup_file;
    }
    
    private function backupPosts($post_ids) {
        global $wpdb;
        
        $ids_str = implode(',', array_map('intval', $post_ids));
        $sql = "\n-- Posts Backup\n";
        
        $posts = $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE ID IN ({$ids_str})", ARRAY_A);
        
        if (!empty($posts)) {
            $sql .= "-- Backup for posts table\n";
            foreach ($posts as $post) {
                $values = array();
                foreach ($post as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $wpdb->_real_escape($value) . "'";
                    }
                }
                $sql .= "-- BACKUP POST ID: {$post['ID']}\n";
                $sql .= "INSERT INTO `{$wpdb->posts}` VALUES (" . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }
        
        return $sql;
    }
    
    private function backupPostMeta($meta_items) {
        global $wpdb;
        
        $sql = "\n-- Post Meta Backup\n";
        
        foreach ($meta_items as $item) {
            $meta_records = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
                $item['post_id'],
                $item['meta_key']
            ), ARRAY_A);
            
            foreach ($meta_records as $meta) {
                $values = array();
                foreach ($meta as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $wpdb->_real_escape($value) . "'";
                    }
                }
                $sql .= "-- BACKUP META: {$meta['meta_key']} for Post {$meta['post_id']}\n";
                $sql .= "INSERT INTO `{$wpdb->postmeta}` VALUES (" . implode(', ', $values) . ");\n";
            }
        }
        
        return $sql;
    }
    
    private function backupComments($comment_ids) {
        global $wpdb;
        
        $ids_str = implode(',', array_map('intval', $comment_ids));
        $sql = "\n-- Comments Backup\n";
        
        $comments = $wpdb->get_results("SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ({$ids_str})", ARRAY_A);
        
        if (!empty($comments)) {
            foreach ($comments as $comment) {
                $values = array();
                foreach ($comment as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $wpdb->_real_escape($value) . "'";
                    }
                }
                $sql .= "-- BACKUP COMMENT ID: {$comment['comment_ID']}\n";
                $sql .= "INSERT INTO `{$wpdb->comments}` VALUES (" . implode(', ', $values) . ");\n";
            }
        }
        
        return $sql;
    }
    
    private function recordBackup($filename, $type, $size) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wtsr_operations';
        
        $wpdb->insert(
            $table_name,
            array(
                'operation_type' => 'backup',
                'search_text' => '',
                'replace_text' => '',
                'scope' => json_encode(array('type' => $type)),
                'affected_items' => 0,
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'user_id' => get_current_user_id(),
                'backup_file' => $filename
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s')
        );
    }
    
    public function getBackupList() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wtsr_operations';
        
        $backups = $wpdb->get_results(
            "SELECT * FROM {$table_name} 
             WHERE operation_type = 'backup' 
             ORDER BY created_at DESC 
             LIMIT 20"
        );
        
        foreach ($backups as &$backup) {
            $backup_file = $this->backup_dir . '/' . $backup->backup_file;
            $backup->file_exists = file_exists($backup_file);
            $backup->file_size = $backup->file_exists ? $this->formatFileSize(filesize($backup_file)) : 'N/A';
            
            if ($backup->user_id) {
                $user = get_user_by('id', $backup->user_id);
                $backup->user_name = $user ? $user->display_name : __('未知用户', 'worldteam-search-replace');
            } else {
                $backup->user_name = __('系统', 'worldteam-search-replace');
            }
        }
        
        return $backups;
    }
    
    public function restoreBackup($backup_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wtsr_operations';
        
        $backup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d AND operation_type = 'backup'",
            $backup_id
        ));
        
        if (!$backup) {
            throw new Exception(__('备份记录不存在', 'worldteam-search-replace'));
        }
        
        $backup_file = $this->backup_dir . '/' . $backup->backup_file;
        
        if (!file_exists($backup_file)) {
            throw new Exception(__('备份文件不存在', 'worldteam-search-replace'));
        }
        
        // Read and execute SQL
        $sql_content = file_get_contents($backup_file);
        
        if ($sql_content === false) {
            throw new Exception(__('无法读取备份文件', 'worldteam-search-replace'));
        }
        
        // Split SQL into individual statements
        $statements = $this->splitSQL($sql_content);
        
        // Execute statements
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && !$this->isComment($statement)) {
                $result = $wpdb->query($statement);
                if ($result === false) {
                    throw new Exception(sprintf(__('SQL执行失败: %s', 'worldteam-search-replace'), $wpdb->last_error));
                }
            }
        }
        
        return true;
    }
    
    private function splitSQL($sql_content) {
        // Simple SQL statement splitter
        $statements = array();
        $current_statement = '';
        $in_string = false;
        $string_char = '';
        
        $lines = explode("\n", $sql_content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || $this->isComment($line)) {
                continue;
            }
            
            $current_statement .= $line . "\n";
            
            if (substr($line, -1) === ';') {
                $statements[] = $current_statement;
                $current_statement = '';
            }
        }
        
        if (!empty($current_statement)) {
            $statements[] = $current_statement;
        }
        
        return $statements;
    }
    
    private function isComment($line) {
        $line = trim($line);
        return empty($line) || substr($line, 0, 2) === '--' || substr($line, 0, 2) === '/*';
    }
    
    public function deleteBackup($backup_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wtsr_operations';
        
        $backup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d AND operation_type = 'backup'",
            $backup_id
        ));
        
        if (!$backup) {
            throw new Exception(__('备份记录不存在', 'worldteam-search-replace'));
        }
        
        $backup_file = $this->backup_dir . '/' . $backup->backup_file;
        
        // Delete file
        if (file_exists($backup_file)) {
            if (!unlink($backup_file)) {
                throw new Exception(__('无法删除备份文件', 'worldteam-search-replace'));
            }
        }
        
        // Delete record
        $wpdb->delete($table_name, array('id' => $backup_id), array('%d'));
        
        return true;
    }
    
    public function cleanupOldBackups($days = 30) {
        $cutoff_time = time() - ($days * 24 * 60 * 60);
        $cutoff_date = date('Y-m-d H:i:s', $cutoff_time);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wtsr_operations';
        
        $old_backups = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE operation_type = 'backup' 
             AND created_at < %s",
            $cutoff_date
        ));
        
        $deleted_count = 0;
        
        foreach ($old_backups as $backup) {
            try {
                $this->deleteBackup($backup->id);
                $deleted_count++;
            } catch (Exception $e) {
                // Continue with next backup
                continue;
            }
        }
        
        return $deleted_count;
    }
    
    private function formatFileSize($bytes) {
        if ($bytes === 0) {
            return '0 B';
        }
        
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
} 