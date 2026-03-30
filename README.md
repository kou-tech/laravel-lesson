# laravel-lesson

Laravelの学習用プロジェクトです。

## 必要な環境

- Docker
- Make
- Windows の場合は WSL2 + Docker Desktop

## セットアップ

```bash
git clone https://github.com/kou-tech/laravel-lesson.git
cd laravel-lesson
make setup
```

初回は Docker イメージのビルドと依存パッケージのインストールが行われます。

完了後、以下の URL でアクセスできます。

- アプリ: http://localhost:8000
- Vite (HMR): http://localhost:5173

## よく使うコマンド

| コマンド | 内容 |
|---|---|
| `make setup` | 初回セットアップ |
| `make up` | コンテナ起動 |
| `make down` | コンテナ停止 |
| `make restart` | コンテナ再起動 |
| `make logs` | ログ表示 |
| `make app` | コンテナに入る |
| `make fresh` | DB初期化 + シーダー |
| `make seed` | シーダー実行 |
| `make test` | テスト実行 |
| `make lint` | ESLint |
| `make format` | Pint + Prettier |
| `make help` | コマンド一覧 |
