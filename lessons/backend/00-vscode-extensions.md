# Lesson 0 おすすめのVSCode拡張機能

## はじめに

Laravel開発を効率的に進めるために、VSCodeの拡張機能を活用しましょう。
このレッスンでは、Laravel開発に役立つ3つの拡張機能を紹介します。

### 紹介する拡張機能

- Intelephense（PHP言語サーバー）
- Laravel（Laravel公式拡張機能）
- Git Graph（Gitの可視化ツール）


## Intelephense

PHPの開発をサポートする言語サーバーです。コード補完や定義へのジャンプなど、PHPを書くうえで欠かせない機能を提供します。

### 主な機能（無料版）

- コード補完
- シグネチャヘルプ（関数の引数表示）
- 定義へのジャンプ
- 参照の検索
- シンボル検索
- 診断機能（エラー検出）
- コード整形
- ホバー機能（変数や関数の情報表示）

### インストール

VSCodeの拡張機能タブで「Intelephense」を検索してインストールします。

### 参考

- [Intelephense公式サイト](https://marketplace.visualstudio.com/items?itemName=bmewburn.vscode-intelephense-client)


## Laravel（公式拡張機能）

Laravel公式が提供するVSCode拡張機能です。Laravelの各種機能に対して補完やジャンプが効くようになります。

### 主な機能

- `app('auth')`や`App::make()`などへの自動補完とジャンプ
- `config()`関数の補完とリンク
- Eloquentのメソッド、フィールド、リレーションシップの自動補完
- `view()`関数でのBladeビューへの自動補完とジャンプ
- `route()`関数やミドルウェア指定での補完
- 翻訳文字列、環境変数、バリデーションの補完
- Blade構文のハイライト

### 動作要件

PHP 8.2以上が必要です。初回インストール時にバイナリをダウンロードします。

### インストール

VSCodeの拡張機能タブで「Laravel」を検索し、Laravel公式（laravel.vscode-laravel）をインストールします。

### 参考

- [Laravel - Visual Studio Marketplace](https://marketplace.visualstudio.com/items?itemName=laravel.vscode-laravel)


## Git Graph

Gitのコミット履歴をグラフで視覚的に表示する拡張機能です。ブランチの流れやマージの状況を一目で把握できます。

### 主な機能

- ローカル・リモートブランチ、タグ、未コミット変更の視覚化
- コミット詳細の表示と2つのコミット間の比較
- 右クリックメニューからのGit操作（ブランチ作成、チェックアウト、マージなど）
- コミットメッセージの検索
- ブランチのフィルタリング

### 使い方

1. VSCodeのフッターにある「Git Graph」ボタンをクリック
2. コミット履歴がグラフで表示される
3. コミットをクリックすると詳細を確認できる

### インストール

VSCodeの拡張機能タブで「Git Graph」を検索してインストールします。

### 参考

- [Git Graph - Visual Studio Marketplace](https://marketplace.visualstudio.com/items?itemName=mhutchie.git-graph)

