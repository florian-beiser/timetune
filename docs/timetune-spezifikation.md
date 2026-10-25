# TimeTune – Spezifikation Web-App

Stand: 25.10.2026

## 1. Ziel und Überblick

Eine Web-App, mit der das Konzertpublikum per Smartphone eine Live-Variante von Hitster spielt. Der Musikverein spielt moderne Stücke live, jeder Besucher ordnet jedes Stück in seine eigene Zeitleiste ein. Wer am Ende die meisten richtig platzierten Karten hat, gewinnt.

- Keine Installation: Beitritt per QR-Code im Browser, nur Name eingeben.
- Keine Musik aus der App: Die Musik kommt live von der Bühne.
- Maßgebliches Jahr: das Original-Erscheinungsjahr des Songs (nicht das Jahr des Arrangements).
- Zielgröße: bis 500 gleichzeitige Spieler, 8–12 Stücke pro Spiel.
- Sprache der Oberfläche: Deutsch.

## 2. Tech-Stack

Durchgehend offizielle Laravel-Pakete bzw. das, was die Laravel-Dokumentation empfiehlt.

### Backend

| Komponente | Wahl | Zweck |
| --- | --- | --- |
| Sprache | PHP 8.4 (min. 8.3) | Laufzeit |
| Framework | Laravel 13 | Basis |
| Datenbank | MySQL 8 | Persistenz |
| WebSockets | Laravel Reverb | Echtzeit-Events an alle Clients |
| Broadcasting | Laravel Broadcasting (Events mit `ShouldBroadcastNow`) | Zustandswechsel pushen |
| Queue | Database-Driver (Redis optional) | Auswertung, Aufräum-Jobs |
| Cache/Session | Database (Redis optional bei Lastproblemen) | Sessions, Rangliste |
| Scheduler | Laravel Scheduler | Löschung der Spielerdaten nach 30 Tagen |
| Rate Limiting | Laravel `RateLimiter` | Schutz für Join und Abgaben |
| QR-Code | `simplesoftwareio/simple-qrcode` | QR-Code für Leinwand |

### Frontend

| Komponente | Wahl | Zweck |
| --- | --- | --- |
| Starter Kit | Laravel Vue Starter Kit (Inertia + Vue 3 + TypeScript) | Grundgerüst, Routing ohne separate API-App |
| UI | Tailwind CSS + shadcn-vue (im Starter Kit enthalten) | Komponenten, Dark Mode |
| Echtzeit-Client | Laravel Echo (`@laravel/echo-vue`) + `pusher-js` | Verbindung zu Reverb |
| Build | Vite | Asset-Bundling |
| Routen-Typisierung | Laravel Wayfinder | Typsichere Routen im Frontend |

Hinweis: Die Auth-Funktionen des Starter Kits werden für Spieler nicht genutzt. Der Moderator-Zugang läuft über einen geheimen `admin_key` in der URL (siehe Abschnitt 3). Optional kann das Starter-Kit-Login für die Spielverwaltung genutzt werden.

### Entwicklung und Qualität

| Werkzeug | Zweck |
| --- | --- |
| Laravel Herd oder Laravel Sail | Lokale Umgebung (Sail = Docker mit MySQL) |
| Laravel Boost | MCP-Server und Guidelines für KI-gestützte Entwicklung (Claude Code etc.) |
| Pest 4 | Unit-, Feature- und Browsertests |
| Laravel Pint | Code-Formatierung |
| Larastan | Statische Analyse |
| Laravel Pail | Log-Tailing im Terminal |
| Laravel Telescope | Debugging (nur lokal) |
| k6 | Lasttest mit 500 simulierten WebSocket-Clients |

### Betrieb

| Werkzeug | Zweck |
| --- | --- |
| Laravel Forge (auf AWS EC2) oder Laravel Cloud | Provisionierung, Deployment, SSL |
| Supervisor (über Forge) | Reverb-Server und Queue-Worker dauerhaft laufen lassen |
| Laravel Pulse | Live-Monitoring während des Konzerts |
| Laravel Nightwatch (optional) | Fehler- und Performance-Monitoring |

Ein einzelner Server mit 2 vCPU / 4 GB RAM reicht für 500 Spieler. Reverb läuft auf demselben Server hinter Nginx als WebSocket-Proxy.

### Composer- und NPM-Pakete (Überblick)

```bash
laravel new timetune --vue
composer require laravel/reverb simplesoftwareio/simple-qrcode
composer require --dev laravel/boost laravel/telescope larastan/larastan
php artisan install:broadcasting   # installiert Reverb + Echo
php artisan boost:install
```

## 3. Rollen und Ansichten

Die App hat drei Ansichten unter einer gemeinsamen Spiel-ID. Alle Ansichten sind per Echtzeit-Verbindung synchronisiert.

| Ansicht | URL (Beispiel) | Gerät | Zweck |
| --- | --- | --- | --- |
| Spieler | `/play/{gameCode}` | Smartphone der Besucher | Beitreten, eigene Zeitleiste sehen, Stücke platzieren, Ergebnis sehen |
| Moderator | `/host/{gameId}?key={adminKey}` | Tablet/Laptop des Moderators | Songliste verwalten, Runden starten/schließen/auflösen, Spiel beenden |
| Leinwand | `/screen/{gameId}` | Beamer im Saal | QR-Code, Spielerzahl, Rundenstatus, Auflösung, Rangliste |

Der Moderator-Zugang ist durch einen geheimen Admin-Schlüssel geschützt. Spieler brauchen kein Konto.

## 4. Spielregeln

Jeder Spieler baut eine eigene Zeitleiste; alle hören gleichzeitig dasselbe Stück.

**Startkarte**
- Beim Beitritt erhält jeder Spieler zufällig eine Startkarte aus einem separaten Pool (Songs, die nicht gespielt werden).
- Die Startkarte ist aufgedeckt (Jahr, Titel, Interpret sichtbar) und zählt als 1 Karte.
- Die Verteilung rotiert möglichst gleichmäßig über den Pool, damit die Zeitleisten unterschiedlich sind.

**Zeitleiste**
- Karten sind nach Jahr sortiert, alt oben, neu unten.
- Bei n Karten gibt es n+1 Lücken (Positionen 0 bis n).
- Nur die Reihenfolge zählt, Abstände zwischen Jahren spielen keine Rolle.

**Platzierung pro Runde**
- Der Spieler wählt genau eine Lücke. Bis zum Schließen der Runde darf er sie ändern.
- Keine Abgabe bis zum Schließen = falsch.

**Auswertung**
- Richtig, wenn gilt: Jahr der linken Nachbarkarte ≤ Song-Jahr ≤ Jahr der rechten Nachbarkarte (fehlender Nachbar = unbegrenzt).
- Gleiches Jahr wie ein Nachbar gilt als richtig.
- Richtig: Die Karte wird in die Zeitleiste eingefügt. Falsch: Die Karte verfällt.

**Wertung**
- Punkte = Anzahl Karten in der Zeitleiste (inkl. Startkarte).
- Gleichstand: Tiebreaker-Frage am Ende. Jeder gibt ein Jahr zu einem festgelegten Song ein; die kleinste Abweichung gewinnt. Bleibt es gleich, entscheidet die frühere Abgabe.

**Optional (per Einstellung):** Bonusfrage pro Runde per Multiple Choice (Titel/Interpret, 4 Antworten). Richtige Antwort = +1 Bonuspunkt, getrennt von den Karten ausgewiesen.

## 5. Spielzustände und Ablauf

Nur der Moderator löst Übergänge aus; Spieler und Leinwand reagieren darauf.

| Zustand | Auslöser (Moderator) | Nächster Zustand | Spieler sieht | Leinwand zeigt |
| --- | --- | --- | --- | --- |
| `LOBBY` | „Spiel starten“ | `ROUND_OPEN` (Runde 1) | Eigene Startkarte, „Gleich geht’s los“ | QR-Code, Spielerzahl |
| `ROUND_OPEN` | „Abgabe schließen“ | `ROUND_CLOSED` | Zeitleiste mit antippbaren Lücken | „Stück X von N – jetzt einordnen“, Anzahl Abgaben |
| `ROUND_CLOSED` | „Auflösen“ | `REVEALED` | „Abgabe geschlossen“, gewählte Position | „Abgabe geschlossen“ |
| `REVEALED` | „Nächstes Stück“ | `ROUND_OPEN` (nächste Runde) | Richtig/Falsch, aktualisierte Zeitleiste | Jahr, Titel, Interpret, Anteil richtig, Top 10 |
| `REVEALED` (letzte Runde) | „Tiebreaker starten“ | `TIEBREAKER` | Eingabefeld Jahr | Tiebreaker-Frage |
| `TIEBREAKER` | „Ergebnis zeigen“ | `FINISHED` | Eigener Rang, Punkte | Siegerpodest Top 3, Top 10 |

Optional kann `ROUND_OPEN` einen Countdown haben (z. B. 30 s), nach dem automatisch geschlossen wird. Standard ist manuelles Schließen, weil die Stücklängen variieren.

Umsetzung: `state` als PHP-Enum (`App\Enums\GameState`), erlaubte Übergänge zentral in einer Methode `canTransitionTo()` geprüft.

## 6. Screens im Detail

### Spieler (Smartphone, Hochformat)

1. **Beitreten:** Name eingeben (max. 20 Zeichen), Button „Mitspielen“. Gleiche Namen werden automatisch nummeriert („Anna 2“).
2. **Lobby:** Groß die eigene Startkarte (Jahr, Titel, Interpret), darunter „Warte auf das erste Stück…“.
3. **Runde offen:** Oben „Stück 3 von 10“ und eine verdeckte Karte „?“. Darunter die Zeitleiste vertikal: Karten als Kacheln mit Jahr groß, Titel/Interpret klein; zwischen den Kacheln und an beiden Enden Lücken als Buttons „hier einfügen“. Die gewählte Lücke wird hervorgehoben und zeigt die „?“-Karte. Hinweis: „Du kannst bis zum Schließen ändern“.
4. **Runde geschlossen:** Lücken gesperrt, gewählte Position bleibt sichtbar.
5. **Auflösung:** Karte dreht sich um (Animation). Grün „Richtig!“ mit eingefügter Karte oder rot „Daneben“ mit Hinweis, wo sie hingehört hätte; die Karte verschwindet. Unten: eigene Kartenzahl und aktueller Rang.
6. **Tiebreaker:** Zahleneingabe Jahr (4-stellig), Button „Abschicken“.
7. **Ende:** Rang, Punkte, eigene finale Zeitleiste.

### Moderator (Tablet/Laptop)

- **Vorbereitung:** Spiel anlegen (Name), Songliste für die Runden (Reihenfolge per Drag & Drop), Startkarten-Pool, Tiebreaker-Song, Optionen (Bonusfrage, Countdown). Import per CSV (`titel;interpret;jahr;rolle`).
- **Live:** Aktueller Zustand, aktuelles Stück mit Lösung (nur hier sichtbar), Anzahl Spieler und Abgaben, ein großer Button für den nächsten Übergang. Zusätzlich: Runde überspringen, Spiel zurücksetzen (mit Bestätigung).

### Leinwand (Beamer, Querformat)

- Lobby: großer QR-Code mit Kurz-URL und Spielcode, Live-Zähler der Spieler.
- Runde offen: „Stück 3 von 10 – jetzt einordnen!“, Fortschrittsbalken der Abgaben.
- Auflösung: Jahr sehr groß, Titel und Interpret, „62 % lagen richtig“, Top-10-Rangliste.
- Ende: Podest Platz 1–3, darunter Top 10.
- Ohne Bedienelemente; folgt nur dem Spielzustand.

## 7. Beispiel-Durchlauf

Vier Stücke, zwei Spieler. Das Beispiel dient zugleich als Testfall für die Auswertungslogik.

**Startkarten:** Anna erhält *Africa* – Toto (1982), Markus *Viva la Vida* – Coldplay (2008).

| Runde | Song (Jahr) | Anna: vorher → Lücke | Anna: Ergebnis | Markus: vorher → Lücke | Markus: Ergebnis |
| --- | --- | --- | --- | --- | --- |
| 1 | Wonderwall – Oasis (1995) | [1982] → Pos. 1 | richtig → [1982, 1995] | [2008] → Pos. 1 | falsch → [2008] |
| 2 | Billie Jean – Michael Jackson (1983) | [1982, 1995] → Pos. 1 | richtig → [1982, 1983, 1995] | [2008] → Pos. 0 | richtig → [1983, 2008] |
| 3 | Rolling in the Deep – Adele (2010) | [1982, 1983, 1995] → Pos. 3 | richtig → [1982, 1983, 1995, 2010] | [1983, 2008] → Pos. 1 | falsch → [1983, 2008] |
| 4 | Bohemian Rhapsody – Queen (1975) | [1982, 1983, 1995, 2010] → Pos. 1 | falsch → unverändert | [1983, 2008] → Pos. 0 | richtig → [1975, 1983, 2008] |

**Endstand:** Anna 4 Karten, Markus 3 Karten. Die Leinwand zeigt nach Runde 4: „Bohemian Rhapsody – Queen – 1975“, „50 % lagen richtig“, Rangliste Anna (4), Markus (3).

**Spieler-Screen Anna, Runde 4, offen (vereinfacht):**

```
Stück 4 von 4          Deine Karten: 4

          [ ? ]

  ( hier einfügen )
  1982  Africa · Toto
  ( hier einfügen )   ← gewählt
  1983  Billie Jean · Michael Jackson
  ( hier einfügen )
  1995  Wonderwall · Oasis
  ( hier einfügen )
  2010  Rolling in the Deep · Adele
  ( hier einfügen )
```

## 8. Datenmodell

Die Zeitleiste eines Spielers wird nicht gespeichert, sondern aus seinen korrekten Platzierungen plus Startkarte berechnet.

| Tabelle | Felder | Hinweise |
| --- | --- | --- |
| `games` | id, code (6 Zeichen), name, admin_key, state, current_round, options (JSON), created_at | state als Enum |
| `songs` | id, game_id, title, artist, year, role, sort_order | role: `ROUND` / `START_POOL` / `TIEBREAKER` |
| `rounds` | id, game_id, song_id, number, opened_at, closed_at, revealed_at | eine Zeile pro gespieltem Stück |
| `players` | id, game_id, name, session_token, start_song_id, joined_at, last_seen_at | session_token im Cookie für Wiederverbindung |
| `placements` | id, round_id, player_id, position, submitted_at, is_correct | unique(round_id, player_id); is_correct beim Auflösen gesetzt |
| `tiebreaks` | id, game_id, player_id, guessed_year, submitted_at | unique(game_id, player_id) |

**Abgeleitete Werte**
- Zeitleiste = Startkarte + Songs aller Runden mit `is_correct = true`, sortiert nach Jahr.
- Punkte = 1 + Anzahl korrekter Platzierungen.
- `position` bezieht sich auf die Zeitleiste zum Zeitpunkt der Runde, also ohne die aktuelle Karte.

## 9. API und Echtzeit-Events

Inertia-Seiten für die drei Ansichten, JSON-Endpunkte für Aktionen, Reverb für Zustandsänderungen. Der Server ist die einzige Quelle der Wahrheit; die Lösung (Jahr, Titel) eines offenen Stücks wird nie an Spieler oder Leinwand gesendet.

**HTTP-Endpunkte (Auszug)**

| Methode | Pfad | Rolle | Zweck |
| --- | --- | --- | --- |
| POST | `/api/games/{code}/join` | Spieler | Name senden → player_id, session_token, Startkarte |
| GET | `/api/players/me` | Spieler | Eigene Zeitleiste, aktueller Zustand, eigene Abgabe |
| PUT | `/api/rounds/{id}/placement` | Spieler | position setzen/ändern (nur bei `ROUND_OPEN`) |
| POST | `/api/games/{id}/tiebreak` | Spieler | guessed_year abgeben (nur bei `TIEBREAKER`) |
| POST | `/api/host/games/{id}/advance` | Moderator | nächsten Zustandsübergang auslösen |
| POST | `/api/host/games/{id}/songs/import` | Moderator | CSV-Import der Songs |
| GET | `/api/games/{id}/leaderboard` | alle | Top 10 + Anzahl Spieler |

**Broadcast-Kanäle**

| Kanal | Typ | Empfänger |
| --- | --- | --- |
| `game.{id}` | public | alle Clients des Spiels |
| `game.{id}.host` | private (admin_key) | Moderator |
| `player.{id}` | private (session_token) | einzelner Spieler |

**Events (Server → Clients)**

| Event | Kanal | Payload |
| --- | --- | --- |
| `StateChanged` | `game.{id}` | state, round_number, total_rounds |
| `SubmissionsCount` | `game.{id}` | submitted, total_players (gedrosselt, max. 1×/s) |
| `RoundRevealed` | `game.{id}` | song (title, artist, year), percent_correct |
| `PlayerResult` | `player.{id}` | is_correct, correct_position, timeline, score, rank |
| `LeaderboardUpdated` | `game.{id}` | top10 [{name, score}] |
| `GameFinished` | `game.{id}` | podium, top10 |

Beim Auflösen wertet der Server alle Platzierungen in einer Transaktion aus und sendet danach die Events. Bei 500 Spielern werden `PlayerResult`-Events per Queue-Job gebündelt versendet.

## 10. Nicht-funktionale Anforderungen

- **Last:** 500 Spieler gleichzeitig; alle Abgaben einer Runde innerhalb weniger Sekunden.
- **Latenz:** Zustandswechsel kommen in unter 1 s auf allen Geräten an.
- **Netz:** Kleine Payloads; Erstaufruf der Spielerseite unter 300 KB.
- **Wiederverbindung:** Neu laden oder Verbindungsabbruch führt per session_token zurück zum selben Spieler, ohne Datenverlust. Fallback auf Polling alle 3 s, falls WebSocket scheitert.
- **Mobile:** Mobile-first, Bedienung mit einer Hand, Touch-Flächen min. 44 px, Dark Mode für den dunklen Saal.
- **Datenschutz (DSGVO):** Nur ein frei gewählter Anzeigename, keine E-Mail, kein Tracking. Löschung aller Spielerdaten 30 Tage nach Spielende. Kurzer Hinweis beim Beitritt.
- **Moderation:** Moderator kann anstößige Namen ausblenden; einfache Wortfilterliste.
- **Sicherheit:** Admin-Endpunkte nur mit admin_key; Rate Limit auf Join und Abgaben.

## 11. Sonderfälle

| Fall | Verhalten |
| --- | --- |
| Beitritt während einer offenen Runde | Spieler bekommt Startkarte und darf in der laufenden Runde noch abgeben |
| Beitritt nach dem Schließen einer Runde | Spieler steigt ab der nächsten Runde ein; verpasste Runden zählen nicht als Fehler |
| Beitritt nach Spielende | Hinweis „Spiel bereits beendet“, Anzeige der Rangliste |
| Abgabe nach dem Schließen | Server lehnt ab; Client zeigt „Zu spät“ |
| Mehrere Tabs/Geräte mit gleichem Token | Letzter Stand gilt; Abgaben werden überschrieben, nicht doppelt gezählt |
| Startkarten-Pool kleiner als Spielerzahl | Pool wird reihum mehrfach vergeben |
| Song-Jahr gleich dem einer Nachbarkarte | zählt als richtig, egal auf welcher Seite |
| Moderator lädt Seite neu | Zustand wird vom Server geladen, Spiel läuft weiter |
| Stück wird nicht gespielt | Moderator überspringt die Runde; sie wird für niemanden gewertet |
| Kein Tiebreaker nötig | Moderator kann direkt zu `FINISHED` springen |

## 12. Akzeptanzkriterien

- [ ] Beitritt per QR-Code und Namenseingabe in unter 15 Sekunden, ohne Konto.
- [ ] Jeder Spieler erhält eine Startkarte aus dem Pool; bei mehr Spielern als Pool-Songs wird reihum vergeben.
- [ ] Bei n Karten werden n+1 Lücken angezeigt; die Auswahl ist bis zum Schließen änderbar.
- [ ] Die Auswertung liefert für den Beispiel-Durchlauf exakt die dort genannten Ergebnisse (Pest-Test).
- [ ] Gleiches Jahr wie ein Nachbar wird als richtig gewertet (Pest-Test).
- [ ] Die Lösung eines offenen Stücks ist in keiner Antwort und keinem Event an Spieler oder Leinwand enthalten (Pest-Test).
- [ ] Zustandswechsel erscheinen bei 500 simulierten Clients in unter 1 s (k6-Lasttest).
- [ ] Neu laden auf dem Handy führt zurück zum selben Spieler mit gleicher Zeitleiste (Pest-Browsertest).
- [ ] Leinwand zeigt QR-Code, Rundenstatus, Auflösung und Top 10 ohne Bedienung.
- [ ] Songs lassen sich per CSV importieren (`titel;interpret;jahr;rolle`).
- [ ] Tiebreaker entscheidet Gleichstände nach kleinster Jahresabweichung, dann frühester Abgabe.
- [ ] Pint und Larastan laufen ohne Fehler.

## 13. Prompt für die KI-Entwicklung

Diesen Text zusammen mit dieser Datei an das KI-Werkzeug (z. B. Claude Code mit Laravel Boost) übergeben.

```
Entwickle die Web-App „TimeTune“ exakt nach der beigefügten Spezifikation.

Vorgehen:
1. Neues Projekt mit Laravel 13 und dem Vue Starter Kit anlegen, Pakete aus
   Abschnitt „Tech-Stack“ installieren, Laravel Boost einrichten.
2. Datenmodell, Migrationen, Enums und Models gemäß „Datenmodell“ anlegen.
3. Auswertungslogik als eigene, testbare Klasse implementieren und zuerst
   Pest-Tests schreiben, die den „Beispiel-Durchlauf“ exakt abbilden.
4. Zustandsmaschine aus „Spielzustände und Ablauf“ serverseitig implementieren.
5. Endpunkte, Broadcast-Kanäle und Events gemäß „API und Echtzeit-Events“
   mit Reverb und Laravel Echo umsetzen.
6. Die drei Ansichten Spieler, Moderator, Leinwand gemäß „Screens im Detail“
   als Inertia/Vue-Seiten bauen: Deutsch, mobile-first, Dark Mode.
7. Alle „Sonderfälle“ abdecken und jedes „Akzeptanzkriterium“ prüfen.
8. Seeder mit dem Beispiel-Durchlauf (4 Runden-Songs, 2 Startkarten,
   1 Tiebreaker-Song), k6-Lasttestskript und README mit Setup und
   Deployment (Forge, Supervisor für Reverb und Queue) liefern.

Die Lösung eines offenen Stücks darf nie an Spieler- oder Leinwand-Clients gehen.
Bei Unklarheiten: nachfragen statt annehmen.
```
