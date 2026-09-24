/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineCustomElement, h } from 'vue'
import pinia from './store/index.js'
import ProfilePageIntegration from './views/ProfilePageIntegration.vue'
import { generateFilePath } from '@nextcloud/router'

const requestToken = window.OC?.requestToken
if (requestToken) {
	__webpack_nonce__ = btoa(requestToken)
}

__webpack_public_path__ = generateFilePath('social', '', 'js/')

const profileSectionTagName = 'social-profile-section'

const SocialProfileSectionElement = defineCustomElement({
	props: {
		user: {
			type: String,
			default: '',
		},
	},
	render() {
		return h(ProfilePageIntegration, { userId: this.user })
	},
}, {
	// the custom element runs an app of its own, and the posts it renders read
	// the store like every other timeline entry does — a boost used to reach
	// for a store that was never installed here
	configureApp(app) {
		app.use(pinia)
	},
})

if (!customElements.get(profileSectionTagName)) {
	customElements.define(profileSectionTagName, SocialProfileSectionElement)
}

// `OCA.Profile.ProfileSections` is the registry of every supported Nextcloud;
// the `OCA.Core` callback contract it replaced was gone before the app's floor.
window.OCA?.Profile?.ProfileSections?.registerSection({
	id: 'social-profile-section',
	order: 0,
	tagName: profileSectionTagName,
})
