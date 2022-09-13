<template>
	<div>
		<h2>Social</h2>
		<transition-group name="list" tag="div">
			<TimelineEntry v-for="entry in timeline" :key="entry.id" :item="entry" :isProfilePage="true" />
		</transition-group>
	</div>
</template>

<script>
import ProfileInfo from './../components/ProfileInfo.vue'
import TimelineEntry from './../components/TimelineEntry.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ProfilePageIntegration',
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
	components: {
		ProfileInfo,
		TimelineEntry,
	},
	computed: {
		getCount() {
			let account = this.accountInfo
			return (field) => account.details.count ? account.details.count[field] : ''
		},
	},
	// Start fetching account information before mounting the component
	beforeMount() {
		let fetchMethod = 'fetchPublicAccountInfo'

		let uid = this.userId

		axios.get(generateUrl(`apps/social/api/v1/account/${uid}/info`)).then((response) => {
			this.accountInfo = response.data.result.account
			console.log(this.accountInfo)
		})

		const since = Math.floor(Date.now() / 1000) + 1

		axios.get(generateUrl(`apps/social/api/v1/account/${uid}/stream?limit=25&since=${since}`)).then(({ data }) => {
			console.log(this.timeline)
			this.timeline = data.result
		})
	}
}
</script>
