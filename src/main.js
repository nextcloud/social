/**
 * @copyright Copyright (c) 2018 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import Vue from 'vue'
import { sync } from 'vuex-router-sync'

import App from './App'
import store from './store'
import router from './router'
import vuetwemoji from 'vue-twemoji'
import contenteditableDirective from 'vue-contenteditable-directive'
import ClickOutside from 'vue-click-outside'
sync(store, router)

// CSP config for webpack dynamic chunk loading
// eslint-disable-next-line
__webpack_nonce__ = btoa(OC.requestToken)

// Correct the root of the app for chunk loading
// OC.linkTo matches the apps folders
// eslint-disable-next-line
__webpack_public_path__ = OC.linkTo('social', 'js/')

Vue.prototype.t = t
Vue.prototype.n = n
Vue.prototype.OC = OC
Vue.prototype.OCA = OCA

Vue.directive('ClickOutside', ClickOutside)
Vue.use(contenteditableDirective)
Vue.use(vuetwemoji, {
	baseUrl: OC.linkTo('social', 'img/'), // can set to local folder of emojis. default: https://twemoji.maxcdn.com/
	extension: '.svg', // .svg, .png
	className: 'emoji', // custom className for image output
	size: 'twemoji' // image size
})

/* eslint-disable-next-line no-new */
new Vue({
	router: router,
	render: h => h(App),
	store: store
}).$mount('#vue-content')
