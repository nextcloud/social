/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import BackgroundSection from '../../../../src/components/admin/BackgroundSection.vue'

/**
 * @param {object} fields one row of `BackgroundHealthService::summary()`
 * @return {object} that row, with the parts a healthy job has
 */
function job(fields = {}) {
	return {
		class: 'OCA\\Social\\Cron\\Queue',
		label: 'Delivery to other servers',
		interval: 720,
		last: Math.floor(Date.now() / 1000) - 120,
		late: false,
		registered: true,
		...fields,
	}
}

/**
 * @param {object} overrides what the instance is seeing
 * @return {object} the mounted section
 */
function mountBackground(overrides = {}) {
	return mount(BackgroundSection, {
		props: {
			background: { jobs: [job()], late: 0, worst: 0, ...overrides },
		},
	})
}

describe('the background work section', () => {
	it('says so when everything has run', () => {
		expect(mountBackground().text()).toContain('Every job has run recently.')
	})

	it('lists each job with when it last ran', () => {
		const wrapper = mountBackground()

		expect(wrapper.text()).toContain('Delivery to other servers')
		expect(wrapper.text()).toContain('12 minutes')
		expect(wrapper.text()).toContain('2 minutes ago')
	})

	/**
	 * The symptom of cron having stopped is a post that never arrives, and
	 * nothing in the app said so.
	 */
	it('names the problem when jobs are behind', () => {
		const wrapper = mountBackground({
			jobs: [job({ late: true, last: Math.floor(Date.now() / 1000) - 172800 })],
			late: 1,
			worst: 172800,
		})

		expect(wrapper.text()).toContain('1 job has not run when it should have.')
		expect(wrapper.text()).toContain('2 days')
		expect(wrapper.text()).toContain('cron is not running')
		expect(wrapper.find('.background__row--late').exists()).toBe(true)
	})

	/** Which is what an upgrade whose migrations have not run looks like. */
	it('tells an administrator when a job is not registered at all', () => {
		const wrapper = mountBackground({ jobs: [job({ registered: false, last: 0 })] })

		expect(wrapper.text()).toContain('not registered')
		expect(wrapper.text()).toContain('occ upgrade')
	})

	/** A page that cries wolf on a fresh install is one nobody reads. */
	it('says "never" rather than complaining about a job that has not run yet', () => {
		const wrapper = mountBackground({ jobs: [job({ last: 0 })] })

		expect(wrapper.text()).toContain('never')
		expect(wrapper.text()).toContain('Every job has run recently.')
	})
})
