<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="!serverData.public" class="followed-hashtags">
		<NcButton
			variant="tertiary"
			class="followed-hashtags__toggle"
			:aria-expanded="open ? 'true' : 'false'"
			aria-controls="followed-hashtags-list"
			@click="toggle">
			<template #icon>
				<Pound :size="20" />
			</template>
			{{ t('social', 'Followed hashtags') }}
		</NcButton>

		<!-- always in the tree, so the control it is named by has something to
		     point at whether it is open or not -->
		<div id="followed-hashtags-list" class="followed-hashtags__panel">
			<template v-if="open">
				<p v-if="loading" class="followed-hashtags__hint">
					{{ t('social', 'Loading …') }}
				</p>
				<ul v-else-if="tags.length > 0" class="followed-hashtags__list">
					<li v-for="tag in tags" :key="tag.name">
						<router-link :to="{ name: 'tags', params: { tag: tag.name } }">
							{{ '#' + tag.name }}
						</router-link>
					</li>
				</ul>
				<p v-else class="followed-hashtags__hint">
					{{ t('social', 'You are not following any hashtag yet.') }}
				</p>
			</template>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import Pound from 'vue-material-design-icons/Pound.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import logger from '../services/logger.js'
import { useServerData } from '../composables/useServerData.js'

/** what the server caps a page of this list at anyway */
const PAGE_SIZE = 50

export default {
	name: 'HashtagFollowedList',
	components: {
		NcButton,
		Pound,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			open: false,
			loading: false,
			/** @type {object[]} Tag entities */
			tags: [],
		}
	},

	methods: {
		t: translate,
		toggle() {
			this.open = !this.open
			if (this.open) {
				this.load()
			}
		},

		/** Re-reads the list, but only while somebody is looking at it. */
		refresh() {
			if (this.open) {
				this.load()
			}
		},

		async load() {
			if (this.loading) {
				return
			}

			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('apps/social/api/v1/followed_tags'),
					{ params: { limit: PAGE_SIZE } },
				)
				this.tags = Array.isArray(data) ? data : []
			} catch (error) {
				logger.error('Failed to load the followed hashtags', { error })
				showError(translate('social', 'Could not load the hashtags you follow'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.followed-hashtags {
	margin: 0 calc(var(--default-grid-baseline) * 2);
}

.followed-hashtags__list {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: var(--default-grid-baseline) 0;

	a {
		display: inline-block;
		padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-hover);
		color: var(--color-main-text);
	}
}

.followed-hashtags__hint {
	color: var(--color-text-lighter);
	margin: var(--default-grid-baseline) 0;
}
</style>
