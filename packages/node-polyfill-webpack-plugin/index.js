/**
 * SPDX-FileCopyrightText: 2020 - 2022 Richie Bendall
 * SPDX-License-Identifier: MIT
 *
 * Aloha Social carries this small local variant of node-polyfill-webpack-plugin
 * because the app has no Node `crypto` imports. Keeping that alias out also
 * keeps the unused, vulnerable crypto-browserify and elliptic packages out of
 * the development dependency tree. Other Nextcloud polyfills remain unchanged.
 */
'use strict'

function includeKeys(object, predicate) {
	const result = {}

	if (Array.isArray(predicate)) {
		for (const key of predicate) {
			result[key] = object[key]
		}
	} else {
		for (const key of Object.keys(object)) {
			const value = object[key]

			if (predicate(key, value, object)) {
				result[key] = value
			}
		}
	}

	return result
}

const defaultPolyfills = new Set([
	'assert',
	'buffer',
	'Buffer',
	'constants',
	'events',
	'fs',
	'http',
	'https',
	'os',
	'path',
	'querystring',
	'stream',
	'string_decoder',
	'sys',
	'timers',
	'tty',
	'url',
	'util',
	'vm',
	'zlib',
])

function createAliasFilter({ excludeAliases, onlyAliases, additionalAliases }) {
	if (onlyAliases.length > 0) {
		return object => includeKeys(object, onlyAliases)
	}

	if (additionalAliases.length > 0) {
		return object => includeKeys(object, key => (defaultPolyfills.has(key) && !excludeAliases.includes(key)) || additionalAliases.includes(key))
	}

	return object => includeKeys(object, key => defaultPolyfills.has(key) && !excludeAliases.includes(key))
}

function areItemsUnique(...iterables) {
	const seen = new Set()

	for (const iterable of iterables) {
		for (const item of iterable) {
			if (seen.has(item)) {
				return false
			}

			seen.add(item)
		}
	}

	return true
}

module.exports = class NodePolyfillPlugin {
	constructor(options = {}) {
		this.options = {
			excludeAliases: [],
			onlyAliases: [],
			additionalAliases: [],
			...options,
		}

		if (this.options.onlyAliases.length > 0) {
			if (this.options.excludeAliases.length > 0 || this.options.additionalAliases.length > 0) {
				throw new Error('onlyAliases is mutually exclusive with excludeAliases and additionalAliases')
			}
		} else if (!areItemsUnique(this.options.excludeAliases, this.options.additionalAliases)) {
			throw new Error('excludeAliases and additionalAliases must not include the same items')
		}
	}

	apply(compiler) {
		const filter = createAliasFilter(this.options)

		compiler.options.plugins.push(new compiler.webpack.ProvidePlugin(filter({
			Buffer: [require.resolve('buffer/'), 'Buffer'],
			console: require.resolve('console-browserify'),
			process: require.resolve('process/browser'),
		})))

		compiler.options.resolve.fallback = {
			...filter({
				assert: require.resolve('assert/'),
				buffer: require.resolve('buffer/'),
				console: require.resolve('console-browserify'),
				constants: require.resolve('constants-browserify'),
				domain: require.resolve('domain-browser'),
				events: require.resolve('events/'),
				fs: false,
				http: require.resolve('stream-http'),
				https: require.resolve('https-browserify'),
				os: require.resolve('os-browserify/browser'),
				path: require.resolve('path-browserify'),
				punycode: require.resolve('punycode/'),
				process: require.resolve('process/browser'),
				querystring: require.resolve('querystring-es3'),
				stream: require.resolve('stream-browserify'),
				/* eslint-disable camelcase */
				_stream_duplex: require.resolve('readable-stream/lib/_stream_duplex'),
				_stream_passthrough: require.resolve('readable-stream/lib/_stream_passthrough'),
				_stream_readable: require.resolve('readable-stream/lib/_stream_readable'),
				_stream_transform: require.resolve('readable-stream/lib/_stream_transform'),
				_stream_writable: require.resolve('readable-stream/lib/_stream_writable'),
				/* eslint-enable camelcase */
				string_decoder: require.resolve('string_decoder/'),
				sys: require.resolve('util/'),
				timers: require.resolve('timers-browserify'),
				tty: require.resolve('tty-browserify'),
				url: require.resolve('url/'),
				util: require.resolve('util/'),
				vm: require.resolve('vm-browserify'),
				zlib: require.resolve('browserify-zlib'),
			}),
			...compiler.options.resolve.fallback,
		}
	}
}
