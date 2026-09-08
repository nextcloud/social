<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div v-if="item.account"
		class="post-avatar"
		:class="{ 'post-avatar--remote': !origin.local }"
		:style="{ '--instance-colour': origin.colour }"
		:title="origin.local ? undefined : origin.instance">
		<NcAvatar v-if="isLocal"
			class="messages__avatar__icon"
			:show-user-status="false"
			menu-position="left"
			:user="item.account.username"
			:display-name="item.account.display_name"
			:disable-tooltip="true" />
		<NcAvatar v-else
			:url="item.account.avatar"
			:disable-tooltip="true" />
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { originOf } from '../utils/instanceIdentity.js'

export default {
	name: 'TimelineAvatar',
	components: {
		NcAvatar,
	},
	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */
		item: {
			type: Object,
			default: () => {},
		},
	},
	computed: {
		/**
		 * @return {string}
		 */
		userTest() {
			return this.item.account.display_name
		},
		/** @return {boolean} */
		isLocal() {
			return !this.item.account.acct.includes('@')
		},
		/** @return {{instance: string, colour: string, local: boolean}} where the author lives */
		origin() {
			return originOf(this.item.account.acct)
		},
	},
}
</script>

<style scoped lang='scss'>
.post-avatar {
	padding: 5px 10px 10px 5px;
	height: 52px;
	width: 52px;

	/* a ring in the colour of the server the author is on, so a timeline
	   visibly spans instances instead of hiding it after the @ */
	&--remote :deep(.avatardiv) {
		box-shadow: 0 0 0 2px var(--color-main-background), 0 0 0 4px var(--instance-colour);
	}
}
</style>
