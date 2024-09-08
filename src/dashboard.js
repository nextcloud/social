/* jshint esversion: 6 */

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import Dashboard from './views/Dashboard.vue'

// eslint-disable-next-line
__webpack_nonce__ = btoa(OC.requestToken);
// eslint-disable-next-line
__webpack_public_path__ = OC.linkTo('social', 'js/');

Vue.prototype.t = t
Vue.prototype.n = n
Vue.prototype.OC = window.OC

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('social_notifications', (el, { widget }) => {
		const View = Vue.extend(Dashboard)
		/* eslint-disable-next-line no-new */
		new View({
			propsData: { title: widget.title },
			el,
			name: 'SocialDashboard',
		})
	})
})
