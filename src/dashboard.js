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
import { translate, translatePlural } from '@nextcloud/l10n'
import Dashboard from './views/Dashboard'

Vue.prototype.t = translate
Vue.prototype.n = translatePlural
Vue.prototype.OC = window.OC
Vue.prototype.OCA = window.OCA

document.addEventListener('DOMContentLoaded', function() {

	OCA.Dashboard.register('social_notifications', (el, { widget }) => {
		const View = Vue.extend(Dashboard)
		new View({
			propsData: { title: widget.title },
		}).$mount(el)
	})

})
