<template>
	<div class="timeline-entry">
		<div class="entry-content">
			<div class="post-avatar">
				<avatar v-if="item.actor_info" :size="32" :user="item.actor_info.preferredUsername" />
				<avatar :size="32" user="?" />
			</div>
			<div class="post-content">
				<div class="post-author-wrapper">
					<router-link v-if="item.actor_info && item.actor_info.local" :to="{ name: 'profile', params: { account: item.actor_info.preferredUsername }}">
						<span class="post-author">{{ item.actor_info.preferredUsername }}</span>
						<span class="post-author-id">{{ item.actor_info.account }}</span>
					</router-link>
					<a v-else :href="item.actor_info.url">
						<span class="post-author">{{ item.actor_info.preferredUsername }}</span>
						<span class="post-author-id">{{ item.actor_info.account }}</span>
					</a>
				</div>
				<div class="post-message" v-html="formatedMessage" />
			</div>
			<div class="post-timestamp">{{ item.published }}</div>
		</div>
	</div>
</template>

<script>
import { Avatar } from 'nextcloud-vue'
import 'linkifyjs/string'

export default {
	name: 'TimelineEntry',
	components: {
		Avatar
	},
	props: {
		item: { type: Object, default: () => {} }
	},
	data: function() {
		return {

		}
	},
	computed: {
		formatedMessage: function() {
			let message = this.item.content
			message = message.replace(/(?:\r\n|\r|\n)/g, '<br />')
			message = message.linkify()
			message = this.$twemoji.parse(message)
			return message
		}
	}
}
</script>
<style scoped>
	.timeline-entry {
		padding: 10px;
		margin-bottom: 10px;
	}

	.social__welcome h3 {
		margin-top: 0;
	}

	.post-author-id {
		opacity: .7;
	}

	.post-avatar {
		margin: 5px;
		margin-right: 10px;
		border-radius: 50%;
		overflow: hidden;
		width: 32px;
		height: 32px;
		min-width: 32px;
		flex-shrink: 0;
	}

	.timestamp {
		float: right;
	}

	span {
		/* opacity: 0.5; */
	}
	.entry-content {
		display: flex;
	}

	.post-content {
		flex-grow: 1;
	}

	.post-timestamp {
		opacity: .7;
	}
</style>
<style>
	.post-message a {
		text-decoration: underline;
	}
</style>
