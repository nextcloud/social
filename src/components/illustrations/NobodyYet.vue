<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		Markup rather than a file, for the reason given in NoReplies: the
		img/undraw images are licensed for the eight already in the app.

		Nobody here yet: two faces at the edge of a circle with room in the
		middle for more.
	-->
	<svg
		class="nobody-yet"
		viewBox="0 0 64 56"
		width="64"
		height="56"
		fill="none"
		aria-hidden="true"
		focusable="false">
		<circle
			class="nobody-yet__ring"
			cx="32"
			cy="28"
			r="22"
			stroke="currentColor"
			stroke-width="2"
			stroke-dasharray="5 6" />
		<g stroke="currentColor" stroke-width="2" stroke-linejoin="round">
			<g class="nobody-yet__person">
				<circle
					class="nobody-yet__head"
					cx="23"
					cy="23"
					r="5" />
				<path class="nobody-yet__body" d="M14 38a9 9 0 0 1 18 0Z" />
			</g>
			<g class="nobody-yet__person nobody-yet__person--far">
				<circle
					class="nobody-yet__head"
					cx="42"
					cy="26"
					r="4" />
				<path class="nobody-yet__body" d="M35 38a7 7 0 0 1 14 0Z" />
			</g>
		</g>
	</svg>
</template>

<script>
export default {
	name: 'NobodyYet',
}
</script>

<style scoped>
.nobody-yet {
	color: var(--color-text-maxcontrast);
}

/* a dashed ring rather than a solid one: the circle is not closed yet */
.nobody-yet__ring {
	opacity: .4;
}

.nobody-yet__head,
.nobody-yet__body {
	fill: var(--color-main-background);
}

/* the second figure stands behind the first */
.nobody-yet__person--far {
	opacity: .55;
}
</style>
