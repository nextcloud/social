<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="social-profile">
		<h2 class="social-profile__title">
			{{ t('social', 'Social') }}
		</h2>
		<transition-group
			v-if="!isOwnProfile"
			name="list"
			tag="ul"
			class="social-profile__timeline">
			<ProfileStatusCard
				v-for="entry in timeline"
				:key="entry.id"
				:status="entry" />
		</transition-group>
		<section v-if="isOwnProfile" class="social-profile__home" aria-labelledby="social-profile-home-heading">
			<header class="social-profile__home-heading">
				<div>
					<h2 id="social-profile-home-heading">
						{{ t('social', 'My Feed') }}
					</h2>
					<p>{{ t('social', 'Posts from accounts you follow, including private posts you are allowed to see.') }}</p>
				</div>
			</header>
			<Composer class="social-profile__home-composer" @posted="onHomePost" />
			<p v-if="homeLoading && profileTimeline.length === 0" role="status" class="social-profile__home-state">
				{{ t('social', 'Loading your home feed…') }}
			</p>
			<div v-else-if="homeError && profileTimeline.length === 0" class="social-profile__home-state" role="alert">
				<p>{{ t('social', 'Could not load your home feed') }}</p>
				<NcButton variant="secondary" @click="loadHomeFeed()">
					{{ t('social', 'Try again') }}
				</NcButton>
			</div>
			<p v-else-if="profileTimeline.length === 0" class="social-profile__home-state">
				{{ t('social', 'No posts in your feed yet') }}
			</p>
			<transition-group
				v-else
				name="list"
				tag="ul"
				class="social-profile__timeline social-profile__home-timeline">
				<ProfileStatusCard v-for="entry in profileTimeline" :key="`home-${entry.id}`" :status="entry" />
			</transition-group>
			<p v-if="homeError && profileTimeline.length" class="social-profile__home-state" role="alert">
				{{ t('social', 'Could not load your home feed') }}
			</p>
			<NcButton
				v-if="profileTimeline.length && homeHasMore"
				variant="secondary"
				class="social-profile__load-more"
				:disabled="homeLoading"
				@click="loadHomeFeed(homeTimeline[homeTimeline.length - 1]?.id)">
				{{ homeLoading ? t('social', 'Loading…') : t('social', 'Load more') }}
			</NcButton>
		</section>
	</section>
</template>

<script>
import ProfileStatusCard from './../components/ProfileStatusCard.vue'
import { defineAsyncComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import logger from './../services/logger.js'

const Composer = defineAsyncComponent(() => import(/* webpackChunkName: "composer" */'../components/Composer/Composer.vue'))

export default {
	name: 'ProfilePageIntegration',
	components: {
		NcButton,
		Composer,
		ProfileStatusCard,
	},

	props: {
		userId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			timeline: [],
			homeTimeline: [],
			homeLoading: false,
			homeError: false,
			homeHasMore: false,
		}
	},

	computed: {
		isOwnProfile() {
			return Boolean(window.OC?.getCurrentUser?.()?.uid)
				&& window.OC.getCurrentUser().uid === this.userId
		},

		// The public profile and home endpoints overlap for posts by this account.
		// Show one combined timeline so own posts never appear twice around the composer.
		profileTimeline() {
			if (!this.isOwnProfile) {
				return this.timeline
			}

			const posts = new Map()
			for (const entry of this.timeline) {
				posts.set(String(entry.id), entry)
			}
			for (const entry of this.homeTimeline) {
				posts.set(String(entry.id), entry)
			}

			return [...posts.values()].sort((left, right) => {
				const leftDate = Date.parse(left.created_at || '')
				const rightDate = Date.parse(right.created_at || '')
				return Number.isFinite(leftDate) && Number.isFinite(rightDate) ? rightDate - leftDate : 0
			})
		},
	},

	// Start fetching account information before mounting the component
	beforeMount() {
		const uid = this.userId

		if (!uid) {
			return
		}

		axios.get(generateUrl(`apps/social/api/v1/accounts/${encodeURIComponent(uid)}/statuses`)).then(({ data }) => {
			this.timeline = data
			logger.debug('Loaded profile timeline', { timeline: this.timeline })
		}).catch((error) => {
			logger.error('Failed to load profile timeline', { error, uid })
		})

		if (this.isOwnProfile) {
			this.loadHomeFeed()
		}
	},

	methods: {
		async onHomePost() {
			this.homeTimeline = []
			this.homeHasMore = false
			await this.loadHomeFeed()
		},

		async loadHomeFeed(maxId = '') {
			if (!this.isOwnProfile || this.homeLoading) {
				return
			}
			this.homeLoading = true
			this.homeError = false
			try {
				const params = { limit: 20 }
				if (maxId) {
					params.max_id = maxId
				}
				const { data } = await axios.get(generateUrl('apps/social/api/v1/timelines/home'), { params })
				const page = Array.isArray(data) ? data : []
				const existing = new Set(this.homeTimeline.map((entry) => String(entry.id)))
				this.homeTimeline = maxId
					? [...this.homeTimeline, ...page.filter((entry) => !existing.has(String(entry.id)))]
					: page
				this.homeHasMore = page.length === 20
			} catch (error) {
				this.homeError = true
				logger.error('Failed to load the profile home feed', { error, uid: this.userId })
			} finally {
				this.homeLoading = false
			}
		},
	},
}
</script>

<style scoped>
.social-profile {
	min-width: 0;
}

.social-profile__title {
	margin-block: 0 0.75rem;
}

.social-profile__timeline {
	list-style: none;
	margin: 0;
	padding: 0;
}

.social-profile__home {
	margin-block-start: 2rem;
	padding-block-start: 1.5rem;
	border-block-start: 1px solid var(--color-border);
}

.social-profile__home-heading {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
	margin-block-end: 1rem;
}

.social-profile__home-heading h2 {
	margin: 0;
}

.social-profile__home-heading p {
	margin: 0.25rem 0 0;
	color: var(--color-text-maxcontrast);
}

.social-profile__home-composer {
	margin-block-end: 1rem;
}

.social-profile__home-state {
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
}

.social-profile__load-more {
	margin-block: 1rem 2rem;
}
</style>
