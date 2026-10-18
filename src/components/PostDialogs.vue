<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<MuteDialog
		v-if="open === 'mute'"
		:open="true"
		:account="item.account"
		:status="item"
		:timelineType="type"
		@update:open="onOpen" />
	<CollectionPickerDialog
		v-if="open === 'collect'"
		:open="true"
		:status="item"
		@update:open="onOpen" />
	<TagPeopleDialog
		v-if="open === 'tag'"
		:nid="item.nid"
		:people="item.tagged_people ?? []"
		@close="close"
		@tagged="onTagged" />
	<QuoteControlDialog
		v-if="open === 'quotes'"
		:nid="item.nid"
		:approval="item.quote_approval"
		@close="close" />
	<ReplyControlDialog
		v-if="open === 'replies'"
		:nid="item.nid"
		:replyPolicy="item.reply_policy ?? 'everyone'"
		@changed="onReplyPolicy"
		@close="close" />
	<NcDialog
		:open="open === 'block'"
		:name="t('social', 'Block {account}?', { account: item.account.acct })"
		:buttons="blockButtons"
		@update:open="onOpen">
		<p class="report-hint">
			{{ t('social', 'Their posts leave your timelines, they are unfollowed both ways, and they can no longer follow you or see your posts.') }}
		</p>
	</NcDialog>
	<NcDialog
		:open="open === 'report'"
		:name="t('social', 'Report {account}', { account: item.account.acct })"
		:buttons="reportButtons"
		@update:open="onOpen">
		<!-- a Bluesky account has nowhere here to be reported but this
		     server's moderators and Bluesky's own moderation service, so
		     passing it on is offered, and ticked, for that one only -->
		<p v-if="reportsToBluesky" class="report-hint">
			{{ t('social', 'The report goes to the moderators of this instance. With the box below ticked, this server also passes it on to Bluesky\'s moderation service, in its own name and not yours.') }}
		</p>
		<p v-else class="report-hint">
			{{ t('social', 'The report goes to the moderators of this instance. It is never sent to the reported account or their server.') }}
		</p>
		<textarea
			v-model="reportComment"
			class="report-comment"
			:placeholder="t('social', 'Why are you reporting this post? (optional)')"
			rows="3" />
		<NcCheckboxRadioSwitch
			v-if="reportsToBluesky"
			v-model="reportForward"
			class="report-forward">
			{{ t('social', 'Also report to Bluesky\'s moderation service') }}
		</NcCheckboxRadioSwitch>
	</NcDialog>
	<DeliveryDialog
		:open="open === 'delivery'"
		:statusId="item.id"
		@update:open="onOpen" />
	<!-- deleting is irreversible and federates: it is not something to
	     do on the first click of a menu item sitting under "Edit" -->
	<NcDialog
		:open="deleting"
		:name="open === 'redraft' ? t('social', 'Delete and write it again?') : t('social', 'Delete this post?')"
		:buttons="deleteButtons"
		@update:open="onOpen">
		<p class="delete-hint">
			{{ open === 'redraft'
				? t('social', 'The post is removed everywhere it reached, and its words, pictures and content warning are put back in the composer. What you post next is a new post: the boosts, likes and replies this one collected stay with it and are gone.')
				: t('social', 'The post is removed from this server and a deletion is sent to every server that received it. This cannot be undone.') }}
		</p>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { defineAsyncComponent } from 'vue'
import DeliveryDialog from './DeliveryDialog.vue'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { isBlueskyAccount } from '../utils/accountLocality.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useTimelineStore } from '../store/timeline.js'

// The mute dialog is the same one the profile opens, and it is worth nothing
// until somebody asks for it: the post menu is on every post on the page.
const MuteDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./MuteDialog.vue'))
// fetched with the other dialogs a post rarely opens, for the same reason
const CollectionPickerDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./CollectionPickerDialog.vue'))
// and the same for naming the people in a photograph, which is a thing an
// author does once per post and no reader ever does
const TagPeopleDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./TagPeopleDialog.vue'))
// same chunk, and for the same reason: a dialog nobody opens until they ask for
// it, which brings framework form controls with it
const QuoteControlDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./QuoteControlDialog.vue'))
const ReplyControlDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./ReplyControlDialog.vue'))

/**
 * The dialogs a post's menu opens, for every layout a post is drawn in.
 *
 * Which one is up is the post's to say, as one name in `open`: the menu that
 * opens them and the focus that comes back to it afterwards both live with
 * the post. What each dialog then does — the block, the report, the delete —
 * is done here.
 */
export default {
	name: 'PostDialogs',
	components: {
		CollectionPickerDialog,
		DeliveryDialog,
		MuteDialog,
		NcCheckboxRadioSwitch,
		NcDialog,
		QuoteControlDialog,
		ReplyControlDialog,
		TagPeopleDialog,
	},

	props: {
		/** the post the dialogs are about */
		item: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			required: true,
		},

		/** the timeline the post is shown in, which muting from it is about */
		type: {
			type: String,
			required: true,
		},

		/**
		 * Which dialog is up: `mute`, `collect`, `tag`, `quotes`, `replies`,
		 * `block`, `report`, `delivery`, `delete` or `redraft`; '' for none.
		 */
		open: {
			type: String,
			default: '',
		},
	},

	emits: ['update:open'],

	data() {
		return {
			reportComment: '',
			/** whether a report about a Bluesky account also goes to Bluesky's moderation service */
			reportForward: true,
		}
	},

	computed: {
		...mapStores(useAccountStore, useTimelineStore),

		/** @return {boolean} whether the delete confirmation is up, for either reason */
		deleting() {
			return this.open === 'delete' || this.open === 'redraft'
		},

		/** @return {boolean} whether a report can be passed on to Bluesky's moderation service */
		reportsToBluesky() {
			return isBlueskyAccount(this.item.account)
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		blockButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => this.close() },
				{ label: t('social', 'Block'), variant: 'error', callback: () => this.blockAuthor() },
			]
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		reportButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => this.close() },
				{ label: t('social', 'Report'), variant: 'error', callback: () => this.sendReport() },
			]
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		deleteButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => this.close() },
				{
					label: this.open === 'redraft' ? t('social', 'Delete & re-draft') : t('social', 'Delete'),
					variant: 'error',
					callback: () => this.remove(),
				},
			]
		},
	},

	methods: {
		t,

		close() {
			this.$emit('update:open', '')
		},

		/**
		 * @param {boolean} open what a dialog said about itself; a dialog
		 *                       only ever closes itself
		 */
		onOpen(open) {
			if (!open) {
				this.close()
			}
		},

		/**
		 * @param {Array} people who the post names now, as the server says
		 */
		onTagged(people) {
			this.timelineStore.updateStatusTagged({ statusId: this.item.id, taggedPeople: people })
		},

		/**
		 * @param {string} policy who may reply now
		 */
		onReplyPolicy(policy) {
			this.timelineStore.updateStatusReplyPolicy({ statusId: this.item.id, replyPolicy: policy })
		},

		/**
		 * Blocks the author, once the dialog has agreed. The store takes their
		 * posts out of every timeline, this one included, so there is nothing
		 * left here to say afterwards.
		 */
		async blockAuthor() {
			const relationship = await this.accountStore.blockAccount({ id: this.item.account.id })
			// the store said what went wrong; the dialog stays for another try
			if (relationship?.id) {
				this.close()
				showSuccess(t('social', 'You have blocked {account}', { account: this.item.account.acct }))
			}
		},

		async sendReport() {
			try {
				await axios.post(generateUrl('apps/social/api/v1/reports'), {
					account_id: this.item.account.id,
					status_ids: [this.item.id],
					comment: this.reportComment,
					...(this.reportsToBluesky ? { forward: this.reportForward } : {}),
				})
				showSuccess(t('social', 'Post reported to the moderators'))
				this.close()
				this.reportComment = ''
				this.reportForward = true
			} catch (error) {
				logger.error('Failed to report the post', { error })
				showError(t('social', 'Failed to report the post'))
			}
		},

		/**
		 * Deletes the post, and — for a re-draft — hands its words, pictures
		 * and warning to the composer.
		 *
		 * The composer is filled *after* the delete rather than before: a
		 * delete the server refuses leaves the post where it is, and a
		 * composer already holding its words would invite somebody to post it
		 * twice.
		 */
		async remove() {
			const redraft = this.open === 'redraft'
			this.close()

			// the post as it stands, kept before the store forgets it
			const draft = redraft ? this.item : null

			await this.timelineStore.postDelete(this.item)

			if (draft !== null) {
				this.timelineStore.setComposerDisplayStatus(true)
				eventBus.emit('composer-redraft', draft)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.delete-hint {
	padding: 0 12px 12px;
	color: var(--color-text-lighter);
	line-height: 1.5;
}
</style>
