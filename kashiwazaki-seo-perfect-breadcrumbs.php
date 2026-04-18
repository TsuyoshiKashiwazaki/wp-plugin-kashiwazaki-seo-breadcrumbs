<?php
/**
 * Plugin Name: Kashiwazaki SEO Perfect Breadcrumbs
 * Plugin URI: https://www.tsuyoshikashiwazaki.jp
 * Description: 高度なSEO対策を実現する多機能パンくずリストプラグイン。URLステータスチェック機能により404エラーを自動回避し、常に最適なパンくずリストを生成。構造化データ対応、6種類のデザインパターン、自動挿入機能を搭載。サブディレクトリインストールにも完全対応。
 * Version: 1.0.7
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
define('KSPB_PLUGIN_VERSION', '1.0.7');
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
    'show_on_home_posts' => true,
    'auth_username' => '',
    'auth_password' => ''
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
     * リクエスト内でキャッシュしたパンくず配列。
     * auto_insert_breadcrumbs (the_content フィルタ) と add_structured_data (wp_head)
     * の両方で generate_breadcrumbs() が呼ばれ、毎回 URL セグメント数 × 外部 HTTP が
     * 走るのを避けるため、1 リクエスト内では結果を再利用する。
     * null = 未計算、空配列 = 計算済みで breadcrumbs なし、他 = 計算済み。
     */
    private $cached_breadcrumbs = null;

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

        // 内部データスキーマバージョン '1.0.6' 以降: auth_password を暗号化保存に移行。
        // ※ これは kspb_version (内部データバージョン、migration 追跡用) であって、
        //   プラグイン公開バージョン KSPB_PLUGIN_VERSION (リリース時にユーザーが bump) とは別。
        // 旧ユーザーが管理画面で再保存しなくても DB から平文が消えるようにする。
        // sodium 不在や random_bytes 失敗時は encrypt() が平文を返し得るため、戻り値が
        // 実際に暗号化された (enc: プレフィックス付き) かを検証してから version を bump する。
        // 暗号化失敗時は version を据え置き、次回 init で再試行する。
        if (version_compare($current_version, '1.0.6', '<')) {
            $options = get_option(KSPB_OPTION_NAME, []);
            $stored = $options['auth_password'] ?? '';
            $prefix = KSPB_Crypto::PREFIX;
            $prefix_len = strlen($prefix);

            // プレフィックス検査だけでは、偶然 "enc:" で始まる平文 (例: 'enclosed') を
            // 暗号化済みと誤判定する。一方で「format valid だが復号不能」なケース
            // (鍵/salt 変更) は、元値を破壊せず残したほうが後で salt を戻して復旧できる。
            // したがって format 検証で分岐する:
            //   - format invalid (base64 壊れ / nonce+MAC 長さ不足) → 偶然衝突の平文とみなし migration
            //   - format valid       → 暗号化値とみなし touch しない (復号成否は問わず DB 保持)
            //   - プレフィックスなし → 旧平文 → migration
            $needs_migration = false;
            if ($stored !== '') {
                if (strncmp($stored, $prefix, $prefix_len) !== 0) {
                    $needs_migration = true;
                } elseif (!KSPB_Crypto::is_valid_ciphertext_format($stored)) {
                    $needs_migration = true;
                }
            }

            if ($needs_migration) {
                $encrypted = KSPB_Crypto::encrypt($stored);
                // sodium 不在や random_bytes 失敗時に encrypt() は平文をそのまま返す。
                // その場合に元の平文が偶然 enc: で始まっていると prefix マッチだけでは
                // 暗号化成功と誤判定されるため、(a) 実際に format 検証を通過する暗号文で
                // あること、(b) 元値と異なること、の両方を求める。
                $really_encrypted = ($encrypted !== $stored)
                    && KSPB_Crypto::is_valid_ciphertext_format($encrypted);
                if ($really_encrypted) {
                    $options['auth_password'] = $encrypted;
                    update_option(KSPB_OPTION_NAME, $options);
                    update_option('kspb_version', '1.0.6');
                }
                // 暗号化が平文のまま or format invalid なら version を上げず、次回 init で再試行
            } else {
                // プレフィックスなしの空値、または format valid な暗号文 (復号成否は問わず
                // 現時点で触る必要がない) → 安全に version bump
                update_option('kspb_version', '1.0.6');
            }
        }

        // 新しい内部データスキーマバージョンを追加する場合、ここに version_compare
        // ブロックを追記する (冪等、失敗時は version bump せず次回再試行):
        //
        //   if (version_compare($current_version, 'X.Y.Z', '<')) {
        //       // X.Y.Z のマイグレーション処理
        //       update_option('kspb_version', 'X.Y.Z');
        //   }

        // バージョン遷移と独立して、DEFAULT_OPTIONS に新規追加されたキーを既存レコードに
        // 自動補完する。wp_parse_args は既存値を上書きせず、不足キーだけを default から
        // 補うため冪等。新オプションを KSPB_DEFAULT_OPTIONS に追加した際、アップグレード
        // ユーザーの DB レコードにも反映される。
        $stored_options = get_option(KSPB_OPTION_NAME, []);
        if (is_array($stored_options)) {
            $merged = wp_parse_args($stored_options, KSPB_DEFAULT_OPTIONS);
            if ($merged !== $stored_options) {
                update_option(KSPB_OPTION_NAME, $merged);
            }
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
        KSPB_Admin_Page::render();
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
        // リクエスト内で既に計算済みならそれを返す (the_content と wp_head の二重呼出で
        // 外部 HTTP が重複発生するのを防ぐ memo)。
        if ($this->cached_breadcrumbs !== null) {
            return $this->cached_breadcrumbs;
        }

        // スクレイピングボットからのアクセスの場合は何も返さない（無限ループ防止）
        if (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'KSPB Breadcrumbs Bot') !== false) {
            $this->cached_breadcrumbs = [];
            return $this->cached_breadcrumbs;
        }

        $this->cached_breadcrumbs = $this->breadcrumb_builder->build($this->get_options());
        return $this->cached_breadcrumbs;
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
        // the_content は wp_head / RSS フィード / REST API / get_the_content() の
        // 入れ子呼び出し / 管理画面エディタプレビュー 等でも発火するため、
        // フロント本文以外でパンくず HTML が混入しないようコンテキスト判定を先に行う。
        if (is_admin()
            || is_feed()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_AJAX') && DOING_AJAX)
            || !is_main_query()
            || !in_the_loop()) {
            return $content;
        }

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
    public function should_display_breadcrumbs($options) {
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
     * パンくずリストを構築
     */
    public function build($options) {
        $breadcrumbs = [];

        // ホームを追加
        if (!empty($options['show_home'])) {
            $breadcrumbs[] = $this->create_home_item($options['home_text']);
        }

        // URLセグメントを取得
        $segments = $this->get_url_segments();

        // セグメントがなければホームのみ
        if (empty($segments)) {
            return $breadcrumbs;
        }

        // URLパスから階層を構築
        $breadcrumbs = $this->build_from_url_path($breadcrumbs);

        // 最大深度を超えた場合は切り詰める
        if (count($breadcrumbs) > self::MAX_DEPTH) {
            $breadcrumbs = array_slice($breadcrumbs, 0, self::MAX_DEPTH);
        }

        return $breadcrumbs;
    }

    /**
     * オプションを取得（親クラスのメソッドを呼び出し）。
     * build_from_url_path() が enable_scraping フラグ取得のために参照する。
     */
    private function get_options() {
        $instance = KashiwazakiSeoPerfectBreadcrumbs::get_instance();
        return $instance->get_options();
    }

    /**
     * ホーム項目を作成
     */
    private function create_home_item($text) {
        // ドメインルートをホームとする（WordPressインストールディレクトリを除外）。
        // parse_url は filter 等で壊れた URL を渡すと false / 欠損キーを返し得るため、
        // scheme/host は ?? で defensive に default 値を補う。
        $parsed_url = parse_url(home_url());
        $scheme = (is_array($parsed_url) ? ($parsed_url['scheme'] ?? 'https') : 'https');
        $host = (is_array($parsed_url) ? ($parsed_url['host'] ?? '') : '');
        $domain_root = $scheme . '://' . $host . '/';

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

        // 深度制限を前段で適用 (公開リクエストから深い URL を多段スクレイピングされると
        // セグメント数 × wp_remote_get で自己 DoS を引き起こすため)。
        // build() 末尾でも $breadcrumbs を切り詰めているが、ここで事前に segments を
        // 絞ることで内部ループの外部 HTTP 回数自体を制限する。
        if (count($segments) > self::MAX_DEPTH) {
            $segments = array_slice($segments, 0, self::MAX_DEPTH);
        }

        $position = count($breadcrumbs) + 1;
        $options = $this->get_options();
        $enable_scraping = !empty($options['enable_scraping']);

        // ドメインルートを取得（サブディレクトリインストールを考慮）。
        // parse_url 失敗 / 欠損キーに備え ?? で default 値を補う。
        $parsed_url = parse_url(home_url());
        $scheme = (is_array($parsed_url) ? ($parsed_url['scheme'] ?? 'https') : 'https');
        $host = (is_array($parsed_url) ? ($parsed_url['host'] ?? '') : '');
        $install_path = (is_array($parsed_url) ? ($parsed_url['path'] ?? '') : '');
        $domain_root = $scheme . '://' . $host;

        // WordPressがサブディレクトリにインストールされている場合はそのパスも含める
        if ($install_path !== '' && $install_path !== '/') {
            $domain_root .= rtrim($install_path, '/');
        }

        // スクレイパーを初期化（タイトル取得は常に必要）
        $options = $this->get_options();
        $scraper = new KSPB_URL_Scraper($options);
        
        // 現在のページのURLを取得
        $current_url = esc_url_raw(home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '')));

        // 各セグメントを処理
        foreach ($segments as $index => $segment) {
            // URL構造に基づいてパスを構築
            $path_segments = array_slice($segments, 0, $index + 1);
            // WordPress内部関数用のデコードされたパス
            $accumulated_path = '/' . implode('/', $path_segments);
            // URL構築用のエンコードされたパス
            $encoded_segments = array_map('rawurlencode', $path_segments);
            $encoded_path = '/' . implode('/', $encoded_segments);
            // ドメインルートからの完全なURLを生成 (parse_url 失敗に備えた defensive)。
            // ループ冒頭で取得した $scheme / $host を再利用すれば本来冗長だが、将来の
            // filter で home_url() が動的変化するケースへの保険として再取得する。
            $parsed_loop = parse_url(home_url());
            $loop_scheme = (is_array($parsed_loop) ? ($parsed_loop['scheme'] ?? 'https') : 'https');
            $loop_host = (is_array($parsed_loop) ? ($parsed_loop['host'] ?? '') : '');
            $domain_root = $loop_scheme . '://' . $loop_host;
            $url = $domain_root . $encoded_path . '/';
            // パーセントエンコーディングを小文字に統一（%E3 → %e3）
            $url = $this->lowercase_percent_encoding($url);

            // 最後のセグメントかつ現在のページの場合の特別処理
            // URL比較時も小文字化して比較
            $is_current_page = ($index === count($segments) - 1) &&
                               (rtrim($url, '/') ===
                                rtrim($this->lowercase_percent_encoding($current_url), '/'));

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

            // 3. 投稿（post）と全カスタム投稿タイプの個別記事をチェック
            if (!$title) {
                $post_types = get_post_types(['public' => true], 'names');
                foreach ($post_types as $post_type) {
                    $posts = get_posts([
                        'name' => $segment,
                        'post_type' => $post_type,
                        'post_status' => 'publish',
                        'numberposts' => 1
                    ]);

                    if (!empty($posts)) {
                        $title = get_the_title($posts[0]->ID);
                        break;
                    }
                }
            }

            // 4. カスタム投稿タイプアーカイブをチェック
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
     * URLのパーセントエンコーディングを小文字に変換
     * (%E3%81%82 → %e3%81%82)
     */
    private function lowercase_percent_encoding($url) {
        return preg_replace_callback('/%[0-9A-F]{2}/', function($matches) {
            return strtolower($matches[0]);
        }, $url);
    }

    /**
     * URLセグメントを取得（完全なURLパスを保持）
     */
    private function get_url_segments() {
        // 完全なURLパスを取得（WordPressインストールディレクトリも含む）
        $request_uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '');
        $url_path = parse_url($request_uri, PHP_URL_PATH) ?? '';

        // URLパスの全セグメントを取得し、URLデコードを適用
        $segments = array_filter(explode('/', trim($url_path, '/')));
        $segments = array_map('urldecode', $segments);

        return array_values($segments); // インデックスをリセット
    }


    /**
     * スラッグを整形
     */
    private function format_slug($slug) {
        // スラッグを人間が読みやすい形式に変換
        return ucwords(str_replace(['-', '_'], ' ', $slug));
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
     * 1 リクエスト内で許可する外部スクレイピング回数の上限。
     * 公開リクエストから無制限に wp_remote_get が走るのを防ぐサーキットブレーカ。
     */
    private const MAX_SCRAPES_PER_REQUEST = 10;

    /**
     * get_title_from_url の失敗結果に使う negative cache の sentinel。
     * transient の false (=未ヒット) と区別するために文字列で保存する。
     */
    private const NEGATIVE_SENTINEL = '__kspb_null__';

    /**
     * Negative cache (取得失敗時の短期キャッシュ) の保持秒数。
     */
    private const NEGATIVE_CACHE_DURATION = 300;

    /**
     * キャッシュバージョン管理用 option 名。clear_cache でこのバージョンを +1 する
     * (論理無効化) ことで、persistent object cache drop-in (Redis Object Cache 等)
     * 利用時にも古い transient を一括無効化できる。物理削除は DB 側のみで行い、
     * object cache に残った古い version キーは TTL で自然消滅する (TTL は Redis や
     * Memcached もそのまま尊重するため、整合性の問題は起きない)。
     *
     * レジストリ + update_option 方式は TOCTOU race で新キー消失リスクがあり、
     * かつレジストリ上限を超えると古いキーが残存したため、ここでは採用しない。
     */
    private const CACHE_VERSION_OPTION = 'kspb_cache_version';

    /**
     * 訪問済みURLリスト（永久ループ防止）
     */
    private $visited_urls = [];

    /**
     * 1 リクエスト内での外部スクレイピング試行回数カウンタ。
     */
    private $scrape_count = 0;

    /**
     * 現在のキャッシュバージョン (整数) を取得。オプション未設定時は 1。
     */
    private static function cache_version() {
        $v = get_option(self::CACHE_VERSION_OPTION, 1);
        return is_numeric($v) ? (int) $v : 1;
    }

    /**
     * transient キーに現在のキャッシュバージョンを含めて生成する。clear_cache で
     * バージョンが bump されると、古い version 付きキーは get_transient 側で
     * 参照されなくなり、object cache 側に残ったデータも TTL で自然消滅する。
     *
     * さらに Basic 認証の状態 (username + パスワード有無) を hash として key に混ぜる
     * ことで、認証設定変更時にキャッシュが自動的に無効化される (保護ページタイトルが
     * 旧認証設定のまま残留する経路を遮断)。
     *
     * @param string $prefix 'status' | 'title' など論理キーの接頭辞
     * @param string $url    キャッシュ対象 URL
     */
    private static function versioned_cache_key($prefix, $url) {
        return 'kspb_' . $prefix . '_v' . self::cache_version() . '_' . self::auth_state_hash() . '_' . md5($url);
    }

    /**
     * Basic 認証の状態を短い hash 化して返す。username またはパスワード保存値が変わると
     * hash も変わるため、cache key が自動的に別物になり旧キャッシュが論理的に無効化される。
     * wp_salt('auth') で keyed HMAC を取るので、保存値が sodium 不在時に平文化しても
     * cache key から元値をオフラインで推測することはできない。
     */
    private static function auth_state_hash() {
        $options = get_option(KSPB_OPTION_NAME, []);
        if (!is_array($options)) {
            $options = [];
        }
        $user = (string) ($options['auth_username'] ?? '');
        $pw_ref = (string) ($options['auth_password'] ?? '');
        $material = $user . '|' . $pw_ref;
        return substr(hash_hmac('sha256', $material, wp_salt('auth')), 0, 8);
    }

    /**
     * Basic認証ヘッダー
     */
    private $auth_headers = [];

    /**
     * コンストラクタ
     */
    public function __construct($options = []) {
        $username = $options['auth_username'] ?? '';
        // 保存値は暗号化されているため復号してからヘッダに使う (旧平文は透過的に扱う)
        $password = KSPB_Crypto::decrypt($options['auth_password'] ?? '');
        if ($username !== '' && $password !== '') {
            $this->auth_headers = [
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
            ];
        }
    }

    /**
     * 外部 HTTP リクエスト時に SSL 証明書検証を行うかどうか。
     *
     * デフォルトは true (本番想定)。WP_DEBUG=true の開発環境では自己署名等を
     * 通しやすくするため false。さらに `kspb_sslverify` フィルタで任意に上書き可能。
     * 返却値は必ず boolean。
     */
    private static function should_verify_ssl() {
        $default = !(defined('WP_DEBUG') && WP_DEBUG);
        return (bool) apply_filters('kspb_sslverify', $default);
    }

    /**
     * URLのステータスコードをチェック（リダイレクト先も含む）
     */
    public function check_url_status($url) {
        // 現在のページのURLはチェックしない（永久ループ防止）
        $current_url = esc_url_raw(home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '')));
        if ($url === $current_url || rtrim($url, '/') === rtrim($current_url, '/')) {
            return ['status' => 200, 'redirect_to' => null];
        }

        // キャッシュキーにはキャッシュバージョンを含める。clear_cache で version を
        // bump することで論理無効化され、object cache 側も TTL で自然消滅する。
        $cache_key = self::versioned_cache_key('status', $url);
        $cached_data = get_transient($cache_key);
        if ($cached_data !== false) {
            return $cached_data;
        }

        // HEADリクエストで高速チェック（リダイレクトを追跡しない）
        $response = wp_remote_head($url, [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,  // リダイレクトを追跡しない
            'user-agent' => 'KSPB Breadcrumbs Bot/1.0',
            'sslverify' => self::should_verify_ssl(),
            'headers' => $this->auth_headers
        ]);

        if (is_wp_error($response)) {
            return ['status' => 0, 'redirect_to' => null];
        }

        $status_code = wp_remote_retrieve_response_code($response);

        // リダイレクト先のLocationヘッダーを取得。
        // scheme-relative (//evil.example) / relative (new-page, ../path) / 大文字スキーム
        // (HTTP://) / javascript: / data: など多様な形式を WP_Http::make_absolute_url で
        // 一度絶対 URL に正規化してから、http/https + home_url() の同一ホストに限定する。
        // これで偶然の防御ではなく明示的なホワイトリスト判定になる。
        $location = wp_remote_retrieve_header($response, 'location');
        if (is_string($location)) {
            $location = trim($location);
        } else {
            $location = '';
        }

        if ($location !== '' && class_exists('WP_Http') && method_exists('WP_Http', 'make_absolute_url')) {
            $location = WP_Http::make_absolute_url($location, $url);
        }
        if (!is_string($location) || $location === '') {
            $location = null;
        }

        if ($location) {
            // parse_url は 不正な URL で false、component アクセスで warning を起こすため
            // is_array ガードで defensive に扱う。
            $parsed_loc = parse_url($location);
            $loc_scheme = (is_array($parsed_loc) && isset($parsed_loc['scheme']))
                ? strtolower($parsed_loc['scheme'])
                : '';
            $loc_host = (is_array($parsed_loc) ? ($parsed_loc['host'] ?? '') : '');
            $home_host = parse_url(home_url(), PHP_URL_HOST);
            // scheme は http/https 限定、host は home_url と同一のみ採用。
            // 不一致・不明・非 http(s) スキーム (javascript:, data:, file: 等) はすべて拒否。
            if (!in_array($loc_scheme, ['http', 'https'], true)
                || $loc_host === ''
                || !$home_host
                || strcasecmp($loc_host, $home_host) !== 0) {
                $location = null;
            }
        }



        $result = [
            'status' => $status_code,
            'redirect_to' => $location
        ];

        // 認証エラーはキャッシュしない（設定変更後に即反映させるため）
        if ($status_code !== 401 && $status_code !== 403) {
            set_transient($cache_key, $result, self::CACHE_DURATION);
        }

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

        // キャッシュキーにはキャッシュバージョンを含める (clear_cache の version bump で
        // 論理無効化)。negative sentinel なら失敗として null を返す。
        $cache_key = self::versioned_cache_key('title', $url);
        $cached_title = get_transient($cache_key);
        if ($cached_title !== false) {
            return ($cached_title === self::NEGATIVE_SENTINEL) ? null : $cached_title;
        }

        // Per-request サーキットブレーカ: 1 リクエストあたりの外部 HTTP 数を抑制し
        // 長い URL 攻撃などで自己 DoS に陥るのを防ぐ。cache miss 時のみインクリメント。
        if ($this->scrape_count >= self::MAX_SCRAPES_PER_REQUEST) {
            return null;
        }
        $this->scrape_count++;

        // HTTPリクエスト。redirection=0 で wp_remote_get 内部のリダイレクト追跡を禁止し、
        // SSRF の到達範囲を広げない (3xx レスポンスは空 body でネガティブキャッシュされる)。
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'user-agent' => 'KSPB Breadcrumbs Bot/1.0',
            'sslverify' => self::should_verify_ssl(),
            'headers' => $this->auth_headers
        ]);

        // 失敗系はすべて短期間の negative cache に入れ、同一 URL の連続攻撃で
        // 毎回外部 HTTP が走るのを防ぐ。
        if (is_wp_error($response)) {
            set_transient($cache_key, self::NEGATIVE_SENTINEL, self::NEGATIVE_CACHE_DURATION);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            set_transient($cache_key, self::NEGATIVE_SENTINEL, self::NEGATIVE_CACHE_DURATION);
            return null;
        }

        // タイトルタグを抽出
        $title = $this->extract_title($body);

        if ($title) {
            set_transient($cache_key, $title, self::CACHE_DURATION);
        } else {
            set_transient($cache_key, self::NEGATIVE_SENTINEL, self::NEGATIVE_CACHE_DURATION);
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

        // (1) キャッシュバージョンを bump = すべての kspb transient を論理的に無効化。
        // 次回 versioned_cache_key() は新バージョンのキーを返すため、以降 get_transient は
        // MISS となり再取得される。古い version のキーは object cache 上に残るが TTL で
        // 自然消滅する (Redis / Memcached も TTL を尊重する)。
        // この方式は registry + update_option の TOCTOU race や上限溢れの問題を回避する。
        $current = self::cache_version();
        update_option(self::CACHE_VERSION_OPTION, $current + 1, false);

        // (2) wp_options の kspb transient 行の物理削除 (DB-only 環境の掃除、
        // options テーブル肥大化防止)。LIKE の ESCAPE を 3 層で厳密化:
        //   PHP 文字列 `\\\\_%` → MySQL 受信 `\\_%` → LIKE 評価で `\_` (リテラル `_`)。
        //   ESCAPE 句自体も `'\\\\'` (PHP) → `'\\'` (MySQL) → ESCAPE 文字 `\`。
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_kspb\\\\_%' ESCAPE '\\\\'
                OR option_name LIKE '_transient_timeout_kspb\\\\_%' ESCAPE '\\\\'"
        );

        // wp_cache_flush() は使わない: 他プラグイン / WP 本体のキャッシュ全体を消す副作用を
        // 避けるため。object cache 側の kspb 旧キーはバージョン不一致で参照されず、TTL
        // 満了まで残るのみで実害なし。
    }
}

/**
 * 認証情報などの機密文字列を sodium_crypto_secretbox で暗号化保存するユーティリティ。
 *
 * wp_salt('auth') から派生した対称鍵で暗号化するため、同一サイト内で復号できる。
 * 暗号化済み値は "enc:" プレフィックス付きで保存。プレフィックスが無い値 (旧 1.0.5
 * までの平文) は自動的に平文として復号される (後方互換)。
 */
class KSPB_Crypto {

    const PREFIX = 'enc:';

    /**
     * 平文を暗号化して "enc:base64(nonce||cipher)" 形式で返す。
     * 空文字列はそのまま返す。sodium 拡張が無い環境・暗号化失敗時は false を返す。
     *
     * @return string|false 暗号化文字列、または失敗時 false
     */
    public static function encrypt($plaintext) {
        if ($plaintext === null || $plaintext === '') {
            return '';
        }
        if (!function_exists('sodium_crypto_secretbox') || !defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')) {
            return false;
        }
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox((string) $plaintext, $nonce, self::key());
        } catch (\Exception $e) {
            return false;
        }
        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * 暗号化文字列を復号。"enc:" プレフィックスが無い入力は平文として透過的に返す。
     * 復号失敗時は空文字列を返す。
     */
    public static function decrypt($stored) {
        if ($stored === null || $stored === '') {
            return '';
        }
        $stored = (string) $stored;
        if (strncmp($stored, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return $stored; // 旧バージョンの平文 (後方互換)
        }
        if (!function_exists('sodium_crypto_secretbox_open') || !defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES')) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());
        return $plain === false ? '' : $plain;
    }

    /**
     * 値が暗号文の「形式」を満たすかだけを判定する (実際の復号可否は問わない)。
     * - PREFIX 付き
     * - base64 decode 可能
     * - nonce + MAC の最低長を満たす
     *
     * true = format valid。valid だが復号不能の場合 (鍵/salt 変更、一時的な sodium 不在等)
     *   は元値を保持して将来の復旧可能性を残すために使う。
     * false = format invalid = 偶然 PREFIX で始まる平文、または壊れた文字列。migration 対象。
     */
    public static function is_valid_ciphertext_format($stored) {
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        $prefix_len = strlen(self::PREFIX);
        if (strncmp($stored, self::PREFIX, $prefix_len) !== 0) {
            return false;
        }
        if (!defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES') || !defined('SODIUM_CRYPTO_SECRETBOX_MACBYTES')) {
            return false;
        }
        $raw = base64_decode(substr($stored, $prefix_len), true);
        if ($raw === false) {
            return false;
        }
        $min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        return strlen($raw) >= $min;
    }

    private static function key() {
        return substr(hash('sha256', wp_salt('auth'), true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}

// プラグインの初期化
KashiwazakiSeoPerfectBreadcrumbs::get_instance();

// グローバル関数として提供
function kspb_display_breadcrumbs() {
    $instance = KashiwazakiSeoPerfectBreadcrumbs::get_instance();
    $options = $instance->get_options();

    // 表示条件をチェック。should_display_breadcrumbs() を public 化したため
    // ReflectionClass を介さず直接呼び出せる (ランタイムコスト + カプセル化解除 を回避)。
    if (!$instance->should_display_breadcrumbs($options)) {
        return;
    }

    echo $instance->render_breadcrumbs();
}
