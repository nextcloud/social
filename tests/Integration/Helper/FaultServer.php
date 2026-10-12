<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Helper;

use RuntimeException;

/**
 * Fake inboxes that misbehave on purpose, each served by a PHP built-in
 * server of its own on a free loopback port (`fault-router.php` says how
 * each one misbehaves).
 *
 * Every inbox is a host name of its own under `.localhost`, which libcurl
 * resolves to the loopback without asking DNS: the delivery code tells peers
 * apart by host name — one circuit breaker and one request per wave each —
 * so each fake peer needs a name, and a port alone would not give it one. A
 * process per peer, so a peer that hangs holds up only itself, and stopping
 * one leaves nothing running behind it.
 */
final class FaultServer {
	/** @var array<string, array{port: int, process: resource}> host => its server */
	private array $servers = [];

	private string $dir;

	public static function start(): self {
		$server = new self();
		$server->dir = sys_get_temp_dir() . '/social-faults-' . bin2hex(random_bytes(6));
		mkdir($server->dir);

		return $server;
	}

	public function stop(): void {
		foreach ($this->servers as $server) {
			proc_terminate($server['process']);
			proc_close($server['process']);
		}
		$this->servers = [];
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->dir);
	}

	/**
	 * The inbox of a fake peer, started on first use: `$behaviour` is how it
	 * misbehaves, `$name` keeps it apart from every other peer.
	 */
	public function inbox(string $behaviour, string $name): string {
		$host = $this->host($behaviour, $name);
		$this->servers[$host] ??= $this->serve();

		return 'http://' . $host . ':' . $this->servers[$host]['port'] . '/inbox';
	}

	/** The host name of a fake peer, as the delivery code keys it. */
	public function host(string $behaviour, string $name): string {
		return $behaviour . '-' . $name . '.faults.localhost';
	}

	/** From now on the peer answers 202, whatever its name says. */
	public function bringUp(string $behaviour, string $name): void {
		file_put_contents($this->dir . '/' . $behaviour . '-' . $name . '.state', 'up');
	}

	/**
	 * Every request the peers received, in order.
	 *
	 * @return list<array{host: string, method: string, id: string, answer: int, at: float}>
	 */
	public function hits(): array {
		$hits = [];
		foreach (@file($this->dir . '/hits.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
			$hit = json_decode($line, true);
			if (is_array($hit)) {
				$hits[] = $hit;
			}
		}

		return $hits;
	}

	/**
	 * The requests one peer received.
	 *
	 * @return list<array{host: string, method: string, id: string, answer: int, at: float}>
	 */
	public function hitsOn(string $behaviour, string $name): array {
		$host = $this->host($behaviour, $name);

		return array_values(array_filter($this->hits(), static fn (array $hit): bool => $hit['host'] === $host));
	}

	/** @return array{port: int, process: resource} */
	private function serve(): array {
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if ($socket === false) {
			throw new RuntimeException('no free port: ' . $error);
		}
		$port = (int)substr((string)strrchr((string)stream_socket_get_name($socket, false), ':'), 1);
		fclose($socket);

		$log = $this->dir . '/server-' . $port . '.log';
		$process = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fault-router.php'],
			[0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
			$pipes,
			$this->dir,
			['FAULT_DIR' => $this->dir] + getenv()
		);
		if (!is_resource($process)) {
			throw new RuntimeException('a fault server did not start');
		}

		$until = microtime(true) + 10;
		while (microtime(true) < $until) {
			$probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
			if ($probe !== false) {
				fclose($probe);

				return ['port' => $port, 'process' => $process];
			}
			usleep(20_000);
		}

		proc_terminate($process);
		throw new RuntimeException('a fault server never answered on port ' . $port . ': ' . @file_get_contents($log));
	}
}
