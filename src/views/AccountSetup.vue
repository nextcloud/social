<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="account-setup social__wrapper">
		<section class="account-setup__card" :aria-label="t('social', 'Your account on the fediverse')">
			<p class="account-setup__eyebrow">
				{{ t('social', 'Welcome to Aloha Social') }}
			</p>
			<h1 class="account-setup__title">
				{{ t('social', 'Create your federated profile') }}
			</h1>
			<p class="account-setup__lead">
				{{ t('social', 'Start with a new account here, or add your existing Fediverse handle to your Nextcloud profile.') }}
			</p>

			<div class="account-setup__mode-picker" role="group" :aria-label="t('social', 'How do you want to continue?')">
				<button
					type="button"
					class="account-setup__mode"
					:class="{ 'account-setup__mode--active': mode === 'create' }"
					:aria-pressed="mode === 'create'"
					@click="mode = 'create'">
					<IconAccountPlus :size="20" />
					<span>
						{{ t('social', 'Create a new account') }}
					</span>
				</button>
				<button
					type="button"
					class="account-setup__mode"
					:class="{ 'account-setup__mode--active': mode === 'link' }"
					:aria-pressed="mode === 'link'"
					@click="mode = 'link'">
					<IconLinkVariant :size="20" />
					<span>
						{{ t('social', 'Link an existing account') }}
					</span>
				</button>
			</div>

			<form v-if="mode === 'create'" class="account-setup__choice" @submit.prevent="create">
				<h2 class="account-setup__heading">
					{{ t('social', 'Create your account here') }}
				</h2>
				<p class="account-setup__hint">
					{{ t('social', 'This creates a new account for posting from this Nextcloud.') }}
				</p>
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
					:disabled="busy !== ''"
					required>
				<p class="account-setup__address" role="status">
					{{ t('social', 'Your address will be @{handle}@{host}', { handle: handle || '…', host: hostname }) }}
				</p>
				<p class="account-setup__hint">
					{{ t('social', 'Use letters, numbers, underscores, dots, and dashes. Your username cannot be changed later.') }}
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

			<form v-else class="account-setup__choice account-setup__choice--secondary" @submit.prevent="link">
				<h2 class="account-setup__heading">
					{{ t('social', 'Link your existing Fediverse account') }}
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
					class="account-setup__input account-setup__input--wide"
					autocomplete="off"
					spellcheck="false"
					placeholder="you@mastodon.social"
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
		</section>
	</div>
</template>

<script>
/**
 * What a person sees before they have an account: the question.
 *
 * The actor used to be created on the first click of the app icon, with a
 * handle derived from the user id and no way to say "I already have one on
 * mastodon.social". This asks. Two answers: an account here, with the handle
 * they choose (the address cannot be changed afterwards, so it is chosen
 * first), or the account they already have, named on their Nextcloud profile
 * so their colleagues find it -- and nothing created here.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import IconLinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import logger from '../services/logger.js'
import { useServerData } from '../composables/useServerData.js'

export default {
	name: 'AccountSetup',
	components: {
		IconAccountPlus,
		IconLinkVariant,
		NcButton,
		NcLoadingIcon,
	},

	emits: ['created', 'linked'],

	setup() {
		const { serverData, hostname } = useServerData()

		return { serverData, hostname }
	},

	data() {
		const serverData = useServerData().serverData.value

		return {
			handle: serverData.suggestedHandle ?? '',
			linked: serverData.linkedHandle ?? '',
			linkedNow: '',
			mode: 'create',
			/** which of the two forms is talking to the server: 'create', 'link' or '' */
			busy: '',
			createError: '',
			linkError: '',
		}
	},

	methods: {
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
	justify-content: center;
	padding: 24px 16px;

	&__card {
		width: 100%;
		max-width: 720px;
		padding: 32px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 16px);
	}

	&__eyebrow {
		margin: 0 0 8px;
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.04em;
		text-transform: uppercase;
		color: var(--color-primary-element);
	}

	&__title {
		margin: 0 0 8px;
		font-size: 30px;
		font-weight: 700;
		line-height: 1.25;
		text-wrap: balance;
	}

	&__lead {
		margin: 0 0 24px;
		line-height: 1.6;
	}

	&__mode-picker {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
		gap: 16px;
		margin-bottom: 16px;
	}

	&__mode {
		display: flex;
		gap: 8px;
		align-items: center;
		padding: 14px 16px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		font-weight: 600;
		text-align: left;
		color: var(--color-main-text);

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
		}
	}

	&__choice {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 20px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);

		&--secondary {
			background: var(--color-background-hover);
		}
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

	&__handle {
		display: flex;
		align-items: center;
		gap: 4px;
		font-variant-numeric: tabular-nums;
	}

	&__at,
	&__host {
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__input {
		width: 100%;
		min-width: 0;
		margin: 0;

		&--wide {
			width: 100%;
		}
	}

	&__address {
		margin: 0;
		font-size: 14px;
		font-weight: 600;
		color: var(--color-main-text);
		word-break: break-word;
	}

	&__hint {
		margin: 0;
		font-size: 13px;
		line-height: 1.5;
		color: var(--color-text-maxcontrast);
	}

	&__error {
		margin: 0;
		color: var(--color-error-text);
	}

	&__done {
		margin: 0;
		color: var(--color-success-text);
	}

	@media (max-width: 767px) {
		padding: 16px 8px;

		&__card {
			padding: 20px;
		}

		&__title {
			font-size: 26px;
		}
	}
}
</style>
