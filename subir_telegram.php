<?php
session_start();

// === CONFIGURACIÓN ===
$config_dir = 'config/';
$log_file = 'debug_telegram.log';
$github_file = $config_dir . 'github_config.json';
$telegram_file = $config_dir . 'telegram_config.json';

if (!is_dir($config_dir)) mkdir($config_dir, 0777, true);

// === LOG ===
function logDebug($msg) {
    global $log_file;
    $time = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$time] $msg" . PHP_EOL, FILE_APPEND);
}

logDebug("=== NUEVA SESIÓN ===");

// === CARGAR CONFIG ===
$github_config = file_exists($github_file) ? json_decode(file_get_contents($github_file), true) : [];
$telegram_config = file_exists($telegram_file) ? json_decode(file_get_contents($telegram_file), true) : [];

// === GUARDAR TELEGRAM ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_telegram') {
    $bot_token = trim($_POST['bot_token'] ?? '');
    $chat_id = trim($_POST['chat_id'] ?? '');
    if ($bot_token && $chat_id) {
        $config = ['bot_token' => $bot_token, 'chat_id' => $chat_id];
        file_put_contents($telegram_file, json_encode($config, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'message' => 'Guardado']);
        logDebug("Telegram guardado");
    } else {
        echo json_encode(['success' => false, 'message' => 'Faltan datos']);
    }
    exit;
}

// === OBTENER LISTA CON FILTROS ===
if (isset($_GET['action']) && $_GET['action'] === 'get_list') {
    if (empty($github_config)) { echo json_encode(['items' => [], 'total' => 0]); exit; }

    $url = "https://raw.githubusercontent.com/{$github_config['repo_owner']}/{$github_config['repo_name']}/{$github_config['branch']}/{$github_config['data_file']}";
    $json = @file_get_contents($url);
    if (!$json) { echo json_encode(['items' => [], 'total' => 0]); exit; }

    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['data'])) { echo json_encode(['items' => [], 'total' => 0]); exit; }

    $all_items = [];
    foreach ($data['data'] as $item) {
        $id = $item['id_tmdb'] ?? null;
        if (!$id) continue;
        $updated = $item['updated_at'] ?? $item['created_at'] ?? '1900-01-01';
        $type_raw = $item['type'] ?? 'unknown';
        $type_label = match($type_raw) {
            'movie' => 'Película',
            'animes' => 'Anime',
            'dorama' => 'Dorama',
            default => 'Series'
        };

        $all_items[] = [
            'id' => $id,
            'title' => $item['titulo'] ?? 'Sin título',
            'type' => $type_label,
            'type_raw' => $type_raw,
            'year' => $item['year'] ?? '',
            'quality' => $item['calidad'] ?? '',
            'image' => $item['imagen'] ?? "https://via.placeholder.com/300x450/1a2639/eee?text={$item['titulo']}",
            'updated' => $updated,
            'ts' => strtotime($updated)
        ];
    }

    usort($all_items, fn($a, $b) => $b['ts'] <=> $a['ts']);

    $search = trim($_GET['search'] ?? '');
    $filter_type = $_GET['filter_type'] ?? 'all';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 10;

    $filtered = array_filter($all_items, function($i) use ($search, $filter_type) {
        $match_title = empty($search) || stripos($i['title'], $search) !== false;
        $match_type = $filter_type === 'all' || $i['type_raw'] === $filter_type;
        return $match_title && $match_type;
    });

    $total = count($filtered);
    $items = array_slice($filtered, ($page - 1) * $per_page, $per_page);

    echo json_encode([
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $per_page),
        'has_prev' => $page > 1,
        'has_next' => $page < ceil($total / $per_page)
    ]);
    exit;
}

// === ESCAPAR MARKDOWN V2 ===
function escape_md2($text) {
    $text = str_replace('\\', '\\\\', $text);
    $chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    foreach ($chars as $char) {
        $text = str_replace($char, '\\' . $char, $text);
    }
    return $text;
}

// === GENERAR MENSAJE ===
function generarMensaje($item, $details) {
    $idiomas_raw = [];
    if ($item['type'] === 'movie') {
        foreach (($details['enlaces'] ?? []) as $e) {
            if (!empty($e['language'])) $idiomas_raw[] = $e['language'];
        }
    } else {
        foreach (($details['temporadas'] ?? []) as $t) {
            foreach (($t['capitulos'] ?? []) as $c) {
                foreach (($c['enlaces'] ?? []) as $e) {
                    if (!empty($e['language'])) $idiomas_raw[] = $e['language'];
                }
            }
        }
    }

    $idiomas = [];
    foreach ($idiomas_raw as $l) {
        $l = trim(mb_strtolower($l));
        if (in_array($l, ['latino', 'español', 'castellano'])) $idiomas['Latino'] = true;
        if (in_array($l, ['sub', 'subtitulado', 'ingles subtitulado', 'inglés subtitulado'])) $idiomas['Subtitulado'] = true;
    }
    $idiomas_str = $idiomas ? implode(', ', array_keys($idiomas)) : 'Ninguno';

    $temp_con_enlace = 0;
    if ($item['type'] !== 'movie') {
        foreach (($details['temporadas'] ?? []) as $t) {
            $has_link = false;
            foreach (($t['capitulos'] ?? []) as $c) {
                if (!empty($c['enlaces']) && is_array($c['enlaces'])) {
                    $has_link = true;
                    break;
                }
            }
            if ($has_link) $temp_con_enlace++;
        }
    }

    $titulo = $item['titulo'] ?? 'Sin título';
    $calidad = $item['calidad'] ?? 'No especificada';
    $etiquetas = $item['etiquetas'] ?? 'Ninguna';
    $sinopsis = $details['synopsis'] ?? 'Sin sinopsis.';
    $sinopsis = mb_substr($sinopsis, 0, 300) . (mb_strlen($sinopsis) > 300 ? '...' : '');

    $titulo_esc = escape_md2($titulo);
    $calidad_esc = escape_md2($calidad);
    $etiquetas_esc = escape_md2($etiquetas);
    $sinopsis_esc = escape_md2($sinopsis);
    $idiomas_esc = escape_md2($idiomas_str);

// === ICONO SEGÚN TIPO (EMOJI REAL) ===
    $tipo_icono = ($item['type'] === 'movie') ? "🎥" : "📺";

    // === MENSAJE CON EMOJIS REALES ===
    $caption = $tipo_icono . " *" . $titulo_esc . "*\n\n";

    if ($item['type'] === 'movie') {
        $caption .= "📽️ " . escape_md2("Calidad: ") . $calidad_esc . "\n";
        $caption .= "🌐 " . escape_md2("Idiomas Disponibles: ") . $idiomas_esc . "\n";
        $caption .= "_" . "🎭 " . escape_md2("Etiquetas: ") . $etiquetas_esc . "_\n";
        $caption .= "📖 " . escape_md2("Sinopsis:\n") . $sinopsis_esc;
    } else {
        $caption .= "🎬 " . escape_md2("Temporadas disponibles: ") . $temp_con_enlace . "\n";
        $caption .= "🌐 " . escape_md2("Idiomas Disponibles: ") . $idiomas_esc . "\n";
        $caption .= "_" . "🎭 " . escape_md2("Etiquetas: ") . $etiquetas_esc . "_\n";
        $caption .= "📖 " . escape_md2("Sinopsis:\n") . $sinopsis_esc;
    }

    return $caption;
}

// === ENVIAR ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_selected') {
    $ids = array_filter($_POST['ids'] ?? []);
    if (empty($ids) || empty($github_config) || empty($telegram_config)) {
        echo json_encode(['success' => false, 'message' => 'Faltan datos']);
        exit;
    }

    $owner = $github_config['repo_owner'];
    $repo = $github_config['repo_name'];
    $branch = $github_config['branch'];
    $folder = $github_config['details_folder'];
    $home_file = $github_config['data_file'];

    $home_url = "https://raw.githubusercontent.com/$owner/$repo/$branch/$home_file";
    $home_json = @file_get_contents($home_url);
    if (!$home_json) {
        echo json_encode(['success' => false, 'message' => 'No home.json']);
        exit;
    }

    $home_data = json_decode($home_json, true);
    $map = [];
    foreach ($home_data['data'] as $item) {
        $id = $item['id_tmdb'] ?? null;
        if ($id) $map[$id] = $item;
    }

    $enviados = $fallidas = $skipped = 0;

    foreach ($ids as $id) {
        if (!isset($map[$id])) { $skipped++; continue; }

        $item = $map[$id];
        $detail_url = "https://raw.githubusercontent.com/$owner/$repo/$branch/$folder$id.json";
        $json = @file_get_contents($detail_url);
        $details = $json ? json_decode($json, true) : [];

        $imagen = $details['imagen'] ?? $item['imagen'] ?? null;
        if (!$imagen) { $skipped++; continue; }

        $caption = generarMensaje($item, $details);

        if (enviarFotoPorUrl($telegram_config['bot_token'], $telegram_config['chat_id'], $imagen, $caption)) {
            $enviados++;
            logDebug("ENVIADO: $id");
        } else {
            $fallidas++;
            logDebug("FALLÓ: $id");
        }

        usleep(800000);
    }

    echo json_encode(['success' => true, 'enviados' => $enviados, 'fallidas' => $fallidas, 'skipped' => $skipped]);
    exit;
}

function enviarFotoPorUrl($token, $chat_id, $photo_url, $caption) {
    $url = "https://api.telegram.org/bot$token/sendPhoto";

    $data = [
        'chat_id' => $chat_id,
        'photo' => $photo_url,
        'caption' => $caption,
        'parse_mode' => 'MarkdownV2'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logDebug("Telegram → HTTP: $http_code | URL: $photo_url");
    logDebug("Respuesta: " . substr($response, 0, 300));

    $json = json_decode($response, true);
    $ok = $http_code == 200 && ($json['ok'] ?? false);

    if (!$ok) {
        logDebug("ERROR: " . ($json['description'] ?? 'Desconocido'));
    }

    return $ok;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar Telegram</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --primary: #3b82f6;
            --success: #10b981;
            --text: #f1f5f9;
            --muted: #94a3b8;
            --border: rgba(255,255,255,0.1);
            --secondary-dark: #1a2639;
            --text-light: #e2e8f0;
            --accent-blue: #2563eb;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); margin: 0; overflow-x: hidden; }
        .container-fluid { max-width: 1400px; margin: 0 auto; padding: 0 15px; }
        .container-fluid .navbar-toggler i{
          background: transparent;
          
        }
        /* NAVBAR */
        .navbar {
            background-color: var(--secondary-dark);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            padding: 1rem;
            position: sticky;
            top: 0;
            z-index: 1050;
        }
        .navbar-brand { color: var(--text-light); font-weight: 600; }
        .navbar-toggler { border: none; color: var(--text-light); font-size: 1.2rem; }

        /* SIDEBAR */
        .sidebar {
            margin-top: 0px;
            background-color: var(--secondary-dark);
            height: 100vh;
            position: fixed;
            width: 250px;
            overflow-y: auto;
            transform: translateX(-250px);
            transition: transform 0.3s ease-in-out;
            z-index: 1000;
        }
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.5); z-index: 999;
            opacity: 0; visibility: hidden; transition: all 0.3s ease-in-out;
        }
        .sidebar-overlay.active { opacity: 1; visibility: visible; }
        .sidebar a {
            color: var(--text-light); padding: 12px 20px; display: flex; align-items: center;
            text-decoration: none; transition: background-color 0.3s, color 0.3s;
        }
        .sidebar a:hover { background-color: var(--accent-blue); color: #ffffff; }
        .sidebar a i { margin-right: 12px; width: 20px; text-align: center; }

        /* CONTENT */
        .content {
            margin-left: 0;
            padding: 1.5rem;
            transition: margin-left 0.3s ease-in-out;
        }
        @media (min-width: 992px) {
            .sidebar { transform: translateX(0); }
            .content { margin-left: 250px; }
        }

        /* CARDS & GRID */
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; margin-bottom: 1.5rem; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        .card-header { padding: 1rem 1.5rem; background: rgba(255,255,255,0.05); border-bottom: 1px solid var(--border); font-weight: 600; font-size: 1.1rem; }
        .card-body { padding: 1.5rem; }
        input, select { background: rgba(255,255,255,0.05); border: 1px solid var(--border); color: var(--text); border-radius: 8px; padding: 0.5rem 0.75rem; width: 100%; transition: all 0.2s; }
        input:focus, select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,0.25); }
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-success { background: var(--success); color: white; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; }
        .item-card { background: rgba(255,255,255,0.05); border-radius: 12px; overflow: hidden; cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
        .item-card:hover { transform: translateY(-4px); box-shadow: 0 8px 16px rgba(0,0,0,0.3); }
        .item-card.selected { border-color: var(--primary); }
        .item-img { width: 100%; height: 260px; object-fit: cover; }
        .item-info { padding: 0.75rem; }
        .item-title { font-weight: 600; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .item-meta { font-size: 0.8rem; color: var(--muted); margin-top: 0.25rem; }
        .progress { height: 8px; background: rgba(255,255,255,0.1); border-radius: 4px; overflow: hidden; margin: 1rem 0; }
        .progress-bar { height: 100%; background: var(--success); width: 0; transition: width 0.3s ease; }
        .debug { background: #000; color: #0f0; padding: 1rem; border-radius: 8px; font-family: monospace; font-size: 0.8rem; max-height: 400px; overflow-y: auto; white-space: pre-wrap; }
        .row { display: flex; gap: 1rem; flex-wrap: wrap; }
        .col { flex: 1; min-width: 200px; }
        .alert { padding: 1rem; border-radius: 12px; margin: 1rem 0; font-size: 0.95rem; }
        .alert-info { background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); color: #93c5fd; }
        .alert-warning { background: rgba(251,146,60,0.1); border: 1px solid rgba(251,146,60,0.3); color: #fdba74; }
        .spinner { width: 2rem; height: 2rem; border: 0.25em solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spin 0.75s linear infinite; }
        .pagination { display: flex; justify-content: center; gap: 0.5rem; margin: 1rem 0; }
        .pagination button { background: rgba(255,255,255,0.1); color: var(--text); border: 1px solid var(--border); padding: 0.5rem 1rem; border-radius: 8px; cursor: pointer; }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination button.active { background: var(--primary); }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" id="sidebarToggle">
                <i class="fas fa-bars text-light" id="toggleIcon"></i>
            </button>
            <a class="navbar-brand ms-3" href="#">Panel de Administración</a>
        </div>
    </nav>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <a href="panel.php"><i class="fas fa-home"></i> Inicio</a>
        <a href="catalogo_movie.php"><i class="fas fa-film"></i> Catálogo de Películas</a>
        <a href="catalogo_serie.php"><i class="fas fa-tv"></i> Catálogo de Series</a>
        <a href="subir_contenido.php"><i class="fas fa-upload"></i> Subir Contenido</a>
        <a href="config_github.php"><i class="fab fa-github"></i> Configuración GitHub</a>
        <a href="subir_telegram.php" class="active"><i class="fab fa-telegram-plane"></i> Configurar Telegram</a>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Content -->
    <div class="content">
        <div class="container-fluid">

            <!-- Config Telegram -->
            <div class="card">
                <div class="card-header">Configuración Telegram</div>
                <div class="card-body">
                    <form id="form" class="row">
                        <input type="hidden" name="action" value="save_telegram">
                        <div class="col"><input type="password" name="bot_token" placeholder="Bot Token" value="<?=htmlspecialchars($telegram_config['bot_token']??'')?>" required></div>
                        <div class="col"><input type="text" name="chat_id" placeholder="Chat ID" value="<?=htmlspecialchars($telegram_config['chat_id']??'')?>" required></div>
                        <div class="col" style="flex:0 0 100px;"><button type="submit" class="btn btn-primary w-100">Guardar</button></div>
                    </form>
                </div>
            </div>

            <!-- Filtros y Resultados -->
            <div id="content">
                <?php if (empty($github_config)): ?>
                    <div class="alert alert-info">Configura GitHub primero.</div>
                <?php elseif (empty($telegram_config)): ?>
                    <div class="alert alert-info">Guarda Telegram.</div>
                <?php else: ?>
                    <div id="filters" class="card">
                        <div class="card-header">Filtros</div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col">
                                    <input type="text" id="search" placeholder="Buscar por título..." onkeyup="loadList()">
                                </div>
                                <div class="col">
                                    <select id="filter_type" onchange="loadList()">
                                        <option value="all">Todos los tipos</option>
                                        <option value="movie">Película</option>
                                        <option value="series">Series</option>
                                        <option value="animes">Animes</option>
                                        <option value="dorama">Dorama</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="results"></div>
                <?php endif; ?>
            </div>

            <!-- LOG -->
            <?php if (file_exists($log_file)): ?>
                <div class="card">
                    <div class="card-header">LOG (últimas 50 líneas)</div>
                    <div class="card-body p-0">
                        <pre class="debug"><?php echo htmlspecialchars(implode('', array_slice(file($log_file), -50))); ?></pre>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // === SIDEBAR TOGGLE ===
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggle = document.getElementById('sidebarToggle');

        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });

        // === FORM & LIST ===
        const form = document.getElementById('form');
        const results = document.getElementById('results');
        let selected = [];

        form.addEventListener('submit', e => {
            e.preventDefault();
            const fd = new FormData(form);
            fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { alert(d.message); if (d.success) setTimeout(() => location.reload(), 500); });
        });

        function getFilters() {
            return {
                search: document.getElementById('search').value,
                filter_type: document.getElementById('filter_type').value,
                page: window.currentPage || 1
            };
        }

        function loadList() {
            const filters = getFilters();
            const params = new URLSearchParams({ action: 'get_list', ...filters });
            fetch(`?${params}`)
                .then(r => r.json())
                .then(data => {
                    window.currentPage = data.page;
                    renderResults(data);
                });
        }

        function renderResults(data) {
            if (!data.items?.length) {
                results.innerHTML = '<div class="alert alert-warning">No se encontraron resultados.</div>';
                return;
            }

            let html = `<div class="card">
                <div class="card-header">
                    <span>Resultados: ${data.total} (${data.page}/${data.pages})</span>
                    <button id="send-btn" class="btn btn-success btn-sm" disabled>Enviar</button>
                </div>
                <div class="card-body p-0">
                    <div class="grid p-3">`;

            data.items.forEach(i => {
                html += `<div class="item-card" data-id="${i.id}" onclick="toggleSelect(this, '${i.id}')">
                    <img class="item-img" src="${i.image}" alt="">
                    <div class="item-info">
                        <div class="item-title">${i.title}</div>
                        <div class="item-meta">${i.type} • ${i.year} ${i.quality?'• '+i.quality:''}</div>
                    </div>
                </div>`;
            });

            html += `</div></div>
                <div class="p-3">
                    <div class="progress"><div class="progress-bar" id="bar"></div></div>
                    <div class="pagination">
                        <button onclick="changePage(${data.page - 1})" ${!data.has_prev ? 'disabled' : ''}>Anterior</button>
                        <button class="active">${data.page}</button>
                        <button onclick="changePage(${data.page + 1})" ${!data.has_next ? 'disabled' : ''}>Siguiente</button>
                    </div>
                </div>
            </div>`;

            results.innerHTML = html;
            document.getElementById('send-btn').onclick = sendSelected;
        }

        function changePage(page) {
            window.currentPage = page;
            loadList();
        }

        function toggleSelect(el, id) {
            el.classList.toggle('selected');
            if (el.classList.contains('selected')) selected.push(id);
            else selected = selected.filter(x => x !== id);
            updateButtons();
        }

        function updateButtons() {
            const n = selected.length;
            const btn = document.getElementById('send-btn');
            if (btn) {
                btn.disabled = n === 0;
                btn.textContent = `Enviar (${n})`;
            }
        }

        function sendSelected() {
            if (selected.length === 0) return;
            const bar = document.getElementById('bar');
            let done = 0;
            const btn = document.getElementById('send-btn');
            btn.disabled = true;
            btn.textContent = 'Enviando...';

            selected.forEach((id, i) => {
                setTimeout(() => {
                    const fd = new FormData();
                    fd.append('action', 'send_selected');
                    fd.append('ids[]', id);
                    fetch('', { method: 'POST', body: fd })
                        .then(() => { done++; bar.style.width = `${(done/selected.length)*100}%`; })
                        .finally(() => {
                            if (done === selected.length) {
                                alert('Envío completado');
                                btn.disabled = false;
                                btn.textContent = 'Enviar';
                                selected = [];
                                loadList();
                            }
                        });
                }, i * 800);
            });
        }

        <?php if (!empty($github_config) && !empty($telegram_config)): ?>
        loadList();
        <?php endif; ?>
    </script>
</body>
</html>