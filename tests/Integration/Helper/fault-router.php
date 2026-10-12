<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * The router of `FaultServer`: one fake inbox per host name, misbehaving the
 * way the first label of the name says (`slow-…`, `error-…`, `busy-…`, …).
 * Every request is written to `hits.log` in FAULT_DIR, with what it was
 * answered (0 for an answer the sender has given up on by then), before it
 * is answered. A host whose `<label>.state` file says
 * `up` answers 202 whatever its name says, which is how a test brings a
 * failing peer back.
 */

$dir = (string)getenv('FAULT_DIR');
$host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
$label = explode('.', $host)[0];
$behaviour = explode('-', $label)[0];
$body = (string)file_get_contents('php://input');
$decoded = json_decode($body, true);

$state = is_file($dir . '/' . $label . '.state') ? trim((string)file_get_contents($dir . '/' . $label . '.state')) : '';
if ($state === 'up') {
	$behaviour = 'ok';
}

// what the sender is answered; a peer that hangs past the sender's timeout
// answers nothing the sender ever reads
$answer = match ($behaviour) {
	'slow' => 0,
	'ok' => 202,
	'error' => 500,
	'busy', 'week' => 429,
	'unavailable', 'flaky', 'dated' => 503,
	'reset' => 200,
	'loop' => 302,
	default => 404,
};

file_put_contents($dir . '/hits.log', json_encode([
	'host' => $host,
	'method' => $_SERVER['REQUEST_METHOD'] ?? '',
	'id' => is_array($decoded) ? (string)($decoded['id'] ?? '') : '',
	'answer' => $answer,
	'at' => microtime(true),
]) . "\n", FILE_APPEND | LOCK_EX);

switch ($behaviour) {
	case 'slow':
		// longer than the delivery's timeout: the sender gives up first
		sleep(5);
		http_response_code(202);
		break;

	case 'busy':
	case 'unavailable':
		header('Retry-After: 120');
		http_response_code($answer);
		break;

	case 'dated':
		// the HTTP-date form, five minutes from now
		header('Retry-After: ' . gmdate('D, d M Y H:i:s \\G\\M\\T', time() + 300));
		http_response_code($answer);
		break;

	case 'week':
		header('Retry-After: 604800');
		http_response_code($answer);
		break;

	case 'reset':
		// promises more than it sends and hangs up: the body is cut off
		header('Content-Type: application/activity+json');
		header('Content-Length: 4096');
		http_response_code(200);
		echo '{"cut":"';
		flush();
		exit;

	case 'loop':
		header('Location: http://' . $host . ':' . (int)($_SERVER['SERVER_PORT'] ?? 0) . '/inbox');
		http_response_code(302);
		break;

	default:
		http_response_code($answer);
}
