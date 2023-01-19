// SPDX-FileCopyrigthText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const CopyPlugin = require('copy-webpack-plugin')

webpackConfig.plugins.push(new CopyPlugin({
	patterns: [
		{ from: 'node_modules/twemoji/2/svg/', to: '../img/twemoji' },
	],
}))

webpackConfig.entry = {
	social: path.join(__dirname, 'src', 'main.js'),
	ostatus: path.join(__dirname, 'src', 'ostatus.js'),
	profilePage: path.join(__dirname, 'src', 'profile.js'),
	dashboard: path.join(__dirname, 'src', 'dashboard.js'),
	oauth: path.join(__dirname, 'src', 'oauth.js'),
}

module.exports = webpackConfig
