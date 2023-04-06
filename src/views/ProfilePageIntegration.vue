<template>
	<div>
		<h2>Social</h2>
		<transition-group name="list" tag="ul">
			<TimelineEntry v-for="entry in timeline"
				:key="entry.id"
				:item="entry" />
		</transition-group>
	</div>
</template>

<script>
import TimelineEntry from './../components/TimelineEntry.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import logger from './../services/logger.js'

export default {
	name: 'ProfilePageIntegration',
	components: {
		TimelineEntry,
	},
	props: {
		userId: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			accountInfo: null,
			timeline: [],
		}
	},
	computed: {
		getCount() {
			const account = this.accountInfo
			return (field) => account.details.count ? account.details.count[field] : ''
		},
	},
	// Start fetching account information before mounting the component
	beforeMount() {
		const uid = this.userId

		axios.get(generateUrl(`apps/social/api/v1/account/${uid}/info`)).then((response) => {
			this.accountInfo = response.data.result.account
			logger.log(this.accountInfo)
		})

		const since = Math.floor(Date.now() / 1000) + 1

		axios.get(generateUrl(`apps/social/api/v1/account/${uid}/stream?limit=25&since=${since}`)).then(({ data }) => {
			logger.log(this.timeline)
			this.timeline = data.result
		})
	},
}
</script>
