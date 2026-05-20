import { createApp, defineCustomElement, h } from 'vue'
import ProfilePageIntegration from './views/ProfilePageIntegration.vue'
import { generateFilePath } from '@nextcloud/router'
import { translate, translatePlural } from '@nextcloud/l10n'

// eslint-disable-next-line
const requestToken = window.OC?.requestToken
if (requestToken) {
	__webpack_nonce__ = btoa(requestToken)
}
// eslint-disable-next-line
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
})

if (!customElements.get(profileSectionTagName)) {
	customElements.define(profileSectionTagName, SocialProfileSectionElement)
}

if (window.OCA?.Profile?.ProfileSections) {
	window.OCA.Profile.ProfileSections.registerSection({
		id: 'social-profile-section',
		order: 0,
		tagName: profileSectionTagName,
	})
} else if (window.OCA?.Core?.ProfileSections) {
	// Keep compatibility with older Nextcloud builds that still use the legacy callback contract.
	window.OCA.Core.ProfileSections.registerSection((el, userId) => {
		const app = createApp(ProfilePageIntegration, { userId })
		app.config.globalProperties.t = translate
		app.config.globalProperties.n = translatePlural
		app.config.globalProperties.OC = window.OC
		app.config.globalProperties.OCA = window.OCA
		return app
	})
}
