<?php
/**
 * Plugin Name: Autoload Options Analyzer
 * Description: Анализирует и отображает все опции с автозагрузкой в WordPress
 * Plugin URI: https://github.com/RobertoBennett/autoload-options-analyzer
 * Version: 1.3
 * Author: Robert Bennett
 * Text Domain: Autoload Options Analyzer
 */

// Защита от прямого доступа
if (!defined('ABSPATH')) {
    exit;
}

// Добавляем пункт меню в админке
add_action('admin_menu', 'aoa_add_admin_menu');

// Регистрируем AJAX обработчики
add_action('wp_ajax_aoa_toggle_autoload', 'aoa_ajax_toggle_autoload');

function aoa_add_admin_menu() {
    add_management_page(
        'Анализатор автозагрузки',
        'Анализ Autoload',
        'manage_options',
        'autoload-analyzer',
        'aoa_display_page'
    );
}

// AJAX обработчик для изменения автозагрузки
function aoa_ajax_toggle_autoload() {
    // Проверяем nonce для безопасности
    if (!wp_verify_nonce($_POST['nonce'], 'aoa_toggle_nonce')) {
        wp_die('Ошибка безопасности');
    }
    
    // Проверяем права пользователя
    if (!current_user_can('manage_options')) {
        wp_die('Недостаточно прав');
    }
    
    $option_name = sanitize_text_field($_POST['option_name']);
    $action = sanitize_text_field($_POST['toggle_action']); // 'disable' или 'enable'
    
    if (empty($option_name)) {
        wp_send_json_error('Не указано имя опции');
    }
    
    // Проверяем, что это не системная опция
    if (aoa_is_core_option($option_name)) {
        wp_send_json_error('Нельзя изменять системные опции WordPress');
    }
    
    global $wpdb;
    
    // Определяем новое значение autoload
    $new_autoload = ($action === 'disable') ? 'no' : 'yes';
    
    // Обновляем опцию в базе данных
    $result = $wpdb->update(
        $wpdb->options,
        array('autoload' => $new_autoload),
        array('option_name' => $option_name),
        array('%s'),
        array('%s')
    );
    
    if ($result === false) {
        wp_send_json_error('Ошибка при обновлении базы данных: ' . $wpdb->last_error);
    }
    
    if ($result === 0) {
        wp_send_json_error('Опция не найдена или уже имеет указанное значение');
    }
    
    // Очищаем кеш опций WordPress
    wp_cache_delete('alloptions', 'options');
    
    $message = ($action === 'disable') ? 
        'Автозагрузка отключена для опции: ' . $option_name : 
        'Автозагрузка включена для опции: ' . $option_name;
    
    wp_send_json_success($message);
}

// Функция для определения источника опции
function aoa_detect_option_source($option_name) {
    $sources = array(
        'wp_' => 'WordPress Core',
        '_transient_' => 'Временные данные (Transients)',
        '_site_transient_' => 'Сетевые временные данные',
        'widget_' => 'Виджеты',
        'theme_mods_' => 'Настройки темы',
        'active_plugins' => 'Активные плагины',
        'recently_activated' => 'Недавно активированные плагины',
        'uninstall_plugins' => 'Деинсталляционные хуки плагинов'
    );
    
    // Проверяем известные префиксы
    foreach ($sources as $prefix => $source) {
        if (strpos($option_name, $prefix) === 0) {
            return $source;
        }
    }
    
    // Пытаемся определить по имени плагина
    $active_plugins = get_option('active_plugins');
    
    if (is_array($active_plugins) && !empty($active_plugins)) {
        foreach ($active_plugins as $plugin) {
            $plugin_parts = explode('/', $plugin);
            
            if (!empty($plugin_parts) && isset($plugin_parts[0])) {
                $plugin_slug = $plugin_parts[0];
                
                $variations = array(
                    $plugin_slug,
                    str_replace('-', '_', $plugin_slug),
                    str_replace('_', '-', $plugin_slug)
                );
                
                foreach ($variations as $variant) {
                    if (stripos($option_name, $variant) !== false) {
                        return "Плагин: " . $plugin_slug;
                    }
                }
            }
        }
    }
    
    return 'Неизвестный источник';
}

// Страница отображения результатов
function aoa_display_page() {
    global $wpdb;
    
    if (!current_user_can('manage_options')) {
        wp_die(__('У вас недостаточно прав для доступа к этой странице.'));
    }
    
    // Получаем параметр фильтра
    $show_disabled = isset($_GET['show_disabled']) ? (bool)$_GET['show_disabled'] : false;
    
    ?>
    <div class="wrap">
        <h1>Анализ автозагружаемых опций</h1>
        
        <div class="tablenav top">
            <div class="alignleft actions">
                <a href="<?php echo admin_url('tools.php?page=autoload-analyzer'); ?>" 
                   class="button <?php echo !$show_disabled ? 'button-primary' : ''; ?>">
                    Автозагружаемые опции
                </a>
                <a href="<?php echo admin_url('tools.php?page=autoload-analyzer&show_disabled=1'); ?>" 
                   class="button <?php echo $show_disabled ? 'button-primary' : ''; ?>">
                    Отключенные опции
                </a>
            </div>
        </div>
        
        <?php
        // Получаем опции в зависимости от фильтра
        $autoload_value = $show_disabled ? 'no' : 'yes';
        $autoload_options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, LENGTH(option_value) as size, autoload 
                 FROM {$wpdb->options} 
                 WHERE autoload = %s 
                 ORDER BY size DESC",
                $autoload_value
            )
        );
        
        if ($wpdb->last_error) {
            echo '<div class="notice notice-error"><p>Ошибка базы данных: ' . esc_html($wpdb->last_error) . '</p></div>';
            return;
        }
        
        if (empty($autoload_options)) {
            $message = $show_disabled ? 'Не найдено опций с отключенной автозагрузкой.' : 'Не найдено опций с автозагрузкой.';
            echo '<p>' . $message . '</p>';
            return;
        }
        
        $total_size = 0;
        $grouped_options = array();
        
        foreach ($autoload_options as $option) {
            $source = aoa_detect_option_source($option->option_name);
            if (!isset($grouped_options[$source])) {
                $grouped_options[$source] = array();
            }
            $grouped_options[$source][] = $option;
            $total_size += intval($option->size);
        }
        
        ?>
        
        <div class="notice notice-info">
            <p><strong>Общая информация:</strong></p>
            <p>Всего опций: <?php echo count($autoload_options); ?></p>
            <p>Общий размер данных: <?php echo aoa_format_bytes($total_size); ?></p>
        </div>
        
        <?php if (!empty($grouped_options)): ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 20%;">Источник</th>
                    <th style="width: 50%;">Имя опции</th>
                    <th style="width: 15%;">Размер</th>
                    <th style="width: 15%;">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped_options as $source => $options): ?>
                    <?php foreach ($options as $option): ?>
                        <tr id="option-row-<?php echo esc_attr($option->option_name); ?>">
                            <td><strong><?php echo esc_html($source); ?></strong></td>
                            <td>
                                <code style="font-size: 11px;">
                                    <?php echo esc_html($option->option_name); ?>
                                </code>
                            </td>
                            <td><?php echo aoa_format_bytes(intval($option->size)); ?></td>
                            <td>
                                <?php if (!aoa_is_core_option($option->option_name)): ?>
                                    <?php if ($option->autoload === 'yes'): ?>
                                        <button class="button button-small aoa-toggle-btn" 
                                                data-option="<?php echo esc_attr($option->option_name); ?>"
                                                data-action="disable">
                                            Отключить автозагрузку
                                        </button>
                                    <?php else: ?>
                                        <button class="button button-small button-primary aoa-toggle-btn" 
                                                data-option="<?php echo esc_attr($option->option_name); ?>"
                                                data-action="enable">
                                            Включить автозагрузку
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="description">Системная опция</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- Индикатор загрузки -->
        <div id="aoa-loading" style="display: none;">
            <p>Обработка запроса...</p>
        </div>
    </div>
    
    <style>
    .aoa-toggle-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    #aoa-loading {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        padding: 20px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        z-index: 9999;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        $('.aoa-toggle-btn').on('click', function(e) {
            e.preventDefault();
            
            var button = $(this);
            var optionName = button.data('option');
            var action = button.data('action');
            var row = $('#option-row-' + optionName.replace(/[^a-zA-Z0-9]/g, '\\$&'));
            
            // Подтверждение действия
            var confirmMessage = action === 'disable' ? 
                'Вы уверены, что хотите отключить автозагрузку для опции "' + optionName + '"? Это может повлиять на работу сайта.' :
                'Вы уверены, что хотите включить автозагрузку для опции "' + optionName + '"?';
                
            if (!confirm(confirmMessage)) {
                return;
            }
            
            // Отключаем кнопку и показываем индикатор загрузки
            button.prop('disabled', true);
            $('#aoa-loading').show();
            
            // AJAX запрос
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'aoa_toggle_autoload',
                    option_name: optionName,
                    toggle_action: action,
                    nonce: '<?php echo wp_create_nonce('aoa_toggle_nonce'); ?>'
                },
                success: function(response) {
                    $('#aoa-loading').hide();
                    
                    if (response.success) {
                        // Показываем сообщение об успехе
                        $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                            .insertAfter('.wrap h1')
                            .delay(3000)
                            .fadeOut();
                        
                        // Перезагружаем страницу через 1 секунду для обновления данных
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        // Показываем сообщение об ошибке
                        $('<div class="notice notice-error is-dismissible"><p>Ошибка: ' + response.data + '</p></div>')
                            .insertAfter('.wrap h1');
                        
                        button.prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    $('#aoa-loading').hide();
                    button.prop('disabled', false);
                    
                    $('<div class="notice notice-error is-dismissible"><p>Ошибка AJAX: ' + error + '</p></div>')
                        .insertAfter('.wrap h1');
                }
            });
        });
        
        // Автоматическое скрытие уведомлений
        $(document).on('click', '.notice-is-dismissible .notice-dismiss', function() {
            $(this).parent().fadeOut();
        });
    });
    </script>
    <?php
}

// Функция проверки, является ли опция системной
function aoa_is_core_option($option_name) {
    $core_options = array(
        'siteurl', 'home', 'blogname', 'blogdescription', 'users_can_register',
        'admin_email', 'start_of_week', 'use_balanceTags', 'use_smilies',
        'require_name_email', 'comments_notify', 'posts_per_rss', 'rss_use_excerpt',
        'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port',
        'default_category', 'default_comment_status', 'default_ping_status',
        'default_pingback_flag', 'posts_per_page', 'date_format', 'time_format',
        'links_updated_date_format', 'comment_moderation', 'moderation_notify',
        'permalink_structure', 'rewrite_rules', 'hack_file', 'blog_charset',
        'moderation_keys', 'active_plugins', 'category_base', 'ping_sites',
        'comment_max_links', 'gmt_offset', 'default_email_category', 'recently_edited',
        'template', 'stylesheet', 'comment_registration', 'html_type', 'use_trackback',
        'default_role', 'db_version', 'uploads_use_yearmonth_folders', 'upload_path',
        'blog_public', 'default_link_category', 'show_on_front', 'tag_base',
        'show_avatars', 'avatar_rating', 'upload_url_path', 'thumbnail_size_w',
        'thumbnail_size_h', 'thumbnail_crop', 'medium_size_w', 'medium_size_h',
        'avatar_default', 'large_size_w', 'large_size_h', 'image_default_link_type',
        'image_default_size', 'image_default_align', 'close_comments_for_old_posts',
        'close_comments_days_old', 'thread_comments', 'thread_comments_depth',
        'page_comments', 'comments_per_page', 'default_comments_page', 'comment_order',
        'sticky_posts', 'widget_categories', 'widget_text', 'widget_rss',
        'uninstall_plugins', 'timezone_string', 'page_for_posts', 'page_on_front',
        'default_post_format', 'link_manager_enabled', 'finished_splitting_shared_terms',
        'site_icon', 'medium_large_size_w', 'medium_large_size_h',
        'wp_page_for_privacy_policy', 'show_comments_cookies_opt_in', 'initial_db_version'
    );
    
    return in_array($option_name, $core_options);
}

// Функция форматирования размера
function aoa_format_bytes($bytes, $precision = 2) {
    $bytes = max(0, intval($bytes));
    
    if ($bytes == 0) {
        return '0 B';
    }
    
    $units = array('B', 'KB', 'MB', 'GB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];

}

