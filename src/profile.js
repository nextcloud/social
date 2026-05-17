import { createApp } from 'vue'
import ProfilePageIntegration from './views/ProfilePageIntegration.vue'
import { generateFilePath } from '@nextcloud/router'
import { translate, translatePlural } from '@nextcloud/l10n'

// eslint-disable-next-line
__webpack_nonce__ = btoa(OC.requestToken)
// eslint-disable-next-line
__webpack_public_path__ = generateFilePath('social', '', 'js/')

if (OCA?.Core?.ProfileSections) {
	OCA.Core.ProfileSections.registerSection((el, userId) => {
		const app = createApp(ProfilePageIntegration, { userId })
		app.config.globalProperties.t = translate
		app.config.globalProperties.n = translatePlural
		app.config.globalProperties.OC = OC
		app.config.globalProperties.OCA = OCA
		return app
	})
}
