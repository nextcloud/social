import { createApp } from 'vue'
import OAuth2Authorize from './views/OAuth2Authorize.vue'

// eslint-disable-next-line
const requestToken = window.OC?.requestToken
if (requestToken) {
	__webpack_nonce__ = btoa(requestToken)
}
// eslint-disable-next-line
__webpack_public_path__ = window.OC?.linkTo('social', 'js/') ?? '/apps/social/js/'

const app = createApp(OAuth2Authorize)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.config.globalProperties.OC = window.OC
app.config.globalProperties.OCA = window.OCA
app.mount('#social-oauth2')
