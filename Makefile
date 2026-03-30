.PHONY: setup build init up down restart logs app fresh seed test lint format help

help: ## ヘルプを表示
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

# セットアップ
setup: build init ## 初回セットアップ

build: ## イメージをビルド
	docker compose build

init: up ## 依存インストール・マイグレーション
	@until docker compose exec app php artisan --version > /dev/null 2>&1; do \
		echo "⏳ 準備中... 10秒後にリトライ"; \
		sleep 10; \
	done
	@echo "✅ http://localhost:8000"

# コンテナ操作
up: ## 起動
	docker compose up -d

down: ## 停止
	docker compose down -v

restart: down up ## 再起動

logs: ## ログ表示
	docker compose logs -f

app: ## コンテナに入る
	docker compose exec app bash

# Laravel
fresh: ## DB初期化 + シーダー
	docker compose exec app php artisan migrate:fresh --seed

seed: ## シーダー実行
	docker compose exec app php artisan db:seed

test: ## テスト
	docker compose exec app php artisan test

# コード品質
lint: ## ESLint
	docker compose exec app npx eslint .

format: ## Pint + Prettier
	docker compose exec app ./vendor/bin/pint -v
	docker compose exec app npx prettier --write resources/
