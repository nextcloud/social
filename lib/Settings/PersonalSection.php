<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {

	private IURLGenerator $url;
	private IL10N $l;

	public function __construct(IURLGenerator $generator, IL10N $l) {
		$this->url = $generator;
		$this->l = $l;
	}

	public function getID() {
		return 'social';
	}

	public function getName() {
		return $this->l->t('Social');
	}

	public function getPriority() {
		return 99;
	}

	public function getIcon(): string {
		return $this->url->imagePath('social', 'social-dark.svg');
	}
}
