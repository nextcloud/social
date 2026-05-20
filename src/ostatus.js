import { createApp } from 'vue'
import store from './store/index.js'
import OStatus from './views/OStatus.vue'

// eslint-disable-next-line
const requestToken = window.OC?.requestToken
if (requestToken) {
	__webpack_nonce__ = btoa(requestToken)
}
// eslint-disable-next-line
__webpack_public_path__ = window.OC?.linkTo('social', 'js/') ?? '/apps/social/js/'

const app = createApp(OStatus)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.config.globalProperties.OC = window.OC
app.config.globalProperties.OCA = window.OCA
app.use(store)
app.mount('#content')
