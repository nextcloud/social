<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="social-profile">
		<h2 class="social-profile__title">
			{{ t('social', 'Social') }}
		</h2>
		<transition-group name="list" tag="ul" class="social-profile__timeline">
			<ProfileStatusCard
				v-for="entry in timeline"
				:key="entry.id"
				:status="entry" />
		</transition-group>
	</section>
</template>

<script>
import ProfileStatusCard from './../components/ProfileStatusCard.vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import logger from './../services/logger.js'

export default {
	name: 'ProfilePageIntegration',
	components: {
		ProfileStatusCard,
	},

	props: {
		userId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			timeline: [],
		}
	},

	computed: {
	},

	// Start fetching account information before mounting the component
	beforeMount() {
		const uid = this.userId

		if (!uid) {
			return
		}

		axios.get(generateUrl(`apps/social/api/v1/accounts/${encodeURIComponent(uid)}/statuses`)).then(({ data }) => {
			this.timeline = data
			logger.debug('Loaded profile timeline', { timeline: this.timeline })
		}).catch((error) => {
			logger.error('Failed to load profile timeline', { error, uid })
		})
	},
}
</script>

<style scoped>
.social-profile {
	min-width: 0;
}

.social-profile__title {
	margin-block: 0 0.75rem;
}

.social-profile__timeline {
	list-style: none;
	margin: 0;
	padding: 0;
}
</style>
