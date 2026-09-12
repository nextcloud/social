/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const CopyPlugin = require('copy-webpack-plugin')

webpackConfig.plugins.push(new CopyPlugin({
	patterns: [
		{ from: 'node_modules/twemoji/2/svg/', to: '../img/twemoji' },
	],
}))

webpackConfig.entry = {
	adminAnnouncements: path.join(__dirname, 'src', 'adminAnnouncements.js'),
	adminModeration: path.join(__dirname, 'src', 'adminModeration.js'),
	social: path.join(__dirname, 'src', 'main.js'),
	ostatus: path.join(__dirname, 'src', 'ostatus.js'),
	profilePage: path.join(__dirname, 'src', 'profile.js'),
	dashboard: path.join(__dirname, 'src', 'dashboard.js'),
	oauth: path.join(__dirname, 'src', 'oauth.js'),
}

webpackConfig.optimization.concatenateModules = false

// The emoji picker is most of a megabyte, and it was landing in the same
// vendor chunk as NcActionButton — which every post's overflow menu needs — so
// everybody downloaded the whole emoji set to see a "..." button. Give it a
// chunk of its own and it arrives when somebody opens the picker.
webpackConfig.optimization.splitChunks = {
	...(webpackConfig.optimization.splitChunks ?? {}),
	cacheGroups: {
		...(webpackConfig.optimization.splitChunks?.cacheGroups ?? {}),
		emoji: {
			test: /[\\/]node_modules[\\/](?:emoji-mart|emoji-mart-vue-fast)[\\/]/,
			name: 'emoji-picker',
			chunks: 'all',
			priority: 30,
			reuseExistingChunk: true,
			enforce: true,
		},
	},
}
webpackConfig.module.rules.unshift({
	test: /\.mjs$/,
	type: 'javascript/auto',
	resolve: {
		fullySpecified: false,
	},
})
webpackConfig.module.rules.unshift({
	test: /node_modules\/(?:axios|webdav|@vue\/devtools-shared)\/.*\.js$/,
	resolve: {
		fullySpecified: false,
	},
})
webpackConfig.resolve.extensions = ['.*', '.ts', '.js', '.vue', '.json']
webpackConfig.resolve.fallback = {
	...webpackConfig.resolve.fallback,
	buffer: require.resolve('buffer/'),
}

// Preserve .htaccess and the hand-written admin-settings script when cleaning
// the output directory
webpackConfig.output.clean = {
	keep: /\.htaccess|social-adminSettings\.js/,
}

module.exports = webpackConfig
