<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<ul class="skeleton" aria-hidden="true">
		<li v-for="index in count" :key="index" class="skeleton__post">
			<div class="skeleton__header">
				<span class="skeleton__avatar skeleton__shape" />
				<span class="skeleton__name skeleton__shape" />
			</div>
			<span class="skeleton__line skeleton__shape" />
			<span class="skeleton__line skeleton__line--short skeleton__shape" />
			<div class="skeleton__actions">
				<span v-for="action in 3" :key="action" class="skeleton__action skeleton__shape" />
			</div>
		</li>
	</ul>
</template>

<script>
/**
 * The shape of the timeline while it loads, instead of a spinner on an empty
 * page: the posts are the same size and in the same place, so the timeline
 * settles into them rather than appearing from nothing.
 */
export default {
	name: 'TimelineSkeleton',
	props: {
		count: {
			type: Number,
			default: 3,
		},
	},
}
</script>

<style scoped lang="scss">
@keyframes skeleton-sheen {
	0% { background-position: 200% 0; }
	100% { background-position: -200% 0; }
}

.skeleton {
	list-style: none;

	&__post {
		padding: 18px 20px 14px;
		margin-bottom: 8px;
		border: 1px solid var(--color-border);
		border-radius: 8px;
		background: var(--color-main-background);
	}

	&__header {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 12px;
	}

	&__shape {
		display: block;
		border-radius: var(--border-radius, 3px);
		/* the sheen travels across a gradient wider than the element */
		background: linear-gradient(
			90deg,
			var(--color-background-dark) 25%,
			var(--color-background-hover) 37%,
			var(--color-background-dark) 63%
		);
		background-size: 400% 100%;
		animation: skeleton-sheen 1.4s ease-in-out infinite;
	}

	&__avatar {
		width: 32px;
		height: 32px;
		border-radius: 50%;
		flex-shrink: 0;
	}

	&__name {
		width: 40%;
		height: 14px;
	}

	&__line {
		width: 100%;
		height: 12px;
		margin-bottom: 8px;

		&--short {
			width: 65%;
		}
	}

	&__actions {
		display: flex;
		gap: 20px;
		margin-top: 14px;
	}

	&__action {
		width: 24px;
		height: 24px;
		border-radius: 50%;
	}
}

@media (prefers-reduced-motion: reduce) {
	.skeleton__shape {
		animation: none;
		background: var(--color-background-dark);
	}
}
</style>
