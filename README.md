***

# HELMA InfoScreen (v. 1.0.0)

**HELMA InfoScreen** is a lightweight, single-file WordPress plugin that turns any WordPress site into a digital signage host. It provides a PowerPoint-like visual editor for creating slides and a dedicated, auto-updating player mode for information screens.

> **Origin Story:**  
> This tool was originally developed to address a specific need at **[Fontenehuset Asker](https://www.fontenehusetasker.no)** (The Fountain House Asker). They needed a simplified, user-friendly way to manage their in-house information screens directly from their existing website, without relying on complex third-party software or subscription services.

## ✨ Key Features

*   **Visual Editor:** Drag-and-drop interface to move and resize text and images.
*   **Real-Time Updates:** The player checks for changes every 10 seconds. If the presentation is updated in the editor, the screens update automatically without a refresh.
*   **True Fullscreen Mode:** Uses a custom URL parameter to bypass the active WordPress theme (headers/footers), rendering only the presentation on a black background.
*   **Customizable Slides:** Set individual duration (seconds) and background colors per slide.
*   **Typography:** Change fonts, colors, and sizes easily.
*   **Lightweight:** No database tables created. Data is stored in a single `wp_option`. Uses `interact.js` (via CDN) for drag/drop functionality.
*   **Simple Security:** Password-protected editor access and save actions.

## 📦 Installation

You can install HELMA in two ways: as a **Plugin** or via **Code Snippets**.

### Method 1: Via Code Snippets (Recommended for quick setup)
1. Install the [Code Snippets](https://wordpress.org/plugins/code-snippets/) plugin on your WordPress site.
2. Create a new Snippet named "HELMA".
3. Paste the contents of `helma.php` into the code area.
4. Select "Run snippet everywhere".
5. Click **Save Changes and Activate**.

### Method 2: As a Standalone Plugin
1. Create a folder named `helma` on your computer.
2. Save the PHP code as `helma.php` inside that folder.
3. Zip the folder (`helma.zip`).
4. Go to **WordPress Admin > Plugins > Add New > Upload Plugin**.
5. Upload and activate.

## 🚀 Usage

### 1. Setup the Editor Page
1. Create a new WordPress Page (e.g., named "HELMA Editor").
2. Add the shortcode:  
   `[helma_editor]`
3. Publish the page.

### 2. Displaying the Presentation (The Player)
To display the presentation on a TV or Info Screen, use your site's homepage URL appended with the player parameter:

```
https://your-website.com/?helma_player=1
```

*This special URL forces WordPress to ignore your theme's header, footer, and styles, providing a clean, full-screen canvas for the slides.*

## ⚙️ Configuration & Passwords

By default, the system uses a simple hardcoded password for accessing the editor and saving data.

*   **Default Password:** `helma`

**To change this:**
1. Open the code (in Code Snippets or the plugin editor).
2. Search for the variable `$correct_password = 'helma';`.
3. Change `'helma'` to your desired password.
4. Search for the cookie check `$_COOKIE['helma_auth'] !== 'helma'` and update the string there as well if you want to change the login token logic.

## 🛠 Technical Details

*   **Frontend Library:** [Interact.js](https://interactjs.io/) (CDN) for resizing and dragging elements.
*   **Storage:** Data is serialized as JSON and stored in `wp_options` table under `helma_presentation_data`.
*   **Polling:** The player uses AJAX long-polling (10s interval) to check `helma_last_updated` timestamp against the server.

---
*Developed with ❤️ for Fontenehuset Asker.*
