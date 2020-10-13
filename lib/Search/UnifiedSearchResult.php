<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
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


namespace OCA\Social\Search;


use OCP\Search\SearchResultEntry;


/**
 * Class SearchResultEntry
 *
 * @package OCA\Social\Search
 */
class UnifiedSearchResult extends SearchResultEntry {


	/**
	 * UnifiedSearchResult constructor.
	 *
	 * @param string $thumbnailUrl
	 * @param string $title
	 * @param string $subline
	 * @param string $resourceUrl
	 * @param string $icon
	 * @param bool $rounded
	 */
	public function __construct(
		string $thumbnailUrl = '', string $title = '', string $subline = '', string $resourceUrl = '',
		string $icon = '',
		bool $rounded = false
	) {
		parent::__construct($thumbnailUrl, $title, $subline, $resourceUrl, $icon, $rounded);
	}


	/**
	 * @return string
	 */
	public function getThumbnailUrl(): string {
		return $this->thumbnailUrl;
	}

	/**
	 * @param string $thumbnailUrl
	 *
	 * @return UnifiedSearchResult
	 */
	public function setThumbnailUrl(string $thumbnailUrl): self {
		$this->thumbnailUrl = $thumbnailUrl;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * @param string $title
	 *
	 * @return UnifiedSearchResult
	 */
	public function setTitle(string $title): self {
		$this->title = $title;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getSubline(): string {
		return $this->subline;
	}

	/**
	 * @param string $subline
	 *
	 * @return UnifiedSearchResult
	 */
	public function setSubline(string $subline): self {
		$this->subline = $subline;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getResourceUrl(): string {
		return $this->resourceUrl;
	}

	/**
	 * @param string $resourceUrl
	 *
	 * @return UnifiedSearchResult
	 */
	public function setResourceUrl(string $resourceUrl): self {
		$this->resourceUrl = $resourceUrl;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getIcon(): string {
		return $this->icon;
	}

	/**
	 * @param string $icon
	 *
	 * @return UnifiedSearchResult
	 */
	public function setIcon(string $icon): self {
		$this->icon = $icon;

		return $this;
	}


	/**
	 * @return bool
	 */
	public function isRounded(): bool {
		return $this->rounded;
	}

	/**
	 * @param bool $rounded
	 *
	 * @return UnifiedSearchResult
	 */
	public function setRounded(bool $rounded): self {
		$this->rounded = $rounded;

		return $this;
	}

}

