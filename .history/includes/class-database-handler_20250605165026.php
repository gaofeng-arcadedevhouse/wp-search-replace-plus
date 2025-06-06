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
        $page = isset($options['page']) ? max(1, intval($options['page'])) : 1;
        $per_page = isset($options['per_page']) ? max(10, min(100, intval($options['per_page']))) : 50;
        
        try {
            // Use simple search first to test
            if (in_array('posts', $search_scope) || in_array('pages', $search_scope)) {
                $results = $this->simplePostSearch($search_text, $search_scope, $options);
            }
            
        } catch (Exception $e) {
            error_log('WTSR: Database search exception: ' . $e->getMessage());
            throw $e;
        }
        
        error_log('WTSR: Database search completed with ' . count($results) . ' total results');
        return $results;
    }
    
    private function simplePostSearch($search_text, $search_scope, $options) {
        $results = array();
        
        // Very simple approach like your demo
        $args = array(
            'post_type' => 'any',
            'posts_per_page' => 50, // Limit for testing
            'post_status' => 'publish'
        );
        
        $posts = get_posts($args);
        error_log('WTSR: get_posts found ' . count($posts) . ' posts');
        
        foreach ($posts as $post) {
            $post_content = $post->post_content;
            $post_title = $post->post_title;
            $matches = array();
            $has_match = false;
            
            // Simple case-insensitive search like your demo
            if ((in_array('posts', $search_scope) && $post->post_type === 'post') || 
                (in_array('pages', $search_scope) && $post->post_type === 'page')) {
                
                if (stripos($post_content, $search_text) !== false) {
                    $matches[] = array(
                        'field' => 'post_content',
                        'content' => substr($post_content, 0, 200),
                        'matches' => 1
                    );
                    $has_match = true;
                }
                
                if (stripos($post_title, $search_text) !== false) {
                    $matches[] = array(
                        'field' => 'post_title',
                        'content' => $post_title,
                        'matches' => 1
                    );
                    $has_match = true;
                }
            }
            
            if ($has_match) {
                $results[] = array(
                    'type' => 'post',
                    'id' => $post->ID,
                    'title' => $post_title ? $post_title : '(no title)',
                    'post_type' => $post->post_type,
                    'post_status' => $post->post_status,
                    'edit_url' => get_edit_post_link($post->ID),
                    'view_url' => get_permalink($post->ID),
                    'matches' => $matches,
                    'match_count' => count($matches)
                );
                
                error_log('WTSR: Found match in post ' . $post->ID . ' (' . $post_title . ')');
            }
        }
        
        return $results;
    }
    
    // Keep the old complex search as backup
    public function searchComplex($search_text, $search_scope, $options) {
        error_log('WTSR: Database search started with text: ' . $search_text);
        error_log('WTSR: Search scope: ' . implode(',', $search_scope));
        
        $results = array();
        $page = isset($options['page']) ? max(1, intval($options['page'])) : 1;
        $per_page = isset($options['per_page']) ? max(10, min(100, intval($options['per_page']))) : 50;
        
        try {
            // Use WP_Query like the working demo - much more reliable
            $args = array(
                'post_type' => 'any',
                'posts_per_page' => -1, // Get all posts, we'll handle pagination in PHP
                'post_status' => array('publish', 'draft', 'private'),
                'ignore_sticky_posts' => 1
            );
            
            $query = new WP_Query($args);
            error_log('WTSR: WP_Query found ' . $query->found_posts . ' total posts');
            
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();
                    $post_title = get_the_title() ? get_the_title() : '(no title)';
                    $post_type = get_post_type($post_id);
                    $post_status = get_post_status($post_id);
                    $post_content = get_post_field('post_content', $post_id);
                    $post_excerpt = get_post_field('post_excerpt', $post_id);
                    
                    $matches = array();
                    $has_match = false;
                    
                    // Search in posts/pages content
                    if ((in_array('posts', $search_scope) && $post_type === 'post') || 
                        (in_array('pages', $search_scope) && $post_type === 'page')) {
                        
                        // Check title
                        if ($this->textContainsMatch($post_title, $search_text, $options)) {
                            $matches[] = array(
                                'field' => 'post_title',
                                'content' => $post_title,
                                'matches' => $this->findMatchesInText($post_title, $search_text, $options)
                            );
                            $has_match = true;
                        }
                        
                        // Check content
                        if ($this->textContainsMatch($post_content, $search_text, $options)) {
                            $matches[] = array(
                                'field' => 'post_content',
                                'content' => $this->getContentPreview($post_content, $search_text, $options),
                                'matches' => $this->findMatchesInText($post_content, $search_text, $options)
                            );
                            $has_match = true;
                        }
                        
                        // Check excerpt
                        if ($this->textContainsMatch($post_excerpt, $search_text, $options)) {
                            $matches[] = array(
                                'field' => 'post_excerpt',
                                'content' => $post_excerpt,
                                'matches' => $this->findMatchesInText($post_excerpt, $search_text, $options)
                            );
                            $has_match = true;
                        }
                    }
                    
                    // Search in ACF fields
                    if (in_array('acf', $search_scope) && function_exists('get_fields')) {
                        $fields = get_fields($post_id);
                        if ($fields && is_array($fields)) {
                            foreach ($fields as $field_name => $field_value) {
                                $field_value_string = is_scalar($field_value) ? $field_value : json_encode($field_value);
                                if (is_string($field_value_string) && $this->textContainsMatch($field_value_string, $search_text, $options)) {
                                    $matches[] = array(
                                        'field' => 'acf_' . $field_name,
                                        'content' => $this->getContentPreview($field_value_string, $search_text, $options),
                                        'matches' => $this->findMatchesInText($field_value_string, $search_text, $options)
                                    );
                                    $has_match = true;
                                }
                            }
                        }
                    }
                    
                    // Search in custom fields (meta)
                    if (in_array('meta', $search_scope)) {
                        $meta_values = get_post_meta($post_id);
                        if ($meta_values && is_array($meta_values)) {
                            foreach ($meta_values as $meta_key => $meta_value_array) {
                                // Skip ACF internal fields
                                if (strpos($meta_key, '_') === 0) continue;
                                
                                foreach ($meta_value_array as $meta_value) {
                                    $meta_value_string = is_scalar($meta_value) ? $meta_value : json_encode($meta_value);
                                    if (is_string($meta_value_string) && $this->textContainsMatch($meta_value_string, $search_text, $options)) {
                                        $matches[] = array(
                                            'field' => 'meta_' . $meta_key,
                                            'content' => $this->getContentPreview($meta_value_string, $search_text, $options),
                                            'matches' => $this->findMatchesInText($meta_value_string, $search_text, $options)
                                        );
                                        $has_match = true;
                                        break; // Only need one match per meta key
                                    }
                                }
                            }
                        }
                    }
                    
                    if ($has_match) {
                        // Calculate match count safely
                        $total_match_count = 0;
                        foreach ($matches as $match) {
                            if (isset($match['matches']) && is_numeric($match['matches'])) {
                                $total_match_count += intval($match['matches']);
                            } else {
                                $total_match_count += 1; // Default to 1 if not numeric
                            }
                        }
                        
                        $results[] = array(
                            'type' => 'post',
                            'id' => $post_id,
                            'title' => $post_title,
                            'post_type' => $post_type,
                            'post_status' => $post_status,
                            'edit_url' => get_edit_post_link($post_id),
                            'view_url' => get_permalink($post_id),
                            'matches' => $matches,
                            'match_count' => $total_match_count
                        );
                        
                        error_log('WTSR: Found match in post ' . $post_id . ' (' . $post_title . ')');
                    }
                }
                
                wp_reset_postdata();
            }
            
            // Search in comments if requested
            if (in_array('comments', $search_scope)) {
                $comment_results = $this->searchCommentsSimple($search_text, $options);
                $results = array_merge($results, $comment_results);
            }
            
        } catch (Exception $e) {
            error_log('WTSR: Database search exception: ' . $e->getMessage());
            throw $e;
        }
        
        error_log('WTSR: Database search completed with ' . count($results) . ' total results');
        return $results;
    }
    
    private function searchCommentsSimple($search_text, $options) {
        $results = array();
        
        $args = array(
            'status' => 'approve',
            'number' => 1000 // Limit for performance
        );
        
        $comments = get_comments($args);
        
        foreach ($comments as $comment) {
            $matches = array();
            $has_match = false;
            
            // Check comment content
            if ($this->textContainsMatch($comment->comment_content, $search_text, $options)) {
                $matches[] = array(
                    'field' => 'comment_content',
                    'content' => $this->getContentPreview($comment->comment_content, $search_text, $options),
                    'matches' => $this->findMatchesInText($comment->comment_content, $search_text, $options)
                );
                $has_match = true;
            }
            
            // Check comment author
            if ($this->textContainsMatch($comment->comment_author, $search_text, $options)) {
                $matches[] = array(
                    'field' => 'comment_author',
                    'content' => $comment->comment_author,
                    'matches' => $this->findMatchesInText($comment->comment_author, $search_text, $options)
                );
                $has_match = true;
            }
            
            if ($has_match) {
                // Calculate match count safely
                $total_match_count = 0;
                foreach ($matches as $match) {
                    if (isset($match['matches']) && is_numeric($match['matches'])) {
                        $total_match_count += intval($match['matches']);
                    } else {
                        $total_match_count += 1;
                    }
                }
                
                $results[] = array(
                    'type' => 'comment',
                    'id' => $comment->comment_ID,
                    'title' => sprintf(__('Comment by %s', 'worldteam-search-replace'), $comment->comment_author),
                    'post_id' => $comment->comment_post_ID,
                    'author' => $comment->comment_author,
                    'date' => $comment->comment_date,
                    'edit_url' => admin_url('comment.php?action=editcomment&c=' . $comment->comment_ID),
                    'view_url' => get_comment_link($comment->comment_ID),
                    'matches' => $matches,
                    'match_count' => $total_match_count
                );
            }
        }
        
        return $results;
    }
    
    public function replace($search_text, $replace_text, $items, $options) {
        $replaced_items = 0;
        $errors = array();
        
        foreach ($items as $item) {
            try {
                if ($item['type'] === 'post') {
                    $this->replaceInPost($item, $search_text, $replace_text, $options);
                } elseif ($item['type'] === 'acf') {
                    $this->replaceInACF($item, $search_text, $replace_text, $options);
                } elseif ($item['type'] === 'meta') {
                    $this->replaceInPostMeta($item, $search_text, $replace_text, $options);
                } elseif ($item['type'] === 'comment') {
                    $this->replaceInComment($item, $search_text, $replace_text, $options);
                }
                
                $replaced_items++;
                
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
        $post_data = array('ID' => $item['id']);
        
        foreach ($item['matches'] as $match) {
            $field = $match['field'];
            $current_content = '';
            
            switch ($field) {
                case 'post_title':
                    $current_content = get_the_title($item['id']);
                    break;
                case 'post_content':
                    $post = get_post($item['id']);
                    $current_content = $post->post_content;
                    break;
                case 'post_excerpt':
                    $post = get_post($item['id']);
                    $current_content = $post->post_excerpt;
                    break;
            }
            
            $new_content = $this->performTextReplace($current_content, $search_text, $replace_text, $options);
            $post_data[$field] = $new_content;
        }
        
        wp_update_post($post_data);
    }
    
    private function replaceInACF($item, $search_text, $replace_text, $options) {
        $current_value = get_field($item['field_key'], $item['id']);
        $new_value = $this->performTextReplace($current_value, $search_text, $replace_text, $options);
        
        update_field($item['field_key'], $new_value, $item['id']);
    }
    
    private function replaceInPostMeta($item, $search_text, $replace_text, $options) {
        $current_value = get_post_meta($item['id'], $item['meta_key'], true);
        $new_value = $this->performTextReplace($current_value, $search_text, $replace_text, $options);
        
        update_post_meta($item['id'], $item['meta_key'], $new_value);
    }
    
    private function replaceInComment($item, $search_text, $replace_text, $options) {
        $comment_data = array('comment_ID' => $item['id']);
        
        foreach ($item['matches'] as $match) {
            $field = $match['field'];
            
            if ($field === 'comment_content') {
                $comment = get_comment($item['id']);
                $new_content = $this->performTextReplace($comment->comment_content, $search_text, $replace_text, $options);
                $comment_data['comment_content'] = $new_content;
            } elseif ($field === 'comment_author') {
                $comment = get_comment($item['id']);
                $new_author = $this->performTextReplace($comment->comment_author, $search_text, $replace_text, $options);
                $comment_data['comment_author'] = $new_author;
            }
        }
        
        wp_update_comment($comment_data);
    }
    
    private function performTextReplace($content, $search_text, $replace_text, $options) {
        if ($options['regex_mode']) {
            return preg_replace('/' . $search_text . '/u', $replace_text, $content);
        }
        
        if ($options['case_sensitive']) {
            return str_replace($search_text, $replace_text, $content);
        } else {
            return str_ireplace($search_text, $replace_text, $content);
        }
    }
    
    private function textContainsMatch($text, $search_text, $options) {
        if (empty($text) || empty($search_text)) {
            return false;
        }
        
        if ($options['regex_mode']) {
            return preg_match('/' . str_replace('/', '\/', $search_text) . '/u', $text);
        }
        
        if ($options['whole_words']) {
            $pattern = '\b' . preg_quote($search_text, '/') . '\b';
            $flags = $options['case_sensitive'] ? '' : 'i';
            return preg_match('/' . $pattern . '/' . $flags, $text);
        }
        
        if ($options['case_sensitive']) {
            return strpos($text, $search_text) !== false;
        } else {
            return stripos($text, $search_text) !== false;
        }
    }
    
    private function findMatchesInText($text, $search_text, $options) {
        if (empty($text) || empty($search_text)) {
            return 0;
        }
        
        try {
            if ($options['regex_mode']) {
                $count = preg_match_all('/' . str_replace('/', '\/', $search_text) . '/u', $text);
                return is_numeric($count) ? intval($count) : 0;
            }
            
            if ($options['whole_words']) {
                $pattern = '\b' . preg_quote($search_text, '/') . '\b';
                $flags = $options['case_sensitive'] ? '' : 'i';
                $count = preg_match_all('/' . $pattern . '/' . $flags, $text);
                return is_numeric($count) ? intval($count) : 0;
            }
            
            if ($options['case_sensitive']) {
                return substr_count($text, $search_text);
            } else {
                return substr_count(strtolower($text), strtolower($search_text));
            }
        } catch (Exception $e) {
            error_log('WTSR: Error in findMatchesInText: ' . $e->getMessage());
            return 0;
        }
    }
    
    private function getContentPreview($content, $search_text, $options, $context_length = 200) {
        if (empty($content)) {
            return '';
        }
        
        if (strlen($content) <= $context_length * 2) {
            return $content;
        }
        
        // Find first match position
        $first_match_pos = false;
        if ($options['case_sensitive']) {
            $first_match_pos = strpos($content, $search_text);
        } else {
            $first_match_pos = stripos($content, $search_text);
        }
        
        if ($first_match_pos === false) {
            return substr($content, 0, $context_length) . '...';
        }
        
        $start = max(0, $first_match_pos - $context_length);
        $preview = substr($content, $start, $context_length * 2);
        
        if ($start > 0) {
            $preview = '...' . $preview;
        }
        
        if ($start + $context_length * 2 < strlen($content)) {
            $preview .= '...';
        }
        
        return $preview;
    }
} 