PHP_BIN=php
PHPUNIT=vendor/bin/phpunit
PHPCSFIXER=vendor/bin/php-cs-fixer
PSALM=vendor/bin/psalm

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

.PHONY: test
test: ## Run unit tests
	$(PHP_BIN) $(PHPUNIT)

.PHONY: cs-fix
cs-fix: ## Run php-cs-fixer to fix code style
	$(PHP_BIN) $(PHPCSFIXER) fix --config=.php-cs-fixer.dist.php --allow-risky=yes

.PHONY: cs-check
cs-check: ## Run php-cs-fixer in dry-run mode
	$(PHP_BIN) $(PHPCSFIXER) fix --config=.php-cs-fixer.dist.php --allow-risky=yes --dry-run --diff

.PHONY: psalm
psalm: ## Run psalm static analysis
	$(PHP_BIN) $(PSALM)

.PHONY: static-analysis
static-analysis: cs-check psalm ## Run all static analysis tools

.PHONY: check
check: static-analysis test ## Run all checks (cs-fix dry-run, psalm, tests)
