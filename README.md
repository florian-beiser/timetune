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

Statt der lizenzpflichtigen **BasierCircle** aus dem Design-System verwendet TimeTune **Figtree** (SIL Open Font License, frei auch für kommerzielle Nutzung): eine geometrische Grotesk im selben Stil, mit den Gewichten 400 und 600. Die Schrift kommt über das npm-Paket `@fontsource/figtree` und wird mit den übrigen Assets ausgeliefert, ohne Anfrage an Google Fonts (DSGVO).

## Demo mit Docker

Für eine Vorführung läuft TimeTune komplett in Docker, ohne lokales PHP, Node oder eine Datenbank. Die Befehle funktionieren in PowerShell, im Windows-Terminal mit WSL und unter macOS/Linux gleich.

### Schritt für Schritt

1. **Docker Desktop** installieren und starten: <https://www.docker.com/products/docker-desktop/>.
2. **Projekt holen:**
    ```bash
    git clone git@github.com:florian-beiser/timetune.git
    cd timetune
    ```
3. **Starten.** Der erste Start baut das Image, das dauert ca. 2–3 Minuten:
    ```bash
    docker compose up -d --build
    ```
4. **Prüfen**, ob alles läuft. Alle vier Container müssen `Up` sein, `web` zusätzlich `healthy`:
    ```bash
    docker compose ps
    ```
    Im Browser am Laptop: <http://localhost:8090/play/K7M2QX> zeigt die Beitrittsseite.
5. **Eigene Netzwerk-Adresse herausfinden.** Unter Windows `ipconfig` (IPv4-Adresse des WLAN- oder Ethernet-Adapters, z. B. `192.168.15.153`), unter macOS `ipconfig getifaddr en0`.
6. **Leinwand und Moderator mit dieser Adresse öffnen**, nicht mit `localhost`:
    - Leinwand (Beamer, Vollbild mit F11): `http://192.168.15.153:8090/screen/1`
    - Moderator (zweiter Tab oder Tablet): `http://192.168.15.153:8090/host/1?key=demo-admin-key-bitte-aendern`
7. **Handys** im selben WLAN scannen den QR-Code auf der Leinwand, geben einen Namen ein und spielen mit.
8. **Spielen:** Im Moderator-Bereich, Tab _Steuerung_: _Spiel starten_ → pro Stück _Abgabe schließen_ → _Auflösen_ → _Nächstes Stück_. Das Demo-Spiel enthält den Beispiel-Durchlauf aus der Spezifikation (4 Stücke, Tiebreaker „The Final Countdown“).

### Wenn es hakt

- **Handys kommen nicht auf die Seite:**
    - Die Leinwand muss über die Netzwerk-Adresse geöffnet sein, denn der QR-Code enthält genau die Adresse aus der Adresszeile der Leinwand. `localhost` erreichen Handys nicht. Unter WSL ist auch die `172.x`-Adresse intern, also die Windows-Adresse nehmen.
    - Die Windows-Firewall muss Docker für private Netzwerke erlauben. Beim ersten Start kommt dazu eine Rückfrage.
    - Gäste-WLANs isolieren Geräte oft voneinander. Dann einen Handy-Hotspot verwenden und Laptop und Handys damit verbinden.
- **Port 8090 belegt:** anderen Port wählen.
    - PowerShell: `$env:TIMETUNE_PORT=9000; docker compose up -d`
    - bash/WSL/macOS: `TIMETUNE_PORT=9000 docker compose up -d`

    Die Adressen oben dann mit `:9000`.

- **Fehlersuche:** `docker compose logs -f web` (oder `reverb`, `queue`).

### Zurücksetzen, aktualisieren, beenden

- **Neue Runde mit denselben Spielern:** im Moderator-Bereich unter _Einstellungen → Gefahrenzone_ _Spiel zurücksetzen_.
- **Komplett frisch** (Spieler, Logo, Fotos, Farben weg): `docker compose down -v`, dann `docker compose up -d`.
- **Neue Version holen:** `git pull`, dann `docker compose up -d --build`. Daten bleiben erhalten.
- **Beenden:** `docker compose down`. Daten bleiben erhalten.

### Technik

Aufbau (`compose.yaml`, ein Image für alle Rollen):

| Container   | Aufgabe                                                                                                             |
| ----------- | ------------------------------------------------------------------------------------------------------------------- |
| `web`       | FrankenPHP (PHP + Caddy) auf Port 80; leitet `/app/*` (WebSockets) an Reverb weiter, migriert und seedet beim Start |
| `reverb`    | WebSocket-Server                                                                                                    |
| `queue`     | genau ein Queue-Worker                                                                                              |
| `scheduler` | `schedule:work` (30-Tage-Löschung)                                                                                  |

Die SQLite-Datenbank und der App-Key liegen im Volume `data`, Logos und Fotos im Volume `uploads`. Sie überstehen Neustarts und Updates.

Die Browser holen sich die WebSocket-Adresse zur Laufzeit vom Server (`REVERB_PUBLIC_*`, leer = gleiche Adresse wie die Seite). Dasselbe Image funktioniert daher unter jeder IP oder Domain. HTTPS übernimmt ein vorgeschalteter Reverse-Proxy (nächster Abschnitt). Für den Dauerbetrieb im Internet die Demo-Schlüssel ersetzen: die Reverb-Schlüssel in `compose.yaml` und den Moderatorschlüssel aus dem Seeder (eigenes Spiel im Dashboard anlegen).

## Betrieb hinter einem HTTPS-Reverse-Proxy

Beispiel: TimeTune läuft per Docker Compose auf Port 8090, ein Caddy davor terminiert TLS für `https://timetune.beiser.info`.

```
Browser ──https/wss──▶ Caddy (TLS) ──http──▶ :8090 TimeTune (web) ──/app/*──▶ reverb:8080
```

### 1. Caddy

Caddy auf demselben Server:

```
timetune.beiser.info {
	reverse_proxy localhost:8090
}
```

Caddy setzt `X-Forwarded-For`, `X-Forwarded-Proto` und `X-Forwarded-Host` selbst und leitet WebSocket-Verbindungen (`/app/…`) ohne weitere Konfiguration weiter. Läuft Caddy selbst in Docker, statt `localhost` den Host erreichen (z. B. `host.docker.internal:8090`) oder Caddy ins Compose-Netz von TimeTune hängen.

### 2. TimeTune konfigurieren

```bash
cp docker/proxy.env.example docker/proxy.env    # Werte prüfen und anpassen
docker compose --env-file docker/proxy.env up -d --build
```

| Variable                          | Bedeutung                                                                              | Beispiel                       |
| --------------------------------- | -------------------------------------------------------------------------------------- | ------------------------------ |
| `TIMETUNE_APP_URL`                | Öffentliche Adresse (`APP_URL`); gilt für Links, die außerhalb einer Anfrage entstehen | `https://timetune.beiser.info` |
| `TIMETUNE_TRUSTED_PROXIES`        | Proxys, deren `X-Forwarded-*`-Header gelten (`TRUSTED_PROXIES`)                        | `private`                      |
| `TIMETUNE_SECURE_COOKIES`         | Cookies nur über HTTPS senden (`SESSION_SECURE_COOKIE`)                                | `true`                         |
| `TIMETUNE_PORT` / `TIMETUNE_BIND` | Port und Adresse am Host, auf die der Proxy zugreift                                   | `8090` / `127.0.0.1`           |

Ohne `--env-file` gelten die Demo-Werte: kein Proxy, `http://localhost:8090`, normale Cookies. Die Variablen heißen `TIMETUNE_*`, damit Docker Compose nicht versehentlich Werte aus der Laravel-`.env` im Projektordner übernimmt.

**`TRUSTED_PROXIES`** (auch außerhalb von Docker in der `.env` nutzbar):

- leer (Standard): Weitergeleitete Header werden ignoriert. Das ist richtig, solange kein Proxy davor steht.
- `private`: alle privaten und lokalen Netze (127/8, 10/8, 172.16/12, 192.168/16 usw.). Das passt, wenn Caddy auf demselben Server oder im Docker-Netz läuft, denn TimeTune sieht dann eine private Absenderadresse.
- IP-Adressen oder Netze, kommagetrennt, z. B. `203.0.113.10, 10.0.0.0/8`: für einen Proxy auf einem anderen Server.
- `*`: vertraut jedem direkten Gegenüber. Nur verwenden, wenn Port 8090 ausschließlich vom Proxy erreichbar ist, sonst kann jeder Client Host und Protokoll fälschen.

Ausgewertet werden `X-Forwarded-For`, `-Host`, `-Port`, `-Proto`, `-Prefix` und `X-Forwarded-AWS-ELB`. Laravel erkennt damit HTTPS und erzeugt Asset-URLs, Redirects, Links und den QR-Code mit `https://` und der öffentlichen Domain.

**`TIMETUNE_BIND=127.0.0.1`** macht Port 8090 nur auf dem Server selbst erreichbar. Niemand kann dann den Proxy umgehen. Läuft Caddy in einem Container oder auf einem anderen Rechner, `0.0.0.0` setzen und den Port per Firewall auf den Proxy beschränken.

### 3. WebSockets (Reverb)

Nichts zu tun: Die Browser verbinden sich mit derselben Adresse wie die Seite, bei HTTPS also automatisch über `wss://timetune.beiser.info/app/…`. Caddy leitet das an TimeTune weiter, der `web`-Container an Reverb. Intern sprechen Web, Queue und Reverb unverschlüsselt im Docker-Netz miteinander (`REVERB_HOST=reverb`).

### 4. Prüfen

```bash
curl -s https://timetune.beiser.info/play/K7M2QX | grep -o 'href="[^"]*app-[^"]*\.css"'
# erwartet: href="https://timetune.beiser.info/build/assets/app-….css"
```

Im Browser (Entwicklertools → Netzwerk) dürfen keine `http://`-Anfragen und keine „Mixed Content“-Meldungen auftauchen. Die WebSocket-Verbindung muss als `wss://…/app/…` mit Status 101 erscheinen.

| Problem                               | Ursache / Lösung                                                                                                                       |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| CSS/JS mit `http://`, „Mixed Content“ | `TIMETUNE_TRUSTED_PROXIES` leer oder Proxy-Adresse nicht enthalten → `private` oder die IP des Proxys eintragen, Container neu starten |
| Links mit falscher Domain             | Proxy sendet `X-Forwarded-Host` nicht, oder er ist nicht vertraut; bei Caddy ist das Standard                                          |
| Login/Formulare: 419 „Page Expired“   | `TIMETUNE_SECURE_COOKIES=true`, aber Zugriff über `http://` → nur über HTTPS aufrufen                                                  |
| Live-Updates fehlen, alle 3 s Polling | WebSocket wird nicht durchgereicht: andere Proxys brauchen Upgrade-Header für `/app/` (Caddy: automatisch)                             |

## Ablauf am Konzertabend

Der Moderator-Bereich hat vier Tabs. Der gewählte Tab steht in der Adresse (`#steuerung`, `#spieler`, `#songs`, `#einstellungen`) und bleibt beim Neuladen erhalten.

| Tab               | Inhalt                                                                                                                                                                                                                                                                                                                                    |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Steuerung**     | In der Lobby eine Bereitschafts-Checkliste (Songs, Startkarten, Tiebreaker, Spieler, QR-Adresse) und _Spiel starten_. Danach Status, aktuelles Stück mit Lösung, Abgaben, der Button für den nächsten Schritt, _Runde wieder öffnen_, _Runde überspringen_, Song-Fortschritt und Top 10. Bei offener Runde blinkt ein roter Punkt am Tab. |
| **Spieler**       | Alle Beigetretenen mit Aktivität; umbenennen, ausblenden, entfernen. Der Tab zeigt Anzahl und Inaktive.                                                                                                                                                                                                                                   |
| **Songs**         | Runden-Songs, Startkarten-Pool, Tiebreaker; CSV-Import, Fotos, Drag & Drop. Nach dem Start nur lesbar, Fotos bleiben änderbar.                                                                                                                                                                                                            |
| **Einstellungen** | Spiel (Name, Titel, Bonusfrage, Countdown), Beitritts-Adresse, Darstellung (Logo, Farben, Vorschau) und die Gefahrenzone mit _Spiel zurücksetzen_.                                                                                                                                                                                        |

1. **Vorbereitung** (Moderator, Zustand `LOBBY`): Songs anlegen oder per CSV importieren, Runden-Songs per Drag & Drop sortieren, Startkarten-Pool und Tiebreaker-Song festlegen, Optionen (Bonusfrage, Countdown) setzen. Songs lassen sich nur vor Spielstart ändern.
2. **Leinwand** öffnen (Link im Moderator-Kopf): QR-Code, Spielcode, Live-Zähler der Spieler.
3. **Spiel starten** → pro Stück: _Abgabe schließen_ → _Auflösen_ → _Nächstes Stück_. Wird ein Stück nicht gespielt: _Runde überspringen_ (wird für niemanden gewertet).
4. Nach dem letzten Stück: _Tiebreaker starten_ (empfohlen bei Gleichstand auf dem Podium) oder direkt _Ergebnis zeigen_.

**Spieler im Blick** (Tab _Spieler_): Die Liste „Angemeldete Spieler“ zeigt alle Beigetretenen mit Status (aktiv/inaktiv), Beitrittszeit, Abgaben und Karten, mit Filter und Suche. Die Handys senden alle 45 s ein Lebenszeichen, solange die Seite sichtbar ist. Nach 2 Minuten ohne Lebenszeichen gilt ein Spieler als inaktiv, etwa wenn die Seite geschlossen oder das Handy gesperrt ist (`config/timetune.php`, `inactive_after_seconds`). Einzelne Spieler oder alle Inaktiven lassen sich entfernen (mit Bestätigung). Ihre Karten und Abgaben werden gelöscht, das Handy zeigt einen Hinweis, und ein erneuter Beitritt ist möglich.

**Beitritts-Adresse** (Tab _Einstellungen_): Statt der Adresse der App kann der QR-Code auf eine eigene Adresse zeigen, etwa eine kurze Domain oder einen Kurzlink. Regeln:

- nur Host, z. B. `hitster.musikverein.at` oder `localhost:8010`: `/play/{Code}` wird angehängt
- mit Pfad, z. B. `bit.ly/mv-hitster`: wird unverändert verwendet (der Kurzlink muss auf `…/play/{Code}` weiterleiten)
- der Platzhalter `{code}` wird durch den Spielcode ersetzt

Ohne Schema ergänzt die App `https://`, bei `localhost` und IP-Adressen `http://`. Das Feld zeigt beim Tippen, wohin der QR-Code führen wird. Leer bedeutet die Adresse der App.

**Darstellung anpassen** (Tab _Einstellungen_): Titel ändern (statt „Konzert-Hitster“, z. B. „Cäcilia-Hitster“; höchstens 40 Zeichen, auf der Leinwand wird lange Schrift automatisch kleiner), Logo hochladen, Akzent- und Hintergrundfarbe wählen (mit Live-Vorschau und Kontrastwarnung) und pro Song ein Foto hinterlegen (Spalte „Foto“ in der Songliste). Ohne Anpassung sehen Spieler und Leinwand aus wie im Standard-Design. Das Logo erscheint auf Handy und Leinwand (Lobby und Siegerehrung). Das Song-Foto wird erst nach der Auflösung gezeigt: auf der umgedrehten Karte am Handy, groß auf der Leinwand und bei Startkarten in der Lobby. Handys übernehmen geänderte Farben beim nächsten Laden, die Leinwand beim nächsten Zustandswechsel.

**Runde wieder öffnen:** Wurde eine Runde zu früh geschlossen, öffnet _Runde wieder öffnen_ sie erneut. Das geht nur vor dem _Auflösen_, danach kennen alle die Lösung. Abgegebene Lücken bleiben erhalten und lassen sich bis zum erneuten Schließen ändern. Wer nach dem Schließen beigetreten ist, darf jetzt ebenfalls abgeben. Ein laufender Countdown startet neu.

**Namen ändern:** Spieler ändern ihren Namen jederzeit über „Name ändern“ unten auf dem Handy. Es gelten dieselben Regeln wie beim Beitritt: höchstens 20 Zeichen, Wortfilter, gleiche Namen werden nummeriert. Der Moderator ändert Namen über den Stift neben dem Namen in der Spielerliste, auch um anstößige Namen zu ersetzen; der Wortfilter gilt dort nicht. Das Handy zeigt den neuen Namen sofort an.

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
