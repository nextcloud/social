<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="account-setup social__wrapper">
		<header class="account-setup__header">
			<span class="account-setup__logo" aria-hidden="true" />
			<div class="account-setup__header-text">
				<h1 class="account-setup__title">
					{{ t('social', 'Welcome to Aloha Social') }}
				</h1>
				<p class="account-setup__lead">
					{{ t('social', 'You do not have an account here yet. Here is what this is:') }}
				</p>
			</div>
		</header>

		<!-- the explainer reads, the answers sit: two columns while there
		     is room, one below it -->
		<div class="account-setup__body">
			<div class="account-setup__about">
				<h2 class="account-setup__about-title">
					{{ t('social', 'What Aloha Social gives you') }}
				</h2>
				<ul class="account-setup__points">
					<li class="account-setup__point">
						<IconAt class="account-setup__point-icon" :size="20" />
						<div class="account-setup__point-text">
							<h3 class="account-setup__point-title">
								{{ t('social', 'An address of your own') }}
							</h3>
							<p>
								{{ t('social', 'Like an email address: @you@host. People on Mastodon, Pixelfed, PeerTube and other servers can follow it and reply.') }}
							</p>
						</div>
					</li>
					<li class="account-setup__point">
						<IconWeb class="account-setup__point-icon" :size="20" />
						<div class="account-setup__point-text">
							<h3 class="account-setup__point-title">
								{{ t('social', 'One fediverse, many servers') }}
							</h3>
							<p>
								{{ t('social', 'Thousands of social networks speak the same language, so an account here reaches an account anywhere. This Nextcloud runs one of those servers.') }}
							</p>
						</div>
					</li>
					<li class="account-setup__point">
						<IconSwapHorizontal class="account-setup__point-icon" :size="20" />
						<div class="account-setup__point-text">
							<h3 class="account-setup__point-title">
								{{ t('social', 'Yours to keep') }}
							</h3>
							<p>
								{{ t('social', 'Your profile, avatar and name come from your Nextcloud account — and you can move the whole thing to another server whenever you like.') }}
							</p>
						</div>
					</li>
					<!-- what the administrator has switched on, not what every
					     Aloha Social does: an instance that also speaks AT Protocol
					     says so here, and one that does not says nothing -->
					<li v-if="blueskyEnabled" class="account-setup__point">
						<IconAt class="account-setup__point-icon" :size="20" />
						<div class="account-setup__point-text">
							<h3 class="account-setup__point-title">
								{{ t('social', 'Also on Bluesky') }}
							</h3>
							<p>
								{{ t('social', 'This server speaks AT Protocol as well, so your account gets a Bluesky identity too and your posts are shown there. Switching that off is in Settings.') }}
							</p>
						</div>
					</li>
				</ul>
				<details class="account-setup__more">
					<summary>{{ t('social', 'What is the fediverse?') }}</summary>
					<p>
						{{ t('social', 'The fediverse is not one website but thousands of them, run by different people and communities, that all speak the same language (ActivityPub). An account on any of them can follow, like and reply to an account on any other, the way email works across providers. Nobody owns it and nobody can switch it off.') }}
					</p>
				</details>
			</div>

			<div class="account-setup__answers">
				<!-- the two answers, one at a time: the question is which of them,
					     not two forms to read past each other -->
				<div class="account-setup__modes" role="group" :aria-label="t('social', 'How do you want to continue?')">
					<button
						type="button"
						class="account-setup__mode"
						:class="{ 'account-setup__mode--active': mode === 'create' }"
						:aria-pressed="mode === 'create'"
						:disabled="busy !== ''"
						@click="choose('create')">
						<IconAccountPlus class="account-setup__mode-icon" :size="20" />
						<span class="account-setup__mode-text">
							<span class="account-setup__mode-title">
								{{ t('social', 'Create a new account') }}
							</span>
							<span class="account-setup__mode-desc">
								{{ t('social', 'Your own address, on this server.') }}
							</span>
						</span>
					</button>
					<button
						type="button"
						class="account-setup__mode"
						:class="{ 'account-setup__mode--active': mode === 'link' }"
						:aria-pressed="mode === 'link'"
						:disabled="busy !== ''"
						@click="choose('link')">
						<IconLinkVariant class="account-setup__mode-icon" :size="20" />
						<span class="account-setup__mode-text">
							<span class="account-setup__mode-title">
								{{ t('social', 'Link an existing account') }}
							</span>
							<span class="account-setup__mode-desc">
								{{ t('social', 'An account on Mastodon or another server.') }}
							</span>
						</span>
					</button>
				</div>

				<form v-if="mode === 'create'" class="account-setup__form" @submit.prevent="create">
					<h2 class="account-setup__heading">
						{{ t('social', 'Choose your username') }}
					</h2>
					<label class="account-setup__label" for="account-setup-handle">
						{{ t('social', 'Username') }}
					</label>
					<input
						id="account-setup-handle"
						v-model.trim="handle"
						type="text"
						class="account-setup__input"
						autocomplete="off"
						spellcheck="false"
						maxlength="64"
						pattern="[A-Za-z0-9_]+([A-Za-z0-9_.\-]*[A-Za-z0-9_])?"
						aria-describedby="account-setup-address"
						:aria-invalid="createError !== ''"
						:disabled="busy !== ''"
						required>
					<!-- the address as it is typed, not on the way out: the choice
						     is permanent, so it is worth seeing before it is made -->
					<p id="account-setup-address" class="account-setup__address">
						@{{ handle || '…' }}@{{ hostname }}
					</p>
					<!-- the second address the account gets, where the administrator
						     has switched that on -->
					<p v-if="blueskyEnabled && handle !== ''" class="account-setup__address account-setup__address--bluesky">
						<span class="account-setup__bluesky-label">{{ t('social', 'On Bluesky too:') }}</span>
						@{{ handle }}.{{ blueskyHost }}
					</p>
					<p class="account-setup__hint">
						{{ t('social', 'This is your address on the fediverse — letters, numbers, underscores, dots and dashes. It cannot be changed later.') }}
					</p>
					<p v-if="createError" class="account-setup__error" role="alert">
						{{ createError }}
					</p>
					<NcButton type="submit" variant="primary" :disabled="busy !== '' || handle === ''">
						<template #icon>
							<NcLoadingIcon v-if="busy === 'create'" :size="20" />
							<IconAccountPlus v-else :size="20" />
						</template>
						{{ t('social', 'Create account') }}
					</NcButton>
				</form>

				<form v-else class="account-setup__form" @submit.prevent="link">
					<h2 class="account-setup__heading">
						{{ t('social', 'Your existing account') }}
					</h2>
					<p class="account-setup__hint">
						{{ t('social', 'This only shows your existing account on your Nextcloud profile so others can find it. You will not post from that external account here.') }}
					</p>
					<label class="account-setup__label" for="account-setup-linked">
						{{ t('social', 'Fediverse handle') }}
					</label>
					<input
						id="account-setup-linked"
						v-model.trim="linked"
						type="text"
						class="account-setup__input"
						autocomplete="off"
						spellcheck="false"
						placeholder="you@mastodon.social"
						:aria-invalid="linkError !== ''"
						:disabled="busy !== ''">
					<p v-if="linkError" class="account-setup__error" role="alert">
						{{ linkError }}
					</p>
					<p v-if="linkedNow" class="account-setup__done" role="status">
						{{ t('social', 'Your profile now says you are @{handle}. The people on this Nextcloud will see it on Discover.', { handle: linkedNow }) }}
					</p>
					<NcButton type="submit" variant="secondary" :disabled="busy !== '' || linked === ''">
						<template #icon>
							<NcLoadingIcon v-if="busy === 'link'" :size="20" />
							<IconLinkVariant v-else :size="20" />
						</template>
						{{ t('social', 'Link account') }}
					</NcButton>
				</form>
			</div>
		</div>
	</div>
</template>

<script>
/**
 * Introduces the app before asking whether to create a local identity or
 * publish an existing external handle on the Nextcloud profile.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import IconAt from 'vue-material-design-icons/At.vue'
import IconLinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import IconSwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import IconWeb from 'vue-material-design-icons/Web.vue'
import logger from '../services/logger.js'
import { useServerData } from '../composables/useServerData.js'

export default {
	name: 'AccountSetup',
	components: {
		IconAccountPlus,
		IconAt,
		IconLinkVariant,
		IconSwapHorizontal,
		IconWeb,
		NcButton,
		NcLoadingIcon,
	},

	emits: ['created', 'linked'],

	/**
	 * Exposes instance configuration and the hostname used in address previews.
	 *
	 * @return {object} reactive server data and hostname
	 */
	setup() {
		const { serverData, hostname } = useServerData()

		return { serverData, hostname }
	},

	/**
	 * Seeds the forms from the suggested username and published external handle.
	 *
	 * @return {object} form values, selected path and request state
	 */
	data() {
		const serverData = useServerData().serverData.value

		return {
			handle: serverData.suggestedHandle ?? '',
			linked: serverData.linkedHandle ?? '',
			linkedNow: '',
			/** which of the two answers is on screen */
			mode: 'create',
			/** which of the two forms is talking to the server: 'create', 'link' or '' */
			busy: '',
			createError: '',
			linkError: '',
		}
	},

	computed: {
		/**
		 * Controls whether the setup explains and previews the Bluesky identity.
		 *
		 * @return {boolean} whether this instance supports AT Protocol
		 */
		blueskyEnabled() {
			return this.serverData.bluesky?.enabled === true
		},

		/**
		 * Resolves the Bluesky handle suffix, falling back to the instance hostname.
		 *
		 * @return {string} the host used by Bluesky handles
		 */
		blueskyHost() {
			return this.serverData.bluesky?.host ?? this.hostname
		},
	},

	methods: {
		/**
		 * Selects a form and clears errors from the previous choice.
		 *
		 * @param {'create'|'link'} mode which form to show
		 * @return {void}
		 */
		choose(mode) {
			this.mode = mode
			this.createError = ''
			this.linkError = ''
		},

		/**
		 * Creates the chosen local identity and emits it, or displays the refusal.
		 *
		 * @return {Promise<void>}
		 */
		async create() {
			this.busy = 'create'
			this.createError = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/account/create'), { username: this.handle })
				this.$emit('created', data?.result?.account ?? null)
			} catch (error) {
				logger.warn('Could not create the account', { error })
				this.createError = error?.response?.data?.error || t('social', 'Could not create the account')
			} finally {
				this.busy = ''
			}
		},

		/**
		 * Publishes the external handle on the profile without creating an account.
		 *
		 * @return {Promise<void>}
		 */
		async link() {
			this.busy = 'link'
			this.linkError = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/account/link'), { handle: this.linked })
				this.linkedNow = data?.result?.handle ?? this.linked
				this.$emit('linked', this.linkedNow)
			} catch (error) {
				logger.warn('Could not put the account on the profile', { error })
				this.linkError = error?.response?.data?.error || t('social', 'Could not save that')
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
.account-setup {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 80px 16px 24px;

	// the setup question fills the page, not the reading column: the app
	// centers its pages at 800px, and an explainer beside two answers
	// does not fit in it
	#app-content-vue &.social__wrapper {
		max-width: 1080px;
	}

	&__header {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 16px;
		width: 100%;
		max-width: 1024px;
		margin-bottom: 40px;
		text-align: center;
	}

	/* the app's own mark, drawn on the current colour like the sidebar's */
	&__logo {
		display: block;
		width: 48px;
		height: 48px;
		background-image: url('../../img/social-dark.svg');
		background-repeat: no-repeat;
		background-position: center;
		background-size: contain;
		filter: var(--background-invert-if-dark);
	}

	&__header-text {
		min-width: 0;
	}

	&__title {
		margin: 0 0 8px;
		font-size: 28px;
		font-weight: 700;
		line-height: 1.25;
		text-wrap: balance;
	}

	&__lead {
		margin: 0;
		line-height: 1.6;
		text-wrap: pretty;
	}

	// the explainer reads, the answers sit: two columns while there is
	// room, one below it
	&__body {
		display: grid;
		grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
		gap: 12px 32px;
		align-items: start;
		width: 100%;
		max-width: 1024px;

		@media (max-width: 760px) {
			grid-template-columns: minmax(0, 1fr);
		}
	}

	/* the explainer is quiet: one column of rows with an accent icon, no
	   card of its own, so the two answers stay the things that look
	   choosable */
	&__about {
		margin: 0;
		min-width: 0;
		text-align: center;
	}

	&__about-title {
		margin: 0 0 12px;
		font-size: 13px;
		font-weight: 600;
		letter-spacing: 0.02em;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
	}

	&__points {
		display: flex;
		flex-direction: column;
		gap: 12px;
		margin: 0 0 12px;
		padding: 0;
		list-style: none;
	}

	&__point {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8px;
		text-align: center;
	}

	&__point-icon {
		flex: 0 0 auto;
		color: var(--color-primary-element);
	}

	&__point-text {
		min-width: 0;
	}

	&__point-title {
		margin: 0 0 2px;
		font-size: 14px;
		font-weight: 600;
	}

	&__point-text p {
		margin: 0;
		font-size: 13px;
		line-height: 1.45;
		color: var(--color-text-maxcontrast);
		text-wrap: pretty;
	}

	&__more {
		summary {
			cursor: pointer;
			font-size: 13px;
			font-weight: 600;
			color: var(--color-primary-element);
		}

		p {
			margin: 8px 0 0;
			font-size: 13px;
			line-height: 1.5;
			color: var(--color-text-maxcontrast);
			text-wrap: pretty;
		}
	}

	&__modes {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
		gap: 12px;
		max-width: 600px;
		margin: 0 auto;
	}

	&__mode {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8px;
		padding: 16px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		text-align: center;
		color: var(--color-main-text);
		transition: border-color .15s ease, background-color .15s ease;

		&:hover {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		&--active {
			border-color: var(--color-primary-element);
			background: var(--color-background-hover);

			.account-setup__mode-icon {
				color: var(--color-primary-element);
			}
		}
	}

	&__mode-icon {
		flex: 0 0 auto;
		color: var(--color-text-maxcontrast);
	}

	&__mode-text {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}

	&__mode-title {
		font-weight: 600;
	}

	&__mode-desc {
		font-size: 13px;
		font-weight: 400;
		line-height: 1.4;
		color: var(--color-text-maxcontrast);
		text-wrap: pretty;
	}

	&__form {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
		margin-top: 20px;
		padding: 20px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		max-width: 500px;
		width: 100%;
		text-align: center;
	}

	&__heading {
		margin: 0;
		font-size: 17px;
		font-weight: 600;
	}

	&__label {
		font-size: 13px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__input {
		width: 100%;
		min-width: 0;
		margin: 0;
		text-align: center;
	}

	/* the address the username would make, in the shape FirstRun shows the
	   one an account has */
	&__address {
		margin: 0 auto;
		padding: 10px 14px;
		border-radius: var(--border-radius-element, 8px);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-size: 16px;
		font-weight: 700;
		overflow-wrap: anywhere;
		text-align: center;
		max-width: 100%;
	}

	/* the second address is the quieter one: the fediverse address is the
	   one the username is for */
	&__address--bluesky {
		background: none;
		border: 1px solid var(--color-border);
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		font-weight: 600;
		text-align: center;
	}

	&__bluesky-label {
		font-weight: 400;
	}

	&__hint {
		margin: 0;
		font-size: 13px;
		line-height: 1.5;
		color: var(--color-text-maxcontrast);
		text-align: center;
	}

	&__error {
		margin: 0;
		font-size: 13px;
		color: var(--color-error);
		text-align: center;
	}

	&__done {
		margin: 0;
		font-size: 13px;
		color: var(--color-success);
		text-align: center;
	}

	&__error {
		margin: 0;
		color: var(--color-error-text);
	}

	&__done {
		margin: 0;
		color: var(--color-success-text);
	}

	/* the chosen answer changing tint is the only movement on this page, and
	   it is one a reader who asked their system for less does without */
	@media (prefers-reduced-motion: reduce) {
		&__mode {
			transition: none;
		}
	}

	@media (max-width: 767px) {
		padding: 16px 8px;

		&__card {
			padding: 20px;
		}

		&__title {
			font-size: 24px;
		}
	}
}
</style>
