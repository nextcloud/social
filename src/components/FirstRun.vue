<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="first-run social__wrapper" :aria-label="t('social', 'Getting started on the fediverse')">
		<header class="first-run__header">
			<span class="first-run__logo" aria-hidden="true" />
			<h1 class="first-run__brand">
				{{ t('social', 'Aloha Social') }}
			</h1>
			<NcButton
				variant="tertiary"
				class="first-run__skip"
				:aria-label="t('social', 'Skip the introduction')"
				@click="finish(false)">
				<template #icon>
					<IconClose :size="20" />
				</template>
			</NcButton>
		</header>

		<!-- where in the introduction this page is, and the way between
		     the steps: a row of tabs on a narrow screen, a sticky rail
		     beside the page on a wide one, the way the settings page does it -->
		<div class="first-run__body">
			<nav class="first-run__nav" :aria-label="t('social', 'Introduction progress')">
				<ol class="first-run__steps">
					<li
						v-for="item in introductionSteps"
						:key="item.n"
						class="first-run__step"
						:class="{ 'first-run__step--current': step === item.n - 1 }">
						<button
							type="button"
							class="first-run__step-button"
							:aria-current="step === item.n - 1 ? 'step' : undefined"
							:disabled="busy !== '' || moveBusy || followsBusy || bioSaving"
							@click="step = item.n - 1">
							<span class="first-run__step-icon">
								<component :is="item.icon" :size="20" />
							</span>
							<span class="first-run__step-text">
								<span class="first-run__step-title">{{ item.label }}</span>
								<span class="first-run__step-description">{{ item.description }}</span>
							</span>
							<span class="first-run__step-indicator" aria-hidden="true">
								<IconCheck v-if="item.n - 1 < step" :size="14" />
								<template v-else-if="item.n - 1 === step"><IconCheck :size="14" /></template>
								<template v-else>{{ item.n }}</template>
							</span>
						</button>
					</li>
				</ol>
			</nav>
			<div class="first-run__main">
				<div class="first-run__card">
					<Transition :name="reducedMotion ? '' : 'first-run-step'" mode="out-in">
						<!-- 1. how it all works -->
						<div v-if="step === 0" key="how" class="first-run__page">
							<h2 ref="heading" class="first-run__title" tabindex="-1">
								{{ t('social', 'How Aloha Social works') }}
							</h2>
							<p class="first-run__lead">
								{{ t('social', 'Your account is ready. Here is what you have, and what you can do with it:') }}
							</p>

							<div class="first-run__address">
								<code class="first-run__handle">{{ socialId }}</code>
								<NcButton variant="secondary" @click="copyHandle">
									<template #icon>
										<IconCheck v-if="copied" :size="20" />
										<IconContentCopy v-else :size="20" />
									</template>
									{{ copied ? t('social', 'Copied') : t('social', 'Copy') }}
								</NcButton>
							</div>
							<p class="first-run__hint">
								{{ t('social', 'Give it to people the way you would an email address. Anybody on Mastodon, Pixelfed, PeerTube or any other server that speaks ActivityPub can follow it and talk to you.') }}
							</p>

							<ul class="first-run__what">
								<li class="first-run__what-item">
									<IconPost class="first-run__what-icon" :size="20" />
									<div class="first-run__what-text">
										<h3 class="first-run__what-title">
											{{ t('social', 'Post what you like') }}
										</h3>
										<p>
											{{ t('social', 'Text, pictures and polls. Who sees each post is yours to choose: everybody, your followers, or only the people you mention.') }}
										</p>
									</div>
								</li>
								<li class="first-run__what-item">
									<IconAccountMultiplePlus class="first-run__what-icon" :size="20" />
									<div class="first-run__what-text">
										<h3 class="first-run__what-title">
											{{ t('social', 'Follow anybody') }}
										</h3>
										<p>
											{{ t('social', 'On any server that speaks ActivityPub — Mastodon, Pixelfed, PeerTube, this Nextcloud — and their posts arrive in your home feed.') }}
										</p>
									</div>
								</li>
								<li class="first-run__what-item">
									<IconAccountCircle class="first-run__what-icon" :size="20" />
									<div class="first-run__what-text">
										<h3 class="first-run__what-title">
											{{ t('social', 'Your profile is already set up') }}
										</h3>
										<p>
											{{ t('social', 'Name, avatar and bio come from your Nextcloud account; change them on your profile page and they change here too.') }}
										</p>
									</div>
								</li>
								<!-- what this instance offers, where the administrator has
							     switched it on: an account here is a Bluesky account too -->
								<li v-if="blueskyEnabled" class="first-run__what-item">
									<IconButterfly class="first-run__what-icon" :size="20" />
									<div class="first-run__what-text">
										<h3 class="first-run__what-title">
											{{ t('social', 'Also on Bluesky') }}
										</h3>
										<p>
											{{ t('social', 'This server speaks AT Protocol as well, so you are reachable from any Bluesky app at {handle}. Your posts and profile are shown there, and switching that off is in Settings.', { handle: blueskyHandle }) }}
										</p>
									</div>
								</li>
							</ul>

							<div class="first-run__islands">
								<AlohaScene scene="islands" class="first-run__islands-scene" />
								<p class="first-run__hint">
									{{ t('social', 'Every server in the fediverse is an island, and yours is {host}. Following somebody on another one opens a canoe route to theirs, and their posts travel to you along it.', { host: homeHost }) }}
								</p>
							</div>
						</div>

						<!-- 2. the account's face: a bio, next to the name the
					     Nextcloud account gives it -->
						<div v-else-if="step === 1" key="profile" class="first-run__page">
							<h2 ref="heading" class="first-run__title" tabindex="-1">
								{{ t('social', 'Set up your profile') }}
							</h2>
							<p class="first-run__lead">
								{{ t('social', 'Everything here can be changed later in Settings.') }}
							</p>

							<p v-if="!credentials" class="first-run__hint">
								{{ t('social', 'Loading your account …') }}
							</p>
							<template v-else>
								<p class="first-run__hint">
									{{ t('social', 'You post as {name}.', { name: displayName }) }}
									<a class="first-run__link" :href="personalSettings">
										{{ t('social', 'Change your name in your Nextcloud settings') }}
									</a>
								</p>
								<div class="first-run__bio">
									<label class="first-run__bio-label" for="social-first-run-bio">
										{{ t('social', 'Bio') }}
									</label>
									<textarea
										id="social-first-run-bio"
										v-model="bioDraft"
										class="first-run__bio-input"
										rows="4"
										aria-describedby="social-first-run-bio-count"
										:aria-invalid="bioTooLong ? 'true' : 'false'"
										:placeholder="t('social', 'A few words about you, shown on your profile and shared with other servers.')"
										:disabled="bioSaving"
										@input="touchBio" />
									<!-- no maxlength: the server truncates an over-long bio
								     instead of refusing it, and silently swallowing the
								     tail of a pasted bio is worse than saying that it
								     is too long -->
									<div class="first-run__bio-row">
										<span
											id="social-first-run-bio-count"
											class="first-run__bio-count"
											:class="{ 'first-run__bio-count--over': bioTooLong }"
											role="status">
											{{ bioCountLabel }}
										</span>
										<NcButton variant="secondary" :disabled="!bioChanged || bioTooLong || bioSaving" @click="saveBio">
											<template #icon>
												<NcLoadingIcon v-if="bioSaving" :size="20" />
											</template>
											{{ bioSaving ? t('social', 'Saving…') : t('social', 'Save') }}
										</NcButton>
									</div>
								</div>
							</template>
						</div>

						<!-- 3. who can reach it: the settings sections themselves, so
					     nothing here is a second implementation of anything -->
						<div v-else-if="step === 2" key="privacy" class="first-run__page">
							<h2 ref="heading" class="first-run__title" tabindex="-1">
								{{ t('social', 'Decide who can reach you') }}
							</h2>
							<p class="first-run__lead">
								{{ t('social', 'Everything here can be changed later in Settings.') }}
							</p>

							<h3 class="first-run__subtitle">
								{{ t('social', 'Who can find and follow you') }}
							</h3>
							<AccountSettings />
							<h3 class="first-run__subtitle">
								{{ t('social', 'Who may send you direct messages') }}
							</h3>
							<DirectMessagesSettings />
						</div>

						<!-- 4. people -->
						<div v-else-if="step === 3" key="people" class="first-run__page">
							<h2 ref="heading" class="first-run__title" tabindex="-1">
								{{ t('social', 'A feed is only as good as who is in it') }}
							</h2>
							<p class="first-run__lead">
								{{ t('social', 'Nothing shows up here until you follow somebody. Two places to start:') }}
							</p>

							<h3 class="first-run__subtitle">
								{{ t('social', 'People on your Nextcloud') }}
							</h3>
							<ul v-if="colleagues.length" class="first-run__people">
								<li v-for="account in colleagues" :key="account.acct" class="first-run__person">
									<ActorAvatar :actor="account" :size="36" :link="false" />
									<span class="first-run__person-names">
										<span class="first-run__person-name">{{ account.display_name || account.username }}</span>
										<span class="first-run__person-handle">@{{ account.acct }}</span>
									</span>
									<NcButton
										:variant="followed.includes(account.acct) ? 'success' : 'primary'"
										:disabled="followed.includes(account.acct) || busy === account.acct"
										@click="follow(account)">
										<template #icon>
											<NcLoadingIcon v-if="busy === account.acct" :size="20" />
											<IconCheck v-else-if="followed.includes(account.acct)" :size="20" />
											<IconAccountPlus v-else :size="20" />
										</template>
										{{ followed.includes(account.acct) ? t('social', 'Following') : t('social', 'Follow') }}
									</NcButton>
								</li>
							</ul>
							<p v-else-if="loading" class="first-run__hint">
								{{ t('social', 'Looking around …') }}
							</p>
							<p v-else class="first-run__hint">
								{{ t('social', 'Nobody on this Nextcloud has said where they are on the fediverse yet. You are the first — your handle is now on your Nextcloud profile, so the next person will see you here.') }}
							</p>
							<h3 class="first-run__subtitle">
								{{ t('social', 'Starter packs') }}
							</h3>
							<ul v-if="packs.length" class="first-run__packs">
								<li v-for="pack in packs" :key="pack.id" class="first-run__pack">
									<span class="first-run__pack-text">
										<span class="first-run__pack-name">{{ pack.name }}</span>
										<span v-if="pack.description" class="first-run__pack-description">{{ pack.description }}</span>
									</span>
									<NcButton
										:variant="followedPacks.includes(pack.id) ? 'success' : 'secondary'"
										:disabled="followedPacks.includes(pack.id) || busy === pack.id || !pack.size"
										@click="followPack(pack)">
										<template #icon>
											<NcLoadingIcon v-if="busy === pack.id" :size="20" />
											<IconCheck v-else-if="followedPacks.includes(pack.id)" :size="20" />
											<IconAccountMultiplePlus v-else :size="20" />
										</template>
										{{ followedPacks.includes(pack.id)
											? t('social', 'Following')
											: n('social', 'Follow %n account', 'Follow %n accounts', pack.size) }}
									</NcButton>
								</li>
							</ul>
							<p v-else-if="!loading" class="first-run__hint">
								{{ t('social', 'No starter packs on this server. Discover has suggestions and what is trending.') }}
							</p>
						</div>

						<!-- 5. what you had -->
						<div v-else-if="step === 4" key="follows" class="first-run__page">
							<h2 ref="heading" class="first-run__title" tabindex="-1">
								{{ t('social', 'Already somewhere else?') }}
							</h2>
							<p class="first-run__lead">
								{{ t('social', 'If you have an account on Mastodon or another server, type its handle. Everyone it follows is followed from here and its public posts are brought along, in the background. Moving your followers over as well is the last step, done on your old server; Settings → Migration tells you how.') }}
							</p>
							<div class="first-run__move">
								<NcTextField
									v-model="oldHandle"
									class="first-run__move-field"
									:label="t('social', 'Your old account')"
									placeholder="@you@mastodon.example"
									:disabled="moveBusy"
									@keydown.enter="moveIn" />
								<NcButton variant="primary" :disabled="moveBusy || oldHandle.trim() === ''" @click="moveIn">
									<template #icon>
										<NcLoadingIcon v-if="moveBusy" :size="20" />
										<IconAccountArrowRight v-else :size="20" />
									</template>
									{{ moveBusy ? t('social', 'Moving …') : t('social', 'Move here') }}
								</NcButton>
							</div>
							<p v-if="moveResult" class="first-run__result" role="status">
								{{ moveResult }}
							</p>
							<p class="first-run__hint">
								{{ t('social', 'Or upload the file your old server exports: Mastodon and servers like it write following_accounts.csv, Pixelfed pixelfed-following.json; each account in it is followed from here.') }}
							</p>
							<!-- opened by the button below, which is the control a keyboard
						     and a screen reader reach; the input stays out of both -->
							<input
								ref="follows"
								type="file"
								accept=".csv,text/csv,.json,application/json"
								class="hidden-visually"
								tabindex="-1"
								aria-hidden="true"
								@change="importFollows">
							<div class="first-run__import">
								<NcButton variant="primary" :disabled="followsBusy" @click="pickFollows">
									<template #icon>
										<NcLoadingIcon v-if="followsBusy" :size="20" />
										<IconUpload v-else :size="20" />
									</template>
									{{ followsBusy ? t('social', 'Following …') : t('social', 'Upload your follows') }}
								</NcButton>
								<p v-if="followsResult" class="first-run__result" role="status">
									{{ followsResult }}
								</p>
							</div>
							<p class="first-run__hint">
								{{ t('social', 'On Mastodon: Preferences → Import and export → Data export → Follows (CSV). Moving your followers over as well is in Settings, under Migration — that one is a one-way move, so it is not a button here.') }}
							</p>
						</div>

						<!-- 6. go -->
						<div v-else key="ready" class="first-run__page">
							<h2 ref="heading" class="first-run__title" tabindex="-1">
								{{ t('social', 'That is all there is to it') }}
							</h2>
							<p class="first-run__lead">
								{{ followedAnything
									? t('social', 'Your feed fills up as the people you follow post. Meanwhile, say hello — a first post is how the fediverse finds out you are here.')
									: t('social', 'You have not followed anyone yet, and that is fine: Discover is always a click away in the sidebar. Say hello in the meantime — a first post is how the fediverse finds out you are here.') }}
							</p>
						</div>
					</Transition>

					<footer class="first-run__footer">
						<NcButton v-if="step > 0" variant="tertiary" @click="step--">
							<template #icon>
								<IconArrowLeft :size="20" />
							</template>
							{{ t('social', 'Back') }}
						</NcButton>
						<span class="first-run__spacer" />
						<NcButton v-if="step < stepNames.length - 1" variant="primary" @click="step++">
							{{ t('social', 'Next') }}
							<template #icon>
								<IconArrowRight :size="20" />
							</template>
						</NcButton>
						<NcButton v-else variant="primary" @click="finish(true)">
							<template #icon>
								<IconPencil :size="20" />
							</template>
							{{ t('social', 'Write your first post') }}
						</NcButton>
					</footer>
				</div>
			</div>
		</div>
	</section>
</template>

<script>
/**
 * The walkthrough a new account goes through, in place of a paragraph about
 * beta.
 *
 * The account was made a moment ago — by the setup screen, or by registering
 * on the signup page — and the reader has three questions the old banner did
 * not answer: how does this thing work, who do I follow, and can I bring
 * what I had. Four pages, each skippable, turned with Next and Back (or the
 * arrow keys), and it is gone for good once closed.
 *
 * It is a page of its own rather than part of the app: `App.vue` renders it
 * instead* of the app while it is open, so the reader sees this and nothing
 * else — no sidebar, no feed behind it — and the app is there when they are
 * through. `?welcome=1`, which the setup screen and the first login after
 * registering carry, is what brings it up; `?replay=1`, which Settings links
 * to, brings it up again.
 *
 * Nothing here is a second implementation of anything: the people come from
 * the suggestions the Discover page reads (the colleagues among them, marked
 * `featured`), the packs from the same starter-pack routes, and the CSV goes
 * to the same import the Settings page posts to.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconAccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import IconAccountCircle from 'vue-material-design-icons/AccountCircle.vue'
import IconAccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import IconAccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import IconArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import IconArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import IconButterfly from 'vue-material-design-icons/Butterfly.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconPost from 'vue-material-design-icons/Post.vue'
import IconShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import ActorAvatar from './ActorAvatar.vue'
import AccountSettings from './AccountSettings.vue'
import AlohaScene from './illustrations/AlohaScene.vue'
import DirectMessagesSettings from './DirectMessagesSettings.vue'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { useAccountStore } from '../store/account.js'
import { useSettingsStore } from '../store/settings.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'

/** how many colleagues to show; Discover has the rest */
const MAX_COLLEAGUES = 6

/** what the server truncates a bio at, in characters */
const BIO_MAX_LENGTH = 500

/** how long a page takes to slide in, which is how long focus waits */
const PAGE_TRANSITION_MS = 220

export default {
	name: 'FirstRun',
	components: {
		AccountSettings,
		ActorAvatar,
		AlohaScene,
		DirectMessagesSettings,
		IconAccountArrowRight,
		IconAccountCircle,
		IconAccountMultiplePlus,
		IconAccountPlus,
		IconArrowLeft,
		IconArrowRight,
		IconButterfly,
		IconCheck,
		IconClose,
		IconContentCopy,
		IconPencil,
		IconPost,
		IconShieldCheck,
		IconUpload,
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},

	emits: ['done'],

	setup() {
		const { socialId } = useCurrentUser()

		return { socialId }
	},

	data() {
		return {
			step: 0,
			stepNames: [
				t('social', 'How it works'),
				t('social', 'Profile'),
				t('social', 'Privacy'),
				t('social', 'People to follow'),
				t('social', 'Bring what you had'),
				t('social', 'Ready'),
			],

			/** where the page heading is focused once it has finished arriving */
			focusTimer: null,
			loading: true,
			/** accounts on this Nextcloud that said where they are, as Discover marks them */
			colleagues: [],
			packs: [],
			/** the handles followed from here */
			followed: [],
			/** the pack ids followed from here */
			followedPacks: [],
			/** what is being followed right now: a handle or a pack id */
			busy: '',
			copied: false,
			copyTimer: null,
			/** the bio as it is being edited */
			bioDraft: '',
			/** whether the reader has touched the bio since it was read */
			bioTouched: false,
			/** whether the bio is being saved */
			bioSaving: false,
			followsBusy: false,
			followsResult: '',
			/** @type {string} the old account, as typed */
			oldHandle: '',
			moveBusy: false,
			moveResult: '',
			reducedMotion: window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false,
		}
	},

	computed: {
		...mapStores(useAccountStore, useSettingsStore),

		/**
		 * The reader's own server, as their address spells it.
		 *
		 * @return {string}
		 */
		homeHost() {
			const at = String(this.socialId ?? '').lastIndexOf('@')
			return at > 0 ? this.socialId.slice(at + 1) : window.location.host
		},

		/** @return {boolean} whether this instance also speaks AT Protocol */
		blueskyEnabled() {
			return this.settingsStore.getServerData.bluesky?.enabled === true
		},

		/**
		 * The address the reader is reachable at on Bluesky, where this
		 * instance has switched that on: `<name>.<host>`.
		 *
		 * @return {string}
		 */
		blueskyHandle() {
			const name = String(this.socialId ?? '').replace(/^@/, '').split('@')[0]
			const host = this.settingsStore.getServerData.bluesky?.host ?? this.homeHost

			return '@' + name + '.' + host
		},

		followedAnything() {
			return this.followed.length > 0 || this.followedPacks.length > 0 || this.followsResult !== '' || this.moveResult !== ''
		},

		/** @return {object|null} the CredentialAccount, once it has come */
		credentials() {
			return this.accountStore.credentials
		},

		/**
		 * @return {string} the name this account posts under, falling back to
		 *                  the handle where Nextcloud holds no display name
		 */
		displayName() {
			return this.credentials?.display_name || this.credentials?.username || ''
		},

		/** @return {string} where the name and the picture actually live */
		personalSettings() {
			return generateUrl('/settings/user')
		},

		/**
		 * The bio as the server holds it. The same normalization the
		 * profile editor uses: carriage returns go, the ends are trimmed.
		 *
		 * @return {string}
		 */
		bioStored() {
			return (this.credentials?.source?.note ?? '').replace(/\r\n|\r/g, '\n').trim()
		},

		/** @return {string} the bio as it would be stored */
		bioValue() {
			return (this.bioDraft ?? '').replace(/\r\n|\r/g, '\n').trim()
		},

		/** @return {number} how many characters the bio has left */
		bioCharsLeft() {
			// `mb_strlen()` on the server counts code points, and `.length`
			// counts UTF-16 units: an emoji is one character, not two
			return BIO_MAX_LENGTH - [...this.bioValue].length
		},

		/** @return {boolean} */
		bioTooLong() {
			return this.bioCharsLeft < 0
		},

		/** @return {boolean} whether the bio is worth saving */
		bioChanged() {
			return this.bioValue !== this.bioStored
		},

		/** @return {string} */
		bioCountLabel() {
			return this.bioTooLong
				? this.n('social', '%n character too many', '%n characters too many', -this.bioCharsLeft)
				: this.n('social', '%n character left', '%n characters left', this.bioCharsLeft)
		},

		/**
		 * The way between the steps, in the sidebar: how it works, profile,
		 * privacy, people, bring what you had, ready. The labels are the
		 * introduction's own words.
		 *
		 * @return {{n: number, label: string, description: string, icon: string}[]}
		 */
		introductionSteps() {
			return [
				{ n: 1, label: t('social', 'How it works'), description: t('social', 'Your address and what you can do'), icon: 'IconPost' },
				{ n: 2, label: t('social', 'Profile'), description: t('social', 'Your bio and display name'), icon: 'IconAccountCircle' },
				{ n: 3, label: t('social', 'Privacy'), description: t('social', 'Who can follow and message you'), icon: 'IconShieldCheck' },
				{ n: 4, label: t('social', 'People'), description: t('social', 'Find people to follow'), icon: 'IconAccountMultiplePlus' },
				{ n: 5, label: t('social', 'Move'), description: t('social', 'Bring your follows from elsewhere'), icon: 'IconAccountArrowRight' },
				{ n: 6, label: t('social', 'Ready'), description: t('social', 'Start posting and exploring'), icon: 'IconPencil' },
			]
		},
	},

	watch: {
		/**
		 * A page turned is a page arrived at: put the reader on its title,
		 * so a keyboard and a screen reader are on the new page with them.
		 * The wait is the page taking its turn to slide in.
		 */
		step() {
			window.clearTimeout(this.focusTimer)
			this.focusTimer = window.setTimeout(() => {
				/** @type {HTMLElement|undefined} */ (this.$refs.heading)?.focus()
			}, this.reducedMotion ? 0 : PAGE_TRANSITION_MS)
		},

		// the bio starts where the server holds it. Not while the reader
		// is editing: taking the server's copy then would throw their
		// words away.
		credentials: {
			immediate: true,
			handler(credentials) {
				if (credentials && !this.bioTouched) {
					this.bioDraft = this.bioStored
				}
			},
		},
	},

	mounted() {
		this.load()
		window.addEventListener('keydown', this.onKeyDown)
	},

	beforeUnmount() {
		window.clearTimeout(this.copyTimer)
		window.clearTimeout(this.focusTimer)
		window.removeEventListener('keydown', this.onKeyDown)
	},

	methods: {
		/**
		 * The arrow keys turn the page, the way a wizard does; typing in one
		 * of the fields on these pages is not navigation.
		 *
		 * @param {KeyboardEvent} event the key that was pressed
		 */
		onKeyDown(event) {
			if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
				return
			}
			const target = event.target
			if (target instanceof HTMLElement
				&& (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) {
				return
			}
			if (event.key === 'ArrowLeft' && this.step > 0) {
				this.step--
			} else if (event.key === 'ArrowRight' && this.step < this.stepNames.length - 1) {
				this.step++
			}
		},

		/** Opens the file chooser for the follows export. */
		pickFollows() {
			/** @type {HTMLInputElement} */ (this.$refs.follows).click()
		},

		/** Who there is to follow, both kinds at once; either failing leaves the other. */
		async load() {
			this.loading = true
			const [suggestions, packs] = await Promise.allSettled([
				axios.get(generateUrl('apps/social/api/v2/suggestions')),
				axios.get(generateUrl('apps/social/api/v1/starter_packs')),
			])
			if (suggestions.status === 'fulfilled' && Array.isArray(suggestions.value.data)) {
				// the colleagues among the suggestions: `featured` is what
				// Suggestion::SOURCE_COLLEAGUES is called on the wire
				this.colleagues = suggestions.value.data
					.filter((suggestion) => Array.isArray(suggestion.sources) && suggestion.sources.includes('featured'))
					.map((suggestion) => suggestion.account)
					.filter((account) => account && account.acct)
					.slice(0, MAX_COLLEAGUES)
			} else if (suggestions.status === 'rejected') {
				logger.debug('No suggestions for the first run', { error: suggestions.reason })
			}
			if (packs.status === 'fulfilled' && Array.isArray(packs.value.data)) {
				this.packs = packs.value.data
			} else if (packs.status === 'rejected') {
				logger.debug('No starter packs for the first run', { error: packs.reason })
			}
			this.loading = false
		},

		async copyHandle() {
			try {
				await navigator.clipboard.writeText(this.socialId)
				this.copied = true
				window.clearTimeout(this.copyTimer)
				this.copyTimer = window.setTimeout(() => {
					this.copied = false
				}, 2000)
			} catch (error) {
				logger.debug('Could not copy the handle', { error })
				showError(t('social', 'Could not copy — select the address and copy it yourself'))
			}
		},

		/** @param {object} account a colleague, as an Account entity */
		async follow(account) {
			this.busy = account.acct
			try {
				await this.accountStore.followAccount({ accountToFollow: account.acct })
				this.followed.push(account.acct)
			} catch (error) {
				logger.error('Could not follow from the first run', { error })
				showError(t('social', 'Could not follow {account}', { account: account.acct }))
			} finally {
				this.busy = ''
			}
		},

		/** @param {object} pack a starter pack, as the server lists them */
		async followPack(pack) {
			this.busy = pack.id
			try {
				const { data } = await axios.post(generateUrl(`apps/social/api/v1/starter_packs/${pack.id}/follow`))
				const count = Array.isArray(data?.followed) ? data.followed.length : 0

				// nobody was followed: every handle in the pack is on a server
				// this one could not reach. Marking it "Following" and saying
				// "Followed 0 accounts" was the app reporting success for
				// something that did not happen
				if (count === 0) {
					showError(t('social', 'None of these accounts could be reached. Their servers may be busy — Discover has the pack, and says which ones.'))

					return
				}

				this.followedPacks.push(pack.id)
				showSuccess(this.n('social', 'Followed %n account', 'Followed %n accounts', count))
			} catch (error) {
				logger.error('Could not follow the starter pack', { error, pack: pack.id })
				showError(t('social', 'Could not follow these accounts'))
			} finally {
				this.busy = ''
			}
		},

		/**
		 * The same upload the Settings page makes, to the same route.
		 *
		 * @param {Event} event the file input's change
		 */
		/**
		 * Queues the follows file, then watches the import until it is done:
		 * the server keeps the file and follows each account in the background,
		 * where a long list outlives no request.
		 *
		 * @param {Event} event the file input's change
		 * @return {Promise<void>}
		 */
		async importFollows(event) {
			const input = /** @type {HTMLInputElement|null} */ (event?.target ?? null)
			const file = input?.files?.[0]
			if (!file) {
				return
			}

			this.followsBusy = true
			this.followsResult = ''
			try {
				const form = new FormData()
				form.append('file', file)
				const { data } = await axios.post(generateUrl('apps/social/api/v1/migration/follows'), form)
				const job = await this.awaitImport(data?.import?.id)
				if (job === null) {
					this.followsResult = t('social', 'Your follows are being imported in the background; Settings → Migration shows where it gets to.')
				} else if (job.status === 'failed') {
					showError(job.report?.error || t('social', 'Could not import those follows'))
				} else {
					this.followsResult = t(
						'social',
						'{followed} followed, {skipped} skipped, {failed} could not be reached',
						{ followed: job.done ?? 0, skipped: job.skipped ?? 0, failed: job.failed ?? 0 },
					)
				}
			} catch (error) {
				logger.error('Importing follows failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not import those follows'))
			} finally {
				this.followsBusy = false
				if (input) {
					input.value = ''
				}
			}
		},

		/**
		 * Moves here from the old handle: the alias is set, and following who
		 * it follows and bringing its posts over run in the background.
		 *
		 * @return {Promise<void>}
		 */
		async moveIn() {
			const handle = this.oldHandle.trim()
			if (handle === '' || this.moveBusy) {
				return
			}
			this.moveBusy = true
			this.moveResult = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/migration/move-in'), {
					handle,
					follows: '1',
					posts: '1',
					fetch_media: '1',
				})
				const job = await this.awaitImport(data?.import?.id)
				if (job === null) {
					this.moveResult = t('social', 'Your move is running in the background; Settings → Migration shows where it gets to, and names the last step on your old server.')
				} else if (job.status === 'failed') {
					showError(job.report?.error || t('social', 'Could not move that account here'))
				} else {
					this.moveResult = t(
						'social',
						'{followed} accounts followed and {imported} posts brought over. The last step is on your old server; Settings → Migration names it.',
						{ followed: job.report?.followed ?? 0, imported: job.report?.imported ?? 0 },
					)
				}
			} catch (error) {
				logger.error('Moving in failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not move that account here'))
			} finally {
				this.moveBusy = false
			}
		},

		/**
		 * Polls the imports until the one named is finished, for as long as a
		 * person will wait on an introduction screen.
		 *
		 * @param {number|undefined} id the queued import
		 * @return {Promise<object|null>} the finished import, or null when it is still running
		 */
		async awaitImport(id) {
			if (!id) {
				return null
			}
			for (let attempt = 0; attempt < 20; attempt++) {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/migration/imports'))
				const job = (Array.isArray(data?.imports) ? data.imports : []).find((candidate) => candidate.id === id)
				if (job && (job.status === 'done' || job.status === 'failed')) {
					return job
				}
				await new Promise((resolve) => window.setTimeout(resolve, 3000))
			}
			return null
		},

		/** The bio was touched; the server's copy is no longer its start. */
		touchBio() {
			this.bioTouched = true
		},

		/**
		 * Saves the bio the way the profile editor does: only the bio goes,
		 * so nothing else on the account is touched.
		 *
		 * @return {Promise<void>}
		 */
		async saveBio() {
			if (!this.bioChanged || this.bioTooLong || this.bioSaving) {
				return
			}
			this.bioSaving = true
			try {
				const saved = await this.accountStore.updateCredentials({ note: this.bioValue })
				if (saved) {
					showSuccess(t('social', 'Profile saved'))
				}
			} finally {
				this.bioSaving = false
			}
		},

		/**
		 * Closes the introduction for good.
		 *
		 * @param {boolean} compose whether to put the caret in the composer:
		 *   what the last step's button promises, and not what Skip does
		 */
		finish(compose) {
			this.$emit('done')
			if (compose) {
				// the shortcuts help offers "n" for this; the composer answers it
				eventBus.emit('shortcut:compose')
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

.first-run__move {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.first-run__move-field {
	flex: 1;
	min-width: 200px;
}

/*
 * A page of its own, not part of the app: `App.vue` renders it instead of the
 * app, so it is a whole page — the brand at the top, the walkthrough in a
 * card below it — the same quiet surface the setup screen gives its question.
 */
.first-run {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 1040px;
	margin: 0 auto;

	// the walkthrough fills the page, not the reading column: the app
	// centers its pages at 800px, and two columns do not fit in it
	#app-content-vue &.social__wrapper {
		max-width: 1080px;
	}

	/* where in the introduction this page is, and the way between
	   the steps: a row of tabs on a narrow screen, a sticky rail
	   beside the page on a wide one, the way the setup screen does it */
	&__body {
		display: grid;
		grid-template-columns: minmax(0, 1fr);
		width: 100%;
	}

	&__nav {
		min-width: 0;
	}

	&__steps {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
		margin: 0 0 calc(var(--default-grid-baseline) * 5);
		padding: 0;
		list-style: none;
	}

	&__step-button {
		display: inline-flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) * 1) calc(var(--default-grid-baseline) * 3);
		border: 1px solid transparent;
		border-radius: 999px;
		background: transparent;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		font-weight: 600;
		cursor: pointer;
		text-decoration: none;

		&:hover:not(:disabled) {
			background: transparent;
			color: var(--color-main-text);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		&:disabled {
			cursor: default;
			opacity: 0.6;
		}

		.first-run__step--current & {
			background: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}
	}

	&__step-dot {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 22px;
		height: 22px;
		border-radius: 50%;
		background: var(--color-background-darker);
		font-size: 12px;
		font-weight: 700;

		.first-run__step--current & {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
		}
	}

	&__step-icon {
		display: flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;
		inline-size: 28px;
		block-size: 28px;
		border-radius: 50%;
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
	}

	.first-run__step--current &__step-icon {
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
	}

	&__step-text {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__step-title {
		font-weight: bold;
	}

	&__step-description {
		display: none;
		font-size: 13px;
		line-height: 1.35;
		color: var(--color-text-maxcontrast);
	}

	@include layout.from(layout.$wide) {
		&__step-description {
			display: block;
		}
	}

	&__step-indicator {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;
		width: 22px;
		height: 22px;
		border-radius: 50%;
		background: transparent;
		font-size: 12px;
		font-weight: 700;
		color: var(--color-text-maxcontrast);
		margin-inline-start: auto;
		opacity: 0.6;

		.first-run__step--current & {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
			opacity: 1;
		}
	}

	&__main {
		display: flex;
		flex-direction: column;
		align-items: center;
		min-width: 0;
	}

	@include layout.from(layout.$wide) {
		&__body {
			grid-template-columns: 260px minmax(0, 1fr);
			gap: calc(var(--default-grid-baseline) * 8);
			align-items: start;
		}

		&__nav {
			position: sticky;
			inset-block-start: calc(var(--default-grid-baseline) * 12);
			align-self: start;
			max-height: calc(100vh - 120px);
			overflow-y: auto;
			overflow-x: hidden;
		}

		&__steps {
			flex-direction: column;
			align-items: stretch;
			margin: 0;
		}

		&__step-button {
			width: 100%;
			border-radius: var(--border-radius-large);
			text-align: start;
			padding: 8px 10px;
			background: transparent;

			.first-run__step--current & {
				background: var(--color-primary-element-light);
				color: var(--color-primary-element-light-text);
			}

			&:hover:not(:disabled) {
				background: transparent;
				color: var(--color-main-text);
			}
		}
	}

	&__header {
		display: flex;
		align-items: center;
		gap: 12px;
	}

	/* the app's own mark, in the current colour, the way the sidebar's is */
	&__logo {
		display: block;
		flex: 0 0 auto;
		width: 32px;
		height: 32px;
		background-image: url('../../img/social-dark.svg');
		background-repeat: no-repeat;
		background-position: center;
		background-size: contain;
		filter: var(--background-invert-if-dark);
	}

	&__brand {
		flex: 1;
		margin: 0;
		font-size: 17px;
		font-weight: 700;
	}

	&__card {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 32px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 16px);
		width: 100%;
		max-width: 880px;
	}

	@media (max-width: 767px) {
		gap: 12px;

		&__card {
			padding: 20px;
		}
	}

	/* which page of how many, in words beside the dots */
	&__step-name {
		margin: 0;
		font-size: 12px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	/* the dot that is current stretches into a short bar, which is what
	   says "you are here" without a number */
	&__dot {
		width: 8px;
		height: 8px;
		border-radius: 999px;
		background: var(--color-border-dark);
		transition: width .3s cubic-bezier(.22, 1.2, .48, 1), background-color .2s ease;

		&--done {
			background: var(--color-primary-element-light-hover);
		}

		&--current {
			width: 28px;
			background: var(--color-primary-element);
		}
	}

	/* one page of the walkthrough: focused, and scrolls if a page is taller
	   than the window rather than pushing the footer away */
	&__page {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	/* what you can do, as a quiet list: icon, what it is, one line on it.
	   Two columns while there is room for them */
	&__what {
		display: grid;
		grid-template-columns: minmax(0, 1fr);
		gap: 10px;
		margin: 8px 0 0;
		padding: 0;
		list-style: none;

		@media (min-width: 720px) {
			grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
			gap: 12px 24px;
		}
	}

	&__what-item {
		display: flex;
		gap: 12px;
		align-items: flex-start;
	}

	&__what-icon {
		flex: 0 0 auto;
		margin-top: 2px;
		color: var(--color-primary-element);
	}

	&__what-title {
		margin: 0 0 2px;
		font-size: 14px;
		font-weight: 600;
	}

	&__what-text p {
		margin: 0;
		font-size: 13px;
		line-height: 1.45;
		color: var(--color-text-maxcontrast);
		text-wrap: pretty;
	}

	&__title {
		margin: 0;
		font-size: 22px;
		font-weight: 700;
		line-height: 1.25;
		text-wrap: balance;
	}

	&__subtitle {
		margin: 12px 0 0;
		font-size: 14px;
		font-weight: 600;
		letter-spacing: .02em;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
	}

	&__lead {
		margin: 0;
		line-height: 1.6;
	}

	&__hint,
	&__result {
		margin: 0;
		color: var(--color-text-maxcontrast);
		line-height: 1.6;
	}

	/* the picture beside its sentence, and above it on a phone */
	&__islands {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px 16px;
		margin-top: 8px;

		p {
			flex: 1 1 240px;
		}
	}

	&__islands-scene {
		flex: 0 0 auto;
		width: 132px;
	}

	&__address {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
		margin: 8px 0;
	}

	&__handle {
		flex: 1 1 auto;
		min-width: 0;
		padding: 10px 14px;
		border-radius: var(--border-radius-element, 8px);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-size: 16px;
		font-weight: 700;
		overflow-wrap: anywhere;
		user-select: all;
	}

	/* the bio, full width: a few words do not need two columns */
	&__bio {
		display: flex;
		flex-direction: column;
		gap: 8px;
		min-width: 0;
	}

	&__bio-label {
		font-size: 13px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__bio-input {
		width: 100%;
		padding: 8px 10px;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		font-size: 14px;
		line-height: 1.5;
		resize: vertical;
		background: var(--color-main-background);
		color: var(--color-main-text);
	}

	&__bio-row {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px 12px;
	}

	&__bio-count {
		flex: 1 1 auto;
		font-size: 12px;
		color: var(--color-text-maxcontrast);

		&--over {
			color: var(--color-error-text);
			font-weight: 600;
		}
	}

	/* the name lives on the Nextcloud account, next to the picture */
	&__link {
		text-decoration: underline;
	}

	&__people,
	&__packs {
		margin: 0;
		padding: 0;
		list-style: none;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__person,
	&__pack {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: 44px;
	}

	&__person-names,
	&__pack-text {
		flex: 1;
		min-width: 0;
		display: flex;
		flex-direction: column;
		line-height: 1.3;
	}

	&__person-name,
	&__pack-name {
		font-weight: 600;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__person-handle,
	&__pack-description {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__import {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
		margin: 4px 0;
	}

	&__footer {
		display: flex;
		align-items: center;
		gap: 8px;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
	}

	&__spacer {
		flex: 1;
	}
}

/* one step slides out to the left as the next comes in from the right */
.first-run-step-enter-active,
.first-run-step-leave-active {
	transition: opacity .18s ease, transform .22s cubic-bezier(.22, 1.2, .48, 1);
}

.first-run-step-enter-from {
	opacity: 0;
	transform: translateX(16px);
}

.first-run-step-leave-to {
	opacity: 0;
	transform: translateX(-16px);
}

@media (prefers-reduced-motion: reduce) {
	.first-run__dot,
	.first-run-step-enter-active,
	.first-run-step-leave-active {
		transition: none;
	}
}
</style>
