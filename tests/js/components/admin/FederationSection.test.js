/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import FederationSection from '../../../../src/components/admin/FederationSection.vue'

/**
 * `FederationHealthService::summary()`, with nothing wrong.
 *
 * @param {object} overrides what this instance is seeing
 * @return {object} the mounted section
 */
function mountFederation(overrides = {}) {
	return mount(FederationSection, {
		props: {
			federation: {
				waiting: 0,
				running: 0,
				failing: 0,
				atRisk: 0,
				abandoned: 0,
				maxTries: 15,
				truncated: false,
				abandonedTruncated: false,
				retentionDays: 7,
				instances: [],
				givenUp: [],
				...overrides,
			},
		},
	})
}

describe('the federation health section', () => {
	it('says a healthy queue is healthy instead of showing an empty table', () => {
		const wrapper = mountFederation({ waiting: 4 })

		expect(wrapper.text()).toContain('4 deliveries waiting to be sent.')
		expect(wrapper.text()).toContain('Nothing is failing to deliver.')
		expect(wrapper.text()).not.toContain('Waiting deliveries')
	})

	it('names a failing instance with its attempts and its last try', () => {
		const wrapper = mountFederation({
			waiting: 30,
			failing: 6,
			atRisk: 2,
			instances: [{ host: 'gone.example', requests: 5, tries: 14, last: 1_700_000_000 }],
		})

		expect(wrapper.text()).toContain('gone.example')
		expect(wrapper.text()).toContain('14 / 15')
		expect(wrapper.text()).toContain('2023-11-14 22:13')
		expect(wrapper.text()).toContain('6 deliveries have failed at least once.')
		expect(wrapper.text()).toContain('2 of them are close to being given up on.')
		expect(wrapper.text()).toContain('A delivery is abandoned after 15 attempts.')
	})

	it('says never rather than showing the epoch', () => {
		const wrapper = mountFederation({
			failing: 1,
			instances: [{ host: 'gone.example', requests: 1, tries: 3, last: 0 }],
		})

		expect(wrapper.text()).toContain('never')
		expect(wrapper.text()).not.toContain('1970-01-01')
	})

	it('says when a count stopped short', () => {
		expect(mountFederation({ failing: 500, truncated: true }).text())
			.toContain('only the first few hundred were counted')
	})

	it('gives the instances given up on a table of their own', () => {
		const wrapper = mountFederation({
			abandoned: 12,
			givenUp: [{ host: 'gone.example', requests: 12, tries: 16, last: 1_700_000_000 }],
		})

		expect(wrapper.text()).toContain('Given up on')
		expect(wrapper.text()).toContain('12 deliveries were given up on')
		expect(wrapper.text()).toContain('Deliveries given up on')
		expect(wrapper.text()).toContain('social:queue:retry --instance')
	})

	it('says nothing has been given up on when nothing has', () => {
		const wrapper = mountFederation({ waiting: 4 })

		expect(wrapper.text()).toContain('Nothing has been given up on in the last 7 days.')
		expect(wrapper.text()).not.toContain('Deliveries given up on')
	})

	it('counts what is being sent right now only when something is', () => {
		expect(mountFederation({ waiting: 4, running: 2 }).text())
			.toContain('2 are being sent right now.')
		expect(mountFederation({ waiting: 4 }).text())
			.not.toContain('being sent right now')
	})
})
