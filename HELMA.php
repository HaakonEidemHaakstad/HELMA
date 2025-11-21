<?php
/*
Plugin Name: HELMA InfoScreen (Fullscreen Edition)
Description: Info-skjerm system. [helma_editor] for redigering. Legg til ?helma_player=1 bak URL for fullskjerm.
Version: 2.1
Author: Helma AI
*/

if (!defined('ABSPATH')) exit;

/*********************************************************
 * 1. BACKEND & LAGRING
 *********************************************************/

add_action('wp_ajax_helma_save_presentation', 'helma_save_presentation');
add_action('wp_ajax_nopriv_helma_save_presentation', 'helma_save_presentation');

function helma_save_presentation() {
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $data = isset($_POST['data']) ? stripslashes($_POST['data']) : '';

    // PASSORD HER (Endre 'helma' hvis du vil)
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
    wp_send_json_success(['data' => json_decode($data), 'updated' => $updated]);
}

/*********************************************************
 * 2. FULLSKJERM OVERSTYRING (Player Mode)
 *********************************************************/
add_action('template_redirect', 'helma_fullscreen_check');

function helma_fullscreen_check() {
    if (isset($_GET['helma_player']) && $_GET['helma_player'] == '1') {
        helma_render_fullscreen_player();
        exit;
    }
}

function helma_render_fullscreen_player() {
    $saved_data = get_option('helma_presentation_data', '[]');
    $last_updated = get_option('helma_last_updated', 0);
    ?>
    <!DOCTYPE html>
    <html lang="no">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            .player-el { position: absolute; white-space: pre-wrap; line-height: 1.2; }
            .player-img { background-size: cover; background-position: center; background-repeat: no-repeat; }
            .loading-msg { color: #666; font-family: sans-serif; font-size: 2rem; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
        </style>
    </head>
    <body>

        <div id="helma-stage-container">
            <div id="helma-stage">
                <div class="loading-msg">Laster HELMA...</div>
            </div>
        </div>

        <script>
            let slides = <?php echo $saved_data ?: '[]'; ?>;
            let localUpdatedTimestamp = <?php echo $last_updated ?: 0; ?>;
            let currentIndex = 0;
            let slideTimer = null;

            const container = document.getElementById('helma-stage-container');
            const stage = document.getElementById('helma-stage');
            const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

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

            function renderSlide(index) {
                if (!slides || slides.length === 0) {
                    stage.innerHTML = '<div class="loading-msg">Ingen presentasjon funnet.<br>Lagre en i editoren.</div>';
                    return;
                }

                let slide = slides[index];
                stage.innerHTML = '';
                stage.style.backgroundColor = slide.background || '#ffffff';

                slide.elements.forEach(el => {
                    let dom = document.createElement('div');
                    dom.className = 'player-el';
                    dom.style.left = el.x + 'px';
                    dom.style.top = el.y + 'px';
                    dom.style.width = el.width + 'px';
                    dom.style.height = el.height + 'px';

                    if (el.type === 'text') {
                        dom.innerText = el.content;
                        dom.style.fontSize = el.style.fontSize;
                        dom.style.fontFamily = el.style.fontFamily;
                        dom.style.color = el.style.color;
                    } else if (el.type === 'image') {
                        dom.classList.add('player-img');
                        dom.style.backgroundImage = `url('${el.src}')`;
                    }
                    stage.appendChild(dom);
                });

                let duration = (slide.duration || 6) * 1000;
                if (slideTimer) clearTimeout(slideTimer);
                slideTimer = setTimeout(nextSlide, duration);
            }

            function nextSlide() {
                currentIndex++;
                if (currentIndex >= slides.length) currentIndex = 0;
                renderSlide(currentIndex);
            }

            setInterval(() => {
                fetch(ajaxUrl + '?action=helma_get_data')
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        if (res.data.updated > localUpdatedTimestamp) {
                            localUpdatedTimestamp = res.data.updated;
                            slides = res.data.data;
                            currentIndex = 0;
                            renderSlide(0);
                        }
                    }
                })
                .catch(err => console.log("Polling error:", err));
            }, 10000);

            renderSlide(0);
        </script>
    </body>
    </html>
    <?php
}

/*********************************************************
 * 3. SHORTCODE: EDITOR ([helma_editor])
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

    ob_start();
    ?>
    <style>
        #helma-app { font-family: 'Segoe UI', sans-serif; display: flex; flex-direction: column; height: 85vh; background: #f0f0f1; border:1px solid #ccc; margin-top:20px;}

        /* FIX FOR TOOLBAR: Bedre håndtering av plass */
        .helma-toolbar {
            background: #fff;
            padding: 10px;
            border-bottom: 1px solid #ccc;
            display: flex;
            flex-wrap: wrap; /* Tillat at ting bytter linje */
            gap: 10px; /* Luft mellom alle knapper (vannrett og loddrett) */
            align-items: center;
        }

        /* Sørger for at elementer ikke krasjer */
        .helma-toolbar > * {
            white-space: nowrap;
        }

        .helma-main { display: flex; flex: 1; overflow: hidden; }
        .helma-sidebar { width: 180px; background: #e5e5e5; padding: 10px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; border-right:1px solid #ccc;}
        .helma-slide-thumb { background: white; height: 80px; border: 2px solid transparent; cursor: pointer; position: relative; display: flex; align-items: center; justify-content: center; font-size: 14px; color: #666; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
        .helma-slide-thumb.active { border-color: #0073aa; background:#f0f9ff;}
        .helma-slide-thumb .slide-num { position: absolute; top: 2px; left: 4px; font-size: 10px; font-weight: bold; }
        .helma-canvas-area { flex: 1; background: #888; display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden; }

        #helma-canvas { width: 960px; height: 540px; background: white; position: relative; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.3); }

        .helma-el { position: absolute; cursor: move; border: 1px dashed transparent; box-sizing: border-box;}
        .helma-el:hover, .helma-el.selected { border: 1px dashed #0073aa; background:rgba(0,115,170,0.05); }
        .helma-text { padding: 5px; outline: none; white-space: pre-wrap; line-height: 1.2; }
        .helma-image { background-size: cover; background-position: center; background-repeat: no-repeat; }

        .h-btn { padding: 6px 12px; background: #0073aa; color: white; border: none; cursor: pointer; border-radius: 3px; font-size:13px; height: 32px; display:inline-flex; align-items:center;}
        .h-btn:hover { background: #005177; }
        .h-btn-danger { background: #d63638; }
        .h-btn-danger:hover { background: #a52022; }
        .h-input { padding: 5px; border: 1px solid #ccc; border-radius: 3px; font-size:13px; height: 32px; box-sizing: border-box;}

        .prop-group { display: flex; gap: 5px; align-items: center; border-left: 1px solid #ddd; padding-left: 10px; padding-right: 10px; }

        .top-links { position: absolute; top: -30px; right: 0; font-size: 13px; }
        .top-links a { text-decoration: none; color: #0073aa; font-weight: bold; background:#fff; padding:5px 10px; border-radius:3px; border:1px solid #ccc;}

        /* Responsive adjustments */
        @media(max-width: 800px) {
            .helma-sidebar { display: none; } /* Hide sidebar on very small screens */
        }
    </style>

    <div style="position:relative;">
        <div class="top-links">
            <a href="<?php echo $fullscreen_link; ?>" target="_blank">⤢ Åpne Spiller (Fullskjerm)</a>
        </div>

        <div id="helma-app">
            <div class="helma-toolbar">
                <strong style="margin-right:10px;">HELMA</strong>
                <button class="h-btn" onclick="addText()">+ Tekst</button>
                <button class="h-btn" onclick="addImage()">+ Bilde</button>

                <div style="display:flex; align-items:center; gap:5px; border:1px solid #eee; padding:4px; border-radius:4px;">
                    <label style="font-size:12px;">Tid:</label>
                    <input type="number" id="slide-duration" value="6" class="h-input" style="width:50px;" onchange="updateDuration()">
                    <span style="font-size:12px">s</span>
                </div>

                <div style="display:flex; align-items:center; gap:5px; border:1px solid #eee; padding:4px; border-radius:4px;">
                    <label style="font-size:12px;">Bakgrunn:</label>
                    <input type="color" id="slide-bg" onchange="updateBg()" style="height:25px; border:none; padding:0;">
                </div>

                <div class="prop-group" id="props-panel" style="visibility: hidden;">
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
                    <button class="h-btn h-btn-danger" onclick="deleteElement()">Slett</button>
                </div>

                <div style="flex-grow:1;"></div> <!-- Spacer pushes save/delete to right unless wrapped -->

                <button class="h-btn h-btn-danger" onclick="deleteSlide()">Slett Slide</button>
                <button class="h-btn" style="background: #28a745;" onclick="savePresentation()">LAGRE</button>
            </div>

            <div class="helma-main">
                <div class="helma-sidebar" id="slide-list"></div>
                <div class="helma-canvas-area">
                    <div id="helma-canvas" onclick="deselectAll(event)"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Interact.js -->
    <script src="https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js"></script>

    <script>
        let slides = <?php echo $saved_data; ?>;
        if (!Array.isArray(slides) || slides.length === 0) {
            slides = [{ duration: 6, background: '#ffffff', elements: [] }];
        }

        let currentSlideIndex = 0;
        let selectedElementId = null;
        const canvas = document.getElementById('helma-canvas');
        const slideList = document.getElementById('slide-list');
        const propsPanel = document.getElementById('props-panel');

        function fitEditorCanvas() {
            let wrapper = document.querySelector('.helma-canvas-area');
            if (!wrapper) return;
            let scale = Math.min((wrapper.clientWidth - 40) / 960, (wrapper.clientHeight - 40) / 540);
            if(scale > 1) scale = 1;
            canvas.style.transform = `scale(${scale})`;
        }
        window.addEventListener('resize', fitEditorCanvas);

        function renderSidebar() {
            slideList.innerHTML = '';
            slides.forEach((slide, index) => {
                let div = document.createElement('div');
                div.className = `helma-slide-thumb ${index === currentSlideIndex ? 'active' : ''}`;
                div.innerHTML = `<span class="slide-num">${index + 1}</span> Slide ${index + 1}`;
                div.onclick = () => loadSlide(index);
                slideList.appendChild(div);
            });
            let addBtn = document.createElement('button');
            addBtn.className = 'h-btn';
            addBtn.innerText = '+ Ny Slide';
            addBtn.style.width = '100%';
            addBtn.style.marginTop = 'auto';
            addBtn.onclick = addSlide;
            slideList.appendChild(addBtn);
        }

        function loadSlide(index) {
            currentSlideIndex = index;
            renderSidebar();
            renderCanvas();

            document.getElementById('slide-duration').value = slides[index].duration || 6;
            document.getElementById('slide-bg').value = slides[index].background || '#ffffff';
            propsPanel.style.visibility = 'hidden';
            setTimeout(fitEditorCanvas, 100);
        }

        function renderCanvas() {
            canvas.innerHTML = '';
            canvas.style.backgroundColor = slides[currentSlideIndex].background || '#ffffff';

            slides[currentSlideIndex].elements.forEach(el => {
                let domEl = document.createElement('div');
                domEl.className = 'helma-el ' + (el.type === 'text' ? 'helma-text' : 'helma-image');
                domEl.id = el.id;
                domEl.style.transform = `translate(${el.x}px, ${el.y}px)`;
                domEl.style.width = el.width + 'px';
                domEl.style.height = el.height + 'px';
                domEl.dataset.id = el.id;

                if (el.type === 'text') {
                    domEl.contentEditable = true;
                    domEl.innerText = el.content;
                    domEl.style.fontSize = el.style.fontSize;
                    domEl.style.fontFamily = el.style.fontFamily;
                    domEl.style.color = el.style.color;
                    domEl.onblur = (e) => { el.content = e.target.innerText; };
                } else if (el.type === 'image') {
                    domEl.style.backgroundImage = `url('${el.src}')`;
                }

                domEl.onmousedown = (e) => { e.stopPropagation(); selectElement(el.id); };
                canvas.appendChild(domEl);
            });
        }

        function addSlide() {
            slides.push({ duration: 6, background: '#ffffff', elements: [] });
            loadSlide(slides.length - 1);
        }

        function deleteSlide() {
            if (slides.length <= 1) return alert("Kan ikke slette siste slide.");
            if (confirm("Slette slide?")) {
                slides.splice(currentSlideIndex, 1);
                if (currentSlideIndex >= slides.length) currentSlideIndex = slides.length - 1;
                loadSlide(currentSlideIndex);
            }
        }

        function addText() {
            let id = 'el_' + Date.now();
            slides[currentSlideIndex].elements.push({
                id: id, type: 'text', content: 'Tekst', x: 50, y: 50, width: 300, height: 60,
                style: { fontSize: '30px', fontFamily: 'Arial', color: '#000000' }
            });
            renderCanvas();
        }

        function addImage() {
            let url = prompt("Bilde URL:");
            if (url) {
                let id = 'el_' + Date.now();
                slides[currentSlideIndex].elements.push({
                    id: id, type: 'image', src: url, x: 100, y: 100, width: 400, height: 300
                });
                renderCanvas();
            }
        }

        function updateDuration() { slides[currentSlideIndex].duration = parseInt(document.getElementById('slide-duration').value); }
        function updateBg() {
            let c = document.getElementById('slide-bg').value;
            slides[currentSlideIndex].background = c;
            canvas.style.backgroundColor = c;
        }

        function selectElement(id) {
            selectedElementId = id;
            document.querySelectorAll('.helma-el').forEach(el => el.classList.remove('selected'));
            let domEl = document.getElementById(id);
            if(domEl) domEl.classList.add('selected');
            let elData = slides[currentSlideIndex].elements.find(e => e.id === id);
            propsPanel.style.visibility = 'visible';
        }

        function deselectAll(e) {
            if (e.target.id === 'helma-canvas') {
                selectedElementId = null;
                document.querySelectorAll('.helma-el').forEach(el => el.classList.remove('selected'));
                propsPanel.style.visibility = 'hidden';
            }
        }

        function updateProp(prop, value) {
            if (!selectedElementId) return;
            let el = slides[currentSlideIndex].elements.find(e => e.id === selectedElementId);
            if (el && el.type === 'text') {
                el.style[prop] = value;
                document.getElementById(selectedElementId).style[prop] = value;
            }
        }

        function deleteElement() {
            if (!selectedElementId) return;
            slides[currentSlideIndex].elements = slides[currentSlideIndex].elements.filter(e => e.id !== selectedElementId);
            selectedElementId = null;
            renderCanvas();
            propsPanel.style.visibility = 'hidden';
        }

        interact('.helma-el')
            .draggable({
                listeners: {
                    move (event) {
                        let target = event.target;
                        let el = slides[currentSlideIndex].elements.find(e => e.id === target.dataset.id);
                        el.x += event.dx; el.y += event.dy;
                        target.style.transform = `translate(${el.x}px, ${el.y}px)`;
                    }
                },
                modifiers: [ interact.modifiers.restrictRect({ restriction: 'parent', endOnly: true }) ]
            })
            .resizable({
                edges: { left: true, right: true, bottom: true, top: true },
                listeners: {
                    move (event) {
                        let target = event.target;
                        let el = slides[currentSlideIndex].elements.find(e => e.id === target.dataset.id);
                        el.width = event.rect.width; el.height = event.rect.height;
                        el.x += event.deltaRect.left; el.y += event.deltaRect.top;
                        target.style.width = event.rect.width + 'px';
                        target.style.height = event.rect.height + 'px';
                        target.style.transform = `translate(${el.x}px, ${el.y}px)`;
                    }
                }
            });

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
            .then(res => alert(res.success ? "Lagret!" : "Feil: " + res.data.message));
        }

        loadSlide(0);
    </script>
    <?php
    return ob_get_clean();
}
