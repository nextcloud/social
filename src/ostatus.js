import { createApp } from 'vue'
import store from './store/index.js'
import OStatus from './views/OStatus.vue'

// eslint-disable-next-line
__webpack_nonce__ = btoa(OC.requestToken)
// eslint-disable-next-line
__webpack_public_path__ = OC.linkTo('social', 'js/')

const app = createApp(OStatus)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.config.globalProperties.OC = OC
app.config.globalProperties.OCA = OCA
app.use(store)
app.mount('#content')
