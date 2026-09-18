<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!--
		Drawn here rather than shipped as a file, for the reason NoReplies
		gives: the images in img/undraw are licensed for the eight that were
		already in the app, they are drawn in colours this app uses nowhere
		else, and a component this small costs less than another asset to
		fetch. Two envelopes, the near one open, in `currentColor` so it
		follows the theme rather than being corrected by a filter.
	-->
	<svg
		class="no-messages"
		viewBox="0 0 64 56"
		width="64"
		height="56"
		fill="none"
		aria-hidden="true"
		focusable="false">
		<g stroke="currentColor" stroke-width="2" stroke-linejoin="round">
			<rect
				class="no-messages__far"
				x="4"
				y="8"
				width="38"
				height="26"
				rx="4" />
			<path class="no-messages__far" d="M4 13l19 12 19-12" />
			<rect
				class="no-messages__near"
				x="22"
				y="22"
				width="38"
				height="26"
				rx="4" />
			<path class="no-messages__near" d="M22 27l19 12 19-12" />
		</g>
		<g class="no-messages__spark" fill="currentColor">
			<circle cx="52" cy="12" r="2" />
			<circle cx="58" cy="6" r="1.5" />
			<circle cx="46" cy="5" r="1.5" />
		</g>
	</svg>
</template>

<script>
/**
 * Nobody has written to you yet.
 *
 * Its own drawing rather than the stock illustration that was here: that one
 * is two people whispering, in a pink and a blue this app uses nowhere else,
 * and an empty state is the app talking about itself.
 */
export default {
	name: 'NoMessages',
}
</script>

<style scoped lang="scss">
.no-messages {
	color: var(--color-text-maxcontrast);
}

/* the far envelope sits behind, so it is quieter; the near one is the subject */
.no-messages__far {
	opacity: .45;
}

.no-messages__near {
	fill: var(--color-main-background);
}

.no-messages__spark {
	opacity: .7;
}
</style>
