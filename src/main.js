/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Vue from 'vue'
import { sync } from 'vuex-router-sync'

import App from './App.vue'
import store from './store/index.js'
import router from './router.js'
import vuetwemoji from 'vue-twemoji'
import ClickOutside from 'vue-click-outside'
import VueMasonry from 'vue-masonry-css'

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
Vue.use(vuetwemoji, {
	baseUrl: OC.linkTo('social', 'img/'), // can set to local folder of emojis. default: https://twemoji.maxcdn.com/
	extension: '.svg', // .svg, .png
	className: 'emoji', // custom className for image output
	size: 'twemoji', // image size
})
Vue.use(VueMasonry)

/* eslint-disable-next-line no-new */
new Vue({
	el: '#content',
	// eslint-disable-next-line vue/match-component-file-name
	name: 'SocialRoot',
	router,
	render: h => h(App),
	store,
})
