<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Mastodon entity schemas

One JSON file per entity of the Mastodon client API, in `mastodon/`, checked by
`SchemaValidator` — a small in-house validator for the part of JSON Schema these
files use (types, `required`, `enum`, `format`, `$ref`, `allOf`/`anyOf`, and
OpenAPI's `nullable`). It is deliberately not a general JSON Schema
implementation: what it adds over the key-set pins in
`tests/Federation/ApiContractTest.php` is the *types* — a string id, a
`2026-10-09T12:34:56.000Z` date, an integer count, a `null` that is sent rather
than left out — which is what a client written in Swift or Kotlin decodes into
and fails on.

## Where the shapes come from

The schemas were written by hand from the entity pages of the Mastodon
documentation (docs.joinmastodon.org/entities/…) as they read for **Mastodon
4.5**, from what the author knows of them; they were not generated from, or
copied out of, any published OpenAPI document. Each file's `description` names
the page it follows. Where the documentation and Mastodon's own serializers
disagree, the schema follows what Mastodon actually sends and says so in its
`description` (`Announcement.published`, `Account::Field.verified_at`).

What the schemas decide:

- **required** is what the documentation lists without "optional": a client
  may declare those keys non-optional, so they must be present — as `null`
  when the type allows it. Keys documented as optional, or added in a later
  version than most clients target, are typed when present and not required.
- The **Status** schema is the authenticated view: `favourited`,
  `reblogged`, `muted`, `bookmarked` and `pinned` are then always present.
- **Formats**: `datetime` is Mastodon's `YYYY-MM-DDTHH:MM:SS.sssZ` exactly;
  `date` is a calendar date (`Account.last_status_at`,
  `FeaturedTag.last_status_at`); `id` is a string of digits; `url` an
  absolute http(s) URL.
- Keys this app adds beside Mastodon's are allowed and not typed here; the
  key sets themselves are pinned in `ApiContractTest`.

## Deliberate deviations

A place where the app departs from Mastodon on purpose is written into the
schema with the reason, so a change to it is a decision rather than a
surprise:

- `Instance.configuration.urls` and `V1Instance.urls` are empty objects:
  there is no streaming server, and an empty `urls` makes a client poll at
  once instead of after a timeout (`docs/Mastodon-Compatibility.md`).
- `Notification.type` accepts this app's own namespaced types
  (`bluesky:verified`, `story:react`, …) and Pixelfed's `tagged`, beside
  Mastodon's.

## Using them

    $validator = new SchemaValidator();
    $errors = $validator->validate(SchemaValidator::wire($payload), 'Status');

`wire()` puts a payload through `json_encode()` and back with objects kept as
objects, which is how a client sees it. The integration tests in
`tests/Integration/Api/` call the real controllers and validate their answers;
`tests/Api/EntitySchemaTest.php` covers the entities that cannot be produced
there without a network (a link preview card, a translation).
