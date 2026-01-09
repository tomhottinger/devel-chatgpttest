<?php
/**
 * Generate a static HTML bookmarks page from one or more XBEL files.
 */

// Data structures ----------------------------------------------------------------

class Bookmark {
    public string $title;
    public string $href;
    public ?string $desc;
    public ?string $added;
    public ?string $modified;
    public ?string $id;

    public function __construct(string $title, string $href, ?string $desc = null, ?string $added = null, ?string $modified = null, ?string $id = null) {
        $this->title = $title;
        $this->href = $href;
        $this->desc = $desc;
        $this->added = $added;
        $this->modified = $modified;
        $this->id = $id;
    }
}

class Folder {
    public string $title;
    public array $children; // array of Folder|Bookmark
    public ?string $slug = null;
    public ?string $id;

    public function __construct(string $title, array $children = [], ?string $id = null) {
        $this->title = $title;
        $this->children = $children;
        $this->id = $id;
    }
}

// Parsing ------------------------------------------------------------------------

function parseFolder(SimpleXMLElement $elem, string $fallbackTitle): Folder {
    $title = (string)$elem->title ?: $fallbackTitle;
    $folderId = (string)$elem['id'] ?: null;
    $children = [];

    foreach ($elem->children() as $child) {
        if ($child->getName() === 'title') {
            continue;
        }
        if ($child->getName() === 'folder') {
            $children[] = parseFolder($child, 'Untitled folder');
        } elseif ($child->getName() === 'bookmark') {
            $href = (string)$child['href'];
            if (!$href) {
                continue;
            }
            $bookmarkTitle = (string)$child->title ?: $href;
            $desc = (string)$child->desc ?: null;
            $added = (string)$child['added'] ?: null;
            $modified = (string)$child['modified'] ?: null;
            $bookmarkId = (string)$child['id'] ?: null;

            $children[] = new Bookmark($bookmarkTitle, $href, $desc, $added, $modified, $bookmarkId);
        }
    }

    return new Folder($title, $children, $folderId);
}

function parseXbelFile(string $path): Folder {
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        throw new Exception("Failed to parse XBEL file: $path");
    }
    $pathInfo = pathinfo($path);
    return parseFolder($xml, $pathInfo['filename']);
}

// Rendering ----------------------------------------------------------------------

function discoverThemes(string $scriptDir): array {
    $cssDir = $scriptDir . '/style/css';
    if (!is_dir($cssDir)) {
        return [];
    }

    $themes = [];
    $files = glob($cssDir . '/bookmarks-*.css');

    foreach ($files as $cssFile) {
        $filename = basename($cssFile);

        // Skip the base stylesheet
        if ($filename === 'bookmarks-base.css') {
            continue;
        }

        // Convert filename to display name: bookmarks-cosmic-traveler.css -> Cosmic Traveler
        $namePart = str_replace(['bookmarks-', '.css'], '', $filename);
        $displayName = ucwords(str_replace('-', ' ', $namePart));
        $themes[] = [$filename, $displayName];
    }

    // Sort by display name
    usort($themes, function($a, $b) {
        return strcmp($a[1], $b[1]);
    });

    return $themes;
}

function formatTimestamp(?string $value): ?string {
    if (!$value) {
        return null;
    }
    try {
        // XBEL timestamps are typically ISO-8601. Preserve date only for readability.
        $dt = new DateTime($value);
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return $value;
    }
}

function renderBookmarkTile(Bookmark $bookmark): string {
    $desc = $bookmark->desc ? '<div class="tile-desc">' . htmlspecialchars($bookmark->desc, ENT_QUOTES, 'UTF-8') . '</div>' : '';
    $added = formatTimestamp($bookmark->added);
    $modified = formatTimestamp($bookmark->modified);

    $metaParts = [];
    if ($added) {
        $metaParts[] = "added $added";
    }
    if ($modified) {
        $metaParts[] = "updated $modified";
    }
    $meta = $metaParts ? '<div class="tile-meta">' . implode(' · ', $metaParts) . '</div>' : '';

    $idAttr = $bookmark->id ? ' data-id="' . htmlspecialchars($bookmark->id, ENT_QUOTES, 'UTF-8') . '"' : '';

    return '<a class="tile bookmark-tile" href="' . htmlspecialchars($bookmark->href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noreferrer noopener" draggable="true"' . $idAttr . '>'
        . '<div class="tile-title">' . htmlspecialchars($bookmark->title, ENT_QUOTES, 'UTF-8') . '</div>'
        . $desc . $meta
        . '</a>';
}

function renderSubfolderTile(Folder $folder): string {
    $idAttr = $folder->id ? ' data-id="' . htmlspecialchars($folder->id, ENT_QUOTES, 'UTF-8') . '"' : '';

    return '<a class="tile folder-tile" href="' . htmlspecialchars($folder->slug, ENT_QUOTES, 'UTF-8') . '" draggable="true"' . $idAttr . '>'
        . '<div class="tile-title">' . htmlspecialchars($folder->title, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div class="tile-meta">Ordner öffnen</div>'
        . '</a>';
}

function renderPage(Folder $folder, string $pageTitle, ?string $parentLink, array $themes, string $currentPage = 'index.html'): string {
    $now = gmdate('Y-m-d H:i') . ' UTC';

    // Use discovered themes or fallback to a default if none found
    if (empty($themes)) {
        $themes = [['bookmarks-base.css', 'Default']];
    }

    $themesJs = json_encode($themes);
    $themeOptions = '';
    foreach ($themes as $i => $theme) {
        list($filename, $label) = $theme;
        $selected = $i === 0 ? ' selected' : '';
        $themeOptions .= '<option value="' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    $themeSwitch = '<div class="theme-switch-menu">'
        . '<label for="theme-select">Style:</label>'
        . '<select id="theme-select" aria-label="Darstellung wählen">' . $themeOptions . '</select>'
        . '</div>';

    // Create reload link
    $reloadUrl = '../index.php?return=' . htmlspecialchars($currentPage, ENT_QUOTES, 'UTF-8');

    $bookmarkItems = '';
    $subfolderItems = '';

    foreach ($folder->children as $child) {
        if ($child instanceof Bookmark) {
            $bookmarkItems .= renderBookmarkTile($child);
        } elseif ($child instanceof Folder) {
            $subfolderItems .= renderSubfolderTile($child);
        }
    }

    $bookmarksSection = $bookmarkItems
        ? '<div class="tile-grid">' . $bookmarkItems . '</div>'
        : '<p class="muted">Keine Bookmarks.</p>';

    $subfoldersSection = $subfolderItems
        ? '<div class="tile-grid">' . $subfolderItems . '</div>'
        : '<p class="muted">Keine Unterordner.</p>';

    $parentNav = $parentLink
        ? '<a class="parent" href="' . htmlspecialchars($parentLink, ENT_QUOTES, 'UTF-8') . '">↩ Zurück</a>'
        : '';

    // Use the first theme in the list as the default initial theme
    $defaultTheme = !empty($themes) ? $themes[0][0] : 'bookmarks-base.css';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>{$folder->title} – {$pageTitle}</title>
  <link rel="stylesheet" href="../style/css/bookmarks-base.css">
  <link id="themeStylesheet" rel="stylesheet" href="../style/css/{$defaultTheme}">
</head>
<body>
  <header>
    <div class="badge">Bookmarks</div>
    <div>
      <h1><a href="{$reloadUrl}" class="reload-link" title="Bookmarks neu laden">{$folder->title}</a></h1>
      <div class="updated">Updated {$now}</div>
    </div>
    <div class="header-actions">
      {$parentNav}
      <button id="burger-menu-btn" class="burger-btn" aria-label="Menü öffnen">
        <span></span>
        <span></span>
        <span></span>
      </button>
    </div>
  </header>

  <div id="burger-menu" class="burger-menu">
    <div class="burger-menu-content">
      <button id="burger-close" class="burger-close" aria-label="Menü schliessen">&times;</button>

      <div class="menu-section">
        <h3>Darstellung</h3>
        {$themeSwitch}
      </div>

      <div class="menu-section">
        <h3>Aktionen</h3>
        <button id="reload-btn" class="menu-button">
          <span>🔄</span> Bookmarks neu laden
        </button>
      </div>

      <div class="menu-section">
        <h3>Reihenfolge</h3>
        <button id="reset-order-btn" class="menu-button">
          <span>↺</span> Reihenfolge zurücksetzen
        </button>
        <button id="export-order-btn" class="menu-button">
          <span>⬇</span> Reihenfolge exportieren
        </button>
        <button id="import-order-btn" class="menu-button">
          <span>⬆</span> Reihenfolge importieren
        </button>
        <input type="file" id="import-order-input" accept=".json" style="display: none;">
      </div>
    </div>
  </div>
  <main>
    <section>
      <h2>Bookmarks</h2>
      {$bookmarksSection}
    </section>
    <section>
      <h2>Ordner</h2>
      {$subfoldersSection}
    </section>
  </main>
  <script>
    (() => {
      // Burger menu
      const burgerBtn = document.getElementById("burger-menu-btn");
      const burgerMenu = document.getElementById("burger-menu");
      const burgerClose = document.getElementById("burger-close");

      function openMenu() {
        burgerMenu.classList.add("open");
      }

      function closeMenu() {
        burgerMenu.classList.remove("open");
      }

      burgerBtn.addEventListener("click", openMenu);
      burgerClose.addEventListener("click", closeMenu);

      // Close menu when clicking outside
      burgerMenu.addEventListener("click", (e) => {
        if (e.target === burgerMenu) {
          closeMenu();
        }
      });

      // Close menu on Escape key
      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && burgerMenu.classList.contains("open")) {
          closeMenu();
        }
      });

      // Theme switcher
      const themes = {$themesJs};
      const select = document.getElementById("theme-select");
      const link = document.getElementById("themeStylesheet");
      const basePath = "../style/css/";
      const stored = localStorage.getItem("bookmarkTheme");
      const validValues = themes.map(([file]) => file);
      const initial = validValues.includes(stored) ? stored : select.value;
      link.href = basePath + initial;
      select.value = initial;
      select.addEventListener("change", () => {
        const value = select.value;
        link.href = basePath + value;
        localStorage.setItem("bookmarkTheme", value);
      });

      // Drag & drop reordering
      const currentPage = "{$folder->slug}";
      const orderKey = "tileOrder_" + currentPage;

      // Apply saved order
      function applyOrder() {
        const savedOrder = localStorage.getItem(orderKey);
        if (!savedOrder) return;

        const order = JSON.parse(savedOrder);
        const grids = document.querySelectorAll(".tile-grid");

        grids.forEach(grid => {
          const tiles = Array.from(grid.querySelectorAll(".tile[data-id]"));
          const tileMap = new Map(tiles.map(t => [t.dataset.id, t]));

          // Reorder tiles based on saved order
          order.forEach(id => {
            const tile = tileMap.get(id);
            if (tile && tile.parentNode === grid) {
              grid.appendChild(tile);
            }
          });
        });
      }

      // Save current order
      function saveOrder() {
        const grids = document.querySelectorAll(".tile-grid");
        const order = [];

        grids.forEach(grid => {
          const tiles = grid.querySelectorAll(".tile[data-id]");
          tiles.forEach(tile => {
            if (tile.dataset.id) {
              order.push(tile.dataset.id);
            }
          });
        });

        localStorage.setItem(orderKey, JSON.stringify(order));
      }

      // Drag & drop handlers
      let draggedElement = null;

      document.addEventListener("dragstart", (e) => {
        if (e.target.classList.contains("tile") && e.target.dataset.id) {
          draggedElement = e.target;
          e.target.style.opacity = "0.5";
        }
      });

      document.addEventListener("dragend", (e) => {
        if (e.target.classList.contains("tile")) {
          e.target.style.opacity = "";
          draggedElement = null;
        }
      });

      document.addEventListener("dragover", (e) => {
        e.preventDefault();
        const target = e.target.closest(".tile");
        if (target && target.dataset.id && draggedElement && target !== draggedElement) {
          const grid = target.parentNode;
          if (grid.classList.contains("tile-grid") && draggedElement.parentNode === grid) {
            const rect = target.getBoundingClientRect();
            const midpoint = rect.left + rect.width / 2;

            if (e.clientX < midpoint) {
              grid.insertBefore(draggedElement, target);
            } else {
              grid.insertBefore(draggedElement, target.nextSibling);
            }
          }
        }
      });

      document.addEventListener("drop", (e) => {
        e.preventDefault();
        saveOrder();
      });

      // Apply saved order on load
      applyOrder();

      // Reload button
      document.getElementById("reload-btn").addEventListener("click", () => {
        window.location.href = "{$reloadUrl}";
      });

      // Reset order button
      document.getElementById("reset-order-btn").addEventListener("click", () => {
        if (confirm("Möchten Sie die Reihenfolge wirklich zurücksetzen?")) {
          localStorage.removeItem(orderKey);
          location.reload();
        }
      });

      // Export order button
      document.getElementById("export-order-btn").addEventListener("click", () => {
        const savedOrder = localStorage.getItem(orderKey);
        if (!savedOrder) {
          alert("Keine gespeicherte Reihenfolge vorhanden.");
          return;
        }

        const data = {
          page: currentPage,
          order: JSON.parse(savedOrder),
          exported: new Date().toISOString()
        };

        const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = "tile-order-" + currentPage.replace(".html", "") + ".json";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      });

      // Import order button
      document.getElementById("import-order-btn").addEventListener("click", () => {
        document.getElementById("import-order-input").click();
      });

      document.getElementById("import-order-input").addEventListener("change", (e) => {
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (event) => {
          try {
            const data = JSON.parse(event.target.result);

            if (!data.order || !Array.isArray(data.order)) {
              alert("Ungültiges Dateiformat.");
              return;
            }

            localStorage.setItem(orderKey, JSON.stringify(data.order));
            location.reload();
          } catch (err) {
            alert("Fehler beim Laden der Datei: " + err.message);
          }
        };
        reader.readAsText(file);

        // Reset input so same file can be selected again
        e.target.value = "";
      });
    })();
  </script>
</body>
</html>
HTML;
}

// Site generation ----------------------------------------------------------------

function slugify(string $title): string {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
    $slug = trim($slug, '-');
    return $slug ?: 'folder';
}

function uniqueName(string $base, array &$used): string {
    $candidate = "$base.html";
    $counter = 2;
    while (in_array($candidate, $used)) {
        $candidate = "$base-$counter.html";
        $counter++;
    }
    $used[] = $candidate;
    return $candidate;
}

function assignSlugs(Folder $root): void {
    $used = [];
    $root->slug = 'index.html';
    $used[] = $root->slug;

    $walk = function(Folder $folder) use (&$walk, &$used) {
        foreach ($folder->children as $child) {
            if ($child instanceof Folder) {
                $child->slug = uniqueName(slugify($child->title), $used);
                $walk($child);
            }
        }
    };

    $walk($root);
}

function writeFolderPages(Folder $folder, string $outputDir, string $pageTitle, ?string $parentLink, array $themes): void {
    if ($folder->slug === null) {
        throw new Exception("Slug must be assigned before writing pages");
    }

    $html = renderPage($folder, $pageTitle, $parentLink, $themes, $folder->slug);

    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    file_put_contents($outputDir . '/' . $folder->slug, $html);

    foreach ($folder->children as $child) {
        if ($child instanceof Folder) {
            writeFolderPages($child, $outputDir, $pageTitle, $folder->slug, $themes);
        }
    }
}

function buildSite(array $xbelPaths, string $outputDir, string $pageTitle): string {
    $scriptDir = __DIR__;
    $themes = discoverThemes($scriptDir);

    if (function_exists('logMessage')) {
        logMessage("  Building site from " . count($xbelPaths) . " XBEL file(s)");
        logMessage("  Found " . count($themes) . " theme(s)");
    }

    $folders = [];
    foreach ($xbelPaths as $path) {
        $folder = parseXbelFile($path);
        $folders[] = $folder;
        if (function_exists('logMessage')) {
            logMessage("  Parsed: " . basename($path) . " (title: " . $folder->title . ")");
        }
    }

    $root = new Folder($pageTitle, $folders);
    assignSlugs($root);

    if (function_exists('logMessage')) {
        logMessage("  Writing HTML files to: $outputDir");
    }

    writeFolderPages($root, $outputDir, $pageTitle, null, $themes);

    if (function_exists('logMessage')) {
        logMessage("  Generated index.html and subpages");
    }

    return $outputDir . '/index.html';
}

// CLI ----------------------------------------------------------------------------

function main(): void {
    global $argv;

    // Simple argument parsing
    $xbelFiles = [];
    $outputDir = 'dist';
    $pageTitle = 'My Bookmarks';

    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '-o' || $argv[$i] === '--output') {
            $outputDir = $argv[++$i] ?? 'dist';
        } elseif ($argv[$i] === '-t' || $argv[$i] === '--title') {
            $pageTitle = $argv[++$i] ?? 'My Bookmarks';
        } elseif (!str_starts_with($argv[$i], '-')) {
            // Expand glob patterns
            $matches = glob($argv[$i]);
            if ($matches) {
                $xbelFiles = array_merge($xbelFiles, $matches);
            } else {
                $xbelFiles[] = $argv[$i];
            }
        }
    }

    if (empty($xbelFiles)) {
        echo "Usage: php xbel_static.php [options] <xbel_files...>\n";
        echo "Options:\n";
        echo "  -o, --output DIR    Output directory (default: dist)\n";
        echo "  -t, --title TITLE   Page title (default: My Bookmarks)\n";
        exit(1);
    }

    // Check if files exist
    $missing = [];
    foreach ($xbelFiles as $file) {
        if (!file_exists($file)) {
            $missing[] = $file;
        }
    }

    if (!empty($missing)) {
        echo "Missing input files: " . implode(', ', $missing) . "\n";
        exit(1);
    }

    $outputPath = buildSite($xbelFiles, $outputDir, $pageTitle);
    echo "Wrote $outputPath and subpages\n";
}

// Run if called from command line
if (php_sapi_name() === 'cli' && basename($argv[0]) === 'xbel_static.php') {
    main();
}
