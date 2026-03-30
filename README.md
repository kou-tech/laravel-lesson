# laravel-lesson

Laravelの学習用プロジェクトです。

## 必要な環境

- Docker
- Make
- Windows の場合は WSL2 + Docker Desktop

## セットアップ

### macOS の場合

```bash
git clone https://github.com/kou-tech/laravel-lesson.git
cd laravel-lesson
make setup
```

### Windows（WSL2）の場合

#### 1. WSL2 をインストール

管理者権限で PowerShell を開き、以下を実行します。

```powershell
wsl --install
```

これで WSL2 と Ubuntu がまとめてインストールされます。

> **補足:** Windows 10（バージョン 2004 以降）または Windows 11 が必要です。BIOS で仮想化が有効になっていることを確認してください。

#### 2. Docker Desktop をインストール

[Docker Desktop](https://www.docker.com/products/docker-desktop/) をインストールし、設定画面で 「Use the WSL 2 based engine」が有効になっていることを確認します。

#### 3. WSL 内で clone・セットアップ

Ubuntu ターミナルを開き、以下を実行します。必ず WSL 内のファイルシステムに clone してください。

```bash
# WSL のホームディレクトリに clone する（/mnt/c/ 以下は避ける）
cd ~
git clone https://github.com/kou-tech/laravel-lesson.git
cd laravel-lesson
make setup
```

> なぜ `/mnt/c/`（Cドライブ）ではなく WSL 内に clone するのか？
>
> WSL と Windows は独立したファイルシステムを持っています。`/mnt/c/` は Windows の C ドライブを WSL からマウントしたもので、ファイルシステム間の変換が毎回走るためI/O が非常に遅くなります。WSL 内（`/home/...`）であれば Linux がファイルを直接管理するため高速です。

#### 4. VS Code で開発する

VS Code に [Remote - WSL](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.vscode-remote-extensionpack) 拡張機能をインストールすると、Windows 側の VS Code から WSL 内のファイルを直接編集できます。

```bash
# WSL ターミナルからプロジェクトを VS Code で開く
code .
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
