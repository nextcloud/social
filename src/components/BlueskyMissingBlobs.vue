<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcNoteCard type="warning" class="migration__bluesky-missing">
		<p>
			{{ n('social', '%n picture or video of your posts could not be copied here.', '%n pictures or videos of your posts could not be copied here.', count) }}
			{{ t('social', 'Your account is not pointed at this server until you decide.') }}
		</p>
		<p v-if="error" class="migration__bluesky-missing-error" role="alert">
			{{ error }}
		</p>
		<div class="migration__bluesky-missing-actions">
			<NcButton :disabled="busy" @click="decide(false)">
				{{ t('social', 'Try copying them again') }}
			</NcButton>
			<NcButton :disabled="busy" @click="decide(true)">
				{{ t('social', 'Go on without them') }}
			</NcButton>
		</div>
	</NcNoteCard>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError } from '../services/toast.js'

/**
 * A move here that stopped because pictures or videos did not arrive
 * (`needs_decision`): the person has them copied again or goes on without
 * them. Emits the move as the server answers it.
 */
export default {
	name: 'BlueskyMissingBlobs',

	components: {
		NcButton,
		NcNoteCard,
	},

	props: {
		/** the move, as the server keeps it */
		move: {
			type: Object,
			required: true,
		},
	},

	emits: ['move'],

	data() {
		return {
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @return {number} how many did not arrive */
		count() {
			return this.move.progress?.missing?.count ?? 0
		},
	},

	methods: {
		n,
		t,

		/**
		 * @param {boolean} withoutMissing whether the move goes on without them
		 * @return {Promise<void>}
		 */
		async decide(withoutMissing) {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/move-in/continue'), { without_missing: withoutMissing })
				this.$emit('move', data?.move)
			} catch (error) {
				if (error?.response?.status === 422) {
					this.error = error.response.data?.error || t('social', 'Could not go on with the move')
				} else {
					showError(t('social', 'Could not go on with the move'))
				}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.migration__bluesky-missing-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.migration__bluesky-missing-error {
	color: var(--color-error-text, var(--color-error));
}
</style>
