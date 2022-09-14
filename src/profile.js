// SPDX-FileCopyrigthText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

// eslint-disable-next-line
__webpack_nonce__ = btoa(OC.requestToken)
// eslint-disable-next-line
__webpack_public_path__ = OC.linkTo('social', 'js/')

import ProfilePageIntegration from './views/ProfilePageIntegration.vue' 
import Vue from 'vue'
import { sync } from 'vuex-router-sync'

if (!OCA?.Core?.ProfileSections) {
	exit();
}

Vue.prototype.t = t
Vue.prototype.n = n
Vue.prototype.OC = OC
Vue.prototype.OCA = OCA

const View = Vue.extend(ProfilePageIntegration)

OCA.Core.ProfileSections.registerSection((el, userId) => {
	return View
})
