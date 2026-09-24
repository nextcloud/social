<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="tagged">
		<TimelineSwitcher
			:options="kinds"
			value="tagged"
			:label="t('social', 'Which of their posts to show')" />

		<NcLoadingIcon v-if="loading" class="tagged__loading" :size="32" />

		<div v-else-if="error" class="tagged__error" role="alert">
			<p>{{ error }}</p>
			<NcButton variant="primary" @click="load">
				<template #icon>
					<IconRefresh :size="20" />
				</template>
				{{ t('social', 'Try again') }}
			</NcButton>
		</div>

		<ul v-else-if="posts.length" class="tagged__list">
			<TimelineEntry
				v-for="post in posts"
				:key="post.id"
				:item="post"
				type="account" />
		</ul>

		<NcEmptyContent
			v-else
			:name="t('social', 'No photos of them')"
			:description="emptyDescription">
			<template #icon>
				<IconAccountBoxMultiple :size="20" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import IconAccountBoxMultiple from 'vue-material-design-icons/AccountBoxMultiple.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import TimelineEntry from '../components/TimelineEntry.vue'
import TimelineSwitcher from '../components/TimelineSwitcher.vue'
import logger from '../services/logger.js'
import { profileKinds } from '../composables/useProfileKinds.js'
import { latestLoad } from '../utils/latestLoad.js'

/**
 * The photographs somebody else took that this account is named in.
 *
 * Not a filter of the account's own posts: every post here belongs to
 * somebody else, which is why it is a page rather than a fourth kind beside
 * Photos and Videos. What the reader may see is the server's decision — a
 * post they could not otherwise read is simply not in the answer — so this
 * page renders what it is given and asks no questions of its own.
 */
export default {
	name: 'ProfileTagged',

	components: {
		IconAccountBoxMultiple,
		IconRefresh,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		TimelineEntry,
		TimelineSwitcher,
	},

	data() {
		return {
			posts: [],
			loading: true,
			error: '',
			loads: latestLoad(),
		}
	},

	computed: {
		/** @return {Array} the five places a profile can be read */
		kinds() {
			return profileKinds(this.$route.params.account)
		},

		/** @return {string} */
		emptyDescription() {
			return t('social', 'When somebody names them in a photo, it shows up here.')
		},
	},

	watch: {
		'$route.params.account': 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async load() {
			const isNewest = this.loads.begin()
			this.loading = true
			this.error = ''
			try {
				const account = this.$route.params.account
				const url = generateUrl('apps/social/api/v1.1/accounts/{account}/tagged', { account })
				const { data } = await axios.get(url)
				if (isNewest()) {
					this.posts = Array.isArray(data) ? data : []
				}
			} catch (error) {
				logger.error('could not load the photos somebody is tagged in', { error })
				if (isNewest()) {
					this.error = t('social', 'Could not load these photos')
				}
			} finally {
				if (isNewest()) {
					this.loading = false
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.tagged__loading {
	margin-block: 32px;
}

.tagged__error {
	text-align: center;
	margin-block: 24px;
}

.tagged__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
