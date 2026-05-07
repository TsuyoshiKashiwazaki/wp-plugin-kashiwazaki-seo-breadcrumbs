# Changelog

All notable changes to Kashiwazaki SEO Perfect Breadcrumbs will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.8] - 2026-05-07

### Added
- タイトル分割の区切り文字配列に**全角縦棒「｜」(U+FF5C)**、**全角コロン「：」(U+FF1A)**、**全角ダッシュ「―」(U+2015)** を追加。日本語サイトの `<title>` で多用される全角区切りで分割されず、パンくずに記事タイトル全文（サイト名込み）が表示されてしまう問題を解消（GitHub Issue #2）
  - `KSPB_Breadcrumb_Builder::build_from_url_path()` の現在ページフォールバック処理: `｜`, `―` を追加
  - `KSPB_URL_Scraper::extract_title()` の外部 URL タイトル抽出: `｜`, `：`, `―` を追加
  - 全角文字を配列先頭に配置することで、日本語タイトルでの優先マッチを実現
  - 既存の半角区切り文字 (`|`, `-`, `–`, `—`, `:`, `»`, `·`) は維持し、後方互換性を保証

## [1.0.7] - 2026-04-18

### Security
- `$_SERVER['REQUEST_URI']` の全使用箇所（3箇所）に `wp_unslash()` を適用。URL構築箇所には `esc_url_raw()` を追加（入力時サニタイズによる多層防御）
- `KSPB_Crypto::encrypt()` を平文フォールバックからフェイルクローズに変更。sodium 拡張未導入時は `false` を返し、呼び出し元で既存の暗号化済み値を維持。管理画面に `add_settings_error()` でエラー通知を表示

## [1.0.6] - 2026-04-18

### Security
- Basic認証のパスワードを sodium_crypto_secretbox（wp_salt 派生鍵）で暗号化して保存。旧バージョンの平文保存値は初回読込時に暗号化形式へ自動移行（1.0.5 以前→1.0.6 のアップグレード経路のみ）。PHP sodium 拡張が利用できない環境では平文のまま保存される（将来 sodium 有効化後に新規入力値は暗号化対象）
- 外部URLタイトル取得時に wp_remote_get のリダイレクト自動追跡を無効化（`redirection=0`）し、内部ネットワークへの意図しないアクセスを遮断（SSRF 対策）
- transient キャッシュキーに認証状態の HMAC ハッシュ（wp_salt keyed）を組み込み、認証情報変更時に古いキャッシュを論理的に無効化
- sslverify は WP_DEBUG=false 環境でデフォルト有効、WP_DEBUG=true 環境でデフォルト無効（開発環境の自己署名証明書対応）。任意環境から `kspb_sslverify` フィルタで上書き可能

### Fixed
- **キャッシュクリアボタンを押してもキャッシュが消えない問題を修正** — 旧実装はオブジェクトキャッシュのみをフラッシュしていたため、wp_options テーブルに保存された transient が残留していた。新実装ではバージョン番号による論理無効化と wp_options からの物理削除を併用
- プラグイン削除（uninstall）時にオプションと transient が残留する問題を修正（マルチサイト環境では全サイトで cleanup を実行）
- `the_content` フィルター経由でパンくずが管理画面・フィード・REST API・AJAX で意図せず挿入される問題を修正
- `$_POST` データ処理における wp_unslash の適用箇所を整理し、WordPress 規約に沿った正しい位置で 1 回のみ適用するよう修正
- `parse_url()` の戻り値が false / キー欠損の場合に PHP warning が出る問題を修正
- 外部URLスクレイピングが無制限に行われる可能性を 3 層防御（階層の深さ制限・ネガティブキャッシュ・1 リクエストあたりの上限）で対策

### Changed
- 管理画面の設定ページに `current_user_can('manage_options')` の明示チェックを追加（多層防御）
- 管理画面の JavaScript ハンドラーを inline `onclick=` / `onchange=` から data 属性 + addEventListener ベースに変更（CSP 互換性向上）
- post_types 設定の検証にホワイトリスト方式（`get_post_types(['public' => true])`）を追加
- Basic認証ユーザー名にコロン文字が含まれる場合のバリデーション処理を追加（RFC 7617 準拠）
- フォントサイズ入力値のサーバー側クランプ処理を追加（min/max 範囲外の値を無害化）
- 管理画面ファイルの読み込みを純粋なクラス定義のみに限定し、副作用を分離
- 内部データスキーマバージョン管理のためのマイグレーション枠組みを整備

### Removed
- 使用されていないデッドコード約 563 行を削除（旧 breadcrumb builder の非推奨関数群）
- `wp_cache_flush()` の全体キャッシュフラッシュ呼び出しを廃止（他プラグインへの副作用を防止）
- Reflection API を使った private メソッド呼び出しを廃止（設計見直しで不要化）
- URL スクレイパ内の未使用定数 `KSPB_URL_Scraper::MAX_DEPTH` を削除（階層制限は `KSPB_Breadcrumb_Builder::MAX_DEPTH` と per-request 上限で担保されている）

## [1.0.5] - 2026-02-28

### Added
- Basic認証環境でのURLスクレイピング対応
- 管理画面にBasic認証のユーザー名・パスワード設定欄を追加
- KSPB_URL_Scraperクラスにコンストラクタを追加し、認証情報を受け取る設計に変更
- wp_remote_head / wp_remote_get にAuthorizationヘッダーを付与する機能を実装
- 401/403レスポンスをキャッシュしないよう修正（認証設定変更後に即反映）

## [1.0.4] - 2025-12-04

### Fixed
- タグアーカイブ（/tag/）などでパンくずが正しく生成されない問題を修正
- WordPressの状態関数（is_home等）に依存せず、純粋にURL構造からパンくずを生成するよう改善

## [1.0.3] - 2025-11-19

### Fixed
- 日本語パーマリンク（マルチバイト文字URL）で404エラーが発生する問題を修正
- URLエンコーディングの大文字小文字を統一（%E3 → %e3）してリンク不一致を解消

### Added
- 投稿（post）とカスタム投稿タイプの個別記事タイトル取得処理を追加

### Improved
- URLセグメントのデコード処理を実装してWordPress内部検索を最適化
- パーセントエンコーディング小文字化関数を追加してURL一貫性を向上

## [1.0.2] - 2025-11-02

### Added
- アーカイブページ表示制御機能を追加
- カスタム投稿タイプアーカイブの個別制御を追加
- 「すべてのページで表示」シンプルモードを追加（デフォルトON）
- 詳細設定の折りたたみUIを追加
- セクションごとの一括選択/解除ボタンを追加
- グリッドレイアウトで管理画面を改善

### Fixed
- カスタム投稿タイプアーカイブと個別投稿を分離
- イレギュラーなカスタムアーカイブページ（poll/datasets等）に対応
- 構造化データ出力に設定チェックを追加
- ショートコード・テーマ関数に設定チェックを追加

### Improved
- 構造化データ出力をHTMLコメントで識別可能に

## [1.0.1] - 2025-10-23

### Fixed
- サブディレクトリインストール時のURL構造解析を修正
- ホームURLがドメインルートを正しく指すように修正

### Improved
- WordPressインストールディレクトリもパンくず階層に含めるよう改善
- URL構造の完全な解析により正確な階層表示を実現

## [1.0.0] - 2025-09-21

### 🎉 Initial Release

### Added
- **Revolutionary URL Structure-based Hierarchy Parsing Engine** - Analyzes actual URL paths to build breadcrumb hierarchy
- **Intelligent URL Analysis** - Automatically constructs hierarchy from URL structure without depending on WordPress internal structure
- **404 Error Auto-avoidance** - Automatically detects broken links and uses alternative URLs
- **301/302 Redirect Tracking** - Follows redirects to ensure valid breadcrumb links
- **Schema.org Structured Data Support** - Automatically generates JSON-LD structured data for SEO
- **Creator Credit Feature** - Outputs software creator information as structured data (SoftwareApplication schema)
- **URL Scraping Feature** - Automatically fetches page titles from URLs
- **24-hour Cache System** - Improves performance by caching URL check results and titles
- **Cache Clear Function** - One-click cache clearing from admin panel
- **3 Design Patterns** - Classic, Modern, and Rounded styles
- **Customizable Font Size** - Adjustable from 10px to 24px
- **SVG Icons** - Visual hierarchy indicators with home, folder, and page icons
- **Auto-insertion Feature** - Automatically adds breadcrumbs to selected post types (top/bottom/both)
- **Shortcode Support** - `[kspb_breadcrumbs]` for flexible placement
- **Theme Function** - `kspb_display_breadcrumbs()` for direct theme integration
- **Subdirectory Installation Support** - Works perfectly with WordPress installed in subdirectories
- **External Directory Recognition** - Includes non-WordPress directories in hierarchy
- **Infinite Loop Prevention** - Maximum depth of 10 levels to prevent infinite recursion
- **Responsive Design** - Mobile-friendly breadcrumb display
- **Japanese Language Support** - Full support for Japanese text and UI

### Technical Features
- URL path-based hierarchy construction
- WordPress hierarchy independence
- Support for custom post types
- Category, tag, and archive page support
- Multi-site compatibility
- GPL-2.0-or-later license

### Performance
- Optimized URL checking with HEAD requests
- Efficient caching mechanism
- Minimal database queries
- Lightweight CSS and no JavaScript dependencies

---

[1.0.8]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.8
[1.0.7]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.7
[1.0.6]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.6
[1.0.5]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.5
[1.0.4]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.4
[1.0.3]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.3
[1.0.2]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.2
[1.0.1]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.1
[1.0.0]: https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases/tag/v1.0.0