/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

// what the module registered on import, kept outside any mock's call history
const { registered } = vi.hoisted(() => ({ registered: [] }))
vi.mock('@nextcloud/files', () => ({
	FileType: { File: 'file', Folder: 'folder' },
	registerFileAction: (action) => registered.push(action),
}))

import { composeUrl, isShareable, MAX_ATTACHMENTS, shareAction, shareToSocial } from '../../src/filesAction.js'

const file = (path, mime = 'image/jpeg', type = 'file') => ({ path, mime, type, basename: path.split('/').pop() })

describe('Share to Social in the Files app', () => {
	it('registers itself once, under a stable id', () => {
		expect(registered).toEqual([shareAction])
		expect(shareAction.id).toBe('social-share')
		expect(shareAction.displayName({ nodes: [] })).toBe('Share to Social')
		expect(shareAction.iconSvgInline({ nodes: [] })).toMatch(/^<svg/)
	})

	it('is offered for pictures and videos, and for nothing else', () => {
		expect(isShareable(file('/Photos/beach.jpg'))).toBe(true)
		expect(isShareable(file('/Videos/talk.mp4', 'video/mp4'))).toBe(true)
		// a post cannot carry these
		expect(isShareable(file('/Documents/notes.pdf', 'application/pdf'))).toBe(false)
		expect(isShareable(file('/Photos', 'httpd/unix-directory', 'folder'))).toBe(false)
		expect(isShareable({ path: '/x', type: 'file' })).toBe(false)
	})

	it('is offered for a selection only when every file qualifies and a post can carry them all', () => {
		expect(shareAction.enabled({ nodes: [file('/a.jpg')] })).toBe(true)
		expect(shareAction.enabled({ nodes: [file('/a.jpg'), file('/b.mp4', 'video/mp4')] })).toBe(true)
		// one document in the selection and the whole action goes: an action
		// that would have to refuse half of what was picked is worse than none
		expect(shareAction.enabled({ nodes: [file('/a.jpg'), file('/c.pdf', 'application/pdf')] })).toBe(false)
		expect(shareAction.enabled({ nodes: [] })).toBe(false)
		const tooMany = Array.from({ length: MAX_ATTACHMENTS + 1 }, (_, i) => file(`/${i}.jpg`))
		expect(shareAction.enabled({ nodes: tooMany })).toBe(false)
		expect(shareAction.enabled({ nodes: tooMany.slice(0, MAX_ATTACHMENTS) })).toBe(true)
	})

	it('sends the browser to the composer with one attach parameter per file', () => {
		const url = composeUrl([file('/Photos/beach day.jpg'), file('/Videos/talk.mp4', 'video/mp4')])

		expect(url.startsWith('/index.php/apps/social/timeline/home?')).toBe(true)
		const query = new URL(url, 'https://cloud.example.org').searchParams
		expect(query.getAll('attach')).toEqual(['/Photos/beach day.jpg', '/Videos/talk.mp4'])
	})

	it('answers the Files app silently, once per file, after leaving', async () => {
		const navigate = vi.fn()

		expect(shareToSocial([file('/a.jpg'), file('/b.jpg')], navigate)).toEqual([null, null])
		expect(navigate).toHaveBeenCalledWith(composeUrl([file('/a.jpg'), file('/b.jpg')]))

		// the Files app calls one or the other depending on the selection
		expect(await shareAction.execBatch({ nodes: [] })).toEqual([])
	})
})
