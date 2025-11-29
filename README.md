# HELMA InfoScreen (Robust Player)

A single‑file WordPress plugin for running a simple digital signage / infoscreen system with a browser‑based slide editor and fullscreen player.

All UI labels are in Norwegian, but the workflow is simple and can be used without translation.

**Current Version:** 4.6  
**Author:** Helma AI (see [GitHub repo](https://github.com/helma-ai) for related projects)

---

## Features

- Slide‑based infoscreen / digital signage player
- WYSIWYG editor (`[helma_editor]` shortcode)
- Text, image, clock (date/time), and weather widgets
- Drag, resize, snap‑to‑grid layout (InteractJS + SortableJS)
- Background color and optional background image per slide
- Clipboard image paste (auto‑resize + JPEG compression)
- Slide activation (show/hide), naming, and duplication
- Live clock and weather in the player
- Automatic polling for updates and a “watchdog” to recover if a slide gets stuck
- Weather proxy to MET Norway API (avoids CORS issues)
- No-cache headers in player for fresh content
- Single-file plugin for easy deployment and modification

---

## Requirements

- WordPress (front‑end and `admin-ajax.php` must be reachable)
- PHP 7.4+ recommended
- Modern browser (Chrome, Edge, Firefox, etc.)

**External Dependencies (loaded via CDN in editor):**
- [InteractJS](https://interactjs.io/) for drag/resize
- [SortableJS](https://sortablejs.github.io/Sortable/) for slide reordering

**Weather Features:**
- Geocoding via [Nominatim (OpenStreetMap)](https://nominatim.openstreetmap.org/)
- Forecasts via [MET Norway API](https://api.met.no/)
- Icons from [metno/weathericons](https://github.com/metno/weathericons) (SVG)

---

## Installation

1. **Create the plugin folder**

   ```text
   wp-content/plugins/helma-infoscreen/
   ```

2. **Add the plugin file**

   Save the provided `HELMA.php` into that folder:

   ```text
   wp-content/plugins/helma-infoscreen/HELMA.php
   ```

3. **Configure branding background**

   At the top of `HELMA.php`:

   ```php
   function helma_get_branding_bg() {
       $url = 'https://example.com/path/to/your/background.png';
       return $url;
   }
   ```

   Change the URL to your own brand background image. This is used as a fallback in the player when a slide's background is plain white and no custom image is set.

4. **(Strongly recommended) Change passwords**

   There are two hard‑coded passwords you should change before going live:

   - Editor login (cookie):

     ```php
     // in helma_render_editor()
     if (isset($_POST['helma_login_pass']) && $_POST['helma_login_pass'] === 'helma') {
     ```

   - Save endpoint:

     ```php
     // in helma_save_presentation()
     $correct_password = 'helma';
     ```

   Replace `"helma"` with your own strong password(s).

5. **Activate the plugin**

   In the WordPress admin, go to **Plugins → Installed Plugins** and activate **HELMA InfoScreen (Robust Player)**.

---

## Usage

### 1. Create an editor page

1. In WordPress, create a new **Page** (e.g. “Infoskjerm Editor”).
2. Add the shortcode:

   ```text
   [helma_editor]
   ```

3. Publish the page and open it on the front‑end.

4. **Login**: you’ll see a small login form. Default password is:

   ```text
   helma
   ```

   (Change this in the code as described above.)

You should now see the full editor interface.

---

### 2. Editor overview

The editor consists of:

- **Toolbar (top)**  
  - `LAGRE` button (save to the database; prompts for save password).
  - Buttons: `+ Tekst`, `+ Bilde`, `+ Dato/Tid`, `+ Vær`.
  - Grid toggle with density control.
  - Undo / Redo buttons.
  - Slide duration (seconds).
  - Slide background color.
  - Element property panel (font, size, color, alignment).
  - Delete element / delete slide.

- **Sidebar (left)**  
  - List of slides with:
    - Name (editable)
    - Visibility toggle (👁 / ✖)
    - Copy button (+)
  - Button to add a **new slide**.

- **Canvas (center)**  
  - 960×540 design area (scaled to your browser).
  - Right‑click context menu:
    - Add Text / Image / Clock / Weather
    - Set background (image URL / color / opacity)
    - Reset background
    - Delete selected element

#### Elements

- **Text (`+ Tekst`)**
  - Double‑click text to edit.
  - Drag and resize via handles.
  - Style via the toolbar (font, size, color, alignment).

- **Image (`+ Bilde` or paste)**  
  - Add by URL, or paste directly from clipboard:
    - Paste: the plugin resizes to max 1000px and compresses as JPEG.
  - Drag and resize.

- **Clock (`+ Dato/Tid`)**
  - Shows local time in the editor for preview.
  - In the player, it updates in real time (format: `dd.mm.yyyy HH:MM`).

- **Weather (`+ Vær`)**
  - When adding, you’ll be prompted for a place name (e.g. `Oslo`).
  - Uses Nominatim (OpenStreetMap) for geocoding and MET Norway (`api.met.no`) for forecasts.
  - In the player, a real forecast icon and temperature are shown (updated every 30 minutes via cache).

#### Grid & snapping

- Toggle **Grid** in the toolbar to show a golden grid overlay.
- Use `+` / `-` buttons (or keyboard shortcuts) to change grid density.
- Dragging/resizing snaps to the grid when enabled.

#### Keyboard shortcuts

- `Ctrl+S` (or `Cmd+S`): Save (same as `LAGRE`).
- `Ctrl+Z` / `Cmd+Z`: Undo.
- `Ctrl+Y` / `Cmd+Y`: Redo.
- `Ctrl+G`: Toggle grid.
- `+` / `-`: Adjust grid density.
- Arrow keys: Move selected element (hold Shift for 10px steps).
- `Delete`:
  - If an element is selected → delete element.
  - If not → delete current slide.
- `Esc`: Blur current text edit.

---

### 3. Fullscreen player

To show the infoscreen on a TV or dedicated screen, use **Player Mode**.

The player is loaded by appending `?helma_player=1` to any front‑end URL:

```text
https://example.com/?helma_player=1
```

or to a specific page:

```text
https://example.com/infoskjerm/?helma_player=1
```

You also get a ready‑made **“Åpne Spiller (Fullskjerm)”** link in the editor UI.

Player behavior:

- Renders a 960×540 stage scaled to the browser window.
- Cycles through **only active slides**.
- Uses each slide’s `duration` (minimum 3 seconds).
- Shows:
  - Background color or slide background image.
  - Fallback branding background (from `helma_get_branding_bg()`) when slide background is plain white.
  - Text, images, clock, and live weather.
- Polls the server every 10 seconds for changes:
  - If new data is saved, it reloads slides without refreshing the entire page.
  - If plugin version changes, it forces a full reload.
- A “watchdog” timer forces a slide change if a slide gets stuck longer than expected (checks every 5 seconds).
- Includes meta tags and headers to prevent caching (`no-cache, no-store, must-revalidate`).

---

## Data storage & AJAX endpoints

The plugin stores data in WordPress options:

- `helma_presentation_data` — JSON array of slides.
- `helma_last_updated` — Unix timestamp of the last successful save.

AJAX endpoints (via `admin-ajax.php`):

- `helma_save_presentation` (POST)
  - Requires `password` (matches `$correct_password` in PHP).
  - Saves `data` (JSON) to the database.
- `helma_get_data` (GET)
  - Returns:
    - `data` — slide array
    - `updated` — timestamp
    - `version` — plugin version
- `helma_get_weather` (GET)
  - Proxies MET Norway’s `locationforecast` API.
  - Required params: `lat`, `lon`.

---

## Security notes

This plugin is intentionally simple and **not hardened** for multi‑user / public environments:

- **No WordPress capability checks** (`current_user_can` etc.).
- Authentication is handled by:
  - A cookie (`helma_auth`) set via a plain password form.
  - A hard‑coded save password.
- Weather uses external APIs (Nominatim + MET Norway) directly from the browser.

**Recommendations:**

- Only use on a **trusted intranet** or non‑public page.
- Change all hard‑coded passwords before use.
- Consider restricting access to the editor page via:
  - WordPress page visibility / membership plugins, or
  - Customizing the PHP to require `current_user_can('manage_options')`, etc.
- Optionally move passwords into environment variables or WordPress constants.

---

## Customization tips

- **Background image**  
  Change `helma_get_branding_bg()` to your own image or logo.

- **Dimensions**  
  The internal canvas is 960×540 (16:9). You can change this (and related constants / CSS) if you need another aspect ratio.

- **Version constant**  
  Bump `HELMA_VERSION` when you deploy breaking changes; the player auto‑reloads if the version changes.

- **Styling**  
  All inline CSS for editor and player is contained in `HELMA.php`. You can tweak fonts, colors, and layout directly there.

---

## Troubleshooting

- **Player not loading or showing "Laster HELMA..." forever?**  
  Check browser console for errors. Ensure `admin-ajax.php` is accessible and the site URL is correct.

- **Weather not showing?**  
  - Verify lat/lon in the editor (add a weather element and check console).
  - MET API requires a User-Agent (set in the proxy); rate limits may apply.
  - Icons are loaded from GitHub; ensure no ad-blockers block them.

- **Unsaved changes warning?**  
  The editor prompts before unload if there are unsaved changes. Save regularly with `LAGRE`.

- **Editor not responsive on mobile?**  
  The sidebar hides on small screens (<800px). Use a desktop for best experience.

- **CORS or API errors?**  
  The weather proxy handles MET API requests server-side to avoid CORS. If it fails, check PHP logs for `wp_remote_get` issues.

For other issues, inspect the browser console or PHP error logs.

---

## License

This plugin is licensed under the GNU General Public License v2.0 or later (GPL-2.0-or-later), as it's designed for WordPress.

```text
Copyright (c) 2023 Helma AI

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

For the full license text, see [GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).

---
