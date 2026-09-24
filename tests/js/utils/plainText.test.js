/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { htmlToPlainText } from '../../../src/utils/plainText.js'

describe('htmlToPlainText', () => {
	it('keeps the first line break between bare text and a block', () => {
		expect(htmlToPlainText('one<div>two</div>')).toBe('one\ntwo')
	})

	it('keeps one break between adjacent blocks', () => {
		expect(htmlToPlainText('<p>one</p><p>two</p>')).toBe('one\ntwo')
	})

	it('keeps explicit breaks inside a block', () => {
		expect(htmlToPlainText('<p>one<br>two</p>')).toBe('one\ntwo')
	})
})
