/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The offline recovery page, a bundle of its own: the page is served alone
// and saved as one file, so nothing of the app's framework comes with it.
import { start } from './recovery/main.js'

start(document)
