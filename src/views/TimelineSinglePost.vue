<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div ref="socialWrapper" class="social__wrapper">
		<!-- a post opened from a timeline is a place the reader went into, and
		     the way out of it was the browser's own button or the sidebar -->
		<NcButton
			class="thread__back"
			variant="tertiary"
			:aria-label="t('social', 'Back')"
			@click="goBack">
			<template #icon>
				<ArrowLeft :size="20" />
			</template>
			{{ t('social', 'Back') }}
		</NcButton>
		<Composer v-show="composerDisplayStatus" />
		<!-- the three lists are one conversation; the spine says so -->
		<div class="thread" :class="{ 'thread--connected': hasThread }">
			<TimelineList
				v-if="timeline"
				class="thread__ancestors"
				:showParents="true"
				:type="$route.params.type"
				:reverseOrder="true" />
			<TimelineEntry
				v-if="singlePost"
				ref="mainPost"
				class="main-post"
				:item="singlePost"
				type="single-post"
				element="div" />
			<!-- a deleted post is not an empty page: say so -->
			<NcEmptyContent
				v-else
				:name="t('social', 'This post is not available')"
				:description="t('social', 'It may have been deleted, or this server never received it.')">
				<template #icon>
					<CommentRemoveOutline :size="20" />
				</template>
			</NcEmptyContent>
			<TimelineList v-if="timeline" class="descendants thread__descendants" :type="$route.params.type" />
		</div>
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import { translate } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import CommentRemoveOutline from 'vue-material-design-icons/CommentRemoveOutline.vue'
import TimelineEntry from '../components/TimelineEntry.vue'
import TimelineList from '../components/TimelineList.vue'
import { loadState } from '@nextcloud/initial-state'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useTimelineStore } from '../store/timeline.js'
import { useServerData } from '../composables/useServerData.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

export default {
	name: 'TimelineSinglePost',
	components: {
		ArrowLeft,
		Composer,
		CommentRemoveOutline,
		NcButton,
		NcEmptyContent,
		TimelineEntry,
		TimelineList,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	computed: {
		...mapStores(useAccountStore, useTimelineStore),
		singlePost() {
			return this.timelineStore.getSinglePost
		},

		composerDisplayStatus() {
			return this.timelineStore.getComposerDisplayStatus
		},

		/**
		 * Whose post this is. The route says so; this used to be read off
		 * window.location by splitting the href and slicing a '@' off the
		 * second-to-last segment, which broke on any URL shape but one.
		 *
		 * @return {string}
		 */
		account() {
			return String(this.$route.params.account ?? '').replace(/^@/, '')
		},

		timeline() {
			return this.timelineStore.getTimeline
		},

		parentsTimeline() {
			return this.timelineStore.getParentsTimeline
		},

		/**
		 * Whether there is a conversation here, or only the one post.
		 *
		 * The spine is a line drawn behind the avatars to say that what it runs
		 * through belongs together. With nothing above the post and nothing
		 * below it there is nothing to say, and the line is a mark beside a
		 * single card with no other end.
		 *
		 * @return {boolean}
		 */
		hasThread() {
			return this.parentsTimeline.length > 0 || this.timeline.length > 0
		},
	},

	watch: {
		'$route.params.id': 'load',
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
		// Keep the handler so unmounted() removes only this one — a bare
		// eventBus.off('composer-reply') would also detach the Composer's.
		this.onComposerReply = (item) => {
			this.$nextTick(() => {
				this.$refs.socialWrapper?.querySelector(`[data-social-status="${item.id}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
			})
		}
		eventBus.on('composer-reply', this.onComposerReply)

		await this.load()
	},

	unmounted() {
		eventBus.off('composer-reply', this.onComposerReply)
	},

	methods: {
		t: translate,

		/**
		 * Back to wherever the reader came from, and to the home timeline when
		 * that is nowhere.
		 *
		 * `history.state.back` is what the router writes when it navigates
		 * inside the app, so it is also how to tell a post opened from a
		 * timeline from one opened from a link somebody sent: going back from
		 * the second would leave the app entirely, which is not what a button
		 * inside it should do.
		 */
		goBack() {
			if (window.history.state?.back) {
				this.$router.back()

				return
			}

			this.$router.push({ name: 'timeline', params: { type: 'home' } })
		},

		/**
		 * Opens the conversation the route names. Called again when the route
		 * changes to another post, because the router-view is no longer keyed
		 * on the full path and this component is reused.
		 */
		async load() {
			// read before the reset: changeTimelineType prunes the status index
			const singlePost = this.timelineStore.getPostFromTimeline(this.$route.params.id) ?? this.postFromInitialState()

			this.timelineStore.changeTimelineType({
				type: 'single-post',
				params: {
					account: this.account,
					id: this.$route.params.id,
					type: 'single-post',
					singlePost: this.$route.params.id || singlePost?.id,
				},
			})
			this.timelineStore.addToStatuses(singlePost)

			// the account is loaded for the post's author card; nothing here
			// reads the answer, which is why it is not kept
			const fetchMethod = this.serverData.public ? 'fetchPublicAccountInfo' : 'fetchAccountInfo'
			await this.accountStore[fetchMethod](this.account)
		},

		/**
		 * The post the server rendered into the page, for a permalink opened
		 * cold. `loadState` throws when the key is absent — which is what a
		 * deleted post looks like — and the throw used to happen inside
		 * beforeMount, so the view never rendered at all.
		 *
		 * @return {object|null}
		 */
		postFromInitialState() {
			try {
				return loadState('social', 'item')
			} catch (error) {
				logger.debug('No post in the initial state', { error })
				return null
			}
		},
	},
}
</script>

<style scoped lang="scss">
.social__wrapper {
	padding-bottom: 25%;
}

.thread__back {
	margin-bottom: 8px;
}

/*
 * The indent belongs to the thread, not to the list: `.social__timeline` is
 * another component's root, and a scoped rule here lands on it beside the
 * list's own layout with the same specificity, so which one wins depends on
 * the order the bundle happens to put them in.
 */
.thread .social__timeline {
	margin-inline-start: 16px;
}

/**
 * A reply chain used to read as three unrelated stacks of cards. The spine is
 * a single line behind the avatars: everything on it belongs to the same
 * conversation.
 *
 * Only when there is one. A post with no parent and no replies is a single
 * card, and the line beside it ran from nothing to nothing.
 */
.thread {
	position: relative;

	&--connected::before {
		content: '';
		position: absolute;
		top: 12px;
		bottom: 12px;
		inset-inline-start: 42px;
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

/* The post being read is the card, and this is spacing around it. It used to
   be a second card: white ground, 20px of padding, rounded corners, a shadow,
   and an accent border on top of all that — so a post on its own page was
   drawn inside two boxes, one nested in the other about ten pixels out. What
   marks the post the page is about is that it is the one at the top with the
   thread hanging off it, which needs no frame of its own. */
.main-post {
	position: relative;
	z-index: 1;
	margin: 16px 0;
	/* the lists above and below sit 16px out and pad 8 inside that, so this
	   takes the 24 that puts its avatar on the same line as theirs — which is
	   the line the spine runs down */
	margin-inline-start: 24px;
}

#app-content {
	position: relative;
}
</style>
