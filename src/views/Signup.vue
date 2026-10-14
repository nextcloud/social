<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="guest-box wide signup">
		<header class="signup__brand">
			<span class="signup__logo" aria-hidden="true" />
			<div class="signup__brand-text">
				<p class="signup__eyebrow">
					{{ t('social', 'Aloha Social') }}
				</p>
				<h2 class="signup__heading">
					{{ heading }}
				</h2>
			</div>
		</header>

		<!-- where in the registration this page is: the overview, the form,
		     or what became of it. Only while registration is open; a closed
		     page is a dead end, not a step -->
		<ol v-if="state.open" class="signup__steps" :aria-label="t('social', 'Registration progress')">
			<li class="signup__step" :class="{ 'signup__step--current': step === 1 }" :aria-current="step === 1 ? 'step' : undefined">
				<span class="signup__step-dot" aria-hidden="true">1</span>
				{{ t('social', 'About') }}
			</li>
			<li class="signup__step" :class="{ 'signup__step--current': step === 2 }" :aria-current="step === 2 ? 'step' : undefined">
				<span class="signup__step-dot" aria-hidden="true">2</span>
				{{ t('social', 'Your details') }}
			</li>
			<li class="signup__step" :class="{ 'signup__step--current': step === 3 }" :aria-current="step === 3 ? 'step' : undefined">
				<span class="signup__step-dot" aria-hidden="true">3</span>
				{{ t('social', 'Result') }}
			</li>
		</ol>

		<!-- what the confirmation link or the form came to -->
		<template v-if="outcome">
			<div class="signup__narrow">
				<NcNoteCard :type="outcome.state === 'error' ? 'error' : 'success'">
					{{ outcomeText }}
				</NcNoteCard>
				<div class="signup__actions">
					<NcButton
						v-if="outcome.state === 'created'"
						variant="primary"
						:href="firstRunLoginUrl"
						wide>
						{{ t('social', 'Log in') }}
					</NcButton>
					<NcButton v-else-if="outcome.state === 'error'" :href="signupPage" wide>
						{{ t('social', 'Register again') }}
					</NcButton>
				</div>
			</div>
		</template>

		<template v-else-if="!state.open">
			<div class="signup__narrow">
				<NcNoteCard type="info">
					{{ state.reason }}
				</NcNoteCard>
				<div class="signup__actions">
					<NcButton :href="state.loginUrl" wide>
						{{ t('social', 'Back to the login page') }}
					</NcButton>
				</div>
			</div>
		</template>

		<section v-else-if="!showForm" class="signup__landing" aria-labelledby="signup-introduction-title">
			<div class="signup__columns">
				<div class="signup__intro">
					<h3 id="signup-introduction-title">
						{{ t('social', 'An Aloha Social account, connected to the fediverse') }}
					</h3>
					<p>{{ t('social', 'Your address will look like @{username}@{domain}. You can follow and talk with people on Mastodon, Pixelfed, PeerTube and other services that use ActivityPub.', { username: 'your name', domain: state.domain }) }}</p>
					<p>{{ t('social', 'This is an Aloha Social-only account. It does not give you access to Files, Talk, WebDAV or other Nextcloud apps on this server.') }}</p>
					<p v-if="state.twoFactorRequired">
						{{ t('social', 'This server requires two-factor authentication. You will need to set it up when you first sign in.') }}
					</p>
					<p>{{ t('social', 'After joining, you can finish the introduction, find people and any starter packs this server offers, write posts, and manage your profile and account settings. If you have an export from a compatible service, you can import your follow list.') }}</p>
					<p>{{ t('social', 'Public posts may be delivered to other servers. Other servers may keep copies, so deleting a post here cannot guarantee that every copy elsewhere is removed.') }}</p>
				</div>
				<aside class="signup__side">
					<h3 class="signup__side-heading">
						{{ t('social', 'What happens next') }}
					</h3>
					<p>{{ registrationPath }}</p>
					<p v-if="state.signupNotice" class="signup__instance-notice">
						{{ state.signupNotice }}
					</p>
					<p v-if="state.privacyUrl || state.legalUrl" class="signup__legal">
						<a
							v-if="state.privacyUrl"
							:href="state.privacyUrl"
							target="_blank"
							rel="noopener noreferrer">{{ t('social', 'Privacy policy') }}</a>
						<a
							v-if="state.legalUrl"
							:href="state.legalUrl"
							target="_blank"
							rel="noopener noreferrer">{{ t('social', 'Legal notice') }}</a>
					</p>
				</aside>
			</div>
			<div class="signup__actions signup__actions--split">
				<NcButton variant="primary" @click="showForm = true">
					{{ t('social', 'Continue to registration') }}
				</NcButton>
				<NcButton :href="state.loginUrl">
					{{ t('social', 'I already have an account') }}
				</NcButton>
			</div>
		</section>

		<form
			v-else
			class="signup__form"
			novalidate
			@submit.prevent="submit">
			<p class="signup__lede">
				{{ lede }}
			</p>

			<div class="signup__fields">
				<NcTextField
					v-model="handle"
					:label="t('social', 'Username')"
					:error="field === 'handle'"
					:helperText="field === 'handle' ? message : address"
					autocomplete="username"
					autocapitalize="none"
					spellcheck="false"
					:maxlength="64"
					required />

				<NcTextField
					v-model="email"
					type="email"
					:label="t('social', 'Email address')"
					:error="field === 'email'"
					:helperText="field === 'email' ? message : (state.verifyEmail ? t('social', 'We send a link to this address to confirm it.') : '')"
					autocomplete="email"
					required />

				<NcPasswordField
					v-model="password"
					class="signup__field--full"
					:label="t('social', 'Password')"
					:error="field === 'password'"
					:helperText="field === 'password' ? message : t('social', 'At least 8 characters.')"
					autocomplete="new-password"
					required />
			</div>

			<!-- nobody sees this field; a form-filling bot fills it in -->
			<div class="signup__trap" aria-hidden="true">
				<label for="social-signup-website">Website</label>
				<input
					id="social-signup-website"
					v-model="website"
					type="text"
					name="website"
					tabindex="-1"
					autocomplete="off">
			</div>

			<div v-if="state.rules.length > 0" class="signup__rules">
				<h3 class="signup__rules-heading">
					{{ t('social', 'Server rules') }}
				</h3>
				<ol class="signup__rules-list">
					<li v-for="(rule, index) in state.rules" :key="index">
						{{ rule }}
					</li>
				</ol>
			</div>
			<p v-if="state.signupNotice" class="signup__instance-notice">
				{{ state.signupNotice }}
			</p>
			<NcCheckboxRadioSwitch
				v-if="state.signupNoticeRequired"
				v-model="noticeAccepted"
				class="signup__check"
				:class="{ 'signup__check--error': field === 'notice' }">
				{{ t('social', 'I have read and accept this registration notice') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-model="rules" class="signup__check" :class="{ 'signup__check--error': field === 'rules' }">
				{{ t('social', 'I accept the server rules') }}
			</NcCheckboxRadioSwitch>
			<p v-if="state.privacyUrl || state.legalUrl" class="signup__legal">
				<a
					v-if="state.privacyUrl"
					:href="state.privacyUrl"
					target="_blank"
					rel="noopener noreferrer">{{ t('social', 'Privacy policy') }}</a>
				<a
					v-if="state.legalUrl"
					:href="state.legalUrl"
					target="_blank"
					rel="noopener noreferrer">{{ t('social', 'Legal notice') }}</a>
			</p>

			<NcCheckboxRadioSwitch
				v-if="state.minAge > 0"
				v-model="age"
				class="signup__check"
				:class="{ 'signup__check--error': field === 'age' }">
				{{ t('social', 'I am at least {age} years old', { age: state.minAge }) }}
			</NcCheckboxRadioSwitch>

			<NcNoteCard v-if="message && !['handle', 'email', 'password'].includes(field)" type="error">
				{{ message }}
			</NcNoteCard>

			<div class="signup__actions signup__actions--split">
				<NcButton variant="tertiary" @click="showForm = false">
					{{ t('social', 'Back to the overview') }}
				</NcButton>
				<NcButton
					type="submit"
					variant="primary"
					class="signup__submit"
					:disabled="sending">
					<template v-if="sending" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Create account') }}
				</NcButton>
			</div>

			<p class="signup__login">
				<a :href="state.loginUrl">{{ t('social', 'I already have an account') }}</a>
			</p>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { signupUrl } from '../services/externalApi.js'

/** What `ExternalSignupService::pageState()` provides when it provides nothing. */
const CLOSED = {
	open: false,
	reason: '',
	mode: 'approval',
	invited: false,
	inviteToken: '',
	approval: true,
	verifyEmail: true,
	twoFactorRequired: false,
	minAge: 0,
	rules: [],
	privacyUrl: '',
	legalUrl: '',
	signupNotice: '',
	signupNoticeRequired: false,
	signupNoticeVersion: '',
	domain: '',
	loginUrl: '/login',
	result: null,
}

/**
 * Registering an account that reaches Social only.
 *
 * Everything the page may say comes from the server: whether registration is
 * open, the rules, the minimum age, and the reasons a registration is turned
 * down, already translated. The page only arranges it.
 */
export default {
	name: 'Signup',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
	},

	data() {
		const state = { ...CLOSED, ...loadState('social', 'signup', {}) }

		return {
			state,
			handle: '',
			email: '',
			password: '',
			rules: false,
			noticeAccepted: false,
			age: false,
			website: '',
			sending: false,
			/** the field the server refused, or '' */
			field: '',
			message: '',
			showForm: false,
			/** @type {{state: string, handle?: string, message?: string}|null} */
			outcome: state.result ?? null,
		}
	},

	computed: {
		/** @return {string} */
		heading() {
			if (this.outcome?.state === 'created') {
				return t('social', 'Your account is ready')
			}
			if (this.outcome?.state === 'verify') {
				return t('social', 'Check your email')
			}
			if (this.outcome?.state === 'approval') {
				return t('social', 'Thank you')
			}

			return t('social', 'Create an account')
		},

		/**
		 * Where in the registration this page is: the overview, the form,
		 * or what became of it.
		 *
		 * @return {number} 1, 2 or 3
		 */
		step() {
			if (this.outcome) {
				return 3
			}

			return this.showForm ? 2 : 1
		},

		/** @return {string} */
		lede() {
			if (this.state.invited) {
				return t('social', 'You were invited to join {domain}. Your account can be used for Aloha Social, and nothing else on this server.', { domain: this.state.domain })
			}
			if (this.state.approval) {
				return t('social', 'Join {domain}. Your account can be used for Aloha Social, and nothing else on this server. An administrator looks at every registration before it becomes an account.', { domain: this.state.domain })
			}

			return t('social', 'Join {domain}. Your account can be used for Aloha Social, and nothing else on this server.', { domain: this.state.domain })
		},

		/** @return {string} registration steps configured for this instance */
		registrationPath() {
			if (this.state.invited) {
				return t('social', 'Your invitation lets you register now. {emailStep}', { emailStep: this.state.verifyEmail ? t('social', 'You will first confirm your email address.') : '' })
			}
			if (this.state.mode === 'invite') {
				return t('social', 'This server accepts registrations by invitation only.')
			}
			if (this.state.approval) {
				return t('social', 'After you submit, an administrator will review your request. {emailStep}', { emailStep: this.state.verifyEmail ? t('social', 'You will first confirm your email address.') : '' })
			}

			return this.state.verifyEmail
				? t('social', 'After you submit, confirm your email address to activate your account.')
				: t('social', 'After you submit, your account will be ready to use.')
		},

		/** @return {string} the address the handle will be */
		address() {
			const handle = this.handle.trim().replace(/^@/, '').toLowerCase()

			return t('social', 'Your address will be {address}', { address: '@' + (handle || t('social', 'username')) + '@' + this.state.domain })
		},

		/** @return {string} */
		outcomeText() {
			switch (this.outcome?.state) {
				case 'verify':
					return t('social', 'We sent a link to {email}. Follow it within 24 hours to confirm your address.', { email: this.email || t('social', 'your email address') })
				case 'approval':
					return t('social', 'Your registration is waiting for an administrator. You get an email once it has been looked at.')
				case 'created':
					return t('social', 'Log in as {handle} with the password you chose.', { handle: this.outcome.handle ?? '' })
				default:
					return this.outcome?.message ?? t('social', 'Something went wrong.')
			}
		},

		/** @return {string} */
		signupPage() {
			return signupUrl()
		},

		/**
		 * The first login, for somebody who has just registered.
		 *
		 * The page promises an introduction after joining; the way in is the
		 * home timeline carrying `welcome`, which is what the account setup
		 * screen reloads with and what the introduction shows for. The login
		 * is core's, and it takes the new reader there on its way through.
		 *
		 * @return {string}
		 */
		firstRunLoginUrl() {
			const app = generateUrl('/apps/social/') + '?welcome=1'
			const separator = this.state.loginUrl.includes('?') ? '&' : '?'

			return this.state.loginUrl + separator + 'redirect_url=' + encodeURIComponent(app)
		},
	},

	mounted() {
		// the two columns need more than the guest wrapper's 700px; the
		// class goes back when the page does, so the login keeps its width
		this.guestWrapper = document.querySelector('.wrapper')
		this.guestWrapper?.classList.add('social-signup-wide')
	},

	unmounted() {
		this.guestWrapper?.classList.remove('social-signup-wide')
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async submit() {
			this.sending = true
			this.field = ''
			this.message = ''
			try {
				const { data } = await axios.post(signupUrl(), {
					handle: this.handle,
					email: this.email,
					password: this.password,
					rules: this.rules,
					notice: this.noticeAccepted,
					noticeVersion: this.state.signupNoticeVersion,
					age: this.age,
					invite: this.state.inviteToken,
					website: this.website,
				})
				this.password = ''
				this.outcome = data
			} catch (error) {
				this.field = error?.response?.data?.field ?? ''
				this.message = error?.response?.data?.message
					|| (error?.response?.status === 429
						? t('social', 'Too many registrations right now, please try again later.')
						: t('social', 'The registration could not be sent. Please try again.'))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
// The guest layout's box is 440px by habit; registration carries an
// introduction, a form and the instance's rules, so it takes the wide
// variant core offers and fills a widened wrapper instead. The wrapper
// itself is core's (700px); the page asks for more while it is mounted
// and hands it back when it is gone.
.signup {
	width: min(1024px, 100%);
	text-align: start;

	&__brand {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 4);
		margin-block-end: calc(var(--default-grid-baseline) * 5);
	}

	&__logo {
		flex: none;
		width: 52px;
		height: 52px;
		background-image: url('../../img/social-dark.svg');
		background-repeat: no-repeat;
		background-position: center;
		background-size: contain;
		filter: var(--background-invert-if-dark);
	}

	&__eyebrow {
		margin: 0;
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
	}

	&__heading {
		margin: calc(var(--default-grid-baseline) * 1) 0 0;
		font-size: 26px;
		font-weight: 700;
		line-height: 1.25;
	}

	// three beats, one line on a wide page: what the page is, what it
	// asks, what became of it
	&__steps {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
		margin: 0 0 calc(var(--default-grid-baseline) * 6);
		padding: 0;
		list-style: none;
	}

	&__step {
		display: flex;
		flex: 1 1 0;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding-block-end: calc(var(--default-grid-baseline) * 2);
		border-block-end: 2px solid var(--color-border);
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		white-space: nowrap;

		&--current {
			border-block-end-color: var(--color-primary-element);
			color: var(--color-main-text);
			font-weight: 700;
		}
	}

	&__step-dot {
		display: inline-flex;
		flex: none;
		align-items: center;
		justify-content: center;
		width: 24px;
		height: 24px;
		border-radius: 50%;
		background: var(--color-background-darker);
		font-size: 12px;
		font-weight: 700;

		.signup__step--current & {
			background: var(--color-primary-element);
			color: var(--color-primary-element-text);
		}
	}

	// an outcome or a refusal is a short message, not a page: it keeps
	// to a column so it does not stretch across the wide box
	&__narrow {
		max-width: 560px;
		margin-inline: auto;
	}

	&__landing {
		line-height: 1.6;
	}

	// the introduction reads, the next steps sit: two columns while
	// there is room, one below it
	&__columns {
		display: grid;
		grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
		gap: calc(var(--default-grid-baseline) * 6);
		align-items: start;

		@media (max-width: 640px) {
			grid-template-columns: minmax(0, 1fr);
		}
	}

	&__intro {
		h3 {
			margin-block: 0 calc(var(--default-grid-baseline) * 3);
			font-size: 17px;
			font-weight: 700;
			line-height: 1.4;
		}

		p {
			margin-block: 0 calc(var(--default-grid-baseline) * 3);
		}
	}

	&__side {
		padding: calc(var(--default-grid-baseline) * 4);
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);

		p {
			margin-block: 0 calc(var(--default-grid-baseline) * 3);

			&:last-child {
				margin-block-end: 0;
			}
		}
	}

	&__side-heading {
		margin-block: 0 calc(var(--default-grid-baseline) * 2);
		font-size: 14px;
		font-weight: 700;
	}

	&__lede {
		margin-block: 0 calc(var(--default-grid-baseline) * 4);
		color: var(--color-text-maxcontrast);
		font-size: 15px;
		line-height: 1.6;
	}

	&__form {
		display: flex;
		flex-direction: column;
	}

	// handle and email side by side while there is room; the password
	// takes the row beneath them
	&__fields {
		display: grid;
		grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
		gap: 0 calc(var(--default-grid-baseline) * 4);
		margin-block-end: calc(var(--default-grid-baseline) * 4);

		@media (max-width: 560px) {
			grid-template-columns: minmax(0, 1fr);
		}
	}

	&__field--full {
		grid-column: 1 / -1;
	}

	// out of sight and out of the tab order, but still in the form
	&__trap {
		position: absolute;
		inset-inline-start: -10000px;
		width: 1px;
		height: 1px;
		overflow: hidden;
	}

	&__rules {
		margin-block-end: calc(var(--default-grid-baseline) * 4);
		padding: calc(var(--default-grid-baseline) * 4);
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);
	}

	&__instance-notice {
		margin-block: 0 calc(var(--default-grid-baseline) * 4);
		padding: calc(var(--default-grid-baseline) * 3);
		border-inline-start: 3px solid var(--color-primary-element);
		background: var(--color-background-hover);
		white-space: pre-wrap;
	}

	// inside the side card the notice is one row among others, not a
	// block of its own
	&__side &__instance-notice {
		margin-block: calc(var(--default-grid-baseline) * 3) 0;
	}

	&__rules-heading {
		margin-block: 0 calc(var(--default-grid-baseline) * 2);
		font-size: 1em;
	}

	&__rules-list {
		margin: 0;
		padding-inline-start: 1.5em;
		list-style: decimal;
	}

	&__legal {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);

		a {
			text-decoration: underline;
		}
	}

	&__side &__legal {
		margin-block: calc(var(--default-grid-baseline) * 3) 0;
	}

	&__check {
		margin-block-end: calc(var(--default-grid-baseline) * 2);
	}

	&__check--error {
		color: var(--color-error-text, var(--color-error));
	}

	// Nextcloud's checkbox icon sits a few pixels above its label baseline;
	// align the small signup confirmations with the first line of their text.
	&__check :deep(.checkbox-radio-switch__icon) {
		transform: translateY(0.25em);
	}

	&__actions {
		margin-block-start: calc(var(--default-grid-baseline) * 4);

		// two answers side by side while they fit; the submit takes
		// what the way back does not need
		&--split {
			display: flex;
			flex-wrap: wrap;
			gap: calc(var(--default-grid-baseline) * 3);
			align-items: center;
		}
	}

	&__submit {
		flex: 1 1 240px;
	}

	&__login {
		margin-block: calc(var(--default-grid-baseline) * 3) 0;
		text-align: center;

		a {
			text-decoration: underline;
		}
	}
}
</style>

<style>
/* The guest wrapper centers every public page at 700px; registration lays
   an introduction beside its form and needs the room. Unscoped, because the
   wrapper is core's markup above this component, and narrowed to the class
   the page puts on while it is mounted. */
.wrapper.social-signup-wide {
	max-width: 1080px;
}
</style>
