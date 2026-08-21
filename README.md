# Energy Dashboard – IP-Symcon Modul

Grafische HTML-SDK-Kachel für Solaranlage (OpenDTU) und Stromzähler, im selben dunklen Dashboard-Stil wie SunRiser8, BMW CarData, Weather Dashboard und Heating Dashboard. Liest ausschließlich bereits vorhandene Variablen — keine eigene API-Anbindung.

## Installation

Modulverwaltung → + → URL eintragen:
```
https://github.com/mwilkens780/IPSymcon-EnergyDashboard
```

## Konfiguration

Anders als bei den anderen Dashboard-Modulen gibt es hier **keine vorbelegten Objekt-IDs** — Solaranlage und Stromzähler haben pro Installation unterschiedliche IDs, die sich nicht automatisch ermitteln ließen. Bitte in der Instanzkonfiguration einmalig alle Variablen zuweisen (OpenDTU-Instanz "Fuchsbau" bzw. die Stromzähler-Instanz).

Für die Verlaufs-/Verbrauchsdiagramme wird eine **Archiv-Instanz** benötigt (Standard: die bereits im System vorhandene Archive-Control-Instanz). "Leistung AC", "Energie heute" und der Stromzähler-Zählerstand sollten dort archiviert sein.

## Funktionsweise

- **Solaranlage**: zeigt Leistung AC/DC, Spannung, Strom, Frequenz, Effizienz, Wechselrichter-Temperatur, Energie heute/gesamt sowie Status-Badges (Produziert, Erreichbar, OpenDTU Online). Die Leistungsbegrenzung relativ [%] lässt sich per Drehregler einstellen — die Schreibaktion wird über die globale IPS-Funktion `RequestAction()` an die OpenDTU-Instanz weitergereicht.
- **Stromzähler**: Da nur ein fortlaufender Zählerstand (Wh) zur Verfügung steht, berechnet das Modul Tages-/Wochen-/Monatsverbrauch sowie eine ungefähre aktuelle Leistung (W) selbst aus den archivierten Zählerstand-Differenzen (`AC_GetLoggedValues`). Ist eine echte "Aktuelle Leistung"-Variable vorhanden, kann sie optional direkt ausgewählt werden.

## Kachel einrichten

Die Kachel in einer Tile-Visualization platzieren, mindestens 4 Spalten × 7 Zeilen für vollständige Darstellung.
