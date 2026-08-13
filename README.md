# Texteditor (files_texteditor)

Die App öffnet Textdateien aus dem Dateibereich von owncloud.online direkt im
Browser und speichert Änderungen zurück in die Datei. Grundlage ist der
Editor Ace mit Syntaxhervorhebung. Solange eine Datei im Editor geöffnet ist,
wird sie für andere Bearbeiter gesperrt, sofern die Speicheranbindung
dauerhafte Sperren unterstützt.

## Was die App tut

- Ein Klick auf eine Textdatei öffnet den Editor. Die App trägt sich für die
  unten genannten Dateitypen als Standardaktion ein; über das Aktionsmenü der
  Datei heißt der Eintrag „In Texteditor öffnen".
- Über das „+"-Menü der Dateiliste legt der Eintrag „Textdatei" eine neue
  Datei an und öffnet sie sofort im Editor. Dieser Eintrag erscheint nur in
  der normalen Dateiliste, nicht in der Ansicht öffentlicher Links.
- Gespeichert wird automatisch drei Sekunden nach der letzten Änderung.
  Zusätzlich speichert `Strg+S` bzw. `Cmd+S` sofort. Der Stand wird oben in
  der Leiste angezeigt („speichern…", „gespeichert!", „fehlgeschlagen!").
- Ungespeicherte Änderungen erkennen Sie am Sternchen neben dem Dateinamen
  und im Titel des Browser-Tabs.
- Der Editor arbeitet auch in öffentlichen Links, sofern die Freigabe
  Schreibrechte hat. Bei passwortgeschützten Links muss das Passwort in
  derselben Sitzung bereits eingegeben worden sein.
- Geschlossen wird über die Schaltfläche mit dem Kreuz rechts in der Leiste
  über dem Editor. Ein Klick neben den Editorbereich und die Zurück-Taste des
  Browsers schließen ihn ebenfalls. Erst dabei wird die Sperre auf der Datei
  freigegeben.

## Unterstützte Dateitypen

Die App meldet sich für diese MIME-Typen an:

- `text` — damit alle Dateitypen, deren MIME-Typ mit `text/` beginnt,
  also z. B. `text/plain`, `text/markdown`, `text/html`, `text/css`
- `text/plain`
- `application/javascript`
- `application/json`
- `application/xml`
- `application/x-empty` (leere Dateien)
- `application/x-php`
- `application/x-pearl`
- `application/x-text`
- `application/yaml`

Maßgeblich ist der MIME-Typ, den owncloud.online der Datei zuordnet. Eine
Datei ohne einen dieser MIME-Typen lässt sich nicht im Editor öffnen.

Die Syntaxhervorhebung richtet sich dagegen nach der Dateiendung. Erkannt
werden `bat`, `c`, `clj`, `cmd`, `coffee`, `cpp`, `cs`, `css`, `groovy`, `h`,
`htm`, `html`, `ily`, `java`, `js`, `jsm`, `json`, `latex`, `less`, `lua`,
`ly`, `markdown`, `md`, `mdown`, `mdwn`, `mkd`, `ml`, `mli`, `php`, `pl`,
`ps1`, `py`, `rb`, `scad`, `scala`, `scss`, `sh`, `sql`, `svg`, `tex`,
`textile`, `tt` und `xml`. Dateien mit dem MIME-Typ
`text/html` werden immer als HTML hervorgehoben. Für alle übrigen Endungen
öffnet der Editor die Datei ohne Hervorhebung; bearbeiten lässt sie sich
trotzdem.

## Gleichzeitiges Bearbeiten

Die App ist kein Mehrbenutzer-Editor. Zwei Personen bearbeiten dieselbe Datei
nicht gemeinsam, sondern nacheinander. Dafür greifen zwei Mechanismen:

1. **Sperre beim Öffnen.** Beim Öffnen setzt die App eine dauerhafte Sperre
   auf die Datei, sofern die Speicheranbindung dauerhafte Sperren
   unterstützt. Öffnet danach jemand anderes dieselbe Datei, erhält diese
   Person die Datei nur lesend, mit dem Hinweis „Datei ist schreibgeschützt,
   blockiert von …" und dem Namen des Sperrenden.
2. **Prüfung beim Speichern.** Beim Speichern vergleicht der Server das
   Änderungsdatum der Datei mit dem Stand beim Öffnen. Weicht es ab, wird
   nicht gespeichert, sondern „Die Datei konnte nicht gespeichert werden,
   weil sie seit dem Öffnen verändert worden ist" gemeldet. Bestehende
   Änderungen anderer werden also nicht überschrieben.

Die Sperre wird freigegeben, wenn Sie den Editor über die Schaltfläche
schließen. Wird stattdessen der Browser-Tab geschlossen, warnt der Browser
vorher; wird die Warnung übergangen, bleibt die Sperre bis zu ihrem Ablauf
bestehen. Ist die Datei bereits durch einen anderen Vorgang gesperrt, meldet
der Editor „Die Datei ist gesperrt."

## Größengrenze und Zeichenkodierung

Der Editor öffnet Dateien bis **4 MiB (4.194.304 Byte)**. Größere Dateien
weist der Server mit „Diese Datei ist zu groß. Bitte lade die Datei
stattdessen herunter." ab. Die Grenze ist im Code festgelegt und lässt sich
nicht konfigurieren.

Gearbeitet wird in UTF-8. Der Server erkennt daneben unter anderem GB2312,
GBK, BIG5, Windows-1252, SJIS-win, EUC-JP, ISO-8859-15, ISO-8859-1 und ASCII
und rechnet den Inhalt zur Anzeige nach UTF-8 um. **Dateien, die nicht schon
UTF-8 sind, werden dabei nur lesend geöffnet**, damit die Umwandlung die
Datei nicht verändert. Lässt sich die Kodierung nicht umrechnen, erscheint
„Kodierung kann nicht in UTF-8 umgewandelt werden."

## Voraussetzungen

- owncloud.online 11.0 bis 11.99
- PHP 8.4 oder neuer

## Installation

Der einfachere Weg ist die Installation über den Markt in der
Apps-Verwaltung. Von Hand:

    cd /var/www/owncloud.online/apps
    git clone https://github.com/BWTECH-github/files_texteditor.git
    cd files_texteditor
    composer install --no-dev
    chown -R www-data:www-data .
    sudo -u www-data php8.4 ../../occ app:enable files_texteditor

`composer install --no-dev` ist nötig, weil die App beim Laden einer Datei
ihren eigenen Autoloader einbindet. Fehlt das Verzeichnis `vendor`, schlägt
jeder Aufruf des Editors fehl.

Die App ist als standardmäßig aktiv gekennzeichnet und wird nach der
Installation ohne weiteres Zutun angeboten.

## Einstellungen

Die App hat keine Einstellungsseite und legt keine eigenen
Konfigurationsschlüssel an — weder in der App-Konfiguration noch in der
`config.php`. Größengrenze (4 MiB), Zeitpunkt des automatischen Speicherns
(drei Sekunden) und Farbschema sind fest im Code hinterlegt.

Steuern lässt sich damit nur, ob die App überhaupt läuft:

    sudo -u www-data php8.4 occ app:disable files_texteditor
    sudo -u www-data php8.4 occ app:enable files_texteditor

Eigene `occ`-Befehle bringt die App nicht mit.

Wer bearbeiten darf, ergibt sich aus den normalen Rechten: Ohne
Schreibrecht — auch bei einer Freigabe ohne Änderungsrecht — öffnet der
Editor die Datei nur lesend.

## Fehlersuche

Die Meldungen sind hier so zitiert, wie sie die Sprache „Deutsch" ausgibt.
Unter „Deutsch (Deutschland)" weichen einige Formulierungen ab, etwa „Die
Datei ist zu groß zum öffnen." statt „Diese Datei ist zu groß.".

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| „Diese Datei ist zu groß." | Datei über 4 MiB | Datei herunterladen, lokal bearbeiten, wieder hochladen |
| „Datei ist schreibgeschützt, blockiert von …" | Jemand anderes hat die Datei im Editor offen | Warten, bis der Editor dort geschlossen wird oder die Sperre abläuft |
| „Die Datei konnte nicht gespeichert werden, weil sie seit dem Öffnen verändert worden ist" | Die Datei wurde zwischenzeitlich von anderer Seite geändert | Eigenen Text zwischenspeichern, Editor schließen, Datei neu öffnen, Änderungen erneut einarbeiten |
| Editor öffnet nur lesend, ohne Sperrhinweis | Datei ist nicht UTF-8 kodiert, oder das Schreibrecht fehlt | Datei lokal nach UTF-8 wandeln; Freigabe- und Ordnerrechte prüfen |
| „Kodierung kann nicht in UTF-8 umgewandelt werden." | Kodierung nicht erkannt oder nicht umrechenbar, etwa bei Binärdateien | Datei lokal nach UTF-8 wandeln |
| Klick auf die Textdatei lädt sie herunter, statt den Editor zu öffnen | App nicht aktiviert, oder der MIME-Typ der Datei gehört nicht zu den unterstützten | `occ app:enable files_texteditor`; MIME-Typ der Datei prüfen |
| Editor bleibt beim Öffnen im Ladezustand hängen | Verzeichnis `vendor` fehlt oder ist unvollständig, der Server bricht die Anfrage mit einem Fehler ab | `composer install --no-dev` im App-Verzeichnis nachholen, Server-Log prüfen |
| „Ungültiger gemeinsamer Schlüssel" oder „Kein Benutzer gefunden" | Sitzung abgelaufen oder Freigabe entfernt | Seite neu laden und erneut anmelden bzw. den Link prüfen |
| Beim Schließen des Tabs warnt der Browser vor Datenverlust | Der Editor ist noch offen, die Sperre steht noch | Editor über die Schaltfläche schließen, statt den Tab zu schließen |

Serverseitige Meldungen protokolliert die App im Server-Log unter dem
App-Namen `files_texteditor`.

## Vorschau-Erweiterungen für andere Apps

Andere Apps können neben dem Editor eine Vorschau einblenden. Dazu
registrieren sie ein Objekt mit den Methoden `init()` und
`preview(text, previewElement)` für einen MIME-Typ:

```js
OCA.MYApp.Preview = function () {
    // ...
};

OCA.MYApp.Preview.prototype = {
    // Vorbereitung: eigene Ressourcen laden
    init: function () {
        // ...
    },
    // wird bei jeder Aenderung mit dem aktuellen Text aufgerufen
    preview: function (text, previewElement) {
        // ...
    }
};

OCA.Files_Texteditor.registerPreviewPlugin(
    'text/markdown',
    new OCA.MYApp.Preview()
);
```

Das Vorschau-Element hat die ID `preview`. Zusätzlich erhält es den MIME-Typ
der bearbeiteten Datei als Klasse, wobei der Schrägstrich durch einen
Bindestrich ersetzt wird. Eine Markdown-Vorschau sprechen Sie also über
`#preview.text-markdown` an.

## Herkunft

Die App geht auf „Text Editor" der ownCloud GmbH zurück und steht unter der
AGPL-3.0. Als Editor-Komponente kommt Ace zum Einsatz. Die BW-Tech GmbH hat
die App für owncloud.online und PHP 8.4 angepasst.

- Quelltext und Fehlermeldungen:
  https://github.com/BWTECH-github/files_texteditor
- Dokumentation: https://docs.owncloud.online
- Produkt: https://owncloud.online
