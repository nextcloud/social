<template>
	<div class="timeline-entry">
		<div class="entry-content">
			<div v-if="item.actor_info" class="post-avatar">
				<avatar v-if="item.local" :size="32" :user="item.actor_info.preferredUsername"
					:display-name="item.actor_info.account" />
				<avatar v-else :size="32" :url="avatarUrl" />
			</div>
			<div class="post-content">
				{{ item.account_info }}

				<div class="post-author-wrapper">
					<router-link v-if="item.actor_info && item.local" :to="{ name: 'profile', params: { account: item.actor_info.preferredUsername }}">
						<span class="post-author">{{ item.actor_info.preferredUsername }}</span>
						<span class="post-author-id">{{ item.actor_info.account }}</span>
					</router-link>
					<a v-else-if="item.actor_info" :href="item.actor_info.url">
						<span class="post-author">{{ item.actor_info.preferredUsername }}</span>
						<span class="post-author-id">{{ item.actor_info.account }}</span>
					</a>
					<a v-else :href="item.attributedTo">
						<span class="post-author-id">{{ item.attributedTo }}</span>
					</a>
				</div>
				<div class="post-message" v-html="formatedMessage" />
			</div>
			<div :data-timestamp="timestamp" class="post-timestamp live-relative-timestamp">{{ relativeTimestamp }}</div>
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
	data() {
		return {

		}
	},
	computed: {
		relativeTimestamp() {
			return OC.Util.relativeModifiedDate(this.item.published)
		},
		timestamp() {
			return Date.parse(this.item.published)
		},
		formatedMessage() {
			let message = this.item.content
			message = message.replace(/(?:\r\n|\r|\n)/g, '<br />')
			message = message.linkify({
				formatHref: {
					email: function(href) {
						return OC.generateUrl('/apps/social/@' + (href.indexOf('mailto:') === 0 ? href.substring(7) : href))
					}
				}
			})
			message = this.$twemoji.parse(message)
			return message
		},
		avatarUrl() {
			return OC.generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.attributedTo)
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

	.post-timestamp {
		width: 120px;
		text-align: right;
		flex-shrink: 0;
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
