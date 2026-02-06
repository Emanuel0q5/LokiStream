<?php
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

$config_file = 'config/github_config.json';
$tmdb_config_file = 'config/tmdb_config.json';
$github_config = [];
$tmdb_config = [];

if (file_exists($config_file)) {
    $github_config = json_decode(file_get_contents($config_file), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("ERROR: Invalid JSON in $config_file: " . json_last_error_msg());
        $github_config = [];
    }
}
if (file_exists($tmdb_config_file)) {
    $tmdb_config = json_decode(file_get_contents($tmdb_config_file), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("ERROR: Invalid JSON in $tmdb_config_file: " . json_last_error_msg());
        $tmdb_config = [];
    }
}

$api_key = $tmdb_config['api_key'] ?? '';
$id = $_GET['id'] ?? '';
$type = $_GET['type'] ?? 'series';
$item = null;
$details = null;
$extract = isset($_GET['extract']);
$extract_specials = isset($_GET['extract_specials']);

// DEBUG: Initial information
error_log("=== DEBUG: Loading for ID: $id, Type: $type ===");

// Check GitHub existence flags
$item_exists_in_github = false;
$details_exist_in_github = false;

if ($id && !empty($github_config)) {
    // Load home.json
    $api_url = "https://api.github.com/repos/{$github_config['repo_owner']}/{$github_config['repo_name']}/contents/{$github_config['data_file']}?ref={$github_config['branch']}";
    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$github_config['token']}",
            "Accept: application/vnd.github.v3+json",
            "User-Agent: PHP-App"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $data = json_decode($response, true);
        $content = base64_decode($data['content']);
        $json_data = json_decode($content, true);
        $found_items = array_filter($json_data['data'] ?? [], fn($item) => $item['id_tmdb'] === $id && $item['type'] === $type);
        if (!empty($found_items)) {
            $item = array_shift($found_items);
            $item_exists_in_github = true;
            error_log("DEBUG: Item found in home.json on GitHub");
        } else {
            error_log("DEBUG: Item NOT found in home.json on GitHub - Will create new");
        }
    } else {
        error_log("DEBUG: Failed to load home.json. HTTP Code: $http_code");
    }

    // Load details/<id_tmdb>.json
    $detail_url = "https://api.github.com/repos/{$github_config['repo_owner']}/{$github_config['repo_name']}/contents/{$github_config['details_folder']}$id.json?ref={$github_config['branch']}";
    $ch = curl_init($detail_url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$github_config['token']}",
            "Accept: application/vnd.github.v3+json",
            "User-Agent: PHP-App"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    $detail_response = curl_exec($ch);
    $detail_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("DEBUG: Details HTTP Code: $detail_http_code");
    if ($detail_http_code === 200) {
        $detail_data = json_decode($detail_response, true);
        if (isset($detail_data['content']) && !empty($detail_data['content'])) {
            $details_content = base64_decode($detail_data['content']);
            $details = json_decode($details_content, true);
            $details_exist_in_github = true;
            error_log("DEBUG: Details found on GitHub");
        }
    } elseif ($detail_http_code === 404) {
        error_log("DEBUG: Details file NOT found on GitHub - Will create new");
    } else {
        error_log("DEBUG: Error loading details. HTTP Code: $detail_http_code");
    }
}

// If details not found on GitHub, attempt to load from local
if (!$details && $id) {
    $local_details_file = 'local_data/' . $id . '_details_backup.json';
    if (file_exists($local_details_file)) {
        $details_content = file_get_contents($local_details_file);
        $details = json_decode($details_content, true);
        error_log("DEBUG: Loading details from local backup");
    }
}

// Fetch from TMDB if needed
if ((!$item || $extract || $extract_specials) && $id && $api_key) {
    $api_url = "https://api.themoviedb.org/3/tv/$id?api_key=$api_key&language=es";
    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $tmdb_data = json_decode($response, true);
        if (!$item) {
            $item = [
                'id_tmdb' => $id,
                'titulo' => $tmdb_data['name'] ?? '',
                'imagen' => $tmdb_data['poster_path'] ? "https://image.tmdb.org/t/p/w342{$tmdb_data['poster_path']}" : '',
                'etiquetas' => implode(', ', array_map(fn($g) => $g['name'], $tmdb_data['genres'] ?? [])),
                'type' => $type,
                'created_at' => $tmdb_data['first_air_date'] ?? date('Y-m-d'),
                'updated_at' => date('Y-m-d\TH:i:s.v\Z'),
                'year' => date('Y', strtotime($tmdb_data['first_air_date'] ?? ''))
            ];
        }
        if (!$details || $extract || $extract_specials) {
            $details = $details ?: [];
            $details['synopsis'] = $tmdb_data['overview'] ?? '';
            $details['titulo'] = $tmdb_data['name'] ?? '';
            $details['imagen'] = $tmdb_data['poster_path'] ? "https://image.tmdb.org/t/p/w342{$tmdb_data['poster_path']}" : '';
            $details['etiquetas'] = implode(', ', array_map(fn($g) => $g['name'], $tmdb_data['genres'] ?? []));
            $details['year'] = date('Y', strtotime($tmdb_data['first_air_date'] ?? ''));
            $details['type'] = $type;

            $seasons = [];
            $season_numbers = $extract_specials ? [0] : array_map(fn($s) => $s['season_number'], $tmdb_data['seasons'] ?? []);
            foreach ($season_numbers as $season_number) {
                $season_api_url = "https://api.themoviedb.org/3/tv/$id/season/$season_number?api_key=$api_key&language=es";
                $ch = curl_init($season_api_url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                $season_response = curl_exec($ch);
                curl_close($ch);
                $season_data = json_decode($season_response, true);
                $capitulos = [];
                foreach ($season_data['episodes'] ?? [] as $episode) {
                    $episode_number = sprintf("%02d", $episode['episode_number']);
                    $episode_name = $episode['name'] ?? '';
                    $formatted_name = "{$episode_number}. {$episode_name}";
                    
                    $enlaces_existentes = [];
                    if ($details && isset($details['temporadas'])) {
                        foreach ($details['temporadas'] as $temp) {
                            if (($temp['temporada'] === "Especiales" && $season_number === 0) || 
                                ($temp['temporada'] === "Temporada {$season_number}")) {
                                foreach ($temp['capitulos'] as $cap) {
                                    if ($cap['episode_id'] == $episode_number) {
                                        $enlaces_existentes = $cap['enlaces'] ?? [];
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                    
                    $capitulos[] = [
                        'episode_id' => $episode_number,
                        'nombre' => $formatted_name,
                        'portada' => $episode['still_path'] ? "https://image.tmdb.org/t/p/w300{$episode['still_path']}" : '',
                        'enlaces' => $enlaces_existentes
                    ];
                }
                $seasons[] = [
                    'temporada' => $season_number === 0 ? "Especiales" : "Temporada {$season_number}",
                    'capitulos' => $capitulos
                ];
            }
            $details['temporadas'] = $seasons;
        }
    }
}

// Save copy to local if details are available (from GitHub or TMDB)
if ($details && $id) {
    $local_details_file = 'local_data/' . $id . '_details_backup.json';
    $local_dir = dirname($local_details_file);
    if (!is_dir($local_dir)) {
        mkdir($local_dir, 0755, true);
    }
    file_put_contents($local_details_file, json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    error_log("DEBUG: Saved copy of details to local backup");
}

// Process POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start(); // Start output buffering to capture debug
    error_log("=== INICIANDO PROCESAMIENTO POST at " . date('Y-m-d H:i:s') . " ===");

    // Log raw POST data
    $post_data = print_r($_POST, true);
    error_log("DEBUG: Raw POST data: $post_data");

    $type = $_POST['type'] ?? ($item['type'] ?? 'series');
    $id_tmdb = $_POST['id_tmdb'];

    // Prepare item
    $new_item = [
        'id_tmdb' => $id_tmdb,
        'imagen' => $_POST['imagen'],
        'titulo' => $_POST['titulo'],
        'etiquetas' => $_POST['etiquetas'],
        'type' => $type,
        'created_at' => $_POST['created_at'] ?: date('Y-m-d'),
        'updated_at' => date('Y-m-d\TH:i:s.v\Z'),
        'year' => $_POST['year']
    ];

    // Prepare details
    $new_details = [
        'id_tmdb' => $id_tmdb,
        'titulo' => $_POST['titulo'],
        'imagen' => $_POST['imagen'],
        'etiquetas' => $_POST['etiquetas'],
        'year' => $_POST['year'],
        'type' => $type,
        'synopsis' => $_POST['synopsis'],
        'created_at' => $_POST['created_at'] ?: date('Y-m-d'),
        'updated_at' => date('Y-m-d\TH:i:s.v\Z'),
        'temporadas' => []
    ];

    // Process seasons and episodes
    $temporadas = [];
    if (isset($_POST['temporada_nombre']) && is_array($_POST['temporada_nombre'])) {
        foreach ($_POST['temporada_nombre'] as $t_index => $temporada_nombre) {
            if (empty(trim($temporada_nombre))) continue;
            $capitulos = [];
            if (isset($_POST['capitulo_episode_id'][$t_index]) && is_array($_POST['capitulo_episode_id'][$t_index])) {
                foreach ($_POST['capitulo_episode_id'][$t_index] as $c_index => $episode_id) {
                    if (!empty(trim($episode_id))) {
                        $enlaces = [];
                        if (isset($_POST['enlace_nombre'][$t_index][$c_index]) && is_array($_POST['enlace_nombre'][$t_index][$c_index])) {
                            foreach ($_POST['enlace_nombre'][$t_index][$c_index] as $e_index => $nombre_enlace) {
                                if (!empty(trim($nombre_enlace))) {
                                    $enlaces[] = [
                                        'nombre' => trim($nombre_enlace),
                                        'url' => trim($_POST['enlace_url'][$t_index][$c_index][$e_index] ?? ''),
                                        'type' => trim($_POST['enlace_type'][$t_index][$c_index][$e_index] ?? 'embed'),
                                        'language' => trim($_POST['enlace_language'][$t_index][$c_index][$e_index] ?? 'Latino')
                                    ];
                                }
                            }
                        }
                        $capitulos[] = [
                            'episode_id' => trim($episode_id),
                            'nombre' => trim($_POST['capitulo_nombre'][$t_index][$c_index] ?? ''),
                            'portada' => trim($_POST['capitulo_portada'][$t_index][$c_index] ?? ''),
                            'enlaces' => $enlaces
                        ];
                    }
                }
            }
            $temporadas[] = ['temporada' => trim($temporada_nombre), 'capitulos' => $capitulos];
        }
    }
    $new_details['temporadas'] = $temporadas;

    $local_details_file = 'local_data/' . $id_tmdb . '_details_backup.json';
    $local_dir = dirname($local_details_file);
    if (!is_dir($local_dir)) {
        mkdir($local_dir, 0755, true);
    }
    file_put_contents($local_details_file, json_encode($new_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $details_updated = false;
    if (!empty($github_config)) {
        $details_content = base64_encode(json_encode($new_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $detail_url = "https://api.github.com/repos/{$github_config['repo_owner']}/{$github_config['repo_name']}/contents/{$github_config['details_folder']}$id_tmdb.json";
        $ch = curl_init($detail_url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$github_config['token']}",
                "Accept: application/vnd.github.v3+json",
                "User-Agent: PHP-App"
            ],
            CURLOPT_RETURNTRANSFER => true
        ]);
        $detail_response = curl_exec($ch);
        $detail_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $detail_data = $detail_http_code === 200 ? json_decode($detail_response, true) : ['sha' => null];

        $ch = curl_init($detail_url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$github_config['token']}",
                "Accept: application/vnd.github.v3+json",
                "User-Agent: PHP-App"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode([
                'message' => ($detail_data['sha'] ? 'Update' : 'Create') . " details for $type with ID $id_tmdb",
                'content' => $details_content,
                'sha' => $detail_data['sha'],
                'branch' => $github_config['branch']
            ]),
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $details_response = curl_exec($ch);
        $details_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (in_array($details_http_code, [200, 201])) {
            $details_updated = true;
            if (file_exists($local_details_file)) unlink($local_details_file);
            error_log("Local file deleted after successful GitHub upload");
        } else {
            error_log("Failed to upload to GitHub. HTTP Code: $details_http_code");
        }
    }

    // Update home.json
    if (!empty($github_config)) {
        $api_url = "https://api.github.com/repos/{$github_config['repo_owner']}/{$github_config['repo_name']}/contents/{$github_config['data_file']}?ref={$github_config['branch']}";
        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$github_config['token']}",
                "Accept: application/vnd.github.v3+json",
                "User-Agent: PHP-App"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $data = json_decode($response, true);
            $content = base64_decode($data['content']);
            $json_data = json_decode($content, true);

            // Filter out existing entry
            $json_data['data'] = array_filter($json_data['data'], fn($entry) => $entry['id_tmdb'] !== $id_tmdb || $entry['type'] !== $type);

            // Add new entry
            $json_data['data'][] = $new_item;

            $new_content = base64_encode(json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $ch = curl_init($api_url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$github_config['token']}",
                    "Accept: application/vnd.github.v3+json",
                    "User-Agent: PHP-App"
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => json_encode([
                    'message' => 'Add/Update ' . $type . ' with ID ' . $id_tmdb,
                    'content' => $new_content,
                    'sha' => $data['sha'],
                    'branch' => $github_config['branch']
                ]),
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            $home_response = curl_exec($ch);
            $home_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (in_array($home_http_code, [200, 201])) {
                $home_updated = true;
            } else {
                error_log("Failed to update home.json. HTTP Code: $home_http_code");
            }
        } else {
            error_log("Failed to load home.json for update. HTTP Code: $http_code");
        }
    }

    if ($details_updated && $home_updated) {
        header('Location: panel.php?success=1');
        exit;
    } else {
        echo '<div class="alert alert-danger">Error al guardar. Detalles: ' . ($details_updated ? 'OK' : 'Falló') . ', Home: ' . ($home_updated ? 'OK' : 'Falló') . '</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agregar/Editar Serie/Animes/Dorama - Panel de Administración</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-dark: #1a2639;
            --secondary-dark: #2e3b55;
            --accent-blue: #3b82f6;
            --text-light: #e5e7eb;
            --card-bg: #2d3748;
            --border-light: rgba(255, 255, 255, 0.1);
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background-color: var(--primary-dark);
            color: var(--text-light);
            margin: 0;
            overflow-x: hidden;
        }

        .navbar {
            background-color: var(--secondary-dark);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            padding: 1rem;
            position: sticky;
            top: 0;
            z-index: 1050;
        }

        .navbar-brand {
            color: var(--text-light);
            font-weight: 600;
        }

        .navbar-toggler {
            border: none;
            color: var(--text-light);
            font-size: 1.2rem;
        }

        .sidebar {
            background-color: var(--secondary-dark);
            height: 100vh;
            position: fixed;
            width: 250px;
            overflow-y: auto;
            transform: translateX(-250px);
            transition: transform 0.3s ease-in-out;
            z-index: 1000;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease-in-out;
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .sidebar a {
            color: var(--text-light);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: background-color 0.3s, color 0.3s;
        }

        .sidebar a:hover {
            background-color: var(--accent-blue);
            color: #ffffff;
        }

        .sidebar a i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        .content {
            margin-left: 0;
            padding: 0px;
            transition: margin-left 0.3s ease-in-out;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .content.sidebar-open {
            margin-left: 250px;
        }

        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            padding: 25px;
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--border-light);
            color: var(--text-light);
            font-weight: 600;
            margin-bottom: 25px;
            padding-bottom: 10px;
            font-size: 1.25rem;
        }

        .form-label {
            color: var(--text-light);
            font-weight: 500;
            margin-bottom: 5px;
        }

        .form-control {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-light);
            color: var(--text-light);
            border-radius: 6px;
            min-height: 38px;
        }

        .form-control:focus {
            background-color: var(--secondary-dark);
            border-color: var(--accent-blue);
            color: var(--text-light);
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }

        .btn-primary {
            background-color: var(--accent-blue);
            border-color: var(--accent-blue);
            padding: 10px 20px;
            font-size: 1rem;
            font-weight: 500;
        }

        .btn-primary:hover {
            background-color: #2563eb;
            border-color: #2563eb;
        }

        .btn-secondary {
            background-color: transparent;
            border: 1px solid var(--border-light);
            color: var(--text-light);
            padding: 10px 20px;
            font-size: 1rem;
            font-weight: 500;
        }

        .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .info-text {
            color: #9ca3af;
            font-size: 0.85rem;
            margin-top: 5px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .extra-item {
            margin-top: 15px;
            padding: 15px;
            background-color: var(--secondary-dark);
            border-radius: 6px;
            border: 1px solid var(--border-light);
        }

        .remove-btn {
            margin-left: 10px;
            color: #ef4444;
            cursor: pointer;
            font-size: 1rem;
        }

        .add-extra-btn {
            margin-top: 10px;
        }

        .chapter-section {
            margin-top: 10px;
            padding-left: 5px;
        }

        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border: 1px solid #10b981;
            color: #10b981;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .accordion-button {
            background-color: var(--secondary-dark);
            color: var(--text-light);
        }

        .accordion-body {
            background-color: var(--card-bg);
        }

        .episode-img {
            max-width: 100px;
            height: auto;
            margin-bottom: 10px;
        }

        .enlace-item {
            margin-bottom: 10px;
            padding: 10px;
            background-color: var(--secondary-dark);
            border-radius: 6px;
            border: 1px solid var(--border-light);
        }

        .debug-panel {
            background-color: #1f2937;
            border: 1px solid #374151;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .debug-success {
            color: #10b981;
            font-weight: 600;
        }

        .debug-error {
            color: #ef4444;
            font-weight: 600;
        }

        .debug-warning {
            color: #f59e0b;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .container {
                margin: 10px;
                padding: 10px;
            }

            .card {
                padding: 15px;
            }

            .form-group {
                margin-bottom: 15px;
            }

            .navbar {
                padding: 10px 15px;
            }

            .navbar-brand {
                font-size: 1.2rem;
            }

            .button-group {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Overlay para mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
          <!--  <button class="navbar-toggler" type="button" id="sidebarToggle">
                <i class="fas fa-bars text-light" id="toggleIcon"></i>
            </button>-->
            <a class="navbar-brand ms-3" href="#">Panel de Administración</a>
        </div>
    </nav>

    <!-- Sidebar --><!--
    <div class="sidebar" id="sidebar">
        <a href="panel.php"><i class="fas fa-home"></i> Inicio</a>
        <a href="catalogo_movie.php"><i class="fas fa-film"></i> Catálogo de Películas</a>
        <a href="catalogo_serie.php"><i class="fas fa-tv"></i> Catálogo de Series</a>
        <a href="subir_contenido.php"><i class="fas fa-upload"></i> Subir Contenido</a>
        <a href="config_github.php"><i class="fab fa-github"></i> Configuración GitHub</a>
        <a href="subir_telegram.php"><i class="fab fa-telegram-plane"></i> Configurar Telegram</a>
    </div>-->

    <!-- Content -->
    <div class="content" id="content">
        <div class="container">
            <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle"></i> Contenido guardado exitosamente. <a href="home.php" class="text-decoration-none">Volver al inicio</a>
                </div>
            <?php endif; ?>
            
            <!-- PANEL DE DEPURACIÓN -->
            <div class="debug-panel">
                <h6 class="mb-3">🔍 Información de Depuración</h6>
                <div class="row">
                    <div class="col-md-6">
                        <strong>ID TMDB:</strong> <span class="debug-success"><?php echo $id; ?></span><br>
                        <strong>Tipo:</strong> <span class="debug-success"><?php echo $type; ?></span><br>
                        <strong>Estado en GitHub:</strong><br>
                        &nbsp;&nbsp;- <strong>Home:</strong> 
                        <?php if ($item_exists_in_github): ?>
                            <span class="debug-success">EXISTE</span>
                        <?php else: ?>
                            <span class="debug-warning">NUEVO (se creará)</span>
                        <?php endif; ?>
                        <br>
                        &nbsp;&nbsp;- <strong>Detalles:</strong> 
                        <?php if ($details_exist_in_github): ?>
                            <span class="debug-success">EXISTE</span>
                        <?php else: ?>
                            <span class="debug-warning">NUEVO (se creará)</span>
                        <?php endif; ?>
                        <br>
                        <strong>Temporadas cargadas:</strong> 
                        <?php if (isset($details['temporadas']) && is_array($details['temporadas'])): ?>
                            <span class="debug-success"><?php echo count($details['temporadas']); ?></span>
                        <?php else: ?>
                            <span class="debug-warning">0</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Acciones al guardar:</strong><br>
                        <?php if ($item_exists_in_github): ?>
                            <span class="debug-success">✓ Actualizará home.json</span><br>
                        <?php else: ?>
                            <span class="debug-warning">+ Creará nuevo en home.json</span><br>
                        <?php endif; ?>
                        <?php if ($details_exist_in_github): ?>
                            <span class="debug-success">✓ Actualizará detalles</span><br>
                        <?php else: ?>
                            <span class="debug-warning">+ Creará nuevos detalles</span><br>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-edit"></i> 
                    <?php 
                    if ($id) {
                        echo $item_exists_in_github ? 'Editar' : 'Agregar';
                    } else {
                        echo 'Agregar';
                    }
                    ?> 
                    <?php echo ucfirst($type); ?>
                    
                    <?php if ($id && !$item_exists_in_github): ?>
                        <span class="badge bg-warning ms-2">NUEVO EN GITHUB</span>
                    <?php endif; ?>
                </div>
                <form method="POST" id="contentForm">
                    <!-- Campos básicos -->
                    <div class="form-group">
                        <label for="id_tmdb" class="form-label">ID TMDB <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="id_tmdb" name="id_tmdb" value="<?php echo htmlspecialchars($item['id_tmdb'] ?? ''); ?>" required>
                        <?php if ($id && !$item_exists_in_github): ?>
                            <small class="text-warning"><i class="fas fa-exclamation-triangle"></i> Este contenido se agregará como nuevo en GitHub</small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="imagen" class="form-label">URL de la Imagen <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" id="imagen" name="imagen" value="<?php echo htmlspecialchars($item['imagen'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="titulo" class="form-label">Título <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="titulo" name="titulo" value="<?php echo htmlspecialchars($item['titulo'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="etiquetas" class="form-label">Etiquetas <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="etiquetas" name="etiquetas" value="<?php echo htmlspecialchars($item['etiquetas'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="type" class="form-label">Tipo <span class="text-danger">*</span></label>
                        <select class="form-control" id="type" name="type" required>
                            <option value="series" <?php echo (!$id || ($item['type'] ?? 'series') === 'series') ? 'selected' : ''; ?>>Serie</option>
                            <option value="animes" <?php echo ($item['type'] ?? '') === 'animes' ? 'selected' : ''; ?>>Animes</option>
                            <option value="dorama" <?php echo ($item['type'] ?? '') === 'dorama' ? 'selected' : ''; ?>>Dorama</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="created_at" class="form-label">Fecha de Creación</label>
                        <input type="date" class="form-control" id="created_at" name="created_at" value="<?php echo htmlspecialchars($item['created_at'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="year" class="form-label">Año <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="year" name="year" value="<?php echo htmlspecialchars($item['year'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="synopsis" class="form-label">Sinopsis <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="synopsis" name="synopsis" required rows="5"><?php echo htmlspecialchars($details['synopsis'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- SECCIÓN DE TEMPORADAS -->
                    <div class="form-group extra-section">
                        <label class="form-label">Temporadas</label>
                        <?php if ($id && $api_key): ?>
                            <a href="?id=<?php echo $id; ?>&type=<?php echo $type; ?>&extract=1" class="btn btn-secondary mb-2">Extraer Temporadas de TMDB</a>
                            <a href="?id=<?php echo $id; ?>&type=<?php echo $type; ?>&extract_specials=1" class="btn btn-secondary mb-2">Extraer Especiales de TMDB</a>
                        <?php endif; ?>
                        <div class="accordion" id="temporadas-accordion">
                            <?php if (isset($details['temporadas']) && is_array($details['temporadas']) && !empty($details['temporadas'])): ?>
                                <?php foreach ($details['temporadas'] as $t_index => $temporada): ?>
                                    <div class="accordion-item extra-item">
                                        <h2 class="accordion-header" id="temporada-heading-<?php echo $t_index; ?>">
                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#temporada-collapse-<?php echo $t_index; ?>" aria-expanded="false" aria-controls="temporada-collapse-<?php echo $t_index; ?>">
                                                <?php echo htmlspecialchars($temporada['temporada'] ?? "Temporada " . ($t_index + 1)); ?>
                                            </button>
                                        </h2>
                                        <div id="temporada-collapse-<?php echo $t_index; ?>" class="accordion-collapse collapse" aria-labelledby="temporada-heading-<?php echo $t_index; ?>">
                                            <div class="accordion-body">
                                                <input type="text" class="form-control mb-2" name="temporada_nombre[]" value="<?php echo htmlspecialchars($temporada['temporada'] ?? ''); ?>" placeholder="Nombre de la temporada" required>
                                                <button type="button" class="btn btn-danger btn-sm mb-2" onclick="removeExtra(this, 'temporada')">
                                                    <i class="fas fa-trash"></i> Eliminar Temporada
                                                </button>
                                                
                                                <div class="chapter-section accordion" id="capitulos-accordion-<?php echo $t_index; ?>">
                                                    <?php if (isset($temporada['capitulos']) && is_array($temporada['capitulos'])): ?>
                                                        <?php foreach ($temporada['capitulos'] as $c_index => $capitulo): ?>
                                                            <div class="accordion-item">
                                                                <h3 class="accordion-header" id="capitulo-heading-<?php echo $t_index; ?>-<?php echo $c_index; ?>">
                                                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#capitulo-collapse-<?php echo $t_index; ?>-<?php echo $c_index; ?>" aria-expanded="false" aria-controls="capitulo-collapse-<?php echo $t_index; ?>-<?php echo $c_index; ?>">
                                                                        <?php echo htmlspecialchars($capitulo['nombre'] ?? "Episodio " . ($c_index + 1)); ?>
                                                                    </button>
                                                                </h3>
                                                                <div id="capitulo-collapse-<?php echo $t_index; ?>-<?php echo $c_index; ?>" class="accordion-collapse collapse" aria-labelledby="capitulo-heading-<?php echo $t_index; ?>-<?php echo $c_index; ?>">
                                                                    <div class="accordion-body">
                                                                        <div class="mb-2">
                                                                            <label class="form-label">ID Episodio *</label>
                                                                            <input type="text" class="form-control" name="capitulo_episode_id[<?php echo $t_index; ?>][]" value="<?php echo htmlspecialchars($capitulo['episode_id'] ?? ''); ?>" required>
                                                                        </div>
                                                                        <div class="mb-2">
                                                                            <label class="form-label">Nombre del episodio *</label>
                                                                            <input type="text" class="form-control" name="capitulo_nombre[<?php echo $t_index; ?>][]" value="<?php echo htmlspecialchars($capitulo['nombre'] ?? ''); ?>" required>
                                                                        </div>
                                                                        <div class="mb-2">
                                                                            <label class="form-label">URL de la portada</label>
                                                                            <input type="url" class="form-control" name="capitulo_portada[<?php echo $t_index; ?>][]" value="<?php echo htmlspecialchars($capitulo['portada'] ?? ''); ?>" onchange="updateImagePreview(this, <?php echo $t_index; ?>, <?php echo $c_index; ?>)">
                                                                        </div>
                                                                        <?php if (!empty($capitulo['portada'])): ?>
                                                                            <img src="<?php echo htmlspecialchars($capitulo['portada']); ?>" alt="Preview" class="episode-img d-block mb-2" id="preview-<?php echo $t_index; ?>-<?php echo $c_index; ?>">
                                                                        <?php else: ?>
                                                                            <img src="" alt="Preview" class="episode-img d-block mb-2" id="preview-<?php echo $t_index; ?>-<?php echo $c_index; ?>" style="display: none;">
                                                                        <?php endif; ?>
                                                                        
                                                                        <button type="button" class="btn btn-danger btn-sm mb-2" onclick="removeExtra(this, 'capitulo', <?php echo $t_index; ?>)">
                                                                            <i class="fas fa-trash"></i> Eliminar Episodio
                                                                        </button>
                                                                        
                                                                        <!-- ENLACES -->
                                                                        <div class="mt-3">
                                                                            <label class="form-label">Enlaces del Episodio</label>
                                                                            <div class="enlace-group" id="enlace-group-<?php echo $t_index; ?>-<?php echo $c_index; ?>">
                                                                                <?php if (isset($capitulo['enlaces']) && is_array($capitulo['enlaces']) && !empty($capitulo['enlaces'])): ?>
                                                                                    <?php foreach ($capitulo['enlaces'] as $e_index => $enlace): ?>
                                                                                        <div class="enlace-item">
                                                                                            <div class="row">
                                                                                                <div class="col-md-3">
                                                                                                    <label class="form-label">Nombre *</label>
                                                                                                    <input type="text" class="form-control mb-1" name="enlace_nombre[<?php echo $t_index; ?>][<?php echo $c_index; ?>][]" value="<?php echo htmlspecialchars($enlace['nombre'] ?? ''); ?>" required>
                                                                                                </div>
                                                                                                <div class="col-md-4">
                                                                                                    <label class="form-label">URL *</label>
                                                                                                    <input type="url" class="form-control mb-1" name="enlace_url[<?php echo $t_index; ?>][<?php echo $c_index; ?>][]" value="<?php echo htmlspecialchars($enlace['url'] ?? ''); ?>" required>
                                                                                                </div>
                                                                                                <div class="col-md-2">
                                                                                                    <label class="form-label">Tipo</label>
                                                                                                    <select class="form-control mb-1" name="enlace_type[<?php echo $t_index; ?>][<?php echo $c_index; ?>][]">
                                                                                                        <option value="embed" <?php echo (($enlace['type'] ?? 'embed') === 'embed') ? 'selected' : ''; ?>>Embed</option>
                                                                                                        <option value="mp4" <?php echo (($enlace['type'] ?? '') === 'mp4') ? 'selected' : ''; ?>>MP4</option>
                                                                                                    </select>
                                                                                                </div>
                                                                                                <div class="col-md-2">
                                                                                                    <label class="form-label">Idioma</label>
                                                                                                    <select class="form-control mb-1" name="enlace_language[<?php echo $t_index; ?>][<?php echo $c_index; ?>][]">
                                                                                                        <option value="Latino" <?php echo (($enlace['language'] ?? 'Latino') === 'Latino') ? 'selected' : ''; ?>>Latino</option>
                                                                                                        <option value="Sub" <?php echo (($enlace['language'] ?? '') === 'Sub') ? 'selected' : ''; ?>>Subtitulado</option>
                                                                                                        <option value="Cast" <?php echo (($enlace['language'] ?? '') === 'Cast') ? 'selected' : ''; ?>>Español/Castellano</option>
                                                                                                    </select>
                                                                                                </div>
                                                                                                <div class="col-md-1 d-flex align-items-end">
                                                                                                    <button type="button" class="btn btn-danger btn-sm mb-1" onclick="removeEnlace(this)">
                                                                                                        <i class="fas fa-trash"></i>
                                                                                                    </button>
                                                                                                </div>
                                                                                            </div>
                                                                                        </div>
                                                                                    <?php endforeach; ?>
                                                                                <?php else: ?>
                                                                                    <div class="enlace-item">
                                                                                        <div class="row">
                                                                                            <div class="col-md-3">
                                                                                                <label class="form-label">Nombre *</label>
                                                                                                <input type="text" class="form-control mb-1" name="enlace_nombre[<?php echo $t_index; ?>][<?php echo $c_index; ?>][]" required>
                                                                                            </div>
                                                                                            <div class="col-md-4">
                                                                                                <label class="form-label">URL *</label>
                                                                                                <input type="url" class="form-control mb-1" name="enlace_url[<?php echo $t_index; ?>][<?php echo $c_index; ?>][]" required>
                                                                                            </div>
                                                                                            <div class="col-md-2">
                                                                                                <label class="form-label">Tipo</label>
                                                                                                <select class="form-control mb-1" name="enlace_type[<?php echo $t_index; ?>][<?php echo $c_index; ?>][]">
                                                                                                    <option value="embed">Embed</option>
                                                                                                    <option value="mp4">MP4</option>
                                                                                                </select>
                                                                                            </div>
                                                                                            <div class="col-md-2">
                                                                                                <label class="form-label">Idioma</label>
                                                                                                <select class="form-control mb-1" name="enlace_language[<?php echo $t_index; ?>][<?php echo $c_index; ?>][]">
                                                                                                    <option value="Latino">Latino</option>
                                                                                                    <option value="Sub">Subtitulado</option>
                                                                                                    <option value="Cast">Español/Castellano</option>
                                                                                                </select>
                                                                                            </div>
                                                                                            <div class="col-md-1 d-flex align-items-end">
                                                                                                <button type="button" class="btn btn-danger btn-sm mb-1" onclick="removeEnlace(this)">
                                                                                                    <i class="fas fa-trash"></i>
                                                                                                </button>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <button type="button" class="btn btn-secondary mt-2" onclick="addEnlace(<?php echo $t_index; ?>, <?php echo $c_index; ?>)">
                                                                                <i class="fas fa-plus"></i> Agregar Enlace
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                                <button type="button" class="btn btn-secondary mt-2" onclick="addCapitulo(<?php echo $t_index; ?>)">
                                                    <i class="fas fa-plus"></i> Agregar Episodio
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="accordion-item extra-item">
                                    <h2 class="accordion-header" id="temporada-heading-0">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#temporada-collapse-0" aria-expanded="false" aria-controls="temporada-collapse-0">
                                            Temporada 1
                                        </button>
                                    </h2>
                                    <div id="temporada-collapse-0" class="accordion-collapse collapse" aria-labelledby="temporada-heading-0">
                                        <div class="accordion-body">
                                            <input type="text" class="form-control mb-2" name="temporada_nombre[]" value="Temporada 1" required>
                                            <button type="button" class="btn btn-danger btn-sm mb-2" onclick="removeExtra(this, 'temporada')">
                                                <i class="fas fa-trash"></i> Eliminar Temporada
                                            </button>
                                            
                                            <div class="chapter-section accordion" id="capitulos-accordion-0">
                                                <div class="accordion-item">
                                                    <h3 class="accordion-header" id="capitulo-heading-0-0">
                                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#capitulo-collapse-0-0" aria-expanded="false" aria-controls="capitulo-collapse-0-0">
                                                            Episodio 1
                                                        </button>
                                                    </h3>
                                                    <div id="capitulo-collapse-0-0" class="accordion-collapse collapse" aria-labelledby="capitulo-heading-0-0">
                                                        <div class="accordion-body">
                                                            <div class="mb-2">
                                                                <label class="form-label">ID Episodio *</label>
                                                                <input type="text" class="form-control" name="capitulo_episode_id[0][]" value="01" required>
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label">Nombre del episodio *</label>
                                                                <input type="text" class="form-control" name="capitulo_nombre[0][]" value="Episodio 1" required>
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label">URL de la portada</label>
                                                                <input type="url" class="form-control" name="capitulo_portada[0][]" onchange="updateImagePreview(this, 0, 0)">
                                                            </div>
                                                            <img src="" alt="Preview" class="episode-img d-block mb-2" id="preview-0-0" style="display: none;">
                                                            
                                                            <button type="button" class="btn btn-danger btn-sm mb-2" onclick="removeExtra(this, 'capitulo', 0)">
                                                                <i class="fas fa-trash"></i> Eliminar Episodio
                                                            </button>
                                                            
                                                            <div class="mt-3">
                                                                <label class="form-label">Enlaces del Episodio</label>
                                                                <div class="enlace-group" id="enlace-group-0-0">
                                                                    <div class="enlace-item">
                                                                        <div class="row">
                                                                            <div class="col-md-3">
                                                                                <label class="form-label">Nombre *</label>
                                                                                <input type="text" class="form-control mb-1" name="enlace_nombre[0][0][]" value="Ver Online" required>
                                                                            </div>
                                                                            <div class="col-md-4">
                                                                                <label class="form-label">URL *</label>
                                                                                <input type="url" class="form-control mb-1" name="enlace_url[0][0][]" required>
                                                                            </div>
                                                                            <div class="col-md-2">
                                                                                <label class="form-label">Tipo</label>
                                                                                <select class="form-control mb-1" name="enlace_type[0][0][]">
                                                                                    <option value="embed">Embed</option>
                                                                                    <option value="mp4">MP4</option>
                                                                                </select>
                                                                            </div>
                                                                            <div class="col-md-2">
                                                                                <label class="form-label">Idioma</label>
                                                                                <select class="form-control mb-1" name="enlace_language[0][0][]">
                                                                                    <option value="Latino">Latino</option>
                                                                                    <option value="Sub">Subtitulado</option>
                                                                                    <option value="Cast">Español/Castellano</option>
                                                                                </select>
                                                                            </div>
                                                                            <div class="col-md-1 d-flex align-items-end">
                                                                                <button type="button" class="btn btn-danger btn-sm mb-1" onclick="removeEnlace(this)">
                                                                                    <i class="fas fa-trash"></i>
                                                                                </button>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <button type="button" class="btn btn-secondary mt-2" onclick="addEnlace(0, 0)">
                                                                    <i class="fas fa-plus"></i> Agregar Enlace
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <button type="button" class="btn btn-secondary mt-2" onclick="addCapitulo(0)">
                                                <i class="fas fa-plus"></i> Agregar Episodio
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-secondary mt-2" onclick="addTemporada()">
                            <i class="fas fa-plus"></i> Agregar Temporada
                        </button>
                    </div>

                    <div class="button-group">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='panel.php'">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> 
                            <?php echo $item_exists_in_github ? 'Actualizar' : 'Guardar Nuevo'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let temporadaCount = <?php echo isset($details['temporadas']) ? count($details['temporadas']) : 1; ?>;
        let capituloCount = {};

        // Inicializar contadores
        <?php if (isset($details['temporadas'])): ?>
            <?php foreach ($details['temporadas'] as $t_index => $temporada): ?>
                capituloCount[<?php echo $t_index; ?>] = <?php echo isset($temporada['capitulos']) ? count($temporada['capitulos']) : 0; ?>;
            <?php endforeach; ?>
        <?php else: ?>
            capituloCount[0] = 1;
        <?php endif; ?>

        function addTemporada() {
            const container = document.getElementById('temporadas-accordion');
            const index = temporadaCount++;
            capituloCount[index] = 0;
            
            const newTemporada = `
                <div class="accordion-item extra-item">
                    <h2 class="accordion-header" id="temporada-heading-${index}">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#temporada-collapse-${index}" aria-expanded="false" aria-controls="temporada-collapse-${index}">
                            Temporada ${index + 1}
                        </button>
                    </h2>
                    <div id="temporada-collapse-${index}" class="accordion-collapse collapse" aria-labelledby="temporada-heading-${index}">
                        <div class="accordion-body">
                            <input type="text" class="form-control mb-2" name="temporada_nombre[]" value="Temporada ${index + 1}" required>
                            <button type="button" class="btn btn-danger btn-sm mb-2" onclick="removeExtra(this, 'temporada')">
                                <i class="fas fa-trash"></i> Eliminar Temporada
                            </button>
                            <div class="accordion chapter-section" id="capitulos-accordion-${index}"></div>
                            <button type="button" class="btn btn-secondary mt-2" onclick="addCapitulo(${index})">
                                <i class="fas fa-plus"></i> Agregar Episodio
                            </button>
                        </div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', newTemporada);
            addCapitulo(index); // Agregar un episodio por defecto
        }

        function addCapitulo(temporadaIndex) {
            const container = document.getElementById(`capitulos-accordion-${temporadaIndex}`);
            const index = capituloCount[temporadaIndex] || 0;
            capituloCount[temporadaIndex] = index + 1;
            
            const newCapitulo = `
                <div class="accordion-item">
                    <h3 class="accordion-header" id="capitulo-heading-${temporadaIndex}-${index}">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#capitulo-collapse-${temporadaIndex}-${index}" aria-expanded="false" aria-controls="capitulo-collapse-${temporadaIndex}-${index}">
                            Episodio ${index + 1}
                        </button>
                    </h3>
                    <div id="capitulo-collapse-${temporadaIndex}-${index}" class="accordion-collapse collapse" aria-labelledby="capitulo-heading-${temporadaIndex}-${index}">
                        <div class="accordion-body">
                            <div class="mb-2">
                                <label class="form-label">ID Episodio *</label>
                                <input type="text" class="form-control" name="capitulo_episode_id[${temporadaIndex}][]" value="${String(index + 1).padStart(2, '0')}" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Nombre del episodio *</label>
                                <input type="text" class="form-control" name="capitulo_nombre[${temporadaIndex}][]" value="Episodio ${index + 1}" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">URL de la portada</label>
                                <input type="url" class="form-control" name="capitulo_portada[${temporadaIndex}][]" onchange="updateImagePreview(this, ${temporadaIndex}, ${index})">
                            </div>
                            <img src="" alt="Preview" class="episode-img d-block mb-2" id="preview-${temporadaIndex}-${index}" style="display: none;">
                            
                            <button type="button" class="btn btn-danger btn-sm mb-2" onclick="removeExtra(this, 'capitulo', ${temporadaIndex})">
                                <i class="fas fa-trash"></i> Eliminar Episodio
                            </button>
                            
                            <div class="mt-3">
                                <label class="form-label">Enlaces del Episodio</label>
                                <div class="enlace-group" id="enlace-group-${temporadaIndex}-${index}">
                                    <div class="enlace-item">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <label class="form-label">Nombre *</label>
                                                <input type="text" class="form-control mb-1" name="enlace_nombre[${temporadaIndex}][${index}][]" value="Ver Online" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">URL *</label>
                                                <input type="url" class="form-control mb-1" name="enlace_url[${temporadaIndex}][${index}][]" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Tipo</label>
                                                <select class="form-control mb-1" name="enlace_type[${temporadaIndex}][${index}][]">
                                                    <option value="embed">Embed</option>
                                                    <option value="mp4">MP4</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Idioma</label>
                                                <select class="form-control mb-1" name="enlace_language[${temporadaIndex}][${index}][]">
                                                    <option value="Latino">Latino</option>
                                                    <option value="Sub">Subtitulado</option>
                                                    <option value="Cast">Español/Castellano</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 d-flex align-items-end">
                                                <button type="button" class="btn btn-danger btn-sm mb-1" onclick="removeEnlace(this)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-secondary mt-2" onclick="addEnlace(${temporadaIndex}, ${index})">
                                    <i class="fas fa-plus"></i> Agregar Enlace
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', newCapitulo);
        }

        function addEnlace(temporadaIndex, capituloIndex) {
            const enlaceGroup = document.getElementById(`enlace-group-${temporadaIndex}-${capituloIndex}`);
            const newEnlace = `
                <div class="enlace-item">
                    <div class="row">
                        <div class="col-md-3">
                            <label class="form-label">Nombre *</label>
                            <input type="text" class="form-control mb-1" name="enlace_nombre[${temporadaIndex}][${capituloIndex}][]" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">URL *</label>
                            <input type="url" class="form-control mb-1" name="enlace_url[${temporadaIndex}][${capituloIndex}][]" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tipo</label>
                            <select class="form-control mb-1" name="enlace_type[${temporadaIndex}][${capituloIndex}][]">
                                <option value="embed">Embed</option>
                                <option value="mp4">MP4</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Idioma</label>
                            <select class="form-control mb-1" name="enlace_language[${temporadaIndex}][${capituloIndex}][]">
                                <option value="Latino">Latino</option>
                                <option value="Sub">Subtitulado</option>
                                <option value="Cast">Español/Castellano</option>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="button" class="btn btn-danger btn-sm mb-1" onclick="removeEnlace(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
            enlaceGroup.insertAdjacentHTML('beforeend', newEnlace);
        }

        function removeExtra(element, type, index = null) {
            const item = element.closest('.accordion-item');
            if (item) {
                item.remove();
            }
            if (type === 'temporada' && index !== null) {
                delete capituloCount[index];
            }
        }

        function removeEnlace(element) {
            const group = element.closest('.enlace-item');
            if (group) {
                group.remove();
            }
        }

        function updateImagePreview(input, temporadaIndex, capituloIndex) {
            const preview = document.getElementById(`preview-${temporadaIndex}-${capituloIndex}`);
            if (preview && input.value) {
                preview.src = input.value;
                preview.style.display = 'block';
            }
        }

        // Validación del formulario
        document.getElementById('contentForm').addEventListener('submit', function(e) {
            console.log('Validando formulario...');
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = '#ef4444';
                    console.log('Campo requerido vacío:', field.name);
                } else {
                    field.style.borderColor = '';
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Por favor, completa todos los campos requeridos.');
                return;
            }
            
            console.log('Formulario válido, enviando...');
        });

        // Toggle sidebar
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const content = document.getElementById('content');
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            content.classList.toggle('sidebar-open');
        });

        document.getElementById('sidebarOverlay').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const content = document.getElementById('content');
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            content.classList.remove('sidebar-open');
        });
    </script>
</body>
</html>