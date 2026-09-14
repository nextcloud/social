<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		Drawn here rather than shipped as a file, for the same reason as
		NoReplies: the images in img/undraw are licensed for the eight that
		were already in the app and nothing else. Markup also follows the
		theme on its own — `currentColor` for the strokes and the page's own
		background inside the shapes, so they read as overlapping on light and
		on dark without a filter to correct either.

		A timeline with nobody in it: three empty cards, the nearest one still
		waiting to be written.
	-->
	<svg
		class="quiet-timeline"
		viewBox="0 0 72 56"
		width="72"
		height="56"
		fill="none"
		aria-hidden="true"
		focusable="false">
		<g stroke="currentColor" stroke-width="2" stroke-linejoin="round">
			<rect
				class="quiet-timeline__far"
				x="14"
				y="3"
				width="46"
				height="14"
				rx="4" />
			<rect
				class="quiet-timeline__mid"
				x="8"
				y="20"
				width="52"
				height="14"
				rx="4" />
			<rect
				class="quiet-timeline__near"
				x="4"
				y="37"
				width="58"
				height="16"
				rx="4" />
		</g>
		<g class="quiet-timeline__lines" fill="currentColor">
			<rect
				x="10"
				y="42"
				width="26"
				height="2.5"
				rx="1.25" />
			<rect
				x="10"
				y="47"
				width="16"
				height="2.5"
				rx="1.25" />
		</g>
	</svg>
</template>

<script>
export default {
	name: 'QuietTimeline',
}
</script>

<style scoped>
.quiet-timeline {
	color: var(--color-text-maxcontrast);
}

/* the ones behind sit back; the near card paints over them */
.quiet-timeline__far {
	opacity: .3;
}

.quiet-timeline__mid {
	opacity: .5;
	fill: var(--color-main-background);
}

.quiet-timeline__near {
	fill: var(--color-main-background);
}

.quiet-timeline__lines {
	opacity: .45;
}
</style>
