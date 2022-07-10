<?php

namespace OCA\Social;

use OCP\IURLGenerator;

class InstanceUtils {
	private IURLGenerator $generator;

	public function __construct(IURLGenerator $generator) {
		$this->generator = $generator;
	}
	/**
	 * Return the url of the instance: e.g. https://hello.social
	 */
	public function getLocalInstanceUrl(): string {
		$url = $this->generator->getAbsoluteURL('/');
		return rtrim($url, '/');
	}

	/**
	 * Return the name of the instance: e.g. hello.social
	 */
	public function getLocalInstanceName(): string {
		$url = $this->generator->getAbsoluteURL('/');
		$url = rtrim($url, '/');
		return substr($url, 8);
	}
}
