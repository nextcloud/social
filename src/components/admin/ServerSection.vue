<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('social', 'Server')"
		:description="description">
		<div class="server">
			<NcTextField
				v-model="form.contactEmail"
				class="server__field"
				type="email"
				:label="t('social', 'Contact address')"
				placeholder="admin@instance.example"
				:helperText="t('social', 'Shown to anybody asking this server what it is. Clients read it on their first request.')" />

			<NcTextArea
				v-model="form.extendedDescription"
				class="server__field"
				:label="t('social', 'About this instance')"
				:placeholder="t('social', 'Who runs this server, who it is for, and what is expected of the people on it.')"
				rows="4" />

			<div class="server__sizes">
				<NcTextField
					v-model="form.maxSize"
					class="server__number"
					type="number"
					min="1"
					max="10240"
					:label="t('social', 'Largest picture or file (MB)')" />
				<NcTextField
					v-model="form.maxVideoSize"
					class="server__number"
					type="number"
					min="1"
					max="102400"
					:label="t('social', 'Largest video (MB)')" />
			</div>

			<div class="server__sizes">
				<NcTextField
					v-model="form.imageMaxEdge"
					class="server__number"
					type="number"
					min="0"
					max="16384"
					:label="t('social', 'Shrink pictures to (pixels)')"
					:helperText="t('social', '0 stores every upload exactly as it arrived, which is the default and the only setting that loses nothing. A ceiling saves disk and bandwidth; a picture 8000 pixels wide is not being looked at at 8000 pixels.')" />
				<NcTextField
					v-model="form.imageQuality"
					class="server__number"
					type="number"
					min="40"
					max="100"
					:label="t('social', 'Stored at quality')"
					:helperText="t('social', 'Only used when a ceiling is set above, because otherwise nothing is re-encoded.')" />
			</div>

			<NcTextField
				v-model="form.inboxThrottle"
				class="server__field"
				type="number"
				min="0"
				max="100000"
				:label="t('social', 'Inbox requests allowed per instance per minute')"
				:helperText="t('social', '0 accepts everything, which is what an instance behind its own rate limiter wants.')" />

			<NcCheckboxRadioSwitch v-model="form.secureMode" type="switch">
				{{ t('social', 'Secure mode') }}
			</NcCheckboxRadioSwitch>
			<p class="social-admin__hint">
				{{ t('social', 'Unsigned ActivityPub fetches are refused. Servers that do not sign what they ask for — and every anonymous reader — stop seeing anything from this one.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="form.publishBlocks" type="switch">
				{{ t('social', 'Publish the list of blocked instances') }}
			</NcCheckboxRadioSwitch>
			<p class="social-admin__hint">
				{{ t('social', 'Anybody can then read which instances this server refuses, the way Mastodon publishes it.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="form.allowSelfSigned" type="switch">
				{{ t('social', 'Accept certificates that do not check out') }}
			</NcCheckboxRadioSwitch>
			<NcNoteCard type="warning">
				<strong>{{ t('social', 'For development only.') }}</strong>
				{{ t('social', 'On a server anybody else uses, this hands every federated request to whoever can answer for the address.') }}
			</NcNoteCard>

			<NcNoteCard v-if="message !== ''" :type="saved ? 'success' : 'error'">
				{{ message }}
			</NcNoteCard>

			<NcButton variant="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save') }}
			</NcButton>
		</div>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, serverUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * What this instance says about itself, and the limits it holds peers to.
 *
 * Rendered for an administrator and not for a delegate: what it holds is
 * administration rather than moderation, and its endpoint refuses anybody who
 * is not an administrator anyway. The parent decides that — the settings are
 * simply not in the initial state of a delegate.
 */
export default {
	name: 'ServerSection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** `ServerSettingsService::current()`, as initial state */
		settings: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			form: {
				contactEmail: this.settings.contact_email ?? '',
				extendedDescription: this.settings.extended_description ?? '',
				maxSize: String(this.settings.max_size ?? 10),
				maxVideoSize: String(this.settings.max_video_size ?? 2048),
				imageMaxEdge: String(this.settings.image_max_edge ?? 0),
				imageQuality: String(this.settings.image_quality ?? 85),
				inboxThrottle: String(this.settings.inbox_throttle ?? 300),
				secureMode: this.settings.secure_mode === true,
				publishBlocks: this.settings.publish_blocks === true,
				allowSelfSigned: this.settings.allow_self_signed === true,
			},

			message: '',
			saved: false,
			saving: false,
		}
	},

	computed: {
		description() {
			return t('social', 'What this instance tells other servers and their clients about itself, and the limits it holds them to. Every one of these could only be set with "occ config:app:set social" until now, which meant most of them were never set at all.')
		},
	},

	methods: {
		t,

		/**
		 * Writes the whole card, or none of it.
		 *
		 * The endpoint validates every field and writes all or nothing, so what
		 * is reported here is what stands: a card that saved six of its eight
		 * fields would leave an administrator guessing which.
		 *
		 * @return {Promise<boolean>} whether it was taken
		 */
		async save() {
			this.saving = true
			try {
				await axios.post(serverUrl(), {
					contactEmail: this.form.contactEmail.trim(),
					extendedDescription: this.form.extendedDescription,
					maxSize: parseInt(this.form.maxSize, 10),
					maxVideoSize: parseInt(this.form.maxVideoSize, 10),
					imageMaxEdge: parseInt(this.form.imageMaxEdge, 10),
					imageQuality: parseInt(this.form.imageQuality, 10),
					inboxThrottle: parseInt(this.form.inboxThrottle, 10),
					secureMode: this.form.secureMode,
					publishBlocks: this.form.publishBlocks,
					allowSelfSigned: this.form.allowSelfSigned,
				})
				this.saved = true
				this.message = t('social', 'Saved')
				showSuccess(t('social', 'The server settings were saved'))

				return true
			} catch (error) {
				// the endpoint names the field it would not take; it is the one
				// thing that tells the administrator what to change
				this.saved = false
				this.message = errorMessage(error, t('social', 'Could not save the server settings'))
				showError(t('social', 'Could not save the server settings'))

				return false
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.server {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	// stretch, not flex-start: a field sized to its own content is a field
	// whose floating label is cut off in the middle of a word, and the
	// longest label here is longer than the number it sits above
	align-items: stretch;
	max-width: 600px;

	&__sizes {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	// the two megabyte fields share a row until the column is too narrow for
	// both labels, and then they stack
	&__number {
		flex: 1 1 240px;
	}
}
</style>
