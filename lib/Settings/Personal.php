<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Personal implements ISettings {
	public function getForm(): TemplateResponse {
		return new TemplateResponse('social', 'settings-personal');
	}

	public function getSection(): string {
		return 'social';
	}

	public function getPriority(): int {
		return 99;
	}
}
