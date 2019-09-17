<template>
	<div v-if="item.actor_info" class="post-avatar">
		<avatar v-if="item.local" :size="32" :user="userTest"
			:display-name="item.actor_info.account" :disable-tooltip="true" />
		<avatar v-else :size="32" :url="avatarUrl"
			:disable-tooltip="true" />
	</div>
</template>

<script>
import Avatar from '@nextcloud/vue/dist/Components/Avatar'

export default {
	name: 'TimelineAvatar',
	components: {
		Avatar
	},
	props: {
		item: { type: Object, default: () => {} }
	},
	computed: {
		userTest() {
			return this.item.actor_info.preferredUsername
		},
		avatarUrl() {
			return OC.generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.attributedTo)
		}
	}
}
</script>

<style lang="scss" scoped>
.post-avatar {
    margin: 5px;
    margin-right: 10px;
    border-radius: 50%;
    overflow: hidden;
    width: 32px;
    height: 32px;
    min-width: 32px;
    flex-shrink: 0;
    grid-column: 1;
    grid-row: 2;
    align-self: start;
}

</style>
