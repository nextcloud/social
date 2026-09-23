/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Register a Social section with the Nextcloud Profile app.
 *
 * The profile page's classic app scripts are emitted before its `type=module`
 * entry. At that point `OCA.Profile.ProfileSections` does not exist yet. The
 * Profile app creates that registry immediately before mounting the page and
 * reads its sections during the mount, so deferring registration to
 * DOMContentLoaded or a timer is already too late. Intercept the registry's
 * first assignment and register synchronously, before the Profile page reads
 * it. If a Nextcloud version has already created the registry, register now.
 *
 * @param {object} profile the `OCA.Profile` namespace
 * @param {object} section section descriptor accepted by `registerSection`
 * @return {boolean} whether registration is immediate or has been queued
 */
export function registerProfileSection(profile, section) {
	if (profile.ProfileSections?.registerSection) {
		profile.ProfileSections.registerSection(section)
		return true
	}

	const descriptor = Object.getOwnPropertyDescriptor(profile, 'ProfileSections')
	if (descriptor && descriptor.configurable === false) {
		return false
	}

	let registry
	Object.defineProperty(profile, 'ProfileSections', {
		configurable: true,
		enumerable: descriptor?.enumerable ?? true,
		get: () => registry,
		set(value) {
			// Restore an ordinary data property before invoking the registry, so
			// later reads and any future assignments retain the Profile app's
			// expected semantics.
			Object.defineProperty(profile, 'ProfileSections', {
				configurable: true,
				enumerable: descriptor?.enumerable ?? true,
				writable: true,
				value,
			})
			registry = value
			value?.registerSection?.(section)
		},
	})

	return true
}
