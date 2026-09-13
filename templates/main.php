<?php

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The framework — Vue, @nextcloud/vue, pinia — is one file shared by every
// entry point of this app rather than a copy inside each, so it has to be
// loaded before the entry that expects it. Nextcloud keeps the order they
// are added in, and adding the same script twice on one page is a no-op.
\OCP\Util::addScript('social', 'social-framework');
\OCP\Util::addScript('social', 'social-social');
