<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection
		:name="t('social', 'Storage')"
		:description="t('social', 'What this app is keeping on disk, and which half of it you can do something about. Added up by the background job once a day: counting it is one file lookup per stored file, which is not something to do while a settings page loads.')">
		<p v-if="!storage" class="social-admin__hint">
			{{ t('social', 'Nothing has been measured yet. The background job adds this up once a day; it will appear after the next run.') }}
		</p>
		<template v-else>
			<div class="storage">
				<div class="storage__figure">
					<span class="storage__number">{{ human(localBytes) }}</span>
					<span class="storage__label">{{ t('social', 'posted from here') }}</span>
					<span class="storage__sub">{{ n('social', '%n file', '%n files', localFiles) }}</span>
				</div>
				<div class="storage__figure">
					<span class="storage__number">{{ human(remoteBytes) }}</span>
					<span class="storage__label">{{ t('social', 'cached from other servers') }}</span>
					<span class="storage__sub">{{ n('social', '%n file', '%n files', remoteFiles) }}</span>
				</div>
			</div>
			<p class="social-admin__hint">
				{{ t('social', 'What was posted from here is somebody\'s own work and is not going anywhere. The cached half is copies of other servers\' pictures, and is what Retention above removes — a shorter retention is the lever for this number.') }}
			</p>
			<p class="social-admin__hint">
				{{ t('social', 'Measured {when}.', { when: measuredAt }) }}
				<span v-if="storage.missing > 0">
					{{ n('social', '%n file named in the database was not on disk.', '%n files named in the database were not on disk.', storage.missing) }}
				</span>
			</p>
		</template>
	</NcSettingsSection>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

/**
 * Two numbers about disk, and which of them an administrator can act on.
 *
 * The split is the point. "Social is using 40 GB" is not actionable; "31 GB of
 * it is cached copies of other servers' pictures, and Retention is the lever"
 * is.
 */
export default {
	name: 'StorageSection',

	components: {
		NcSettingsSection,
	},

	props: {
		/** the last measurement, or null when none has been taken */
		storage: {
			type: Object,
			default: null,
		},
	},

	computed: {
		localBytes() {
			return this.sideBytes('local')
		},

		localFiles() {
			return this.sideFiles('local')
		},

		remoteBytes() {
			return this.sideBytes('remote')
		},

		remoteFiles() {
			return this.sideFiles('remote')
		},

		/** @return {string} when it was measured, in the reader's own format */
		measuredAt() {
			const when = new Date((this.storage?.measured ?? 0) * 1000)

			return isNaN(when.getTime()) ? '' : when.toLocaleString()
		},
	},

	methods: {
		t,
		n,

		/**
		 * @param {string} side 'local' or 'remote'
		 * @return {number} the bytes on that side, attachments and avatars
		 */
		sideBytes(side) {
			const half = this.storage?.[side] ?? {}

			return (half.attachments?.bytes ?? 0) + (half.avatars?.bytes ?? 0)
		},

		/**
		 * @param {string} side 'local' or 'remote'
		 * @return {number} the files on that side
		 */
		sideFiles(side) {
			const half = this.storage?.[side] ?? {}

			return (half.attachments?.files ?? 0) + (half.avatars?.files ?? 0)
		},

		/**
		 * @param {number} bytes how many
		 * @return {string} in the units a person reads
		 */
		human(bytes) {
			const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
			let value = bytes
			let unit = 0
			while (value >= 1024 && unit < units.length - 1) {
				value /= 1024
				unit++
			}

			return `${value < 10 && unit > 0 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`
		},
	},
}
</script>

<style lang="scss" scoped>
.storage {
	display: flex;
	gap: 32px;
	flex-wrap: wrap;
	margin-block-end: 8px;
}

.storage__figure {
	display: flex;
	flex-direction: column;
}

.storage__number {
	font-size: 32px;
	font-weight: bold;
	line-height: 1.1;
}

.storage__label {
	font-weight: bold;
}

.storage__sub {
	color: var(--color-text-maxcontrast);
}
</style>
