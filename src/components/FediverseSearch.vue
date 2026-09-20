<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="finder">
		<NcTextField
			v-model="query"
			class="finder__field"
			type="search"
			:label="t('social', 'Find people across the fediverse')"
			:placeholder="t('social', 'A name, a handle, or a subject')"
			trailingButtonIcon="close"
			:showTrailingButton="query !== ''"
			@trailingButtonClick="clear"
			@keydown.enter="search(true)">
			<template #icon>
				<AccountSearch :size="20" />
			</template>
		</NcTextField>

		<!--
			Which directories are being asked, and a way to ask just one.
			Named rather than implied: whose directory a reader is searching is
			not something to have to guess, and an instance's administrator
			chose this list.

			One control rather than a button per directory. Eight of them wrap
			onto two lines above an empty result list, which is a lot of
			furniture in front of a search box that has not been used yet — and
			the question they answer ("where am I looking?") has one answer at
			a time.
		-->
		<div v-if="sources.length > 1" class="finder__where">
			<span class="finder__where-label">{{ t('social', 'Looking in') }}</span>
			<NcActions :menuName="chosenLabel" variant="tertiary">
				<NcActionButton :modelValue="chosen === ''" @click="choose('')">
					<template #icon>
						<Earth :size="20" />
					</template>
					{{ t('social', 'Everywhere') }}
				</NcActionButton>
				<NcActionButton
					v-for="source in sources"
					:key="source.host"
					:modelValue="chosen === source.host"
					@click="choose(source.host)">
					{{ source.label }}
				</NcActionButton>
			</NcActions>
			<span class="finder__where-why">{{ whereWhy }}</span>
		</div>

		<NcLoadingIcon v-if="loading" class="finder__loading" :size="32" />

		<template v-else-if="asked">
			<ul v-if="accounts.length" class="finder__list">
				<PersonCard
					v-for="account in accounts"
					:key="account.acct"
					:account="account"
					:link="account.known === true"
					:reason="reasonFor(account)"
					:followed="isFollowed(account)"
					:pending="isPending(account)"
					@follow="follow" />
			</ul>

			<NcEmptyContent
				v-else
				:name="t('social', 'Nobody by that name')"
				:description="emptyDescription">
				<template #icon>
					<AccountSearch />
				</template>
			</NcEmptyContent>

			<!--
				A server that did not answer is not a server with nobody on it,
				and a reader about to try a different spelling is entitled to
				know which of the two they are looking at.
			-->
			<p v-if="quiet.length" class="finder__quiet">
				{{ n('social',
					'{names} did not answer. What is above is the rest.',
					'{names} did not answer. What is above is the rest.',
					quiet.length, { names: quiet.join(', ') }) }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountSearch from 'vue-material-design-icons/AccountSearch.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import PersonCard from './PersonCard.vue'
import { useFollowByHandle } from '../composables/useFollowByHandle.js'
import logger from '../services/logger.js'

/** How long the box waits after the last keystroke before it asks anybody. */
const DEBOUNCE = 400

/**
 * The shortest query worth sending to four servers. One letter matches
 * everybody and tells the reader nothing.
 */
const MIN_LENGTH = 2

/**
 * Finding somebody to follow when you do not know which server they are on.
 *
 * The app could already resolve a handle somebody had already given you, and
 * suggest people out of a follow graph a new account is not yet part of.
 * Neither answers "who is there" — which is what a directory is for, and what
 * this asks several of.
 *
 * Nothing is aggregated or stored here: the server asks the directories, drops
 * anybody on a domain this instance will not federate with, and answers. Each
 * source's outcome comes back with the results because a quiet server and an
 * empty one are different answers.
 */
export default {
	name: 'FediverseSearch',
	components: {
		AccountSearch,
		Earth,
		NcActionButton,
		NcActions,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
		PersonCard,
	},

	setup() {
		return useFollowByHandle()
	},

	data() {
		return {
			query: '',
			/** one directory's host, or '' for all of them */
			chosen: '',
			sources: [],
			accounts: [],
			/** what each source said about itself, as the server reported it */
			reports: [],
			loading: false,
			/** whether anything has been asked yet, so the empty state waits its turn */
			asked: false,
			timer: null,
		}
	},

	computed: {
		/** @return {string} the directory being searched, for the menu's own button */
		chosenLabel() {
			if (this.chosen === '') {
				return t('social', 'Everywhere')
			}

			return this.sources.find((source) => source.host === this.chosen)?.label ?? this.chosen
		},

		/**
		 * @return {string} how many directories that is, or why this one is on
		 * the list — the question the row of chips used to answer by existing
		 */
		whereWhy() {
			if (this.chosen !== '') {
				const source = this.sources.find((one) => one.host === this.chosen)

				return source ? this.whySource(source) : ''
			}

			return n('social', '%n directory', '%n directories', this.sources.length)
		},

		/** @return {string[]} the directories that were asked and did not answer */
		quiet() {
			return this.reports
				.filter((report) => report.status !== 'ok')
				.map((report) => report.label || report.host)
		},

		/**
		 * Why there is nothing, in the terms of what was actually asked.
		 *
		 * @return {string}
		 */
		emptyDescription() {
			if (this.chosen !== '') {
				return t('social', 'Nobody there matched. Try the other directories.')
			}

			return t('social', 'Nobody in these directories matched. A full handle — like @someone@example.org — finds a person on any server, listed or not.')
		},
	},

	watch: {
		query() {
			this.schedule()
		},
	},

	beforeMount() {
		this.loadSources()
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		/**
		 * Which directory answered for this person.
		 *
		 * The row's own reason for being on the page: with eight directories
		 * asked at once, "who told us about this account" is the difference
		 * between a name a reader recognises and one they do not.
		 *
		 * @param {object} account one result
		 * @return {string}
		 */
		reasonFor(account) {
			if (account.known) {
				return t('social', 'Known to this server')
			}

			const source = this.sources.find((one) => one.host === account.source)

			return source ? t('social', 'From {directory}', { directory: source.label }) : ''
		},

		/**
		 * Why a server is in this list, for the reader who wonders how their
		 * Nextcloud came to be asking a stranger's server about people. The
		 * three answers are different enough to be worth telling apart: one
		 * an administrator chose, one this instance already talks to every
		 * day, and one somebody else's list of servers suggested.
		 *
		 * @param {object} source the source as the server described it
		 * @return {string} what to show when the name is pointed at
		 */
		whySource(source) {
			if (source.origin === 'federation') {
				return t('social', 'Asked because this server already federates with it')
			}

			if (source.origin === 'discovered') {
				return t('social', 'Suggested by a directory of servers')
			}

			return t('social', 'Chosen by the administrator of this server')
		},

		t,
		n,

		/** Which directories this instance asks, in the order it asks them. */
		async loadSources() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/directories'))
				this.sources = Array.isArray(data) ? data : []
			} catch (error) {
				// the box still works; it simply cannot offer one directory at
				// a time
				logger.debug('Could not read the directory list', { error })
			}
		},

		choose(host) {
			this.chosen = host
			if (this.query.trim().length >= MIN_LENGTH) {
				this.search(true)
			}
		},

		clear() {
			this.query = ''
			this.accounts = []
			this.reports = []
			this.asked = false
		},

		/** Waits for the typing to stop, so a name is one search and not eight. */
		schedule() {
			clearTimeout(this.timer)
			if (this.query.trim().length < MIN_LENGTH) {
				this.asked = false
				this.accounts = []

				return
			}

			this.timer = setTimeout(() => this.search(), DEBOUNCE)
		},

		/**
		 * @param {boolean} now whether to skip the wait — the reader pressed
		 *                      Enter or picked a directory, which is not typing
		 */
		async search(now = false) {
			const query = this.query.trim()
			if (query.length < MIN_LENGTH) {
				return
			}

			if (now) {
				clearTimeout(this.timer)
			}

			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/directories/search'), {
					params: { q: query, source: this.chosen },
				})

				// the reader has typed on since this was asked, and these are
				// answers to a question that is no longer on screen
				if (query !== this.query.trim()) {
					return
				}

				this.accounts = Array.isArray(data?.accounts) ? data.accounts : []
				this.reports = Array.isArray(data?.sources) ? data.sources : []
				this.asked = true
			} catch (error) {
				logger.error('Could not search the fediverse directories', { error })
				this.accounts = []
				this.reports = []
				this.asked = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Where a row goes. Somebody this instance already holds has a profile
		 * here; somebody it has never met has one on their own server, and
		 * sending a reader there is honest about which of the two this is.
		 *
		 * @param {object} account one result
		 * @return {object} what to bind on the link
		 */
		linkFor(account) {
			if (account.known) {
				return { to: { name: 'profile', params: { account: account.acct } } }
			}

			return { href: account.url, target: '_blank', rel: 'noopener noreferrer' }
		},

		/** @param {object} account one result @return {string} the letter its avatar falls back to */
		initial(account) {
			return (account.display_name || account.username || '?').trim().charAt(0).toUpperCase()
		},

		/**
		 * A picture that will not load leaves a broken frame in every row;
		 * dropping it falls back to the initial.
		 *
		 * @param {object} account the row whose picture failed
		 */
		dropAvatar(account) {
			account.avatar = ''
		},
	},
}
</script>

<style scoped lang="scss">
.finder {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block-end: calc(var(--default-grid-baseline) * 4);
}

.finder__sources {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.finder__loading {
	margin: 24px auto;
}

.finder__list {
	list-style: none;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: var(--default-grid-baseline);
}

.finder__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding-inline-end: calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);

	&:hover,
	&:focus-within {
		background-color: var(--color-background-dark);
	}
}

.finder__person {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	min-width: 0;
	flex: 1 1 280px;
	color: inherit;
}

.finder__avatar {
	flex: 0 0 auto;
	width: 40px;
	height: 40px;
	border-radius: 50%;
	object-fit: cover;
	background-color: var(--color-background-dark);
}

.finder__avatar--blank {
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.finder__names {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1 1 auto;
}

.finder__name {
	font-weight: bold;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* said quietly, because it is a fact about the account rather than a warning */
.finder__bot {
	margin-inline-start: var(--default-grid-baseline);
	padding: 0 6px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-dark);
	font-size: var(--font-size-small, 0.85em);
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.finder__handle,
.finder__note {
	font-size: var(--font-size-small, 0.85em);
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.finder__follow {
	flex: 0 0 auto;
}

.finder__quiet {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 0.85em);
}
</style>
