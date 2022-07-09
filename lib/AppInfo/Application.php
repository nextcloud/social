<?php

declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Social\AppInfo;

use Closure;
use OCA\Social\Entity\Account;
use OCA\Social\Notification\Notifier;
use OCA\Social\Search\UnifiedSearchProvider;
use OCA\Social\Serializer\AccountSerializer;
use OCA\Social\Serializer\SerializerFactory;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\Feed\RedisFeedProvider;
use OCA\Social\Service\IFeedProvider;
use OCA\Social\Service\UpdateService;
use OCA\Social\WellKnown\WebfingerHandler;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\QueryException;
use OCP\IDBConnection;
use OCP\IServerContainer;
use OC\DB\SchemaWrapper;
use OCP\DB\ISchemaWrapper;
use Psr\Container\ContainerInterface;
use Throwable;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Class Application
 *
 * @package OCA\Social\AppInfo
 */
class Application extends App implements IBootstrap {
	public const APP_NAME = 'social';

	public function __construct(array $params = []) {
		parent::__construct(self::APP_NAME, $params);
	}


	/**
	 * @param IRegistrationContext $context
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(UnifiedSearchProvider::class);
		$context->registerWellKnownHandler(WebfingerHandler::class);
		$context->registerNotifierService(Notifier::class);

		/** @var SerializerFactory $serializerFactory */
		$serializerFactory = $this->getContainer()->get(SerializerFactory::class);
		$serializerFactory->registerSerializer(Account::class, AccountSerializer::class);

		$context->registerService(IFeedProvider::class, function (ContainerInterface $container): IFeedProvider {
			return $container->get(RedisFeedProvider::class);
		});
	}


	/**
	 * @param IBootContext $context
	 */
	public function boot(IBootContext $context): void {
	}
}
