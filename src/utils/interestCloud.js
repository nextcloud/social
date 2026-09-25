/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * How the interest cloud turns a rank into a size.
 *
 * The feed weighs a tag by `w(rank) = 1 / (1 + 0.15 · rank)`, and the cloud
 * draws the same curve, so what looks biggest is what the feed leans on most.
 * The curve is cut into six steps rather than drawn continuously: thirty
 * slightly different sizes read as noise, six read as tiers, and a tag that
 * moves one rank either keeps its size or changes it by one clear notch.
 */

/** the rem size of each step, top first */
export const STEP_SIZES = [2, 1.65, 1.38, 1.18, 1, 0.85]

/**
 * The lowest weight that still earns each step but the last. Chosen so the
 * steps hold 2, 2, 3, 4, 8 ranks and then the tail: the top of the cloud
 * changes size quickly, where the difference matters, and the tail settles.
 */
const STEP_FLOORS = [0.85, 0.65, 0.5, 0.38, 0.27]

/**
 * @param {number} rank 0-based
 * @return {number} the feed's weight for that rank, 1 at the top
 */
export function rankWeight(rank) {
	return 1 / (1 + 0.15 * Math.max(0, rank))
}

/**
 * @param {number} rank 0-based
 * @return {number} 0 (largest) to 5 (smallest)
 */
export function sizeStep(rank) {
	const weight = rankWeight(rank)
	const step = STEP_FLOORS.findIndex((floor) => weight >= floor)

	return step === -1 ? STEP_FLOORS.length : step
}

/**
 * A list with one entry taken out and put back at another index.
 *
 * @param {string[]} order the tags in rank order
 * @param {string} tag the one that moves
 * @param {number} position where it lands, 0-based, in the list without it
 * @return {string[]} a new list
 */
export function moveTo(order, tag, position) {
	const rest = order.filter((entry) => entry !== tag)
	const at = Math.max(0, Math.min(position, rest.length))

	return [...rest.slice(0, at), tag, ...rest.slice(at)]
}
