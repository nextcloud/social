<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcAvatar v-if="isLocal"
		:size="size"
		:user="actor.username"
		:display-name="actor.acct"
		:disable-tooltip="true"
		:show-user-status="false" />
	<NcAvatar v-else
		:size="size"
		:url="avatarUrl"
		:show-user-status="false"
		:disable-tooltip="true" />
</template>

<script>
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ActorAvatar',
	components: {
		NcAvatar,
	},
	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Account>} */
		actor: {
			type: Object,
			default: () => {},
		},
		size: {
			type: Number,
			default: 32,
		},
	},
	data() {
		return {
			followingText: t('social', 'Following'),
		}
	},
	computed: {
		/** @return {string} */
		avatarUrl() {
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.attributedTo)
		},
		/**
		 * @return {boolean}
		 */
		isLocal() {
			return !this.actor.acct.includes('@')
		},
	},
}
</script>
