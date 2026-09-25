/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Runs vue-tsc against a TypeScript of its own.
 *
 * `vue-tsc` works by loading TypeScript's JavaScript compiler and patching its
 * host so that it understands `.vue`. TypeScript 7 is the native compiler and
 * ships no JavaScript one — twenty platform binaries and an `exports` map with
 * no `lib/tsc` in it — so `vue-tsc` cannot load it and exits with
 * `ERR_PACKAGE_PATH_NOT_EXPORTED` before checking anything.
 *
 * Its `run()` takes the path to a tsc, and only its own `bin` leaves that to
 * `require.resolve('typescript/lib/tsc')`. So this hands it the TypeScript 5
 * installed beside the project one as `vue-tsc-typescript`, and the components
 * keep being checked while `tsc` checks the plain-JavaScript half with 7.
 *
 * This file comes out, and the alias with it, once vue-tsc can drive the
 * native compiler.
 */
require('vue-tsc').run(require.resolve('vue-tsc-typescript/lib/tsc'))
