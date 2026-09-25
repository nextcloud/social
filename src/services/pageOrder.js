/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Where each page sits in the sidebar, so that moving between two of them has
 * a direction.
 *
 * A fade says a page was replaced; a slide says which way you went, and the
 * way you went is down or up the list you clicked in. The order here is the
 * sidebar's order -- see `Navigation.vue`, whose entries carry these same
 * destinations -- and anything not in the list sits past the end, because a
 * post, a tag or somebody's profile is somewhere you go *from* the sidebar
 * rather than a rung on it.
 */

/** The sidebar, top to bottom, as route identities. */
const ORDER = [
	'timeline:',
	'timeline:photos',
	'timeline:videos',
	'timeline:news',
	'timeline:notifications',
	'timeline:direct',
	'discover',
	'profile',
	'follow-requests',
	'timeline:favourites',
	'timeline:bookmarks',
	'statistics',
	'blocked-accounts',
	'settings',
]

/** Anything not on the list is below it, in the order it is reached. */
const BEYOND = ORDER.length

/**
 * What a route is called for the purpose of ordering.
 *
 * The timeline is six sidebar entries wearing one route name, told apart by
 * `params.type`, so the type is part of the identity. Everything else is its
 * route name.
 *
 * @param {{name?: string|symbol|null, params?: Record<string, string|string[]>}} route a route, or anything with `name` and `params`
 * @return {string} the identity
 */
export function pageIdentity(route) {
	const name = String(route?.name ?? '')
	if (name === 'timeline') {
		return 'timeline:' + String(route?.params?.type ?? '')
	}

	return name
}

/**
 * How far down the sidebar a page is.
 *
 * @param {{name?: string|symbol|null, params?: Record<string, string|string[]>}} route the route to place
 * @return {number} its rank, or one past the end for somewhere the sidebar does not list
 */
export function pageRank(route) {
	const at = ORDER.indexOf(pageIdentity(route))

	return at === -1 ? BEYOND : at
}

/**
 * Which way the reader went.
 *
 * `''` when there is no direction to give: the same page, or a move that
 * neither page is on the sidebar for -- a post to another post, say -- where
 * a slide would be inventing a geography that is not there.
 *
 * @param {{name?: string|symbol|null, params?: Record<string, string|string[]>}} to where they are going
 * @param {{name?: string|symbol|null, params?: Record<string, string|string[]>}} from where they were
 * @return {'forward'|'back'|''} the direction
 */
export function pageDirection(to, from) {
	if (from === undefined || from === null || pageIdentity(to) === pageIdentity(from)) {
		return ''
	}

	const there = pageRank(to)
	const here = pageRank(from)
	if (there === here) {
		return ''
	}

	return there > here ? 'forward' : 'back'
}
