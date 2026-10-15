/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import { showError } from '../../../src/services/toast.js'

import BlueskyMissingBlobs from '../../../src/components/BlueskyMissingBlobs.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { post: vi.fn() },
}))
vi.mock('../../../src/services/toast.js', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const CONTINUE = '/index.php/apps/social/api/v1/social/bluesky/move-in/continue'

/** A dialog that draws what it is given, so the question can be asserted on. */
const NcDialog = {
	name: 'NcDialog',
	props: ['open', 'name', 'buttons'],
	emits: ['update:open'],
	template: '<div v-if="open" class="dialog-stub"><slot /></div>',
}

/**
 * @param {number} count how many pictures or videos did not arrive
 * @return {object} the mounted card
 */
function mountCard(count = 4) {
	return mount(BlueskyMissingBlobs, {
		props: {
			move: { id: 1, direction: 'in', state: 'needs_decision', progress: { code: 'blobs-missing', missing: { count, cids: [] } }, error: '' },
		},
		global: { stubs: { NcDialog } },
	})
}

const buttonNamed = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)
const dialogButtons = (wrapper) => wrapper.findComponent(NcDialog).props('buttons')

describe('BlueskyMissingBlobs', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('copies again at once', async () => {
		axios.post.mockResolvedValue({ data: { move: { state: 'running' } } })
		const wrapper = mountCard()

		await buttonNamed(wrapper, 'Try copying them again').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(CONTINUE, { without_missing: false })
		expect(wrapper.emitted('move')).toEqual([[{ state: 'running' }]])
	})

	it('asks before going on without them, and says how many are lost', async () => {
		const wrapper = mountCard(4)

		await buttonNamed(wrapper, 'Go on without them').trigger('click')

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcDialog).props('name')).toBe('Go on without them?')
		expect(wrapper.find('.dialog-stub').text()).toBe('4 pictures or videos of your posts could not be copied here. Your account is pointed at this server without them: the posts that show them lose them, and they are not copied later.')
	})

	it('says it in the singular for one', async () => {
		const wrapper = mountCard(1)

		await buttonNamed(wrapper, 'Go on without them').trigger('click')

		expect(wrapper.find('.dialog-stub').text()).toBe('1 picture or video of your posts could not be copied here. Your account is pointed at this server without it: the posts that show it lose it, and it is not copied later.')
	})

	it('goes on without them once confirmed', async () => {
		axios.post.mockResolvedValue({ data: { move: { state: 'running' } } })
		const wrapper = mountCard()

		await buttonNamed(wrapper, 'Go on without them').trigger('click')
		const [, confirm] = dialogButtons(wrapper)
		expect(confirm.label).toBe('Go on without them')
		confirm.callback()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(CONTINUE, { without_missing: true })
		expect(wrapper.find('.dialog-stub').exists()).toBe(false)
	})

	it('sends nothing when the question is cancelled', async () => {
		const wrapper = mountCard()

		await buttonNamed(wrapper, 'Go on without them').trigger('click')
		dialogButtons(wrapper)[0].callback()
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.find('.dialog-stub').exists()).toBe(false)
	})

	it('says why the server would not go on, in plain words with its own behind Details', async () => {
		axios.post.mockRejectedValue({ response: { status: 422, data: { error: 'The move does not wait for a decision', code: 'no-decision-pending' } } })
		const wrapper = mountCard()

		await buttonNamed(wrapper, 'Try copying them again').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('The move is not waiting for a decision any more. Reload the page to see where it stands.')
		expect(wrapper.find('.bluesky-move-error__details code').text()).toBe('The move does not wait for a decision')
		expect(showError).not.toHaveBeenCalled()
	})
})
