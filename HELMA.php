<?php
/*
Plugin Name: HELMA InfoScreen (Robust Player)
Description: Info-skjerm system. [helma_editor] for redigering. Legg til ?helma_player=1 bak URL for fullskjerm.
Version: 4.6
Author: Helma AI
*/

if (!defined('ABSPATH')) exit;

define('HELMA_VERSION', '4.6');

/*********************************************************
 * 1. INNSTILLINGER (LIM INN BILDELENKEN DIN HER)
 *********************************************************/

function helma_get_branding_bg() {
    $url = 'https://www.fontenehusetasker.no/wp-content/uploads/2025/11/bakgrunn.png';
    return $url;
}

/*********************************************************
 * 2. BACKEND & LAGRING & PROXY
 *********************************************************/

add_action('wp_ajax_helma_save_presentation', 'helma_save_presentation');
add_action('wp_ajax_nopriv_helma_save_presentation', 'helma_save_presentation');

function helma_save_presentation() {
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $data = isset($_POST['data']) ? stripslashes($_POST['data']) : '';

    $correct_password = 'helma';

    if ($password !== $correct_password) {
        wp_send_json_error(['message' => 'Feil passord!']);
    }

    update_option('helma_presentation_data', $data);
    update_option('helma_last_updated', time());
    wp_send_json_success(['message' => 'Presentasjon lagret!']);
}

add_action('wp_ajax_helma_get_data', 'helma_get_data');
add_action('wp_ajax_nopriv_helma_get_data', 'helma_get_data');

function helma_get_data() {
    $data = get_option('helma_presentation_data', '[]');
    $updated = get_option('helma_last_updated', 0);
    wp_send_json_success([
        'data' => json_decode($data),
        'updated' => $updated,
        'version' => HELMA_VERSION
    ]);
}

// WEATHER PROXY (Yr.no / MET API)
add_action('wp_ajax_helma_get_weather', 'helma_get_weather_proxy');
add_action('wp_ajax_nopriv_helma_get_weather', 'helma_get_weather_proxy');

function helma_get_weather_proxy() {
    $lat = isset($_GET['lat']) ? sanitize_text_field($_GET['lat']) : '';
    $lon = isset($_GET['lon']) ? sanitize_text_field($_GET['lon']) : '';

    if (!$lat || !$lon) {
        wp_send_json_error(['message' => 'Missing coordinates']);
    }

    $url = "https://api.met.no/weatherapi/locationforecast/2.0/compact?lat={$lat}&lon={$lon}";
    $args = [ 'headers' => [ 'User-Agent' => 'HELMA-InfoScreen/4.6 https://github.com/helma-ai' ] ];
    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'API Error']);
    }

    $body = wp_remote_retrieve_body($response);
    header('Content-Type: application/json');
    echo $body;
    wp_die();
}

/*********************************************************
 * 3. FULLSKJERM OVERSTYRING (Player Mode)
 *********************************************************/
add_action('template_redirect', 'helma_fullscreen_check');

function helma_fullscreen_check() {
    if (isset($_GET['helma_player']) && $_GET['helma_player'] == '1') {
        nocache_headers();
        helma_render_fullscreen_player();
        exit;
    }
}

function helma_render_fullscreen_player() {
    $saved_data = get_option('helma_presentation_data', '[]');
    $last_updated = get_option('helma_last_updated', 0);
    $bg_image = helma_get_branding_bg();
    ?>
    <!DOCTYPE html>
    <html lang="no">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
        <meta http-equiv="Pragma" content="no-cache" />
        <meta http-equiv="Expires" content="0" />
        <title>HELMA Infoskjerm</title>
        <style>
            body, html {
                margin: 0; padding: 0; width: 100%; height: 100%;
                background-color: #000; overflow: hidden;
                display: flex; justify-content: center; align-items: center;
            }
            #helma-stage-container {
                position: relative;
                width: 960px; height: 540px;
                box-shadow: 0 0 50px rgba(0,0,0,0.5);
            }
            #helma-stage {
                width: 100%; height: 100%;
                background: white; position: relative; overflow: hidden;
            }
            #helma-player-bg {
                position: absolute; top: 0; left: 0; width: 100%; height: 100%;
                background-size: cover; background-position: center;
                z-index: 0; pointer-events: none;
            }
            .player-el {
                position: absolute;
                white-space: pre-wrap;
                line-height: 1.2;
                box-sizing: border-box;
                padding: 5px;
                z-index: 10;
            }
            .player-img {
                background-size: 100% 100%;
                background-position: center;
                background-repeat: no-repeat;
                padding: 0;
            }

            /* Weather Widget Styles */
            .helma-weather-box {
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                height: 100%; width: 100%; text-align: center;
            }
            .hw-icon { width: 50%; height: 50%; background-repeat: no-repeat; background-position: center; background-size: contain; }
            .hw-temp { font-weight: bold; }
            .hw-loc { font-size: 0.6em; opacity: 0.8; margin-top: 5px; }

            .loading-msg { color: #666; font-family: sans-serif; font-size: 2rem; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; z-index: 20; }
        </style>
    </head>
    <body>

        <div id="helma-stage-container">
            <div id="helma-stage">
                <div id="helma-player-bg"></div>
                <div class="loading-msg">Laster HELMA...<br><span style="font-size:14px">v<?php echo HELMA_VERSION; ?></span></div>
            </div>
        </div>

        <script>
            // Initial Load: Filter out inactive slides immediately
            let rawData = <?php echo $saved_data ?: '[]'; ?>;
            let slides = rawData.filter(s => s.active !== false);

            let localUpdatedTimestamp = <?php echo $last_updated ?: 0; ?>;
            let localVersion = "<?php echo HELMA_VERSION; ?>";
            let brandingImage = "<?php echo $bg_image; ?>";
            let currentIndex = 0;
            let slideTimer = null;
            let clockTimer = null;
            let weatherCache = {};

            // Watchdog variables
            let lastSlideChangeTime = Date.now();
            let currentSlideDuration = 10000;

            const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            const container = document.getElementById('helma-stage-container');
            const stage = document.getElementById('helma-stage');

            function resizeStage() {
                let winW = window.innerWidth;
                let winH = window.innerHeight;
                let baseW = 960;
                let baseH = 540;
                let scaleX = winW / baseW;
                let scaleY = winH / baseH;
                let scale = Math.min(scaleX, scaleY);
                container.style.transform = `scale(${scale})`;
            }
            window.addEventListener('resize', resizeStage);
            resizeStage();

            function getNorwegianTime() {
                const now = new Date();
                let d = now.getDate().toString().padStart(2, '0');
                let m = (now.getMonth() + 1).toString().padStart(2, '0');
                let y = now.getFullYear();
                let H = now.getHours().toString().padStart(2, '0');
                let M = now.getMinutes().toString().padStart(2, '0');
                return `${d}.${m}.${y} ${H}:${M}`;
            }

            function fetchWeather(lat, lon, domElement, placeName) {
                let key = lat + '_' + lon;
                if (weatherCache[key] && (Date.now() - weatherCache[key].time < 1800000)) {
                    renderWeatherDOM(domElement, weatherCache[key].data, placeName);
                    return;
                }
                fetch(ajaxUrl + '?action=helma_get_weather&lat=' + lat + '&lon=' + lon)
                .then(r => r.json())
                .then(data => {
                    if (data && data.properties) {
                        weatherCache[key] = { time: Date.now(), data: data };
                        renderWeatherDOM(domElement, data, placeName);
                    } else { domElement.innerHTML = 'Ingen data'; }
                })
                .catch(e => { console.log(e); domElement.innerHTML = 'Feil'; });
            }

            function renderWeatherDOM(dom, data, place) {
                try {
                    let timeseries = data.properties.timeseries[0];
                    let temp = timeseries.data.instant.details.air_temperature;
                    let symbol = timeseries.data.next_1_hours.summary.symbol_code;
                    let iconUrl = `https://raw.githubusercontent.com/metno/weathericons/master/weather/svg/${symbol}.svg`;
                    dom.innerHTML = `
                        <div class="helma-weather-box">
                            <div class="hw-icon" style="background-image: url('${iconUrl}');"></div>
                            <div class="hw-temp">${temp}°</div>
                            <div class="hw-loc">${place}</div>
                        </div>
                    `;
                } catch(e) { console.warn("Weather parse error", e); }
            }

            function renderSlide(index) {
                lastSlideChangeTime = Date.now(); // Reset watchdog

                // Clear content but keep structure
                stage.innerHTML = '<div id="helma-player-bg"></div>';
                let bgLayer = document.getElementById('helma-player-bg');

                if (!slides || slides.length === 0) {
                    let msg = document.createElement('div');
                    msg.className = 'loading-msg';
                    msg.innerText = 'Ingen aktive slides.';
                    stage.appendChild(msg);
                    if (slideTimer) clearTimeout(slideTimer);
                    slideTimer = setTimeout(() => location.reload(), 5000);
                    return;
                }

                if (clockTimer) { clearInterval(clockTimer); clockTimer = null; }

                let slide = slides[index];

                // BACKGROUND LOGIC
                let bgCol = slide.background || '#ffffff';
                let bgImg = slide.backgroundImage || null;
                let bgOp  = (slide.bgOpacity !== undefined) ? slide.bgOpacity : 1;

                stage.style.backgroundColor = bgCol;

                if (bgImg) {
                    bgLayer.style.backgroundImage = `url('${bgImg}')`;
                    bgLayer.style.opacity = bgOp;
                }
                else if ((bgCol === '#ffffff' || bgCol === '#fff') && brandingImage && brandingImage !== 'LIM_INN_BILDE_URL_HER') {
                    bgLayer.style.backgroundImage = `url('${brandingImage}')`;
                    bgLayer.style.opacity = 1;
                } else {
                    bgLayer.style.backgroundImage = 'none';
                }

                let hasClock = false;

                // WRAP ELEMENT RENDERING IN TRY/CATCH TO PREVENT CRASHES
                try {
                    if (slide.elements) {
                        slide.elements.forEach(el => {
                            let dom = document.createElement('div');
                            dom.className = 'player-el';
                            dom.style.left = el.x + 'px';
                            dom.style.top = el.y + 'px';
                            dom.style.width = el.width + 'px';
                            dom.style.height = el.height + 'px';

                            let s = el.style || {};
                            dom.style.fontSize = s.fontSize || '30px';
                            dom.style.fontFamily = s.fontFamily || 'Arial';
                            dom.style.color = s.color || '#000';
                            dom.style.textAlign = s.textAlign || 'left';
                            if (el.type === 'weather') dom.style.backgroundColor = s.backgroundColor || 'transparent';

                            if (el.type === 'text') {
                                dom.innerText = el.content;
                            }
                            else if (el.type === 'clock') {
                                dom.classList.add('helma-live-clock');
                                dom.innerText = getNorwegianTime();
                                hasClock = true;
                            }
                            else if (el.type === 'weather') {
                                fetchWeather(el.lat, el.lon, dom, el.place);
                            }
                            else if (el.type === 'image') {
                                dom.classList.add('player-img');
                                dom.style.backgroundImage = `url('${el.src}')`;
                            }
                            stage.appendChild(dom);
                        });
                    }
                } catch(e) {
                    console.error("Error rendering elements:", e);
                }

                if (hasClock) {
                    clockTimer = setInterval(() => {
                        let timeStr = getNorwegianTime();
                        document.querySelectorAll('.helma-live-clock').forEach(c => c.innerText = timeStr);
                    }, 1000);
                }

                // Determine duration (Min 3 seconds)
                let d = parseInt(slide.duration) || 6;
                if (d < 3) d = 3;
                currentSlideDuration = d * 1000;

                if (slideTimer) clearTimeout(slideTimer);
                slideTimer = setTimeout(nextSlide, currentSlideDuration);
            }

            function nextSlide() {
                currentIndex++;
                if (currentIndex >= slides.length) currentIndex = 0;
                renderSlide(currentIndex);
            }

            // Polling for updates
            setInterval(() => {
                fetch(ajaxUrl + '?action=helma_get_data&t=' + Date.now())
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        if (res.data.version && res.data.version !== localVersion) {
                            location.reload(true); return;
                        }
                        if (res.data.updated > localUpdatedTimestamp) {
                            localUpdatedTimestamp = res.data.updated;
                            slides = res.data.data.filter(s => s.active !== false);
                            currentIndex = 0;
                            renderSlide(0);
                        }
                    }
                })
                .catch(err => console.log("Polling error (ignored):", err));
            }, 10000);

            // WATCHDOG TIMER
            // Checks every 5 seconds. If current slide has been shown 5s longer than intended, force next.
            setInterval(() => {
                if (Date.now() - lastSlideChangeTime > (currentSlideDuration + 5000)) {
                    console.warn("Watchdog triggered: Slide stuck. Forcing next.");
                    nextSlide();
                }
            }, 5000);

            renderSlide(0);
        </script>
    </body>
    </html>
    <?php
}

/*********************************************************
 * 4. SHORTCODE: EDITOR ([helma_editor])
 *********************************************************/
add_shortcode('helma_editor', 'helma_render_editor');

function helma_render_editor() {
    if (!isset($_COOKIE['helma_auth']) || $_COOKIE['helma_auth'] !== 'helma') {
        if (isset($_POST['helma_login_pass']) && $_POST['helma_login_pass'] === 'helma') {
            setcookie('helma_auth', 'helma', time() + 3600, '/');
            $_COOKIE['helma_auth'] = 'helma';
        } else {
            return '
            <div style="max-width:300px; margin:50px auto; text-align:center; font-family:sans-serif;">
                <h2>HELMA Innlogging</h2>
                <form method="POST">
                    <input type="password" name="helma_login_pass" placeholder="Passord" style="padding:10px; width:100%; margin-bottom:10px;">
                    <button type="submit" style="padding:10px 20px; background:#0073aa; color:white; border:none; cursor:pointer;">Logg inn</button>
                </form>
            </div>';
        }
    }

    $saved_data = get_option('helma_presentation_data', '[]');
    if (!$saved_data || $saved_data == '') $saved_data = '[]';

    $fullscreen_link = home_url('/') . '?helma_player=1';
    $bg_image = helma_get_branding_bg();

    ob_start();
    ?>
    <style>
        #helma-app { font-family: 'Segoe UI', sans-serif; display: flex; flex-direction: column; height: 85vh; background: #f0f0f1; border:1px solid #ccc; margin-top:20px; outline:none;}

        .helma-toolbar {
            background: #fff;
            padding: 10px;
            border-bottom: 1px solid #ccc;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .helma-toolbar > * { white-space: nowrap; }

        .helma-main { display: flex; flex: 1; overflow: hidden; }

        .helma-sidebar {
            width: 180px;
            background: #e5e5e5;
            display: flex;
            flex-direction: column;
            border-right:1px solid #ccc;
        }
        #slide-list-container {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .helma-slide-thumb {
            background: white;
            height: 80px;
            border: 2px solid transparent;
            cursor: grab;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #666;
            box-shadow:0 1px 3px rgba(0,0,0,0.1);
            user-select: none;
            transition: opacity 0.3s;
        }
        .helma-slide-thumb.inactive {
            opacity: 0.5;
            background: #f0f0f0;
        }
        .helma-slide-thumb:active { cursor: grabbing; }
        .helma-slide-thumb.active { border-color: #0073aa; background:#f0f9ff;}
        .helma-slide-thumb .slide-num { position: absolute; top: 2px; left: 4px; font-size: 10px; font-weight: bold; }

        .slide-name-input {
            margin-top: 15px;
            width: 85%;
            text-align: center;
            border: 1px solid transparent;
            background: transparent;
            font-size: 13px;
            color: #444;
            padding: 2px;
            cursor: text;
        }
        .slide-name-input:focus, .slide-name-input:hover {
            border: 1px solid #ccc;
            background: #fff;
            outline: none;
        }

        .slide-action-btn {
            position: absolute; top: 2px;
            width: 20px; height: 20px;
            background: #fff; border: 1px solid #ccc;
            border-radius: 3px; font-size: 14px; line-height:1;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: #333; z-index: 10;
        }
        .slide-action-btn:hover { background: #eee; color: #0073aa; border-color:#999;}

        .copy-btn { right: 2px; }
        .vis-btn { right: 24px; font-size: 12px; }

        .sortable-ghost { opacity: 0.4; background: #ccc; }

        .helma-canvas-area { flex: 1; background: #888; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }

        #helma-canvas {
            width: 960px; height: 540px;
            background: white;
            position: relative; overflow: hidden;
            box-shadow: 0 0 20px rgba(0,0,0,0.3);
            background-size: cover;
            background-position: center;
        }

        #helma-bg-layer {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background-size: cover; background-position: center;
            z-index: 0; pointer-events: none;
        }

        .helma-grid-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none; z-index: 5;
            background-image:
                linear-gradient(to right, rgba(255, 215, 0, 0.4) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255, 215, 0, 0.4) 1px, transparent 1px);
        }

        .helma-el { position: absolute; cursor: move; border: 1px dashed transparent; box-sizing: border-box; z-index: 10;}
        .helma-el:hover, .helma-el.selected { border: 1px dashed #0073aa; background:rgba(0,115,170,0.05); }
        .helma-text { padding: 5px; outline: none; white-space: pre-wrap; line-height: 1.2; }
        .helma-image { background-size: 100% 100%; background-position: center; background-repeat: no-repeat; }
        .helma-weather-ph {
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            border: 1px dotted #999; background: rgba(255,255,255,0.8); text-align:center;
        }

        .h-btn { padding: 6px 12px; background: #0073aa; color: white; border: none; cursor: pointer; border-radius: 3px; font-size:13px; height: 32px; display:inline-flex; align-items:center; justify-content:center;}
        .h-btn:hover { background: #005177; }
        .h-btn-danger { background: #d63638; }
        .h-input { padding: 5px; border: 1px solid #ccc; border-radius: 3px; font-size:13px; height: 32px; box-sizing: border-box;}
        .prop-group { display: flex; gap: 5px; align-items: center; border-left: 1px solid #ddd; padding-left: 10px; padding-right: 10px; }

        .h-btn-small { width: 22px; height: 22px; background: #eee; border:none; cursor: pointer; font-size: 14px; display:flex; align-items:center; justify-content:center; }
        .h-btn-small:hover { background: #ddd; }

        /* RAINBOW ANIMATION FOR SAVE BUTTON */
        @keyframes rainbow-pulse {
            0%   { background-color: #ff0000; transform: scale(1); box-shadow: 0 0 10px rgba(255,0,0,0.5); }
            14%  { background-color: #ff7f00; }
            28%  { background-color: #cccc00; }
            42%  { background-color: #00ba00; transform: scale(1.2); box-shadow: 0 0 35px 5px rgba(0,255,0,0.8); } /* Big pulse */
            57%  { background-color: #0000ff; }
            71%  { background-color: #4b0082; }
            85%  { background-color: #9400d3; }
            100% { background-color: #ff0000; transform: scale(1); box-shadow: 0 0 10px rgba(255,0,0,0.5); }
        }
        .h-btn.unsaved-pulse {
            animation: rainbow-pulse 3s linear infinite;
            font-weight: bold;
            border: 1px solid rgba(255,255,255,0.5);
            text-shadow: 0 1px 2px rgba(0,0,0,0.5);
        }

        /* CONTEXT MENU */
        #helma-context-menu {
            display: none;
            position: fixed; /* FIXED POSITIONING TO APPEAR UNDER CURSOR */
            z-index: 9999;
            width: 170px;
            background: #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid #ccc;
        }
        .ctx-item {
            padding: 8px 12px;
            font-size: 13px;
            color: #333;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }
        .ctx-item:hover { background: #f0f9ff; color: #0073aa; }
        .ctx-item:last-child { border-bottom: none; }
        .ctx-header { background: #f9f9f9; padding: 5px 12px; font-weight: bold; font-size: 11px; color: #999; text-transform: uppercase; border-bottom:1px solid #eee;}
        .ctx-danger { color: #d63638; }
        .ctx-danger:hover { background: #fff0f0; color: #d63638; }

        .top-links { position: absolute; top: -30px; right: 0; font-size: 13px; }
        .top-links a { text-decoration: none; color: #0073aa; font-weight: bold; background:#fff; padding:5px 10px; border-radius:3px; border:1px solid #ccc;}

        .loading-overlay {
            position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.8);
            z-index:999; display:none; align-items:center; justify-content:center; font-size:20px; color:#333;
        }

        @media(max-width: 800px) { .helma-sidebar { display: none; } }
    </style>

    <div style="position:relative;">
        <div class="top-links">
            <a href="<?php echo $fullscreen_link; ?>" target="_blank">⤢ Åpne Spiller (Fullskjerm)</a>
        </div>

        <div id="helma-app" tabindex="0">
            <div id="paste-loading" class="loading-overlay">Laster inn bilde...</div>

            <!-- Context Menu -->
            <div id="helma-context-menu">
                <div class="ctx-header">Legg til...</div>
                <div class="ctx-item" onclick="handleCtx('text')">+ Tekst</div>
                <div class="ctx-item" onclick="handleCtx('image')">+ Bilde</div>
                <div class="ctx-item" onclick="handleCtx('clock')">+ Dato/Tid</div>
                <div class="ctx-item" onclick="handleCtx('weather')">+ Vær</div>

                <div class="ctx-header">Bakgrunn</div>
                <div class="ctx-item" onclick="handleCtx('bg-image')">Sett Bilde (URL)</div>
                <div class="ctx-item" onclick="handleCtx('bg-color')">Sett Farge (Hex)</div>
                <div class="ctx-item" onclick="handleCtx('bg-opacity')">Justér Synlighet</div>
                <div class="ctx-item" onclick="handleCtx('bg-reset')">Nullstill</div>

                <div class="ctx-item ctx-danger" id="ctx-del" style="display:none; margin-top:5px; border-top:1px solid #eee;" onclick="handleCtx('delete')">Slett Valgt</div>
            </div>

            <div class="helma-toolbar">
                <strong style="margin-right:10px;">HELMA</strong>
                <button class="h-btn" id="save-btn" style="background: #28a745; margin-right:20px;" onclick="savePresentation()">LAGRE</button>

                <button class="h-btn" onclick="addText()">+ Tekst</button>
                <button class="h-btn" onclick="addImage()">+ Bilde</button>
                <button class="h-btn" onclick="addClock()">+ Dato/Tid</button>
                <button class="h-btn" onclick="addWeather()">+ Vær</button>

                <div style="display:flex; align-items:center; gap:5px; border:1px solid #eee; padding:4px; border-radius:4px; margin-left:10px; background:#fff;">
                    <input type="checkbox" id="grid-toggle" onchange="toggleGrid()" style="margin:0;">
                    <label for="grid-toggle" style="font-size:12px; cursor:pointer;">Grid</label>

                    <div style="display:flex; align-items:center; border:1px solid #ccc; border-radius:3px; margin-left:5px;">
                        <button class="h-btn-small" onclick="adjustGrid(-1)">-</button>
                        <input type="text" id="grid-count" value="2" readonly style="width:25px; height:22px; font-size:12px; border:none; text-align:center; padding:0; background:#fff;">
                        <button class="h-btn-small" onclick="adjustGrid(1)">+</button>
                    </div>
                </div>

                <div style="display:flex; gap:0; margin-left:10px;">
                    <button class="h-btn" style="background:#666; border-radius:3px 0 0 3px;" onclick="undo()" title="Angre (Ctrl+Z)">⟲</button>
                    <button class="h-btn" style="background:#666; border-radius:0 3px 3px 0; border-left:1px solid #555;" onclick="redo()" title="Gjør om (Ctrl+Y)">⟳</button>
                </div>

                <div style="display:flex; align-items:center; gap:5px; border:1px solid #eee; padding:4px; border-radius:4px; margin-left:10px;">
                    <label style="font-size:12px;">Tid:</label>
                    <input type="number" id="slide-duration" value="6" class="h-input" style="width:50px;" onchange="updateDuration()">
                    <span style="font-size:12px">s</span>
                </div>

                <div style="display:flex; align-items:center; gap:5px; border:1px solid #eee; padding:4px; border-radius:4px;">
                    <label style="font-size:12px;">Bakgrunn:</label>
                    <input type="color" id="slide-bg" onchange="updateBg()" style="height:25px; border:none; padding:0;">
                </div>

                <div class="prop-group" id="geo-panel" style="visibility: hidden;">
                    <span style="font-size:12px;">Bredde %:</span>
                    <input type="number" id="geo-w" class="h-input" style="width:50px" onchange="updateGeo('w', this.value)">
                    <span style="font-size:12px;">Høyde %:</span>
                    <input type="number" id="geo-h" class="h-input" style="width:50px" onchange="updateGeo('h', this.value)">
                </div>

                <div class="prop-group" id="props-panel" style="display: none;">
                    <select id="font-family" class="h-input" onchange="updateProp('fontFamily', this.value)">
                        <option value="Arial">Arial</option>
                        <option value="'Times New Roman'">Times</option>
                        <option value="'Courier New'">Courier</option>
                        <option value="Verdana">Verdana</option>
                        <option value="Georgia">Georgia</option>
                        <option value="Impact">Impact</option>
                    </select>
                    <input type="number" id="font-size" class="h-input" style="width:50px" value="24" onchange="updateProp('fontSize', this.value + 'px')">
                    <input type="color" id="font-color" onchange="updateProp('color', this.value)" style="height:30px; width:30px; padding:0; border:none;">

                    <div style="display:flex; border-left:1px solid #ddd; padding-left:8px; gap:4px;">
                        <button class="h-btn" style="padding:0 6px; width:32px;" title="Venstre" onclick="updateProp('textAlign', 'left')">L</button>
                        <button class="h-btn" style="padding:0 6px; width:32px;" title="Senter" onclick="updateProp('textAlign', 'center')">C</button>
                        <button class="h-btn" style="padding:0 6px; width:32px;" title="Høyre" onclick="updateProp('textAlign', 'right')">R</button>
                    </div>
                </div>

                <button class="h-btn h-btn-danger" id="del-btn" style="visibility:hidden;" onclick="deleteElement()">Slett</button>
                <div style="flex-grow:1;"></div>
                <button class="h-btn h-btn-danger" onclick="deleteSlide()">Slett Slide</button>
            </div>

            <div class="helma-main">
                <div class="helma-sidebar">
                    <div id="slide-list-container"></div>
                    <div style="padding:10px; border-top:1px solid #ccc; background:#f9f9f9;">
                         <button class="h-btn" style="width:100%;" onclick="addSlide()">+ Ny Slide</button>
                    </div>
                </div>
                <div class="helma-canvas-area">
                    <div id="helma-canvas" onclick="deselectAll(event)">
                        <!-- Background Layer gets injected by JS -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

    <script>
        let slides = <?php echo $saved_data; ?>;
        if (!Array.isArray(slides) || slides.length === 0) {
            slides = [{ name: 'Start', duration: 6, background: '#ffffff', active: true, elements: [] }];
        }

        let historyStack = [];
        let redoStack = [];
        let hasUnsavedChanges = false;

        // Context Menu Vars
        let ctxMenu = document.getElementById('helma-context-menu');
        let ctxCoords = { x: 50, y: 50 };
        let ctxTargetId = null;

        window.addEventListener('beforeunload', function (e) {
            if (hasUnsavedChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // UPDATE SAVE BUTTON VISUALS
        function updateSaveBtnState() {
            let btn = document.getElementById('save-btn');
            if (!btn) return;
            if (hasUnsavedChanges) {
                btn.classList.add('unsaved-pulse');
                btn.innerText = 'LAGRE *';
            } else {
                btn.classList.remove('unsaved-pulse');
                btn.innerText = 'LAGRE';
            }
        }

        // FIXED PASTE HANDLER
        window.addEventListener('paste', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
                return;
            }

            var items = (e.clipboardData || e.originalEvent.clipboardData).items;
            var blob = null;

            for (var i = 0; i < items.length; i++) {
                if (items[i].type.indexOf("image") === 0) {
                    blob = items[i].getAsFile();
                    break;
                }
            }

            if (blob !== null) {
                e.preventDefault();
                document.getElementById('paste-loading').style.display = 'flex';

                var reader = new FileReader();
                reader.onload = function(event) {
                    var img = new Image();
                    img.onload = function() {
                        var canvas = document.createElement('canvas');
                        var ctx = canvas.getContext('2d');
                        var MAX_DIM = 1000;
                        var width = img.width;
                        var height = img.height;

                        if (width > height) {
                            if (width > MAX_DIM) {
                                height *= MAX_DIM / width;
                                width = MAX_DIM;
                            }
                        } else {
                            if (height > MAX_DIM) {
                                width *= MAX_DIM / height;
                                height = MAX_DIM;
                            }
                        }

                        width = Math.floor(width);
                        height = Math.floor(height);

                        canvas.width = width;
                        canvas.height = height;
                        ctx.drawImage(img, 0, 0, width, height);

                        var dataUrl = canvas.toDataURL('image/jpeg', 0.7);

                        pushHistory();
                        let id = 'el_' + Date.now();
                        slides[currentSlideIndex].elements.push({
                            id: id, type: 'image', src: dataUrl,
                            x: 50, y: 50, width: 400, height: 300
                        });

                        document.getElementById('paste-loading').style.display = 'none';
                        renderCanvas();
                    }
                    img.src = event.target.result;
                };
                reader.readAsDataURL(blob);
            }
        });

        function pushHistory() {
            hasUnsavedChanges = true;
            updateSaveBtnState();
            historyStack.push(JSON.stringify(slides));
            if (historyStack.length > 20) historyStack.shift();
            redoStack = [];
        }

        function undo() {
            if (historyStack.length === 0) return;
            hasUnsavedChanges = true;
            updateSaveBtnState();
            redoStack.push(JSON.stringify(slides));
            let prevState = historyStack.pop();
            slides = JSON.parse(prevState);
            if (currentSlideIndex >= slides.length) currentSlideIndex = slides.length - 1;
            loadSlide(currentSlideIndex);
            selectedElementId = null;
            hidePanels();
        }

        function redo() {
            if (redoStack.length === 0) return;
            hasUnsavedChanges = true;
            updateSaveBtnState();
            historyStack.push(JSON.stringify(slides));
            let nextState = redoStack.pop();
            slides = JSON.parse(nextState);
            if (currentSlideIndex >= slides.length) currentSlideIndex = slides.length - 1;
            loadSlide(currentSlideIndex);
            selectedElementId = null;
            hidePanels();
        }

        let brandingImage = "<?php echo $bg_image; ?>";
        let currentSlideIndex = 0;
        let selectedElementId = null;
        let isGridEnabled = false;

        const canvas = document.getElementById('helma-canvas');
        const slideListContainer = document.getElementById('slide-list-container');
        const propsPanel = document.getElementById('props-panel');
        const geoPanel = document.getElementById('geo-panel');
        const delBtn = document.getElementById('del-btn');
        const geoW = document.getElementById('geo-w');
        const geoH = document.getElementById('geo-h');
        const gridCountInput = document.getElementById('grid-count');

        const CANVAS_WIDTH = 960;
        const CANVAS_HEIGHT = 540;

        function getEditorTime() {
            const now = new Date();
            let d = now.getDate().toString().padStart(2, '0');
            let m = (now.getMonth() + 1).toString().padStart(2, '0');
            let y = now.getFullYear();
            let H = now.getHours().toString().padStart(2, '0');
            let M = now.getMinutes().toString().padStart(2, '0');
            return `${d}.${m}.${y} ${H}:${M}`;
        }

        new Sortable(slideListContainer, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function (evt) {
                pushHistory();
                let activeSlideObj = slides[currentSlideIndex];
                let item = slides.splice(evt.oldIndex, 1)[0];
                slides.splice(evt.newIndex, 0, item);
                currentSlideIndex = slides.indexOf(activeSlideObj);
                renderSidebar();
            }
        });

        function fitEditorCanvas() {
            let wrapper = document.querySelector('.helma-canvas-area');
            if (!wrapper) return;
            let scale = Math.min((wrapper.clientWidth - 40) / CANVAS_WIDTH, (wrapper.clientHeight - 40) / CANVAS_HEIGHT);
            if(scale > 1) scale = 1;
            canvas.style.transform = `scale(${scale})`;
        }
        window.addEventListener('resize', fitEditorCanvas);

        function toggleGrid() {
            isGridEnabled = document.getElementById('grid-toggle').checked;
            renderCanvas();
        }

        function adjustGrid(delta) {
            let val = parseInt(gridCountInput.value) || 2;
            val += delta;
            if (val < 1) val = 1;
            if (val > 50) val = 50;
            gridCountInput.value = val;
            if (isGridEnabled) {
                renderCanvas();
            }
        }

        function renderSidebar() {
            slideListContainer.innerHTML = '';
            slides.forEach((slide, index) => {
                let div = document.createElement('div');
                let isActive = (slide.active !== false);
                div.className = `helma-slide-thumb ${index === currentSlideIndex ? 'active' : ''} ${!isActive ? 'inactive' : ''}`;

                let sName = slide.name || `Slide ${index + 1}`;
                let visIcon = isActive ? '👁' : '✖';
                let visTitle = isActive ? 'Deaktiver slide' : 'Aktiver slide';

                div.innerHTML = `
                    <span class="slide-num">${index + 1}</span>
                    <input type="text"
                        class="slide-name-input"
                        value="${sName}"
                        onchange="updateSlideName(${index}, this.value)"
                        onclick="event.stopPropagation()"
                        onmousedown="event.stopPropagation()"
                    >
                    <div class="slide-action-btn vis-btn" title="${visTitle}" onclick="toggleSlideActive(${index}, event)">${visIcon}</div>
                    <div class="slide-action-btn copy-btn" title="Kopier slide" onclick="copySlide(${index}, event)">+</div>
                `;
                div.onclick = () => loadSlide(index);
                slideListContainer.appendChild(div);
            });
        }

        function toggleSlideActive(index, e) {
            e.stopPropagation();
            pushHistory();
            slides[index].active = (slides[index].active === false) ? true : false;
            renderSidebar();
        }

        function updateSlideName(index, val) {
            pushHistory();
            slides[index].name = val;
        }

        function copySlide(index, e) {
            e.stopPropagation();
            pushHistory();
            let original = slides[index];
            let copy = JSON.parse(JSON.stringify(original));
            copy.name = (copy.name || ('Slide ' + (index + 1))) + ' (Kopi)';
            copy.elements.forEach(el => {
                el.id = 'el_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5);
            });
            slides.splice(index + 1, 0, copy);
            currentSlideIndex = index + 1;
            renderSidebar();
            loadSlide(currentSlideIndex);
        }

        function loadSlide(index) {
            currentSlideIndex = index;
            renderSidebar();
            renderCanvas();
            document.getElementById('slide-duration').value = slides[index].duration || 6;
            document.getElementById('slide-bg').value = slides[index].background || '#ffffff';
            hidePanels();
            setTimeout(fitEditorCanvas, 100);
        }

        function renderCanvas() {
            // Re-create background layer structure
            canvas.innerHTML = '<div id="helma-bg-layer"></div>';
            let bgLayer = document.getElementById('helma-bg-layer');

            let currentSlide = slides[currentSlideIndex];
            let bgCol = currentSlide.background || '#ffffff';
            let bgImg = currentSlide.backgroundImage || null;
            let bgOp  = (currentSlide.bgOpacity !== undefined) ? currentSlide.bgOpacity : 1;

            // Apply base color to the canvas container itself
            canvas.style.backgroundColor = bgCol;

            // Apply image to the layer on top
            if (bgImg) {
                bgLayer.style.backgroundImage = `url('${bgImg}')`;
                bgLayer.style.opacity = bgOp;
            }
            else if ((bgCol === '#ffffff' || bgCol === '#fff') && brandingImage && brandingImage !== 'LIM_INN_BILDE_URL_HER') {
                // Fallback to branding if white and no custom image
                bgLayer.style.backgroundImage = `url('${brandingImage}')`;
                bgLayer.style.opacity = 1;
            } else {
                bgLayer.style.backgroundImage = 'none';
            }

            let gridDivs = parseInt(gridCountInput.value) || 2;
            let stepX = CANVAS_WIDTH / gridDivs;
            let stepY = CANVAS_HEIGHT / gridDivs;

            if (isGridEnabled) {
                let grid = document.createElement('div');
                grid.className = 'helma-grid-overlay';
                grid.style.backgroundSize = `${stepX}px ${stepY}px`;
                canvas.appendChild(grid);
            }

            currentSlide.elements.forEach(el => {
                let domEl = document.createElement('div');
                let typeClass = 'helma-text';
                if (el.type === 'image') typeClass = 'helma-image';
                if (el.type === 'weather') typeClass = 'helma-weather-ph';

                domEl.className = 'helma-el ' + typeClass;
                domEl.id = el.id;
                domEl.style.transform = `translate(${el.x}px, ${el.y}px)`;
                domEl.style.width = el.width + 'px';
                domEl.style.height = el.height + 'px';
                domEl.dataset.id = el.id;

                if (el.type === 'text' || el.type === 'clock' || el.type === 'weather') {
                    let s = el.style || {};
                    domEl.style.fontSize = s.fontSize || '30px';
                    domEl.style.fontFamily = s.fontFamily || 'Arial';
                    domEl.style.color = s.color || '#000000';
                    domEl.style.textAlign = s.textAlign || 'left';

                    if(el.type === 'clock') {
                        domEl.innerText = getEditorTime();
                        domEl.style.border = "1px solid #ccc";
                        domEl.title = "Dato/Tid (Oppdateres live i spilleren)";
                    } else if (el.type === 'weather') {
                        domEl.innerHTML = `<strong>Vær: ${el.place}</strong><br><span style="font-size:0.6em">(Vises i spiller)</span>`;
                    } else {
                        domEl.contentEditable = false;
                        domEl.innerText = el.content;
                        domEl.style.cursor = 'move';

                        domEl.ondblclick = (e) => {
                            pushHistory();
                            domEl.contentEditable = true;
                            domEl.style.cursor = 'text';
                            domEl.focus();
                        };

                        domEl.onblur = (e) => {
                            if(el.content !== e.target.innerText) {
                                hasUnsavedChanges = true; // Mark dirty if text changed
                                updateSaveBtnState();
                            }
                            el.content = e.target.innerText;
                            domEl.contentEditable = false;
                            domEl.style.cursor = 'move';
                        };
                    }
                } else if (el.type === 'image') {
                    domEl.style.backgroundImage = `url('${el.src}')`;
                }

                // Allow right click on elements too
                domEl.addEventListener('contextmenu', (e) => {
                    // Pre-select to ensure context actions work
                    selectElement(el.id);
                    ctxTargetId = el.id;
                });

                canvas.appendChild(domEl);
                initInteract(stepX, stepY, domEl);
            });
        }

        // CONTEXT MENU LOGIC
        canvas.addEventListener('contextmenu', function(e) {
            e.preventDefault();

            // Calculate position relative to canvas wrapper
            // Need to account for CSS transform scale
            let wrapper = document.querySelector('.helma-canvas-area');
            let rect = canvas.getBoundingClientRect();

            // Current scale factor of the canvas
            let scaleX = rect.width / CANVAS_WIDTH;
            let scaleY = rect.height / CANVAS_HEIGHT;
            // Usually scaleX == scaleY, so pick one
            let scale = scaleX;

            // Mouse position relative to canvas top-left, adjusted for scale
            let clickX = (e.clientX - rect.left) / scale;
            let clickY = (e.clientY - rect.top) / scale;

            ctxCoords = { x: Math.floor(clickX), y: Math.floor(clickY) };

            // Position the visual menu on screen (absolute to body)
            ctxMenu.style.display = 'block';
            ctxMenu.style.left = e.clientX + 'px';
            ctxMenu.style.top = e.clientY + 'px';

            // Show "Delete" option if we clicked on an existing element (ctxTargetId is set)
            // or if an element is currently selected
            if (selectedElementId) {
                document.getElementById('ctx-del').style.display = 'block';
            } else {
                document.getElementById('ctx-del').style.display = 'none';
            }
        });

        // Close menu on any click
        document.addEventListener('click', function(e) {
            if (e.target.closest('#helma-context-menu')) return;
            ctxMenu.style.display = 'none';
            // ctxTargetId = null; // Don't clear immediately if we need it for actions?
            // Actually reset target only if clicking away
        });

        function handleCtx(action) {
            ctxMenu.style.display = 'none';
            if (action === 'delete') {
                deleteElement();
            } else if (action === 'text') {
                addText(ctxCoords.x, ctxCoords.y);
            } else if (action === 'image') {
                addImage(ctxCoords.x, ctxCoords.y);
            } else if (action === 'clock') {
                addClock(ctxCoords.x, ctxCoords.y);
            } else if (action === 'weather') {
                addWeather(ctxCoords.x, ctxCoords.y);
            } else if (action === 'bg-image') {
                let url = prompt("URL til bakgrunnsbilde:", "");
                if(url) {
                    pushHistory();
                    slides[currentSlideIndex].backgroundImage = url;
                    renderCanvas();
                }
            } else if (action === 'bg-color') {
                let hex = prompt("Hex fargekode (f.eks #ff0000):", "#ffffff");
                if(hex) {
                    pushHistory();
                    slides[currentSlideIndex].background = hex;
                    document.getElementById('slide-bg').value = hex;
                    renderCanvas();
                }
            } else if (action === 'bg-opacity') {
                let op = prompt("Synlighet på bilde (0-100%):", "100");
                if(op !== null) {
                    pushHistory();
                    slides[currentSlideIndex].bgOpacity = parseInt(op) / 100;
                    renderCanvas();
                }
            } else if (action === 'bg-reset') {
                pushHistory();
                slides[currentSlideIndex].backgroundImage = null;
                slides[currentSlideIndex].bgOpacity = 1;
                slides[currentSlideIndex].background = '#ffffff';
                document.getElementById('slide-bg').value = '#ffffff';
                renderCanvas();
            }
        }

        function initInteract(stepX, stepY, element) {
            let modifiers = [ interact.modifiers.restrictRect({ restriction: 'parent', endOnly: true }) ];

            if (isGridEnabled) {
                modifiers.push(
                    interact.modifiers.snap({
                        targets: [ interact.createSnapGrid({ x: stepX, y: stepY }) ],
                        range: Infinity,
                        relativePoints: [ { x: 0, y: 0 } ]
                    }),
                    interact.modifiers.snapSize({
                        targets: [ interact.createSnapGrid({ x: stepX, y: stepY }) ],
                    })
                );
            }

            interact(element)
                .on('down', function (event) {
                    event.stopPropagation(); // Prevent canvas click from deselecting
                    selectElement(element.dataset.id);
                })
                .draggable({
                    listeners: {
                        start(event) { pushHistory(); },
                        move (event) {
                            let target = event.target;
                            let el = slides[currentSlideIndex].elements.find(e => e.id === target.dataset.id);
                            if (!el) return;
                            el.x += event.dx; el.y += event.dy;
                            target.style.transform = `translate(${el.x}px, ${el.y}px)`;
                        }
                    },
                    modifiers: modifiers
                })
                .resizable({
                    edges: { left: true, right: true, bottom: true, top: true },
                    listeners: {
                        start(event) { pushHistory(); },
                        move (event) {
                            let target = event.target;
                            let el = slides[currentSlideIndex].elements.find(e => e.id === target.dataset.id);
                            if (!el) return;

                            el.width = event.rect.width;
                            el.height = event.rect.height;
                            el.x += event.deltaRect.left;
                            el.y += event.deltaRect.top;

                            target.style.width = event.rect.width + 'px';
                            target.style.height = event.rect.height + 'px';
                            target.style.transform = `translate(${el.x}px, ${el.y}px)`;

                            if (selectedElementId === el.id) {
                                geoW.value = Math.round((el.width / CANVAS_WIDTH) * 100);
                                geoH.value = Math.round((el.height / CANVAS_HEIGHT) * 100);
                            }
                        }
                    },
                    modifiers: modifiers
                });
        }

        function savePresentation() {
            let pass = prompt("Lagre-passord:");
            if (!pass) return;
            let data = JSON.stringify(slides);
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({ 'action': 'helma_save_presentation', 'password': pass, 'data': data })
            })
            .then(r => r.json())
            .then(res => {
                alert(res.success ? "Lagret!" : "Feil: " + res.data.message);
                if (res.success) {
                    hasUnsavedChanges = false;
                    updateSaveBtnState();
                }
            });
        }

        // --- UPDATED ADD FUNCTIONS TO ACCEPT COORDS ---
        function addText(optX, optY) {
            pushHistory();
            let id = 'el_' + Date.now();
            let x = (typeof optX === 'number') ? optX : 50;
            let y = (typeof optY === 'number') ? optY : 50;

            slides[currentSlideIndex].elements.push({
                id: id,
                type: 'text',
                content: 'Tekst',
                x: x, y: y, width: 300, height: 60,
                style: {
                    fontSize: '30px', fontFamily: 'Arial', color: '#000000', textAlign: 'left'
                }
            });
            renderCanvas();
        }

        function addClock(optX, optY) {
            pushHistory();
            let id = 'el_' + Date.now();
            let x = (typeof optX === 'number') ? optX : 50;
            let y = (typeof optY === 'number') ? optY : 50;

            slides[currentSlideIndex].elements.push({
                id: id,
                type: 'clock',
                content: '',
                x: x, y: y, width: 400, height: 60,
                style: {
                    fontSize: '40px', fontFamily: 'Arial', color: '#000000', textAlign: 'center'
                }
            });
            renderCanvas();
        }

        function addWeather(optX, optY) {
            let place = prompt("Skriv inn stedsnavn (f.eks. Oslo):");
            if (!place) return;

            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(place)}`)
            .then(r => r.json())
            .then(data => {
                if (data && data.length > 0) {
                    pushHistory();
                    let lat = data[0].lat;
                    let lon = data[0].lon;
                    let id = 'el_' + Date.now();
                    let x = (typeof optX === 'number') ? optX : 100;
                    let y = (typeof optY === 'number') ? optY : 100;

                    slides[currentSlideIndex].elements.push({
                        id: id,
                        type: 'weather',
                        place: place,
                        lat: lat,
                        lon: lon,
                        x: x, y: y, width: 250, height: 200,
                        style: {
                            fontSize: '40px', fontFamily: 'Arial', color: '#000000', textAlign: 'center'
                        }
                    });
                    renderCanvas();
                } else {
                    alert("Fant ikke sted: " + place);
                }
            })
            .catch(err => alert("Feil ved søk: " + err));
        }

        function addImage(optX, optY) {
            let url = prompt("Bilde URL:");
            if (url) {
                pushHistory();
                let id = 'el_' + Date.now();
                let x = (typeof optX === 'number') ? optX : 100;
                let y = (typeof optY === 'number') ? optY : 100;

                slides[currentSlideIndex].elements.push({
                    id: id, type: 'image', src: url, x: x, y: y, width: 400, height: 300
                });
                renderCanvas();
            }
        }

        function updateDuration() {
            pushHistory();
            slides[currentSlideIndex].duration = parseInt(document.getElementById('slide-duration').value);
        }
        function updateBg() {
            pushHistory();
            let c = document.getElementById('slide-bg').value;
            slides[currentSlideIndex].background = c;
            renderCanvas();
        }

        function hidePanels() {
            propsPanel.style.display = 'none';
            geoPanel.style.visibility = 'hidden';
            delBtn.style.visibility = 'hidden';
        }

        function selectElement(id) {
            selectedElementId = id;
            document.querySelectorAll('.helma-el').forEach(el => el.classList.remove('selected'));
            let domEl = document.getElementById(id);
            if(domEl) domEl.classList.add('selected');

            let el = slides[currentSlideIndex].elements.find(e => e.id === id);

            geoPanel.style.visibility = 'visible';
            delBtn.style.visibility = 'visible';

            let wp = Math.round((el.width / CANVAS_WIDTH) * 100);
            let hp = Math.round((el.height / CANVAS_HEIGHT) * 100);
            geoW.value = wp;
            geoH.value = hp;

            if (el && (el.type === 'text' || el.type === 'clock' || el.type === 'weather')) {
                propsPanel.style.display = 'flex';
                if (el.style) {
                     document.getElementById('font-color').value = el.style.color || '#000000';
                     document.getElementById('font-size').value = parseInt(el.style.fontSize) || 30;
                     document.getElementById('font-family').value = el.style.fontFamily || 'Arial';
                }
            } else {
                propsPanel.style.display = 'none';
            }
        }

        function deselectAll(e) {
            // Only deselect if actually clicking the canvas itself, not a child
            if (e.target.id === 'helma-canvas') {
                selectedElementId = null;
                document.querySelectorAll('.helma-el').forEach(el => el.classList.remove('selected'));
                hidePanels();
            }
        }

        function updateGeo(axis, val) {
            if (!selectedElementId) return;
            pushHistory();
            let el = slides[currentSlideIndex].elements.find(e => e.id === selectedElementId);
            if (!el) return;
            let dom = document.getElementById(selectedElementId);
            if (axis === 'w') {
                let px = (val / 100) * CANVAS_WIDTH;
                el.width = px;
                dom.style.width = px + 'px';
            } else {
                let px = (val / 100) * CANVAS_HEIGHT;
                el.height = px;
                dom.style.height = px + 'px';
            }
        }

        function updateProp(prop, value) {
            if (!selectedElementId) return;
            pushHistory();
            let el = slides[currentSlideIndex].elements.find(e => e.id === selectedElementId);
            if (!el || (el.type !== 'text' && el.type !== 'clock' && el.type !== 'weather')) return;
            if (!el.style) el.style = {};
            el.style[prop] = value;
            let dom = document.getElementById(selectedElementId);
            if (dom) { dom.style[prop] = value; }
        }

        function deleteElement() {
            if (!selectedElementId) return;
            pushHistory();
            slides[currentSlideIndex].elements = slides[currentSlideIndex].elements.filter(e => e.id !== selectedElementId);
            selectedElementId = null;
            renderCanvas();
            hidePanels();
        }

        window.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); savePresentation(); return; }
            if ((e.ctrlKey || e.metaKey) && e.key === 'z') { e.preventDefault(); undo(); return; }
            if ((e.ctrlKey || e.metaKey) && e.key === 'y') { e.preventDefault(); redo(); return; }

            // GRID SHORTCUT
            if ((e.ctrlKey || e.metaKey) && e.key === 'g') {
                e.preventDefault();
                let check = document.getElementById('grid-toggle');
                check.checked = !check.checked;
                toggleGrid();
                return;
            }

            if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
            if (e.target.isContentEditable) return;

            // GRID + / - SHORTCUTS
            if (!e.ctrlKey && !e.metaKey) {
                if (e.key === '+' || e.key === '=' || e.key === 'NumpadAdd') {
                    e.preventDefault();
                    adjustGrid(1);
                    return;
                }
                if (e.key === '-' || e.key === 'NumpadSubtract') {
                    e.preventDefault();
                    adjustGrid(-1);
                    return;
                }
            }

            if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                if (!selectedElementId) return;
                e.preventDefault();
                let el = slides[currentSlideIndex].elements.find(e => e.id === selectedElementId);
                if (!el) return;
                if (!e.repeat) pushHistory();
                let step = e.shiftKey ? 10 : 1;
                switch(e.key) {
                    case 'ArrowLeft': el.x -= step; break;
                    case 'ArrowRight': el.x += step; break;
                    case 'ArrowUp': el.y -= step; break;
                    case 'ArrowDown': el.y += step; break;
                }
                let domEl = document.getElementById(selectedElementId);
                if(domEl) domEl.style.transform = `translate(${el.x}px, ${el.y}px)`;
                return;
            }
            if (e.key === 'Escape') { if (document.activeElement) document.activeElement.blur(); return; }

            // UPDATED DELETE LOGIC
            if (e.key === 'Delete') {
                if (selectedElementId) {
                    deleteElement();
                } else {
                    e.preventDefault();
                    deleteSlide();
                }
            }
        });

        // Init
        renderSidebar();
        loadSlide(0);
        fitEditorCanvas();
    </script>
    <?php
    return ob_get_clean();
}
