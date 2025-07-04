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
        // Add error logging for debugging
        error_log('WTSR: Search request received');
        
        // Validate nonce and permissions first
        if (!current_user_can('manage_options')) {
            error_log('WTSR: Insufficient permissions for search');
            wp_send_json_error(array(
                'message' => __('Insufficient permissions', 'worldteam-search-replace')
            ));
            return;
        }
        
        // Validate input
        $search_text = sanitize_textarea_field($_POST['search_text'] ?? '');
        $search_scope = $_POST['search_scope'] ?? array();
        $options = $this->parseSearchOptions($_POST);
        
        error_log('WTSR: Search parameters - Text: ' . $search_text . ', Scope: ' . implode(',', $search_scope));
        
        if (empty($search_text)) {
            error_log('WTSR: Empty search text');
            wp_send_json_error(array(
                'message' => __('Please enter search text', 'worldteam-search-replace')
            ));
            return;
        }
        
        if (empty($search_scope) || !is_array($search_scope)) {
            error_log('WTSR: Invalid search scope');
            wp_send_json_error(array(
                'message' => __('Please select search scope', 'worldteam-search-replace')
            ));
            return;
        }
        
        try {
            error_log('WTSR: Starting search operation');
            $results = $this->performSearch($search_text, $search_scope, $options);
            error_log('WTSR: Search completed successfully');
            
            $response_data = array(
                'results' => $results,
                'summary' => $this->generateSummary($results),
                'search_text' => $search_text,
                'total_matches' => $this->countTotalMatches($results)
            );
            
            error_log('WTSR: Sending success response with ' . count($results['database']) . ' DB results and ' . count($results['files']) . ' file results');
            
            wp_send_json_success($response_data);
            
        } catch (Exception $e) {
            error_log('WTSR: Search failed with exception: ' . $e->getMessage());
            error_log('WTSR: Exception trace: ' . $e->getTraceAsString());
            
            wp_send_json_error(array(
                'message' => sprintf(__('Search failed: %s', 'worldteam-search-replace'), $e->getMessage())
            ));
        } catch (Error $e) {
            error_log('WTSR: Search failed with fatal error: ' . $e->getMessage());
            error_log('WTSR: Error trace: ' . $e->getTraceAsString());
            
            wp_send_json_error(array(
                'message' => sprintf(__('Error occurred during search: %s', 'worldteam-search-replace'), $e->getMessage())
            ));
        }
    }
    
    private function parseSearchOptions($post_data) {
        return array(
            'case_sensitive' => isset($post_data['case_sensitive']) && $post_data['case_sensitive'] === '1',
            'regex_mode' => isset($post_data['regex_mode']) && $post_data['regex_mode'] === '1',
            'whole_words' => isset($post_data['whole_words']) && $post_data['whole_words'] === '1',
            // Performance options
            'per_page' => isset($post_data['per_page']) ? max(10, min(1000, intval($post_data['per_page']))) : 50,
            'page' => isset($post_data['page']) ? max(1, intval($post_data['page'])) : 1,
            'max_files' => isset($post_data['max_files']) ? max(100, min(2000, intval($post_data['max_files']))) : 500,
            'db_limit' => isset($post_data['db_limit']) ? max(50, min(500, intval($post_data['db_limit']))) : 100,
            'skip_large_files' => isset($post_data['skip_large_files']) && $post_data['skip_large_files'] === '1',
            'enable_cache' => isset($post_data['enable_cache']) && $post_data['enable_cache'] === '1'
        );
    }
    
    private function performSearch($search_text, $search_scope, $options) {
        $start_time = microtime(true);
        
        $results = array(
            'database' => array(),
            'files' => array()
        );
        
        // Add performance logging
        error_log('WTSR: Starting search with scope: ' . implode(',', $search_scope));
        error_log('WTSR: Search text: ' . $search_text);
        error_log('WTSR: Starting search with options: ' . json_encode($options));
        
        // Search in database
        $database_scopes = array('posts', 'pages', 'acf', 'meta', 'comments');
        
        // Add custom post types to database scopes
        foreach ($search_scope as $scope_item) {
            if (strpos($scope_item, 'cpt_') === 0) {
                $database_scopes[] = $scope_item;
            }
        }
        
        if (array_intersect($search_scope, $database_scopes)) {
            error_log('WTSR: Starting database search');
            $db_start = microtime(true);
            try {
                $db_results = $this->getDatabaseHandler()->search($search_text, $search_scope, $options);
                if (is_array($db_results)) {
                    $results['database'] = $db_results;
                    error_log('WTSR: Database search returned ' . count($db_results) . ' results');
                } else {
                    error_log('WTSR: Database search returned non-array result: ' . gettype($db_results));
                    $results['database'] = array();
                }
            } catch (Exception $e) {
                error_log('WTSR: Database search failed: ' . $e->getMessage());
                $results['database'] = array();
            }
            $db_time = microtime(true) - $db_start;
            error_log('WTSR: Database search completed in ' . round($db_time, 3) . ' seconds');
        } else {
            error_log('WTSR: Skipping database search - no database scopes selected');
        }
        
        // Search in files
        if (array_intersect($search_scope, array('theme'))) {
            error_log('WTSR: Starting file search');
            $file_start = microtime(true);
            try {
                $file_results = $this->getFileScanner()->search($search_text, $search_scope, $options);
                if (is_array($file_results)) {
                    $results['files'] = $file_results;
                    error_log('WTSR: File search returned ' . count($file_results) . ' results');
                } else {
                    error_log('WTSR: File search returned non-array result: ' . gettype($file_results));
                    $results['files'] = array();
                }
            } catch (Exception $e) {
                error_log('WTSR: File search failed: ' . $e->getMessage());
                $results['files'] = array();
            }
            $file_time = microtime(true) - $file_start;
            error_log('WTSR: File search completed in ' . round($file_time, 3) . ' seconds');
        } else {
            error_log('WTSR: Skipping file search - no file scopes selected');
        }
        
        $total_time = microtime(true) - $start_time;
        error_log('WTSR: Total search completed in ' . round($total_time, 3) . ' seconds');
        error_log('WTSR: Final results - DB: ' . count($results['database']) . ', Files: ' . count($results['files']));
        
        return $results;
    }
    
    private function generateSummary($results) {
        $total_database = is_array($results['database']) ? count($results['database']) : 0;
        $total_files = is_array($results['files']) ? count($results['files']) : 0;
        $total = $total_database + $total_files;
        
        error_log('WTSR: Summary generation - DB: ' . $total_database . ', Files: ' . $total_files . ', Total: ' . $total);
        
        if ($total === 0) {
            return __('No matching results found', 'worldteam-search-replace');
        }
        
        $summary = sprintf(
            __('Found %d matching items', 'worldteam-search-replace'),
            $total
        );
        
        if ($total_database > 0 && $total_files > 0) {
            $summary .= sprintf(
                __(' (Database: %d items, Files: %d items)', 'worldteam-search-replace'),
                $total_database,
                $total_files
            );
        } elseif ($total_database > 0) {
            $summary .= sprintf(
                __(' (Database: %d items)', 'worldteam-search-replace'),
                $total_database
            );
        } elseif ($total_files > 0) {
            $summary .= sprintf(
                __(' (Files: %d items)', 'worldteam-search-replace'),
                $total_files
            );
        }
        
        // Add performance hints
        if ($total > 500) {
            $summary .= ' ' . __('Tip: Results are numerous, consider using pagination or narrowing the search scope', 'worldteam-search-replace');
        }
        
        return $summary;
    }
    
    private function countTotalMatches($results) {
        $total = 0;
        
        if (isset($results['database']) && is_array($results['database'])) {
            foreach ($results['database'] as $item) {
                $total += isset($item['match_count']) ? intval($item['match_count']) : 1;
            }
        }
        
        if (isset($results['files']) && is_array($results['files'])) {
            foreach ($results['files'] as $item) {
                $total += isset($item['match_count']) ? intval($item['match_count']) : 1;
            }
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