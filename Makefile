COMPOSE := docker compose -f docker/compose.yml
PHP     ?= 84

.PHONY: test test-matrix test-lowest shell clean

## Fast development loop: a single PHP version (8.4 by default).
test:
	$(COMPOSE) run --build --rm php$(PHP)

## The full matrix. Strictly sequential: a parallel run races over the shared
## composer.lock, see the comment in docker/compose.yml.
test-matrix:
	@for V in 84 85; do \
	  echo "═══ PHP 8.$${V#8} ($${V}) — prefer-stable ═══"; \
	  $(COMPOSE) run --build --rm php$$V || exit 1; \
	done
	@$(MAKE) test-lowest

## Checks that the constraints declared in composer.json really work, and are
## not just "whatever happened to get installed". The rm -f composer.lock in
## the container command guarantees the lock from the stable run is not
## inherited.
test-lowest:
	@for V in 84 85; do \
	  echo "═══ PHP 8.$${V#8} ($${V}) — prefer-lowest ═══"; \
	  COMPOSER_FLAGS="--prefer-lowest --prefer-stable" $(COMPOSE) run --build --rm php$$V || exit 1; \
	done

shell:
	$(COMPOSE) run --rm --entrypoint sh php$(PHP)

clean:
	$(COMPOSE) down -v
	rm -rf vendor composer.lock
