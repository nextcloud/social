/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Global test environment for the Vue frontend.
 *
 * Nextcloud injects `t`, `n`, `OC` and `OCA` as globals at runtime; the app
 * also relies on them being registered as Vue global properties in main.js.
 * Tests get the same surface here, minimal and deterministic.
 */
import { config } from '@vue/test-utils'
import { vi } from 'vitest'

function translate(app, text, vars = {}) {
	return Object.entries(vars)
		.reduce((str, [key, value]) => str.replaceAll(`{${key}}`, String(value)), text)
}
// %n is what @nextcloud/l10n substitutes the count into; leaving it in place
// made every plural assertion read '%n character left'
function translatePlural(app, singular, plural, count, vars = {}) {
	return translate(app, (count === 1 ? singular : plural).replaceAll('%n', String(count)), { count, ...vars })
}

globalThis.t = translate
globalThis.n = translatePlural

globalThis.OC = {
	requestToken: 'test-request-token',
	linkTo: (app, file) => `/apps/${app}/${file}`,
	generateUrl: (url) => `/index.php${url}`,
	getCurrentUser: () => ({ uid: 'alice', displayName: 'Alice' }),
	config: { modRewriteWorking: false },
	theme: { name: 'Nextcloud' },
	Notification: { showTemporary: vi.fn() },
	MimeType: { getIconUrl: () => '/core/img/filetypes/file.svg' },
	isUserAdmin: () => false,
}
globalThis.OCA = {}
globalThis.OCP = {}

// @nextcloud/router resolves app URLs from these server-injected globals.
globalThis._oc_webroot = ''
globalThis._oc_appswebroots = { social: '/apps/social' }
globalThis.OC.webroot = ''
globalThis.OC.appswebroots = globalThis._oc_appswebroots
document.body.dataset.webroot = ''
document.body.dataset.locale = 'en'
document.body.dataset.user = 'alice'

// @nextcloud/initial-state reads from <input type="hidden" id="initial-state-{app}-{key}">.
globalThis.setInitialState = (app, key, value) => {
	const id = `initial-state-${app}-${key}`
	let el = document.getElementById(id)
	if (!el) {
		el = document.createElement('input')
		el.type = 'hidden'
		el.id = id
		document.head.appendChild(el)
	}
	el.value = btoa(JSON.stringify(value))
}

config.global.mocks = {
	t: translate,
	n: translatePlural,
	OC: globalThis.OC,
	OCA: globalThis.OCA,
}

// Node 26 ships an experimental global localStorage that shadows jsdom's and
// throws without a backing file. Replace it with a plain in-memory Storage.
function memoryStorage() {
	const data = new Map()
	return {
		getItem: (key) => (data.has(key) ? data.get(key) : null),
		setItem: (key, value) => data.set(key, String(value)),
		removeItem: (key) => data.delete(key),
		clear: () => data.clear(),
		key: (index) => [...data.keys()][index] ?? null,
		get length() {
			return data.size
		},
	}
}
Object.defineProperty(globalThis, 'localStorage', { value: memoryStorage(), configurable: true, writable: true })
Object.defineProperty(globalThis, 'sessionStorage', { value: memoryStorage(), configurable: true, writable: true })

// jsdom does not implement the HTML editing API, so `contentEditable` is undefined
// even on an element that carries the attribute. tributejs reads that property to
// decide whether it may attach to the composer's contenteditable.
if (!('contentEditable' in HTMLElement.prototype)) {
	Object.defineProperty(HTMLElement.prototype, 'contentEditable', {
		configurable: true,
		get() {
			return this.getAttribute('contenteditable') ?? 'inherit'
		},
		set(value) {
			this.setAttribute('contenteditable', String(value))
		},
	})
}

// jsdom lacks these; components touch them during mount.
if (!globalThis.matchMedia) {
	globalThis.matchMedia = () => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {}, addListener: () => {}, removeListener: () => {} })
}
if (!globalThis.ResizeObserver) {
	globalThis.ResizeObserver = class {
		observe() {} unobserve() {} disconnect() {}
	}
}
if (!globalThis.IntersectionObserver) {
	globalThis.IntersectionObserver = class {
		observe() {} unobserve() {} disconnect() {}
	}
}
if (!Element.prototype.scrollIntoView) {
	Element.prototype.scrollIntoView = () => {}
}
