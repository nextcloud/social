import { createApp } from 'vue'
import Dashboard from './views/Dashboard.vue'

// eslint-disable-next-line
const requestToken = window.OC?.requestToken
if (requestToken) {
	__webpack_nonce__ = btoa(requestToken)
}
// eslint-disable-next-line
__webpack_public_path__ = window.OC?.linkTo('social', 'js/') ?? '/apps/social/js/'

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('social_notifications', (el, { widget }) => {
		const app = createApp(Dashboard, { title: widget.title })
		app.config.globalProperties.t = t
		app.config.globalProperties.n = n
		app.config.globalProperties.OC = window.OC
		app.config.globalProperties.OCA = window.OCA
		app.mount(el)
	})
})
