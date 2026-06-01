# php-cs-fixer binary; override if it lives elsewhere, e.g.
#   make cs-check PHP_CS_FIXER=vendor/bin/php-cs-fixer
PHP_CS_FIXER ?= php-cs-fixer

.DEFAULT_GOAL := help
.PHONY: help all test cs-check cs-fix

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

all: cs-check test ## Run the coding-style check and the test suite

test: ## Run the test suite
	composer test

cs-check: ## Check coding style without changing files
	$(PHP_CS_FIXER) fix --dry-run --diff --verbose

cs-fix: ## Apply coding style fixes
	$(PHP_CS_FIXER) fix --verbose
