/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * The toast service, read as source.
 *
 * What has to hold here is about the *build*, not about what the functions
 * return: the library is behind a dynamic import to keep it out of the entry,
 * and the thing that broke was which chunk its stylesheet ended up in. A test
 * that called `showError` and asserted it resolved would have passed
 * throughout.
 */
const SOURCE = readFileSync(resolve(process.cwd(), 'src/services/toast.js'), 'utf8')

describe('the toast service', () => {
	/**
	 * `@nextcloud/dialogs` 7 draws a toast with CSS-module class names and
	 * ships their rules in a stylesheet of its own. The server styles the
	 * markup an older major produced and matches none of them, so without this
	 * import every toast in the app is an unstyled block in the top left.
	 */
	it('loads the stylesheet the toasts are drawn with', () => {
		expect(SOURCE).toContain("'@nextcloud/dialogs/style.css'")
	})

	/**
	 * In the same chunk as the library: a static import at the top of the
	 * module would put the stylesheet back in the entry this file exists to
	 * keep small.
	 */
	it('puts it in the lazy chunk rather than the entry', () => {
		const styleImport = SOURCE.match(/import\([^)]*@nextcloud\/dialogs\/style\.css[^)]*\)/)

		expect(styleImport).not.toBeNull()
		expect(styleImport[0]).toContain('webpackChunkName: "toast"')
		// no top-level `import '…style.css'`, which webpack would hoist
		expect(SOURCE).not.toMatch(/^import\s+'@nextcloud\/dialogs\/style\.css'/m)
	})

	it('keeps the library itself out of the entry too', () => {
		expect(SOURCE).not.toMatch(/^import\s+.*from\s+'@nextcloud\/dialogs'/m)
		expect(SOURCE).toMatch(/import\(\s*\/\* webpackChunkName: "toast" \*\/\s*'@nextcloud\/dialogs'\)/)
	})

	it('forwards the four kinds of toast the app raises', () => {
		for (const fn of ['showError', 'showSuccess', 'showWarning', 'showInfo']) {
			expect(SOURCE).toContain(`export async function ${fn}(`)
		}
	})
})
