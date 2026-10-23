<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="!dismissed" class="feed-scope-notice" role="note">
		<p class="feed-scope-notice__text">
			{{ text }}
		</p>
		<NcButton
			variant="tertiary"
			:aria-label="t('social', 'Dismiss')"
			:title="t('social', 'Dismiss')"
			@click="dismiss">
			<template #icon>
				<Close :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'FeedScopeNotice',

	components: {
		Close,
		NcButton,
	},

	props: {
		scope: {
			type: String,
			required: true,
		},

		account: {
			type: String,
			default: '',
		},

		text: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			dismissed: wasDismissed(storageKey(this.account, this.scope)),
		}
	},

	computed: {
		storageKey() {
			return storageKey(this.account, this.scope)
		},
	},

	methods: {
		t,

		dismiss() {
			this.dismissed = true
			try {
				window.localStorage.setItem(this.storageKey, '1')
			} catch {
				// Keep the dismissal for this view even when storage is unavailable.
			}
		},
	},
}

/** @param {string} key @return {boolean} whether the reader closed this note */
function wasDismissed(key) {
	try {
		return window.localStorage.getItem(key) !== null
	} catch {
		return false
	}
}

/**
 * @param {string} account the account scope
 * @param {string} scope the feed scope
 * @return {string}
 */
function storageKey(account, scope) {
	return `social:feed-scope-notice:${account || 'anonymous'}:${scope}`
}
</script>

<style scoped lang="scss">
.feed-scope-notice {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 4px calc(var(--default-grid-baseline) * 2) 12px;
	padding: 4px 4px 4px 14px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);

	&__text {
		flex: 1;
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}
}
</style>
