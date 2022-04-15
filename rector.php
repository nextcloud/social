<?php

declare(strict_types=1);

use Rector\Core\Configuration\Option;
use Rector\Php74\Rector\Property\TypedPropertyRector;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
	$parameters = $containerConfigurator->parameters();
	$parameters->set(Option::PATHS, [
		__DIR__ . '/lib',
		__DIR__ . '/tests/',
	]);

	$parameters->set(Option::BOOTSTRAP_FILES, [
		__DIR__ . '/vendor/autoload.php',
		__DIR__ . '/../../lib/composer/autoload.php',
		__DIR__ . '/../../3rdparty/autoload.php',
	]);

	$parameters->set(Option::AUTO_IMPORT_NAMES, true);
	$parameters->set(Option::IMPORT_SHORT_CLASSES, false);

	$services = $containerConfigurator->services();
	$services->set(TypedPropertyRector::class)
		->configure([
			TypedPropertyRector::INLINE_PUBLIC => true,
		]);
};
