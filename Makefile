SOURCES := $(shell find src -type f) project.yml

all: target/debug/net.nosial.federation_jevguard.ncc target/release/net.nosial.federation_jevguard.ncc
target/debug/net.nosial.federation_jevguard.ncc: $(SOURCES)
	ncc build --configuration debug --log-level debug
target/release/net.nosial.federation_jevguard.ncc: $(SOURCES)
	ncc build --configuration release --log-level debug

test: target/release/net.nosial.federation_jevguard.ncc
	phpunit --configuration phpunit.xml

test-unit: target/release/net.nosial.federation_jevguard.ncc
	phpunit --configuration phpunit.xml --exclude-group integration --exclude-group live

test-live: target/release/net.nosial.federation_jevguard.ncc
	phpunit --configuration phpunit.xml --group live


docs:
	phpdoc --config phpdoc.dist.xml


clean:
	rm -f target/debug/net.nosial.federation_jevguard.ncc
	rm -f target/release/net.nosial.federation_jevguard.ncc
	rm -rf target/docs
	rm -rf target/cache

.PHONY: all clean test test-unit test-live docs test-env test-env-down

TEST_COMPOSE = docker compose -f docker-compose.yml
SERVER_ENDPOINT ?= http://172.17.0.1:7000

test-env:
	$(TEST_COMPOSE) build --pull
	$(TEST_COMPOSE) up -d
	@echo "Waiting for FederationLib to be ready..."
	@for i in $$(seq 1 60); do \
		if curl -sf -o /dev/null "$(SERVER_ENDPOINT)/"; then \
			echo "The test environment is ready"; exit 0; \
		fi; \
		sleep 5; \
	done; \
	echo "The test environment did not become ready within 5 minutes"; $(TEST_COMPOSE) logs app; exit 1

test-env-down:
	$(TEST_COMPOSE) down
