<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap wtsr-admin-page">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="wtsr-container">
        <!-- Search Form -->
        <div class="wtsr-search-form">
            <h2><?php _e('Search Settings', 'worldteam-search-replace'); ?></h2>
            
            <form id="wtsr-search-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="search_text"><?php _e('Search Text', 'worldteam-search-replace'); ?></label>
                        </th>
                        <td>
                            <textarea id="search_text" name="search_text" rows="3" cols="50" class="large-text" placeholder="<?php _e('Enter text to search for...', 'worldteam-search-replace'); ?>"></textarea>
                            <p class="description"><?php _e('Supports regular expression search', 'worldteam-search-replace'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="replace_text"><?php _e('Replace Text', 'worldteam-search-replace'); ?></label>
                        </th>
                        <td>
                            <textarea id="replace_text" name="replace_text" rows="3" cols="50" class="large-text" placeholder="<?php _e('Enter replacement text...', 'worldteam-search-replace'); ?>"></textarea>
                            <p class="description"><?php _e('Leave empty for deletion', 'worldteam-search-replace'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Search Scope', 'worldteam-search-replace'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php _e('Select search scope', 'worldteam-search-replace'); ?></legend>
                                
                                <h4><?php _e('Database Content', 'worldteam-search-replace'); ?></h4>
                                <label for="search_posts">
                                    <input type="checkbox" id="search_posts" name="search_scope[]" value="posts" checked>
                                    <?php _e('Posts Content', 'worldteam-search-replace'); ?>
                                </label><br>
                                
                                <label for="search_pages">
                                    <input type="checkbox" id="search_pages" name="search_scope[]" value="pages" checked>
                                    <?php _e('Pages Content', 'worldteam-search-replace'); ?>
                                </label><br>
                                
                                <?php
                                // Get all custom post types
                                $custom_post_types = get_post_types(array(
                                    'public' => true,
                                    '_builtin' => false
                                ), 'objects');
                                
                                // Debug: Log all available post types
                                $all_post_types = get_post_types(array(), 'objects');
                                error_log('WTSR: All post types available: ' . implode(', ', array_keys($all_post_types)));
                                error_log('WTSR: Public custom post types: ' . implode(', ', array_keys($custom_post_types)));
                                
                                // Add widgets if it exists as a post type
                                if (isset($all_post_types['widgets'])) {
                                    $custom_post_types['widgets'] = $all_post_types['widgets'];
                                    error_log('WTSR: Widgets post type found and added');
                                } elseif (isset($all_post_types['widget'])) {
                                    $custom_post_types['widget'] = $all_post_types['widget'];
                                    error_log('WTSR: Widget (singular) post type found and added');
                                } else {
                                    error_log('WTSR: Neither widgets nor widget post type found');
                                    // Check for similar post types
                                    foreach ($all_post_types as $pt_name => $pt_obj) {
                                        if (strpos($pt_name, 'widget') !== false) {
                                            error_log('WTSR: Found similar post type: ' . $pt_name);
                                        }
                                    }
                                }
                                
                                foreach ($custom_post_types as $post_type_name => $post_type_obj):
                                    $label = $post_type_obj->labels->name;
                                    $checkbox_id = 'search_' . $post_type_name;
                                    $checkbox_value = 'cpt_' . $post_type_name;
                                    error_log('WTSR: Adding checkbox for post type: ' . $post_type_name . ' (' . $label . ')');
                                ?>
                                <label for="<?php echo esc_attr($checkbox_id); ?>">
                                    <input type="checkbox" id="<?php echo esc_attr($checkbox_id); ?>" name="search_scope[]" value="<?php echo esc_attr($checkbox_value); ?>" <?php echo ($post_type_name === 'widgets' || $post_type_name === 'widget') ? 'checked' : ''; ?>>
                                    <?php echo esc_html($label); ?>
                                </label><br>
                                <?php endforeach; ?>
                                
                                <?php if (function_exists('get_fields') || function_exists('get_field_object')): ?>
                                <label for="search_acf">
                                    <input type="checkbox" id="search_acf" name="search_scope[]" value="acf">
                                    <?php _e('ACF Fields (All Post Types)', 'worldteam-search-replace'); ?>
                                </label><br>
                                <?php else: ?>
                                <label for="search_acf" style="color: #999;">
                                    <input type="checkbox" id="search_acf" name="search_scope[]" value="acf" disabled>
                                    <?php _e('ACF Fields (requires ACF plugin)', 'worldteam-search-replace'); ?>
                                </label><br>
                                <?php endif; ?>
                                
                                <label for="search_meta">
                                    <input type="checkbox" id="search_meta" name="search_scope[]" value="meta">
                                    <?php _e('Custom Fields', 'worldteam-search-replace'); ?>
                                </label><br>
                                
                                <label for="search_comments">
                                    <input type="checkbox" id="search_comments" name="search_scope[]" value="comments">
                                    <?php _e('Comments Content', 'worldteam-search-replace'); ?>
                                </label><br>
                                
                                <h4><?php _e('File System', 'worldteam-search-replace'); ?></h4>
                                <label for="search_theme">
                                    <input type="checkbox" id="search_theme" name="search_scope[]" value="theme">
                                    <?php _e('Theme Files', 'worldteam-search-replace'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Search Options', 'worldteam-search-replace'); ?></th>
                        <td>
                            <label for="case_sensitive">
                                <input type="checkbox" id="case_sensitive" name="case_sensitive" value="1">
                                <?php _e('Case Sensitive', 'worldteam-search-replace'); ?>
                            </label><br>
                            
                            <label for="regex_mode">
                                <input type="checkbox" id="regex_mode" name="regex_mode" value="1">
                                <?php _e('Regular Expression Mode', 'worldteam-search-replace'); ?>
                            </label><br>
                            
                            <label for="whole_words">
                                <input type="checkbox" id="whole_words" name="whole_words" value="1">
                                <?php _e('Match Whole Words', 'worldteam-search-replace'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Performance Settings', 'worldteam-search-replace'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php _e('Performance optimization options', 'worldteam-search-replace'); ?></legend>
                                
                                <p>
                                    <label for="wtsr-max-files"><?php _e('Max File Search Limit:', 'worldteam-search-replace'); ?></label>
                                    <input type="number" id="wtsr-max-files" name="max_files" value="500" min="100" max="2000" style="width: 80px;">
                                    <span class="description"><?php _e('Limit file search count for better performance (recommended: 500-1000)', 'worldteam-search-replace'); ?></span>
                                </p>
                                
                                <p>
                                    <label for="wtsr-db-limit"><?php _e('Database Query Limit:', 'worldteam-search-replace'); ?></label>
                                    <select id="wtsr-db-limit" name="db_limit">
                                        <option value="50">50 per page</option>
                                        <option value="100" selected>100 per page</option>
                                        <option value="200">200 per page</option>
                                        <option value="500">500 per page</option>
                                    </select>
                                    <span class="description"><?php _e('Number of database results per page', 'worldteam-search-replace'); ?></span>
                                </p>
                                
                                <p>
                                    <label>
                                        <input type="checkbox" id="wtsr-skip-large-files" name="skip_large_files" value="1" checked>
                                        <?php _e('Skip Large Files (>2MB)', 'worldteam-search-replace'); ?>
                                    </label>
                                    <span class="description"><?php _e('Skip large files to improve search speed', 'worldteam-search-replace'); ?></span>
                                </p>
                                
                                <p>
                                    <label>
                                        <input type="checkbox" id="wtsr-enable-cache" name="enable_cache" value="1">
                                        <?php _e('Enable Search Cache', 'worldteam-search-replace'); ?>
                                    </label>
                                    <span class="description"><?php _e('Cache search results for faster repeated searches (experimental feature)', 'worldteam-search-replace'); ?></span>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="button" id="wtsr-search-btn" class="button button-primary">
                        <span class="dashicons dashicons-search"></span>
                        <?php _e('Start Search', 'worldteam-search-replace'); ?>
                    </button>
                </p>
            </form>
        </div>
        
        <!-- Search Results -->
        <div id="wtsr-results" class="wtsr-results" style="display: none;">
            <h2><?php _e('Search Results', 'worldteam-search-replace'); ?></h2>
            
            <div class="wtsr-results-summary">
                <p id="wtsr-summary-text"></p>
            </div>
            
            <div class="wtsr-results-actions">
                <button type="button" id="wtsr-select-all" class="button">
                    <?php _e('Select All', 'worldteam-search-replace'); ?>
                </button>
                <button type="button" id="wtsr-select-none" class="button">
                    <?php _e('Select None', 'worldteam-search-replace'); ?>
                </button>
                <button type="button" id="wtsr-replace-selected" class="button button-primary" disabled>
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Replace Selected', 'worldteam-search-replace'); ?>
                </button>
            </div>
            
            <div id="wtsr-results-table" class="wtsr-results-table">
                <!-- Results will be loaded here via AJAX -->
            </div>
        </div>
        
        <!-- Progress Bar -->
        <div id="wtsr-progress" class="wtsr-progress" style="display: none;">
            <div class="wtsr-progress-bar">
                <div class="wtsr-progress-fill"></div>
            </div>
            <p class="wtsr-progress-text"></p>
        </div>
        
        <!-- Status Messages -->
        <div id="wtsr-messages" class="wtsr-messages"></div>
    </div>
</div>

<!-- Confirmation Modal -->
<div id="wtsr-modal" class="wtsr-modal" style="display: none;">
    <div class="wtsr-modal-content">
        <div class="wtsr-modal-header">
            <h3><?php _e('Confirm Replace Operation', 'worldteam-search-replace'); ?></h3>
            <button type="button" class="wtsr-modal-close">&times;</button>
        </div>
        <div class="wtsr-modal-body">
            <p class="wtsr-warning">
                <span class="dashicons dashicons-warning"></span>
                <?php _e('You are about to perform a replace operation that cannot be undone! Please confirm the following information:', 'worldteam-search-replace'); ?>
            </p>
            <div id="wtsr-confirmation-details"></div>
        </div>
        <div class="wtsr-modal-footer">
            <button type="button" id="wtsr-confirm-replace" class="button button-primary">
                <?php _e('Confirm Replace', 'worldteam-search-replace'); ?>
            </button>
            <button type="button" id="wtsr-cancel-replace" class="button">
                <?php _e('Cancel', 'worldteam-search-replace'); ?>
            </button>
        </div>
    </div>
</div> 