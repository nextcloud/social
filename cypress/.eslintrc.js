/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
module.exports = {
	env: {
		'cypress/globals': true,
	},
	plugins: [
		'cypress',
	],
	extends: [
		'plugin:cypress/recommended',
	],
};
