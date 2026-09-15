<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social-admin">
		<ActivitySection :activity="state.activity" />
		<ReportsSection
			:reports="state.reports"
			:openTotal="state.openReports"
			:resolvedTotal="state.resolvedReports"
			:perPage="state.reportsPerPage" />
		<ReviewSection
			:queue="state.review"
			:total="state.reviewTotal"
			:reviewFirstPost="state.reviewFirstPost"
			:autospam="state.autospam" />
		<AccountsSection />
		<RetentionSection :days="state.retentionDays" />
		<FederationSection :federation="state.federation" />
		<AccessSection :accessType="state.accessType" :addresses="state.accessList" />
		<AnnouncementsSection />
		<!-- not for a delegate: the server is administered, not moderated, and
		     `AdminSettings` sends no server settings to one -->
		<ServerSection v-if="state.server !== null" :settings="state.server" />
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import AccessSection from './AccessSection.vue'
import ActivitySection from './ActivitySection.vue'
import AccountsSection from './AccountsSection.vue'
import AnnouncementsSection from './AnnouncementsSection.vue'
import FederationSection from './FederationSection.vue'
import ReportsSection from './ReportsSection.vue'
import RetentionSection from './RetentionSection.vue'
import ReviewSection from './ReviewSection.vue'
import ServerSection from './ServerSection.vue'

/** What `AdminSettings::getForm()` provides when it provides nothing. */
const NOTHING = {
	reports: [],
	openReports: 0,
	resolvedReports: 0,
	reportsPerPage: 50,
	activity: { day: { posts: 0, authors: 0 }, week: { posts: 0, authors: 0 } },
	review: [],
	reviewTotal: 0,
	reviewFirstPost: true,
	autospam: true,
	server: null,
	accessType: 'all_but',
	accessList: [],
	retentionDays: 0,
	federation: {
		waiting: 0,
		running: 0,
		failing: 0,
		atRisk: 0,
		abandoned: 0,
		maxTries: 15,
		truncated: false,
		abandonedTruncated: false,
		retentionDays: 7,
		instances: [],
		givenUp: [],
	},
}

/**
 * The nine sections of Administration → Social.
 *
 * Nothing here uses `v-html`, and nothing below it does either. Half of what
 * these tables draw — a handle, an instance name, the comment on a report — is
 * a string another server sent, and this is the page whose buttons delete
 * accounts. Vue escapes interpolation; `v-html` is the one way to lose that.
 */
export default {
	name: 'AdminSettings',

	components: {
		AccessSection,
		ActivitySection,
		AccountsSection,
		AnnouncementsSection,
		FederationSection,
		ReportsSection,
		RetentionSection,
		ReviewSection,
		ServerSection,
	},

	data() {
		return {
			state: { ...NOTHING, ...loadState('social', 'adminSettings', {}) },
		}
	},
}
</script>

<style lang="scss">
// Deliberately not scoped: the table below is the same table in four of the
// sections, and a moderator reading a page of reports should not have to learn
// a second layout halfway down it.
.social-admin {
	&__table {
		width: 100%;
		border-collapse: collapse;
		margin-block: calc(var(--default-grid-baseline) * 2);

		th {
			text-align: start;
			font-weight: bold;
			color: var(--color-text-maxcontrast);
			padding: calc(var(--default-grid-baseline) * 2);
			border-block-end: 1px solid var(--color-border);
		}

		td {
			padding: calc(var(--default-grid-baseline) * 2);
			border-block-end: 1px solid var(--color-border);
			vertical-align: top;
			// an actor id is a URL, and one long one used to widen the table
			// until the decision buttons were off the side of the page
			overflow-wrap: anywhere;
		}
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: var(--default-grid-baseline);
		align-items: center;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__scroll {
		overflow-x: auto;
	}
}
</style>
