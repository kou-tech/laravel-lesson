# F0 準備

## 学習目標

フロントエンドのレッスンを始める前に、バックエンドで作ったものがそろっているかを確かめ、練習用のデータを入れて、画面を開けるようにします。あわせて、画面の開発で毎回使う Vite の開発サーバーと Wayfinder、ブラウザの React Developer Tools の役割を確認します。

### 到達目標
- バックエンドで作ったもののうち、フロントエンドのレッスンで使うものがそろっているかを確かめられる
- 練習用のデータを入れ、生徒と講師で画面にログインできる
- 画面が表示されないときに、Vite の開発サーバーと Wayfinder のどちらを疑えばよいか分かる
- React Developer Tools で、ページが受け取った props を見られる

## このレッスンでやること

フロントエンドのレッスンでは、バックエンドの Lesson 14 で作ったサービスクラス（AttendCourse）や、Lesson 18 の受講キャンセル（CancelAttendance）を、画面から呼び出します。ボス戦3まで終えたブランチで作業を続けてください。

Step 1〜2 でバックエンドとデータの準備を確かめ、Step 3〜5 で画面を開くまでの仕組みを確認します。Step 6〜7 は、このあとのレッスンで使う道具と、見た目の作り方の扱いの説明です。

## Step 1 バックエンドの準備を確かめる

まず判定を実行して、バックエンドで作ったものがそろっているかを確かめます。

```bash
make quest-f0
```

[前提] のミッションでは、フロントエンドのレッスンで使うバックエンドの機能と、型の検査が通ることを確かめます。バックエンドについては、講座一覧API と、受講登録とキャンセルのサービスクラスなどがあるかを見ています。❌ が出たら、ヒントに書かれたレッスンに戻って直してください。型の検査が ❌ のときは、Step 5 で Wayfinder のファイルを生成してから、もう一度実行してください。

[メイン] のミッション（練習用のデータが入っていること）は、この時点ではまだ ❌ です。Step 2 を進めると ✅ になります。

## Step 2 練習用のデータを入れる

フロントエンドのレッスンでは、満席の講座や、開始が近くてキャンセルの締切を過ぎた受講など、画面の動きを確かめるためのデータを使います。次のコマンドで入れてください。

```bash
make seed-frontend
```

中身は `database/seeders/FrontendLessonSeeder.php` です。講師の test2@example.com の講座として、次の6件が入ります。

| 講座 | 状態 | 用途 |
|---|---|---|
| React 入門 | 公開中、定員20 | 受講登録を試す |
| TypeScript 実践（満席） | 公開中、定員2、2人が受講中 | 満席のときの表示と、受講登録の失敗 |
| Inertia でつくる画面（開始間近） | 公開中、2日後に開始、test@example.com が受講中 | 締切を過ぎてキャンセルできない受講 |
| コンポーネント設計（受講中） | 公開中、14日後に開始、test@example.com が受講中 | キャンセルできる受講 |
| 非同期処理の設計（下書き） | 下書き | 絞り込みで使う |
| JavaScript 基礎（募集終了） | 終了 | 絞り込みで使う |

満席の講座を埋めるために、練習用の生徒 student1@example.com と student2@example.com も作られます。

`make seed-frontend` は何度実行しても、同じ講座や受講が重複しません。`make fresh` でデータベースを作り直したときは、DatabaseSeeder のあとにもう一度実行してください。

> 補足: 画面にログインするときは、生徒の test@example.com か、講師の test2@example.com を使います。パスワードはどちらも `password` です（DatabaseSeeder で作ったユーザーです）。

## Step 3 画面を開く

ブラウザで http://localhost:8000 を開きます。右上の「Log in」から test@example.com でログインし、ダッシュボードが表示されることを確かめてください。

画面は必ず `localhost` で開いてください。`127.0.0.1:8000` で開いても画面は表示されますが、画面からログインが必要な API（`auth:sanctum` のルート）を呼ぶと、すべて 401 になります。

理由は、Lesson 6 で見た Sanctum の SPA セッション認証の設定にあります。Sanctum は、`.env` の `SANCTUM_STATEFUL_DOMAINS` に書かれたドメインから来たリクエストだけを、ブラウザのログイン（セッション）で認証します。このプロジェクトでは `localhost:8000` だけが書かれているので、`127.0.0.1:8000` から来たリクエストはログインしていない扱いになります。また、ブラウザは `localhost` と `127.0.0.1` を別のサイトとして扱うため、片方でログインしても、もう片方ではログインしていない状態です。

```
SANCTUM_STATEFUL_DOMAINS=localhost:8000
```

> 補足: ブックマークやブラウザの履歴から開くと、以前の `127.0.0.1` のアドレスになっていることがあります。401 が出たら、まずアドレスバーを確かめてください。

## Step 4 Vite の開発サーバーを確かめる

画面のコード（`resources/js` の tsx ファイル）は、ブラウザがそのまま読める形ではありません。開発中は Vite の開発サーバーが、ブラウザから要求されたファイルをその場で変換して返します。ファイルを保存すると画面が自動で更新されるのも、Vite の働きです。

このプロジェクトでは、`make up` でコンテナを起動すると、`php artisan serve` と一緒に `npm run dev`（Vite の開発サーバー）も起動し、常駐します。動いているかどうかは、コンテナのログで確かめられます。

```bash
make logs
```

`[vite]` で始まる行が出ていれば動いています。確かめたら Ctrl+C でログの表示を終えてください。

Vite の開発サーバーが止まっているときにページを開くと、次のエラーになります。

```
Unable to locate file in Vite manifest: resources/js/pages/xxx.tsx.
```

開発サーバーが動いていないと、Laravel は代わりに `npm run build` で作った `public/build/manifest.json` からファイルを探します。ビルドしたあとに足したページはこの一覧に入っていないので、見つからずにエラーになります。このエラーが出たら、`docker compose ps` でコンテナが動いているかを確かめ、止まっていれば `make up` で起動してください。

> 補足: 自分で書いた Pest のテストでページを開くときも、Vite の開発サーバーが止まっていると同じエラーになります。判定のテストは Vite を使わない設定にしているので、この影響を受けません。

## Step 5 Wayfinder のファイルを生成する

このプロジェクトの画面では、URL を文字列で書かず、Wayfinder が生成した関数を使います。たとえばダッシュボードのページ（`resources/js/pages/dashboard.tsx`）は、次のように `@/routes` から `dashboard` を読み込んで、URL を作っています。

```tsx
import { dashboard } from '@/routes';

const url = dashboard().url; // '/dashboard'
```

Wayfinder は `routes/web.php` などのルートとコントローラーを読み、`resources/js/routes` と `resources/js/actions` に TypeScript のファイルを生成します。生成したファイルは git の管理から外しているので、clone した直後や、ルートを足した直後には、ファイルがないか古くなっていることがあります。そのときは、型の検査（`npm run types`）が次のエラーで落ちます。

```
Cannot find module '@/routes' or its corresponding type declarations.
```

このエラーが出たら、Wayfinder のファイルを生成し直してから、もう一度型の検査を実行します。

```bash
docker compose exec app php artisan wayfinder:generate --with-form
docker compose exec app npm run types
```

`--with-form` は、F4 で使う `<Form>` 向けの関数も一緒に生成するオプションです。何もエラーが出ずに終われば準備できています。

> 補足: Vite の開発サーバーが動いているあいだは、ルートを書き換えると Wayfinder のファイルも自動で生成し直されます。手で生成するのは、エラーが出たときだけで構いません。

## Step 6 React Developer Tools を入れる

React Developer Tools は、画面がどの部品（コンポーネント）でできていて、それぞれがどんな props と state を持っているかを見られるブラウザの拡張機能です。Lesson 2 で使った Log や `dd()` の画面版のようなもので、F3 以降で「表示がおかしいときに、まず値を確かめる」ために使います。

Chrome なら Chrome ウェブストアで「React Developer Tools」を検索し、Meta（旧 Facebook）が提供しているものを入れてください。Firefox と Edge にも同じ名前の拡張機能があります。

入れたら、ダッシュボードを開いた状態でデベロッパーツールを開きます（Mac は Cmd+Option+I、Windows は F12）。タブに「Components」が増えているので、開いて左の一覧から `Dashboard` を選ぶと、右側にそのページが受け取った props が表示されます。`auth` の中の `user` を開き、`email` が test@example.com になっていることを確かめてください。

この `auth.user` は、ダッシュボードのコントローラーが渡したものではありません。`app/Http/Middleware/HandleInertiaRequests.php` の `share()` で、すべてのページに渡している値です（共有 props と呼びます。F4 で扱います）。

## Step 7 Tailwind CSS と components/ui の扱い

このプロジェクトの画面の見た目は、Tailwind CSS で作っています。Tailwind CSS では、CSS ファイルを書く代わりに、`className` に用意されたクラス名を並べて見た目を指定します。

```tsx
<div className="flex items-center gap-2 rounded-lg border p-4">...</div>
```

`flex` は横に並べる、`gap-2` は間をあける、`p-4` は内側に余白をとる、という意味です。

`resources/js/components/ui` には、ボタン（`button.tsx`）、カード（`card.tsx`）、バッジ（`badge.tsx`）、ダイアログ（`dialog.tsx`）などの既製の部品が入っています。これは shadcn/ui という部品集から、このプロジェクトに写したものです。

このレッスンでは、Tailwind CSS のクラス名と components/ui の部品は「使うだけ」にします。本文のクラス名はそのまま写して構いませんし、覚える必要もありません。components/ui の部品と、自分で作る業務の部品をどう分けるかは、F6 で扱います。

> 補足: VSCode に拡張機能「Tailwind CSS IntelliSense」を入れると、クラス名の補完と、そのクラスが表す CSS の表示ができるようになります。

## クリア判定

ここまでできたら、もう一度判定を実行しましょう。

```bash
make quest-f0
```

練習用のデータが入っていること（Step 2）がメインのミッションです。[前提] のミッションでは、フロントエンドのレッスンで使うバックエンドの機能と、Wayfinder のファイルがあって型の検査が通ること（Step 5）を確かめています。型の検査を [前提] にしているのは、Vite の開発サーバーが常駐していれば、Wayfinder のファイルが自動で生成されるためです。最後に ⬜ で並ぶセルフチェックは、http://localhost:8000 でログインできること（Step 3）と、React Developer Tools で `auth.user` が見られること（Step 6）です。

## 参考資料

- [Inertia.js 公式ドキュメント](https://inertiajs.com/)
- [Laravel 公式ドキュメント - Asset Bundling（Vite）](https://laravel.com/docs/vite)
- [Laravel Wayfinder](https://github.com/laravel/wayfinder)
- [React 公式ドキュメント - React Developer Tools](https://ja.react.dev/learn/react-developer-tools)
- [Tailwind CSS 公式ドキュメント](https://tailwindcss.com/docs)

## 次のレッスン

[F1 React に必要な JavaScript](./01-javascript.md) では、PHP と比べながら、React のコードを読み書きするのに必要な JavaScript の書き方を学びます。
