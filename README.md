## XBEL Bookmarks Static Builder

Erzeugt eine statische HTML-Seite aus einem oder mehreren XBEL-Dateien.

### Voraussetzungen
- Python 3.10+ (nur Standardbibliothek)

### Nutzung
```bash
python xbel_static.py samples/example.xbel -o dist -t "Meine Bookmarks"
```
- `xbel_files`: eine oder mehrere `.xbel` Dateien
- `-o/--output`: Zielverzeichnis (Standard: `dist`), erzeugt mehrere HTML-Dateien
- `-t/--title`: Titel der erzeugten Seite

Das Skript legt im Zielverzeichnis eine `index.html` und pro Ordner-Knoten eine weitere HTML-Datei an. Jeder Ordner ist aus der übergeordneten Datei verlinkt.

### Beispiel
1. Beispiel-Datei: `samples/example.xbel`
2. Bauen:
   ```bash
   python xbel_static.py samples/example.xbel
   ```
3. Öffne `dist/index.html` im Browser; navigiere über die Ordner-Links zu den Unterseiten.

### Hinweise
- XBEL-Folder werden beibehalten; mehrere Eingabedateien werden unter dem gewählten Titel zusammengefasst. Jeder Ordner bekommt eine eigene HTML-Seite.
- Fehlende oder leere `<title>`-Elemente fallen auf Dateiname bzw. URL zurück.
