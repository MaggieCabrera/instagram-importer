<?php
/**
 * Plugin Name: Instagram Import
 * Description: Import Instagram posts from data export
 * Version: 1.0
 * Author: Maggie
 */

require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

if (!defined('ABSPATH')) {
    exit;
}

class Instagram_Import {
    private $chunk_size; // Will be set in constructor
    private $temp_dir;

    public function __construct() {
        // Set chunk size to be much smaller - 1MB
        $this->chunk_size = 1 * 1024 * 1024;

        add_action('init', array($this, 'register_post_type'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_upload_chunk', array($this, 'handle_chunk_upload'));
        add_action('wp_ajax_process_completed_upload', array($this, 'process_completed_upload'));
        
        // Set up temp directory
        $upload_dir = wp_upload_dir();
        $this->temp_dir = $upload_dir['basedir'] . '/instagram-import-temp';
        
        // Create temp directory with proper permissions
        if (!file_exists($this->temp_dir)) {
            if (!wp_mkdir_p($this->temp_dir)) {
                error_log('Instagram Import: Failed to create temp directory: ' . $this->temp_dir);
            } else {
                // Set directory permissions to 0755
                chmod($this->temp_dir, 0755);
            }
        }

        // Verify temp directory is writable
        if (!is_writable($this->temp_dir)) {
            error_log('Instagram Import: Temp directory is not writable: ' . $this->temp_dir);
        }

        // Create js and css directories if they don't exist
        $js_dir = plugin_dir_path(__FILE__) . 'js';
        $css_dir = plugin_dir_path(__FILE__) . 'css';
        if (!file_exists($js_dir)) {
            wp_mkdir_p($js_dir);
        }
        if (!file_exists($css_dir)) {
            wp_mkdir_p($css_dir);
        }
    }

    private function return_bytes($size_str) {
        $size_str = trim($size_str);
        $unit = strtolower($size_str[strlen($size_str)-1]);
        $val = intval($size_str);
        
        switch($unit) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        
        return $val;
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'instagram_post_page_instagram-import') {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'instagram-import',
            plugins_url('css/style.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'css/style.css')
        );

        // Enqueue JS
        wp_enqueue_script(
            'instagram-import',
            plugins_url('js/upload.js', __FILE__),
            array('jquery'),
            filemtime(plugin_dir_path(__FILE__) . 'js/upload.js'),
            true
        );

        wp_localize_script('instagram-import', 'instagramImport', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('instagram_import_nonce'),
            'chunk_size' => $this->chunk_size,
            'max_upload_size' => $this->return_bytes(ini_get('upload_max_filesize'))
        ));

        wp_add_inline_script('instagram-import', 'console.log("Instagram Import plugin initialized with chunk size: " + instagramImport.chunk_size + " bytes");', 'before');
    }

    public function register_post_type() {
        register_taxonomy('instagram_tag', 'instagram_post', array(
            'labels' => array(
                'name' => __('Instagram Tags'),
                'singular_name' => __('Instagram Tag'),
                'menu_name' => __('Tags'),
                'all_items' => __('All Tags'),
                'edit_item' => __('Edit Tag'),
                'view_item' => __('View Tag'),
                'update_item' => __('Update Tag'),
                'add_new_item' => __('Add New Tag'),
                'new_item_name' => __('New Tag Name'),
                'search_items' => __('Search Tags'),
                'popular_items' => __('Popular Tags'),
                'separate_items_with_commas' => __('Separate tags with commas'),
                'add_or_remove_items' => __('Add or remove tags'),
                'choose_from_most_used' => __('Choose from the most used tags'),
                'not_found' => __('No tags found')
            ),
            'public' => true,
            'hierarchical' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'show_in_quick_edit' => true,
            'show_in_nav_menus' => true,
            'rewrite' => array('slug' => 'instagram-tag')
        ));

        register_post_type('instagram_post', array(
            'labels' => array(
                'name' => __('Instagram Posts'),
                'singular_name' => __('Instagram Post'),
            ),
            'public' => true,
            'has_archive' => true,
            'supports' => array('title', 'editor', 'thumbnail', 'custom-fields'),
            'taxonomies' => array('instagram_tag'),
            'menu_icon' => 'dashicons-instagram',
            'show_in_rest' => true,
        ));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=instagram_post',
            __('Import Instagram Data'),
            __('Import Data'),
            'manage_options',
            'instagram-import',
            array($this, 'render_import_page')
        );
    }

    public function render_import_page() {
        if (isset($_POST['import'])) {
            $upload_dir = wp_upload_dir();
            $temp_dir = $upload_dir['basedir'] . '/instagram-import-temp';
            
            if (!file_exists($temp_dir)) {
                wp_mkdir_p($temp_dir);
            }

            if (!empty($_FILES['instagram_export'])) {
                $file = $_FILES['instagram_export'];
                $upload_path = $temp_dir . '/upload_' . time();
                
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    WP_Filesystem();
                    $unzip_path = $upload_path . '-extracted';
                    unzip_file($upload_path, $unzip_path);
                    
                    $posts_file = $unzip_path . '/your_instagram_activity/content/posts_1.html';
                    
                    if (file_exists($posts_file)) {
                        $posts = parse_instagram_posts($posts_file);
                        import_instagram_posts($posts, $unzip_path);
                        echo '<div class="notice notice-success"><p>Import completed successfully!</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Could not find posts HTML file in the export.</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>Error uploading file.</p></div>';
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>Instagram Import</h1>
            <div id="wp-data-notices"></div>
            <form method="post" enctype="multipart/form-data" id="instagram-upload-form">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="instagram_zip">Instagram Export ZIP</label></th>
                        <td>
                            <input type="file" name="instagram_zip" id="instagram_zip" accept=".zip" required>
                            <p class="description">Upload your Instagram data export ZIP file</p>
                            <div id="upload-progress" style="display: none; margin-top: 10px;">
                                <div class="status-text upload-status"></div>
                                <div class="progress-wrapper">
                                    <div class="progress-bar-fill"></div>
                                    <div class="progress-text">0%</div>
                                </div>
                            </div>
                            <div id="import-progress" style="display: none; margin-top: 10px;">
                                <div class="status-text import-status"></div>
                                <div class="progress-wrapper">
                                    <div class="progress-bar-fill"></div>
                                    <div class="progress-text">0%</div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Import', 'primary', 'import'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_chunk_upload() {
        check_ajax_referer('instagram_import_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Clean up temp directory when starting a new upload (chunk index 0)
        if (isset($_POST['chunk_index']) && intval($_POST['chunk_index']) === 0) {
            error_log('Instagram Import: Starting new upload, cleaning temp directory');
            $this->cleanup_old_temp_files();
        }

        // Debug information
        error_log('Instagram Import: Starting chunk upload');
        error_log('Instagram Import: Temp directory: ' . $this->temp_dir);
        error_log('Instagram Import: Temp directory writable: ' . (is_writable($this->temp_dir) ? 'yes' : 'no'));
        error_log('Instagram Import: $_FILES contents: ' . print_r($_FILES, true));
        error_log('Instagram Import: $_POST contents: ' . print_r($_POST, true));

        // Check if we received the chunk
        if (!isset($_FILES['chunk'])) {
            error_log('Instagram Import: No chunk in $_FILES');
            wp_send_json_error('No chunk received in $_FILES');
            return;
        }

        if (!isset($_FILES['chunk']['tmp_name']) || empty($_FILES['chunk']['tmp_name'])) {
            error_log('Instagram Import: No tmp_name in chunk data');
            wp_send_json_error('No temporary file name received');
            return;
        }

        $chunk = $_FILES['chunk']['tmp_name'];
        
        if (!file_exists($chunk)) {
            error_log('Instagram Import: Chunk temp file does not exist: ' . $chunk);
            error_log('Instagram Import: Upload error code: ' . $_FILES['chunk']['error']);
            wp_send_json_error('Chunk temp file not found. Upload error code: ' . $_FILES['chunk']['error']);
            return;
        }

        $chunk_size = filesize($chunk);
        error_log('Instagram Import: Received chunk size: ' . $chunk_size . ' bytes');
        
        // Verify chunk size
        if ($chunk_size === 0) {
            error_log('Instagram Import: Received empty chunk');
            wp_send_json_error('Received empty chunk');
            return;
        }

        if ($chunk_size > $this->chunk_size) {
            error_log('Instagram Import: Chunk too large: ' . $chunk_size . ' > ' . $this->chunk_size);
            wp_send_json_error('Chunk size ' . $chunk_size . ' exceeds maximum allowed size of ' . $this->chunk_size);
            return;
        }

        $index = intval($_POST['chunk_index']);
        $total_chunks = intval($_POST['total_chunks']);
        $filename = sanitize_file_name($_POST['filename']);
        $upload_id = sanitize_key($_POST['upload_id']);

        error_log('Instagram Import: Processing chunk ' . ($index + 1) . ' of ' . $total_chunks);

        $chunk_dir = $this->temp_dir . '/' . $upload_id;
        if (!file_exists($chunk_dir)) {
            if (!wp_mkdir_p($chunk_dir)) {
                error_log('Instagram Import: Failed to create chunk directory: ' . $chunk_dir);
                wp_send_json_error('Failed to create chunk directory');
                return;
            }
            chmod($chunk_dir, 0755);
        }

        if (!is_writable($chunk_dir)) {
            error_log('Instagram Import: Chunk directory is not writable: ' . $chunk_dir);
            wp_send_json_error('Chunk directory is not writable');
            return;
        }

        $chunk_file = $chunk_dir . '/chunk-' . $index;
        error_log('Instagram Import: Attempting to save chunk to: ' . $chunk_file);

        // Try to copy the file first
        if (!copy($chunk, $chunk_file)) {
            error_log('Instagram Import: Failed to copy chunk file');
            error_log('Instagram Import: PHP error: ' . error_get_last()['message']);
            
            // Try move_uploaded_file as fallback
            if (!move_uploaded_file($chunk, $chunk_file)) {
                $error = error_get_last();
                error_log('Instagram Import: Failed to move uploaded file. PHP Error: ' . print_r($error, true));
                error_log('Instagram Import: Source file exists: ' . (file_exists($chunk) ? 'yes' : 'no'));
                error_log('Instagram Import: Source file readable: ' . (is_readable($chunk) ? 'yes' : 'no'));
                error_log('Instagram Import: Destination directory writable: ' . (is_writable(dirname($chunk_file)) ? 'yes' : 'no'));
                wp_send_json_error('Failed to save chunk. Check error log for details.');
                return;
            }
        }

        // Verify the chunk was saved correctly
        if (!file_exists($chunk_file)) {
            error_log('Instagram Import: Chunk file was not created: ' . $chunk_file);
            wp_send_json_error('Chunk file was not created');
            return;
        }

        $saved_size = filesize($chunk_file);
        if ($saved_size !== $chunk_size) {
            error_log('Instagram Import: Saved chunk size (' . $saved_size . ') does not match original (' . $chunk_size . ')');
            wp_send_json_error('Chunk file size mismatch');
            return;
        }

        error_log('Instagram Import: Successfully saved chunk ' . ($index + 1) . ' of ' . $total_chunks);

        // Check if all chunks are uploaded
        if ($this->are_all_chunks_uploaded($chunk_dir, $total_chunks)) {
            error_log('Instagram Import: All chunks received successfully');
            wp_send_json_success(array(
                'complete' => true,
                'upload_id' => $upload_id
            ));
        } else {
            wp_send_json_success(array(
                'complete' => false,
                'chunk_received' => $index,
                'total_chunks' => $total_chunks
            ));
        }
    }

    private function are_all_chunks_uploaded($chunk_dir, $total_chunks) {
        $uploaded_chunks = glob($chunk_dir . '/chunk-*');
        return count($uploaded_chunks) === $total_chunks;
    }

    public function process_completed_upload() {
        check_ajax_referer('instagram_import_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        try {
            $upload_id = sanitize_key($_POST['upload_id']);
            $chunk_dir = $this->temp_dir . '/' . $upload_id;
            
            // Check if we're in the middle of processing
            $status = isset($_POST['status']) ? $_POST['status'] : 'start';
            
            if ($status === 'start') {
                // Combine chunks
                $final_zip = $this->temp_dir . '/' . $upload_id . '.zip';
                $this->combine_chunks($chunk_dir, $final_zip);

                // Create extraction directory
                $extract_dir = $this->temp_dir . '/' . $upload_id . '-extracted';
                if (!file_exists($extract_dir)) {
                    wp_mkdir_p($extract_dir);
                }

                // Extract ZIP file
                WP_Filesystem();
                $unzip_result = unzip_file($final_zip, $extract_dir);
                
                if (is_wp_error($unzip_result)) {
                    throw new Exception('Failed to extract ZIP: ' . $unzip_result->get_error_message());
                }

                wp_send_json_success(array(
                    'status' => 'extracting',
                    'progress' => 33,
                    'message' => 'Files extracted, analyzing content...'
                ));
            }
            elseif ($status === 'extracting') {
                $extract_dir = $this->temp_dir . '/' . $upload_id . '-extracted';
                
                // Process the contents
                $posts_file = $extract_dir . '/your_instagram_activity/content/posts_1.html';
                if (!file_exists($posts_file)) {
                    throw new Exception('Could not find posts HTML file');
                }

                // Parse posts
                $posts = parse_instagram_posts($posts_file);
                if (empty($posts)) {
                    throw new Exception('No posts found in the HTML file');
                }

                wp_send_json_success(array(
                    'status' => 'importing',
                    'progress' => 66,
                    'message' => 'Starting to import ' . count($posts) . ' posts...'
                ));
            }
            elseif ($status === 'importing') {
                $extract_dir = $this->temp_dir . '/' . $upload_id . '-extracted';
                $posts_file = $extract_dir . '/your_instagram_activity/content/posts_1.html';
                $posts = parse_instagram_posts($posts_file);
                
                // Import posts and get stats
                $stats = import_instagram_posts($posts, $extract_dir);
                
                // Cleanup
                $this->cleanup_temp_files($chunk_dir);
                $this->cleanup_temp_files($extract_dir);
                @unlink($this->temp_dir . '/' . $upload_id . '.zip');

                $message = sprintf(
                    'Import complete! %d %s imported, %d %s skipped.',
                    $stats['imported'],
                    _n('post', 'posts', $stats['imported']),
                    $stats['skipped'],
                    _n('duplicate', 'duplicates', $stats['skipped'])
                );

                wp_send_json_success(array(
                    'status' => 'complete',
                    'progress' => 100,
                    'message' => $message,
                    'stats' => $stats
                ));
            }

        } catch (Exception $e) {
            error_log('Instagram Import Error: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }

    private function analyze_directory_structure($dir, $level = 0, $max_level = 10) {
        if ($level >= $max_level) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            $indent = str_repeat('  ', $level);
            
            if (is_dir($path)) {
                error_log($indent . 'DIR: ' . $item);
                $this->analyze_directory_structure($path, $level + 1, $max_level);
            } else {
                $size = filesize($path);
                $type = $this->get_file_type($path);
                error_log($indent . 'FILE: ' . $item . ' (' . $size . ' bytes) [' . $type . ']');
                
                // If it's a JSON file, let's peek at its structure
                if ($type === 'application/json') {
                    $content = file_get_contents($path);
                    if ($content !== false) {
                        $json = json_decode($content, true);
                        if ($json !== null) {
                            error_log($indent . '  JSON Structure: ' . print_r(array_keys($json), true));
                        }
                    }
                }
            }
        }
    }

    private function get_file_type($path) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $type = finfo_file($finfo, $path);
        finfo_close($finfo);
        return $type;
    }

    private function process_instagram_data($dir) {
        error_log('Instagram Import: Starting to process data in ' . $dir);

        // Look for the posts HTML file
        $posts_file = $dir . '/your_instagram_activity/content/posts_1.html';
        error_log('Instagram Import: Looking for posts file at: ' . $posts_file);
        
        if (!file_exists($posts_file)) {
            throw new Exception('Could not find posts HTML file at: ' . $posts_file);
        }

        // Parse and import posts
        $posts = parse_instagram_posts($posts_file);
        error_log('Instagram Import: Found ' . count($posts) . ' posts to import');
        
        if (empty($posts)) {
            throw new Exception('No posts found in the HTML file');
        }

        import_instagram_posts($posts, $dir);
        error_log('Instagram Import: Successfully imported posts');
    }

    private function combine_chunks($chunk_dir, $final_file) {
        $chunks = glob($chunk_dir . '/chunk-*');
        sort($chunks, SORT_NATURAL); // Use natural sorting for correct order

        error_log('Instagram Import: Combining ' . count($chunks) . ' chunks into ' . $final_file);
        
        // Open file for writing in binary mode
        $fp = fopen($final_file, 'wb');
        if (!$fp) {
            error_log('Instagram Import: Failed to open final file for writing: ' . $final_file);
            throw new Exception('Failed to create final file');
        }

        // Write chunks
        foreach ($chunks as $chunk) {
            error_log('Instagram Import: Processing chunk: ' . $chunk);
            $chunk_data = file_get_contents($chunk);
            if ($chunk_data === false) {
                error_log('Instagram Import: Failed to read chunk: ' . $chunk);
                fclose($fp);
                throw new Exception('Failed to read chunk file');
            }
            if (fwrite($fp, $chunk_data) === false) {
                error_log('Instagram Import: Failed to write chunk to final file');
                fclose($fp);
                throw new Exception('Failed to write to final file');
            }
        }
        fclose($fp);

        // Verify the final file
        if (!file_exists($final_file)) {
            error_log('Instagram Import: Final file was not created: ' . $final_file);
            throw new Exception('Final file was not created');
        }

        $final_size = filesize($final_file);
        error_log('Instagram Import: Final file size: ' . $final_size . ' bytes');

        // Basic ZIP validation
        $zip = zip_open($final_file);
        if (!is_resource($zip)) {
            error_log('Instagram Import: Invalid ZIP file created');
            throw new Exception('Invalid ZIP file created');
        }
        zip_close($zip);
    }

    private function cleanup_temp_files($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        rmdir($dir);
    }

    private function cleanup_old_temp_files() {
        if (!is_dir($this->temp_dir)) {
            return;
        }

        $items = scandir($this->temp_dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $this->temp_dir . '/' . $item;
            
            // If it's older than 1 hour, remove it
            if (filemtime($path) < time() - 3600) {
                error_log('Instagram Import: Removing old temp file/directory: ' . $path);
                if (is_dir($path)) {
                    $this->cleanup_temp_files($path);
                } else {
                    @unlink($path);
                }
            }
        }
    }
}

new Instagram_Import(); 

function parse_instagram_posts($file_path) {
    $html = file_get_contents($file_path);
    $posts = array();
    
    // Load HTML into DOMDocument
    $doc = new DOMDocument();
    @$doc->loadHTML($html);
    
    // Find all post containers
    /** @var DOMNodeList $post_divs */
    $post_divs = $doc->getElementsByTagName('div');
    /** @var DOMElement $div */
    foreach ($post_divs as $div) {
        if ($div->getAttribute('class') === 'pam _3-95 _2ph- _a6-g uiBoxWhite noborder') {
            $post = array();
            
            // Get caption
            /** @var DOMNodeList $caption_div */
            $caption_div = $div->getElementsByTagName('div');
            /** @var DOMElement $cdiv */
            foreach ($caption_div as $cdiv) {
                if ($cdiv->getAttribute('class') === '_3-95 _2pim _a6-h _a6-i') {
                    $post['caption'] = $cdiv->textContent;
                    break;
                }
            }
            
            // Get timestamp
            /** @var DOMNodeList $time_divs */
            $time_divs = $div->getElementsByTagName('div');
            /** @var DOMElement $tdiv */
            foreach ($time_divs as $tdiv) {
                if ($tdiv->getAttribute('class') === '_3-94 _a6-o') {
                    $post['timestamp'] = strtotime($tdiv->textContent);
                    break;
                }
            }
            
            // Get images
            $images = array();
            /** @var DOMNodeList $links */
            $links = $div->getElementsByTagName('a');
            /** @var DOMElement $link */
            foreach ($links as $link) {
                if ($link->getAttribute('target') === '_blank') {
                    $img_path = $link->getAttribute('href');
                    if (strpos($img_path, 'media/posts/') !== false) {
                        $images[] = $img_path;
                    }
                }
            }
            $post['images'] = $images;
            
            if (!empty($post['images'])) {
                $posts[] = $post;
            }
        }
    }
    
    return $posts;
}

function import_instagram_posts($posts, $export_path) {
    $imported = 0;
    $skipped = 0;
    
    foreach ($posts as $post) {
        // Check if post already exists - using only timestamp
        $existing_posts = get_posts(array(
            'post_type' => 'instagram_post',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => '_instagram_timestamp',
                    'value' => $post['timestamp'],
                    'compare' => '='
                )
            )
        ));

        if (!empty($existing_posts)) {
            $skipped++;
            continue;
        }

        // Process caption: convert @mentions to links and extract hashtags
        $caption = !empty($post['caption']) ? $post['caption'] : '';
        $hashtags = array();
        
        // Convert @mentions to links
        $caption = preg_replace_callback(
            '/@([a-zA-Z0-9._]+)/',
            function($matches) {
                $username = $matches[1];
                return '<a href="https://www.instagram.com/' . esc_attr($username) . '" target="_blank" rel="noopener">@' . esc_html($username) . '</a>';
            },
            $caption
        );
        
        // Extract hashtags first
        preg_match_all('/#([a-zA-Z0-9_]+)/', $caption, $matches);
        if (!empty($matches[1])) {
            $hashtags = $matches[1];
        }
        
        // Convert hashtags to links
        foreach ($hashtags as $tag) {
            // Get or create the term
            $term = get_term_by('name', $tag, 'instagram_tag');
            if (!$term) {
                $term_result = wp_insert_term($tag, 'instagram_tag');
                if (is_wp_error($term_result)) {
                    continue; // Skip this tag if term creation fails
                }
                $term = get_term($term_result['term_id'], 'instagram_tag');
            }
            
            // Get the term link
            $tag_link = get_term_link($term, 'instagram_tag');
            if (!is_wp_error($tag_link)) {
                $caption = str_replace(
                    '#' . $tag,
                    '<a href="' . esc_url($tag_link) . '">#' . esc_html($tag) . '</a>',
                    $caption
                );
            }
        }
        
        // Clean up title for URL (remove emojis and limit length)
        $clean_title = !empty($caption) ? wp_trim_words($caption, 10) : 'Instagram Post';
        $clean_title = remove_emoji($clean_title);
        $clean_title = $clean_title ?: 'Instagram Post'; // Fallback if title is empty after cleaning
        
        // Create post
        $post_data = array(
            'post_title' => $clean_title,
            'post_content' => $caption,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
            'post_type' => 'instagram_post',
            'post_date' => date('Y-m-d H:i:s', $post['timestamp'])
        );
        
        $post_id = wp_insert_post($post_data);
        
        if ($post_id && !empty($post['images'])) {
            // Store the timestamp as post meta
            update_post_meta($post_id, '_instagram_timestamp', $post['timestamp']);
            
            // Add hashtags as WordPress tags
            if (!empty($hashtags)) {
                wp_set_object_terms($post_id, $hashtags, 'instagram_tag', true);
            }
            
            foreach ($post['images'] as $image_path) {
                $full_path = $export_path . '/' . $image_path;
                
                if (file_exists($full_path)) {
                    $file_array = array(
                        'name' => basename($image_path),
                        'tmp_name' => $full_path
                    );
                    
                    $attach_id = media_handle_sideload($file_array, $post_id);
                    
                    if (!is_wp_error($attach_id)) {
                        if (!has_post_thumbnail($post_id)) {
                            set_post_thumbnail($post_id, $attach_id);
                        }
                    } else {
                        error_log('Instagram Import: Failed to attach image: ' . $attach_id->get_error_message());
                    }
                } else {
                    error_log('Instagram Import: Image file not found: ' . $full_path);
                }
            }
            $imported++;
        } else {
            error_log('Instagram Import: Failed to create post or no images found. Post ID: ' . $post_id);
        }
    }
    
    error_log('Instagram Import: Imported ' . $imported . ' posts, skipped ' . $skipped . ' duplicates');
    
    return array(
        'imported' => $imported,
        'skipped' => $skipped
    );
}

// Helper function to remove emoji characters
function remove_emoji($text) {
    // Match Emoji and Dingbats
    $clean_text = preg_replace('/[\x{1F600}-\x{1F64F}|\x{1F300}-\x{1F5FF}|\x{1F680}-\x{1F6FF}|\x{2600}-\x{26FF}|\x{2700}-\x{27BF}]/u', '', $text);
    // Match Miscellaneous Symbols and Pictographs
    $clean_text = preg_replace('/[\x{1F900}-\x{1F9FF}|\x{2B00}-\x{2BFF}|\x{1F100}-\x{1F1FF}|\x{1F200}-\x{1F2FF}|\x{2000}-\x{206F}|\x{2300}-\x{23FF}|\x{2B00}-\x{2BFF}|\x{2700}-\x{27BF}|\x{1F000}-\x{1F0FF}|\x{1F100}-\x{1F1FF}|\x{1F200}-\x{1F2FF}|\x{2100}-\x{214F}]/u', '', $clean_text);
    return trim($clean_text);
} 