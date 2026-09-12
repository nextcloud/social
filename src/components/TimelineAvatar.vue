<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		v-if="item.account"
		class="post-avatar"
		:class="{ 'post-avatar--remote': !origin.local }"
		:style="{ '--instance-colour': origin.colour }"
		:title="origin.local ? undefined : origin.instance">
		<!-- the ring says which instance in colour, which is nothing at all to
		     a reader who cannot see it or cannot tell two hues apart. The post
		     header shows the instance in words too, but not every avatar sits
		     next to one -->
		<span v-if="!origin.local" class="hidden-visually">
			{{ t('social', 'Account on {instance}', { instance: origin.instance }) }}
		</span>
		<AccountHoverCard
			:handle="item.account.acct"
			:fallback="item.account"
			variant="block"
			placement="bottom-start">
			<NcAvatar
				v-if="isLocal"
				class="messages__avatar__icon"
				:hideStatus="true"
				:user="item.account.username"
				:displayName="item.account.display_name"
				:disableTooltip="true" />
			<NcAvatar
				v-else
				:url="item.account.avatar"
				:disableTooltip="true" />
		</AccountHoverCard>
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { translate } from '@nextcloud/l10n'
import AccountHoverCard from './AccountHoverCard.vue'
import { originOf } from '../utils/instanceIdentity.js'

export default {
	name: 'TimelineAvatar',
	components: {
		AccountHoverCard,
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

	methods: {
		t: translate,
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
