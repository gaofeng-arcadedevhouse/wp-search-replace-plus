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
            <h2><?php _e('搜索设置', 'worldteam-search-replace'); ?></h2>
            
            <form id="wtsr-search-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="search_text"><?php _e('搜索文本', 'worldteam-search-replace'); ?></label>
                        </th>
                        <td>
                            <textarea id="search_text" name="search_text" rows="3" cols="50" class="large-text" placeholder="<?php _e('输入要搜索的文本...', 'worldteam-search-replace'); ?>"></textarea>
                            <p class="description"><?php _e('支持正则表达式搜索', 'worldteam-search-replace'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="replace_text"><?php _e('替换文本', 'worldteam-search-replace'); ?></label>
                        </th>
                        <td>
                            <textarea id="replace_text" name="replace_text" rows="3" cols="50" class="large-text" placeholder="<?php _e('输入替换后的文本...', 'worldteam-search-replace'); ?>"></textarea>
                            <p class="description"><?php _e('留空则为删除操作', 'worldteam-search-replace'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('搜索范围', 'worldteam-search-replace'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php _e('选择搜索范围', 'worldteam-search-replace'); ?></legend>
                                
                                <h4><?php _e('数据库内容', 'worldteam-search-replace'); ?></h4>
                                <label for="search_posts">
                                    <input type="checkbox" id="search_posts" name="search_scope[]" value="posts" checked>
                                    <?php _e('文章内容 (Posts)', 'worldteam-search-replace'); ?>
                                </label><br>
                                
                                <label for="search_pages">
                                    <input type="checkbox" id="search_pages" name="search_scope[]" value="pages" checked>
                                    <?php _e('页面内容 (Pages)', 'worldteam-search-replace'); ?>
                                </label><br>
                                
                                <label for="search_acf">
                                    <input type="checkbox" id="search_acf" name="search_scope[]" value="acf" checked>
                                    <?php _e('ACF字段', 'worldteam-search-replace'); ?>
                                </label><br>
                                
                                <label for="search_meta">
                                    <input type="checkbox" id="search_meta" name="search_scope[]" value="meta">
                                    <?php _e('自定义字段', 'worldteam-search-replace'); ?>
                                </label><br>
                                
                                <label for="search_comments">
                                    <input type="checkbox" id="search_comments" name="search_scope[]" value="comments">
                                    <?php _e('评论内容', 'worldteam-search-replace'); ?>
                                </label><br>
                                
                                <h4><?php _e('文件系统', 'worldteam-search-replace'); ?></h4>
                                <label for="search_theme">
                                    <input type="checkbox" id="search_theme" name="search_scope[]" value="theme">
                                    <?php _e('主题文件', 'worldteam-search-replace'); ?>
                                </label><br>
                                
                                <label for="search_plugins">
                                    <input type="checkbox" id="search_plugins" name="search_scope[]" value="plugins">
                                    <?php _e('插件文件', 'worldteam-search-replace'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('搜索选项', 'worldteam-search-replace'); ?></th>
                        <td>
                            <label for="case_sensitive">
                                <input type="checkbox" id="case_sensitive" name="case_sensitive" value="1">
                                <?php _e('区分大小写', 'worldteam-search-replace'); ?>
                            </label><br>
                            
                            <label for="regex_mode">
                                <input type="checkbox" id="regex_mode" name="regex_mode" value="1">
                                <?php _e('正则表达式模式', 'worldteam-search-replace'); ?>
                            </label><br>
                            
                            <label for="whole_words">
                                <input type="checkbox" id="whole_words" name="whole_words" value="1">
                                <?php _e('匹配完整单词', 'worldteam-search-replace'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="button" id="wtsr-search-btn" class="button button-primary">
                        <span class="dashicons dashicons-search"></span>
                        <?php _e('开始搜索', 'worldteam-search-replace'); ?>
                    </button>
                    
                    <button type="button" id="wtsr-backup-btn" class="button button-secondary">
                        <span class="dashicons dashicons-backup"></span>
                        <?php _e('创建备份', 'worldteam-search-replace'); ?>
                    </button>
                </p>
            </form>
        </div>
        
        <!-- Search Results -->
        <div id="wtsr-results" class="wtsr-results" style="display: none;">
            <h2><?php _e('搜索结果', 'worldteam-search-replace'); ?></h2>
            
            <div class="wtsr-results-summary">
                <p id="wtsr-summary-text"></p>
            </div>
            
            <div class="wtsr-results-actions">
                <button type="button" id="wtsr-select-all" class="button">
                    <?php _e('全选', 'worldteam-search-replace'); ?>
                </button>
                <button type="button" id="wtsr-select-none" class="button">
                    <?php _e('取消全选', 'worldteam-search-replace'); ?>
                </button>
                <button type="button" id="wtsr-replace-selected" class="button button-primary" disabled>
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('替换选中项', 'worldteam-search-replace'); ?>
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
            <h3><?php _e('确认替换操作', 'worldteam-search-replace'); ?></h3>
            <button type="button" class="wtsr-modal-close">&times;</button>
        </div>
        <div class="wtsr-modal-body">
            <p class="wtsr-warning">
                <span class="dashicons dashicons-warning"></span>
                <?php _e('您即将执行替换操作，此操作不可撤销！请确认以下信息：', 'worldteam-search-replace'); ?>
            </p>
            <div id="wtsr-confirmation-details"></div>
        </div>
        <div class="wtsr-modal-footer">
            <button type="button" id="wtsr-confirm-replace" class="button button-primary">
                <?php _e('确认替换', 'worldteam-search-replace'); ?>
            </button>
            <button type="button" id="wtsr-cancel-replace" class="button">
                <?php _e('取消', 'worldteam-search-replace'); ?>
            </button>
        </div>
    </div>
</div> 