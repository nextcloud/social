<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		Drawn here rather than shipped as a file: the illustrations in
		img/undraw are licensed for the eight images that were already in the
		app and nothing else, and this is small enough that a component costs
		less than another asset to fetch. It follows the theme because it is
		markup — `currentColor` for the strokes, the page's own background
		inside the near bubble, so the two read as overlapping on light and on
		dark without a filter to correct either.
	-->
	<svg
		class="no-replies"
		viewBox="0 0 64 56"
		width="64"
		height="56"
		fill="none"
		aria-hidden="true"
		focusable="false">
		<g stroke="currentColor" stroke-width="2" stroke-linejoin="round">
			<path
				class="no-replies__far"
				d="M8 5h34a4 4 0 0 1 4 4v16a4 4 0 0 1-4 4H20l-8 7v-7H8a4 4 0 0 1-4-4V9a4 4 0 0 1 4-4Z" />
			<path
				class="no-replies__near"
				d="M28 22h28a4 4 0 0 1 4 4v17a4 4 0 0 1-4 4h-4v7l-8-7H28a4 4 0 0 1-4-4V26a4 4 0 0 1 4-4Z" />
		</g>
		<g class="no-replies__dots" fill="currentColor">
			<circle cx="36" cy="35" r="2.5" />
			<circle cx="44" cy="35" r="2.5" />
			<circle cx="52" cy="35" r="2.5" />
		</g>
	</svg>
</template>

<script>
export default {
	name: 'NoReplies',
}
</script>

<style scoped>
.no-replies {
	color: var(--color-text-maxcontrast);
}

/* the far bubble sits behind: lighter, and the near one paints over it */
.no-replies__far {
	opacity: .45;
}

.no-replies__near {
	fill: var(--color-main-background);
}

.no-replies__dots {
	opacity: .55;
}
</style>
