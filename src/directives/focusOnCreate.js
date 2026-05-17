import { nextTick } from 'vue'

export default {
	mounted(el) {
		nextTick(() => {
			el.focus()
		})
	},
}
