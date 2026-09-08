/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
module.exports = {
	extends: [
		// this app is Vue 3; the bare '@nextcloud' config lints against the Vue 2
		// ruleset, which rejects valid Vue 3 syntax and misses the Vue 2 leftovers
		'@nextcloud/eslint-config/vue3',
	],
	globals: {
		appName: true,
		__webpack_nonce__: 'writable',
		__webpack_public_path__: 'writable',
	},
	rules: {
		'vue/no-multiple-template-root': 'off',
		'camelcase': ['error', { properties: 'never', ignoreDestructuring: true, allow: ['__webpack_nonce__', '__webpack_public_path__'] }],
		'no-console': 'warn',
	},
}
