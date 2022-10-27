// SPDX-FileCopyrigthText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

// eslint-disable-next-line
import ProfilePageIntegration from './views/ProfilePageIntegration.vue' 
import Vue from 'vue'
import { generateFilePath } from '@nextcloud/router'
import { translate, translatePlural } from '@nextcloud/l10n'

// eslint-disable-next-line
__webpack_nonce__ = btoa(OC.requestToken)
// eslint-disable-next-line
__webpack_public_path__ = generateFilePath('social', '', 'js/')

if (OCA?.Core?.ProfileSections) {
	Vue.prototype.t = translate
	Vue.prototype.n = translatePlural
	Vue.prototype.OC = OC
	Vue.prototype.OCA = OCA

	const View = Vue.extend(ProfilePageIntegration)

	OCA.Core.ProfileSections.registerSection((el, userId) => {
		return View
	})
}
