/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/**
 * Install the Nextcloud globals used by Social templates in the profile app.
 * A custom element owns a separate Vue app, so it cannot inherit the globals
 * installed by the main Social entry.
 *
 * @param {object} app Vue app created by `defineCustomElement`
 * @param {object} globals Nextcloud's `OC` and `OCA` globals
 */
export function configureSocialProfileApp(app, globals) {
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.config.globalProperties.OC = globals.OC
	app.config.globalProperties.OCA = globals.OCA
}

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
