

app_name=social

project_dir=$(CURDIR)
build_dir=$(CURDIR)/build/artifacts
appstore_dir=$(build_dir)/appstore
source_dir=$(build_dir)/source
sign_dir=$(build_dir)/sign
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates
github_account=nextcloud
branch=master
codecov_token_dir=$(HOME)/.nextcloud/codecov_token
version+=0.2.6




all: dev-setup lint build-js-production composer test

# Dev env management
dev-setup: clean clean-dev npm-init composer
	cp -R node_modules/twemoji/2/svg img/twemoji

npm-init:
	npm install

npm-update:
	npm update

# Building
build-js:
	npm run dev

build-js-production:
	npm run build

watch-js:
	npm run watch

# Testing
test:
	npm run test

test-watch:
	npm run test:watch

test-coverage:
	npm run test:coverage

# Linting
lint:
	npm run lint

lint-fix:
	npm run lint:fix

# Cleaning
clean:
	rm -rf $(build_dir)
	rm -fr js/
	mkdir js/

clean-dev:
	rm -rf node_modules

# composer packages
composer:
	composer install --prefer-dist

# releasing to github
release: appstore github-release github-upload

github-release:
	github-release release \
		--user $(github_account) \
		--repo $(app_name) \
		--target $(branch) \
		--tag v$(version) \
		--name "$(app_name) v$(version)"

github-upload:
	github-release upload \
		--user $(github_account) \
		--repo $(app_name) \
		--tag v$(version) \
		--name "$(app_name)-$(version).tar.gz" \
		--file $(build_dir)/$(app_name)-$(version).tar.gz

# creating .tar.gz + signature
appstore: dev-setup lint build-js-production composer
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude=/build \
	--exclude=/docs \
	--exclude=/translationfiles \
	--exclude=/.tx \
	--exclude=/tests \
	--exclude=/.git \
	--exclude=/vendor/friendica/json-ld/.git \
	--exclude=/.github \
	--exclude=/.babelrc.js \
	--exclude=/.drone.yml \
	--exclude=/.eslintrc.js \
	--exclude=/composer.json \
	--exclude=/composer.lock \
	--exclude=/src \
	--exclude=/node_modules \
	--exclude=/webpack.*.js \
	--exclude=/package.json \
	--exclude=/package-lock.json \
	--exclude=/l10n/l10n.pl \
	--exclude=/CONTRIBUTING.md \
	--exclude=/issue_template.md \
	--exclude=/README.md \
	--exclude=/.gitattributes \
	--exclude=/.gitignore \
	--exclude=/.scrutinizer.yml \
	--exclude=/.travis.yml \
	--exclude=/Makefile \
	$(project_dir)/ $(sign_dir)/$(app_name)
	tar -czf $(build_dir)/$(app_name)-$(version).tar.gz \
		-C $(sign_dir) $(app_name)
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Signing package…"; \
		openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(build_dir)/$(app_name)-$(version).tar.gz | openssl base64; \
	fi
