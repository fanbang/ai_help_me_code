<?php
/**
 * Plugin Name: Advanced File Download Manager
 * Description: 创建文件下载卡片，支持多平台、短代码管理和可视化编辑
 * Version: 1.0
 * Author: Your Name
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class AdvancedFileDownload {
    
    public function init() {
          add_action('init', array($this, 'init_plugin'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('wp_ajax_save_download_item', array($this, 'save_download_item'));
		add_action('wp_ajax_delete_download_item', array($this, 'delete_download_item'));
		add_action('wp_ajax_get_download_item', array($this, 'get_download_item'));
		add_action('wp_ajax_search_download_items', array($this, 'search_download_items')); // 新增
		add_action('add_meta_boxes', array($this, 'add_download_meta_box'));
		add_action('save_post', array($this, 'save_post_downloads'));
		add_shortcode('file_download', array($this, 'render_download_shortcode'));
		register_activation_hook(__FILE__, array($this, 'create_tables'));
    }
    
    public function init_plugin() {
        $this->create_tables();
    }
    
    public function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'advanced_downloads';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            shortcode_id varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            files longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY shortcode_id (shortcode_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style('afd-frontend-style', plugin_dir_url(__FILE__) . 'css/frontend.css');
    }
    
    public function admin_enqueue_scripts($hook) {
        if ($hook == 'post.php' || $hook == 'post-new.php' || strpos($hook, 'advanced-file-download') !== false) {
            wp_enqueue_style('afd-admin-style', plugin_dir_url(__FILE__) . 'css/admin.css');
            wp_enqueue_script('afd-admin-script', plugin_dir_url(__FILE__) . 'js/admin.js', array('jquery'), '1.0', true);
            wp_localize_script('afd-admin-script', 'afd_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('afd_nonce')
            ));
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            '文件下载管理',
            '文件下载',
            'manage_options',
            'advanced-file-download',
            array($this, 'admin_page'),
            'dashicons-download',
            26
        );
    }
    
    public function admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'advanced_downloads';
        $downloads = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
        ?>
        <div class="wrap">
            <h1>文件下载管理</h1>
            <button id="add-new-download" class="button button-primary">添加新下载</button>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>短代码ID</th>
                        <th>标题</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($downloads as $download): ?>
                    <tr>
                        <td><code>[file_download id="<?php echo esc_attr($download->shortcode_id); ?>"]</code></td>
                        <td><?php echo esc_html($download->title); ?></td>
                        <td><?php echo esc_html($download->created_at); ?></td>
                        <td>
                            <button class="button edit-download" data-id="<?php echo esc_attr($download->shortcode_id); ?>">编辑</button>
                            <button class="button delete-download" data-id="<?php echo esc_attr($download->shortcode_id); ?>">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 编辑模态框 -->
        <div id="download-modal" class="afd-modal" style="display:none;">
            <div class="afd-modal-content">
                <span class="afd-close">&times;</span>
                <h2>编辑文件下载</h2>
                <form id="download-form">
                    <p>
                        <label>短代码ID:</label>
                        <input type="text" id="shortcode-id" name="shortcode_id" required>
                    </p>
                    <p>
                        <label>标题:</label>
                        <input type="text" id="download-title" name="title" required>
                    </p>
                    
                    <div id="files-container">
                        <h3>文件列表</h3>
                        <div id="files-list"></div>
                        <button type="button" id="add-file" class="button">添加文件</button>
                    </div>
                    
                    <p>
                        <button type="submit" class="button button-primary">保存</button>
                        <button type="button" class="button" id="cancel-edit">取消</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    public function add_download_meta_box() {
        add_meta_box(
            'afd_download_box',
            '文件下载管理',
            array($this, 'download_meta_box_callback'),
            array('post', 'page'),
            'normal',
            'high'
        );
    }
    
   public function download_meta_box_callback($post) {
    wp_nonce_field('afd_meta_box', 'afd_meta_box_nonce');
    
    // 检查文章中是否已有下载短代码
    $content = $post->post_content;
    preg_match_all('/\[file_download\s+id=["\']([^"\']+)["\']\]/', $content, $matches);
    $existing_shortcodes = $matches[1];
    
    // 获取所有可用的短代码
    global $wpdb;
    $table_name = $wpdb->prefix . 'advanced_downloads';
    $all_downloads = $wpdb->get_results("SELECT shortcode_id, title FROM $table_name ORDER BY title ASC");
    
    if (count($existing_shortcodes) > 1) {
        echo '<div class="notice notice-warning"><p>警告: 此文章包含多个文件下载短代码: ' . implode(', ', $existing_shortcodes) . '</p></div>';
    }
    ?>
    
    <div id="afd-meta-box">
        <div class="afd-meta-actions">
            <button type="button" id="select-existing-download" class="button">选择现有下载</button>
            <button type="button" id="add-download-meta" class="button button-primary">创建新下载</button>
        </div>
        
        <div id="downloads-in-post">
            <?php if (!empty($existing_shortcodes)): ?>
            <h4>现有下载:</h4>
            <ul>
                <?php foreach($existing_shortcodes as $shortcode_id): 
                    // 获取下载项详情
                    $download_info = $wpdb->get_row($wpdb->prepare(
                        "SELECT title FROM $table_name WHERE shortcode_id = %s",
                        $shortcode_id
                    ));
                ?>
                <li>
                    <div class="existing-download-item">
                        <div class="download-info">
                            <code>[file_download id="<?php echo esc_attr($shortcode_id); ?>"]</code>
                            <?php if ($download_info): ?>
                                <span class="download-title">- <?php echo esc_html($download_info->title); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="download-actions">
                            <button type="button" class="button button-small edit-existing-download" data-id="<?php echo esc_attr($shortcode_id); ?>">编辑</button>
                            <button type="button" class="button button-small remove-from-post" data-id="<?php echo esc_attr($shortcode_id); ?>">从文章移除</button>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        
        <!-- 选择现有短代码模态框 -->
        <div id="select-shortcode-modal" class="afd-modal" style="display:none;">
            <div class="afd-modal-content">
                <span class="afd-close">&times;</span>
                <h2>选择现有下载短代码</h2>
                
                <div class="shortcode-search">
                    <input type="text" id="shortcode-search" placeholder="搜索下载项..." style="width: 100%; padding: 8px; margin-bottom: 15px;">
                </div>
                
                <div id="shortcode-list" style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($all_downloads)): ?>
                        <p>暂无可用的下载项，请先创建一个。</p>
                    <?php else: ?>
                        <?php foreach($all_downloads as $download): ?>
                        <div class="shortcode-item" data-id="<?php echo esc_attr($download->shortcode_id); ?>" data-title="<?php echo esc_attr($download->title); ?>">
                            <div class="shortcode-info">
                                <strong><?php echo esc_html($download->title); ?></strong>
                                <div class="shortcode-id">ID: <?php echo esc_html($download->shortcode_id); ?></div>
                            </div>
                            <button type="button" class="button button-primary select-shortcode-btn">选择</button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 编辑已有下载的模态框 -->
        <div id="edit-existing-modal" class="afd-modal" style="display:none;">
            <div class="afd-modal-content">
                <span class="afd-close">&times;</span>
                <h2>编辑文件下载</h2>
                <form id="edit-existing-form">
                    <p>
                        <label>短代码ID:</label>
                        <input type="text" id="edit-shortcode-id" name="shortcode_id" readonly style="background: #f0f0f0;">
                        <small>ID不可修改</small>
                    </p>
                    <p>
                        <label>标题:</label>
                        <input type="text" id="edit-download-title" name="title" required>
                    </p>
                    
                    <div id="edit-files-container">
                        <h3>文件列表</h3>
                        <div id="edit-files-list"></div>
                        <div class="add-platform-buttons" id="edit-add-platform-buttons">
                            <button type="button" class="add-platform" data-platform="windows">➕ Windows版本</button>
                            <button type="button" class="add-platform" data-platform="mac">➕ Mac版本</button>
                            <button type="button" class="add-platform" data-platform="linux">➕ Linux版本</button>
                            <button type="button" class="add-platform" data-platform="android">➕ Android版本</button>
                            <button type="button" class="add-platform" data-platform="ios">➕ iOS版本</button>
                        </div>
                    </div>
                    
                    <p>
                        <button type="submit"  id="edit-existing-btn" class="button button-primary">保存修改</button>
                        <button type="button" class="button" id="cancel-edit-existing">取消</button>
                    </p>
                </form>
            </div>
        </div>
    </div>
    
    <!-- 快速编辑器（保持原有代码不变） -->
    <div id="quick-download-editor" style="display:none; margin-top:20px; border:1px solid #ddd; padding:20px;">
        <h3>快速创建文件下载</h3>
        <form id="quick-download-form">
            <table class="form-table">
                <tr>
                    <th><label>短代码ID:</label></th>
                    <td>
                        <input type="text" id="quick-shortcode-id" required placeholder="例: download-<?php echo $post->ID; ?>" value="download-<?php echo $post->ID; ?>">
                        <p class="description">建议使用有意义的ID，默认为 download-文章ID</p>
                    </td>
                </tr>
                <tr>
                    <th><label>标题:</label></th>
                    <td><input type="text" id="quick-title" required placeholder="例: Adobe Photoshop 2025"></td>
                </tr>
            </table>
            
            <div id="quick-files-container">
                <h4>文件列表</h4>
                <div id="quick-files-list">
                    <!-- 默认添加一个Windows文件 -->
                    <div class="file-item" data-platform="windows">
                        <h5>📁 Windows 版本</h5>
                        <table class="form-table">
                            <tr>
                                <th>文件名:</th>
                                <td><input type="text" name="filename" placeholder="例: Adobe.Photoshop.2025.exe"></td>
                            </tr>
                            <tr>
                                <th>文件大小:</th>
                                <td><input type="text" name="filesize" placeholder="例: 2.9GB"></td>
                            </tr>
                        </table>
                        <div class="download-links">
                            <h6>下载链接:</h6>
                            <div class="link-item">
                                <select name="link_icon">
                                    <option value="tera">🟠 tera</option>
                                    <option value="tg">⚡ TG</option>
								<option value="gofile">🌩️ Gofile</option>
                                    <option value="kzwr">💾 Kzwr</option>
                                    <option value="mega">🔒 Mega</option>
                                    <option value="onedrive">📁 OneDrive</option>
                                    <option value="googledrive">🌐 Google Drive</option>
                                    <option value="dropbox">📦 Dropbox</option>
                                </select>
                                <input type="url" name="link_url" placeholder="下载链接">
                                <button type="button" class="remove-link">删除</button>
                            </div>
                            <button type="button" class="add-link">添加链接</button>
                        </div>
                        <button type="button" class="remove-file">删除此文件</button>
                    </div>
                </div>
                <div class="add-platform-buttons">
				<button type="button" class="add-platform" data-platform="windows">➕ Windows版本</button>
                    <button type="button" class="add-platform" data-platform="mac">➕ Mac版本</button>
                    <button type="button" class="add-platform" data-platform="linux">➕ Linux版本</button>
                    <button type="button" class="add-platform" data-platform="android">➕ Android版本</button>
                    <button type="button" class="add-platform" data-platform="ios">➕ iOS版本</button>
                </div>
            </div>
            
            <p>
                <button type="submit" class="button button-primary">创建并插入</button>
                <button type="button" id="cancel-quick-edit" class="button">取消</button>
            </p>
        </form>
    </div>
    <?php
}
   // 新增 AJAX 处理方法：搜索短代码
	public function search_download_items() {
		if (!wp_verify_nonce($_POST['nonce'], 'afd_nonce')) {
			wp_die('Security check failed');
		}
		
		global $wpdb;
		$table_name = $wpdb->prefix . 'advanced_downloads';
		
		$search_term = sanitize_text_field($_POST['search_term']);
		
		if (empty($search_term)) {
			$downloads = $wpdb->get_results("SELECT shortcode_id, title FROM $table_name ORDER BY title ASC");
		} else {
			$downloads = $wpdb->get_results($wpdb->prepare(
				"SELECT shortcode_id, title FROM $table_name WHERE title LIKE %s OR shortcode_id LIKE %s ORDER BY title ASC",
				'%' . $wpdb->esc_like($search_term) . '%',
				'%' . $wpdb->esc_like($search_term) . '%'
			));
		}
		
		wp_send_json_success($downloads);
	}
 
    public function save_post_downloads($post_id) {
        if (!isset($_POST['afd_meta_box_nonce']) || !wp_verify_nonce($_POST['afd_meta_box_nonce'], 'afd_meta_box')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // 这里可以添加额外的保存逻辑
    }
    
    public function save_download_item() {
        if (!wp_verify_nonce($_POST['nonce'], 'afd_nonce')) {
            wp_die('Security check failed');
        }
		  global $wpdb;
        $table_name = $wpdb->prefix . 'advanced_downloads';
        
        $title = sanitize_text_field($_POST['title']);
        $shortcode_id = sanitize_text_field($_POST['shortcode_id']);
        $files = $_POST['files'];
		$processed_files = array();
    
		// 处理新的文件结构
		foreach ($files as $file_key => $file_data) {
			  $parts = explode('_', $file_key);
    $platform = $parts[0];
    $file_index = $parts[1] ?? 0; // 支持同一平台多个文件
    
    if (!isset($processed_files[$platform])) {
        $processed_files[$platform] = [];
    }
    
    $processed_files[$platform][$file_index] = [
        'filename' => $file_data['filename'],
        'filesize' => $file_data['filesize'],
        'links' => $file_data['links']
    ];
		} 
		$existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE shortcode_id = %s",
            $shortcode_id
        ));
        
        if ($existing) {
            $result = $wpdb->update(
                $table_name,
                array(
                    'title' => $title,
                    'files' =>$processed_files,
                    'updated_at' => current_time('mysql')
                ),
                array('shortcode_id' => $shortcode_id)
            );
        } else {
            $result = $wpdb->insert(
                $table_name,
                array(
                    'shortcode_id' => $shortcode_id,
                    'title' => $title,
                    'files' => $processed_files
                )
            );
        }
        
     if ($result !== false) {
            wp_send_json_success('保存成功');
        } else {
            wp_send_json_error('保存失败');
        }
		return;
        global $wpdb;
        $table_name = $wpdb->prefix . 'advanced_downloads';
        
        $shortcode_id = sanitize_text_field($_POST['shortcode_id']);
        $title = sanitize_text_field($_POST['title']);
        $files = json_encode($_POST['files']);
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE shortcode_id = %s",
            $shortcode_id
        ));
        
        if ($existing) {
            $result = $wpdb->update(
                $table_name,
                array(
                    'title' => $title,
                    'files' => $files,
                    'updated_at' => current_time('mysql')
                ),
                array('shortcode_id' => $shortcode_id)
            );
        } else {
            $result = $wpdb->insert(
                $table_name,
                array(
                    'shortcode_id' => $shortcode_id,
                    'title' => $title,
                    'files' => $files
                )
            );
        }
        
        if ($result !== false) {
            wp_send_json_success('保存成功');
        } else {
            wp_send_json_error('保存失败');
        }
    }
    
    public function delete_download_item() {
        if (!wp_verify_nonce($_POST['nonce'], 'afd_nonce')) {
            wp_die('Security check failed');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'advanced_downloads';
        
        $shortcode_id = sanitize_text_field($_POST['shortcode_id']);
        
        $result = $wpdb->delete(
            $table_name,
            array('shortcode_id' => $shortcode_id)
        );
        
        if ($result !== false) {
            wp_send_json_success('删除成功');
        } else {
            wp_send_json_error('删除失败');
        }
    }
    
    public function get_download_item() {
        if (!wp_verify_nonce($_POST['nonce'], 'afd_nonce')) {
            wp_die('Security check failed');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'advanced_downloads';
        
        $shortcode_id = sanitize_text_field($_POST['shortcode_id']);
        
        $download = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE shortcode_id = %s",
            $shortcode_id
        ));
        
        if ($download) {
            $download->files = json_decode($download->files, true);
            wp_send_json_success($download);
        } else {
            wp_send_json_error('未找到数据');
        }
    }
    
    public function render_download_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => ''
        ), $atts);
        
        if (empty($atts['id'])) {
            return '<p>错误: 缺少下载ID</p>';
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'advanced_downloads';
        
        $download = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE shortcode_id = %s",
            $atts['id']
        ));
        
        if (!$download) {
            return '<p>错误: 未找到下载项</p>';
        }
        
        $files = json_decode($download->files, true);
        
        ob_start();
        ?>
        <div class="afd-download-container" data-download-id="<?php echo esc_attr($download->shortcode_id); ?>">
            <div class="afd-platform-tabs">
                <?php 
                $first = true;
                foreach ($files as $platform => $file): 
                    $platform_names = array(
                        'windows' => 'Windows',
                        'mac' => 'Mac',
                        'linux' => 'Linux',
                        'android' => 'Android',
                        'ios' => 'iOS'
                    );
                    $platform_icons = array(
                        'windows' => '🪟',
                        'mac' => '🍎',
                        'linux' => '🐧',
                        'android' => '🤖',
                        'ios' => '📱'
                    );
                ?>
                <button class="afd-tab <?php echo $first ? 'active' : ''; ?>" data-platform="<?php echo esc_attr($platform); ?>">
                    <?php echo $platform_icons[$platform] ?? '💻'; ?> <?php echo $platform_names[$platform] ?? ucfirst($platform); ?>
                </button>
                <?php 
                $first = false;
                endforeach; 
                ?>
            </div>
            
            <div class="afd-download-content">
                <?php 
                $first = true;
                foreach ($files as $platform => $file): 
                ?>
                <div class="afd-platform-content <?php echo $first ? 'active' : ''; ?>" data-platform="<?php echo esc_attr($platform); ?>">
                    <div class="afd-file-info">
                        <div class="afd-file-name"><?php echo esc_html($file['filename']); ?></div>
                        <div class="afd-file-size"><?php echo esc_html($file['filesize']); ?></div>
                    </div>
                    
                    <div class="afd-download-links">
                        <?php foreach ($file['links'] as $link): ?>
                        <a href="<?php echo esc_url($link['url']); ?>" class="afd-download-btn afd-btn-<?php echo esc_attr($link['icon']); ?>" target="_blank" rel="nofollow">
                            <?php echo $this->get_platform_icon($link['icon']); ?>
                            <?php echo esc_html($link['name'] ?? ucfirst($link['icon'])); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php 
                $first = false;
                endforeach; 
                ?>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const downloadContainer = document.querySelector('.afd-download-container[data-download-id="<?php echo esc_js($download->shortcode_id); ?>"]');
            if (downloadContainer) {
                const tabs = downloadContainer.querySelectorAll('.afd-tab');
                const contents = downloadContainer.querySelectorAll('.afd-platform-content');
                
                tabs.forEach(tab => {
                    tab.addEventListener('click', function() {
                        const platform = this.dataset.platform;
                        
                        // 移除所有活动状态
                        tabs.forEach(t => t.classList.remove('active'));
                        contents.forEach(c => c.classList.remove('active'));
                        
                        // 添加活动状态
                        this.classList.add('active');
                        downloadContainer.querySelector('.afd-platform-content[data-platform="' + platform + '"]').classList.add('active');
                    });
                });
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    private function get_platform_icon($platform) {
        $icons = array(
            'tera' => '🟠',
            'tg' => '⚡',
			'gofile'=>'🌩️',
            'kzwr' => '💾',
            'mega' => '☁️',
            'onedrive' => '📁',
            'googledrive' => '🌐',
            'dropbox' => '📦'
        );
        
        return $icons[$platform] ?? '🔗';
    }
}

// 初始化插件
$advanced_file_download = new AdvancedFileDownload();
$advanced_file_download->init();

// CSS 样式
function afd_add_inline_styles() {
    ?>
    <style>
	.existing-download-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.download-info {
    flex: 1;
}

.download-title {
    color: #666;
    font-size: 14px;
    margin-left: 10px;
}

.download-actions {
    display: flex;
    gap: 5px;
}

.download-actions .button {
    font-size: 12px;
    height: auto;
    padding: 4px 8px;
}

#edit-existing-modal .afd-modal-content {
    max-width: 900px;
}

#edit-files-container {
    margin: 20px 0;
}

#edit-add-platform-buttons {
    margin-top: 15px;
}

#edit-add-platform-buttons .add-platform {
    display: none;
}

#edit-add-platform-buttons .add-platform.available {
    display: inline-block;
}
	.afd-meta-actions {
    margin-bottom: 15px;
    display: flex;
    gap: 10px;
}

.shortcode-search {
    margin-bottom: 15px;
}

.shortcode-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 8px;
    background: #f9f9f9;
    transition: background-color 0.2s;
}

.shortcode-item:hover {
    background: #f0f0f1;
}

.shortcode-info {
    flex: 1;
}

.shortcode-info strong {
    display: block;
    margin-bottom: 4px;
    color: #23282d;
}

.shortcode-id {
    font-size: 12px;
    color: #666;
    font-family: monospace;
}

.select-shortcode-btn {
    margin-left: 10px;
}

.no-results {
    text-align: center;
    padding: 20px;
    color: #666;
    font-style: italic;
}
    .afd-download-container {
        background: #f8f9fa;
        border-radius: 12px;
        overflow: hidden;
        margin: 20px 0;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .afd-platform-tabs {
        display: flex;
        background: #e9ecef;
        border-bottom: 1px solid #dee2e6;
    }
    
    .afd-tab {
        flex: 1;
        padding: 6px 16px;
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 16px;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .afd-tab:hover {
        background: #dee2e6;
    }
    
    .afd-tab.active {
        background: #0d8ac8;
        color: white;
    }
    
    .afd-download-content {
        padding: 0;
    }
    
    .afd-platform-content {
        display: none;
        padding: 20px;
    }
    
    .afd-platform-content.active {
        display: block;
    }
    
    .afd-file-info {
        margin-bottom: 16px;
		display: flex;
		align-items: baseline;
		gap: 12px;
		flex-wrap: wrap;
    }
    
    .afd-file-name {
       font-size: 16px;
		font-weight: 600;
		color: #2c3e50;
		margin-bottom: 0;   
		flex: 1;
		min-width: 200px;
    }
    .afd-file-size {
		font-size: 14px;
		color: #2997f7;
		background: #e7f3ff;
		display: inline-block;
		padding: 4px 8px;
		border-radius: 12px;
		white-space: nowrap;
		flex-shrink: 0;
	}
    /* 手机端响应式 */
	@media (max-width: 768px) {
		.afd-file-info {
			flex-direction: column;
			gap: 8px;
		}
		
		.afd-file-name {
			min-width: auto;
			word-break: break-word;
			overflow-wrap: break-word;
		}
	}
    .afd-download-links {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
		float: right;
		margin-bottom: 20px;
    }
    
    .afd-download-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 12px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 500;
        font-size: 14px;
        transition: all 0.3s ease;
        border: 2px solid transparent;
    }
    
    .afd-download-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        text-decoration: none;
		color: #fef8f8 !important;
    }
    
    .afd-btn-tera {
        background: linear-gradient(135deg, #ff6b35, #f7931e);
        color: white;
    }
    
    .afd-btn-tg,.afd-btn-kzwr,.afd-btn-gofile {
        background: linear-gradient(135deg, #51a3f1, #0c88c6);
        color: white;
    }
    
    .afd-btn-mega {
        background: linear-gradient(135deg, #ff4757, #ff3838);
        color: white;
    }
    
    .afd-btn-onedrive {
        background: linear-gradient(135deg, #0078d4, #106ebe);
        color: white;
    }
    
    .afd-btn-googledrive {
        background: linear-gradient(135deg, #4285f4, #34a853);
        color: white;
    }
    
    .afd-btn-dropbox {
        background: linear-gradient(135deg, #0061ff, #0051d5);
        color: white;
    }

    /* 管理界面样式 */
    .afd-modal {
        position: fixed;
        z-index: 100000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }
    
    .afd-modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border-radius: 8px;
        width: 80%;
        max-width: 800px;
        max-height: 80vh;
        overflow-y: auto;
    }
    
    .afd-close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    
    .afd-close:hover {
        color: black;
    }
    
    .file-item {
        border: 1px solid #ddd;
        margin: 10px 0;
        padding: 15px;
        border-radius: 5px;
        background: #f9f9f9;
    }
    
    .link-item {
        display: flex;
        gap: 10px;
        margin: 5px 0;
        align-items: center;
    }
    
    .link-item select,
    .link-item input {
        flex: 1;
    }
    
    .add-platform-buttons {
        margin: 15px 0;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .add-platform {
        padding: 8px 12px;
        border-radius: 4px;
        border: 1px solid #ddd;
        background: #f0f0f1;
        cursor: pointer;
    }
    
    .add-platform:hover {
        background: #e0e0e1;
    }
    </style>
    <?php
}
add_action('wp_head', 'afd_add_inline_styles');
add_action('admin_head', 'afd_add_inline_styles');

// JavaScript 代码
function afd_add_admin_scripts() {
    ?>
    <script>
    jQuery(document).ready(function($) {
 // 编辑已有下载
    $(document).off('click', '.edit-existing-download').on('click', '.edit-existing-download', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        console.log('点击编辑按钮, ID:', id);
        loadDownloadForEdit(id);
    });

// 从文章中移除下载短代码
$(document).on('click', '.remove-from-post', function() {
    var shortcodeId = $(this).data('id');
    if (confirm('确定要从文章中移除这个下载短代码吗？（不会删除下载项本身）')) {
        removeShortcodeFromPost(shortcodeId);
    }
});

 // 关闭编辑已有下载模态框
    $(document).off('click', '#edit-existing-modal .afd-close, #cancel-edit-existing').on('click', '#edit-existing-modal .afd-close, #cancel-edit-existing', function() {
        $('#edit-existing-modal').hide();
    });
 // 编辑已有下载表单提交
   // $(document).off('submit', '#edit-existing-form').on('submit', '#edit-existing-btn', function(e) {console.log('编辑表单提交事件触发');
     //   e.preventDefault();
        
      //  saveExistingDownload();
    //    return false;
  //  });
 $(document).off('click', '#edit-existing-modal #edit-existing-btn').on('click', '#edit-existing-modal  #edit-existing-btn', function(e) {
           e.preventDefault();
        
        saveExistingDownload();
        return false;
    });
 
// 修复添加平台功能，允许同平台多个文件
$(document).off('click', '#edit-add-platform-buttons .add-platform').on('click', '#edit-add-platform-buttons .add-platform', function() {
    var platform = $(this).data('platform');
    addEditFileItem(platform);
    // 不再隐藏按钮，允许添加多个同平台文件
    // $(this).removeClass('available').hide();
});
// 编辑模式下删除文件 
$(document).off('click', '#edit-files-list .remove-file').on('click', '#edit-files-list .remove-file', function() {
    var platform = $(this).closest('.file-item').data('platform');
    $(this).closest('.file-item').remove();
    
    // 检查是否还有该平台的其他文件
    var remainingFiles = $('#edit-files-list .file-item[data-platform="' + platform + '"]').length;
    
    // 如果没有该平台的文件了，显示添加按钮
    if (remainingFiles === 0) {
        $('#edit-add-platform-buttons .add-platform[data-platform="' + platform + '"]').addClass('available').show();
    }
});

// 加载下载项进行编辑
function loadDownloadForEdit(shortcodeId) {
    // 显示加载状态
    var $editBtn = $('.edit-existing-download[data-id="' + shortcodeId + '"]');
    var originalText = $editBtn.text();
    $editBtn.text('加载中...').prop('disabled', true);
    
    $.post(afd_ajax.ajax_url, {
        action: 'get_download_item',
        shortcode_id: shortcodeId,
        nonce: afd_ajax.nonce
    })
    .done(function(response) {
        console.log('加载响应:', response);
        
        if (response.success) {
            var data = response.data;
            $('#edit-shortcode-id').val(data.shortcode_id);
            $('#edit-download-title').val(data.title);
            $('#edit-files-list').empty();
            
            // 重置所有平台按钮为可用状态
            $('#edit-add-platform-buttons .add-platform').addClass('available').show();
            
            // 加载现有文件
            if (data.files && Object.keys(data.files).length > 0) {
                $.each(data.files, function(fileKey, file) {
                    // 获取真实的平台名（可能包含后缀）
                    var platform = file.platform || fileKey.split('_')[0];
                    addEditFileItem(platform, file);
                    
                    // 不隐藏平台按钮，允许同平台多个文件
                    // $('#edit-add-platform-buttons .add-platform[data-platform="' + platform + '"]').removeClass('available').hide();
                });
            } else {
                // 如果没有文件，添加一个默认的Windows文件项
                addEditFileItem('windows');
            }
            
            $('#edit-existing-modal').show();
        } else {
            alert('加载失败: ' + (response.data || '未知错误'));
            console.error('加载失败:', response);
        }
    })
    .fail(function(xhr, status, error) {
        console.error('AJAX请求失败:', status, error);
        alert('加载失败: 网络错误 - ' + error);
    })
    .always(function() {
        $editBtn.text(originalText).prop('disabled', false);
    });
} 

// 显示成功消息的辅助函数
function showSuccessMessage(message) {
    var notice = $('<div class="notice notice-success is-dismissible"><p>' + message + '</p></div>');
    $('.wrap h1').after(notice);
    
    setTimeout(function() {
        notice.fadeOut();
    }, 3000);
}
// 保存编辑的下载项
function saveExistingDownload() {
     var files = {};
    var hasValidFile = false;
    
    $('#edit-files-list .file-item').each(function(index) {
        var platform = $(this).data('platform');
        var filename = $(this).find('input[name="filename"]').val().trim();
        var filesize = $(this).find('input[name="filesize"]').val().trim();
        var links = [];
        
        $(this).find('.link-item').each(function() {
            var icon = $(this).find('select[name="link_icon"]').val();
            var url = $(this).find('input[name="link_url"]').val().trim();
            if (url) {
                links.push({
                    icon: icon,
                    url: url,
                    name: $(this).find('select[name="link_icon"] option:selected').text().replace(/^[^\s]+\s/, '')
                });
            }
        });
        
        console.log(`文件 ${index}: 平台=${platform}, 文件名=${filename}, 大小=${filesize}, 链接数=${links.length}`);
        
        if (filename && filesize && links.length > 0) {
            // 创建唯一的文件键，支持同平台多个文件
            var fileKey = platform;
            var counter = 1;
            
            // 如果该平台已存在文件，添加数字后缀
            while (files[fileKey]) {
                counter++;
                fileKey = platform + '_' + counter;
            }
            
            files[fileKey] = {
                platform: platform, // 保留原始平台信息
                filename: filename,
                filesize: filesize,
                links: links
            };
            hasValidFile = true;
        }
    });
    
    if (!hasValidFile) {
        alert('请至少添加一个完整的文件信息（包含文件名、大小和至少一个下载链接）');
        return false;
    }
    
    var shortcodeId = $('#edit-shortcode-id').val();
    var title = $('#edit-download-title').val().trim();
    
    if (!title) {
        alert('请输入标题');
        $('#edit-download-title').focus();
        return false;
    }
    
    console.log('准备发送数据:', { shortcodeId, title, files });
    
    // 显示加载状态
    var $submitBtn = $('#edit-existing-form button[type="submit"]');
    var originalText = $submitBtn.text();
    $submitBtn.text('保存中...').prop('disabled', true);
    
    $.post(afd_ajax.ajax_url, {
        action: 'save_download_item',
        shortcode_id: shortcodeId,
        title: title,
        files: files,
        nonce: afd_ajax.nonce
    })
    .done(function(response) {
        console.log('服务器响应:', response);
        
        if (response.success) {
            $('#edit-existing-modal').hide();
            
            // 更新现有下载列表中的标题显示
            $('.existing-download-item').each(function() {
                if ($(this).find('.edit-existing-download').data('id') === shortcodeId) {
                    $(this).find('.download-title').text('- ' + title);
                }
            });
            
            // 显示成功消息
            showSuccessMessage('下载项修改保存成功！');
            
            // 如果在管理页面，刷新页面
            if (window.location.href.indexOf('page=file-downloads') !== -1) {
                setTimeout(function() {
                    location.reload();
                }, 1000);
            }
        } else {
            alert('保存失败: ' + (response.data || '未知错误'));
            console.error('保存失败:', response);
        }
    })
    .fail(function(xhr, status, error) {
        console.error('AJAX请求失败:', status, error);
        alert('保存失败: 网络错误 - ' + error);
    })
    .always(function() {
        $submitBtn.text(originalText).prop('disabled', false);
    });
}

// 从文章中移除短代码
function removeShortcodeFromPost(shortcodeId) {
    var shortcode = '[file_download id="' + shortcodeId + '"]';
    
    // 检查编辑器类型并移除短代码
    if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/block-editor')) {
        // 块编辑器 - 这里需要更复杂的逻辑来查找和删除块
        alert('块编辑器模式下，请手动删除短代码：' + shortcode);
    } else if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor) {
        // 经典编辑器
        var content = tinyMCE.activeEditor.getContent();
        content = content.replace(shortcode, '');
        tinyMCE.activeEditor.setContent(content);
    } else {
        // 文本编辑器
        var textarea = document.getElementById('content');
        if (textarea) {
            textarea.value = textarea.value.replace(shortcode, '');
        }
    }
    
    // 从现有下载列表中移除显示
    $('.existing-download-item').each(function() {
        if ($(this).find('.edit-existing-download').data('id') === shortcodeId) {
            $(this).closest('li').remove();
        }
    });
    
    // 检查是否还有其他下载项
    if ($('#downloads-in-post ul li').length === 0) {
        $('#downloads-in-post').empty();
    }
}

// 创建链接项HTML的辅助函数
function createLinkItemHTML(link) {
    link = link || {};
    return `
        <div class="link-item" style="margin: 5px 0; padding: 10px; border: 1px solid #ccc; background: white;">
            <select name="link_icon" style="margin-right: 10px;">
                <option value="tera" ${link.icon === 'tera' ? 'selected' : ''}>🟠 Tera</option>
                <option value="tg" ${link.icon === 'tg' ? 'selected' : ''}>⚡ Tg</option>
                <option value="kzwr" ${link.icon === 'kzwr' ? 'selected' : ''}>⚡ Kzwr</option>
                <option value="mega" ${link.icon === 'mega' ? 'selected' : ''}>☁️ Mega</option>
                <option value="onedrive" ${link.icon === 'onedrive' ? 'selected' : ''}>📁 OneDrive</option>
                <option value="googledrive" ${link.icon === 'googledrive' ? 'selected' : ''}>🌐 Google Drive</option>
                <option value="dropbox" ${link.icon === 'dropbox' ? 'selected' : ''}>📦 Dropbox</option>
            </select>
            <input type="url" name="link_url" placeholder="下载链接" value="${link.url || ''}" required style="width: 60%; margin-right: 10px;">
            <button type="button" class="remove-link button">删除</button>
        </div>
    `;
}
// 添加编辑模式的文件项
function addEditFileItem(platform, data) {
    data = data || {};
    var platformNames = {
        'windows': '🪟 Windows',
        'mac': '🍎 Mac',
        'linux': '🐧 Linux',
        'android': '🤖 Android',
        'ios': '📱 iOS'
    };
    
    // 计算该平台已有的文件数量，用于显示序号
    var existingCount = $('#edit-files-list .file-item[data-platform="' + platform + '"]').length;
    var fileNumber = existingCount > 0 ? ` (${existingCount + 1})` : '';
    
    var linksHtml = '';
    if (data.links && data.links.length > 0) {
        data.links.forEach(function(link) {
            linksHtml += createLinkItemHTML(link);
        });
    } else {
        linksHtml = createLinkItemHTML();
    }
    
    var fileHtml = `
        <div class="file-item" data-platform="${platform}" style="border: 1px solid #ddd; margin: 10px 0; padding: 15px; background: #f9f9f9;">
            <h5>${platformNames[platform] || '💻 ' + platform.toUpperCase()}${fileNumber}</h5>
            <table class="form-table">
                <tr>
                    <th>文件名:</th>
                    <td><input type="text" name="filename" value="${data.filename || ''}" required style="width: 100%;"></td>
                </tr>
                <tr>
                    <th>文件大小:</th>
                    <td><input type="text" name="filesize" value="${data.filesize || ''}" required style="width: 100%;"></td>
                </tr>
            </table>
            <div class="download-links">
                <h6>下载链接:</h6>
                ${linksHtml}
                <button type="button" class="add-link button">添加链接</button>
            </div>
            <button type="button" class="remove-file button" style="background: #dc3232; color: white; margin-top: 10px;">删除此文件</button>
        </div>
    `;
    
    $('#edit-files-list').append(fileHtml);
}
// 选择现有下载功能
$('#select-existing-download').click(function() {
    $('#select-shortcode-modal').show();
    loadShortcodes();
});

// 关闭选择短代码模态框
$(document).on('click', '#select-shortcode-modal .afd-close', function() {
    $('#select-shortcode-modal').hide();
});

// 搜索功能
$('#shortcode-search').on('input', function() {
    var searchTerm = $(this).val();
    loadShortcodes(searchTerm);
});
// 选择短代码
$(document).on('click', '.select-shortcode-btn', function() {
    var shortcodeId = $(this).closest('.shortcode-item').data('id');
    var shortcode = '[file_download id="' + shortcodeId + '"]';
    
    // 插入短代码到编辑器
    insertShortcodeToEditor(shortcode);
    
    // 关闭模态框
    $('#select-shortcode-modal').hide();
    
    // 刷新现有下载列表
    updateExistingDownloadsList();
});
// 插入短代码到编辑器
function insertShortcodeToEditor(shortcode) {
    // 检查是否是经典编辑器还是块编辑器
    if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/block-editor')) {
        // 块编辑器 (Gutenberg)
        var blocks = wp.blocks.createBlock('core/shortcode', {
            text: shortcode
        });
        wp.data.dispatch('core/block-editor').insertBlocks(blocks);
    } else if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor) {
        // 经典编辑器 (TinyMCE)
        tinyMCE.activeEditor.insertContent(shortcode);
    } else {
        // 文本编辑器
        var textarea = document.getElementById('content');
        if (textarea) {
            textarea.value += '\n' + shortcode + '\n';
        }
    }
}

// 更新现有下载列表
function updateExistingDownloadsList() {
    // 这里可以添加逻辑来刷新现有下载列表
    // 为了简化，可以提示用户保存文章后刷新
    var notice = $('<div class="notice notice-success is-dismissible"><p>短代码已插入，请保存文章后刷新页面查看更新后的列表。</p></div>');
    $('.wrap h1').after(notice);
    
    setTimeout(function() {
        notice.fadeOut();
    }, 3000);
}

// 加载短代码列表
function loadShortcodes(searchTerm) {
    searchTerm = searchTerm || '';
    
    $.post(afd_ajax.ajax_url, {
        action: 'search_download_items',
        search_term: searchTerm,
        nonce: afd_ajax.nonce
    }, function(response) {
        if (response.success) {
            var html = '';
            if (response.data.length === 0) {
                html = '<div class="no-results">没有找到匹配的下载项</div>';
            } else {
                response.data.forEach(function(item) {
                    html += `
                        <div class="shortcode-item" data-id="${item.shortcode_id}" data-title="${item.title}">
                            <div class="shortcode-info">
                                <strong>${item.title}</strong>
                                <div class="shortcode-id">ID: ${item.shortcode_id}</div>
                            </div>
                            <button type="button" class="button button-primary select-shortcode-btn">选择</button>
                        </div>
                    `;
                });
            }
            $('#shortcode-list').html(html);
        }
    });
}
        // 添加新下载项
        $('#add-new-download').click(function() {
            $('#shortcode-id').val('');
            $('#download-title').val('');
            $('#files-list').empty();
            addFileItem('windows');
            $('#download-modal').show();
        });

        // 编辑下载项
        $('.edit-download').click(function() {
            var id = $(this).data('id');
            $.post(afd_ajax.ajax_url, {
                action: 'get_download_item',
                shortcode_id: id,
                nonce: afd_ajax.nonce
            }, function(response) {
                if (response.success) {
                    var data = response.data;
                    $('#shortcode-id').val(data.shortcode_id);
                    $('#download-title').val(data.title);
                    $('#files-list').empty();
                    
                    $.each(data.files, function(platform, file) {
                        addFileItem(platform, file);
                    });
                    
                    $('#download-modal').show();
                }
            });
        });

        // 删除下载项
        $('.delete-download').click(function() {
            if (confirm('确定要删除这个下载项吗？')) {
                var id = $(this).data('id');
                $.post(afd_ajax.ajax_url, {
                    action: 'delete_download_item',
                    shortcode_id: id,
                    nonce: afd_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('删除失败');
                    }
                });
            }
        });

        // 保存下载项
        $('#download-form').submit(function(e) {
            e.preventDefault();
            
            var files = {};
            $('#files-list .file-item').each(function() {
                var platform = $(this).data('platform');
                var filename = $(this).find('input[name="filename"]').val();
                var filesize = $(this).find('input[name="filesize"]').val();
                var links = [];
                
                $(this).find('.link-item').each(function() {
                    var icon = $(this).find('select[name="link_icon"]').val();
                    var url = $(this).find('input[name="link_url"]').val();
                    if (url) {
                        links.push({
                            icon: icon,
                            url: url,
                            name: $(this).find('select[name="link_icon"] option:selected').text().replace(/^[^\s]+\s/, '')
                        });
                    }
                });
                
                if (filename && filesize && links.length > 0) {
                    files[platform] = {
                        filename: filename,
                        filesize: filesize,
                        links: links
                    };
                }
            });
            
            $.post(afd_ajax.ajax_url, {
                action: 'save_download_item',
                shortcode_id: $('#shortcode-id').val(),
                title: $('#download-title').val(),
                files: files,
                nonce: afd_ajax.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('保存失败');
                }
            });
        });

        // 关闭模态框
        $('.afd-close, #cancel-edit').click(function() {
            $('#download-modal').hide();
        });

        // 添加文件
       // 添加新文件项（支持同一平台多次添加）
$('#add-file').on('click', function() {
    const platform = 'windows'; // 默认为Windows
    const fileIndex = Date.now(); // 唯一索引
    
    const fileHtml = `
    <div class="file-item" data-platform="${platform}_${fileIndex}">
        <h5>📁 ${platformNames[platform]}</h5>
        <table class="form-table">
            <tr>
                <th>文件名:</th>
                <td><input type="text" name="filename" required></td>
            </tr>
            <tr>
                <th>文件大小:</th>
                <td><input type="text" name="filesize" placeholder="例如: 2.5GB"></td>
            </tr>
        </table>
        <div class="download-links">
            <h6>下载链接:</h6>
            <div class="link-item">
                <select name="link_icon">...</select>
                <input type="url" name="link_url" placeholder="https://...">
                <button type="button" class="remove-link">删除</button>
            </div>
            <button type="button" class="add-link">添加链接</button>
        </div>
        <button type="button" class="remove-file">删除此文件</button>
    </div>`;
    
    $('#files-list').append(fileHtml);
});

// 添加平台专属按钮（如Windows）
$('.add-platform[data-platform="windows"]').on('click', function() {
    $('#add-file').click(); // 触发添加文件
});

        // 文章编辑页面的快速编辑器
        $('#add-download-meta').click(function() {
           // 获取当前文章ID
			var postId = $('#post_ID').val() || 'new';
			var defaultId = 'download-' + postId;
			
			// 检查是否已存在相同ID的短代码
			checkShortcodeExists(defaultId, function(exists) {
				if (exists) {
					// 如果存在，添加时间戳
					defaultId = 'download-' + postId + '-' + Date.now();
				}
				$('#quick-shortcode-id').val(defaultId);
			});
			
			$('#quick-download-editor').show();
        });
// 检查短代码是否存在
function checkShortcodeExists(shortcodeId, callback) {
    $.post(afd_ajax.ajax_url, {
        action: 'get_download_item',
        shortcode_id: shortcodeId,
        nonce: afd_ajax.nonce
    }, function(response) {
        callback(response.success);
    });
}
        $('#cancel-quick-edit').click(function() {
            $('#quick-download-editor').hide();
        });

        // 添加平台
        $(document).on('click', '.add-platform', function() {
            var platform = $(this).data('platform');
            addQuickFileItem(platform);
            $(this).hide();
        });

        // 删除文件
        $(document).on('click', '.remove-file', function() {
            var platform = $(this).closest('.file-item').data('platform');
            $(this).closest('.file-item').remove();
            $('.add-platform[data-platform="' + platform + '"]').show();
        });

        // 添加链接
        $(document).on('click', '.add-link', function() {
            var linkHtml = `
                <div class="link-item">
                    <select name="link_icon">
                        <option value="tera">🟠 Tera</option>
                        <option value="tg">⚡ TG</option>
								<option value="gofile">🌩️ Gofile</option>
                        <option value="kzwr">💾 Kzwr</option>
                        <option value="mega">🔒 Mega</option>
                        <option value="onedrive">📁 OneDrive</option>
                        <option value="googledrive">🌐 Google Drive</option>
                        <option value="dropbox">📦 Dropbox</option>
                    </select>
                    <input type="url" name="link_url" placeholder="下载链接" required>
                    <button type="button" class="remove-link button">删除</button>
                </div>
            `;
            $(this).before(linkHtml);
        });

        // 删除链接
        $(document).on('click', '.remove-link', function() {
            $(this).closest('.link-item').remove();
        });

        // 快速创建表单提交
        $('#quick-download-form').submit(function(e) {
            e.preventDefault();
            
            var files = {};
            $('#quick-files-list .file-item').each(function() {
                var platform = $(this).data('platform');
                var filename = $(this).find('input[name="filename"]').val();
                var filesize = $(this).find('input[name="filesize"]').val();
                var links = [];
                
                $(this).find('.link-item').each(function() {
                    var icon = $(this).find('select[name="link_icon"]').val();
                    var url = $(this).find('input[name="link_url"]').val();
                    if (url) {
                        links.push({
                            icon: icon,
                            url: url,
                            name: $(this).find('select[name="link_icon"] option:selected').text().replace(/^[^\s]+\s/, '')
                        });
                    }
                });
                
                if (filename && filesize && links.length > 0) {
                    files[platform] = {
                        filename: filename,
                        filesize: filesize,
                        links: links
                    };
                }
            });
            
            var shortcodeId = $('#quick-shortcode-id').val();
            var title = $('#quick-title').val();
            
            // 保存到数据库
            $.post(afd_ajax.ajax_url, {
                action: 'save_download_item',
                shortcode_id: shortcodeId,
                title: title,
                files: files,
                nonce: afd_ajax.nonce
            }, function(response) {
                if (response.success) {
                    // 插入短代码到编辑器
                    var shortcode = '[file_download id="' + shortcodeId + '"]';
                    
                    // 检查是否是经典编辑器还是块编辑器
                    if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/block-editor')) {
                        // 块编辑器 (Gutenberg)
                        var blocks = wp.blocks.createBlock('core/shortcode', {
                            text: shortcode
                        });
                        wp.data.dispatch('core/block-editor').insertBlocks(blocks);
                    } else if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor) {
                        // 经典编辑器 (TinyMCE)
                        tinyMCE.activeEditor.insertContent(shortcode);
                    } else {
                        // 文本编辑器
                        var textarea = document.getElementById('content');
                        if (textarea) {
                            textarea.value += '\n' + shortcode + '\n';
                        }
                    }
                    
                    // 更新显示的现有下载列表
                    var existingList = $('#downloads-in-post ul');
                    if (existingList.length === 0) {
                        $('#downloads-in-post').html('<h4>现有下载:</h4><ul></ul>');
                        existingList = $('#downloads-in-post ul');
                    }
                    existingList.append('<li><code>[file_download id="' + shortcodeId + '"]</code></li>');
                    
                    // 重置表单并隐藏编辑器
                    $('#quick-download-form')[0].reset();
                    $('#quick-files-list').html(getDefaultFileItem());
                    $('.add-platform').show();
                    $('#quick-download-editor').hide();
                    
                    alert('文件下载创建成功并已插入到文章中！');
                } else {
                    alert('保存失败: ' + (response.data || '未知错误'));
                }
            });
        });

        function addFileItem(platform, data) {
            data = data || {};
            var platformNames = {
                'windows': '🪟 Windows',
                'mac': '🍎 Mac',
                'linux': '🐧 Linux',
                'android': '🤖 Android',
                'ios': '📱 iOS'
            };
            
            var linksHtml = '';
            if (data.links) {
                data.links.forEach(function(link) {
                    linksHtml += `
                        <div class="link-item">
                            <select name="link_icon">
                                <option value="tera" ${link.icon === 'tera' ? 'selected' : ''}>🟠 Tera</option>
                                <option value="tg" ${link.icon === 'tg' ? 'selected' : ''}>⚡ TG</option>
                        <option value="gofile" ${link.icon === 'gofile' ? 'selected' : ''}>🌩️ Gofile</option>
                                <option value="kzwr" ${link.icon === 'kzwr' ? 'selected' : ''}>💾 Kzwr</option>
                                <option value="mega" ${link.icon === 'mega' ? 'selected' : ''}>🔒 Mega</option>☁️
                                <option value="onedrive" ${link.icon === 'onedrive' ? 'selected' : ''}>📁 OneDrive</option>
                                <option value="googledrive" ${link.icon === 'googledrive' ? 'selected' : ''}>🌐 Google Drive</option>
                                <option value="dropbox" ${link.icon === 'dropbox' ? 'selected' : ''}>📦 Dropbox</option>
                            </select>
                            <input type="url" name="link_url" placeholder="下载链接" value="${link.url || ''}" required>
                            <button type="button" class="remove-link button">删除</button>
                        </div>
                    `;
                });
            } else {
                linksHtml = `
                    <div class="link-item">
                        <select name="link_icon">
                            <option value="tera">🟠 Tera</option>
							<option value="tg">⚡ TG</option>
								<option value="gofile">🌩️ Gofile</option>
                            <option value="kzwr">💾 Kzwr</option>
                            <option value="mega">🔒 Mega</option>
                            <option value="onedrive">📁 OneDrive</option>
                            <option value="googledrive">🌐 Google Drive</option>
                            <option value="dropbox">📦 Dropbox</option>
                        </select>
                        <input type="url" name="link_url" placeholder="下载链接" required>
                        <button type="button" class="remove-link button">删除</button>
                    </div>
                `;
            }
            
            var fileHtml = `
                <div class="file-item" data-platform="${platform}">
                    <h5>${platformNames[platform] || '💻 ' + platform.toUpperCase()}</h5>
                    <table class="form-table">
                        <tr>
                            <th>文件名:</th>
                            <td><input type="text" name="filename" value="${data.filename || ''}" required></td>
                        </tr>
                        <tr>
                            <th>文件大小:</th>
                            <td><input type="text" name="filesize" value="${data.filesize || ''}" required></td>
                        </tr>
                    </table>
                    <div class="download-links">
                        <h6>下载链接:</h6>
                        ${linksHtml}
                        <button type="button" class="add-link button">添加链接</button>
                    </div>
                    <button type="button" class="remove-file button">删除此文件</button>
                </div>
            `;
            
            $('#files-list').append(fileHtml);
        }

        function addQuickFileItem(platform) {
            var platformNames = {
                'windows': '🪟 Windows',
                'mac': '🍎 Mac',
                'linux': '🐧 Linux',
                'android': '🤖 Android',
                'ios': '📱 iOS'
            };
            
            var fileHtml = `
                <div class="file-item" data-platform="${platform}">
                    <h5>${platformNames[platform] || '💻 ' + platform.toUpperCase()}</h5>
                    <table class="form-table">
                        <tr>
                            <th>文件名:</th>
                            <td><input type="text" name="filename" placeholder="例: app.${platform === 'android' ? 'apk' : platform === 'ios' ? 'ipa' : 'exe'}" required></td>
                        </tr>
                        <tr>
                            <th>文件大小:</th>
                            <td><input type="text" name="filesize" placeholder="例: 2.9GB" required></td>
                        </tr>
                    </table>
                    <div class="download-links">
                        <h6>下载链接:</h6>
                        <div class="link-item">
                            <select name="link_icon">
                                <option value="tera">🟠 Tera</option>
								<option value="tg">⚡ TG</option>
								<option value="gofile">🌩️ Gofile</option>
                                <option value="kzwr">💾 Kzwr</option>
                                <option value="mega">🔒 Mega</option>
                                <option value="onedrive">📁 OneDrive</option>
                                <option value="googledrive">🌐 Google Drive</option>
                                <option value="dropbox">📦 Dropbox</option>
                            </select>
                            <input type="url" name="link_url" placeholder="下载链接" required>
                            <button type="button" class="remove-link button">删除</button>
                        </div>
                        <button type="button" class="add-link button">添加链接</button>
                    </div>
                    <button type="button" class="remove-file button">删除此文件</button>
                </div>
            `;
            
            $('#quick-files-list').append(fileHtml);
        }

        function getDefaultFileItem() {
            return `
                <div class="file-item" data-platform="windows">
                    <h5>🪟 Windows 版本</h5>
                    <table class="form-table">
                        <tr>
                            <th>文件名:</th>
                            <td><input type="text" name="filename" placeholder="例: Adobe.Photoshop.2025.exe"></td>
                        </tr>
                        <tr>
                            <th>文件大小:</th>
                            <td><input type="text" name="filesize" placeholder="例: 2.9GB"></td>
                        </tr>
                    </table>
                    <div class="download-links">
                        <h6>下载链接:</h6>
                        <div class="link-item">
                            <select name="link_icon">
                                <option value="tera">🟠 Tera</option>
								<option value="tg">⚡ TG</option>
								<option value="gofile">🌩️ Gofile</option>
                                <option value="kzwr">💾 Kzwr</option>
                                <option value="mega">🔒 Mega</option>
                                <option value="onedrive">📁 OneDrive</option>
                                <option value="googledrive">🌐 Google Drive</option>
                                <option value="dropbox">📦 Dropbox</option>
                            </select>
                            <input type="url" name="link_url" placeholder="下载链接">
                            <button type="button" class="remove-link button">删除</button>
                        </div>
                        <button type="button" class="add-link button">添加链接</button>
                    </div>
                    <button type="button" class="remove-file button">删除此文件</button>
                </div>
            `;
        }
    });
    </script>
    <?php
}
add_action('admin_footer', 'afd_add_admin_scripts');

// 检查文章中多个短代码的功能
function afd_check_multiple_shortcodes($content) {
    preg_match_all('/\[file_download\s+id=["\']([^"\']+)["\']\]/', $content, $matches);
    
    if (count($matches[1]) > 1) {
        add_action('admin_notices', function() use ($matches) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>文件下载插件提醒:</strong> 当前文章包含多个下载短代码: ';
            echo implode(', ', array_map(function($id) { return '<code>[file_download id="' . esc_html($id) . '"]</code>'; }, $matches[1]));
            echo '</p></div>';
        });
    }
}

// 在保存文章时检查
add_action('save_post', function($post_id) {
    $post = get_post($post_id);
    if ($post) {
        afd_check_multiple_shortcodes($post->post_content);
    }
});

// 安装时创建示例数据
function afd_create_sample_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'advanced_downloads';
    
    // 检查是否已有数据
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    if ($count == 0) {
        // 创建示例数据
        $sample_files = array(
            'windows' => array(
                'filename' => 'Adobe.Photoshop.2025v26.6.1.7.Lite.Portable.7z',
                'filesize' => '2.9GB',
                'links' => array(
                    array('icon' => 'tg', 'url' => 'https://example.com/download2', 'name' => 'Tg')
                )
            ),
            'mac' => array(
                'filename' => 'Adobe.Photoshop.2025v26.6.1.7.Full.Portable.7z',
                'filesize' => '6.3GB',
                'links' => array(
                    array('icon' => 'tg', 'url' => 'https://example.com/download3', 'name' => 'tg'), 
                )
            )
        );
        
        $wpdb->insert(
            $table_name,
            array(
                'shortcode_id' => 'photoshop-2025',
                'title' => 'Adobe Photoshop 2025',
                'files' => json_encode($sample_files)
            )
        );
    }
}

// 激活插件时创建示例数据
register_activation_hook(__FILE__, 'afd_create_sample_data');

?>
