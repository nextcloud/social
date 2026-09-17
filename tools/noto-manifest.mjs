/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Regenerates the shipped list of Noto Animated Emoji.
 *
 * Run it with `node tools/noto-manifest.mjs` when Google adds to the set; it
 * rewrites `data/noto-animated-emoji.json`, which is what `GifPackService`
 * reads. Only the *list* is shipped -- the pictures themselves are fetched by
 * the instance on first use and kept in appdata, because all of them together
 * are over half a gigabyte.
 *
 * The emoji are CC BY 4.0. The attribution that licence requires is in
 * `REUSE.toml`, in the picker itself, and in the manifest below.
 */

import { writeFile } from 'node:fs/promises'

/** Google's own index of the set, the same one their gallery reads. */
const API = 'https://googlefonts.github.io/noto-emoji-animation/data/api.json'

/** Where the manifest goes, relative to the app root. */
const OUT = new URL('../data/noto-animated-emoji.json', import.meta.url)

/**
 * A tag as a person would read it: `:face-in-clouds:` is "face in clouds".
 *
 * @param {string} tag one of an icon's tags
 * @return {string} the words in it
 */
function words(tag) {
	return tag.replace(/^:|:$/g, '').replace(/[-_]+/g, ' ').trim().toLowerCase()
}

const response = await fetch(API)
if (!response.ok) {
	throw new Error(`${API} answered ${response.status}`)
}

const { icons } = await response.json()

// most asked for first: it is the order the picker offers them in, and the
// first screenful is what an instance ends up fetching
const sorted = [...icons].sort((a, b) => (b.popularity ?? 0) - (a.popularity ?? 0))

const emoji = sorted.map((icon) => {
	const tags = (icon.tags ?? []).map(words).filter((tag) => tag !== '')
	const categories = (icon.categories ?? []).map((category) => category.toLowerCase())

	return {
		c: icon.codepoint,
		// the first tag is Google's own name for it, and reads better than the
		// Unicode name: "face in clouds", not "FACE IN CLOUDS"
		t: tags[0] ?? icon.codepoint,
		// every tag and the category, so a search for "sad" or "food" finds
		// what somebody means rather than only what it is called
		k: [...new Set([...tags.slice(1), ...categories])].join(' '),
	}
})

const manifest = {
	name: 'Noto Animated Emoji',
	source: 'https://googlefonts.github.io/noto-emoji-animation/',
	license: 'CC-BY-4.0',
	attribution: 'Noto Animated Emoji, by Google, licensed CC BY 4.0',
	generator: 'tools/noto-manifest.mjs',
	emoji,
}

await writeFile(OUT, JSON.stringify(manifest, null, '\t') + '\n')
console.log(`wrote ${emoji.length} emoji to ${OUT.pathname}`)
