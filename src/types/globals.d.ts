/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The globals the Nextcloud server puts on the page. They are not imported,
 * so the type checker has to be told they exist; eslint.config.mjs declares
 * the same set for the linter.
 *
 * `OC` and `OCA` are the server's own namespaces, which have no types this app
 * can import; `any` is what they are, so the rule against it is off here rather
 * than worked around with a shape that would only pretend to be accurate.
 */
/* eslint-disable @typescript-eslint/no-explicit-any */

declare function t(app: string, text: string, vars?: Record<string, unknown>, count?: number): string
declare function n(app: string, singular: string, plural: string, count: number, vars?: Record<string, unknown>): string

declare const appName: string
declare const OC: any
declare const OCA: any

declare const __webpack_nonce__: string
declare const __webpack_public_path__: string

interface Window {
	OC: any
	OCA: any
	_oc_webroot: string
}
