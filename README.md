# TimeTune – Konzert-Hitster

Web-App, mit der das Konzertpublikum per Smartphone eine Live-Variante von Hitster spielt: Der Musikverein spielt ein Stück, jeder Besucher ordnet es in seine eigene Zeitleiste ein. Grundlage sind die [Spezifikation](docs/timetune-spezifikation.md) (Stand 25.10.2026, unverändert; Abweichungen siehe [unten](#abweichungen-von-der-spezifikation-und-offene-punkte)) und das Design „Konzert-Hitster“ aus Claude Design ([Projekt](https://claude.ai/design/p/5b8682aa-e604-4c26-92bd-f3452cea7aad?file=Konzert-Hitster.dc.html)).

| Ansicht         | URL                                       | Gerät         |
| --------------- | ----------------------------------------- | ------------- |
| Spieler         | `/play/{code}` (oder `/` → Code eingeben) | Smartphone    |
| Moderator       | `/host/{gameId}?key={adminKey}`           | Tablet/Laptop |
| Leinwand        | `/screen/{gameId}`                        | Beamer        |
| Spielverwaltung | `/dashboard` (Login)                      | Organisatoren |

**Tech-Stack:** Laravel 13, Inertia v3 + Vue 3 + TypeScript (Vue Starter Kit), Tailwind 4, Laravel Reverb + Echo, SQLite (WAL), Pest 4, Larastan (Level 7), Pint.

## Lokales Setup

Voraussetzungen: PHP ≥ 8.3, Composer, Node 20+, **npm ≥ 11** (npm 10 bricht bei den `overrides` des Starter Kits mit `Cannot read properties of null (reading 'edgesOut')` ab), die PHP-Erweiterung `pdo_sqlite`.

```bash
composer install
npm install
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan reverb:install      # erzeugt REVERB_APP_ID/KEY/SECRET, falls leer
php artisan migrate --seed
composer run dev                # Webserver, Reverb, Queue, Vite, Logs
```

Der Seeder legt den Beispiel-Durchlauf aus der Spezifikation an (4 Runden-Songs, 2 Startkarten, 1 Tiebreaker-Song):

- Spieler: `http://localhost:8000/play/K7M2QX`
- Moderator: `http://localhost:8000/host/1?key=demo-admin-key-bitte-aendern`
- Leinwand: `http://localhost:8000/screen/1`
- Login Spielverwaltung: `test@example.com` / `password`

Neue Spiele legen Organisatoren im Dashboard an. Selbstregistrierung ist deshalb deaktiviert; Konten werden per Seeder oder `php artisan tinker` angelegt.

### Schrift

Das Design nutzt **BasierCircle** (Regular 400 / SemiBold 600). Die Schrift ist lizenzpflichtig und liegt nicht im Repository. Mit gültiger Web-Lizenz `BasierCircle-Regular.woff2` und `BasierCircle-SemiBold.woff2` nach `public/fonts/` legen. Ohne die Dateien greift der System-Font-Stack.

## Ablauf am Konzertabend

1. **Vorbereitung** (Moderator, Zustand `LOBBY`): Songs anlegen oder per CSV importieren, Runden-Songs per Drag & Drop sortieren, Startkarten-Pool und Tiebreaker-Song festlegen, Optionen (Bonusfrage, Countdown) setzen. Songs lassen sich nur vor Spielstart ändern.
2. **Leinwand** öffnen (Link im Moderator-Kopf): QR-Code, Spielcode, Live-Zähler der Spieler.
3. **Spiel starten** → pro Stück: _Abgabe schließen_ → _Auflösen_ → _Nächstes Stück_. Wird ein Stück nicht gespielt: _Runde überspringen_ (wird für niemanden gewertet).
4. Nach dem letzten Stück: _Tiebreaker starten_ (empfohlen bei Gleichstand auf dem Podium) oder direkt _Ergebnis zeigen_.

**Spieler im Blick** (Moderator, alle Zustände): Die Liste „Angemeldete Spieler“ zeigt alle Beigetretenen mit Status (aktiv/inaktiv), Beitrittszeit, Abgaben und Karten, mit Filter und Suche. Die Handys senden alle 45 s ein Lebenszeichen, solange die Seite sichtbar ist. Nach 2 Minuten ohne Lebenszeichen gilt ein Spieler als inaktiv, etwa wenn die Seite geschlossen oder das Handy gesperrt ist (`config/timetune.php`, `inactive_after_seconds`). Einzelne Spieler oder alle Inaktiven lassen sich entfernen (mit Bestätigung). Ihre Karten und Abgaben werden gelöscht, das Handy zeigt einen Hinweis, und ein erneuter Beitritt ist möglich.

**Darstellung anpassen** (Moderator): Logo hochladen, Akzent- und Hintergrundfarbe wählen (mit Live-Vorschau und Kontrastwarnung) und pro Song ein Foto hinterlegen (Spalte „Foto“ in der Songliste). Ohne Anpassung sehen Spieler und Leinwand aus wie im Standard-Design. Das Logo erscheint auf Handy und Leinwand (Lobby und Siegerehrung). Das Song-Foto wird erst nach der Auflösung gezeigt: auf der umgedrehten Karte am Handy, groß auf der Leinwand und bei Startkarten in der Lobby. Handys übernehmen geänderte Farben beim nächsten Laden, die Leinwand beim nächsten Zustandswechsel.

Der große Button sendet den Zustand mit, den der Moderator gesehen hat (`from`). Ein Doppelklick löst deshalb keinen zweiten Übergang aus. _Spiel zurücksetzen_ löscht Runden, Abgaben und Tiebreaker-Antworten; Spieler und Startkarten bleiben erhalten.

**Moderator-Link geheim halten:** Er enthält den `admin_key`. Wer den Link öffnet, hat für diese Browser-Session Moderationsrechte.

### CSV-Import

```
titel;interpret;jahr;rolle
Wonderwall;Oasis;1995;runde
Africa;Toto;1982;start
The Final Countdown;Europe;1986;tiebreaker
```

`rolle`: `runde`/`round`, `start`/`startkarte`/`start_pool`, `tiebreaker`. Die Kopfzeile ist optional, Komma als Trennzeichen wird ebenfalls erkannt. Bei fehlerhaften Zeilen wird nichts importiert und die betroffenen Zeilen werden angezeigt. Maßgeblich ist immer das **Original-Erscheinungsjahr**.

## Architektur

- **Zustandsmaschine** – `App\Enums\GameState::canTransitionTo()` definiert den Zustandsgraphen, `App\Services\GameFlow` führt die Übergänge aus. Jeder Übergang läuft in einer Transaktion mit gesperrter Spielzeile, die Broadcasts folgen nach dem Commit.
- **Auswertung** – `App\Services\TimelineEvaluator` (pure Klasse, ohne Framework) prüft: linker Nachbar ≤ Jahr ≤ rechter Nachbar, fehlender Nachbar = unbegrenzt.
- **Zeitleisten** – werden nicht gespeichert. `App\Services\Standings` berechnet Zeitleisten, Punkte und Ränge mit wenigen Queries im Speicher (Startkarte + korrekte Platzierungen). Die Rangliste ist eine Wettbewerbsrangfolge; am Spielende entscheiden kleinste Tiebreaker-Abweichung und danach frühere Abgabe.
- **Geheimhaltung** – Das Lied einer offenen oder geschlossenen Runde wird nie an Spieler oder Leinwand gesendet (`GameFlow::publicState()`, `PlayerView`, `GameView::screen()`). Nur `GameView::host()` enthält die Lösung.
- **Echtzeit** – Kanäle `game.{id}` (public), `game.{id}.host` (private, Session mit `admin_key`), `player.{id}` (private, Cookie `timetune_player`). Events: `StateChanged`, `SubmissionsCount` (gedrosselt auf 1×/s per verzögertem Job), `RoundRevealed`, `PlayerResult` (Queue-Jobs à 100 Spieler), `LeaderboardUpdated`, `GameFinished`.
- **Wiederverbindung** – Der Spieler wird über das `session_token`-Cookie erkannt (31 Tage gültig). Ist der WebSocket nicht verbunden, fragen die Clients alle 3 s per Polling ab; nach dem Reconnect holen sie verpasste Events einmalig nach.
- **Datenschutz** – Nur der Anzeigename wird gespeichert. `Player` ist `Prunable`; der Scheduler löscht täglich alle Spielerdaten, deren Spielende mehr als 30 Tage zurückliegt (`routes/console.php`).
- **Bilder** – `App\Services\ImageStore` speichert Uploads (JPG/PNG/WebP, kein SVG) verkleinert als WebP unter einem Zufallsnamen in `storage/app/public/games/{id}`: Logo 480 px, Song-Fotos 400 px (Handy) und 1200 px (Leinwand). So laden 500 Handys bei der Auflösung je ca. 20–40 KB statt des Originals. Die Bild-URL eines Songs steckt nur in aufgedeckten Karten.
- **Farben** – Das Theme (`games.options.theme`) überschreibt per CSS-Variablen `--color-royal`/`--color-night` und den Leinwand-Verlauf (`resources/js/composables/useTheme.ts`). Ohne Theme wird nichts überschrieben.
- **Moderation** – Wortfilter in `config/timetune.php` (abgelehnt beim Beitritt); der Moderator kann Namen ausblenden, die dann als „Spieler {id}“ erscheinen.

## Qualität

```bash
./vendor/bin/pest tests/Unit tests/Feature  # Unit- und Feature-Tests
./vendor/bin/pest tests/Browser             # Browsertests (Playwright: npx playwright install chromium)
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
npm run check && npm run types:check
```

Abgedeckt sind unter anderem der Beispiel-Durchlauf aus Abschnitt 7 (Unit + Feature), gleiche Jahre, die Geheimhaltung der Lösung in allen Antworten und Events, Beitritt, Sonderfälle, Tiebreaker, CSV-Import, Moderator-Rechte, die Löschfrist und Neu laden auf dem Handy (Browsertest).

### Lasttest (k6)

`loadtest/k6-players.js` simuliert Spieler mit WebSocket-Verbindung, die in jeder Runde abgeben, plus einen Moderator, der das Spiel steuert. Gemessen wird die Zeit vom Senden eines `StateChanged` bis zur Ankunft beim Client (Schwelle p95 < 1 s).

```bash
k6 run -e BASE_URL=https://timetune.example.de \
       -e WS_URL=wss://timetune.example.de/app/$REVERB_APP_KEY \
       -e GAME_CODE=K7M2QX -e GAME_ID=1 -e ADMIN_KEY=... \
       -e PLAYERS=500 loadtest/k6-players.js
```

Das Spiel muss in der Lobby sein und Runden-Songs sowie Startkarten haben. Der Test spielt alle Runden durch, deshalb nicht gegen ein echtes Konzertspiel laufen lassen. Für aussagekräftige Werte gegen die Produktionsumgebung testen: Der lokale `artisan serve` ist dafür nicht ausgelegt. Ein lokaler Lauf mit 200 Spielern gegen SQLite (WAL) ergab 0 fehlgeschlagene Anfragen, 800/800 Abgaben und Zustandswechsel mit p95 = 17 ms.

## Deployment (Laravel Forge)

Ein Server mit 2 vCPU / 4 GB RAM (z. B. AWS EC2 `t3.medium`) reicht für 500 Spieler.

1. **Server** in Forge anlegen: PHP 8.4, Nginx, keine Datenbank nötig. Site mit dem Repository verbinden, SSL per Let’s Encrypt.
   **SQLite-Datei außerhalb des Release-Verzeichnisses** anlegen, sonst geht sie bei Zero-Downtime-Deployments verloren: `mkdir -p /home/forge/timetune-data && touch /home/forge/timetune-data/database.sqlite`.
2. **Umgebung** (`.env`): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `DB_CONNECTION=sqlite`, `DB_DATABASE=/home/forge/timetune-data/database.sqlite`, `BROADCAST_CONNECTION=reverb`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `REVERB_*` (Host = öffentliche Domain, `REVERB_PORT=443`, `REVERB_SCHEME=https`, Server intern auf `REVERB_SERVER_PORT=8080`) und die `VITE_REVERB_*`-Variablen vor dem Build.
3. **Deploy-Skript** (zusätzlich zum Forge-Standard):
    ```bash
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    php artisan migrate --force
    php artisan storage:link
    php artisan optimize
    php artisan reverb:restart
    php artisan queue:restart
    ```
4. **Reverb**: In Forge unter _Application → Laravel Reverb_ aktivieren. Forge legt dann den Nginx-WebSocket-Proxy (`/app`, `/apps` → `127.0.0.1:8080`) und den Supervisor-Daemon an. Manuell als Daemon: `php artisan reverb:start --host=127.0.0.1 --port=8080`. Für 500+ Verbindungen das Open-Files-Limit des Daemons erhöhen (Forge: _Server → Network → Max Connections_, bzw. `ulimit -n 10000`) und die PHP-Extension `ev` oder `event` installieren.
5. **Queue-Worker** als Supervisor-Daemon (Forge → _Daemons_): `php artisan queue:work --sleep=1 --tries=3`, **genau ein Prozess**. Mehrere Worker auf der SQLite-Queue blockieren sich beim Abholen der Jobs (`database is locked`). Wer mehr Worker will, stellt die Queue auf Redis um (`QUEUE_CONNECTION=redis`).
6. **Scheduler**: Forge → _Scheduler_ → `php artisan schedule:run` minütlich (für die 30-Tage-Löschung).
7. **Uploads**: Song-Fotos dürfen bis 10 MB groß sein. Dazu in PHP `upload_max_filesize` und `post_max_size` auf mindestens 12M setzen (Forge: _PHP → Max File Upload Size_); Nginx erlaubt in Forge standardmäßig genug.
8. **Monitoring** am Konzertabend: Laravel Pulse oder Nightwatch (optional, `composer require laravel/pulse`). Telescope ist nur lokal aktiv.

**Backup:** Zusätzlich zur Datenbank den Ordner `storage/app/public/games` (Logos, Fotos) sichern. Bei aktivem WAL nicht einfach die Datei kopieren, sondern `sqlite3 /home/forge/timetune-data/database.sqlite ".backup '/home/forge/backups/timetune-$(date +%F).sqlite'"` (z. B. täglich im Forge-Scheduler).

Bei Lastproblemen zuerst Queue, Cache und Session auf Redis umstellen (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`). Ein Wechsel auf MySQL braucht nur andere `DB_*`-Werte, der Code ist MySQL-kompatibel.

## Abweichungen von der Spezifikation und offene Punkte

- **Datenbank:** SQLite (WAL, `busy_timeout` 5 s, `IMMEDIATE`-Transaktionen) statt MySQL 8, mit genau einem Queue-Worker. Es gibt nur einen Server, und die Schreiblast kommt in kurzen Spitzen. Ein MySQL-Dienst wäre zusätzlicher Betriebsaufwand ohne Nutzen. Der Code bleibt MySQL-kompatibel.

- **QR-Code:** `bacon/bacon-qr-code` (v3) statt `simplesoftwareio/simple-qrcode`. Das Paket ist nicht mehr gepflegt und verlangt bacon v2, das mit der von Fortify benötigten v3 kollidiert. bacon ist die Bibliothek, die simple-qrcode intern nutzt.
- **Datenmodell-Ergänzungen:** `games.finished_at` (für die Löschfrist), `rounds.skipped_at`, `rounds.closes_at` (Countdown), `rounds.bonus_options`/`bonus_correct_index`, `players.is_hidden` und die Tabelle `bonus_answers` für die optionale Bonusfrage.
- **Erweiterungen über die Spezifikation hinaus:** Spielerliste mit Aktivität und Entfernen, Logo, Song-Fotos und Farben. Dafür gibt es die Spalten `games.logo` und `songs.image`, das Heartbeat-Endpoint `POST /api/players/me/heartbeat` und das Event `PlayerRemoved` auf `player.{id}`.
- **Bonusfrage:** Die Bonuspunkte werden getrennt ausgewiesen und zählen **nicht** für die Rangliste, die sich nur nach Karten richtet. Bitte klären, ob das so gewünscht ist.
- **Tiebreaker:** Alle Spieler dürfen eine Schätzung abgeben (einmalig); sie wirkt nur zwischen punktgleichen Spielern.
- **Spielverwaltung:** Neue Spiele legen eingeloggte Organisatoren im Dashboard an (optionale Nutzung des Starter-Kit-Logins laut Spezifikation).
- **Moderator-API:** `admin_key` per Header `X-Admin-Key` oder über die Session, die der Moderator-Link freischaltet. CSRF-Schutz gilt auch für die Moderator-Endpunkte.
- **Seitengröße Spieler:** ca. 144 KB übertragen (gzip), 497 KB unkomprimiert.
