# laravel-lesson

Laravelの学習用プロジェクトです。

## 必要な環境

- PHP 8.2 以上
- Composer
- Node.js / npm
- SQLite

## Mac での環境構築手順

### 1. Homebrew のインストール（未導入の場合）

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

### 2. 必要なツールのインストール

```bash
brew install php composer node
```

### 3. リポジトリのクローン

```bash
git clone https://github.com/kou-tech/laravel-lesson.git
cd laravel-lesson
```

### 4. セットアップ

```bash
composer run setup
```

このコマンドで以下が一括実行されます。

- `composer install` — PHP 依存パッケージのインストール
- `.env.example` → `.env` のコピー
- アプリケーションキーの生成
- データベースマイグレーション
- `npm install` — Node.js 依存パッケージのインストール
- `npm run build` — フロントエンドのビルド

## 開発サーバーの起動

```bash
composer run dev
```

サーバー、キュー、ログ (Pail)、Vite がまとめて起動します。

アクセス URL: http://localhost:8000

## テスト実行

```bash
composer run test
```

## その他のコマンド

| コマンド | 内容 |
|---|---|
| `composer run format` | コードフォーマット (Pint) |
