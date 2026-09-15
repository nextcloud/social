<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('social', 'Refused pictures')"
		:description="t('social', 'Files this instance will not store, named by their checksum. Every other tool here acts on an account, and none of them stops a file coming back: the account is suspended, the picture is posted again by the next one, and you are deleting the same image for the third time. A refused file is turned away wherever it arrives — an upload here, or an attachment fetched from another server.')">
		<div class="media-blocks__add">
			<NcTextField
				v-model="hash"
				class="media-blocks__hash"
				:label="t('social', 'Checksum (sha256)')"
				placeholder="e3b0c44298fc1c14…"
				:disabled="busy" />
			<NcTextField
				v-model="reason"
				class="media-blocks__reason"
				:label="t('social', 'Why, for whoever reads this next year')"
				:disabled="busy" />
			<NcButton :disabled="busy || hash.trim() === ''" @click="add">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Refuse this file') }}
			</NcButton>
		</div>
		<p class="social-admin__hint">
			{{ t('social', 'The checksum of a file is what sha256sum prints for it. It matches that exact file: a re-encoded or re-cropped copy is a different file and is not caught.') }}
		</p>

		<NcEmptyContent v-if="blocks.length === 0" :name="t('social', 'Nothing is refused.')">
			<template #icon>
				<IconCheckCircle :size="20" />
			</template>
		</NcEmptyContent>

		<table v-else class="media-blocks__table">
			<thead>
				<tr>
					<th>{{ t('social', 'Checksum') }}</th>
					<th>{{ t('social', 'Why') }}</th>
					<th>{{ t('social', 'Refused since') }}</th>
					<th>{{ t('social', 'Turned away') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="block in blocks" :key="block.hash">
					<td class="media-blocks__cell-hash">
						<code>{{ short(block.hash) }}</code>
					</td>
					<td>{{ block.reason }}</td>
					<td>{{ since(block.creation) }} <span v-if="block.moderator">· {{ block.moderator }}</span></td>
					<td>{{ block.blocked }}</td>
					<td class="media-blocks__cell-actions">
						<NcButton :disabled="busy" @click="remove(block)">
							{{ t('social', 'Allow again') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import IconCheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { moderationUrl } from '../../services/adminApi.js'
import { showError } from '../../services/toast.js'

/**
 * The files this instance refuses.
 *
 * The count is the column worth having: a list with no evidence is one nobody
 * dares remove anything from a year later, and "turned away 41 times" and
 * "never" are different decisions to review.
 */
export default {
	name: 'MediaBlocksSection',

	components: {
		IconCheckCircle,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			blocks: [],
			hash: '',
			reason: '',
			busy: false,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @param {string} hash the whole checksum
		 * @return {string} enough of it to recognise a row by
		 */
		short(hash) {
			return hash.slice(0, 16) + '…'
		},

		/**
		 * @param {string} date when it was refused
		 * @return {string} in the reader's own format
		 */
		since(date) {
			const when = new Date(date)

			return isNaN(when.getTime()) ? '' : when.toLocaleDateString()
		},

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(moderationUrl('/media/blocks'))
				this.blocks = data.blocks ?? []
			} catch {
				showError(t('social', 'Could not load the refused files'))
			}
		},

		/** @return {Promise<void>} */
		async add() {
			this.busy = true
			try {
				const { data } = await axios.post(moderationUrl('/media/blocks'), {
					hash: this.hash.trim(),
					reason: this.reason.trim(),
				})
				this.blocks = data.blocks ?? []
				this.hash = ''
				this.reason = ''
			} catch (error) {
				showError(error.response?.data?.error ?? t('social', 'Could not refuse that file'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} block the row to remove
		 * @return {Promise<void>}
		 */
		async remove(block) {
			this.busy = true
			try {
				const { data } = await axios.delete(moderationUrl('/media/blocks'), {
					data: { hash: block.hash },
				})
				this.blocks = data.blocks ?? []
			} catch {
				showError(t('social', 'Could not allow that file again'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.media-blocks__add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}

.media-blocks__hash {
	max-width: 320px;
}

.media-blocks__reason {
	max-width: 360px;
}

.media-blocks__table {
	width: 100%;
	table-layout: fixed;
	border-collapse: collapse;

	th,
	td {
		padding: 8px 12px 8px 0;
		text-align: start;
		vertical-align: top;
		// the settings page sets `nowrap` on table cells above this one
		white-space: normal;
		overflow-wrap: break-word;
		border-block-end: 1px solid var(--color-border);
	}
}

.media-blocks__cell-hash code {
	font-size: 12px;
}

.media-blocks__cell-actions {
	text-align: end;
}
</style>
