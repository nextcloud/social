<template>
	<div v-if="item.account" class="post-avatar">
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
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'

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
	},
}
</script>

<style scoped>
.post-avatar {
	position: relative;
	padding: 5px 10px 10px 5px;
	height: 52px;
	width: 52px;
}
</style>
