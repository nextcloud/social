import { createApp } from 'vue'
import App from './App.vue'
import store from './store/index.js'
import router from './router.js'
import twemoji from 'twemoji'

// CSP config for webpack dynamic chunk loading
// eslint-disable-next-line
const requestToken = window.OC?.requestToken
if (requestToken) {
	__webpack_nonce__ = btoa(requestToken)
}

// Correct the root of the app for chunk loading
// eslint-disable-next-line
__webpack_public_path__ = window.OC?.linkTo('social', 'js/') ?? '/apps/social/js/'

const app = createApp(App)

app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.config.globalProperties.OC = window.OC
app.config.globalProperties.OCA = window.OCA
app.config.globalProperties.$twemoji = twemoji

app.use(store)
app.use(router)
app.mount('#content')
