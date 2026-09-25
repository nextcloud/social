/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * What every entry point puts on `app.config.globalProperties`, so templates
 * and `this` in a component can use it: the translate helpers and the
 * server's two namespaces, the same ones globals.d.ts declares on the page.
 *
 * This file has to be a module: `declare module 'vue'` in a script file would
 * declare a new 'vue' in place of the real one instead of adding to it.
 */
/* eslint-disable @typescript-eslint/no-explicit-any */

export {}

declare module 'vue' {
	interface ComponentCustomProperties {
		t: typeof t
		n: typeof n
		OC: any
		OCA: any
	}
}
