# Slack Welcome Bot (WordPress-Plugin)

Sendet jedem neuen Slack-Workspace-Mitglied automatisch eine Direktnachricht mit den Team-Regeln. Der Regeltext wird direkt aus einer WordPress-Seite gelesen – ändert ihr die Seite, ändert sich automatisch auch der Text der nächsten Willkommens-DM.

## Warum als Plugin?

- Läuft direkt in eurer bestehenden WordPress-Installation – keine Subdomain, kein separater Server, kein manuelles Datei-Upload-Handling nötig.
- Der Regeltext wird direkt aus der WordPress-Datenbank gelesen (kein Netzwerk-Request nötig, keine Caching-Logik erforderlich).
- Zugangsdaten werden sicher über die WordPress-Optionen-Tabelle gespeichert, nicht in einer Datei im Web-Root.

## Installation

1. Den Ordner `slack-welcome-bot` als ZIP packen (falls noch nicht geschehen).
2. WordPress-Adminbereich → **Plugins → Installieren → Plugin hochladen** → ZIP auswählen → **Jetzt installieren** → **Aktivieren**.

   *Alternativ per FTP:* Ordner `slack-welcome-bot` nach `wp-content/plugins/` hochladen und im Adminbereich unter **Plugins** aktivieren.

## Einrichtung

### 1. Regel-Seite anlegen

Erstellt in WordPress eine normale Seite (z. B. **Seiten → Erstellen**) mit dem gewünschten Regeltext, z. B. "Slack-Regeln". Diese Seite muss **veröffentlicht oder privat** sein (kein Entwurf).

Unterstützt werden: Fettung, Kursivschrift, Listen, Links, Überschriften – wird automatisch ins passende Slack-Format umgewandelt.

**Nicht-öffentliche Seiten:** Sowohl Seiten mit Sichtbarkeit "Privat" als auch passwortgeschützte Seiten können als Regel-Seite ausgewählt werden – die Willkommens-DM liest den Inhalt direkt aus der WordPress-Datenbank, unabhängig vom Passwort oder der Sichtbarkeit. Ist eine solche Seite ausgewählt, erscheint auf der Einstellungsseite ein entsprechender Hinweis.

### 2. Slack App erstellen

1. https://api.slack.com/apps → **Create New App** → **From scratch**.
2. **OAuth & Permissions → Bot Token Scopes** hinzufügen:
   - `chat:write`
   - `im:write`
   - `users:read`
3. App im Workspace installieren → **Bot User OAuth Token** (`xoxb-...`) kopieren.
4. **Basic Information → App Credentials → Signing Secret** kopieren.

### 3. Plugin konfigurieren

WordPress-Adminbereich → **Einstellungen → Slack Welcome Bot**:

- **Bot User OAuth Token** eintragen
- **Signing Secret** eintragen
- Unter **Seite mit den Regeln** die im Schritt 1 erstellte Seite auswählen
- Speichern

Auf derselben Seite steht ganz oben die **Webhook-URL** (z. B. `https://eure-domain.tld/wp-json/slack-welcome-bot/v1/events`) – diese als Nächstes benötigt.

### 4. Event Subscription in Slack einrichten

1. In der Slack-App: **Event Subscriptions** → aktivieren.
2. **Request URL**: die Webhook-URL aus Schritt 3 einfügen.
   - Slack schickt sofort einen Verifizierungs-Request; das Plugin beantwortet ihn automatisch, die URL sollte direkt als "Verified" markiert werden.
   - Falls das fehlschlägt: prüfen, ob die WordPress REST API grundsätzlich erreichbar ist (z. B. `https://eure-domain.tld/wp-json/` im Browser aufrufen – sollte JSON liefern) und ob Signing Secret korrekt gespeichert wurde.
3. Unter **Subscribe to bot events** hinzufügen: `team_join`.
4. Speichern. Falls sich Scopes geändert haben, App im Workspace neu installieren.

Fertig – ab jetzt bekommt jedes neue Mitglied automatisch die DM mit dem aktuellen Inhalt der gewählten Seite.

## Troubleshooting

- **Request URL wird nicht verifiziert**: `https://eure-domain.tld/wp-json/` testen. Manche Security-Plugins (z. B. Wordfence, iThemes Security) blockieren die REST API teilweise – ggf. eine Ausnahme für `slack-welcome-bot/v1/*` eintragen.
- **Keine DM kommt an**: PHP-Error-Log prüfen (Fehler werden über `error_log()` geschrieben, meist einsehbar im Hosting-Kontrollpanel oder unter `wp-content/debug.log` bei aktiviertem `WP_DEBUG_LOG`). Bot-Token-Scopes und Installation im Workspace prüfen.
- **Regeltext fehlt/veraltet**: Sicherstellen, dass die ausgewählte Seite veröffentlicht ist und in den Plugin-Einstellungen die richtige Seite ausgewählt wurde.
- **Doppelte DMs**: Sollte durch die eingebaute Event-Deduplizierung (WordPress Transients) nicht vorkommen; falls doch, PHP-Error-Log auf Auffälligkeiten prüfen.

## Kompatibilität

Getestet und funktionsfähig mit **WordPress 7.1 "Mary Lou"** (Stand August 2026) und PHP 7.4–8.x. Das Plugin nutzt ausschließlich stabile, seit Jahren unveränderte WordPress-APIs (Settings API, REST API, Transients, HTTP API) und ist von der 7.1-Änderung an der internen Hook-Callback-ID-Erzeugung (die z. B. bei WP Rocket kurzzeitig zu Fehlern führte) nicht betroffen, da keine manuelle Hook-Introspektion stattfindet.

## Neue Version veröffentlichen

Das Plugin prüft automatisch gegen die GitHub Releases dieses Repos und zeigt im WP-Backend unter **Plugins** einen normalen "Update verfügbar"-Hinweis inkl. **Jetzt aktualisieren**-Button an. Versionierung und Release sind über zwei GitHub-Actions-Workflows automatisiert:

1. Commits nach [Conventional Commits](https://www.conventionalcommits.org/) schreiben (`fix:`, `feat:`, `feat!:`/`BREAKING CHANGE:` für major, …) – daraus leitet semantic-release die nächste Version ab.
2. Im GitHub-Repo unter **Actions → Release → Run workflow** manuell anstoßen (`.github/workflows/release.yml`).
   - Ermittelt per `semantic-release` die nächste Version aus den Commits seit dem letzten Tag.
   - Schreibt die Version in den Plugin-Header + `SWB_PLUGIN_VERSION` zurück, aktualisiert `CHANGELOG.md`.
   - Committet, taggt (`vX.Y.Z`) und erstellt das GitHub Release automatisch.
3. Der Tag-Push löst `.github/workflows/release-asset.yml` aus, das ein ZIP des Plugin-Ordners baut und als Release-Asset anhängt – das ist die Datei, die der Update-Checker in WordPress herunterlädt.

Kein Conventional-Commit seit dem letzten Tag → semantic-release bricht ohne neue Version ab (kein Release).

## Tests

- `composer install && composer test` – PHPUnit-Tests für HTML→Slack-Konvertierung und Webhook-Signaturprüfung (`tests/`).
- `npm test` – Tests für das Release-Tooling (`bin/`).

Beide laufen automatisch per GitHub Actions bei jedem Push/PR (`.github/workflows/tests.yml`).

## Sicherheit

- Jede eingehende Anfrage wird über die Slack-Signatur verifiziert – Anfragen ohne gültige Signatur werden mit HTTP 401 abgelehnt.
- Bot-Token und Signing Secret werden in der WordPress-Optionstabelle gespeichert, nicht im Klartext einsehbar über das Frontend.
- Retries von Slack (bei Timeouts) und doppelte Events werden erkannt und ignoriert.
