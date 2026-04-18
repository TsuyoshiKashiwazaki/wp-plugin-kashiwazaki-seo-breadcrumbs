<?php
/**
 * Kashiwazaki SEO Perfect Breadcrumbs 管理画面
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 管理画面クラス
 */
class KSPB_Admin_Page {
    
    /**
     * オプション名
     */
    private const OPTION_NAME = KSPB_OPTION_NAME;
    
    /**
     * nonce アクション名
     */
    private const NONCE_ACTION = 'kspb_save_settings';
    
    /**
     * nonce フィールド名
     */
    private const NONCE_FIELD = 'kspb_nonce';
    
    /**
     * 設定フィールド定義
     */
    private const FIELDS = [
        'position' => [
            'type' => 'select',
            'sanitize' => 'sanitize_text_field',
            'options' => [
                'top' => '上部のみ',
                'bottom' => '下部のみ',
                'both' => '上下両方'
            ]
        ],
        'pattern' => [
            'type' => 'select',
            'sanitize' => 'sanitize_text_field',
            'options' => [
                'classic' => 'クラシック',
                'modern' => 'モダン',
                'rounded' => '角丸'
            ]
        ],
        'font_size' => [
            'type' => 'number',
            'sanitize' => 'absint',
            'min' => 10,
            'max' => 24,
            'default' => 14
        ],
        'home_text' => [
            'type' => 'text',
            'sanitize' => 'sanitize_text_field'
        ],
        'separator' => [
            'type' => 'text',
            'sanitize' => 'sanitize_text_field'
        ],
        'show_home' => [
            'type' => 'checkbox',
            'sanitize' => 'rest_sanitize_boolean'
        ],
        'enable_scraping' => [
            'type' => 'checkbox',
            'sanitize' => 'rest_sanitize_boolean'
        ],
        'show_breadcrumbs_all' => [
            'type' => 'checkbox',
            'sanitize' => 'rest_sanitize_boolean'
        ],
        'post_types' => [
            'type' => 'checkbox_group',
            'sanitize' => 'array_map'
        ],
        'post_type_archives' => [
            'type' => 'checkbox_group',
            'sanitize' => 'array_map'
        ],
        'show_on_front_page' => [
            'type' => 'checkbox',
            'sanitize' => 'rest_sanitize_boolean'
        ],
        'show_creator_credit' => [
            'type' => 'checkbox',
            'sanitize' => 'rest_sanitize_boolean'
        ],
        'show_on_category' => [
            'type' => 'checkbox',
            'sanitize' => 'rest_sanitize_boolean'
        ],
        'show_on_tag' => [
            'type' => 'checkbox',
            'sanitize' => 'rest_sanitize_boolean'
        ],
        'show_on_date' => [
            'type' => 'checkbox',
            'sanitize' => 'rest_sanitize_boolean'
        ],
        'show_on_author' => [
            'type' => 'checkbox',
            'sanitize' => 'rest_sanitize_boolean'
        ],
        'show_on_home_posts' => [
            'type' => 'checkbox',
            'sanitize' => 'rest_sanitize_boolean'
        ],
        'auth_username' => [
            'type' => 'text',
            'sanitize' => 'sanitize_text_field'
        ],
        'auth_password' => [
            'type' => 'text',
            'sanitize' => 'sanitize_text_field'
        ],
    ];
    
    /**
     * 管理画面を表示
     *
     * WordPress の add_menu_page() 側でも manage_options 権限は要求しているが、
     * render() は public static で他経路からも呼び出せるため、書き込み・表示の
     * どちらに入る前でも defense-in-depth として明示的に権限を再確認する。
     */
    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('この操作を行う権限がありません。', 'kashiwazaki-seo-perfect-breadcrumbs'),
                '',
                ['response' => 403]
            );
        }

        $admin = new self();
        $admin->handle_form_submission();
        $admin->handle_cache_clear();
        $admin->display_page();
    }
    
    /**
     * フォーム送信を処理
     */
    private function handle_form_submission() {
        if (!$this->is_valid_submission()) {
            return;
        }
        
        // WordPress は $_POST に magic quotes 風のスラッシュを追加するため、
        // 保存前に wp_unslash で元に戻してから sanitize へ渡す (WP 規約)。
        $options = $this->sanitize_options(wp_unslash($_POST));

        // Basic 認証 username にコロンが含まれると RFC 7617 で `:` が
        // username:password の区切り文字になり、Authorization ヘッダ生成時に壊れる。
        // 保存は拒否せず該当文字をストリップ + 管理画面に警告を出す。
        if (isset($options['auth_username']) && strpos($options['auth_username'], ':') !== false) {
            $options['auth_username'] = str_replace(':', '', $options['auth_username']);
            add_settings_error(
                'kspb_messages',
                'kspb_username_colon_stripped',
                __('ユーザー名からコロン ":" を除去しました（Basic 認証の形式上、コロンはユーザー名に使えません）。', 'kashiwazaki-seo-perfect-breadcrumbs'),
                'warning'
            );
        }

        update_option(self::OPTION_NAME, $options);
        
        add_settings_error(
            'kspb_messages',
            'kspb_message',
            '設定を保存しました。',
            'updated'
        );
    }
    
    /**
     * キャッシュクリア処理
     */
    private function handle_cache_clear() {
        // nonce 値は wp_unslash で元に戻してから検証 (WP 規約: $_POST は magic quotes 済)
        if (isset($_POST['clear_cache']) && isset($_POST[self::NONCE_FIELD])
            && wp_verify_nonce(wp_unslash($_POST[self::NONCE_FIELD]), self::NONCE_ACTION)) {
            
            $scraper = new KSPB_URL_Scraper();
            $scraper->clear_cache();
            
            add_settings_error(
                'kspb_messages',
                'kspb_cache_cleared',
                'キャッシュをクリアしました。',
                'updated'
            );
        }
    }
    
    /**
     * 有効な送信かチェック
     */
    private function is_valid_submission() {
        // nonce 値は wp_unslash で元に戻してから検証 (WP 規約)
        return isset($_POST['submit'])
            && isset($_POST[self::NONCE_FIELD])
            && wp_verify_nonce(wp_unslash($_POST[self::NONCE_FIELD]), self::NONCE_ACTION);
    }
    
    /**
     * オプションをサニタイズ
     */
    private function sanitize_options($data) {
        $sanitized = [];
        $existing = get_option(self::OPTION_NAME, []);

        foreach (self::FIELDS as $field => $config) {
            // auth_password は特別扱い: フォームに送られてきた新しい値だけを暗号化、
            // 空送信なら既存の暗号化済み値を維持、明示削除チェックで消去。
            // パスワードは sanitize_text_field を通さない (< > & や空白など意味のある文字を
            // 破壊し得るため)。$data は handle_form_submission で wp_unslash 済のため
            // ここでの再 unslash は不要 (二重 unslash でバックスラッシュが消える)。
            if ($field === 'auth_password') {
                if (!empty($data['auth_password_clear'])) {
                    $sanitized[$field] = '';
                } elseif (isset($data[$field]) && $data[$field] !== '') {
                    $raw = (string) $data[$field];
                    $encrypted = KSPB_Crypto::encrypt($raw);
                    if ($encrypted === false) {
                        $sanitized[$field] = $existing['auth_password'] ?? '';
                        $sanitized['auth_username'] = $existing['auth_username'] ?? '';
                        add_settings_error('kspb_messages', 'encrypt_failed',
                            __('パスワードの暗号化に失敗しました（sodium拡張が未導入）。認証情報は既存の値を維持します。', 'kashiwazaki-seo-perfect-breadcrumbs'),
                            'error');
                    } else {
                        $sanitized[$field] = $encrypted;
                    }
                } else {
                    $sanitized[$field] = $existing['auth_password'] ?? '';
                }
                continue;
            }

            if ($config['type'] === 'checkbox') {
                $sanitized[$field] = isset($data[$field]);
            } elseif ($config['type'] === 'checkbox_group') {
                $submitted = isset($data[$field]) && is_array($data[$field])
                    ? array_map('sanitize_text_field', $data[$field])
                    : [];
                if ($field === 'post_types' || $field === 'post_type_archives') {
                    $allowed = get_post_types(['public' => true], 'names');
                    $submitted = array_values(array_intersect($submitted, $allowed));
                }
                $sanitized[$field] = $submitted;
            } elseif ($config['type'] === 'number') {
                // number 型は absint だけでなく min/max で clamp (クライアント側の
                // <input min max> は迂回可能なため、サーバー側でも必ず範囲を強制する)。
                $raw = isset($data[$field])
                    ? call_user_func($config['sanitize'], $data[$field])
                    : ($config['default'] ?? 0);
                if (isset($config['min'])) {
                    $raw = max((int) $config['min'], $raw);
                }
                if (isset($config['max'])) {
                    $raw = min((int) $config['max'], $raw);
                }
                $sanitized[$field] = $raw;
            } else {
                $sanitized[$field] = isset($data[$field])
                    ? call_user_func($config['sanitize'], $data[$field])
                    : '';
            }
        }

        return $sanitized;
    }
    
    /**
     * ページを表示
     */
    private function display_page() {
        $options = get_option(self::OPTION_NAME, KSPB_DEFAULT_OPTIONS);
        $post_types = get_post_types(['public' => true], 'objects');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('kspb_messages'); ?>
            
            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                
                <table class="form-table" role="presentation">
                    <?php $this->render_design_pattern_field($options); ?>
                    <?php $this->render_font_size_field($options); ?>
                    <?php $this->render_position_field($options); ?>
                    <?php $this->render_post_types_field($options, $post_types); ?>
                    <?php $this->render_home_settings_fields($options); ?>
                    <?php $this->render_separator_field($options); ?>
                    <?php $this->render_scraping_field($options); ?>
                    <?php $this->render_creator_credit_field($options); ?>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <?php $this->render_usage_section(); ?>
            <?php $this->render_preview_section($options); ?>
        </div>
        <?php
    }
    
    /**
     * デザインパターンフィールドを表示
     */
    private function render_design_pattern_field($options) {
        ?>
        <tr>
            <th scope="row">
                <label for="pattern">デザインパターン</label>
            </th>
            <td>
                <select name="pattern" id="pattern">
                    <?php foreach (self::FIELDS['pattern']['options'] as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" 
                                <?php selected($options['pattern'] ?? '', $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <?php
    }
    
    /**
     * フォントサイズフィールドを表示
     */
    private function render_font_size_field($options) {
        $font_size = $options['font_size'] ?? self::FIELDS['font_size']['default'];
        ?>
        <tr>
            <th scope="row">
                <label for="font_size">フォントサイズ</label>
            </th>
            <td>
                <input type="number" 
                       name="font_size" 
                       id="font_size"
                       value="<?php echo esc_attr($font_size); ?>" 
                       min="<?php echo self::FIELDS['font_size']['min']; ?>" 
                       max="<?php echo self::FIELDS['font_size']['max']; ?>" 
                       step="1"> px
            </td>
        </tr>
        <?php
    }
    
    /**
     * 表示位置フィールドを表示
     */
    private function render_position_field($options) {
        ?>
        <tr>
            <th scope="row">
                <label for="position">表示位置</label>
            </th>
            <td>
                <select name="position" id="position">
                    <?php foreach (self::FIELDS['position']['options'] as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" 
                                <?php selected($options['position'] ?? '', $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <?php
    }
    
    /**
     * 投稿タイプフィールドを表示
     */
    private function render_post_types_field($options, $post_types) {
        $show_all = $options['show_breadcrumbs_all'] ?? true;
        ?>
        <tr>
            <th scope="row">パンくず表示設定</th>
            <td>
                <style>
                    .kspb-section { margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; background: #f9f9f9; border-radius: 4px; }
                    .kspb-section h4 { margin: 0 0 10px 0; padding-bottom: 10px; border-bottom: 2px solid #0073aa; color: #0073aa; font-size: 14px; }
                    .kspb-section .kspb-bulk-actions { margin-bottom: 10px; padding: 8px; background: #fff; border: 1px solid #ddd; border-radius: 3px; }
                    .kspb-section .kspb-bulk-actions button { margin-right: 5px; font-size: 11px; }
                    .kspb-checkbox-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 8px; }
                    .kspb-checkbox-grid label { display: block; margin: 0; padding: 6px; background: #fff; border: 1px solid #ddd; border-radius: 3px; transition: background-color 0.2s; }
                    .kspb-checkbox-grid label:hover { background: #f0f0f0; }
                    .kspb-checkbox-grid input[type="checkbox"] { margin-right: 6px; }
                    .kspb-detail-settings { margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px; }
                    .kspb-main-toggle { padding: 15px; background: #e7f3ff; border: 2px solid #0073aa; border-radius: 4px; margin-bottom: 15px; }
                    .kspb-main-toggle label { font-weight: bold; font-size: 14px; }
                </style>

                <fieldset>
                    <legend class="screen-reader-text">
                        <span>パンくず表示設定</span>
                    </legend>

                    <!-- メイントグル -->
                    <div class="kspb-main-toggle">
                        <label>
                            <input type="checkbox"
                                   name="show_breadcrumbs_all"
                                   id="kspb_show_all"
                                   value="1"
                                   <?php checked($show_all); ?>
                                   data-kspb-toggle="detail-settings">
                            すべてのページでパンくずを表示する
                        </label>
                        <p class="description" style="margin: 8px 0 0 22px;">
                            このチェックを外すと、ページごとに個別に設定できます。
                        </p>
                    </div>

                    <!-- 詳細設定 -->
                    <div id="kspb-detail-settings" class="kspb-detail-settings" style="display: <?php echo $show_all ? 'none' : 'block'; ?>;">
                        <h3 style="margin-top: 0;">表示するページを選択</h3>
                        <p class="description" style="margin-bottom: 15px;">
                            チェックを入れたページでのみパンくずが表示されます。
                        </p>

                        <!-- 個別投稿ページ -->
                        <div class="kspb-section">
                            <h4>個別投稿ページ</h4>
                            <div class="kspb-bulk-actions">
                                <button type="button" class="button button-small" data-kspb-bulk="post_types" data-kspb-checked="1">すべて選択</button>
                                <button type="button" class="button button-small" data-kspb-bulk="post_types" data-kspb-checked="0">すべて解除</button>
                            </div>
                            <div class="kspb-checkbox-grid">
                                <?php foreach ($post_types as $post_type): ?>
                                    <label>
                                        <input type="checkbox"
                                               class="kspb-check-post_types"
                                               name="post_types[]"
                                               value="<?php echo esc_attr($post_type->name); ?>"
                                               <?php checked(in_array($post_type->name, $options['post_types'] ?? [])); ?>>
                                        <?php echo esc_html($post_type->labels->name); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- カスタム投稿タイプアーカイブ -->
                        <?php
                        $custom_post_types = array_filter($post_types, function($pt) {
                            return !in_array($pt->name, ['post', 'page', 'attachment']);
                        });
                        if (!empty($custom_post_types)):
                        ?>
                        <div class="kspb-section">
                            <h4>カスタム投稿タイプアーカイブ</h4>
                            <div class="kspb-bulk-actions">
                                <button type="button" class="button button-small" data-kspb-bulk="post_type_archives" data-kspb-checked="1">すべて選択</button>
                                <button type="button" class="button button-small" data-kspb-bulk="post_type_archives" data-kspb-checked="0">すべて解除</button>
                            </div>
                            <div class="kspb-checkbox-grid">
                                <?php foreach ($custom_post_types as $post_type): ?>
                                    <label>
                                        <input type="checkbox"
                                               class="kspb-check-post_type_archives"
                                               name="post_type_archives[]"
                                               value="<?php echo esc_attr($post_type->name); ?>"
                                               <?php checked(in_array($post_type->name, $options['post_type_archives'] ?? [])); ?>>
                                        <?php echo esc_html($post_type->labels->name); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 標準アーカイブページ -->
                        <div class="kspb-section">
                            <h4>標準アーカイブページ</h4>
                            <div class="kspb-bulk-actions">
                                <button type="button" class="button button-small" data-kspb-bulk="standard_archives" data-kspb-checked="1">すべて選択</button>
                                <button type="button" class="button button-small" data-kspb-bulk="standard_archives" data-kspb-checked="0">すべて解除</button>
                            </div>
                            <div class="kspb-checkbox-grid">
                                <label>
                                    <input type="checkbox"
                                           class="kspb-check-standard_archives"
                                           name="show_on_category"
                                           value="1"
                                           <?php checked($options['show_on_category'] ?? false); ?>>
                                    カテゴリーアーカイブ
                                </label>
                                <label>
                                    <input type="checkbox"
                                           class="kspb-check-standard_archives"
                                           name="show_on_tag"
                                           value="1"
                                           <?php checked($options['show_on_tag'] ?? false); ?>>
                                    タグアーカイブ
                                </label>
                                <label>
                                    <input type="checkbox"
                                           class="kspb-check-standard_archives"
                                           name="show_on_date"
                                           value="1"
                                           <?php checked($options['show_on_date'] ?? false); ?>>
                                    日付アーカイブ
                                </label>
                                <label>
                                    <input type="checkbox"
                                           class="kspb-check-standard_archives"
                                           name="show_on_author"
                                           value="1"
                                           <?php checked($options['show_on_author'] ?? false); ?>>
                                    著者アーカイブ
                                </label>
                                <label>
                                    <input type="checkbox"
                                           class="kspb-check-standard_archives"
                                           name="show_on_home_posts"
                                           value="1"
                                           <?php checked($options['show_on_home_posts'] ?? false); ?>>
                                    投稿一覧ページ
                                </label>
                            </div>
                        </div>

                        <!-- 特別なページ -->
                        <div class="kspb-section">
                            <h4>特別なページ</h4>
                            <div class="kspb-checkbox-grid">
                                <label>
                                    <input type="checkbox"
                                           name="show_on_front_page"
                                           value="1"
                                           <?php checked($options['show_on_front_page'] ?? false); ?>>
                                    フロントページ（トップページ）
                                </label>
                            </div>
                        </div>

                        <p class="description" style="margin-top: 15px;">
                            ※ 自動挿入は投稿タイプとフロントページのみ対応しています。<br>
                            アーカイブページではテーマファイルに <code>&lt;?php kspb_display_breadcrumbs(); ?&gt;</code> を追加してください。
                        </p>
                    </div>

                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('[data-kspb-bulk]').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var group = btn.getAttribute('data-kspb-bulk');
                                var checked = btn.getAttribute('data-kspb-checked') === '1';
                                document.querySelectorAll('.kspb-check-' + group).forEach(function(cb) {
                                    cb.checked = checked;
                                });
                            });
                        });
                        document.querySelectorAll('[data-kspb-toggle="detail-settings"]').forEach(function(input) {
                            input.addEventListener('change', function() {
                                var el = document.getElementById('kspb-detail-settings');
                                if (el) el.style.display = input.checked ? 'none' : 'block';
                            });
                        });
                        document.querySelectorAll('[data-kspb-confirm]').forEach(function(btn) {
                            btn.addEventListener('click', function(e) {
                                if (!window.confirm(btn.getAttribute('data-kspb-confirm'))) {
                                    e.preventDefault();
                                }
                            });
                        });
                    });
                    </script>
                </fieldset>
            </td>
        </tr>
        <?php
    }
    
    /**
     * ホーム設定フィールドを表示
     */
    private function render_home_settings_fields($options) {
        ?>
        <tr>
            <th scope="row">ホームリンクを表示</th>
            <td>
                <label for="show_home">
                    <input type="checkbox" 
                           name="show_home" 
                           id="show_home"
                           <?php checked($options['show_home'] ?? false); ?>>
                    ホームリンクを表示する
                </label>
            </td>
        </tr>
        
        <tr>
            <th scope="row">
                <label for="home_text">ホームテキスト</label>
            </th>
            <td>
                <input type="text" 
                       name="home_text" 
                       id="home_text"
                       value="<?php echo esc_attr($options['home_text'] ?? ''); ?>" 
                       class="regular-text">
            </td>
        </tr>
        <?php
    }
    
    /**
     * 区切り文字フィールドを表示
     */
    private function render_separator_field($options) {
        ?>
        <tr>
            <th scope="row">
                <label for="separator">区切り文字</label>
            </th>
            <td>
                <input type="text" 
                       name="separator" 
                       id="separator"
                       value="<?php echo esc_attr($options['separator'] ?? '>'); ?>" 
                       class="small-text">
            </td>
        </tr>
        <?php
    }
    
    /**
     * スクレイピング設定フィールドを表示
     */
    private function render_scraping_field($options) {
        ?>
        <tr>
            <th scope="row">URLスクレイピング機能</th>
            <td>
                <label for="enable_scraping">
                    <input type="checkbox" 
                           name="enable_scraping" 
                           id="enable_scraping"
                           <?php checked($options['enable_scraping'] ?? false); ?>>
                    URLステータスチェックとタイトル自動取得を有効にする
                </label>
                <p class="description">
                    404エラーを自動検出し、代替URLを使用します。<br>
                    外部ディレクトリのタイトルも自動取得します。<br>
                    ※ キャッシュは24時間保持されます。
                </p>
                
                <fieldset style="margin-top: 15px; padding: 12px; border: 1px solid #ddd; background: #f9f9f9; border-radius: 4px;">
                    <legend style="font-weight: bold; padding: 0 6px;">Basic認証設定</legend>
                    <p class="description" style="margin-top: 0;">
                        スクレイピング先がBasic認証で保護されている場合に設定してください。
                    </p>
                    <table style="border-collapse: separate; border-spacing: 0 6px;">
                        <tr>
                            <td><label for="auth_username">ユーザー名</label></td>
                            <td>
                                <input type="text"
                                       name="auth_username"
                                       id="auth_username"
                                       value="<?php echo esc_attr($options['auth_username'] ?? ''); ?>"
                                       class="regular-text"
                                       autocomplete="off">
                            </td>
                        </tr>
                        <tr>
                            <td><label for="auth_password">パスワード</label></td>
                            <td>
                                <?php $has_password = !empty($options['auth_password']); ?>
                                <input type="password"
                                       name="auth_password"
                                       id="auth_password"
                                       value=""
                                       placeholder="<?php echo $has_password ? esc_attr__('保存済み（変更する場合のみ入力）', 'kashiwazaki-seo-perfect-breadcrumbs') : ''; ?>"
                                       class="regular-text"
                                       autocomplete="new-password">
                                <?php if ($has_password): ?>
                                    <label style="display:inline-block;margin-left:8px;">
                                        <input type="checkbox" name="auth_password_clear" value="1">
                                        <?php esc_html_e('パスワードを削除する', 'kashiwazaki-seo-perfect-breadcrumbs'); ?>
                                    </label>
                                <?php endif; ?>
                                <p class="description" style="margin-top:4px;">
                                    <?php esc_html_e('入力されたパスワードは wp_salt 由来の鍵で暗号化して保存されます。', 'kashiwazaki-seo-perfect-breadcrumbs'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </fieldset>

                <div style="margin-top: 10px;">
                    <button type="submit" name="clear_cache" class="button"
                            data-kspb-confirm="キャッシュをクリアしてもよろしいですか？">
                        キャッシュをクリア
                    </button>
                    <span class="description" style="margin-left: 10px;">
                        URLチェック結果とタイトルのキャッシュをすべて削除します
                    </span>
                </div>
            </td>
        </tr>
        <?php
    }
    
    /**
     * 使用方法セクションを表示
     */
    private function render_usage_section() {
        ?>
        <h2>使用方法</h2>
        
        <h3>テーマファイルで使用</h3>
        <p>テーマファイルに以下のコードを追加してください：</p>
        <pre><code>&lt;?php kspb_display_breadcrumbs(); ?&gt;</code></pre>
        
        <h3>ショートコードで使用</h3>
        <p>投稿や固定ページ内で以下のショートコードを使用できます：</p>
        <pre><code>[kspb_breadcrumbs]</code></pre>
        
        <h3>自動挿入</h3>
        <p>上記の設定で選択した投稿タイプのコンテンツには、自動的にパンくずリストが挿入されます。</p>
        <?php
    }
    
    /**
     * 制作者クレジット設定フィールドを表示
     */
    private function render_creator_credit_field($options) {
        $show_creator = $options['show_creator_credit'] ?? false;
        ?>
        <tr>
            <th scope="row">制作者クレジット</th>
            <td>
                <label for="show_creator_credit">
                    <input type="checkbox"
                           name="show_creator_credit"
                           id="show_creator_credit"
                           <?php checked($show_creator); ?>>
                    制作者情報の構造化データを出力する
                </label>
                <p class="description">
                    柏崎剛様をソフトウェア制作者として構造化マークアップでクレジットします
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * プレビューセクションを表示
     */
    private function render_preview_section($options) {
        ?>
        <h2>プレビュー</h2>
        <div class="kspb-preview-wrapper" style="border: 1px solid #ccc; padding: 20px; margin-top: 20px; background: #fff;">
            <?php
            $instance = KashiwazakiSeoPerfectBreadcrumbs::get_instance();
            $sample_breadcrumbs = $instance->get_sample_breadcrumbs();
            echo $instance->render_breadcrumbs($sample_breadcrumbs, $options);
            ?>
            <p style="margin-top:10px;color:#666;font-size:12px;">
                ※ これはサンプル表示です。実際のページでは階層やリンク先が自動で変わります。
            </p>
        </div>
        <?php
    }
} 