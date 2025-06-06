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
        
        // Determine which post types to search
        $post_types_to_search = array();
        
        // Handle standard post types
        if (in_array('posts', $search_scope)) {
            $post_types_to_search[] = 'post';
        }
        if (in_array('pages', $search_scope)) {
            $post_types_to_search[] = 'page';
        }
        
        // Handle custom post types (like widgets)
        foreach ($search_scope as $scope_item) {
            if (strpos($scope_item, 'cpt_') === 0) {
                // Custom post type with prefix 'cpt_'
                $post_type = substr($scope_item, 4); // Remove 'cpt_' prefix
                $post_types_to_search[] = $post_type;
            }
        }
        
        // If no specific post types selected, don't search posts
        if (empty($post_types_to_search)) {
            return $results;
        }
        
        error_log('WTSR: Searching post types: ' . implode(', ', $post_types_to_search));
        
        // Debug: Check if widgets post type exists and has posts
        if (in_array('widgets', $post_types_to_search)) {
            $widgets_check = get_posts(array(
                'post_type' => 'widgets',
                'posts_per_page' => 5,
                'post_status' => 'any' // Check all statuses
            ));
            error_log('WTSR: Found ' . count($widgets_check) . ' widgets posts (any status)');
            foreach ($widgets_check as $widget) {
                error_log('WTSR: Widget found - ID: ' . $widget->ID . ', Title: ' . $widget->post_title . ', Status: ' . $widget->post_status);
            }
        }
        
        // Use exactly the same approach as your working demo
        $args = array(
            'post_type' => $post_types_to_search,
            'posts_per_page' => 100, // Reasonable limit
            'post_status' => array('publish', 'private', 'draft'), // Expand status search for widgets
            'ignore_sticky_posts' => 1
        );
        
        $posts = get_posts($args);
        error_log('WTSR: get_posts found ' . count($posts) . ' posts total');
        
        // Debug: Log the post types found
        $found_post_types = array();
        foreach ($posts as $post) {
            if (!in_array($post->post_type, $found_post_types)) {
                $found_post_types[] = $post->post_type;
            }
        }
        error_log('WTSR: Found post types: ' . implode(', ', $found_post_types));
        
        foreach ($posts as $post) {
            $post_contains_string = false;
            $match_details = array();
            
            // Debug: Log widget posts specifically
            if ($post->post_type === 'widget') {
                error_log('WTSR: Checking widget post ID ' . $post->ID . ' - Title: "' . $post->post_title . '"');
                error_log('WTSR: Widget content length: ' . strlen($post->post_content));
                error_log('WTSR: Widget content preview: ' . substr($post->post_content, 0, 200));
                error_log('WTSR: Search text: "' . $search_text . '"');
                error_log('WTSR: Content contains search text: ' . (stripos($post->post_content, $search_text) !== false ? 'YES' : 'NO'));
                error_log('WTSR: Title contains search text: ' . (stripos($post->post_title, $search_text) !== false ? 'YES' : 'NO'));
            }
            
            // Check if this post type should be searched
            if (in_array($post->post_type, $post_types_to_search)) {
                
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
                
                error_log('WTSR: Found match in ' . $post->post_type . ' ' . $post->ID . ' (' . $post->post_title . ')');
            }
        }
        
        return $results;
    }
    
    private function searchACF($search_text, $options) {
        $results = array();
        
        if (!function_exists('get_fields')) {
            return $results;
        }
        
        // Get posts from ALL post types to search ACF fields (including widgets)
        $posts = get_posts(array(
            'post_type' => 'any', // This includes widgets and all custom post types
            'posts_per_page' => 200, // Increased limit to catch more widgets
            'post_status' => array('publish', 'private', 'draft', 'any') // Include all statuses
        ));
        
        error_log('WTSR: Searching ACF fields in ' . count($posts) . ' posts (all post types)');
        
        // Debug: Check what post types we found
        $acf_post_types = array();
        $widgets_count = 0;
        foreach ($posts as $post) {
            if (!in_array($post->post_type, $acf_post_types)) {
                $acf_post_types[] = $post->post_type;
            }
            if ($post->post_type === 'widget') {
                $widgets_count++;
            }
        }
        error_log('WTSR: ACF search found post types: ' . implode(', ', $acf_post_types));
        error_log('WTSR: ACF search found ' . $widgets_count . ' widgets');
        
        // If no widgets found in the general query, try a specific widget query
        if ($widgets_count === 0) {
            error_log('WTSR: No widgets found in general ACF query, trying specific widget query');
            $widget_posts = get_posts(array(
                'post_type' => 'widget',
                'posts_per_page' => 50,
                'post_status' => array('publish', 'private', 'draft', 'any')
            ));
            error_log('WTSR: Specific widget query found ' . count($widget_posts) . ' widgets');
            
            // Add widget posts to the main posts array
            $posts = array_merge($posts, $widget_posts);
            error_log('WTSR: Total posts after adding widgets: ' . count($posts));
        }
        
        foreach ($posts as $post) {
            $fields = get_fields($post->ID);
            
            // Debug: Log widget posts in ACF search
            if ($post->post_type === 'widget') {
                error_log('WTSR: ACF checking widget post ID ' . $post->ID . ' - Title: "' . $post->post_title . '"');
                error_log('WTSR: Widget has ACF fields: ' . ($fields ? 'YES (' . count($fields) . ' fields)' : 'NO'));
                if ($fields) {
                    error_log('WTSR: Widget ACF field names: ' . implode(', ', array_keys($fields)));
                }
            }
            
            if ($fields) {
                foreach ($fields as $field_name => $field_value) {
                    $raw_value = is_scalar($field_value) ? $field_value : json_encode($field_value);
                    
                    // Debug: Log widget ACF field content
                    if ($post->post_type === 'widget') {
                        error_log('WTSR: Widget ACF field "' . $field_name . '" value: ' . substr($raw_value, 0, 100));
                        error_log('WTSR: Field contains search text: ' . (stripos($raw_value, $search_text) !== false ? 'YES' : 'NO'));
                    }
                    
                    if (is_string($raw_value) && stripos($raw_value, $search_text) !== false) {
                        // Get the actual field object to get the real field key
                        $field_object = get_field_object($field_name, $post->ID);
                        $actual_field_key = $field_object ? $field_object['key'] : $field_name;
                        
                        // Create more descriptive title for widgets
                        $display_title = $post->post_title;
                        if ($post->post_type === 'widgets' || $post->post_type === 'widget') {
                            $display_title = '[Widget] ' . $post->post_title;
                        }
                        $display_title .= ' (ACF: ' . $field_name . ')';
                        
                        $results[] = array(
                            'type' => 'acf',
                            'id' => $post->ID,
                            'title' => $display_title,
                            'post_type' => $post->post_type,
                            'field_name' => $field_name,
                            'field_key' => $actual_field_key, // Use the real field key
                            'field_value' => $field_value, // Store original value for replacement
                            'edit_url' => get_edit_post_link($post->ID),
                            'view_url' => get_permalink($post->ID),
                            'matches' => array(array(
                                'field' => 'acf_' . $field_name,
                                'content' => substr($raw_value, 0, 200),
                                'matches' => 1
                            )),
                            'match_count' => 1
                        );
                        
                        error_log('WTSR: Found ACF match in ' . $post->post_type . ' ' . $post->ID . ' (' . $post->post_title . ') field: ' . $field_name);
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
                } elseif ($item['type'] === 'acf') {
                    $this->replaceInACF($item, $search_text, $replace_text, $options);
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
    
    private function replaceInACF($item, $search_text, $replace_text, $options) {
        if (!function_exists('get_field') || !function_exists('update_field')) {
            error_log('WTSR: ACF functions not available for replacement');
            return;
        }
        
        $field_key = isset($item['field_key']) ? $item['field_key'] : $item['field_name'];
        $post_id = $item['id'];
        
        error_log('WTSR: Attempting to replace in ACF field - Post ID: ' . $post_id . ', Field Key: ' . $field_key);
        
        // Get current value directly from database to ensure fresh data
        $current_value = get_field($field_key, $post_id, false); // false = don't format value
        
        if ($current_value === null || $current_value === false) {
            error_log('WTSR: Could not retrieve ACF field value, trying with field name');
            $current_value = get_field($item['field_name'], $post_id, false);
        }
        
        if ($current_value !== null && $current_value !== false) {
            $updated = false;
            
            // Handle different types of ACF field values
            if (is_string($current_value)) {
                // Simple string field
                if (stripos($current_value, $search_text) !== false) {
                    $new_value = str_ireplace($search_text, $replace_text, $current_value);
                    update_field($field_key, $new_value, $post_id);
                    $updated = true;
                    error_log('WTSR: Updated string ACF field');
                }
            } elseif (is_array($current_value)) {
                // Complex field (repeater, flexible content, etc.)
                $new_value = $this->replaceInComplexACFField($current_value, $search_text, $replace_text);
                if ($new_value !== $current_value) {
                    update_field($field_key, $new_value, $post_id);
                    $updated = true;
                    error_log('WTSR: Updated complex ACF field');
                }
            } else {
                // Try to convert to string and search
                $string_value = is_scalar($current_value) ? (string)$current_value : json_encode($current_value);
                if (stripos($string_value, $search_text) !== false) {
                    $new_string_value = str_ireplace($search_text, $replace_text, $string_value);
                    // For non-string values, we need to be careful about type conversion
                    if (is_numeric($current_value)) {
                        $new_value = is_float($current_value) ? (float)$new_string_value : (int)$new_string_value;
                    } else {
                        $new_value = $new_string_value;
                    }
                    update_field($field_key, $new_value, $post_id);
                    $updated = true;
                    error_log('WTSR: Updated scalar ACF field');
                }
            }
            
            if ($updated) {
                error_log('WTSR: ACF field successfully updated - Post ID: ' . $post_id . ', Field: ' . $field_key);
                
                // Clear any ACF caches to ensure the change is visible immediately
                if (function_exists('acf_get_field')) {
                    wp_cache_delete('acf_field_' . $field_key, 'acf');
                    wp_cache_delete('acf_field_' . $item['field_name'], 'acf');
                }
            } else {
                error_log('WTSR: No replacement needed for ACF field - Post ID: ' . $post_id . ', Field: ' . $field_key);
            }
        } else {
            error_log('WTSR: ACF field value is null/false, cannot replace - Post ID: ' . $post_id . ', Field: ' . $field_key);
        }
    }
    
    private function replaceInComplexACFField($field_value, $search_text, $replace_text) {
        if (is_array($field_value)) {
            $updated_value = array();
            foreach ($field_value as $key => $value) {
                if (is_string($value)) {
                    $updated_value[$key] = str_ireplace($search_text, $replace_text, $value);
                } elseif (is_array($value)) {
                    $updated_value[$key] = $this->replaceInComplexACFField($value, $search_text, $replace_text);
                } else {
                    $string_value = is_scalar($value) ? (string)$value : json_encode($value);
                    if (stripos($string_value, $search_text) !== false) {
                        $new_string_value = str_ireplace($search_text, $replace_text, $string_value);
                        if (is_numeric($value)) {
                            $updated_value[$key] = is_float($value) ? (float)$new_string_value : (int)$new_string_value;
                        } else {
                            $updated_value[$key] = $new_string_value;
                        }
                    } else {
                        $updated_value[$key] = $value;
                    }
                }
            }
            return $updated_value;
        }
        return $field_value;
    }
} 