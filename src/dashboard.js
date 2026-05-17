import { createApp } from 'vue'
import Dashboard from './views/Dashboard.vue'

// eslint-disable-next-line
__webpack_nonce__ = btoa(OC.requestToken);
// eslint-disable-next-line
__webpack_public_path__ = OC.linkTo('social', 'js/');

document.addEventListener('DOMContentLoaded', function() {
	OCA.Dashboard.register('social_notifications', (el, { widget }) => {
		const app = createApp(Dashboard, { title: widget.title })
		app.config.globalProperties.t = t
		app.config.globalProperties.n = n
		app.config.globalProperties.OC = window.OC
		app.mount(el)
	})
})
