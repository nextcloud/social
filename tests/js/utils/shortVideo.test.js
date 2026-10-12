/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { clampTrim, fileTypeFor, filmstripTimes, formatTime, isTrimmed, knownDuration, MIN_LENGTH, RECORD_LIMITS, recorderMimeType, trimVideo } from '../../../src/utils/shortVideo.js'

describe('formatTime', () => {
	it('writes minutes and two-digit seconds', () => {
		expect(formatTime(0)).toBe('0:00')
		expect(formatTime(7.9)).toBe('0:07')
		expect(formatTime(65)).toBe('1:05')
		expect(formatTime(-3)).toBe('0:00')
		expect(formatTime(NaN)).toBe('0:00')
	})
})

describe('clampTrim', () => {
	it('keeps both handles inside the video', () => {
		expect(clampTrim({ start: -2, end: 99 }, 30)).toEqual({ start: 0, end: 30 })
	})

	/** a short of no length cannot be posted, so the handles never meet */
	it('keeps the short at least a second long, moving whichever handle moved', () => {
		expect(clampTrim({ start: 10, end: 10.2 }, 30, 'end')).toEqual({ start: 10, end: 10 + MIN_LENGTH })
		expect(clampTrim({ start: 9.8, end: 10 }, 30, 'start')).toEqual({ start: 10 - MIN_LENGTH, end: 10 })
	})

	it('gives way at the end of the video rather than running past it', () => {
		expect(clampTrim({ start: 29.9, end: 30 }, 30, 'end')).toEqual({ start: 30 - MIN_LENGTH, end: 30 })
	})

	it('copes with a video shorter than the shortest short', () => {
		expect(clampTrim({ start: 0, end: 0.5 }, 0.5)).toEqual({ start: 0, end: 0.5 })
	})
})

describe('isTrimmed', () => {
	it('says whether anything is cut off', () => {
		expect(isTrimmed({ start: 0, end: 30 }, 30)).toBe(false)
		expect(isTrimmed({ start: 2, end: 30 }, 30)).toBe(true)
		expect(isTrimmed({ start: 0, end: 20 }, 30)).toBe(true)
	})
})

describe('filmstripTimes', () => {
	it('takes a frame from the middle of each slice', () => {
		expect(filmstripTimes(10, 5)).toEqual([1, 3, 5, 7, 9])
	})

	it('has nothing to show for a video with no length', () => {
		expect(filmstripTimes(0, 5)).toEqual([])
		expect(filmstripTimes(NaN, 5)).toEqual([])
	})
})

describe('recorderMimeType', () => {
	it('prefers MP4 where the browser can make it', () => {
		expect(recorderMimeType(() => true)).toBe('video/mp4;codecs=avc1,mp4a.40.2')
	})

	it('falls back to WebM', () => {
		expect(recorderMimeType((mime) => mime.startsWith('video/webm'))).toBe('video/webm;codecs=vp9,opus')
	})

	it('treats a browser that throws as one that cannot', () => {
		const throws = () => {
			throw new Error('unknown')
		}
		expect(recorderMimeType(throws)).toBe('')
	})
})

describe('fileTypeFor', () => {
	it('names the file after the container, not the codecs', () => {
		expect(fileTypeFor('video/mp4;codecs=avc1')).toEqual({ type: 'video/mp4', extension: 'mp4' })
		expect(fileTypeFor('video/webm;codecs=vp9')).toEqual({ type: 'video/webm', extension: 'webm' })
		expect(fileTypeFor('')).toEqual({ type: 'video/webm', extension: 'webm' })
	})
})

describe('trimVideo', () => {
	/** jsdom has no MediaRecorder; the composer then posts the whole video */
	it('answers null where the browser has no recorder', async () => {
		expect(await trimVideo(new File(['v'], 'a.mp4', { type: 'video/mp4' }), 1, 2)).toBeNull()
	})
})

describe('knownDuration', () => {
	/** A stand-in for a video element whose length can change under it. */
	function fakeVideo(duration) {
		const video = new EventTarget()
		video.duration = duration
		video.currentTime = 0

		return video
	}

	it('answers at once for a video that says how long it is', async () => {
		expect(await knownDuration(fakeVideo(12.5))).toBe(12.5)
	})

	/** a recording's WebM has no length in its header until the browser has read to the end */
	it('reads to the end for a recording that does not say, and goes back to the start', async () => {
		const video = fakeVideo(Infinity)
		const measured = knownDuration(video)
		expect(video.currentTime).toBe(Number.MAX_SAFE_INTEGER)

		video.duration = 7.2
		video.dispatchEvent(new Event('durationchange'))

		expect(await measured).toBe(7.2)
		expect(video.currentTime).toBe(0)
	})

	it('gives up with 0 rather than waiting forever', async () => {
		vi.useFakeTimers()
		const measured = knownDuration(fakeVideo(Infinity), 3000)
		vi.advanceTimersByTime(3000)

		expect(await measured).toBe(0)
		vi.useRealTimers()
	})
})

/**
 * The server decides what a short is (`Stream::shortKindOf()`, which
 * `only_short` filters on), and the composer decides how long a short can be
 * recorded. Read from the PHP source rather than restated, so the two cannot
 * drift: a short recorded to the composer's longest limit is still a short.
 */
describe('the composer and the server agree on what a short is', () => {
	// vitest runs from the project root
	const stream = readFileSync(resolve(process.cwd(), 'lib/Model/ActivityPub/Stream.php'), 'utf8')
	const constant = (name) => Number(stream.match(new RegExp(`const ${name} = ([0-9.]+);`))?.[1])

	it('offers no recording longer than the longest short', () => {
		expect(constant('SHORT_MAX_SECONDS')).toBe(180)
		expect(Math.max(...RECORD_LIMITS)).toBe(constant('SHORT_MAX_SECONDS'))
	})

	it('counts the frame the composer asks the camera for as a short', () => {
		const dialog = readFileSync(resolve(process.cwd(), 'src/components/ShortComposerDialog.vue'), 'utf8')
		const [, width, height] = dialog.match(/width: \{ ideal: (\d+) \}, height: \{ ideal: (\d+) \}/) ?? []

		expect(constant('SHORT_MAX_ASPECT')).toBe(1)
		expect(Number(width) / Number(height)).toBeLessThanOrEqual(constant('SHORT_MAX_ASPECT'))
	})
})
