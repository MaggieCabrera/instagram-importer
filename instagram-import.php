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
        // Set chunk size to be much smaller - 2MB
        $this->chunk_size = 2 * 1024 * 1024; // 2MB chunks

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
            'max_upload_size' => min(
                $this->return_bytes(ini_get('upload_max_filesize')),
                $this->chunk_size
            )
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
                    
                    $posts_file = $unzip_path . '/your_instagram_activity/content/posts_1.json';
                    
                    if (file_exists($posts_file)) {
                        $posts = $this->parse_instagram_posts($unzip_path);
                        $stats = $this->import_instagram_posts($posts, $unzip_path);
                        echo '<div class="notice notice-success"><p>Import completed successfully!</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Could not find posts JSON file in the export.</p></div>';
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

        $upload_id = sanitize_key($_POST['upload_id']);
        $chunk_dir = $this->temp_dir . '/' . $upload_id;
        
        // Create chunk directory if it doesn't exist
        if (!file_exists($chunk_dir)) {
            if (!wp_mkdir_p($chunk_dir)) {
                wp_send_json_error('Failed to create chunk directory');
                return;
            }
        }

        // Debug chunk size
        error_log('Instagram Import: Configured chunk size: ' . $this->chunk_size . ' bytes');
        if (isset($_FILES['chunk'])) {
            error_log('Instagram Import: Received chunk size: ' . $_FILES['chunk']['size'] . ' bytes');
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
        $chunk_file = $chunk_dir . '/chunk-' . $index;

        // Save the chunk
        if (!move_uploaded_file($chunk, $chunk_file)) {
            error_log('Instagram Import: Failed to save chunk file: ' . $chunk_file);
            wp_send_json_error('Failed to save chunk file');
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
            $extract_dir = $this->temp_dir . '/' . $upload_id . '-extracted';
            $final_zip = $this->temp_dir . '/' . $upload_id . '.zip';
            
            $status = isset($_POST['status']) ? $_POST['status'] : 'start';
            
            switch ($status) {
                case 'start':
                    // Combine chunks and extract
                    $this->combine_chunks($chunk_dir, $final_zip);
                    
                    if (!file_exists($extract_dir)) {
                        wp_mkdir_p($extract_dir);
                    }

                    WP_Filesystem();
                    $unzip_result = unzip_file($final_zip, $extract_dir);
                    
                    if (is_wp_error($unzip_result)) {
                        throw new Exception('Failed to extract ZIP: ' . $unzip_result->get_error_message());
                    }

                    wp_send_json_success([
                        'status' => 'extracting',
                        'progress' => 33,
                        'message' => 'Files extracted, analyzing content...'
                    ]);
                    break;

                case 'extracting':
                    // Verify JSON exists and can be parsed
                    $posts = $this->parse_instagram_posts($extract_dir);
                    if (empty($posts)) {
                        throw new Exception('No valid posts found in the import file');
                    }

                    wp_send_json_success([
                        'status' => 'importing',
                        'progress' => 66,
                        'message' => 'Starting to import ' . count($posts) . ' posts...'
                    ]);
                    break;

                case 'importing':
                    $posts = $this->parse_instagram_posts($extract_dir);
                    $stats = $this->import_instagram_posts($posts, $extract_dir);
                    
                    // Clean up all temporary files
                    $this->cleanup_temp_files($chunk_dir);
                    $this->cleanup_temp_files($extract_dir);
                    @unlink($final_zip);

                    wp_send_json_success([
                        'status' => 'complete',
                        'progress' => 100,
                        'message' => sprintf(
                            'Import complete! %d %s imported, %d %s skipped.',
                            $stats['imported'],
                            _n('post', 'posts', $stats['imported']),
                            $stats['skipped'],
                            _n('duplicate', 'duplicates', $stats['skipped'])
                        ),
                        'stats' => $stats
                    ]);
                    break;

                default:
                    throw new Exception('Invalid import status');
            }
        } catch (Exception $e) {
            error_log('Instagram Import Error: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
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
        if (!is_resource($zip) && !is_object($zip)) {
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

    private function parse_instagram_posts($dir_path) {
        $posts = array();
        $media_path = $dir_path . '/your_instagram_activity/content/posts_1.json';
        
        if (!file_exists($media_path)) {
            error_log('Instagram Import: Posts JSON file not found at: ' . $media_path);
            return $posts;
        }

        $json_content = file_get_contents($media_path);
        if ($json_content === false) {
            error_log('Instagram Import: Failed to read JSON file: ' . $media_path);
            return $posts;
        }

        $data = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Instagram Import: JSON decode error: ' . json_last_error_msg());
            return $posts;
        }

        foreach ($data as $item) {
            if (!isset($item['media'])) {
                continue;
            }

            $post = array();
            $post['caption'] = isset($item['title']) ? $item['title'] : '';
            $post['timestamp'] = isset($item['creation_timestamp']) 
                ? intval($item['creation_timestamp']) 
                : null;
            
            $post['images'] = array();
            if (isset($item['media']) && is_array($item['media'])) {
                foreach ($item['media'] as $media) {
                    if (isset($media['uri'])) {
                        $post['images'][] = $media['uri'];
                    }
                }
            }
            
            if (!empty($post['images'])) {
                $posts[] = $post;
            }
        }
        
        return $posts;
    }

    private function import_instagram_posts($posts, $export_path) {
        $imported = 0;
        $skipped = 0;
        
        foreach ($posts as $post) {
            // Skip if no timestamp (required for duplicate checking)
            if (empty($post['timestamp'])) {
                error_log('Instagram Import: Skipping post without timestamp');
                $skipped++;
                continue;
            }

            // Check if post already exists
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
            
            // Extract hashtags
            preg_match_all('/#([a-zA-Z0-9_]+)/', $caption, $matches);
            if (!empty($matches[1])) {
                $hashtags = $matches[1];
            }
            
            // Convert hashtags to links
            foreach ($hashtags as $tag) {
                $term = get_term_by('name', $tag, 'instagram_tag');
                if (!$term) {
                    $term_result = wp_insert_term($tag, 'instagram_tag');
                    if (is_wp_error($term_result)) {
                        continue;
                    }
                    $term = get_term($term_result['term_id'], 'instagram_tag');
                }
                
                $tag_link = get_term_link($term, 'instagram_tag');
                if (!is_wp_error($tag_link)) {
                    $caption = str_replace(
                        '#' . $tag,
                        '<a href="' . esc_url($tag_link) . '">#' . esc_html($tag) . '</a>',
                        $caption
                    );
                }
            }
            
            // Clean up title - using WordPress native functions
            $clean_title = !empty($caption) ? wp_trim_words($caption, 10) : 'Instagram Post';
            // Remove emojis but preserve other UTF-8 characters
            $clean_title = wp_encode_emoji($clean_title);
            // Sanitize the title while preserving UTF-8 characters
            $clean_title = sanitize_text_field($clean_title);
            // Fallback if title is empty after cleaning
            $clean_title = $clean_title ?: 'Instagram Post';
            
            // Create post with UTF-8 support
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
                // Store timestamp
                update_post_meta($post_id, '_instagram_timestamp', $post['timestamp']);
                
                // Add hashtags
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
        
        return array(
            'imported' => $imported,
            'skipped' => $skipped
        );
    }
}

new Instagram_Import(); 