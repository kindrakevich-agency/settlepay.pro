# Settle developer convenience.
# Run `make help` for the full list.

DC      := docker compose
PHP     := $(DC) exec php
NODE    := $(DC) exec node
CONSOLE := $(PHP) php bin/console

.DEFAULT_GOAL := help

##@ Stack

up: ## Start the local stack (db, redis, php-fpm, nginx)
	$(DC) up -d db redis php nginx node
	@echo
	@echo "  → http://localhost:8080  (Symfony)"
	@echo "  → http://localhost:5173  (Vite dev — run 'make dev-front')"

down: ## Stop and remove containers
	$(DC) down

logs: ## Tail logs (set s=php|nginx|db|node)
	$(DC) logs -f $(s)

ps: ## Show running services
	$(DC) ps

##@ Setup

install: ## First-time install (composer + pnpm)
	$(PHP) composer install
	$(NODE) corepack prepare pnpm@9.12.0 --activate
	$(NODE) pnpm install
	$(MAKE) db-create
	$(MAKE) migrate

reinstall: ## Wipe vendor/node_modules and reinstall
	$(PHP) rm -rf vendor
	$(NODE) rm -rf node_modules
	$(MAKE) install

##@ Symfony

shell: ## Drop into the PHP container
	$(PHP) sh

console: ## Run a Symfony console command (e.g. make console c="cache:clear")
	$(CONSOLE) $(c)

migrate: ## Run pending migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

migration: ## Generate a new migration from entity diff
	$(CONSOLE) make:migration

db-create: ## Create the dev database
	$(CONSOLE) doctrine:database:create --if-not-exists

db-reset: ## Drop & recreate the dev database (destructive)
	$(CONSOLE) doctrine:database:drop --force --if-exists
	$(CONSOLE) doctrine:database:create
	$(MAKE) migrate

cc: ## Clear Symfony cache
	$(CONSOLE) cache:clear

test: ## Run PHPUnit
	$(PHP) vendor/bin/phpunit

##@ Frontend

dev-front: ## Start Vite dev server (HMR for /assets)
	$(NODE) pnpm dev

build-front: ## Build production assets to public/build/
	$(NODE) pnpm build

typecheck: ## TypeScript strict typecheck
	$(NODE) pnpm typecheck

##@ Workers

worker: ## Run a foreground messenger worker
	$(CONSOLE) messenger:consume async -vv

listener: ## Run the on-chain listener (testnet by default)
	$(CONSOLE) app:chain:listen --testnet

##@ Help

help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z_0-9-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

.PHONY: up down logs ps install reinstall shell console migrate migration db-create db-reset cc test dev-front build-front typecheck worker listener help
