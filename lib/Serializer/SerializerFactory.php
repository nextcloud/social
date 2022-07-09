<?php

declare(strict_types=1);

// Nextcloud - Social Support
// SPDX-FileCopyrightText: 2022 Carl Schwan <carl@carlschwan.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Social\Serializer;

use Psr\Container\ContainerInterface;

class SerializerFactory {
	/**
	 * @template T
	 * @var array<class-string<T>, class-string<ActivityPubSerializer<T>>>
	 */
	private array $serializers = [];
	private ContainerInterface $container;

	public function __construct(ContainerInterface $container) {
		$this->container = $container;
	}

	/**
	 * @template T
	 * @param class-string<T> $className
	 * @param class-string<ActivityPubSerializer<T>> $serializerName
	 */
	public function registerSerializer(string $className, string $serializerName): void {
		$this->serializers[$className] = $serializerName;
	}

	/**
	 * @template T
	 * @param T $object
	 * @return ActivityPubSerializer<T>
	 */
	public function getSerializerFor(object $object): ActivityPubSerializer {
		return $this->container->get($this->serializers[get_class($object)]);
	}
}
