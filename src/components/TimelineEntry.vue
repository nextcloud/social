<template>
	<div class="timeline-entry">
		<div v-if="item.type === 'Announce'" class="boost">
			<span class="icon-container"><span class="icon-boost"></span></span>
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
		<timeline-content :item="entryContent" />
	</div>
</template>

<script>
import TimelineContent from './TimelineContent.vue'

export default {
	name: 'TimelineEntry',
	components: {
		TimelineContent
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
		boosted() {
			return t('social', 'boosted')
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

	.icon-boost {
		display: inline-block;
		width: 44px;
		height: 17px;
		opacity: .5;
	}

	.post-author {
		font-weight: bold;
	}

	.post-author-id {
		opacity: .7;
	}
</style>
