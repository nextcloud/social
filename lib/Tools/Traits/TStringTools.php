<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools\Traits;

use Exception;

/**
 * Trait TStringTools
 *
 * @package OCA\Social\Tools\Traits
 */
trait TStringTools {
	/**
	 * @param int $length
	 *
	 * @return string
	 */
	protected function token(int $length = 15): string {
		$chars = 'qwertyuiopasdfghjklzxcvbnmQWERTYUIOPASDFGHJKLZXCVBNM1234567890';

		$str = '';
		$max = strlen($chars);
		for ($i = 0; $i < $length; $i++) {
			try {
				$str .= $chars[random_int(0, $max - 2)];
			} catch (Exception $e) {
			}
		}

		return $str;
	}


	/**
	 * Generate uuid: 2b5a7a87-8db1-445f-a17b-405790f91c80
	 *
	 * @param int $length
	 *
	 * @return string
	 */
	protected function uuid(int $length = 0): string {
		$uuid = sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff),
			mt_rand(0, 0xffff), mt_rand(0, 0xfff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);

		if ($length > 0) {
			if ($length <= 16) {
				$uuid = str_replace('-', '', $uuid);
			}

			$uuid = substr($uuid, 0, $length);
		}

		return $uuid;
	}


	/**
	 * @param string $str1
	 * @param string $str2
	 * @param bool $cs case sensitive ?
	 *
	 * @return string
	 */
	protected function commonPart(string $str1, string $str2, bool $cs = true): string {
		for ($i = 0; $i < strlen($str1) && $i < strlen($str2); $i++) {
			$chr1 = $str1[$i];
			$chr2 = $str2[$i];

			if (!$cs) {
				$chr1 = strtolower($chr1);
				$chr2 = strtolower($chr2);
			}

			if ($chr1 !== $chr2) {
				break;
			}
		}

		return substr($str1, 0, $i);
	}


	/**
	 * @param string $line
	 * @param array $params
	 *
	 * @return string
	 */
	protected function feedStringWithParams(string $line, array $params): string {
		$ak = array_keys($params);
		foreach ($ak as $k) {
			$line = str_replace('{' . $k . '}', $params[$k], $line);
		}

		return $line;
	}


	/**
	 * @param int $words
	 *
	 * @return string
	 */
	public function generateRandomSentence(int $words = 5): string {
		$sentence = [];
		for ($i = 0; $i < $words; $i++) {
			$sentence[] = $this->generateRandomWord(rand(2, 12));
		}

		return implode(' ', $sentence);
	}


	/**
	 * @param int $length
	 *
	 * @return string
	 */
	public function generateRandomWord(int $length = 8): string {
		$c = ['b', 'c', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'm', 'n', 'p', 'r', 's', 't', 'v'];
		$v = ['a', 'e', 'i', 'o', 'u', 'y'];

		$word = [];
		for ($i = 0; $i <= ($length / 2); $i++) {
			$word[] = $c[array_rand($c)];
			$word[] = $v[array_rand($v)];
		}

		return implode('', $word);
	}
}
