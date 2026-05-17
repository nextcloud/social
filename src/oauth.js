import { createApp } from 'vue'
import OAuth2Authorize from './views/OAuth2Authorize.vue'

// eslint-disable-next-line
__webpack_nonce__ = btoa(OC.requestToken)
// eslint-disable-next-line
__webpack_public_path__ = OC.linkTo('social', 'js/')

const app = createApp(OAuth2Authorize)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.config.globalProperties.OC = OC
app.config.globalProperties.OCA = OCA
app.mount('#social-oauth2')
