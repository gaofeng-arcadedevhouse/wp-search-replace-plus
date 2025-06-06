<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WTSR_Database_Handler {
    
    private $wpdb;
    
    public function __construct() {
        global $wpdb;
        
        if (!isset($wpdb) || !($wpdb instanceof wpdb)) {
            error_log('WTSR: Global $wpdb is not available or not a wpdb instance');
            throw new Exception(__('WordPress database object is not available', 'worldteam-search-replace'));
        }
        
        $this->wpdb = $wpdb;
        error_log('WTSR: Database handler constructed successfully with wpdb');
    }
    
    public function startTransaction() {
        return $this->wpdb->query('START TRANSACTION');
    }
    
    public function commitTransaction() {
        return $this->wpdb->query('COMMIT');
    }
    
    public function rollbackTransaction() {
        return $this->wpdb->query('ROLLBACK');
    }
    
    public function isReady() {
        return isset($this->wpdb) && $this->wpdb instanceof wpdb;
    }
    
    public static function createTables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wtsr_operations';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            operation_type varchar(50) NOT NULL,
            search_text longtext NOT NULL,
            replace_text longtext,
            scope longtext NOT NULL,
            affected_items int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime,
            user_id bigint(20),
            backup_file varchar(255),
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function search($search_text, $search_scope, $options) {
        error_log('WTSR: Database search started with text: ' . $search_text);
        error_log('WTSR: Search scope: ' . implode(',', $search_scope));
        
        // Add basic validation
        if (empty($search_text) || !is_array($search_scope) || empty($search_scope)) {
            error_log('WTSR: Invalid search parameters');
            return array();
        }
        
        $results = array();
        
        try {
            // Simple search like your working demo
            if (in_array('posts', $search_scope) || in_array('pages', $search_scope)) {
                $results = $this->searchPosts($search_text, $search_scope, $options);
            }
            
            // Add ACF search if requested
            if (in_array('acf', $search_scope) && function_exists('get_fields')) {
                $acf_results = $this->searchACF($search_text, $options);
                $results = array_merge($results, $acf_results);
            }
            
        } catch (Exception $e) {
            error_log('WTSR: Database search exception: ' . $e->getMessage());
            return array(); // Return empty array instead of throwing
        }
        
        error_log('WTSR: Database search completed with ' . count($results) . ' total results');
        return $results;
    }
    
    private function searchPosts($search_text, $search_scope, $options) {
        $results = array();
        
        // Use exactly the same approach as your working demo
        $args = array(
            'post_type' => 'any',
            'posts_per_page' => 100, // Reasonable limit
            'post_status' => 'publish',
            'ignore_sticky_posts' => 1
        );
        
        $posts = get_posts($args);
        error_log('WTSR: get_posts found ' . count($posts) . ' posts');
        
        foreach ($posts as $post) {
            $post_contains_string = false;
            $match_details = array();
            
            // Only search in requested post types
            if ((in_array('posts', $search_scope) && $post->post_type === 'post') || 
                (in_array('pages', $search_scope) && $post->post_type === 'page')) {
                
                // Check post content - exactly like your demo
                if ($post->post_content && stripos($post->post_content, $search_text) !== false) {
                    $post_contains_string = true;
                    $match_details[] = array(
                        'field' => 'post_content',
                        'content' => substr($post->post_content, 0, 200),
                        'matches' => 1
                    );
                }
                
                // Check post title
                if ($post->post_title && stripos($post->post_title, $search_text) !== false) {
                    $post_contains_string = true;
                    $match_details[] = array(
                        'field' => 'post_title',
                        'content' => $post->post_title,
                        'matches' => 1
                    );
                }
            }
            
            if ($post_contains_string) {
                $results[] = array(
                    'type' => 'post',
                    'id' => $post->ID,
                    'title' => $post->post_title ? $post->post_title : '(no title)',
                    'post_type' => $post->post_type,
                    'post_status' => $post->post_status,
                    'edit_url' => get_edit_post_link($post->ID),
                    'view_url' => get_permalink($post->ID),
                    'matches' => $match_details,
                    'match_count' => count($match_details)
                );
                
                error_log('WTSR: Found match in post ' . $post->ID . ' (' . $post->post_title . ')');
            }
        }
        
        return $results;
    }
    
    private function searchACF($search_text, $options) {
        $results = array();
        
        if (!function_exists('get_fields')) {
            return $results;
        }
        
        // Get some posts to search ACF fields
        $posts = get_posts(array(
            'post_type' => 'any',
            'posts_per_page' => 50,
            'post_status' => 'publish'
        ));
        
        foreach ($posts as $post) {
            $fields = get_fields($post->ID);
            if ($fields) {
                foreach ($fields as $field_name => $field_value) {
                    $raw_value = is_scalar($field_value) ? $field_value : json_encode($field_value);
                    if (is_string($raw_value) && stripos($raw_value, $search_text) !== false) {
                        $results[] = array(
                            'type' => 'acf',
                            'id' => $post->ID,
                            'title' => $post->post_title . ' (ACF: ' . $field_name . ')',
                            'post_type' => $post->post_type,
                            'field_name' => $field_name,
                            'edit_url' => get_edit_post_link($post->ID),
                            'view_url' => get_permalink($post->ID),
                            'matches' => array(array(
                                'field' => 'acf_' . $field_name,
                                'content' => substr($raw_value, 0, 200),
                                'matches' => 1
                            )),
                            'match_count' => 1
                        );
                        break; // Only one ACF match per post
                    }
                }
            }
        }
        
        return $results;
    }
    
    // Simple replace method
    public function replace($search_text, $replace_text, $items, $options) {
        $replaced_items = 0;
        $errors = array();
        
        foreach ($items as $item) {
            try {
                if ($item['type'] === 'post') {
                    $this->replaceInPost($item, $search_text, $replace_text, $options);
                    $replaced_items++;
                }
            } catch (Exception $e) {
                $errors[] = sprintf(
                    __('Failed to replace item %s: %s', 'worldteam-search-replace'),
                    $item['title'],
                    $e->getMessage()
                );
            }
        }
        
        return array(
            'replaced_items' => $replaced_items,
            'errors' => $errors
        );
    }
    
    private function replaceInPost($item, $search_text, $replace_text, $options) {
        $post = get_post($item['id']);
        if (!$post) {
            return;
        }
        
        $post_data = array('ID' => $item['id']);
        $updated = false;
        
        // Replace in content
        if (stripos($post->post_content, $search_text) !== false) {
            $post_data['post_content'] = str_ireplace($search_text, $replace_text, $post->post_content);
            $updated = true;
        }
        
        // Replace in title
        if (stripos($post->post_title, $search_text) !== false) {
            $post_data['post_title'] = str_ireplace($search_text, $replace_text, $post->post_title);
            $updated = true;
        }
        
        if ($updated) {
            wp_update_post($post_data);
        }
    }
} 