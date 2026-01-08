#!/usr/bin/env python3
"""
Generate a static HTML bookmarks page from one or more XBEL files.
"""

from __future__ import annotations

import argparse
from dataclasses import dataclass
from datetime import datetime, timezone
from html import escape
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET


# Data structures ----------------------------------------------------------------

@dataclass
class Bookmark:
    title: str
    href: str
    desc: str | None = None
    added: str | None = None
    modified: str | None = None


@dataclass
class Folder:
    title: str
    children: list["Folder | Bookmark"]
    slug: str | None = None


# Parsing ------------------------------------------------------------------------

def _text_or_none(elem: ET.Element | None) -> str | None:
    if elem is None or elem.text is None:
        return None
    return elem.text.strip() or None


def _parse_folder(elem: ET.Element, fallback_title: str) -> Folder:
    title = _text_or_none(elem.find("title")) or fallback_title
    children: list[Folder | Bookmark] = []

    for child in elem:
        if child.tag == "title":
            continue
        if child.tag == "folder":
            children.append(_parse_folder(child, fallback_title="Untitled folder"))
        elif child.tag == "bookmark":
            href = child.get("href")
            if not href:
                continue
            children.append(
                Bookmark(
                    title=_text_or_none(child.find("title")) or href,
                    href=href,
                    desc=_text_or_none(child.find("desc")),
                    added=child.get("added"),
                    modified=child.get("modified"),
                )
            )

    return Folder(title=title, children=children)


def parse_xbel_file(path: Path) -> Folder:
    tree = ET.parse(path)
    root = tree.getroot()
    return _parse_folder(root, fallback_title=path.stem)


# Rendering ----------------------------------------------------------------------

def _format_timestamp(value: str | None) -> str | None:
    if not value:
        return None
    try:
        # XBEL timestamps are typically ISO-8601. Preserve date only for readability.
        dt = datetime.fromisoformat(value.replace("Z", "+00:00"))
        return dt.date().isoformat()
    except ValueError:
        return value


def _render_bookmark_tile(bookmark: Bookmark) -> str:
    desc = f'<div class="tile-desc">{escape(bookmark.desc)}</div>' if bookmark.desc else ""
    added = _format_timestamp(bookmark.added)
    modified = _format_timestamp(bookmark.modified)
    meta_parts = []
    if added:
        meta_parts.append(f"added {added}")
    if modified:
        meta_parts.append(f"updated {modified}")
    meta = f'<div class="tile-meta">{" · ".join(meta_parts)}</div>' if meta_parts else ""
    return (
        f'<a class="tile bookmark-tile" href="{escape(bookmark.href)}" target="_blank" rel="noreferrer noopener">'
        f'<div class="tile-title">{escape(bookmark.title)}</div>'
        f"{desc}{meta}"
        "</a>"
    )


def _render_subfolder_tile(folder: Folder) -> str:
    assert folder.slug, "Slug must be assigned before rendering"
    return (
        f'<a class="tile folder-tile" href="{escape(folder.slug)}">'
        f'<div class="tile-title">{escape(folder.title)}</div>'
        '<div class="tile-meta">Ordner öffnen</div>'
        "</a>"
    )


def render_page(folder: Folder, page_title: str, parent_link: str | None) -> str:
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    themes = [
        ("bookmarks-misty.css", "Misty Forrest"),
        ("bookmarks-aurora.css", "Aurora"),
        ("bookmarks-beach.css", "Beach Sunset"),
        ("bookmarks-amvf.css", "AMVF"),
        ("bookmarks-apocalypse.css", "Apocalypse"),
        ("bookmarks-beach-beauty.css", "Beach Beauty"),
        ("bookmarks-beach-beauty-old.css", "Beach Beauty (old)"),
        ("bookmarks-beauty.css", "Beauty"),
        ("bookmarks-blue-beach.css", "Blue Beach"),
        ("bookmarks-bluechick.css", "Bluechick"),
        ("bookmarks-bluechick2.css", "Bluechick 2"),
        ("bookmarks-climber.css", "Climber"),
        ("bookmarks-cosmic-traveler.css", "Cosmic Traveler"),
        ("bookmarks-dark-cat.css", "Dark Cat"),
        ("bookmarks-fat-cat.css", "Fat Cat"),
        ("bookmarks-galactic-joshua.css", "Galactic Joshua"),
        ("bookmarks-golden-gate.css", "Golden Gate"),
        ("bookmarks-healer.css", "Healer"),
        ("bookmarks-it-chick.css", "IT-Chick"),
        ("bookmarks-it-guy.css", "IT-Guy"),
        ("bookmarks-la-sera-sper-il-lag.css", "La Sera sper il Lag"),
        ("bookmarks-last-hope.css", "Last Hope"),
        ("bookmarks-meditating-woman.css", "Meditating Woman"),
        ("bookmarks-meditation.css", "Meditation"),
        ("bookmarks-red-fox.css", "Red Fox"),
        ("bookmarks-red-fox-old.css", "Red Fox (old)"),
        ("bookmarks-the-fly.css", "The Fly"),
        ("bookmarks-windy-beach.css", "Windy Beach"),
    ]
    themes_js = json.dumps(themes)
    theme_options = "".join(
        f'<option value="{escape(filename)}"{" selected" if i == 0 else ""}>{escape(label)}</option>'
        for i, (filename, label) in enumerate(themes)
    )
    theme_switch = (
        '<div class="theme-switch">'
        '<label for="theme-select">Style:</label>'
        f'<select id="theme-select" aria-label="Darstellung wählen">{theme_options}</select>'
        "</div>"
    )

    bookmark_items = "".join(
        _render_bookmark_tile(child) for child in folder.children if isinstance(child, Bookmark)
    )
    subfolder_items = "".join(
        _render_subfolder_tile(child) for child in folder.children if isinstance(child, Folder)
    )

    bookmarks_section = (
        f"<div class=\"tile-grid\">{bookmark_items}</div>"
        if bookmark_items
        else "<p class=\"muted\">Keine Bookmarks.</p>"
    )
    subfolders_section = (
        f"<div class=\"tile-grid\">{subfolder_items}</div>"
        if subfolder_items
        else "<p class=\"muted\">Keine Unterordner.</p>"
    )

    parent_nav = (
        f'<a class="parent" href="{escape(parent_link)}">↩ Zurück</a>' if parent_link else ""
    )

    return f"""<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{escape(folder.title)} – {escape(page_title)}</title>
  <link rel="stylesheet" href="../style/css/bookmarks-base.css">
  <link id="themeStylesheet" rel="stylesheet" href="../style/css/bookmarks-misty.css">
</head>
<body>
  <header>
    <div class="badge">Bookmarks</div>
    <div>
      <h1>{escape(folder.title)}</h1>
      <div class="updated">Updated {escape(now)}</div>
    </div>
    <div class="header-actions">
      {theme_switch}
      {parent_nav}
    </div>
  </header>
  <main>
    <section>
      <h2>Bookmarks</h2>
      {bookmarks_section}
    </section>
    <section>
      <h2>Ordner</h2>
      {subfolders_section}
    </section>
  </main>
  <script>
    (() => {{
      const themes = {themes_js};
      const select = document.getElementById("theme-select");
      const link = document.getElementById("themeStylesheet");
      const basePath = "../style/css/";
      const stored = localStorage.getItem("bookmarkTheme");
      const validValues = themes.map(([file]) => file);
      const initial = validValues.includes(stored) ? stored : select.value;
      link.href = basePath + initial;
      select.value = initial;
      select.addEventListener("change", () => {{
        const value = select.value;
        link.href = basePath + value;
        localStorage.setItem("bookmarkTheme", value);
      }});
    }})();
  </script>
</body>
</html>
"""


# Site generation ----------------------------------------------------------------

def _slugify(title: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-")
    return slug or "folder"


def _unique_name(base: str, used: set[str]) -> str:
    candidate = f"{base}.html"
    counter = 2
    while candidate in used:
        candidate = f"{base}-{counter}.html"
        counter += 1
    used.add(candidate)
    return candidate


def _assign_slugs(root: Folder) -> None:
    used: set[str] = set()
    root.slug = "index.html"
    used.add(root.slug)

    def walk(folder: Folder) -> None:
        for child in folder.children:
            if isinstance(child, Folder):
                child.slug = _unique_name(_slugify(child.title), used)
                walk(child)

    walk(root)


def _write_folder_pages(folder: Folder, output_dir: Path, page_title: str, parent_link: str | None) -> None:
    assert folder.slug, "Slug must be assigned before writing pages"
    html = render_page(folder, page_title, parent_link)
    output_dir.mkdir(parents=True, exist_ok=True)
    (output_dir / folder.slug).write_text(html, encoding="utf-8")

    for child in folder.children:
        if isinstance(child, Folder):
            _write_folder_pages(child, output_dir, page_title, parent_link=folder.slug)


def build_site(xbel_paths: list[Path], output_dir: Path, page_title: str) -> Path:
    folders = [parse_xbel_file(path) for path in xbel_paths]
    root = Folder(title=page_title, children=folders)
    _assign_slugs(root)
    _write_folder_pages(root, output_dir, page_title, parent_link=None)
    return output_dir / "index.html"


# CLI ----------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(
        description="Generate a static HTML bookmark collection from XBEL files."
    )
    parser.add_argument(
        "xbel_files",
        nargs="+",
        type=Path,
        help="One or more .xbel files to include in the collection.",
    )
    parser.add_argument(
        "-o",
        "--output",
        type=Path,
        default=Path("dist"),
        help="Directory where index.html will be written (default: dist)",
    )
    parser.add_argument(
        "-t",
        "--title",
        default="My Bookmarks",
        help="Page title to render (default: My Bookmarks)",
    )

    args = parser.parse_args()
    missing = [p for p in args.xbel_files if not p.exists()]
    if missing:
        missing_str = ", ".join(str(p) for p in missing)
        raise SystemExit(f"Missing input files: {missing_str}")

    output_path = build_site(args.xbel_files, args.output, args.title)
    print(f"Wrote {output_path} and subpages")


if __name__ == "__main__":
    main()
