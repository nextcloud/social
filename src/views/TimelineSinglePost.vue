<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="socialWrapper" class="social__wrapper">
		<Composer v-show="composerDisplayStatus" />
		<!-- the three lists are one conversation; the spine says so -->
		<div class="thread">
			<TimelineList v-if="timeline"
				class="thread__ancestors"
				:show-parents="true"
				:type="$route.params.type"
				:reverse-order="true" />
			<TimelineEntry ref="mainPost"
				class="main-post"
				:item="singlePost"
				type="single-post"
				element="div" />
			<TimelineList v-if="timeline" class="descendants thread__descendants" :type="$route.params.type" />
		</div>
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import TimelineEntry from '../components/TimelineEntry.vue'
import TimelineList from '../components/TimelineList.vue'
import currentUserMixin from '../mixins/currentUserMixin.js'
import accountMixins from '../mixins/accountMixins.js'
import serverData from '../mixins/serverData.js'
import { loadState } from '@nextcloud/initial-state'
import eventBus from '../services/eventBus.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

export default {
	name: 'TimelineSinglePost',
	components: {
		Composer,
		TimelineEntry,
		TimelineList,
	},
	mixins: [
		accountMixins,
		currentUserMixin,
		serverData,
	],
	data() {
		return {
			uid: this.$route.params.account,
		}
	},
	computed: {
		singlePost() {
			return this.$store.getters.getSinglePost
		},
		composerDisplayStatus() {
			return this.$store.getters.getComposerDisplayStatus
		},
		account() {
			return window.location.href.split('/')[window.location.href.split('/').length - 2].slice(1)
		},
		timeline() {
			return this.$store.getters.getTimeline
		},
		parentsTimeline() {
			return this.$store.getters.getParentsTimeline
		},
	},
	watch: {
		parentsTimeline(_, previousValue) {
			// beforeMount() resets the timeline, so this fires during the first render's
			// pre-flush, before the template refs exist.
			if (previousValue.length !== 0 || !this.$refs.socialWrapper) {
				return
			}

			if (this.$refs.socialWrapper.parentElement?.scrollTop !== 0) {
				return
			}

			this.$nextTick(() => this.$refs.mainPost?.$el?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
		},
	},
	async beforeMount() {
		const singlePost = this.$store.getters.getPostFromTimeline(this.$route.params.id) || loadState('social', 'item')

		this.$store.commit('addToStatuses', singlePost)
		this.$store.dispatch('changeTimelineType', {
			type: 'single-post',
			params: {
				account: this.account,
				id: this.$route.params.id,
				type: 'single-post',
				singlePost: this.$route.params.id || loadState('social', 'item').id,
			},
		})

		// Keep the handler so unmounted() removes only this one — a bare
		// eventBus.off('composer-reply') would also detach the Composer's.
		this.onComposerReply = (item) => {
			this.$nextTick(() => {
				this.$refs.socialWrapper.querySelector(`[data-social-status="${item.id}"]`).scrollIntoView({ behavior: 'smooth', block: 'center' })
			})
		}
		eventBus.on('composer-reply', this.onComposerReply)

		const response = await this.$store.dispatch(this.serverData.public ? 'fetchPublicAccountInfo' : 'fetchAccountInfo', this.account)
		this.uid = response.username
	},
	unmounted() {
		eventBus.off('composer-reply', this.onComposerReply)
	},
}
</script>

<style scoped lang="scss">
.social__wrapper {
	padding-bottom: 25%;
}

.social__timeline {
	margin-left: 16px;
}

/**
 * A reply chain used to read as three unrelated stacks of cards. The spine is
 * a single line behind the avatars: everything on it belongs to the same
 * conversation, and the post being read sits raised off it.
 */
.thread {
	position: relative;

	&::before {
		content: '';
		position: absolute;
		top: 12px;
		bottom: 12px;
		left: 42px;
		width: 2px;
		border-radius: 1px;
		background: var(--color-border);
		z-index: 0;
	}

	&__ancestors,
	&__descendants {
		position: relative;
		z-index: 1;
	}
}

.main-post {
	position: relative;
	z-index: 1;
	background: var(--color-main-background);
	border: 1px solid var(--color-primary-element);
	border-radius: 8px;
	padding: 20px;
	box-sizing: content-box;
	margin: 16px 0;
	box-shadow: 0 2px 12px rgb(0 0 0 / 8%);
}

#app-content {
	position: relative;
}
</style>
