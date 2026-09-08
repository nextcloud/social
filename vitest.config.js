/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { fileURLToPath } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	plugins: [vue()],
	resolve: {
		alias: {
			'@': fileURLToPath(new URL('./src', import.meta.url)),
		},
	},
	test: {
		environment: 'jsdom',
		globals: true,
		setupFiles: ['./tests/js/setup.js'],
		include: ['src/**/*.{test,spec}.js', 'tests/js/**/*.{test,spec}.js'],
		css: false,
		// @nextcloud/vue ships ESM that imports its own CSS; let Vite process it
		// instead of handing the .css files to Node.
		server: {
			deps: {
				inline: [/@nextcloud\/vue/, /@nextcloud\/dialogs/],
			},
		},
		coverage: {
			provider: 'v8',
			include: ['src/**/*.{js,vue}'],
			exclude: ['src/**/*.test.js', 'src/main.js', 'src/dashboard.js', 'src/oauth.js', 'src/ostatus.js', 'src/profile.js'],
			reportsDirectory: './coverage/js',
		},
	},
})
