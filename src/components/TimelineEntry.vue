<template>
	<div class="timeline-entry">
		<div v-if="item.type === 'SocialAppNotification'">
			{{ actionSummary }}
		</div>
		<div v-if="item.type === 'Announce'" class="boost">
			<div class="container-icon-boost">
				<span class="icon-boost" />
			</div>
			<router-link v-if="item.actor_info" :to="{ name: 'profile', params: { account: item.local ? item.actor_info.preferredUsername : item.actor_info.account }}">
				<span v-tooltip.bottom="item.actor_info.account" class="post-author">
					{{ userDisplayName(item.actor_info) }}
				</span>
			</router-link>
			<a v-else :href="item.attributedTo">
				<span class="post-author-id">
					{{ item.attributedTo }}
				</span>
			</a>
			{{ boosted }}
		</div>
		<timeline-post v-if="(item.type === 'Note' || item.type === 'Announce')" :item="entryContent" :parent-announce="isBoost" />
		<user-entry v-if="item.type === 'SocialAppNotificationUser'" :key="user.id" :item="user" />
	</div>
</template>

<script>
import TimelinePost from './TimelinePost'
import UserEntry from './UserEntry'

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost,
		UserEntry
	},
	props: {
		item: { type: Object, default: () => {} }
	},
	data() {
		return {
		}
	},
	computed: {
		entryContent() {
			if (this.item.type === 'Announce') {
				return this.item.cache[this.item.object].object
			} else {
				return this.item
			}
		},
		isBoost() {
			if (this.item.type === 'Announce') {
				return this.item
			}
			return {}
		},
		boosted() {
			return t('social', 'boosted')
		},
		actionSummary() {
			let summary = this.item.summary
			for (var key in this.item.details) {
				let keyword = '{' + key + '}'
				summary = summary.replace(keyword, JSON.stringify(this.item.details[key]))
			}
			return summary
		}
	},
	methods: {
		userDisplayName(actorInfo) {
			return actorInfo.name !== '' ? actorInfo.name : actorInfo.preferredUsername
		}
	}
}
</script>
<style scoped lang="scss">
	.timeline-entry {
		padding: 10px;
		margin-bottom: 10px;
	}

	.container-icon-boost {
		display: inline-block;
		padding-right: 6px;
	}

	.icon-boost {
		display: inline-block;
		width: 38px;
		height: 17px;
		opacity: .5;
		background-position: right center;
		vertical-align: middle;
	}

	.boost {
		opacity: .5;
	}
</style>
