/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @nextcloud/eslint-config 9 dropped the .eslintrc format along with ESLint 9,
// so this is the flat-config successor to .eslintrc.js. `recommendedJavascript`
// is the Vue 3 ruleset that reads <script> blocks as Javascript; the plain
// `recommended` export parses them as Typescript, which this app does not use.
import { recommendedJavascript } from '@nextcloud/eslint-config'

/**
 * Rules the shared config turns on that would rewrite this codebase rather than
 * find anything wrong with it. Each one is a formatting or ordering opinion, and
 * between them they report about 1200 errors across 126 files — a diff nobody
 * can review, landing in the same commit as a toolchain migration.
 *
 * They are off here so that adopting them stays a separate, deliberate change.
 * Nothing below reports a defect; see the counts for what each would cost.
 */
const deferredStyleRules = {
	// 523: blank line between multi-line component properties
	'vue/new-line-between-multi-line-property': 'off',
	// 11: blank line between <template>, <script> and <style>
	'vue/padding-line-between-blocks': 'off',
	// 193 + 7: import order. This one also detaches the comments that explain
	// why particular imports are there — several are side-effect imports whose
	// comment is the only thing saying so.
	'perfectionist/sort-imports': 'off',
	'perfectionist/sort-named-imports': 'off',
	// 109: `const f = () => {}` rewritten to `function f() {}`
	'antfu/top-level-function': 'off',
	// 57: kebab-case template attributes and event names
	'vue/attribute-hyphenation': 'off',
	'vue/v-on-event-hyphenation': 'off',
	// 8: reorders the option blocks inside a component
	'vue/order-in-components': 'off',
	// 9: would rename components — Search.vue, Poll.vue and friends
	'vue/multi-word-component-names': 'off',
	'vue/no-reserved-component-names': 'off',
	// 22: braces around every single-statement if/else
	'curly': 'off',
	// ~290 across eight rules: whitespace, indentation and line breaks
	'@stylistic/indent': 'off',
	'@stylistic/indent-binary-ops': 'off',
	'@stylistic/exp-list-style': 'off',
	'@stylistic/function-paren-newline': 'off',
	'@stylistic/arrow-parens': 'off',
	'@stylistic/padded-blocks': 'off',
	'@stylistic/max-statements-per-line': 'off',
	'@stylistic/implicit-arrow-linebreak': 'off',
}

export default [
	...recommendedJavascript,
	{
		languageOptions: {
			globals: {
				appName: 'readonly',
				// webpack replaces this at build time; src/store/index.js reads it
				process: 'readonly',
				__webpack_nonce__: 'writable',
				__webpack_public_path__: 'writable',
			},
		},
		rules: {
			...deferredStyleRules,
			// this app mounts several roots; the rule is a Vue 2 leftover
			'vue/no-multiple-template-root': 'off',
			// the two webpack globals above are the only snake_case names allowed
			'camelcase': ['error', {
				properties: 'never',
				ignoreDestructuring: true,
				allow: ['__webpack_nonce__', '__webpack_public_path__'],
			}],
			'no-console': 'warn',
			// Function declarations and module-level consts referenced from
			// bodies defined above them: hoisting-safe, because the functions
			// only run once the module has finished evaluating, and several
			// files here deliberately read top-down from entry point to helper.
			// `classes` stays on — a class read before its declaration is a
			// genuine temporal-dead-zone error.
			'no-use-before-define': ['error', { functions: false, classes: true, variables: false }],
		},
	},
	{
		// vitest runs with globals enabled, so the specs do not import these.
		// vitest.config.js collects specs from both trees.
		files: ['tests/js/**/*.js', 'src/**/*.{test,spec}.js'],
		languageOptions: {
			globals: {
				afterAll: 'readonly',
				afterEach: 'readonly',
				beforeAll: 'readonly',
				beforeEach: 'readonly',
				describe: 'readonly',
				expect: 'readonly',
				it: 'readonly',
				test: 'readonly',
				vi: 'readonly',
			},
		},
	},
]
