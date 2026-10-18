<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="post-edit-inline">
		<input
			v-model="spoiler"
			type="text"
			class="post-edit-warning"
			dir="auto"
			maxlength="200"
			:aria-label="t('social', 'Content warning')"
			:placeholder="t('social', 'Content warning, e.g. what the post is about')">
		<textarea
			ref="input"
			v-model="content"
			class="post-edit-textarea"
			dir="auto"
			:maxlength="maxLength"
			:aria-describedby="isTooLong ? `post-edit-count-${item.id}` : undefined"
			:placeholder="t('social', 'Edit your post')"
			@keydown.ctrl.enter="save" />
		<div class="post-edit-actions">
			<span
				:id="`post-edit-count-${item.id}`"
				class="post-edit-count"
				:class="{ 'post-edit-count--over': isTooLong }"
				role="status">
				{{ charactersLeftLabel }}
			</span>
			<NcButton
				variant="primary"
				:disabled="!canSave"
				:aria-label="t('social', 'Save')"
				@click="save">
				{{ t('social', 'Save') }}
			</NcButton>
			<NcButton
				:aria-label="t('social', 'Cancel')"
				@click="$emit('close')">
				{{ t('social', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { htmlToPlainText } from '../utils/plainText.js'
import { mapStores } from 'pinia'
import { useInstanceStore } from '../store/instance.js'
import { useTimelineStore } from '../store/timeline.js'

/**
 * A post's words and content warning, edited where the post is.
 *
 * Every layout a post is drawn in offers Edit from its menu, and each puts
 * this form where its text goes.
 */
export default {
	name: 'PostEditForm',
	components: {
		NcButton,
	},

	props: {
		/** the post being edited, as the timeline holds it */
		item: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			// never the author's bio: an image-only post has no content, and
			// seeding the editor from account.note offered to publish it
			content: htmlToPlainText(this.item.content || ''),
			spoiler: this.item.spoiler_text || '',
		}
	},

	computed: {
		...mapStores(useInstanceStore, useTimelineStore),

		/** @return {number} what the server accepts in one status, as the composer shows it */
		maxLength() {
			return this.instanceStore.maxCharacters
		},

		/** @return {number} how many characters the edit has left */
		charsLeft() {
			return this.maxLength - this.content.length
		},

		/** @return {boolean} */
		isTooLong() {
			return this.charsLeft < 0
		},

		/** @return {boolean} */
		canSave() {
			return this.content.trim() !== '' && !this.isTooLong
		},

		/** @return {string} */
		charactersLeftLabel() {
			return this.isTooLong
				? n('social', '%n character too many', '%n characters too many', -this.charsLeft)
				: n('social', '%n character left', '%n characters left', this.charsLeft)
		},
	},

	mounted() {
		/** @type {HTMLElement|undefined} */ (this.$refs.input)?.focus()
	},

	methods: {
		t,

		async save() {
			if (!this.canSave) {
				return
			}

			const warning = this.spoiler.trim()
			const response = await this.timelineStore.postEdit({
				status: this.item,
				content: this.content.trim(),
				// fixing a typo must not un-hide sensitive content for every
				// follower, so the warning goes back as it stands
				spoiler_text: warning,
				sensitive: warning !== '' || this.item.sensitive === true,
			})
			if (response === undefined) {
				// the store already said so; keep what was typed
				return
			}

			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.post-edit-inline {
	margin-bottom: 10px;

	.post-edit-textarea {
		width: 100%;
		min-height: 100px;
		padding: 8px;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-family: inherit;
		font-size: 15px;
		line-height: 1.65;
		resize: vertical;
		box-sizing: border-box;

		&:focus-visible {
			border-color: var(--color-primary-element);
			outline: 2px solid var(--color-primary-element);
			outline-offset: 1px;
		}
	}

	.post-edit-warning {
		width: 100%;
		margin-bottom: 6px;
		padding: 8px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius, 8px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-size: 14px;
		box-sizing: border-box;

		&:focus-visible {
			border-color: var(--color-primary-element);
			outline: 2px solid var(--color-primary-element);
			outline-offset: 1px;
		}
	}

	.post-edit-actions {
		display: flex;
		gap: 8px;
		margin-top: 8px;
		align-items: center;
		justify-content: flex-end;
	}

	.post-edit-count {
		margin-inline-end: auto;
		font-size: 12px;
		color: var(--color-text-lighter);

		&--over {
			color: var(--color-error);
			font-weight: 600;
		}
	}
}
</style>
