# Federation & Discovery — Tests und `occ` Befehle

Zweck: Sammlung aller relevanten `occ`- und Test‑Kommandos, erwartete Ergebnisse und nötige Konfigurationen, damit ActivityPub/Federation und WebFinger‑Discovery zuverlässig funktionieren.

Voraussetzungen
- Nextcloud muss von außen unter einer öffentlichen HTTPS‑URL erreichbar sein.
- Gültiges TLS‑Zertifikat für Domain.
- `cloud_url` und `social_url` in der Social‑App korrekt gesetzt (siehe unten).
- Webserver muss `.well-known` Redirects korrekt weiterleiten, falls Nextcloud nicht in Domain‑Root läuft.

Wichtige Konfiguration (setzen / prüfen)
- Setze `cloud_url` (z. B. `https://cloud.example.com/index.php`) und `social_url` (z. z. `https://cloud.example.com/apps/social/`):

```bash
sudo -u www-data php /var/www/nextcloud/occ config:app:set social cloud_url --value="https://cloud.example.com"
sudo -u www-data php /var/www/nextcloud/occ config:app:set social social_url --value="https://cloud.example.com/apps/social/"
```

- Prüfe aktuelle Werte:

```bash
sudo -u www-data php /var/www/nextcloud/occ config:app:get social cloud_url
sudo -u www-data php /var/www/nextcloud/occ config:app:get social social_url
```

`.well-known` Webserver‑Redirects (Beispiel Nginx)

```
location = /.well-known/webfinger {
    return 301 https://cloud.example.com/.well-known/webfinger?$query_string;
}
location = /.well-known/host-meta {
    return 301 https://cloud.example.com/.well-known/host-meta?$query_string;
}
location = /.well-known/nodeinfo {
    return 301 https://cloud.example.com/.well-known/nodeinfo?$query_string;
}
```

Wichtige `occ` Kommandos (Social App)
- Cache neu aufbauen:

```bash
sudo -u www-data php /var/www/nextcloud/occ social:cache:refresh
```
- Testpost / DM erzeugen (CLI):

```bash
sudo -u www-data php /var/www/nextcloud/occ social:note:create --to remoteuser@remote.instance --type direct "Hallo (Test DM)"
```
- Queue verarbeiten (synchron/background):

```bash
sudo -u www-data php /var/www/nextcloud/occ social:queue:process
sudo -u www-data php /var/www/nextcloud/occ social:queue:status --token <token>
```
- Reset (destruktiv, setzt ActivityPub Basis neu):

```bash
sudo -u www-data php /var/www/nextcloud/occ social:reset
```

API / Kompatibilitäts‑endpoints (zum Prüfen von Mastodon‑Ähnlichkeit)
- Instance/Verify:

```bash
curl -i https://cloud.example.com/apps/social/api/v1/instance/
curl -i https://cloud.example.com/apps/social/api/v1/accounts/verify_credentials
```

Smoke‑Tests per `curl` (Discovery / Actor / ActivityPub)
- WebFinger prüfen:

```bash
curl -i "https://example.com/.well-known/webfinger?resource=acct:alice@example.com"
```
Erwartung: HTTP 200 JSON mit "links" → `rel: self` `type: application/activity+json` auf Actor URL.

- Host‑meta prüfen:

```bash
curl -i "https://example.com/.well-known/host-meta"
```
Erwartung: XRD/XML mit `lrdd` Link auf `/.well-known/webfinger?resource={uri}`.

- NodeInfo prüfen:

```bash
curl -i "https://example.com/.well-known/nodeinfo"
curl -i "https://example.com/.well-known/nodeinfo/2.0"
```
Erwartung: Link/JSON erreichbar, gibt Server‑Metadaten zurück.

- Actor (ActivityPub) prüfen:

```bash
curl -i -H "Accept: application/activity+json" "https://cloud.example.com/apps/social/users/alice"
```
Erwartung: `application/activity+json` Actor‑JSON mit Feldern: `id`, `inbox`, `outbox`, `sharedInbox` (optional), `preferredUsername`.

- Inbox endpoints (GET/POST):

GET (Inbox collection):
```bash
curl -i -H "Accept: application/activity+json" "https://cloud.example.com/apps/social/@alice/inbox"
```
POST (Receive an incoming activity) — zum Testen normalerweise von Remote‑Servern signiert; für Rauchtest nur prüfen, dass Endpoint erreichbar ist:

```bash
curl -i -X POST -d '{}' "https://cloud.example.com/apps/social/inbox"
```
Erwartung: HTTP 200 (auch bei Signaturproblemen sollten Fehler im Log erscheinen, nicht immer 500)

Fehlerdiagnose / Logs
- Nextcloud Log: `data/nextcloud.log` oder Admin → Logging. Suche nach `social` / `ActivityPub` Fehlermeldungen.
- Queue Tokens prüfen: `occ social:queue:status --token <token>`
- Cron / Background: Stelle sicher, dass der Background‑Worker (cron oder systemd) läuft, oder benutze `social:queue:process` manuell.

DM‑Test (End‑to‑End Anleitung)
1. Stelle sicher, dass dein Konto auf der Instanz `alice@example.com` existiert.
2. Erzeuge per `occ` oder UI eine Direct Nachricht an `remoteuser@remote.instance`:
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ social:note:create --to remoteuser@remote.instance --type direct "E2E DM Test"
   ```
3. Überprüfe `social:queue:status` auf Delivery Token und `data/nextcloud.log` auf Fehler.
4. Auf Remote‑Instanz prüfen, ob Nachricht ankam. Wenn nicht, prüfe Server‑TLS, DNS und Signaturen.

Wichtige Implementierungs‑Hinweise (aus Codebasis)
- WebFinger/host-meta sind in `lib/WellKnown/WebfingerHandler.php` implementiert.
- ActivityPub Endpoints: `lib/Controller/ActivityPubController.php` (sharedInbox, inbox, outbox, followers, following, actor).
- ConfigService (`lib/Service/ConfigService.php`) verlangt feste `cloud_url`/`social_url` und erzeugt IDs basierend darauf.
- Queue/Import Verarbeitung: `ImportService.php` → `parseIncomingRequest()` → Interfaces/Services pro Activity/Object Type.

Sicherheits‑Hinweise
- Nutze gültige TLS‑Zertifikate.
- Prüfe Allow/Block‑Listen in Konfiguration (`FediverseService`) wenn externe Server blockiert werden.
- `social:reset` löscht Daten; nur Admins und mit Vorsicht verwenden.

Weiteres
- Wenn du willst, erstelle ich ein kleines Prüfskript (`bash`), das die `curl`‑Checks automatisiert und einen kurzen Report erzeugt. Sag Bescheid, ob ich das hinzufügen soll.

---
Datei erstellt: Diese Sammlung findest du hier: docs/FEDERATION_TESTS.md
