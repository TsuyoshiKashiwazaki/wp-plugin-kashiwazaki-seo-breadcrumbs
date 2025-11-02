# Kashiwazaki SEO Perfect Breadcrumbs

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.2--dev-orange.svg)](https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/releases)

革新的なURL構造解析でパンくずを自動生成！WordPressの階層に依存せず、実際のURL構造から正確な階層を構築する次世代パンくずリストプラグイン。

> **URL構造をベースにした革新的な階層解析エンジンで、サイトの真の階層構造を可視化**

## 最大の特徴：URL構造ベースの階層解析

従来のWordPressパンくずプラグインは、投稿の親子関係やカテゴリー構造に依存していました。しかし、実際のサイトではURLと内部構造が一致しないことが多く、ユーザーが期待する階層とは異なるパンくずが表示される問題がありました。

**本プラグインの革新的アプローチ:**
- **実際のURL構造を解析** - `/company/about/team/` → 会社情報 > 会社概要 > チーム
- **WordPressの階層に依存しない** - 固定ページの親子関係に縛られない
- **直感的な階層表示** - URLパスがそのままユーザーが理解しやすい階層に
- **外部ディレクトリも認識** - WordPress外のディレクトリも階層に含められる

### 動作例

```
URL: https://example.com/products/electronics/smartphones/iphone/

従来プラグイン: ホーム > 投稿 > iPhone（カテゴリー依存）
本プラグイン: ホーム > 製品 > 家電 > スマートフォン > iPhone（URL構造から自動生成）
```

## 主な機能

### 1. インテリジェントURL解析
- URLパスから自動的に階層構造を構築
- 各階層のタイトルを自動取得（スクレイピング機能）
- サブディレクトリインストールでも正確に動作
- 最大10階層まで対応（無限ループ防止）

### 2. 高度なエラー処理
- 404エラーを自動検出して代替URLを使用
- 301/302リダイレクトを自動追跡
- 存在しないページは自動的にスキップ
- 24時間のキャッシュ機能で高速化

### 3. 完全なSEO対応
- Schema.org構造化データ（JSON-LD）の自動生成
- Google検索結果でのリッチスニペット表示対応
- URL構造とパンくずの一致によるSEO効果最大化

### 4. 柔軟な表示制御
- 投稿タイプごとの表示制御
- 自動挿入機能（上部/下部/両方）
- ショートコード対応 `[kspb_breadcrumbs]`
- テーマファイル用関数 `kspb_display_breadcrumbs()`

### 5. デザイン・カスタマイズ
- 3種類のデザインパターン（クラシック、モダン、角丸）
- カスタマイズ可能なフォントサイズ（10px〜24px）
- SVGアイコン付きの視覚的な表示
- レスポンシブデザイン完全対応

## 最適な使用シーン

- **複雑なURL構造のサイト**: `/services/web-design/portfolio/` のような深い階層を正確に表現
- **カスタム投稿タイプ中心のサイト**: WordPressの内部構造に関係なくURL通りの階層を表示
- **ランディングページ多用サイト**: WordPress外のディレクトリも含めて統一的なパンくず表示
- **サブディレクトリインストール**: `/blog/` や `/shop/` などのサブディレクトリでも正確に動作
- **多言語サイト**: `/en/about/` や `/ja/about/` のような言語別URLでも適切に階層化

## クイックスタート

### 自動インストール

1. WordPress管理画面にログイン
2. 「プラグイン」→「新規追加」を選択
3. 「Kashiwazaki SEO Perfect Breadcrumbs」を検索
4. 「今すぐインストール」→「有効化」をクリック

### 手動インストール

1. このリポジトリをダウンロード
2. `kashiwazaki-seo-breadcrumbs`フォルダを`/wp-content/plugins/`ディレクトリにアップロード
3. WordPress管理画面の「プラグイン」メニューから有効化

## 使い方

### 方法1: 自動挿入（推奨）
設定画面で投稿タイプを選択するだけで自動的に表示されます。

### 方法2: テーマファイルに追加
```php
<?php 
if (function_exists('kspb_display_breadcrumbs')) {
    kspb_display_breadcrumbs();
} 
?>
```

### 方法3: ショートコード
```
[kspb_breadcrumbs]
```

## 設定

管理画面の「Kashiwazaki SEO Perfect Breadcrumbs」メニューから以下の設定が可能：

- **表示設定**: 表示位置、投稿タイプ、ホームリンクなど
- **デザイン設定**: パターン、フォントサイズ、区切り文字
- **機能設定**: URLスクレイピング、キャッシュ管理

## 技術仕様

### 必要環境
- WordPress 5.0以上
- PHP 7.2以上
- MySQL 5.6以上

### 対応ブラウザ
- Chrome（最新版）
- Firefox（最新版）
- Safari（最新版）
- Edge（最新版）

## 更新履歴

### Version 1.0.2 (2025-11-02)
- アーカイブページ表示制御機能を追加
- カスタム投稿タイプアーカイブの個別制御を追加
- 「すべてのページで表示」シンプルモードを追加（デフォルトON）
- 詳細設定の折りたたみUIを追加
- セクションごとの一括選択/解除ボタンを追加
- グリッドレイアウトで管理画面を改善
- カスタム投稿タイプアーカイブと個別投稿を分離
- イレギュラーなカスタムアーカイブページ（poll/datasets等）に対応
- 構造化データ出力に設定チェックを追加
- ショートコード・テーマ関数に設定チェックを追加
- 構造化データ出力をHTMLコメントで識別可能に

### Version 1.0.1 (2025-10-23)
- サブディレクトリインストール時のURL構造解析を修正
- ホームURLがドメインルートを正しく指すように修正
- WordPressインストールディレクトリもパンくず階層に含めるよう改善
- URL構造の完全な解析により正確な階層表示を実現

### Version 1.0.0 (2025-09-21)
- 初回リリース
- 革新的なURL構造ベースの階層解析エンジン実装
- 404エラー自動回避機能
- Schema.org構造化データ対応
- 3種類のデザインパターン
- 24時間キャッシュ機能
- サブディレクトリインストール完全対応
- 制作者クレジット機能追加（SoftwareApplication構造化データ）

詳細は[CHANGELOG.md](CHANGELOG.md)をご覧ください。

## ライセンス

GPL-2.0-or-later

## サポート・開発者

**開発者**: 柏崎剛 (Tsuyoshi Kashiwazaki)
**ウェブサイト**: https://www.tsuyoshikashiwazaki.jp/
**サポート**: プラグインに関するご質問や不具合報告は、開発者ウェブサイトまでお問い合わせください。

## 貢献

プルリクエストは歓迎です。大きな変更の場合は、まずissueを開いて変更内容について議論してください。

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the Branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## サポート

- **公式サイト**: https://www.tsuyoshikashiwazaki.jp
- **サポートフォーラム**: WordPress.orgのサポートフォーラム
- **バグ報告**: [GitHub Issues](https://github.com/TsuyoshiKashiwazaki/wp-plugin-kashiwazaki-seo-breadcrumbs/issues)

---

<div align="center">

**Keywords**: WordPress, Breadcrumbs, SEO, URL Structure, Schema.org, Structured Data, Navigation, パンくずリスト, 構造化データ

Made by [Tsuyoshi Kashiwazaki](https://github.com/TsuyoshiKashiwazaki)

</div>