#!/usr/bin/env python3
"""
Generate a static HTML bookmarks page from one or more XBEL files.
"""

from __future__ import annotations

import argparse
from dataclasses import dataclass
from datetime import datetime, timezone
from html import escape
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


def _render_bookmark(bookmark: Bookmark) -> str:
    desc = f'<div class="desc">{escape(bookmark.desc)}</div>' if bookmark.desc else ""
    added = _format_timestamp(bookmark.added)
    modified = _format_timestamp(bookmark.modified)
    meta_parts = []
    if added:
        meta_parts.append(f"added {added}")
    if modified:
        meta_parts.append(f"updated {modified}")
    meta = ""
    if meta_parts:
        meta = f'<div class="meta">{" · ".join(meta_parts)}</div>'
    return (
        '<li class="bookmark">'
        f'<a href="{escape(bookmark.href)}" target="_blank" rel="noreferrer noopener">{escape(bookmark.title)}</a>'
        f"{desc}{meta}"
        "</li>"
    )


def _render_subfolder_link(folder: Folder) -> str:
    assert folder.slug, "Slug must be assigned before rendering"
    return (
        '<li class="folder-link">'
        f'<a href="{escape(folder.slug)}">{escape(folder.title)}</a>'
        "</li>"
    )


def render_page(folder: Folder, page_title: str, parent_link: str | None) -> str:
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")

    bookmark_items = "".join(
        _render_bookmark(child) for child in folder.children if isinstance(child, Bookmark)
    )
    subfolder_items = "".join(
        _render_subfolder_link(child) for child in folder.children if isinstance(child, Folder)
    )

    bookmarks_section = (
        f"<ul class=\"bookmarks\">{bookmark_items}</ul>" if bookmark_items else "<p class=\"muted\">Keine Bookmarks.</p>"
    )
    subfolders_section = (
        f"<ul class=\"subfolders\">{subfolder_items}</ul>" if subfolder_items else "<p class=\"muted\">Keine Unterordner.</p>"
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
  <style>
    :root {{
      --bg: #0b1b2b;
      --panel: #11263a;
      --accent: #f8c102;
      --text: #e8eef4;
      --muted: #94a4b5;
      --link: #7dd1ff;
    }}
    * {{ box-sizing: border-box; }}
    body {{
      margin: 0;
      font-family: "Inter", "Segoe UI", sans-serif;
      background: radial-gradient(120% 120% at 10% 20%, rgba(255,255,255,0.08), transparent),
                  radial-gradient(80% 80% at 90% 10%, rgba(255,255,255,0.06), transparent),
                  var(--bg);
      color: var(--text);
      min-height: 100vh;
      padding: 24px;
    }}
    header {{
      max-width: 960px;
      margin: 0 auto 18px auto;
      display: flex;
      align-items: center;
      gap: 12px;
    }}
    .badge {{
      background: var(--accent);
      color: #1b1b1b;
      padding: 6px 12px;
      border-radius: 999px;
      font-weight: 700;
      letter-spacing: 0.02em;
    }}
    h1 {{
      margin: 0;
      font-size: 28px;
      letter-spacing: -0.02em;
    }}
    .updated {{
      color: var(--muted);
      font-size: 14px;
    }}
    .parent {{
      color: var(--link);
      text-decoration: none;
      font-size: 14px;
    }}
    .parent:hover {{
      color: var(--accent);
    }}
    main {{
      max-width: 960px;
      margin: 0 auto;
      background: linear-gradient(145deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
      border: 1px solid rgba(255,255,255,0.05);
      border-radius: 16px;
      padding: 20px;
      backdrop-filter: blur(6px);
      box-shadow: 0 24px 60px rgba(0,0,0,0.35);
    }}
    section {{
      margin-bottom: 20px;
    }}
    section h2 {{
      margin: 0 0 8px 0;
      font-size: 15px;
      letter-spacing: 0.03em;
      color: var(--accent);
      text-transform: uppercase;
    }}
    ul {{
      list-style: none;
      padding-left: 16px;
      margin: 0;
    }}
    .subfolders {{
      border-left: 2px solid rgba(255,255,255,0.07);
      padding-left: 12px;
    }}
    .folder-link {{
      margin: 6px 0;
      font-weight: 600;
    }}
    .folder-link a {{
      color: var(--link);
      text-decoration: none;
    }}
    .folder-link a:hover {{
      color: var(--accent);
    }}
    .bookmark {{
      margin: 8px 0;
    }}
    .bookmark a {{
      color: var(--link);
      text-decoration: none;
      font-weight: 600;
    }}
    .bookmark a:hover {{
      color: var(--accent);
    }}
    .desc {{
      color: var(--muted);
      font-size: 13px;
      margin-top: 2px;
    }}
    .meta {{
      color: var(--muted);
      font-size: 12px;
      margin-top: 2px;
    }}
    .muted {{
      color: var(--muted);
    }}
    @media (max-width: 640px) {{
      body {{
        padding: 14px;
      }}
      h1 {{
        font-size: 22px;
      }}
      main {{
        padding: 14px;
      }}
    }}
  </style>
</head>
<body>
  <header>
    <div class="badge">Bookmarks</div>
    <div>
      <h1>{escape(folder.title)}</h1>
      <div class="updated">Updated {escape(now)}</div>
    </div>
    {parent_nav}
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
