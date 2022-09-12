// SPDX-FileCopyrigthText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

const path = require('path');
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	social: path.join(__dirname, 'src', 'main.js'),
	ostatus: path.join(__dirname, 'src', 'ostatus.js'),
}

module.exports = webpackConfig
