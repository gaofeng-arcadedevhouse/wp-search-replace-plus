<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WTSR_Search_Handler {
    
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
    
    public function handleSearchRequest() {
        // Validate input
        $search_text = sanitize_textarea_field($_POST['search_text'] ?? '');
        $search_scope = $_POST['search_scope'] ?? array();
        $options = $this->parseSearchOptions($_POST);
        
        if (empty($search_text)) {
            wp_send_json_error(array(
                'message' => __('请输入搜索文本', 'worldteam-search-replace')
            ));
        }
        
        if (empty($search_scope)) {
            wp_send_json_error(array(
                'message' => __('请选择搜索范围', 'worldteam-search-replace')
            ));
        }
        
        try {
            $results = $this->performSearch($search_text, $search_scope, $options);
            
            wp_send_json_success(array(
                'results' => $results,
                'summary' => $this->generateSummary($results),
                'search_text' => $search_text,
                'total_matches' => $this->countTotalMatches($results)
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => sprintf(__('搜索失败: %s', 'worldteam-search-replace'), $e->getMessage())
            ));
        }
    }
    
    private function parseSearchOptions($post_data) {
        return array(
            'case_sensitive' => isset($post_data['case_sensitive']) && $post_data['case_sensitive'] === '1',
            'regex_mode' => isset($post_data['regex_mode']) && $post_data['regex_mode'] === '1',
            'whole_words' => isset($post_data['whole_words']) && $post_data['whole_words'] === '1'
        );
    }
    
    private function performSearch($search_text, $search_scope, $options) {
        $results = array(
            'database' => array(),
            'files' => array()
        );
        
        // Search in database
        if (array_intersect($search_scope, array('posts', 'pages', 'acf', 'meta', 'comments'))) {
            $results['database'] = $this->getDatabaseHandler()->search($search_text, $search_scope, $options);
        }
        
        // Search in files
        if (array_intersect($search_scope, array('theme', 'plugins'))) {
            $results['files'] = $this->getFileScanner()->search($search_text, $search_scope, $options);
        }
        
        return $results;
    }
    
    private function generateSummary($results) {
        $total_database = count($results['database']);
        $total_files = count($results['files']);
        $total = $total_database + $total_files;
        
        $summary = sprintf(
            __('找到 %d 个匹配项', 'worldteam-search-replace'),
            $total
        );
        
        if ($total_database > 0 && $total_files > 0) {
            $summary .= sprintf(
                __(' (数据库: %d 项，文件: %d 项)', 'worldteam-search-replace'),
                $total_database,
                $total_files
            );
        } elseif ($total_database > 0) {
            $summary .= sprintf(
                __(' (数据库: %d 项)', 'worldteam-search-replace'),
                $total_database
            );
        } elseif ($total_files > 0) {
            $summary .= sprintf(
                __(' (文件: %d 项)', 'worldteam-search-replace'),
                $total_files
            );
        }
        
        return $summary;
    }
    
    private function countTotalMatches($results) {
        $total = 0;
        
        foreach ($results['database'] as $item) {
            $total += $item['match_count'] ?? 1;
        }
        
        foreach ($results['files'] as $item) {
            $total += $item['match_count'] ?? 1;
        }
        
        return $total;
    }
    
    public function getPreview($search_text, $replace_text, $content, $options) {
        $preview = array(
            'original' => $content,
            'modified' => $this->performReplace($content, $search_text, $replace_text, $options),
            'matches' => array()
        );
        
        // Find all matches with their positions
        $preview['matches'] = $this->findMatches($content, $search_text, $options);
        
        return $preview;
    }
    
    private function findMatches($content, $search_text, $options) {
        $matches = array();
        $flags = 0;
        
        if (!$options['case_sensitive']) {
            $flags |= PREG_OFFSET_CAPTURE;
        }
        
        if ($options['regex_mode']) {
            $pattern = $search_text;
        } else {
            $pattern = preg_quote($search_text, '/');
            
            if ($options['whole_words']) {
                $pattern = '\b' . $pattern . '\b';
            }
        }
        
        if (!$options['case_sensitive'] && !$options['regex_mode']) {
            $pattern = '/' . $pattern . '/i';
        } else {
            $pattern = '/' . $pattern . '/';
        }
        
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $matches[0];
        }
        
        return array();
    }
    
    private function performReplace($content, $search_text, $replace_text, $options) {
        if ($options['regex_mode']) {
            $pattern = $search_text;
        } else {
            $pattern = preg_quote($search_text, '/');
            
            if ($options['whole_words']) {
                $pattern = '\b' . $pattern . '\b';
            }
        }
        
        if (!$options['case_sensitive'] && !$options['regex_mode']) {
            $pattern = '/' . $pattern . '/i';
        } else {
            $pattern = '/' . $pattern . '/';
        }
        
        return preg_replace($pattern, $replace_text, $content);
    }
} 