/* jshint esversion: 6 */

/**
 * Nextcloud - social
 *
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
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
		new View({
			propsData: { title: widget.title }
		}).$mount(el)
	})
})
