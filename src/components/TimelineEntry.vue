<template>
	<div class="timeline-entry" @click="getSinglePostTimeline">
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
		<timeline-post
			v-if="item.type === 'SocialAppNotification' && item.details.post"
			:item="item.details.post" />
		<timeline-post
			v-else
			:item="entryContent"
			:parent-announce="isBoost" />
	</div>
</template>

<script>
import Logger from '../logger'
import TimelinePost from './TimelinePost.vue'

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost
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
				if (typeof this.item.details[key] !== 'string' && this.item.details[key].length > 1) {

					let concatination = ''
					for (var stringKey in this.item.details[key]) {

						if (this.item.details[key].length > 3 && stringKey === '3') {
							// ellipses the actors' list to 3 actors when it's big
							concatination = concatination.substring(0, concatination.length - 2)
							concatination += ' and ' + (this.item.details[key].length - 3).toString() + ' other(s), '
							break
						} else {
							concatination += this.item.details[key][stringKey] + ', '
						}
					}

					concatination = concatination.substring(0, concatination.length - 2)
					summary = summary.replace(keyword, concatination)

				} else {
					summary = summary.replace(keyword, this.item.details[key])
				}
			}

			return summary
		}
	},
	methods: {
		getSinglePostTimeline(e) {

			// Do not call the single-post view when clicking on a link, a post attachment miniature or the post's author
			if (e.target.tagName === 'A' || e.target.tagName === 'IMG' || e.target.className.startsWith('post-author')) {
				Logger.debug('will not call single-post', { event: e })
				return
			}

			// Display internal or external post
			if (!this.item.local) {
				if (this.item.type === 'Note') {
					window.open(this.item.id)
				} else if (this.item.type === 'Announce') {
					window.open(this.item.object)
				} else {
					Logger.warn("Don't know what to do with posts of type " + this.item.type, { post: this.item })
				}
			} else {
				this.$router.push({ name: 'single-post',
					params: {
						account: this.item.actor_info.preferredUsername,
						id: this.item.id,
						localId: this.item.id.split('/')[this.item.id.split('/').length - 1],
						type: 'single-post'
					}
				})
			}

		},
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
		&:hover {
			background-color: var(--color-background-hover);
		}
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
