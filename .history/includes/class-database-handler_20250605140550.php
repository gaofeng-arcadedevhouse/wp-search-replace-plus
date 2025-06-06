<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WTSR_Database_Handler {
    
    private $wpdb;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
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
        $results = array();
        
        // Search in posts and pages
        if (in_array('posts', $search_scope) || in_array('pages', $search_scope)) {
            $post_types = array();
            if (in_array('posts', $search_scope)) {
                $post_types[] = 'post';
            }
            if (in_array('pages', $search_scope)) {
                $post_types[] = 'page';
            }
            
            $results = array_merge($results, $this->searchPosts($search_text, $post_types, $options));
        }
        
        // Search in ACF fields
        if (in_array('acf', $search_scope)) {
            $results = array_merge($results, $this->searchACFFields($search_text, $options));
        }
        
        // Search in custom fields
        if (in_array('meta', $search_scope)) {
            $results = array_merge($results, $this->searchPostMeta($search_text, $options));
        }
        
        // Search in comments
        if (in_array('comments', $search_scope)) {
            $results = array_merge($results, $this->searchComments($search_text, $options));
        }
        
        return $results;
    }
    
    private function searchPosts($search_text, $post_types, $options) {
        $results = array();
        $post_types_str = "'" . implode("','", array_map('esc_sql', $post_types)) . "'";
        
        // Build search query
        $search_condition = $this->buildSearchCondition($search_text, $options, array(
            'post_title',
            'post_content',
            'post_excerpt'
        ));
        
        $sql = "SELECT ID, post_title, post_type, post_status, post_content, post_excerpt 
                FROM {$this->wpdb->posts} 
                WHERE post_type IN ($post_types_str) 
                AND post_status IN ('publish', 'draft', 'private') 
                AND ($search_condition)
                ORDER BY post_modified DESC
                LIMIT 100";
        
        $posts = $this->wpdb->get_results($sql);
        
        foreach ($posts as $post) {
            $matches = array();
            
            // Check title
            if ($this->textContainsMatch($post->post_title, $search_text, $options)) {
                $matches[] = array(
                    'field' => 'post_title',
                    'content' => $post->post_title,
                    'matches' => $this->findMatchesInText($post->post_title, $search_text, $options)
                );
            }
            
            // Check content
            if ($this->textContainsMatch($post->post_content, $search_text, $options)) {
                $matches[] = array(
                    'field' => 'post_content',
                    'content' => $this->getContentPreview($post->post_content, $search_text, $options),
                    'matches' => $this->findMatchesInText($post->post_content, $search_text, $options)
                );
            }
            
            // Check excerpt
            if ($this->textContainsMatch($post->post_excerpt, $search_text, $options)) {
                $matches[] = array(
                    'field' => 'post_excerpt',
                    'content' => $post->post_excerpt,
                    'matches' => $this->findMatchesInText($post->post_excerpt, $search_text, $options)
                );
            }
            
            if (!empty($matches)) {
                $results[] = array(
                    'type' => 'post',
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'post_type' => $post->post_type,
                    'post_status' => $post->post_status,
                    'edit_url' => get_edit_post_link($post->ID),
                    'view_url' => get_permalink($post->ID),
                    'matches' => $matches,
                    'match_count' => array_sum(array_column($matches, 'matches'))
                );
            }
        }
        
        return $results;
    }
    
    private function searchACFFields($search_text, $options) {
        $results = array();
        
        // Only search if ACF is active
        if (!function_exists('get_fields')) {
            return $results;
        }
        
        $search_condition = $this->buildSearchCondition($search_text, $options, array('meta_value'));
        
        $sql = "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type 
                FROM {$this->wpdb->postmeta} pm
                INNER JOIN {$this->wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key LIKE 'field_%' 
                AND ($search_condition)
                AND p.post_status IN ('publish', 'draft', 'private')
                ORDER BY pm.post_id DESC
                LIMIT 100";
        
        $meta_fields = $this->wpdb->get_results($sql);
        
        foreach ($meta_fields as $field) {
            if ($this->textContainsMatch($field->meta_value, $search_text, $options)) {
                $field_object = get_field_object($field->meta_key, $field->post_id);
                $field_name = $field_object ? $field_object['label'] : $field->meta_key;
                
                $results[] = array(
                    'type' => 'acf',
                    'id' => $field->post_id,
                    'title' => $field->post_title,
                    'post_type' => $field->post_type,
                    'field_key' => $field->meta_key,
                    'field_name' => $field_name,
                    'edit_url' => get_edit_post_link($field->post_id),
                    'view_url' => get_permalink($field->post_id),
                    'matches' => array(array(
                        'field' => 'meta_value',
                        'content' => $this->getContentPreview($field->meta_value, $search_text, $options),
                        'matches' => $this->findMatchesInText($field->meta_value, $search_text, $options)
                    )),
                    'match_count' => $this->findMatchesInText($field->meta_value, $search_text, $options)
                );
            }
        }
        
        return $results;
    }
    
    private function searchPostMeta($search_text, $options) {
        $results = array();
        
        $search_condition = $this->buildSearchCondition($search_text, $options, array('meta_value'));
        
        $sql = "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type 
                FROM {$this->wpdb->postmeta} pm
                INNER JOIN {$this->wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key NOT LIKE 'field_%' 
                AND pm.meta_key NOT LIKE '_%'
                AND ($search_condition)
                AND p.post_status IN ('publish', 'draft', 'private')
                ORDER BY pm.post_id DESC
                LIMIT 100";
        
        $meta_fields = $this->wpdb->get_results($sql);
        
        foreach ($meta_fields as $field) {
            if ($this->textContainsMatch($field->meta_value, $search_text, $options)) {
                $results[] = array(
                    'type' => 'meta',
                    'id' => $field->post_id,
                    'title' => $field->post_title,
                    'post_type' => $field->post_type,
                    'meta_key' => $field->meta_key,
                    'edit_url' => get_edit_post_link($field->post_id),
                    'view_url' => get_permalink($field->post_id),
                    'matches' => array(array(
                        'field' => 'meta_value',
                        'content' => $this->getContentPreview($field->meta_value, $search_text, $options),
                        'matches' => $this->findMatchesInText($field->meta_value, $search_text, $options)
                    )),
                    'match_count' => $this->findMatchesInText($field->meta_value, $search_text, $options)
                );
            }
        }
        
        return $results;
    }
    
    private function searchComments($search_text, $options) {
        $results = array();
        
        $search_condition = $this->buildSearchCondition($search_text, $options, array(
            'comment_content',
            'comment_author'
        ));
        
        $sql = "SELECT comment_ID, comment_post_ID, comment_author, comment_content, comment_date
                FROM {$this->wpdb->comments} 
                WHERE comment_approved = '1' 
                AND ($search_condition)
                ORDER BY comment_date DESC
                LIMIT 100";
        
        $comments = $this->wpdb->get_results($sql);
        
        foreach ($comments as $comment) {
            $matches = array();
            
            if ($this->textContainsMatch($comment->comment_content, $search_text, $options)) {
                $matches[] = array(
                    'field' => 'comment_content',
                    'content' => $this->getContentPreview($comment->comment_content, $search_text, $options),
                    'matches' => $this->findMatchesInText($comment->comment_content, $search_text, $options)
                );
            }
            
            if ($this->textContainsMatch($comment->comment_author, $search_text, $options)) {
                $matches[] = array(
                    'field' => 'comment_author',
                    'content' => $comment->comment_author,
                    'matches' => $this->findMatchesInText($comment->comment_author, $search_text, $options)
                );
            }
            
            if (!empty($matches)) {
                $post = get_post($comment->comment_post_ID);
                $results[] = array(
                    'type' => 'comment',
                    'id' => $comment->comment_ID,
                    'title' => sprintf(__('评论 #%d - %s', 'worldteam-search-replace'), $comment->comment_ID, $post ? $post->post_title : ''),
                    'post_id' => $comment->comment_post_ID,
                    'author' => $comment->comment_author,
                    'date' => $comment->comment_date,
                    'edit_url' => admin_url('comment.php?action=editcomment&c=' . $comment->comment_ID),
                    'view_url' => get_comment_link($comment->comment_ID),
                    'matches' => $matches,
                    'match_count' => array_sum(array_column($matches, 'matches'))
                );
            }
        }
        
        return $results;
    }
    
    private function buildSearchCondition($search_text, $options, $fields) {
        $conditions = array();
        
        foreach ($fields as $field) {
            if ($options['regex_mode']) {
                $conditions[] = "$field REGEXP '" . esc_sql($search_text) . "'";
            } else {
                $search_term = esc_sql($search_text);
                
                if ($options['case_sensitive']) {
                    $conditions[] = "$field LIKE BINARY '%$search_term%'";
                } else {
                    $conditions[] = "$field LIKE '%$search_term%'";
                }
            }
        }
        
        return implode(' OR ', $conditions);
    }
    
    private function textContainsMatch($text, $search_text, $options) {
        if (empty($text)) {
            return false;
        }
        
        if ($options['regex_mode']) {
            return preg_match('/' . $search_text . '/u', $text);
        }
        
        if ($options['case_sensitive']) {
            return strpos($text, $search_text) !== false;
        } else {
            return stripos($text, $search_text) !== false;
        }
    }
    
    private function findMatchesInText($text, $search_text, $options) {
        if (empty($text)) {
            return 0;
        }
        
        if ($options['regex_mode']) {
            return preg_match_all('/' . $search_text . '/u', $text);
        }
        
        if ($options['case_sensitive']) {
            return substr_count($text, $search_text);
        } else {
            return substr_count(strtolower($text), strtolower($search_text));
        }
    }
    
    private function getContentPreview($content, $search_text, $options, $context_length = 200) {
        if (strlen($content) <= $context_length * 2) {
            return $content;
        }
        
        $first_match_pos = $options['case_sensitive'] 
            ? strpos($content, $search_text)
            : stripos($content, $search_text);
        
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
                    __('替换项目 %s 失败: %s', 'worldteam-search-replace'),
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
} 