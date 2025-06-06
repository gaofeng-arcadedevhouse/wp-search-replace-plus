<?php
// 1. Hook into the admin menu action
add_action('admin_menu', 'my_string_search_admin_menu');

// 2. Function to add the submenu page under "Tools"
function my_string_search_admin_menu() {
    add_submenu_page(
        'tools.php',              // Parent Slug (Tools menu)
        'Search String in Content', // Page Title (appears in browser tab)
        'Search String',          // Menu Title (appears in the submenu)
        'edit_pages',         // Capability required to access
        'my_string_search_page',  // Menu Slug (unique identifier for the page)
        'my_string_search_admin_page_html' // Function to display the page content
        // No icon or position needed for submenus
    );
}

// 3. Function to render the admin page HTML (KEEP THIS FUNCTION AS IT WAS BEFORE)
function my_string_search_admin_page_html() {
  

    $search_term = ''; // Initialize search term

    ?>
    <div class="wrap"> <?php // Standard WordPress admin page wrapper ?>
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <form id="search-string-actual-form" method="get" action=""> <?php // Submit to the current admin page ?>
            <?php // Add hidden fields for page slug to keep context on submission ?>
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />

            <p class="search-box" style="margin-bottom: 20px;"> <?php // Use WordPress search-box class ?>
                <label class="screen-reader-text" for="search_term_input">Search String:</label>
                <input type="search" id="search_term_input" name="search_term_input" value="<?php echo isset($_GET['search_term_input']) ? esc_attr($_GET['search_term_input']) : ''; ?>" required />
                <input type="submit" id="search-submit-button" class="button" value="Search Posts" /> <?php // Use WordPress button class ?>
                <span id="loading-indicator" style="display: none; margin-left: 10px; vertical-align: middle;">
                    <span class="spinner is-active" style="float: none;"></span> Searching...
                </span>
            </p>
        </form>

        <?php
        // Check if a search term was submitted via GET request
        if (isset($_GET['search_term_input']) && !empty(trim($_GET['search_term_input']))) {
            // Sanitize the user input
            $search_term = sanitize_text_field(trim($_GET['search_term_input']));
            $search_term_lower = strtolower($search_term);
            // error_log("[Admin Search String] User Search Term: " . $search_term); // Keep logging if needed

            // Display the summary box (similar style)
            echo "<div class='notice notice-info is-dismissible' style='margin-bottom: 20px; padding: 10px 15px;'>"; // Use WP notice style
            echo "<h2 style='margin: 0 0 5px 0; font-size: 1.2em;'>Search Results for: \\\"" . esc_html($search_term) . "\\\"</h2>";
            // Placeholder for count, will be filled later via JS
            echo "<p id='search-result-count-placeholder' style='margin:0;'><i>Searching...</i></p>";
            echo "</div>";

            $args = array(
                'post_type' => 'any',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'ignore_sticky_posts' => 1 // Good practice for admin queries
            );

            $pages = new WP_Query($args);
            $found_items = false;
            $matched_pages_count = 0;
            $results_table_rows = '';

            if ($pages->have_posts()) {
                while ($pages->have_posts()) {
                    $pages->the_post();
                    $post_id = get_the_ID();
                    $post_title = get_the_title() ? get_the_title() : '(no title)'; // Handle empty titles
                    $post_link = get_permalink($post_id);
                    $post_type = get_post_type($post_id);
                    $edit_link = get_edit_post_link($post_id);
                    $post_content = get_post_field('post_content', $post_id);

                    $post_contains_string = false;
                    $match_details = [];

                    // Check post content
                    if ($post_content && stripos($post_content, $search_term) !== false) {
                        $post_contains_string = true;
                        $found_items = true;
                        $match_details[] = 'Content';
                    }

                    // Check ACF fields
                    if (function_exists('get_fields')) {
                        $fields = get_fields($post_id);
                        if ($fields) {
                            foreach ($fields as $field_name => $field_value) {
                                $raw_value = is_scalar($field_value) ? $field_value : json_encode($field_value);
                                if (is_string($raw_value) && stripos($raw_value, $search_term) !== false) {
                                    $post_contains_string = true;
                                    $found_items = true;
                                    $match_details[] = 'ACF: ' . $field_name;
                                    break; // Optimization
                                }
                            }
                        }
                    }

                    if ($post_contains_string) {
                        $matched_pages_count++;
                        $match_location_str = implode(', ', array_unique($match_details));
                        $results_table_rows .= "<tr>";
                        $results_table_rows .= "<td>" . $post_id . "</td>";
                        $results_table_rows .= "<td>" . esc_html($post_type) . "</td>";
                        $results_table_rows .= "<td><a href='" . esc_url($post_link) . "' target='_blank'>" . esc_html($post_title) . "</a></td>";
                        $results_table_rows .= "<td>" . esc_html($match_location_str) . "</td>";
                        $results_table_rows .= "<td class='actions'>";
                        $results_table_rows .= "<a href='" . esc_url($post_link) . "' target='_blank'>View</a>";
                        if ($edit_link) {
                             $results_table_rows .= " | <a href='" . esc_url($edit_link) . "' target='_blank'>Edit</a>";
                        }
                        $results_table_rows .= "</td>";
                        $results_table_rows .= "</tr>";
                    }
                } // end while
            } // end if have_posts

            wp_reset_postdata();

            // Update summary count via JS
            echo "<script>document.getElementById('search-result-count-placeholder').innerHTML = 'Found <strong>{$matched_pages_count}</strong> posts containing the string.';</script>";

            // Display the results table (using WP standard table classes)
            if ($found_items) {
                ?>
                <table class="wp-list-table widefat fixed striped posts"> <?php // Use WP table classes ?>
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-primary">ID</th>
                            <th scope="col" class="manage-column">Type</th>
                            <th scope="col" class="manage-column">Title</th>
                            <th scope="col" class="manage-column">Found In</th>
                            <th scope="col" class="manage-column">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="the-list">
                        <?php echo $results_table_rows; ?>
                    </tbody>
                </table>
                <?php
            } elseif ($pages->post_count > 0) { // Checked posts but found nothing
                 echo "<div class='notice notice-warning inline'><p>Scanned " . $pages->post_count . " posts, but did not find the specified string \"" . esc_html($search_term) . "\".</p></div>";
            } else { // No posts to check
                echo "<div class='notice notice-warning inline'><p>No published content found to scan.</p></div>";
            }

        } else {
            // Message when the page loads initially or if the search box was empty/invalid
            if (isset($_GET['search_term_input'])) {
                echo "<div class='notice notice-error inline'><p>Please enter a valid search string.</p></div>";
            } else {
                echo "<div class='notice notice-info inline'><p>Please enter a string in the box above and click 'Search Posts' to find matching posts.</p></div>";
            }
        }
        ?>
    </div> <?php // End .wrap ?>

    <?php // Add the JavaScript for loading indicator - safely included within the admin page function ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('search-string-actual-form');
            const submitButton = document.getElementById('search-submit-button');
            const loadingIndicator = document.getElementById('loading-indicator');
            const searchInput = document.getElementById('search_term_input');

            if (form && submitButton && loadingIndicator && searchInput) {
                form.addEventListener('submit', function(event) {
                    if (searchInput.value.trim() === '') {
                        event.preventDefault();
                        alert('Please enter a search term.');
                    } else {
                        loadingIndicator.style.display = 'inline-block'; // Use inline-block for spinner
                        submitButton.disabled = true;
                        // Button text change happens automatically with WP spinner usually
                    }
                });
                // Re-enable button if user goes back (browser caching might keep it disabled)
                 submitButton.disabled = false;
                 loadingIndicator.style.display = 'none';
            }
        });
    </script>
    <?php // We don't need extra <style> tags as we are using WP admin styles primarily ?>
    <?php
} // End admin page HTML function

