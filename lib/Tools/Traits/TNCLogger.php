<?php

declare(strict_types=1);


/**
 * Some tools for myself.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2020, Maxence Lange <maxence@artificial-owl.com>
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


namespace OCA\Social\Tools\Traits;

use Exception;
use OC\HintException;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

trait TNCLogger {
	use TNCSetup;

	public static int $EMERGENCY = 4;
	public static int $ALERT = 3;
	public static int $CRITICAL = 3;
	public static int $ERROR = 3;
	public static int $WARNING = 2;
	public static int $NOTICE = 1;
	public static int $INFO = 1;
	public static int $DEBUG = 0;


	/**
	 * @param Throwable $t
	 * @param array $serializable
	 */
	public function t(Throwable $t, array $serializable = []): void {
		$this->throwable($t, self::$ERROR, $serializable);
	}

	/**
	 * @param Throwable $t
	 * @param int $level
	 * @param array $serializable
	 */
	public function throwable(Throwable $t, int $level = 3, array $serializable = []): void {
		$message = '';
		if (!empty($serializable)) {
			$message = json_encode($serializable);
		}

		$this->logger()
			 ->log(
				$level,
				$message,
				[
					'app' => $this->setup('app'),
					'exception' => $t
				]
			 );
	}


	/**
	 * @param Exception $e
	 * @param array $serializable
	 */
	public function e(Exception $e, array $serializable = []): void {
		$this->exception($e, self::$ERROR, $serializable);
	}

	/**
	 * @param Exception $e
	 * @param int|array $level
	 * @param array $serializable
	 */
	public function exception(Exception $e, $level = 3, array $serializable = []): void {
		if (is_array($level) && empty($serializable)) {
			$serializable = $level;
			$level = 3;
		}

		$message = '';
		if (!empty($serializable)) {
			$message = json_encode($serializable);
		}

		if ($level === self::$DEBUG) {
			$level = (int)$this->appConfig('debug_level');
		}

		$this->logger()
			 ->log(
				$level,
				$message,
				[
					'app' => $this->setup('app'),
					'exception' => $e
				]
			 );
	}


	/**
	 * @param string $message
	 * @param bool $trace
	 * @param array $serializable
	 */
	public function emergency(string $message, bool $trace = false, array $serializable = []): void {
		$this->log(self::$EMERGENCY, '[emergency] ' . $message, $trace, $serializable);
	}

	/**
	 * @param string $message
	 * @param bool $trace
	 * @param array $serializable
	 */
	public function alert(string $message, bool $trace = false, array $serializable = []): void {
		$this->log(self::$ALERT, '[alert] ' . $message, $trace, $serializable);
	}

	/**
	 * @param string $message
	 * @param bool $trace
	 * @param array $serializable
	 */
	public function warning(string $message, bool $trace = false, array $serializable = []): void {
		$this->log(self::$WARNING, '[warning] ' . $message, $trace, $serializable);
	}

	/**
	 * @param string $message
	 * @param bool $trace
	 * @param array $serializable
	 */
	public function notice(string $message, bool $trace = false, array $serializable = []): void {
		$this->log(self::$NOTICE, '[notice] ' . $message, $trace, $serializable);
	}

	/**
	 * @param string $message
	 * @param array $serializable
	 */
	public function debug(string $message, array $serializable = []): void {
		$message = '[debug] ' . $message;
		$debugLevel = (int)$this->appConfig('debug_level');
		$this->log($debugLevel, $message, ($this->appConfig('debug_trace') === '1'), $serializable);
	}


	/**
	 * @param int $level
	 * @param string $message
	 * @param bool $trace
	 * @param array $serializable
	 */
	public function log(int $level, string $message, bool $trace = false, array $serializable = []): void {
		$opts = ['app' => $this->setup('app')];
		if ($trace) {
			$opts['exception'] = new HintException($message, json_encode($serializable));
		} elseif (!empty($serializable)) {
			$message .= ' -- ' . json_encode($serializable);
		}

		$this->logger()
			 ->log($level, $message, $opts);
	}


	/**
	 * @return LoggerInterface
	 */
	public function logger(): LoggerInterface {
		if (isset($this->logger) && $this->logger instanceof LoggerInterface) {
			return $this->logger;
		} else {
			return Server::get(LoggerInterface::class);
		}
	}
}
