<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

/**
 * Stable, local topic identifiers and labels for For You.
 * This catalog is deliberately data-only: resolving a topic never performs
 * network access or infers anything about the reader.
 */
final class RecommendationTopicCatalog {
	private const TOPICS = [
		['id' => 'technology', 'parentId' => null, 'labels' => ['en' => 'Technology', 'de' => 'Technologie'], 'aliases' => ['technology', 'technologie', 'tech']],
		['id' => 'tech.apple', 'parentId' => 'technology', 'labels' => ['en' => 'Apple', 'de' => 'Apple'], 'aliases' => ['apple', 'iphone', 'ipad', 'mac']],
		['id' => 'tech.ai', 'parentId' => 'technology', 'labels' => ['en' => 'Artificial intelligence', 'de' => 'Künstliche Intelligenz'], 'aliases' => ['artificial intelligence', 'machine learning', 'generative ai', 'künstliche intelligenz', 'maschinelles lernen', 'generative ki']],
		['id' => 'tech.mobile', 'parentId' => 'technology', 'labels' => ['en' => 'Mobile technology', 'de' => 'Mobiltechnik'], 'aliases' => ['android', 'mobile phones', 'smartphones', 'mobiltelefone', 'smartphones']],
		['id' => 'tech.security', 'parentId' => 'technology', 'labels' => ['en' => 'Security', 'de' => 'IT-Sicherheit'], 'aliases' => ['cybersecurity', 'infosec', 'computer security', 'it security', 'cybersicherheit', 'computersicherheit']],
		['id' => 'tech.hardware', 'parentId' => 'technology', 'labels' => ['en' => 'Hardware', 'de' => 'Hardware'], 'aliases' => ['computer hardware', 'chips', 'semiconductors', 'computer hardware', 'halbleiter']],
		['id' => 'tech.web', 'parentId' => 'technology', 'labels' => ['en' => 'Web and internet', 'de' => 'Web und Internet'], 'aliases' => ['web technology', 'internet', 'browsers', 'webstandards', 'web standards']],
		['id' => 'tech.privacy', 'parentId' => 'technology', 'labels' => ['en' => 'Privacy', 'de' => 'Datenschutz'], 'aliases' => ['online privacy', 'digital privacy', 'data protection', 'online-privatsphäre', 'datenschutz']],
		['id' => 'oss', 'parentId' => null, 'labels' => ['en' => 'Open source', 'de' => 'Open Source'], 'aliases' => ['open source', 'open-source', 'opensource']],
		['id' => 'oss.nextcloud', 'parentId' => 'oss', 'labels' => ['en' => 'Nextcloud', 'de' => 'Nextcloud'], 'aliases' => ['nextcloud']],
		['id' => 'oss.linux', 'parentId' => 'oss', 'labels' => ['en' => 'Linux', 'de' => 'Linux'], 'aliases' => ['linux', 'gnu linux', 'gnu/linux']],
		['id' => 'oss.selfhosted', 'parentId' => 'oss', 'labels' => ['en' => 'Self-hosting', 'de' => 'Self-Hosting'], 'aliases' => ['self-hosting', 'self hosted', 'homelab', 'selfhosting']],
		['id' => 'oss.community', 'parentId' => 'oss', 'labels' => ['en' => 'Open-source communities', 'de' => 'Open-Source-Communitys'], 'aliases' => ['open source community', 'free software', 'freie software']],
		['id' => 'software', 'parentId' => null, 'labels' => ['en' => 'Software development', 'de' => 'Softwareentwicklung'], 'aliases' => ['software development', 'software engineering', 'programming', 'coding', 'softwareentwicklung', 'programmierung']],
		['id' => 'software.web', 'parentId' => 'software', 'labels' => ['en' => 'Web development', 'de' => 'Webentwicklung'], 'aliases' => ['web development', 'webdev', 'webentwicklung']],
		['id' => 'software.frontend', 'parentId' => 'software', 'labels' => ['en' => 'Frontend development', 'de' => 'Frontend-Entwicklung'], 'aliases' => ['frontend development', 'frontend', 'javascript', 'typescript', 'vue.js', 'react']],
		['id' => 'software.backend', 'parentId' => 'software', 'labels' => ['en' => 'Backend development', 'de' => 'Backend-Entwicklung'], 'aliases' => ['backend development', 'backend', 'apis', 'php', 'python', 'golang']],
		['id' => 'software.devops', 'parentId' => 'software', 'labels' => ['en' => 'DevOps and cloud', 'de' => 'DevOps und Cloud'], 'aliases' => ['devops', 'cloud computing', 'containers', 'kubernetes', 'cloud computing']],
		['id' => 'software.data', 'parentId' => 'software', 'labels' => ['en' => 'Data and databases', 'de' => 'Daten und Datenbanken'], 'aliases' => ['data engineering', 'databases', 'database', 'sql', 'datenbanken']],
		['id' => 'gaming', 'parentId' => null, 'labels' => ['en' => 'Gaming', 'de' => 'Gaming'], 'aliases' => ['gaming', 'video games', 'videogames', 'videospiele', 'computerspiele']],
		['id' => 'gaming.indie', 'parentId' => 'gaming', 'labels' => ['en' => 'Indie games', 'de' => 'Indie-Spiele'], 'aliases' => ['indie games', 'indie gaming', 'indie spiele']],
		['id' => 'gaming.tabletop', 'parentId' => 'gaming', 'labels' => ['en' => 'Tabletop games', 'de' => 'Brett- und Rollenspiele'], 'aliases' => ['board games', 'tabletop games', 'roleplaying games', 'brettspiele', 'rollenspiele']],
		['id' => 'gaming.esports', 'parentId' => 'gaming', 'labels' => ['en' => 'Esports', 'de' => 'E-Sport'], 'aliases' => ['esports', 'e-sports', 'competitive gaming', 'e-sport']],
		['id' => 'business', 'parentId' => null, 'labels' => ['en' => 'Business and economics', 'de' => 'Wirtschaft'], 'aliases' => ['business', 'economics', 'economy', 'startups', 'unternehmen', 'wirtschaft', 'ökonomie']],
		['id' => 'business.startups', 'parentId' => 'business', 'labels' => ['en' => 'Startups', 'de' => 'Start-ups'], 'aliases' => ['startups', 'startup', 'start-ups', 'gründung']],
		['id' => 'business.work', 'parentId' => 'business', 'labels' => ['en' => 'Work and careers', 'de' => 'Arbeit und Karriere'], 'aliases' => ['careers', 'work', 'workplace', 'karriere', 'arbeitsleben']],
		['id' => 'business.finance', 'parentId' => 'business', 'labels' => ['en' => 'Personal finance', 'de' => 'Persönliche Finanzen'], 'aliases' => ['personal finance', 'saving money', 'budgeting', 'geldanlage', 'finanzen']],
		['id' => 'environment', 'parentId' => null, 'labels' => ['en' => 'Environment and climate', 'de' => 'Umwelt und Klima'], 'aliases' => ['environment', 'climate change', 'climate', 'umwelt', 'klimawandel', 'klima']],
		['id' => 'environment.nature', 'parentId' => 'environment', 'labels' => ['en' => 'Nature and wildlife', 'de' => 'Natur und Tierwelt'], 'aliases' => ['nature', 'wildlife', 'biodiversity', 'natur', 'wildtiere', 'biodiversität']],
		['id' => 'environment.energy', 'parentId' => 'environment', 'labels' => ['en' => 'Energy', 'de' => 'Energie'], 'aliases' => ['renewable energy', 'solar power', 'wind power', 'erneuerbare energien', 'solarenergie']],
		['id' => 'environment.sustainability', 'parentId' => 'environment', 'labels' => ['en' => 'Sustainability', 'de' => 'Nachhaltigkeit'], 'aliases' => ['sustainability', 'sustainable living', 'nachhaltigkeit']],
		['id' => 'culture', 'parentId' => null, 'labels' => ['en' => 'Culture and arts', 'de' => 'Kultur und Kunst'], 'aliases' => ['culture', 'arts', 'art history', 'kultur', 'kunst']],
		['id' => 'culture.books', 'parentId' => 'culture', 'labels' => ['en' => 'Books and writing', 'de' => 'Bücher und Schreiben'], 'aliases' => ['books', 'reading', 'writing', 'literature', 'bücher', 'literatur', 'lesen']],
		['id' => 'culture.film', 'parentId' => 'culture', 'labels' => ['en' => 'Film and television', 'de' => 'Film und Fernsehen'], 'aliases' => ['film', 'movies', 'cinema', 'television', 'kino', 'fernsehen']],
		['id' => 'culture.music', 'parentId' => 'culture', 'labels' => ['en' => 'Music', 'de' => 'Musik'], 'aliases' => ['music', 'musicians', 'musik', 'musiker']],
		['id' => 'culture.design', 'parentId' => 'culture', 'labels' => ['en' => 'Design and illustration', 'de' => 'Design und Illustration'], 'aliases' => ['design', 'graphic design', 'illustration', 'illustration', 'grafikdesign']],
		['id' => 'culture.museums', 'parentId' => 'culture', 'labels' => ['en' => 'Museums and heritage', 'de' => 'Museen und Kulturerbe'], 'aliases' => ['museums', 'history of art', 'cultural heritage', 'museen', 'kulturerbe']],
		['id' => 'sports', 'parentId' => null, 'labels' => ['en' => 'Sports', 'de' => 'Sport'], 'aliases' => ['sports', 'sport', 'football', 'soccer', 'basketball', 'tennis', 'fußball']],
		['id' => 'sports.running', 'parentId' => 'sports', 'labels' => ['en' => 'Running', 'de' => 'Laufen'], 'aliases' => ['running', 'marathon', 'trail running', 'laufen', 'marathon']],
		['id' => 'sports.cycling', 'parentId' => 'sports', 'labels' => ['en' => 'Cycling', 'de' => 'Radfahren'], 'aliases' => ['cycling', 'bicycles', 'bikepacking', 'radfahren', 'fahrrad']],
		['id' => 'sports.football', 'parentId' => 'sports', 'labels' => ['en' => 'Football', 'de' => 'Fußball'], 'aliases' => ['football', 'soccer', 'fußball']],
		['id' => 'sports.outdoors', 'parentId' => 'sports', 'labels' => ['en' => 'Outdoor sports', 'de' => 'Outdoor-Sport'], 'aliases' => ['hiking', 'climbing', 'outdoor sports', 'wandern', 'klettern']],
		['id' => 'photography', 'parentId' => null, 'labels' => ['en' => 'Photography', 'de' => 'Fotografie'], 'aliases' => ['photography', 'photographer', 'fotografie', 'fotograf']],
		['id' => 'photography.nature', 'parentId' => 'photography', 'labels' => ['en' => 'Nature photography', 'de' => 'Naturfotografie'], 'aliases' => ['nature photography', 'wildlife photography', 'naturfotografie']],
		['id' => 'photography.street', 'parentId' => 'photography', 'labels' => ['en' => 'Street photography', 'de' => 'Streetfotografie'], 'aliases' => ['street photography', 'streetphoto', 'streetfotografie']],
		['id' => 'photography.film', 'parentId' => 'photography', 'labels' => ['en' => 'Film photography', 'de' => 'Analoge Fotografie'], 'aliases' => ['film photography', 'analog photography', 'analoge fotografie']],
		['id' => 'education', 'parentId' => null, 'labels' => ['en' => 'Education', 'de' => 'Bildung'], 'aliases' => ['education', 'teaching', 'learning', 'bildung', 'lehre', 'lernen']],
		['id' => 'education.languages', 'parentId' => 'education', 'labels' => ['en' => 'Languages', 'de' => 'Sprachen'], 'aliases' => ['languages', 'language learning', 'linguistics', 'sprachen', 'sprachlernen']],
		['id' => 'education.teaching', 'parentId' => 'education', 'labels' => ['en' => 'Teaching', 'de' => 'Pädagogik'], 'aliases' => ['teaching', 'teachers', 'pedagogy', 'unterricht', 'pädagogik']],
		['id' => 'education.history', 'parentId' => 'education', 'labels' => ['en' => 'History', 'de' => 'Geschichte'], 'aliases' => ['history', 'historical', 'geschichte', 'historisch']],
		['id' => 'politics', 'parentId' => null, 'labels' => ['en' => 'Politics', 'de' => 'Politik'], 'aliases' => ['politics', 'politik']],
		['id' => 'politics.international', 'parentId' => 'politics', 'labels' => ['en' => 'International politics', 'de' => 'Internationale Politik'], 'aliases' => ['international politics', 'internationale politik']],
		['id' => 'science', 'parentId' => null, 'labels' => ['en' => 'Science', 'de' => 'Wissenschaft'], 'aliases' => ['science', 'wissenschaft']],
		['id' => 'science.physics', 'parentId' => 'science', 'labels' => ['en' => 'Physics', 'de' => 'Physik'], 'aliases' => ['physics', 'physik']],
		['id' => 'science.biology', 'parentId' => 'science', 'labels' => ['en' => 'Biology and life sciences', 'de' => 'Biologie und Lebenswissenschaften'], 'aliases' => ['biology', 'life sciences', 'biologie', 'lebenswissenschaften']],
		['id' => 'science.space', 'parentId' => 'science', 'labels' => ['en' => 'Space and astronomy', 'de' => 'Weltraum und Astronomie'], 'aliases' => ['space', 'astronomy', 'astrophysics', 'astronomie', 'weltraum']],
		['id' => 'science.math', 'parentId' => 'science', 'labels' => ['en' => 'Mathematics', 'de' => 'Mathematik'], 'aliases' => ['mathematics', 'math', 'mathematik']],
		['id' => 'science.research', 'parentId' => 'science', 'labels' => ['en' => 'Research', 'de' => 'Forschung'], 'aliases' => ['research', 'scientific research', 'forschung']],
		['id' => 'food', 'parentId' => null, 'labels' => ['en' => 'Food and cooking', 'de' => 'Essen und Kochen'], 'aliases' => ['food', 'cooking', 'recipes', 'essen', 'kochen', 'rezepte']],
		['id' => 'food.baking', 'parentId' => 'food', 'labels' => ['en' => 'Baking', 'de' => 'Backen'], 'aliases' => ['baking', 'bread', 'backen', 'brot']],
		['id' => 'food.plantbased', 'parentId' => 'food', 'labels' => ['en' => 'Plant-based food', 'de' => 'Pflanzliche Ernährung'], 'aliases' => ['vegan food', 'plant based', 'vegetarian cooking', 'vegane ernährung', 'vegetarisch']],
		['id' => 'food.restaurants', 'parentId' => 'food', 'labels' => ['en' => 'Restaurants and cafés', 'de' => 'Restaurants und Cafés'], 'aliases' => ['restaurants', 'cafes', 'coffee shops', 'restaurants', 'cafés']],
		['id' => 'travel', 'parentId' => null, 'labels' => ['en' => 'Travel', 'de' => 'Reisen'], 'aliases' => ['travel', 'travelling', 'tourism', 'reisen', 'tourismus']],
		['id' => 'travel.europe', 'parentId' => 'travel', 'labels' => ['en' => 'European travel', 'de' => 'Reisen in Europa'], 'aliases' => ['europe travel', 'european travel', 'europa reisen']],
		['id' => 'travel.outdoors', 'parentId' => 'travel', 'labels' => ['en' => 'Nature trips and camping', 'de' => 'Naturreisen und Camping'], 'aliases' => ['camping', 'backpacking', 'nature travel', 'campen', 'naturreisen']],
		['id' => 'home', 'parentId' => null, 'labels' => ['en' => 'Home and everyday life', 'de' => 'Wohnen und Alltag'], 'aliases' => ['home', 'everyday life', 'interior design', 'wohnen', 'alltag', 'einrichtung']],
		['id' => 'home.gardening', 'parentId' => 'home', 'labels' => ['en' => 'Gardening', 'de' => 'Garten'], 'aliases' => ['gardening', 'garden', 'plants', 'garten', 'pflanzen']],
		['id' => 'home.diy', 'parentId' => 'home', 'labels' => ['en' => 'DIY and crafts', 'de' => 'Selbermachen und Handwerk'], 'aliases' => ['diy', 'crafts', 'woodworking', 'handwerk', 'basteln']],
		['id' => 'transport', 'parentId' => null, 'labels' => ['en' => 'Transport and cities', 'de' => 'Verkehr und Städte'], 'aliases' => ['public transport', 'urban planning', 'cities', 'öffentlicher verkehr', 'stadtplanung', 'städte']],
		['id' => 'transport.rail', 'parentId' => 'transport', 'labels' => ['en' => 'Railways', 'de' => 'Bahnverkehr'], 'aliases' => ['railways', 'trains', 'rail transport', 'bahn', 'züge', 'eisenbahn']],
		['id' => 'transport.mobility', 'parentId' => 'transport', 'labels' => ['en' => 'Mobility and accessibility', 'de' => 'Mobilität und Barrierefreiheit'], 'aliases' => ['mobility', 'accessibility', 'barrier-free', 'mobilität', 'barrierefreiheit']],
		['id' => 'community', 'parentId' => null, 'labels' => ['en' => 'Community and society', 'de' => 'Gemeinschaft und Gesellschaft'], 'aliases' => ['community', 'society', 'communities', 'gesellschaft', 'gemeinschaft']],
		['id' => 'community.events', 'parentId' => 'community', 'labels' => ['en' => 'Events and meetups', 'de' => 'Veranstaltungen und Treffen'], 'aliases' => ['events', 'meetups', 'conferences', 'veranstaltungen', 'treffen', 'konferenzen']],
		['id' => 'community.accessibility', 'parentId' => 'community', 'labels' => ['en' => 'Accessibility and inclusion', 'de' => 'Inklusion und Barrierefreiheit'], 'aliases' => ['inclusion', 'disability rights', 'accessibility', 'inklusion', 'behindertenrechte']],
		['id' => 'media', 'parentId' => null, 'labels' => ['en' => 'Media and communication', 'de' => 'Medien und Kommunikation'], 'aliases' => ['media', 'journalism', 'mass media', 'medien', 'journalismus']],
		['id' => 'media.podcasts', 'parentId' => 'media', 'labels' => ['en' => 'Podcasts', 'de' => 'Podcasts'], 'aliases' => ['podcasts', 'podcasting']],
		['id' => 'media.journalism', 'parentId' => 'media', 'labels' => ['en' => 'Journalism', 'de' => 'Journalismus'], 'aliases' => ['journalism', 'reporting', 'journalismus', 'berichterstattung']],
		['id' => 'media.animation', 'parentId' => 'media', 'labels' => ['en' => 'Animation and comics', 'de' => 'Animation und Comics'], 'aliases' => ['animation', 'comics', 'manga', 'cartoons']],
		['id' => 'media.social', 'parentId' => 'media', 'labels' => ['en' => 'Social media', 'de' => 'Soziale Medien'], 'aliases' => ['social media', 'online communities', 'soziale medien']],
	];

	/** @return list<array{id: string, parentId: ?string, labels: array{en: string, de: string}, aliases: list<string>, policyTags: list<string>, languageHints: list<string>, version: int, enabled: bool}> */
	public function all(): array {
		return array_map(static fn (array $topic): array => $topic + [
			'policyTags' => str_starts_with($topic['id'], 'politics') ? ['sensitive-topic'] : [],
			'languageHints' => ['en', 'de'],
			'version' => 1,
			'enabled' => true,
		], self::TOPICS);
	}

	public function has(string $topicId): bool {
		foreach (self::TOPICS as $topic) {
			if ($topic['id'] === $topicId) {
				return true;
			}
		}

		return false;
	}

	/** @return array{id: string, parentId: ?string, labels: array{en: string, de: string}, aliases: list<string>, policyTags: list<string>, languageHints: list<string>, version: int, enabled: bool}|null */
	public function find(string $topicId): ?array {
		foreach ($this->all() as $topic) {
			if ($topic['id'] === $topicId) {
				return $topic;
			}
		}
		return null;
	}

	public function parentOf(string $topicId): ?string {
		foreach (self::TOPICS as $topic) {
			if ($topic['id'] === $topicId) {
				return $topic['parentId'];
			}
		}

		return null;
	}
}
