<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WTSR_File_Scanner {
    
    private $allowed_extensions = array('php'); // Only search PHP files
    private $excluded_directories = array('.git', '.svn', 'node_modules', 'vendor', '.idea', 'cache', 'tmp', 'logs');
    private $max_file_size = 2097152; // 2MB limit for performance
    private $max_files_per_scan = 1000; // Limit files per scan
    
    public function search($search_text, $search_scope, $options) {
        $results = array();
        $processed_files = 0;
        $max_files = isset($options['max_files']) ? intval($options['max_files']) : $this->max_files_per_scan;
        
        error_log('WTSR: File scanner starting with search scope: ' . implode(', ', $search_scope));
        error_log('WTSR: Max files limit: ' . $max_files);
        
        // Search in theme files
        if (in_array('theme', $search_scope) && $processed_files < $max_files) {
            $theme_directory = get_template_directory();
            error_log('WTSR: Searching theme directory: ' . $theme_directory);
            error_log('WTSR: Theme directory exists: ' . (is_dir($theme_directory) ? 'YES' : 'NO'));
            error_log('WTSR: Theme directory readable: ' . (is_readable($theme_directory) ? 'YES' : 'NO'));
            
            $theme_results = $this->searchInDirectory(
                $theme_directory,
                $search_text,
                $options,
                'theme',
                $max_files - $processed_files
            );
            $results = array_merge($results, $theme_results);
            $processed_files += count($theme_results);
            
            error_log('WTSR: Theme search found ' . count($theme_results) . ' matches');
            
            // Also search child theme if exists and still under limit
            if (is_child_theme() && $processed_files < $max_files) {
                $child_theme_directory = get_stylesheet_directory();
                error_log('WTSR: Searching child theme directory: ' . $child_theme_directory);
                
                $child_theme_results = $this->searchInDirectory(
                    $child_theme_directory,
                    $search_text,
                    $options,
                    'child_theme',
                    $max_files - $processed_files
                );
                $results = array_merge($results, $child_theme_results);
                $processed_files += count($child_theme_results);
                
                error_log('WTSR: Child theme search found ' . count($child_theme_results) . ' matches');
            }
        }
        
        error_log('WTSR: File scanner completed with ' . count($results) . ' total results');
        return $results;
    }
    
    private function searchInDirectory($directory, $search_text, $options, $type, $max_results = null) {
        $results = array();
        
        if (!is_dir($directory) || !is_readable($directory)) {
            error_log('WTSR: Directory not accessible: ' . $directory);
            return $results;
        }
        
        $processed_count = 0;
        $max_results = $max_results ?: $this->max_files_per_scan;
        
        error_log('WTSR: Starting directory scan: ' . $directory . ' (max results: ' . $max_results . ')');
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            // Pre-filter files for better performance
            $file_list = array();
            foreach ($iterator as $file) {
                if ($processed_count >= $max_results) {
                    break;
                }
                
                if ($this->isValidFile($file)) {
                    $file_list[] = $file;
                    $processed_count++;
                }
            }
            
            error_log('WTSR: Found ' . count($file_list) . ' valid files to scan in ' . $directory);
            
            // Process files with memory-efficient streaming
            foreach ($file_list as $file) {
                if (count($results) >= $max_results) {
                    break;
                }
                
                $file_result = $this->processFile($file, $directory, $search_text, $options, $type);
                if ($file_result) {
                    $results[] = $file_result;
                    error_log('WTSR: Match found in file: ' . $file_result['relative_path'] . ' (' . $file_result['match_count'] . ' matches)');
                }
                
                // Free memory periodically
                if (count($results) % 50 === 0) {
                    $this->freeMemory();
                }
            }
            
        } catch (Exception $e) {
            error_log('WTSR: File scanner error in ' . $directory . ': ' . $e->getMessage());
        }
        
        error_log('WTSR: Directory scan completed for ' . $directory . ' with ' . count($results) . ' matches');
        return $results;
    }
    
    private function processFile($file, $base_directory, $search_text, $options, $type) {
        try {
            $file_path = $file->getRealPath();
            $relative_path = str_replace($base_directory, '', $file_path);
            $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
            
            // Skip excluded directories
            if ($this->isExcludedPath($relative_path)) {
                return null;
            }
            
            // Check file size before reading
            $file_size = $file->getSize();
            if (isset($options['skip_large_files']) && $options['skip_large_files'] && $file_size > $this->max_file_size) {
                return null; // Skip large files if option is enabled
            }
            
            // Use memory-efficient file reading for large files
            if ($file_size > 524288) { // 512KB
                $matches = $this->searchLargeFile($file_path, $search_text, $options);
            } else {
                $content = file_get_contents($file_path);
                if (!$this->textContainsMatch($content, $search_text, $options)) {
                    return null;
                }
                $matches = $this->findMatchesInFile($content, $search_text, $options);
            }
            
            if (empty($matches)) {
                return null;
            }
            
            $match_context = $this->getContextLines($matches, $relative_path);
            $match_count = count($matches);
            
            return array(
                'type' => 'file',
                'id' => $relative_path,
                'title' => basename($relative_path),
                'description' => sprintf(__('File: %s', 'worldteam-search-replace'), $relative_path),
                'content' => $match_context,
                'location' => 'Files',
                'file_path' => $relative_path,
                'match_count' => $match_count
            );
            
        } catch (Exception $e) {
            return null; // Skip problematic files
        }
    }
    
    private function searchLargeFile($file_path, $search_text, $options) {
        $matches = array();
        $handle = fopen($file_path, 'r');
        
        if (!$handle) {
            return $matches;
        }
        
        $line_number = 0;
        $buffer_size = 8192; // 8KB buffer
        
        try {
            while (($line = fgets($handle, $buffer_size)) !== false) {
                $line_number++;
                
                if ($this->textContainsMatch($line, $search_text, $options)) {
                    $line_matches = $this->findMatchesInLine($line, $search_text, $options);
                    
                    foreach ($line_matches as $match) {
                        $matches[] = array(
                            'line_number' => $line_number,
                            'line_content' => trim($line),
                            'match_text' => $match['text'],
                            'match_position' => $match['position'],
                            'context_before' => '',
                            'context_after' => ''
                        );
                    }
                }
                
                // Limit matches per file to prevent memory issues
                if (count($matches) >= 100) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
        
        return $matches;
    }
    
    private function isValidFile($file) {
        if (!$file->isFile() || !$file->isReadable()) {
            return false;
        }
        
        // Skip hidden files and system files
        $filename = $file->getFilename();
        if (strpos($filename, '.') === 0) {
            return false;
        }
        
        // Check file size before processing
        if ($file->getSize() > $this->max_file_size) {
            return false;
        }
        
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowed_extensions);
    }
    
    private function isExcludedPath($path) {
        foreach ($this->excluded_directories as $excluded) {
            if (strpos($path, $excluded . '/') !== false || 
                strpos($path, $excluded . '\\') !== false ||
                strpos($path, '/' . $excluded . '/') !== false ||
                strpos($path, '\\' . $excluded . '\\') !== false) {
                return true;
            }
        }
        
        // Skip backup and temporary files
        if (preg_match('/\.(bak|backup|tmp|temp|log)$/i', $path)) {
            return true;
        }
        
        // Skip hidden files and directories  
        if (strpos($path, '/.') !== false) {
            return null;
        }
        
        // Skip temporary and log files
        if (preg_match('/\.(tmp|temp|log)$/i', $path)) {
            return null;
        }
        
        return false;
    }
    
    private function textContainsMatch($text, $search_text, $options) {
        if (empty($text)) {
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
    
    private function findMatchesInFile($content, $search_text, $options) {
        $matches = array();
        $lines = explode("\n", $content);
        
        foreach ($lines as $line_number => $line) {
            if ($this->textContainsMatch($line, $search_text, $options)) {
                $line_matches = $this->findMatchesInLine($line, $search_text, $options);
                
                foreach ($line_matches as $match) {
                    $matches[] = array(
                        'line_number' => $line_number + 1,
                        'line_content' => trim($line),
                        'match_text' => $match['text'],
                        'match_position' => $match['position'],
                        'context_before' => $this->getContextLines($lines, $line_number, -2),
                        'context_after' => $this->getContextLines($lines, $line_number, 2)
                    );
                }
            }
        }
        
        return $matches;
    }
    
    private function findMatchesInLine($line, $search_text, $options) {
        $matches = array();
        
        if ($options['regex_mode']) {
            $pattern = '/' . str_replace('/', '\/', $search_text) . '/u';
            if (preg_match_all($pattern, $line, $regex_matches, PREG_OFFSET_CAPTURE)) {
                foreach ($regex_matches[0] as $match) {
                    $matches[] = array(
                        'text' => $match[0],
                        'position' => $match[1]
                    );
                }
            }
        } else {
            $search_func = $options['case_sensitive'] ? 'strpos' : 'stripos';
            $offset = 0;
            
            while (($pos = $search_func($line, $search_text, $offset)) !== false) {
                $matches[] = array(
                    'text' => substr($line, $pos, strlen($search_text)),
                    'position' => $pos
                );
                $offset = $pos + 1;
            }
        }
        
        return $matches;
    }
    
    private function getContextLines($lines, $current_line, $offset) {
        if ($offset < 0) {
            $start = max(0, $current_line + $offset);
            $end = $current_line - 1;
        } else {
            $start = $current_line + 1;
            $end = min(count($lines) - 1, $current_line + $offset);
        }
        
        $context = array();
        for ($i = $start; $i <= $end; $i++) {
            if (isset($lines[$i])) {
                $context[] = array(
                    'line_number' => $i + 1,
                    'content' => trim($lines[$i])
                );
            }
        }
        
        return $context;
    }
    
    private function getEditUrl($file_path, $type) {
        if ($type === 'theme' || $type === 'child_theme') {
            // Check if it's a theme file that can be edited
            $theme_root = get_theme_root();
            if (strpos($file_path, $theme_root) === 0) {
                $relative_path = str_replace($theme_root . '/', '', $file_path);
                $theme_name = explode('/', $relative_path)[0];
                $file_name = str_replace($theme_name . '/', '', $relative_path);
                
                return admin_url('theme-editor.php?file=' . urlencode($file_name) . '&theme=' . urlencode($theme_name));
            }
        } elseif ($type === 'plugin') {
            // Check if it's a plugin file that can be edited
            if (strpos($file_path, WP_PLUGIN_DIR) === 0) {
                $relative_path = str_replace(WP_PLUGIN_DIR . '/', '', $file_path);
                return admin_url('plugin-editor.php?file=' . urlencode($relative_path));
            }
        }
        
        return null;
    }
    
    public function replace($search_text, $replace_text, $items, $options) {
        $replaced_files = 0;
        $errors = array();
        
        foreach ($items as $item) {
            if ($item['type'] !== 'file') {
                continue;
            }
            
            try {
                $this->replaceInFile($item, $search_text, $replace_text, $options);
                $replaced_files++;
                
            } catch (Exception $e) {
                $errors[] = sprintf(
                    __('Failed to replace file %s: %s', 'worldteam-search-replace'),
                    $item['relative_path'],
                    $e->getMessage()
                );
            }
        }
        
        return array(
            'replaced_files' => $replaced_files,
            'errors' => $errors
        );
    }
    
    private function replaceInFile($item, $search_text, $replace_text, $options) {
        $file_path = $item['file_path'];
        
        // Check file permissions
        if (!is_writable($file_path)) {
            throw new Exception(__('File is not writable', 'worldteam-search-replace'));
        }
        
        // Read current content
        $content = file_get_contents($file_path);
        if ($content === false) {
            throw new Exception(__('Cannot read file content', 'worldteam-search-replace'));
        }
        
        // Perform replacement
        $new_content = $this->performTextReplace($content, $search_text, $replace_text, $options);
        
        // Check if any changes were made
        if ($content !== $new_content) {
            error_log("WTSR: Writing updated content to file: $file_path");
            
            // Write the updated content back to the file
            if (file_put_contents($file_path, $new_content) === false) {
                throw new Exception(__('Cannot write to file', 'worldteam-search-replace'));
            }
            
            $replaced_files++;
            error_log("WTSR: File updated successfully: $file_path");
        } else {
            error_log("WTSR: No changes made to file: $file_path");
        }
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
    
    private function freeMemory() {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
} 