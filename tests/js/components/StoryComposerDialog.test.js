/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import StoryComposerDialog from '../../../src/components/StoryComposerDialog.vue'
import { useTimelineStore } from '../../../src/store/timeline.js'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
const { showError, showSuccess } = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('../../../src/services/toast.js', () => ({ showError, showSuccess }))
vi.mock('../../../src/services/logger.js', () => ({ default: { debug: vi.fn(), info: vi.fn(), warn: vi.fn(), error: vi.fn() } }))

const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'buttons', 'name'],
	emits: ['update:open'],
	template: '<div v-if="open" class="nc-dialog">'
		+ '<button v-for="(button, index) in buttons" :key="index" :class="\'nc-dialog__button--\' + index" :disabled="button.disabled" @click="button.callback()">{{ button.label }}</button><slot /></div>',
}

function mountDialog() {
	const pinia = createPinia()
	setActivePinia(pinia)
	const store = useTimelineStore()
	const createMedia = vi.spyOn(store, 'createMedia').mockResolvedValue({ id: 'm1' })
	globalThis.URL.createObjectURL ??= () => 'blob:preview'
	globalThis.URL.revokeObjectURL ??= () => {}

	const wrapper = mount(StoryComposerDialog, {
		props: { open: true },
		global: { plugins: [pinia], stubs: { NcDialog: NcDialogStub } },
	})

	return { wrapper, createMedia }
}

async function pick(wrapper, file) {
	const input = wrapper.find('input[type="file"]')
	Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
	await input.trigger('change')
}

describe('StoryComposerDialog', () => {
	beforeEach(() => {
		post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('keeps the file input behind its button out of the tab order', () => {
		const input = mountDialog().wrapper.find('input[type="file"]')

		expect(input.attributes('tabindex')).toBe('-1')
		expect(input.attributes('aria-hidden')).toBe('true')
	})

	it('cannot post before a picture was chosen', () => {
		const { wrapper } = mountDialog()

		expect(wrapper.find('.nc-dialog__button--1').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.story-composer__pick').exists()).toBe(true)
	})

	it('uploads the picture the way every attachment goes up, then makes the story of it', async () => {
		const { wrapper, createMedia } = mountDialog()
		post.mockResolvedValue({ data: { id: '5', caption: 'hello' } })

		await pick(wrapper, new File(['x'], 'sea.jpg', { type: 'image/jpeg' }))
		expect(wrapper.find('.story-composer__media').exists()).toBe(true)
		await wrapper.find('.story-composer__caption input').setValue('hello')
		await wrapper.find('.nc-dialog__button--1').trigger('click')
		await flushPromises()

		expect(createMedia).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith(expect.stringContaining('/apps/social/api/v1/stories'), { media_id: 'm1', caption: 'hello', duration: 5 })
		expect(wrapper.emitted('posted')[0][0].id).toBe('5')
		expect(wrapper.emitted('update:open')[0]).toEqual([false])
		expect(showSuccess).toHaveBeenCalled()
	})

	it('offers no seconds for a video, which runs for as long as it runs', async () => {
		const { wrapper } = mountDialog()

		await pick(wrapper, new File(['x'], 'clip.mp4', { type: 'video/mp4' }))

		expect(wrapper.find('.story-composer__duration').exists()).toBe(false)
		expect(wrapper.find('video').exists()).toBe(true)
	})

	it('says so when the server refused the story', async () => {
		const { wrapper } = mountDialog()
		post.mockRejectedValue({ response: { data: { error: 'this account already has 40 live stories' } } })

		await pick(wrapper, new File(['x'], 'sea.jpg', { type: 'image/jpeg' }))
		await wrapper.find('.nc-dialog__button--1').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('this account already has 40 live stories')
		expect(wrapper.emitted('posted')).toBeUndefined()
	})
})
