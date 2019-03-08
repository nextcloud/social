<?php
namespace OCA\Social\Tests;

use OCA\Social\Controller\NavigationController;
use OCA\Social\Controller\SocialPubController;
use OCA\Social\Service\CacheActorService;
use OCP\IL10N;
use OCP\IRequest;

class SocialTest extends \PHPUnit\Framework\TestCase {

	public function testDummy() {
		/**
		 * Dummy test to check if phpunit is working properly
		 */
		$socialPub = new SocialPubController(
			'admin',
			$this->createMock(IRequest::class),
			$this->createMock(IL10N::class),
			$this->createMock(CacheActorService::class),
			$this->createMock(NavigationController::class)
		);
		$socialPub->actor('123');
		$this->assertTrue(true);
	}

}