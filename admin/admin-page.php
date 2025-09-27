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
        'post_types' => [
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
    ];
    
    /**
     * 管理画面を表示
     */
    public static function render() {
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
        
        $options = $this->sanitize_options($_POST);
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
        if (isset($_POST['clear_cache']) && isset($_POST[self::NONCE_FIELD]) 
            && wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            
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
        return isset($_POST['submit']) 
            && isset($_POST[self::NONCE_FIELD])
            && wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION);
    }
    
    /**
     * オプションをサニタイズ
     */
    private function sanitize_options($data) {
        $sanitized = [];
        
        foreach (self::FIELDS as $field => $config) {
            if ($config['type'] === 'checkbox') {
                $sanitized[$field] = isset($data[$field]);
            } elseif ($config['type'] === 'checkbox_group') {
                $sanitized[$field] = isset($data[$field]) 
                    ? array_map('sanitize_text_field', $data[$field]) 
                    : [];
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
        ?>
        <tr>
            <th scope="row">パンくずを表示するページ</th>
            <td>
                <fieldset>
                    <legend class="screen-reader-text">
                        <span>パンくずを表示するページ</span>
                    </legend>
                    
                    <?php foreach ($post_types as $post_type): ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" 
                                   name="post_types[]" 
                                   value="<?php echo esc_attr($post_type->name); ?>"
                                   <?php checked(in_array($post_type->name, $options['post_types'] ?? [])); ?>>
                            <?php echo esc_html($post_type->labels->name); ?>
                        </label>
                    <?php endforeach; ?>
                    
                    <h4 style="margin: 15px 0 5px 0;">特別なページ</h4>
                    <label style="display: block; margin-bottom: 5px;">
                        <input type="checkbox" 
                               name="show_on_front_page" 
                               value="1"
                               <?php checked($options['show_on_front_page'] ?? false); ?>>
                        フロントページ（トップページ）
                    </label>
                    
                    <p class="description" style="margin-top: 10px;">
                        ※ 自動挿入は投稿タイプとフロントページのみ対応しています。<br>
                        アーカイブページではテーマファイルに <code>&lt;?php kspb_display_breadcrumbs(); ?&gt;</code> を追加してください。
                    </p>
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
                
                <div style="margin-top: 10px;">
                    <button type="submit" name="clear_cache" class="button" 
                            onclick="return confirm('キャッシュをクリアしてもよろしいですか？');">
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

// 管理画面を表示
KSPB_Admin_Page::render(); 