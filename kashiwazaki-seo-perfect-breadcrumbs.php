<?php
/**
 * Plugin Name: Kashiwazaki SEO Perfect Breadcrumbs
 * Plugin URI: https://www.tsuyoshikashiwazaki.jp
 * Description: 高度なSEO対策を実現する多機能パンくずリストプラグイン。URLステータスチェック機能により404エラーを自動回避し、常に最適なパンくずリストを生成。構造化データ対応、6種類のデザインパターン、自動挿入機能を搭載。サブディレクトリインストールにも完全対応。
 * Version: 1.0.2
 * Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
 * Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
 * License: GPL v2 or later
 * Text Domain: kashiwazaki-seo-perfect-breadcrumbs
 */

if (!defined('ABSPATH')) {
    exit;
}

// プラグイン定数
define('KSPB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KSPB_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('KSPB_PLUGIN_VERSION', '1.0.2');
define('KSPB_OPTION_NAME', 'kspb_options');
define('KSPB_TEXT_DOMAIN', 'kashiwazaki-seo-perfect-breadcrumbs');

// デフォルト設定
define('KSPB_DEFAULT_OPTIONS', [
    'position' => 'top',
    'show_breadcrumbs_all' => true,
    'post_types' => [],
    'post_type_archives' => [],
    'show_home' => true,
    'home_text' => 'ホーム',
    'separator' => '>',
    'design' => 'simple',
    'pattern' => 'classic',
    'font_size' => 14,
    'show_on_front_page' => false,
    'enable_scraping' => true,
    'show_on_category' => true,
    'show_on_tag' => true,
    'show_on_date' => true,
    'show_on_author' => true,
    'show_on_home_posts' => true
]);

/**
 * メインプラグインクラス
 */
class KashiwazakiSeoPerfectBreadcrumbs {

    /**
     * シングルトンインスタンス
     */
    private static $instance = null;

    /**
     * オプション設定のキャッシュ
     */
    private $options = null;

    /**
     * パンくずビルダー
     */
    private $breadcrumb_builder;

    /**
     * レンダラー
     */
    private $renderer;

    /**
     * シングルトンインスタンスを取得
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        $this->breadcrumb_builder = new KSPB_Breadcrumb_Builder();
        $this->renderer = new KSPB_Renderer();
        $this->init_hooks();
    }

    /**
     * フックの初期化
     */
    private function init_hooks() {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_head', [$this, 'add_structured_data']);
        add_filter('the_content', [$this, 'auto_insert_breadcrumbs']);
        add_shortcode('kspb_breadcrumbs', [$this, 'breadcrumbs_shortcode']);
        register_activation_hook(__FILE__, [$this, 'activate']);

        // プラグイン一覧に設定リンクを追加
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
    }

    /**
     * プラグイン初期化
     */
    public function init() {
        load_plugin_textdomain(KSPB_TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');

        // バージョンチェックとアップグレード処理
        $this->check_version_and_upgrade();
    }

    /**
     * バージョンチェックとアップグレード処理
     */
    private function check_version_and_upgrade() {
        $current_version = get_option('kspb_version', '0');

        // 初回インストールまたはアップグレード時
        if (version_compare($current_version, '1.0.0', '<')) {
            $options = get_option(KSPB_OPTION_NAME, []);

            // スクレイピング機能が未設定の場合は有効にする
            if (!isset($options['enable_scraping'])) {
                $options['enable_scraping'] = true;
                update_option(KSPB_OPTION_NAME, $options);
            }

            // バージョンを更新
            update_option('kspb_version', '1.0.0');
        }
    }

    /**
     * プラグイン有効化時の処理
     */
    public function activate() {
        add_option(KSPB_OPTION_NAME, KSPB_DEFAULT_OPTIONS);
    }

    /**
     * 管理メニューの追加
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Kashiwazaki SEO Perfect Breadcrumbs', KSPB_TEXT_DOMAIN),
            __('Kashiwazaki SEO Perfect Breadcrumbs', KSPB_TEXT_DOMAIN),
            'manage_options',
            'kashiwazaki-seo-perfect-breadcrumbs',
            [$this, 'admin_page'],
            'dashicons-admin-links',
            81
        );
    }

    /**
     * 管理画面の表示
     */
    public function admin_page() {
        require_once KSPB_PLUGIN_PATH . 'admin/admin-page.php';
    }

    /**
     * スタイルの読み込み
     */
    public function enqueue_scripts() {
        // 高優先度でCSSを読み込み
        wp_enqueue_style(
            'kspb-style',
            KSPB_PLUGIN_URL . 'assets/css/breadcrumbs.css',
            [],
            KSPB_PLUGIN_VERSION,
            'all'
        );
        
    }

    /**
     * オプションを取得
     */
    public function get_options() {
        if (null === $this->options) {
            $this->options = wp_parse_args(
                get_option(KSPB_OPTION_NAME, []),
                KSPB_DEFAULT_OPTIONS
            );
        }
        return $this->options;
    }

    /**
     * パンくずリストを生成
     */
    public function generate_breadcrumbs() {
        // スクレイピングボットからのアクセスの場合は何も返さない（無限ループ防止）
        if (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'KSPB Breadcrumbs Bot') !== false) {
            return [];
        }
        
        return $this->breadcrumb_builder->build($this->get_options());
    }

    /**
     * パンくずリストをレンダリング
     */
    public function render_breadcrumbs($breadcrumbs = null, $options = null) {
        if (null === $breadcrumbs) {
            $breadcrumbs = $this->generate_breadcrumbs();
        }
        if (null === $options) {
            $options = $this->get_options();
        }

        return $this->renderer->render($breadcrumbs, $options);
    }

    /**
     * コンテンツへの自動挿入
     */
    public function auto_insert_breadcrumbs($content) {
        $options = $this->get_options();

        if (!$this->should_display_breadcrumbs($options)) {
            return $content;
        }

        $breadcrumbs = $this->render_breadcrumbs();

        return $this->insert_breadcrumbs_to_content($content, $breadcrumbs, $options['position']);
    }

        /**
     * パンくずリストを表示すべきか判定
     */
    private function should_display_breadcrumbs($options) {
        // すべてのページで表示する設定の場合
        if (!empty($options['show_breadcrumbs_all'])) {
            return true;
        }

        // 個別設定モード
        // フロントページの場合
        if (is_front_page()) {
            return !empty($options['show_on_front_page']);
        }

        // 個別投稿ページの場合
        if (is_singular()) {
            $post_type = get_post_type();
            return in_array($post_type, $options['post_types'], true);
        }

        // アーカイブページの場合
        if (is_archive() || is_home()) {
            // カスタム投稿タイプアーカイブの場合は専用設定をチェック
            if (is_post_type_archive()) {
                $post_type = get_post_type_object(get_query_var('post_type'));
                if ($post_type) {
                    return in_array($post_type->name, $options['post_type_archives'] ?? [], true);
                }
            }

            // イレギュラーなカスタムアーカイブページ（poll/datasetsなど）
            // カスタムクエリ変数で投稿タイプを特定
            if (get_query_var('kashiwazaki_poll_datasets_page')) {
                return in_array('poll', $options['post_type_archives'] ?? [], true);
            }

            // カテゴリーアーカイブ
            if (is_category()) {
                return !empty($options['show_on_category']);
            }

            // タグアーカイブ
            if (is_tag()) {
                return !empty($options['show_on_tag']);
            }

            // 日付アーカイブ
            if (is_date()) {
                return !empty($options['show_on_date']);
            }

            // 著者アーカイブ
            if (is_author()) {
                return !empty($options['show_on_author']);
            }

            // 投稿一覧ページ（ブログホーム）
            if (is_home()) {
                return !empty($options['show_on_home_posts']);
            }

            // その他のアーカイブ（デフォルトfalse）
            return false;
        }

        return false;
    }

    /**
     * コンテンツにパンくずリストを挿入
     */
    private function insert_breadcrumbs_to_content($content, $breadcrumbs, $position) {
        switch ($position) {
            case 'top':
                return $breadcrumbs . $content;
            case 'bottom':
                return $content . $breadcrumbs;
            case 'both':
                return $breadcrumbs . $content . $breadcrumbs;
            default:
                return $content;
        }
    }

    /**
     * ショートコード処理
     */
    public function breadcrumbs_shortcode($atts) {
        $options = $this->get_options();

        // 表示条件をチェック
        if (!$this->should_display_breadcrumbs($options)) {
            return '';
        }

        return $this->render_breadcrumbs();
    }

    /**
     * 構造化データの追加
     */
    public function add_structured_data() {
        // オプションを取得
        $options = $this->get_options();

        // 表示条件をチェック
        if (!$this->should_display_breadcrumbs($options)) {
            return;
        }

        // ソフトウェア制作者クレジットの構造化データを出力（オプションが有効な場合のみ、1回のみ）
        static $creator_credit_added = false;
        if (!$creator_credit_added && !is_admin() && !empty($options['show_creator_credit'])) {
            $creator_structured_data = $this->build_creator_structured_data();
            echo "\n" . '<!-- Kashiwazaki SEO Perfect Breadcrumbs: Creator Credit Schema -->' . "\n";
            echo '<script type="application/ld+json">' . wp_json_encode($creator_structured_data) . '</script>' . "\n";
            echo '<!-- /Kashiwazaki SEO Perfect Breadcrumbs: Creator Credit Schema -->' . "\n\n";
            $creator_credit_added = true;
        }

        // パンくずリストの構造化データ
        $breadcrumbs = $this->generate_breadcrumbs();
        if (empty($breadcrumbs)) {
            return;
        }

        $structured_data = $this->build_structured_data($breadcrumbs);
        echo "\n" . '<!-- Kashiwazaki SEO Perfect Breadcrumbs: BreadcrumbList Schema -->' . "\n";
        echo '<script type="application/ld+json">' . wp_json_encode($structured_data) . '</script>' . "\n";
        echo '<!-- /Kashiwazaki SEO Perfect Breadcrumbs: BreadcrumbList Schema -->' . "\n\n";
    }

    /**
     * 構造化データの構築
     */
    private function build_structured_data($breadcrumbs) {
        $items = array_map(function($breadcrumb) {
            return [
                '@type' => 'ListItem',
                'position' => $breadcrumb['position'],
                'name' => $breadcrumb['title'],
                'item' => $breadcrumb['url']
            ];
        }, $breadcrumbs);

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items
        ];
    }

    /**
     * ソフトウェア制作者の構造化データを構築
     */
    private function build_creator_structured_data() {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'Kashiwazaki SEO Perfect Breadcrumbs',
            'description' => '高度なSEO対策を実現する多機能パンくずリストプラグイン',
            'applicationCategory' => 'Plugin',
            'applicationSubCategory' => 'WordPress Plugin',
            'operatingSystem' => 'WordPress',
            'softwareVersion' => KSPB_PLUGIN_VERSION,
            'creator' => [
                '@type' => 'Person',
                'name' => '柏崎剛',
                'alternateName' => 'Tsuyoshi Kashiwazaki',
                'url' => 'https://www.tsuyoshikashiwazaki.jp/profile/',
                'sameAs' => [
                    'https://www.tsuyoshikashiwazaki.jp'
                ]
            ],
            'author' => [
                '@type' => 'Person',
                'name' => '柏崎剛',
                'alternateName' => 'Tsuyoshi Kashiwazaki',
                'url' => 'https://www.tsuyoshikashiwazaki.jp/profile/'
            ],
            'copyrightHolder' => [
                '@type' => 'Person',
                'name' => '柏崎剛',
                'alternateName' => 'Tsuyoshi Kashiwazaki'
            ],
            'copyrightYear' => '2024',
            'license' => 'https://www.gnu.org/licenses/gpl-2.0.html',
            'url' => 'https://www.tsuyoshikashiwazaki.jp',
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'JPY'
            ],
            'softwareHelp' => 'https://www.tsuyoshikashiwazaki.jp'
        ];
    }

    /**
     * サンプルパンくずリストを取得
     */
    public function get_sample_breadcrumbs() {
        return [
            ['title' => 'ホーム', 'url' => '#', 'position' => 1],
            ['title' => '親ページ', 'url' => '#', 'position' => 2],
            ['title' => '子ページ', 'url' => '#', 'position' => 3],
            ['title' => '孫ページ', 'url' => '#', 'position' => 4],
        ];
    }

    /**
     * プラグイン一覧に設定リンクを追加
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=kashiwazaki-seo-perfect-breadcrumbs">' . __('設定', KSPB_TEXT_DOMAIN) . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

/**
 * パンくずリストビルダークラス
 */
class KSPB_Breadcrumb_Builder {

    /**
     * 最大階層深度（無限ループ防止）
     */
    private const MAX_DEPTH = 10;

        /**
     * URLの301リダイレクトをチェックして適切なパンくず項目を作成（共通関数）
     */
    private function create_breadcrumb_item($title, $url, $position) {
        $options = $this->get_options();
        $enable_scraping = !empty($options['enable_scraping']);

        if ($enable_scraping) {
            $scraper = new KSPB_URL_Scraper();
            $status_data = $scraper->check_url_status($url);
            $status = is_array($status_data) ? $status_data['status'] : $status_data;

            // リダイレクトの場合を最優先で処理
            if (($status === 301 || $status === 302) && !empty($status_data['redirect_to'])) {
                $url = $status_data['redirect_to'];
            }

            return [
                'title' => $title,
                'url' => $url,
                'position' => $position,
                'status' => $status
            ];
        } else {
            return [
                'title' => $title,
                'url' => $url,
                'position' => $position
            ];
        }
    }

    /**
     * パンくずリストを構築
     */
    public function build($options) {

        // WordPressがサブディレクトリにインストールされているかチェック
        $wp_path = parse_url(home_url(), PHP_URL_PATH) ?? '';
        $has_subdirectory = !empty($wp_path) && $wp_path !== '/';

        // ホームページの場合でも、サブディレクトリインストールの場合はURL構造を解析
        if ((is_front_page() || is_home()) && !$has_subdirectory) {
            return $this->build_home_breadcrumbs($options);
        }

        $breadcrumbs = [];

        // ホームを追加
        if (!empty($options['show_home'])) {
            $breadcrumbs[] = $this->create_home_item($options['home_text']);
        }

        // すべてのページでURLパスから階層を構築
        $breadcrumbs = $this->build_from_url_path($breadcrumbs);

        // 最大深度を超えた場合は切り詰める
        if (count($breadcrumbs) > self::MAX_DEPTH) {
            $breadcrumbs = array_slice($breadcrumbs, 0, self::MAX_DEPTH);
        }

        return $breadcrumbs;
    }

    /**
     * ホームページのパンくずリストを構築
     */
    private function build_home_breadcrumbs($options) {
        if (!empty($options['show_home'])) {
            return [$this->create_home_item($options['home_text'])];
        }
        return [];
    }

    /**
     * ホーム項目を作成
     */
    private function create_home_item($text) {
        // ドメインルートをホームとする（WordPressインストールディレクトリを除外）
        $parsed_url = parse_url(home_url());
        $domain_root = $parsed_url['scheme'] . '://' . $parsed_url['host'] . '/';

        return [
            'title' => $text,
            'url' => $domain_root,
            'position' => 1
        ];
    }

    /**
     * URLパスからパンくずリストを構築（シンプル版）
     */
    private function build_from_url_path($breadcrumbs) {
        $segments = $this->get_url_segments();
        if (empty($segments)) {
            return $breadcrumbs;
        }

        $position = count($breadcrumbs) + 1;
        $options = $this->get_options();
        $enable_scraping = !empty($options['enable_scraping']);

        // ドメインルートを取得（サブディレクトリインストールを考慮）
        $parsed_url = parse_url(home_url());
        $domain_root = $parsed_url['scheme'] . '://' . $parsed_url['host'];

        // WordPressがサブディレクトリにインストールされている場合はそのパスも含める
        if (!empty($parsed_url['path']) && $parsed_url['path'] !== '/') {
            $domain_root .= rtrim($parsed_url['path'], '/');
        }

        // スクレイパーを初期化（タイトル取得は常に必要）
        $scraper = new KSPB_URL_Scraper();
        
        // 現在のページのURLを取得
        $current_url = home_url($_SERVER['REQUEST_URI'] ?? '');

        // 各セグメントを処理
        foreach ($segments as $index => $segment) {
            // URL構造に基づいてパスを構築
            $accumulated_path = '/' . implode('/', array_slice($segments, 0, $index + 1));
            // ドメインルートからの完全なURLを生成
            $parsed_url = parse_url(home_url());
            $domain_root = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            $url = $domain_root . $accumulated_path . '/';
            
            // 最後のセグメントかつ現在のページの場合の特別処理
            $is_current_page = ($index === count($segments) - 1) && 
                               (rtrim($url, '/') === rtrim($current_url, '/'));

            // まずWordPress内部情報から取得を試みる
            $title = null;
            
            // 1. 累積パスで固定ページをチェック（例: /article/blog）
            $page = get_page_by_path(ltrim($accumulated_path, '/'));
            if ($page && $page->post_status === 'publish') {
                $title = get_the_title($page->ID);
            }
            
            // 2. 単独セグメントで固定ページをチェック（例: blog）
            if (!$title) {
                $page = get_page_by_path($segment);
                if ($page && $page->post_status === 'publish') {
                    $title = get_the_title($page->ID);
                }
            }
            
            // 3. カスタム投稿タイプアーカイブをチェック
            if (!$title) {
                $post_types = get_post_types(['public' => true], 'objects');
                foreach ($post_types as $post_type) {
                    // rewriteスラッグまたは投稿タイプ名で確認
                    $slug = isset($post_type->rewrite['slug']) ? $post_type->rewrite['slug'] : $post_type->name;
                    if ($post_type->has_archive && $slug === $segment) {
                        $title = $post_type->labels->name;
                        break;
                    }
                }
            }
            
            // 4. タクソノミーをチェック
            if (!$title) {
                $taxonomies = get_taxonomies(['public' => true], 'objects');
                foreach ($taxonomies as $taxonomy) {
                    if (isset($taxonomy->rewrite['slug']) && $taxonomy->rewrite['slug'] === $segment) {
                        $title = $taxonomy->labels->name;
                        break;
                    }
                }
            }
            
            // 5. URLから実際のタイトルを取得（現在のページも含む）
            if (!$title && $enable_scraping && $scraper) {
                // User-Agentチェックにより無限ループは防止されているので、現在のページもスクレイピング可能
                $scraped_title = $scraper->get_title_from_url($url);
                if ($scraped_title) {
                    $title = $scraped_title;
                }
            }
            
            // 6. スクレイピング失敗時の追加フォールバック
            if (!$title && $is_current_page) {
                // 現在のページの場合、WordPress関数で再度試す
                if (function_exists('wp_get_document_title')) {
                    $doc_title = wp_get_document_title();
                    if (!empty($doc_title)) {
                        // サイト名を削除
                        $separators = ['|', '-', '–', '—', '»'];
                        foreach ($separators as $sep) {
                            if (strpos($doc_title, $sep) !== false) {
                                $parts = array_map('trim', explode($sep, $doc_title));
                                if (!empty($parts[0])) {
                                    $title = $parts[0];
                                    break;
                                }
                            }
                        }
                        if (!$title) {
                            $title = $doc_title;
                        }
                    }
                }
            }
            
            // 7. それでも取得できない場合はスラッグを整形
            if (!$title) {
                $title = $this->format_slug($segment);
            }

            // パンくず項目を追加（全ての項目にリンクを付ける）
            $breadcrumbs[] = [
                'title' => $title,
                'url' => $url,
                'position' => $position++
            ];
        }

        return $breadcrumbs;
    }

    /**
     * URLセグメントを取得（完全なURLパスを保持）
     */
    private function get_url_segments() {
        // 完全なURLパスを取得（WordPressインストールディレクトリも含む）
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $url_path = parse_url($request_uri, PHP_URL_PATH) ?? '';

        // URLパスの全セグメントを取得
        $segments = array_filter(explode('/', trim($url_path, '/')));

        return array_values($segments); // インデックスをリセット
    }

    /**
     * 完全なURLセグメントを取得（ドメインルートから）
     */
    private function get_full_url_segments() {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $url_path = parse_url($request_uri, PHP_URL_PATH) ?? '';

        return array_filter(explode('/', trim($url_path, '/')));
    }

    /**
     * スラッグを整形
     */
    private function format_slug($slug) {
        // スラッグを人間が読みやすい形式に変換
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }
    

    /**
     * 投稿ページのパンくずを構築
     */
    private function build_post_breadcrumbs($breadcrumbs) {
        global $post;
        $position = count($breadcrumbs) + 1;
        $depth_counter = 0;

        // すべての投稿タイプでURLパスベースで構築
        if (true) {
            // URLパスから階層を構築（最後の記事タイトルは除く）
            $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $path_segments = array_values(array_filter(explode('/', trim($current_path, '/'))));
            
            // 最後のセグメント（記事のスラッグ）を除いてパンくずを構築
            if (!empty($path_segments)) {
                array_pop($path_segments); // 記事のスラッグを除去
                
                $position = count($breadcrumbs) + 1;
                $options = $this->get_options();
                $enable_scraping = !empty($options['enable_scraping']);
                
                $parsed_url = parse_url(home_url());
                $domain_root = $parsed_url['scheme'] . '://' . $parsed_url['host'];
                $scraper = $enable_scraping ? new KSPB_URL_Scraper() : null;
                
                // 各階層を追加（スクレイピング機能でタイトル取得）
                foreach ($path_segments as $index => $segment) {
                    $accumulated_path = '/' . implode('/', array_slice($path_segments, 0, $index + 1));
                    $url = $domain_root . $accumulated_path . '/';
                    
                    $title = $this->format_slug($segment);
                    
                    // スクレイピングでタイトル取得
                    if ($enable_scraping && $scraper) {
                        $scraped_title = $scraper->get_title_from_url($url);
                        if ($scraped_title) {
                            $title = $scraped_title;
                        }
                    }
                    
                    $breadcrumbs[] = [
                        'title' => $title,
                        'url' => $url,
                        'position' => $position++
                    ];
                }
            }
            
            // 現在の投稿を追加
            $breadcrumbs[] = [
                'title' => get_the_title($post->ID),
                'url' => get_permalink($post->ID),
                'position' => count($breadcrumbs) + 1
            ];
            
            return $breadcrumbs;
        }

        // 通常の投稿の場合：カテゴリーを取得
        $categories = get_the_category($post->ID);
        if (!empty($categories)) {
            $primary_category = $categories[0];

            // 親カテゴリーがある場合は階層を辿る
            $category_parents = get_category_parents($primary_category->term_id, false, '/', true);
            if ($category_parents && !is_wp_error($category_parents)) {
                $parent_slugs = explode('/', trim($category_parents, '/'));

                // スクレイピング機能の設定を確認
                $options = $this->get_options();
                $enable_scraping = !empty($options['enable_scraping']);
                $scraper = $enable_scraping ? new KSPB_URL_Scraper() : null;

                foreach ($parent_slugs as $parent_slug) {
                    if (empty($parent_slug)) continue;
                    if (++$depth_counter > self::MAX_DEPTH) break;

                    $parent_cat = get_category_by_slug($parent_slug);
                    if ($parent_cat) {
                        $cat_url = get_category_link($parent_cat->term_id);
                        $cat_title = $parent_cat->name;

                        // スクレイピングが有効な場合、URLステータスをチェック
                        if ($enable_scraping && $scraper) {
                            $status_data = $scraper->check_url_status($cat_url);
                            $status = is_array($status_data) ? $status_data['status'] : $status_data;

                            // 現在のURLパスから適切な階層を取得
                            $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                            $path_segments = array_values(array_filter(explode('/', $current_path)));

                            // URLパスベースの期待されるURL
                            $expected_url = null;
                            if (isset($path_segments[$depth_counter - 1])) {
                                $parsed_url = parse_url(home_url());
                                $domain_root = $parsed_url['scheme'] . '://' . $parsed_url['host'];
                                $expected_url = $domain_root . '/' . implode('/', array_slice($path_segments, 0, $depth_counter)) . '/';
                            }

                            // カテゴリーURLと期待されるURLが異なる場合
                            $use_expected_url = false;
                            if ($expected_url && $cat_url !== $expected_url) {
                                // カテゴリーベースを確認
                                $category_base = get_option('category_base');
                                if (empty($category_base)) {
                                    $category_base = 'category';
                                }

                                // カテゴリーURLにカテゴリーベースが含まれているが、実際のURLパスには含まれていない
                                if (strpos($cat_url, '/' . $category_base . '/') !== false &&
                                    strpos($expected_url, '/' . $category_base . '/') === false) {
                                    $use_expected_url = true;
                                }
                            }

                            // リダイレクトの場合を最優先で処理
                            if (($status === 301 || $status === 302) && !empty($status_data['redirect_to'])) {
                                // リダイレクトの場合、リダイレクト先を使用
                                $cat_url = $status_data['redirect_to'];
                            } elseif ($status === 404 || $status === 0 || $use_expected_url) {
                                // 404エラーの場合、または期待されるURLを使用すべき場合
                                if ($expected_url) {
                                    $alternative_url = $expected_url;

                                    // 代替URLのステータスをチェック
                                    $alt_status_data = $scraper->check_url_status($alternative_url);
                                    $alt_status = is_array($alt_status_data) ? $alt_status_data['status'] : $alt_status_data;

                                    if ($alt_status === 200 || $alt_status === 301 || $alt_status === 302) {
                                        // 代替URLが有効な場合は使用
                                        $cat_url = $alternative_url;
                                        $status = $alt_status;  // ステータスも更新

                                        // リダイレクトの場合
                                        if (($alt_status === 301 || $alt_status === 302) && !empty($alt_status_data['redirect_to'])) {
                                            $cat_url = $alt_status_data['redirect_to'];
                                        }
                                    }
                                }
                            }

                            $breadcrumbs[] = [
                                'title' => $cat_title,
                                'url' => $cat_url,
                                'position' => $position++,
                                'status' => $status
                            ];
                        } else {
                            // スクレイピング無効時は通常のカテゴリーURLを使用
                            $breadcrumbs[] = [
                                'title' => $cat_title,
                                'url' => $cat_url,
                                'position' => $position++
                            ];
                        }
                    }
                }
            }
        }

        // 現在の投稿
        $breadcrumbs[] = $this->create_breadcrumb_item(
            get_the_title($post->ID),
            get_permalink($post->ID),
            $position
        );

        return $breadcrumbs;
    }

    /**
     * 固定ページのパンくずを構築
     */
    private function build_page_breadcrumbs($breadcrumbs) {
        global $post;
        $position = count($breadcrumbs) + 1;
        $depth_counter = 0;

                // 親ページがある場合は階層を辿る
        $ancestors = get_post_ancestors($post->ID);
        if (!empty($ancestors)) {
            $ancestors = array_reverse($ancestors);
            foreach ($ancestors as $ancestor_id) {
                if (++$depth_counter > self::MAX_DEPTH) break; // 深度制限

                $breadcrumbs[] = $this->create_breadcrumb_item(
                    get_the_title($ancestor_id),
                    get_permalink($ancestor_id),
                    $position++
                );
            }
        }

        // 現在のページ
        $breadcrumbs[] = $this->create_breadcrumb_item(
            get_the_title($post->ID),
            get_permalink($post->ID),
            $position
        );

        return $breadcrumbs;
    }

    /**
     * タクソノミーアーカイブのパンくずを構築
     */
    private function build_taxonomy_breadcrumbs($breadcrumbs) {
        $position = count($breadcrumbs) + 1;
        $queried_object = get_queried_object();
        $depth_counter = 0;

        if (is_category()) {
            // カテゴリーアーカイブ
            $category = $queried_object;

                        // 親カテゴリーがある場合
            if ($category->parent != 0) {
                $parent_categories = get_category_parents($category->parent, false, '/', true);
                if ($parent_categories && !is_wp_error($parent_categories)) {
                    $parent_slugs = explode('/', trim($parent_categories, '/'));



                    foreach ($parent_slugs as $parent_slug) {
                        if (empty($parent_slug)) continue;
                        if (++$depth_counter > self::MAX_DEPTH) break; // 深度制限

                                                $parent_cat = get_category_by_slug($parent_slug);
                        if ($parent_cat) {
                            $breadcrumbs[] = $this->create_breadcrumb_item(
                                $parent_cat->name,
                                get_category_link($parent_cat->term_id),
                                $position++
                            );
                        }
                    }
                }
            }

                        // 現在のカテゴリー
            $breadcrumbs[] = $this->create_breadcrumb_item(
                $category->name,
                get_category_link($category->term_id),
                $position
            );
        } elseif (is_tag()) {
            // タグアーカイブ
            $tag = $queried_object;
            $breadcrumbs[] = $this->create_breadcrumb_item(
                $tag->name,
                get_tag_link($tag->term_id),
                $position
            );
                } elseif (is_tax()) {
            // カスタムタクソノミーアーカイブ
            $term = $queried_object;
            $taxonomy = get_taxonomy($term->taxonomy);

            // タクソノミーラベルを追加
            if ($taxonomy) {
                $breadcrumbs[] = $this->create_breadcrumb_item(
                    $taxonomy->labels->name,
                    get_post_type_archive_link(get_post_type()),
                    $position++
                );
            }

            // 現在のターム
            $breadcrumbs[] = $this->create_breadcrumb_item(
                $term->name,
                get_term_link($term),
                $position
            );
        }

        return $breadcrumbs;
    }

    /**
     * アーカイブページのパンくずを構築
     */
    private function build_archive_breadcrumbs($breadcrumbs) {
        $position = count($breadcrumbs) + 1;

        if (is_date()) {
            // 日付アーカイブ
            if (is_year()) {
                $breadcrumbs[] = $this->create_breadcrumb_item(
                    get_the_date('Y年'),
                    get_year_link(get_the_date('Y')),
                    $position
                );
            } elseif (is_month()) {
                // 年
                $breadcrumbs[] = $this->create_breadcrumb_item(
                    get_the_date('Y年'),
                    get_year_link(get_the_date('Y')),
                    $position++
                );
                // 月
                $breadcrumbs[] = $this->create_breadcrumb_item(
                    get_the_date('n月'),
                    get_month_link(get_the_date('Y'), get_the_date('n')),
                    $position
                );
            } elseif (is_day()) {
                // 年
                $breadcrumbs[] = $this->create_breadcrumb_item(
                    get_the_date('Y年'),
                    get_year_link(get_the_date('Y')),
                    $position++
                );
                // 月
                $breadcrumbs[] = $this->create_breadcrumb_item(
                    get_the_date('n月'),
                    get_month_link(get_the_date('Y'), get_the_date('n')),
                    $position++
                );
                // 日
                $breadcrumbs[] = $this->create_breadcrumb_item(
                    get_the_date('j日'),
                    get_day_link(get_the_date('Y'), get_the_date('n'), get_the_date('j')),
                    $position
                );
            }
        } elseif (is_author()) {
            // 著者アーカイブ
            $author = get_queried_object();
            $breadcrumbs[] = $this->create_breadcrumb_item(
                $author->display_name,
                get_author_posts_url($author->ID),
                $position
            );
        } elseif (is_post_type_archive()) {
            // カスタム投稿タイプアーカイブ - 階層は作らず単純にアーカイブを追加
            // build_from_url_pathを呼ばない（無限ループ防止）
            // 親の階層はbuildメソッドで既に処理されている
        }

        return $breadcrumbs;
    }

    /**
     * オプションを取得（親クラスのメソッドを呼び出し）
     */
    private function get_options() {
        $instance = KashiwazakiSeoPerfectBreadcrumbs::get_instance();
        return $instance->get_options();
    }

    /**
     * WordPressの内部情報から項目を検索
     */
    private function find_wordpress_item($segment, $path) {
        // 1. 固定ページを検索（累積パスで）
        $page = get_page_by_path(ltrim($path, '/'));
        if ($page && $page->post_status === 'publish') {
            return [
                'title' => get_the_title($page->ID),
                'url' => get_permalink($page->ID)
            ];
        }

        // 2. カテゴリーを検索
        $category = get_category_by_slug($segment);
        if ($category) {
            return [
                'title' => $category->name,
                'url' => get_category_link($category->term_id)
            ];
        }

        // 3. 投稿を検索
        $posts = get_posts([
            'name' => $segment,
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => 1
        ]);

        if (!empty($posts)) {
            return [
                'title' => get_the_title($posts[0]->ID),
                'url' => get_permalink($posts[0]->ID)
            ];
        }

        // 4. タグを検索
        $tag = get_term_by('slug', $segment, 'post_tag');
        if ($tag) {
            return [
                'title' => $tag->name,
                'url' => get_tag_link($tag->term_id)
            ];
        }

        // 5. カスタム投稿タイプを検索
        $post_types = get_post_types(['public' => true, '_builtin' => false], 'names');
        foreach ($post_types as $post_type) {
            $custom_posts = get_posts([
                'name' => $segment,
                'post_type' => $post_type,
                'post_status' => 'publish',
                'numberposts' => 1
            ]);

            if (!empty($custom_posts)) {
                return [
                    'title' => get_the_title($custom_posts[0]->ID),
                    'url' => get_permalink($custom_posts[0]->ID)
                ];
            }
        }

        // 6. カスタムタクソノミーを検索
        $taxonomies = get_taxonomies(['public' => true, '_builtin' => false], 'names');
        foreach ($taxonomies as $taxonomy) {
            $term = get_term_by('slug', $segment, $taxonomy);
            if ($term) {
                return [
                    'title' => $term->name,
                    'url' => get_term_link($term)
                ];
            }
        }

        return null;
    }

    /**
     * カスタム投稿タイプの個別記事用パンくず構築
     */
    private function build_custom_post_breadcrumbs($breadcrumbs, $post) {
        $position = count($breadcrumbs) + 1;
        $post_type_obj = get_post_type_object(get_post_type($post->ID));

        // カスタム投稿タイプアーカイブを追加（スクレイピング無し）
        if ($post_type_obj && $post_type_obj->has_archive) {
            $breadcrumbs[] = [
                'title' => $post_type_obj->labels->name,
                'url' => get_post_type_archive_link($post_type_obj->name),
                'position' => $position++
            ];
        }

        // カスタムタクソノミーの階層を追加
        $taxonomies = get_object_taxonomies($post_type_obj->name, 'objects');
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->hierarchical) {
                $terms = get_the_terms($post->ID, $taxonomy->name);
                if (!empty($terms) && !is_wp_error($terms)) {
                    // 最も深い階層のタームを取得
                    $deepest_term = null;
                    $max_level = 0;
                    
                    foreach ($terms as $term) {
                        $level = $this->get_term_level($term);
                        if ($level > $max_level) {
                            $max_level = $level;
                            $deepest_term = $term;
                        }
                    }
                    
                    if ($deepest_term) {
                        // 親タームから順番に追加
                        $term_ancestors = get_ancestors($deepest_term->term_id, $taxonomy->name);
                        $term_ancestors = array_reverse($term_ancestors);
                        
                        foreach ($term_ancestors as $ancestor_id) {
                            $ancestor_term = get_term($ancestor_id, $taxonomy->name);
                            $breadcrumbs[] = [
                                'title' => $ancestor_term->name,
                                'url' => get_term_link($ancestor_term),
                                'position' => $position++
                            ];
                        }
                        
                        // 現在のタームを追加
                        $breadcrumbs[] = [
                            'title' => $deepest_term->name,
                            'url' => get_term_link($deepest_term),
                            'position' => $position++
                        ];
                    }
                    break; // 最初の階層タクソノミーのみ使用
                }
            }
        }

        // 現在の投稿を追加
        $breadcrumbs[] = [
            'title' => get_the_title($post->ID),
            'url' => get_permalink($post->ID),
            'position' => $position
        ];

        return $breadcrumbs;
    }

    /**
     * タームの階層レベルを取得
     */
    private function get_term_level($term) {
        $level = 0;
        while ($term->parent != 0) {
            $term = get_term($term->parent, $term->taxonomy);
            $level++;
        }
        return $level;
    }
}

/**
 * レンダラークラス
 */
class KSPB_Renderer {

    /**
     * SVGアイコンの定義
     */
    private const ICONS = [
        'home' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1976d2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12L12 3l9 9"/><path d="M9 21V9h6v12"/></svg>',
        'folder' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ff9800" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h5l2 3h11v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>',
        'page' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#43a047" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="12" y2="16"/></svg>'
    ];

    /**
     * パンくずリストをレンダリング
     */
    public function render($breadcrumbs, $options) {
        if (empty($breadcrumbs)) {
            return '';
        }

        $separator = $options['separator'] ?? '>';
        $pattern = $options['pattern'] ?? 'classic';
        $font_size = intval($options['font_size'] ?? 14);

        $html = $this->build_html($breadcrumbs, $separator, $pattern, $font_size);

        return $html;
    }

    /**
     * HTMLを構築
     */
    private function build_html($breadcrumbs, $separator, $pattern, $font_size) {
        $items_html = $this->build_items_html($breadcrumbs, $separator);

        return sprintf(
            '<nav class="kspb-breadcrumbs %s" aria-label="パンくずリスト" style="font-size:%dpx;">
                <ol class="kspb-list">%s</ol>
            </nav>',
            esc_attr($pattern),
            $font_size,
            $items_html
        );
    }

    /**
     * 項目のHTMLを構築
     */
    private function build_items_html($breadcrumbs, $separator) {
        $html = '';
        $total = count($breadcrumbs);

        foreach ($breadcrumbs as $index => $breadcrumb) {
            $icon = $this->get_icon_for_position($index, $total);
            $html .= $this->build_item_html($breadcrumb, $icon, $separator, $index === $total - 1);
        }

        return $html;
    }

    /**
     * 単一項目のHTMLを構築
     */
    private function build_item_html($breadcrumb, $icon, $separator, $is_last) {
        $html = '<li class="kspb-item">';
        $html .= sprintf('<span class="kspb-icon">%s</span>', $icon);

        // 常にリンクを表示（URLが存在する場合）
        if (!empty($breadcrumb['url'])) {
            $html .= sprintf(
                '<a href="%s">%s</a>',
                esc_url($breadcrumb['url']),
                esc_html($breadcrumb['title'])
            );
        } else {
            // リンクなしでタイトルのみ表示
            $html .= sprintf(
                '<span class="kspb-nolink">%s</span>',
                esc_html($breadcrumb['title'])
            );
        }

        if (!$is_last) {
            $html .= sprintf('<span class="kspb-separator">%s</span>', esc_html($separator));
        }

        $html .= '</li>';

        return $html;
    }

    /**
     * 位置に応じたアイコンを取得
     */
    private function get_icon_for_position($index, $total) {
        if ($index === 0) {
            return self::ICONS['home'];
        } elseif ($index === $total - 1) {
            return self::ICONS['page'];
        } else {
            return self::ICONS['folder'];
        }
    }
}

/**
 * URL スクレイパークラス
 */
class KSPB_URL_Scraper {
    /**
     * キャッシュキーのプレフィックス
     */
    private const CACHE_PREFIX = 'kspb_url_';

    /**
     * キャッシュ期間（秒）
     */
    private const CACHE_DURATION = 86400; // 24時間

    /**
     * タイムアウト（秒）
     */
    private const TIMEOUT = 5;

    /**
     * 最大リトライ回数
     */
    private const MAX_RETRIES = 2;

    /**
     * 永久ループ防止用の最大深度
     */
    private const MAX_DEPTH = 5;

    /**
     * 訪問済みURLリスト（永久ループ防止）
     */
    private $visited_urls = [];

    /**
     * URLのステータスコードをチェック（リダイレクト先も含む）
     */
    public function check_url_status($url) {
        // 現在のページのURLはチェックしない（永久ループ防止）
        $current_url = home_url($_SERVER['REQUEST_URI'] ?? '');
        if ($url === $current_url || rtrim($url, '/') === rtrim($current_url, '/')) {
            return ['status' => 200, 'redirect_to' => null];
        }

        // キャッシュチェック
        $cache_key = 'kspb_status_v2_' . md5($url);
        $cached_data = get_transient($cache_key);
        if ($cached_data !== false) {
            return $cached_data;
        }

        // HEADリクエストで高速チェック（リダイレクトを追跡しない）
        $response = wp_remote_head($url, [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,  // リダイレクトを追跡しない
            'user-agent' => 'KSPB Breadcrumbs Bot/1.0',
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            return ['status' => 0, 'redirect_to' => null];
        }

        $status_code = wp_remote_retrieve_response_code($response);

        // リダイレクト先のLocationヘッダーを取得
        $location = wp_remote_retrieve_header($response, 'location');

        // 相対URLの場合は絶対URLに変換
        if ($location && strpos($location, 'http') !== 0) {
            $parsed = parse_url($url);
            $location = $parsed['scheme'] . '://' . $parsed['host'] . $location;
        }



        $result = [
            'status' => $status_code,
            'redirect_to' => $location
        ];

        // キャッシュに保存
        set_transient($cache_key, $result, self::CACHE_DURATION);

        return $result;
    }



    /**
     * URLからタイトルを取得
     */
    public function get_title_from_url($url) {
        // 訪問済みチェック（同一リクエスト内での重複防止）
        if (in_array($url, $this->visited_urls)) {
            return null;
        }
        $this->visited_urls[] = $url;

        // キャッシュチェック
        $cache_key = 'kspb_title_' . md5($url);
        $cached_title = get_transient($cache_key);
        if ($cached_title !== false) {
            return $cached_title;
        }

        // HTTPリクエスト
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'redirection' => 3,
            'user-agent' => 'KSPB Breadcrumbs Bot/1.0',
            'sslverify' => false // 開発環境対応
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return null;
        }

        // タイトルタグを抽出
        $title = $this->extract_title($body);

        // キャッシュに保存
        if ($title) {
            set_transient($cache_key, $title, self::CACHE_DURATION);
        }

        return $title;
    }

    /**
     * HTMLからタイトルを抽出
     */
    private function extract_title($html) {
        // 1. タイトルタグを最優先で取得
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim($matches[1]);

            // HTMLエンティティをデコード
            $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // 余分な空白を削除
            $title = preg_replace('/\s+/', ' ', $title);

            // サイト名などの区切り文字で分割して最初の部分を取得
            $separators = ['|', '-', '–', '—', ':', '»', '·'];
            foreach ($separators as $sep) {
                if (strpos($title, $sep) !== false) {
                    $parts = explode($sep, $title);
                    $title = trim($parts[0]);
                    // 最初の部分が空または短すぎる場合は2番目を試す
                    if (empty($title) || strlen($title) < 3) {
                        $title = isset($parts[1]) ? trim($parts[1]) : $title;
                    }
                    break;
                }
            }

            if (!empty($title) && strlen($title) > 2) {
                return $title;
            }
        }

        // 2. OGPタイトルを次に試す
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            $og_title = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!empty($og_title) && strlen($og_title) > 2) {
                return $og_title;
            }
        }

        // 3. 最後にH1タグを取得
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
            $h1 = strip_tags(trim($matches[1]));
            if (!empty($h1) && strlen($h1) > 2) {
                return html_entity_decode($h1, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return null;
    }

    /**
     * キャッシュをクリア
     */
    public function clear_cache() {
        global $wpdb;

        // すべてのKSPB関連キャッシュを削除
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_kspb_%'
            OR option_name LIKE '_transient_timeout_kspb_%'"
        );

        // 古い形式のキャッシュも念のため削除
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_kspb_status_%'
            OR option_name LIKE '_transient_timeout_kspb_status_%'"
        );

        // オブジェクトキャッシュもクリア
        wp_cache_flush();
    }
}

// プラグインの初期化
KashiwazakiSeoPerfectBreadcrumbs::get_instance();

// グローバル関数として提供
function kspb_display_breadcrumbs() {
    $instance = KashiwazakiSeoPerfectBreadcrumbs::get_instance();
    $options = $instance->get_options();

    // 表示条件をチェック
    $reflection = new ReflectionClass($instance);
    $method = $reflection->getMethod('should_display_breadcrumbs');
    $method->setAccessible(true);

    if (!$method->invoke($instance, $options)) {
        return;
    }

    echo $instance->render_breadcrumbs();
}
