<?php
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
date_default_timezone_set('America/Panama'); // Zona horaria de Panamá (UTC-5)

// Cargar configuración de GitHub si existe
$config_file = 'config/github_config.json';
$github_config = [];
if (file_exists($config_file)) {
    $github_config = json_decode(file_get_contents($config_file), true);
}

// Función para obtener todos los datos agrupados por tipo (sin caché)
function getAllGitHubData($config) {
    $token = $config['token'] ?? '';
    $repo_owner = $config['repo_owner'] ?? '';
    $repo_name = $config['repo_name'] ?? '';
    $data_file = $config['data_file'] ?? 'home.json';
    $branch = $config['branch'] ?? 'main';

    if (empty($token) || empty($repo_owner) || empty($repo_name)) {
        return ['error' => 'Configuración de GitHub incompleta.'];
    }

    // Obtener datos desde GitHub
    $api_url = "https://api.github.com/repos/$repo_owner/$repo_name/contents/$data_file?ref=$branch";
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Accept: application/vnd.github.v3+json",
        "User-Agent: PHP-App"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return ['error' => 'Error al conectar con GitHub. Código: ' . $http_code];
    }

    $data = json_decode($response, true);
    if (!isset($data['content'])) {
        return ['error' => 'No se pudo obtener el contenido de GitHub.'];
    }

    $content = base64_decode($data['content']);
    $json_data = json_decode($content, true);
    if (!$json_data) {
        return ['error' => 'Error al decodificar el archivo JSON.'];
    }

    $items = $json_data['data'] ?? [];
    $lastUpdated = $json_data['lastUpdated'] ?? '';

    // Agrupar por tipo y contar
    $grouped_data = [
        'peliculas' => ['count' => 0, 'items' => [], 'lastUpdated' => $lastUpdated],
        'series' => ['count' => 0, 'items' => [], 'lastUpdated' => $lastUpdated],
        'animes' => ['count' => 0, 'items' => [], 'lastUpdated' => $lastUpdated],
        'doramas' => ['count' => 0, 'items' => [], 'lastUpdated' => $lastUpdated]
    ];

    foreach ($items as $item) {
        $type = $item['type'] ?? '';
        $mapped_type = ($type === 'movie') ? 'peliculas' : (($type === 'series') ? 'series' : (($type === 'animes') ? 'animes' : (($type === 'dorama') ? 'doramas' : '')));
        if ($mapped_type) {
            $normalized = [
                'id_tmdb' => $item['id_tmdb'] ?? '',
                'imagen' => $item['imagen'] ?? '',
                'titulo' => $item['titulo'] ?? '',
                'year' => $item['year'] ?? '',
                'etiquetas' => $item['etiquetas'] ?? '',
                'type' => $type,
                'created_at' => $item['created_at'] ?? '',
                'updated_at' => $item['updated_at'] ?? '1970-01-01 00:00:00', // Valor por defecto
            ];
            if ($type === 'movie' && isset($item['calidad'])) {
                $normalized['calidad'] = $item['calidad'] ?? '';
            }
            $grouped_data[$mapped_type]['items'][] = $normalized;
            $grouped_data[$mapped_type]['count']++;
        }
    }

    // Ordenar items por updated_at descendente y eliminar duplicados
    foreach ($grouped_data as $type => &$group) {
        // Primero, eliminar duplicados manteniendo el ítem con el updated_at más reciente
        $unique_items = [];
        $seen_ids = [];
        foreach ($group['items'] as $item) {
            $id_tmdb = $item['id_tmdb'];
            if (!isset($seen_ids[$id_tmdb])) {
                $unique_items[] = $item;
                $seen_ids[$id_tmdb] = true;
            } else {
                // Comparar updated_at si ya existe el id_tmdb
                $existing_index = array_search($id_tmdb, array_column($unique_items, 'id_tmdb'));
                try {
                    $existing_time = new DateTime($unique_items[$existing_index]['updated_at'] ?? $unique_items[$existing_index]['created_at'] ?? '1970-01-01 00:00:00');
                    $new_time = new DateTime($item['updated_at'] ?? $item['created_at'] ?? '1970-01-01 00:00:00');
                    if ($new_time > $existing_time) {
                        $unique_items[$existing_index] = $item;
                    }
                } catch (Exception $e) {
                    // Si falla, mantener el existente
                }
            }
        }

        // Ordenar por updated_at descendente, con fallback a created_at
        usort($unique_items, function($a, $b) {
            try {
                $time_a = new DateTime($a['updated_at'] ?? $a['created_at'] ?? '1970-01-01 00:00:00');
                $time_b = new DateTime($b['updated_at'] ?? $b['created_at'] ?? '1970-01-01 00:00:00');
                return $time_b <=> $time_a; // Descendente (PHP 7+)
            } catch (Exception $e) {
                return 0; // Mantener orden si falla
            }
        });

        // Limitar a 15 ítems
        $group['items'] = array_slice($unique_items, 0, 15);
    }

    return $grouped_data;
}

// Obtener datos agrupados
$all_data = getAllGitHubData($github_config);

// Verificar si hay error
$error_message = isset($all_data['error']) ? $all_data['error'] : null;
if ($error_message) {
    $all_data = [
        'peliculas' => ['count' => 0, 'items' => [], 'lastUpdated' => ''],
        'series' => ['count' => 0, 'items' => [], 'lastUpdated' => ''],
        'animes' => ['count' => 0, 'items' => [], 'lastUpdated' => ''],
        'doramas' => ['count' => 0, 'items' => [], 'lastUpdated' => '']
    ];
}

// Asignar datos
$all_peliculas_data = $all_data['peliculas'] ?? ['count' => 0, 'items' => [], 'lastUpdated' => ''];
$all_series_data = $all_data['series'] ?? ['count' => 0, 'items' => [], 'lastUpdated' => ''];
$all_animes_data = $all_data['animes'] ?? ['count' => 0, 'items' => [], 'lastUpdated' => ''];
$all_doramas_data = $all_data['doramas'] ?? ['count' => 0, 'items' => [], 'lastUpdated' => ''];

// Contadores
$total_peliculas_count = $all_peliculas_data['count'];
$total_series_count = $all_series_data['count'];
$total_animes_count = $all_animes_data['count'];
$total_doramas_count = $all_doramas_data['count'];

// Listas recientes (ya ordenadas y filtradas por getAllGitHubData)
$recent_peliculas = $all_peliculas_data['items'];
$recent_series = $all_series_data['items'];
$recent_animes = $all_animes_data['items'];
$recent_doramas = $all_doramas_data['items'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración Profesional - Películas y Series</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
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
            margin-top: 70px;
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
            padding: 20px;
            transition: margin-left 0.3s ease-in-out;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .content.sidebar-open {
            margin-left: 250px;
        }

        .container-fluid {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 15px;
        }

        .info-box {
            background: linear-gradient(135deg, var(--card-bg) 0%, #374151 100%);
            border-radius: 16px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            border: 1px solid var(--border-light);
            overflow: hidden;
            position: relative;
            display: flex;
            align-items: center;
            min-height: 100px;
        }

        .info-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
            border-color: var(--accent-blue);
        }

        .info-box-icon {
            font-size: 1.8rem;
            color: #ffffff;
            background: var(--accent-blue);
            border-radius: 12px;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }

        .info-box:hover .info-box-icon {
            transform: scale(1.1);
        }

        .info-box-content {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .info-box-text {
            font-size: 0.95rem;
            font-weight: 500;
            color: #d1d5db;
            display: block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .info-box-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-light);
            margin-top: 5px;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .card {
            background-color: var(--card-bg);
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s;
            border: 1px solid var(--border-light);
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--border-light);
            font-weight: 600;
            color: var(--text-light);
            padding: 15px 20px;
        }

        /* Swiper Styles */
        .swiper {
            width: 100%;
            height: 100%;
            padding: 20px 10px;
        }

        .swiper-slide {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .swiper-button-next,
        .swiper-button-prev {
            color: var(--accent-blue);
            background: rgba(59, 130, 246, 0.1);
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .swiper-button-next:after,
        .swiper-button-prev:after {
            font-size: 18px;
            font-weight: bold;
        }

        .swiper-button-next:hover,
        .swiper-button-prev:hover {
            background: var(--accent-blue);
            color: white;
        }

        /* Movie/Series Card Styles */
        .media-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 15px;
            margin: 10px;
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
            width: 200px;
            height: 380px;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }

        .media-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            border-color: var(--accent-blue);
        }

        .media-card img {
            width: 100%;
            height: 250px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 12px;
        }

        .media-card-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 80px;
        }

        .media-card h5 {
            font-size: .5rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-light);
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-align: center;
            flex-shrink: 0;
        }

        .media-card .year {
            font-size: 0.9rem;
            color: #9ca3af;
            text-align: center;
            margin-bottom: 10px;
            flex-shrink: 0;
        }

        .media-card .calidad {
            font-size: 0.9rem;
            color: #9ca3af;
            text-align: center;
            margin-bottom: 10px;
            flex-shrink: 0;
        }

        .action-buttons {
            position: static;
            display: flex;
            justify-content: center;
            margin-top: auto;
            flex-shrink: 0;
        }

        .edit-btn {
            background-color: var(--accent-blue);
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            color: #ffffff;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            width: 100%;
            justify-content: center;
        }

        .edit-btn:hover {
            background-color: #2563eb;
            transform: scale(1.05);
        }

        .media-card-text-content {
            flex: 1;
            display: flex;
            justify-content: flex-start;
            /* flex-direction: column;
            justify-content: flex-start;
            min-height: 60px;
            margin-bottom: 10px; */
        }

        .media-card-text-content h5 {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 140px;
            display: block;
            font-size: .9rem;
        }

        .media-card-text-info {
            flex: 1;
            display: flex;
            justify-content: flex-start;
            top: 10px;
        }

        @media (max-width: 767px) {
            .media-card {
                width: 160px;
                height: 340px;
                padding: 12px;
            }

            .media-card img {
                height: 200px;
            }
        }

        @media (min-width: 768px) and (max-width: 991px) {
            .media-card {
                width: 180px;
                height: 360px;
            }
        }

        /* Alert Styles */
        .auto-hide-alert {
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1060;
            min-width: 300px;
            text-align: center;
            animation: slideInDown 0.5s ease;
        }

        .auto-hide-alert.hiding {
            animation: slideOutUp 0.5s ease forwards;
        }

        @keyframes slideInDown {
            from {
                transform: translateX(-50%) translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
        }

        @keyframes slideOutUp {
            from {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
            to {
                transform: translateX(-50%) translateY(-100%);
                opacity: 0;
            }
        }

        @media (max-width: 767px) {
            .info-box {
                padding: 15px;
                min-height: 80px;
            }

            .info-box-icon {
                width: 40px;
                height: 40px;
                font-size: 1.5rem;
            }

            .swiper {
                padding: 15px 5px;
            }

            .auto-hide-alert {
                min-width: 90%;
                margin: 0 10px;
            }
        }

        @media (max-width: 991px) {
            .sidebar {
                position: fixed;
                top: 0;
                height: 100%;
            }

            .content {
                margin-left: 0 !important;
            }

            .content.sidebar-open {
                margin-left: 0 !important;
            }

            .navbar-toggler {
                z-index: 1100;
            }
        }

        @media (min-width: 992px) {
            .content.sidebar-open {
                margin-left: 250px;
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
            <button class="navbar-toggler" type="button" id="sidebarToggle">
                <i class="fas fa-bars text-light" id="toggleIcon"></i>
            </button>
            <a class="navbar-brand ms-3" href="#">Bienvenido al Panel</a>
        </div>
    </nav>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <a href=""><i class="fas fa-home"></i> Inicio</a>
        <a href="catalogo_movie.php"><i class="fas fa-film"></i> Catálogo de Películas</a>
        <a href="catalogo_serie.php"><i class="fas fa-tv"></i> Catálogo de Series</a>
        <a href="subir_contenido.php"><i class="fas fa-upload"></i> Subir Contenido</a>
        <a href="config_github.php"><i class="fab fa-github"></i> Configuración GitHub</a>
        <a href="subir_telegram.php"><i class="fab fa-telegram-plane"></i> Configurar Telegram</a>
        <!--<a href="Extrator_Python.php"><i class="fab fa-telegram-plane"></i> Extrator Python</a>-->
        <a href="Admin_home.php"><i class="fab fa-telegram-plane"></i> Admin Home</a>
        <a href="pedidos.php"><i class="fa-regular fa-chart-bar"></i>Pedidos</a>
    </div>

    <!-- Content -->
    <div class="content" id="content">
        <div class="container">
            <!-- Error Alert -->
            <?php if ($error_message): ?>
                <div class="alert alert-danger auto-hide-alert" id="errorAlert" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Success Alert -->
            <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                <div class="alert alert-success auto-hide-alert" id="successAlert" role="alert">
                    <i class="fas fa-check-circle"></i> Contenido guardado localmente y en GitHub (si configurado).
                </div>
            <?php endif; ?>

            
            
            <p>Has iniciado sesión correctamente.</p>
            
            
            
            
            <!-- Info Boxes -->
            <div class="row g-4">
                <div class="col-6 col-lg-3">
                    <div class="info-box d-flex align-items-center">
                        <div class="info-box-icon"><i class="fas fa-film"></i></div>
                        <div class="info-box-content">
                            <span class="info-box-text">Películas</span>
                            <span class="info-box-number"><?php echo $total_peliculas_count; ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="info-box d-flex align-items-center">
                        <div class="info-box-icon"><i class="fas fa-tv"></i></div>
                        <div class="info-box-content">
                            <span class="info-box-text">Series</span>
                            <span class="info-box-number"><?php echo $total_series_count; ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="info-box d-flex align-items-center">
                        <div class="info-box-icon"><i class="fas fa-star"></i></div>
                        <div class="info-box-content">
                            <span class="info-box-text">Animes</span>
                            <span class="info-box-number"><?php echo $total_animes_count; ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="info-box d-flex align-items-center">
                        <div class="info-box-icon"><i class="fas fa-heart"></i></div>
                        <div class="info-box-content">
                            <span class="info-box-text">Doramas</span>
                            <span class="info-box-number"><?php echo $total_doramas_count; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Películas Recientes con Swiper -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">Películas Recientes</div>
                        <div class="swiper peliculas-swiper">
                            <div class="swiper-wrapper">
                                <?php foreach ($recent_peliculas as $item): ?>
                                    <div class="swiper-slide">
                                        <div class="media-card">
                                            <img src="<?php echo htmlspecialchars($item['imagen'] ?? ''); ?>" alt="<?php echo htmlspecialchars($item['titulo'] ?? ''); ?>" onerror="this.src='https://via.placeholder.com/200x250?text=Sin+Imagen';">
                                            <div class="media-card-content">
                                                <div class="media-card-text-content">
                                                    <h5><?php echo htmlspecialchars($item['titulo'] ?? ''); ?></h5>
                                                </div>
                                                <div class="media-card-text-info">
                                                    <div class="year"><?php echo htmlspecialchars($item['year'] ?? ''); ?></div>
                                                    <div class="year"> - </div>
                                                    <div class="calidad"><?php echo htmlspecialchars($item['calidad'] ?? ''); ?></div>
                                                </div>
                                                <div class="action-buttons">
                                                    <button class="edit-btn" onclick="window.location.href='add_edit_movie.php?id=<?php echo htmlspecialchars($item['id_tmdb']); ?>'">
                                                        <i class="fas fa-edit"></i> Editar
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="swiper-button-next"></div>
                            <div class="swiper-button-prev"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Series Recientes con Swiper -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">Series Recientes</div>
                        <div class="swiper series-swiper">
                            <div class="swiper-wrapper">
                                <?php foreach ($recent_series as $item): ?>
                                    <div class="swiper-slide">
                                        <div class="media-card">
                                            <img src="<?php echo htmlspecialchars($item['imagen'] ?? ''); ?>" alt="<?php echo htmlspecialchars($item['titulo'] ?? ''); ?>" onerror="this.src='https://via.placeholder.com/200x250?text=Sin+Imagen';">
                                            <div class="media-card-content">
                                                <div class="media-card-text-content">
                                                    <h5><?php echo htmlspecialchars($item['titulo'] ?? ''); ?></h5>
                                                </div>
                                                <div class="media-card-text-info">
                                                    <div class="year"><?php echo htmlspecialchars($item['year'] ?? ''); ?></div>
                                                </div>
                                                <div class="action-buttons">
                                                    <button class="edit-btn" onclick="window.location.href='add_edit_serie.php?id=<?php echo htmlspecialchars($item['id_tmdb']); ?>&type=series'">
                                                        <i class="fas fa-edit"></i> Editar
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="swiper-button-next"></div>
                            <div class="swiper-button-prev"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Animes Recientes con Swiper -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">Animes Recientes</div>
                        <div class="swiper animes-swiper">
                            <div class="swiper-wrapper">
                                <?php foreach ($recent_animes as $item): ?>
                                    <div class="swiper-slide">
                                        <div class="media-card">
                                            <img src="<?php echo htmlspecialchars($item['imagen'] ?? ''); ?>" alt="<?php echo htmlspecialchars($item['titulo'] ?? ''); ?>" onerror="this.src='https://via.placeholder.com/200x250?text=Sin+Imagen';">
                                            <div class="media-card-content">
                                                <div class="media-card-text-content">
                                                    <h5><?php echo htmlspecialchars($item['titulo'] ?? ''); ?></h5>
                                                </div>
                                                <div class="media-card-text-info">
                                                    <div class="year"><?php echo htmlspecialchars($item['year'] ?? ''); ?></div>
                                                </div>
                                                <div class="action-buttons">
                                                    <button class="edit-btn" onclick="window.location.href='add_edit_serie.php?id=<?php echo htmlspecialchars($item['id_tmdb']); ?>&type=animes'">
                                                        <i class="fas fa-edit"></i> Editar
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="swiper-button-next"></div>
                            <div class="swiper-button-prev"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Doramas Recientes con Swiper -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">Doramas Recientes</div>
                        <div class="swiper doramas-swiper">
                            <div class="swiper-wrapper">
                                <?php foreach ($recent_doramas as $item): ?>
                                    <div class="swiper-slide">
                                        <div class="media-card">
                                            <img src="<?php echo htmlspecialchars($item['imagen'] ?? ''); ?>" alt="<?php echo htmlspecialchars($item['titulo'] ?? ''); ?>" onerror="this.src='https://via.placeholder.com/200x250?text=Sin+Imagen';">
                                            <div class="media-card-content">
                                                <div class="media-card-text-content">
                                                    <h5><?php echo htmlspecialchars($item['titulo'] ?? ''); ?></h5>
                                                </div>
                                                <div class="media-card-text-info">
                                                    <div class="year"><?php echo htmlspecialchars($item['year'] ?? ''); ?></div>
                                                </div>
                                                <div class="action-buttons">
                                                    <button class="edit-btn" onclick="window.location.href='add_edit_serie.php?id=<?php echo htmlspecialchars($item['id_tmdb']); ?>&type=dorama'">
                                                        <i class="fas fa-edit"></i> Editar
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="swiper-button-next"></div>
                            <div class="swiper-button-prev"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    
    
    
    <script>
        const loggedUser = JSON.parse(localStorage.getItem('loggedUser'));
        if (loggedUser) {
            document.body.innerHTML += `<p>Usuario: ${loggedUser.username}</p>`;
        }
    </script>
    
    
    <!-- Swiper JS -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Inicializar Swiper para Películas
        const peliculasSwiper = new Swiper('.peliculas-swiper', {
            slidesPerView: 2,
            spaceBetween: 10,
            navigation: {
                nextEl: '.peliculas-swiper .swiper-button-next',
                prevEl: '.peliculas-swiper .swiper-button-prev',
            },
            breakpoints: {
                640: { slidesPerView: 3, spaceBetween: 15 },
                768: { slidesPerView: 4, spaceBetween: 15 },
                1024: { slidesPerView: 6, spaceBetween: 15 },
                1200: { slidesPerView: 7, spaceBetween: 15 },
                1400: { slidesPerView: 8, spaceBetween: 15 },
            },
        });

        // Inicializar Swiper para Series
        const seriesSwiper = new Swiper('.series-swiper', {
            slidesPerView: 2,
            spaceBetween: 10,
            navigation: {
                nextEl: '.series-swiper .swiper-button-next',
                prevEl: '.series-swiper .swiper-button-prev',
            },
            breakpoints: {
                640: { slidesPerView: 3, spaceBetween: 15 },
                768: { slidesPerView: 4, spaceBetween: 15 },
                1024: { slidesPerView: 6, spaceBetween: 15 },
                1200: { slidesPerView: 7, spaceBetween: 15 },
                1400: { slidesPerView: 8, spaceBetween: 15 },
            },
        });

        // Inicializar Swiper para Animes
        const animesSwiper = new Swiper('.animes-swiper', {
            slidesPerView: 2,
            spaceBetween: 10,
            navigation: {
                nextEl: '.animes-swiper .swiper-button-next',
                prevEl: '.animes-swiper .swiper-button-prev',
            },
            breakpoints: {
                640: { slidesPerView: 3, spaceBetween: 15 },
                768: { slidesPerView: 4, spaceBetween: 15 },
                1024: { slidesPerView: 6, spaceBetween: 15 },
                1200: { slidesPerView: 7, spaceBetween: 15 },
                1400: { slidesPerView: 8, spaceBetween: 15 },
            },
        });

        // Inicializar Swiper para Doramas
        const doramasSwiper = new Swiper('.doramas-swiper', {
            slidesPerView: 2,
            spaceBetween: 10,
            navigation: {
                nextEl: '.doramas-swiper .swiper-button-next',
                prevEl: '.doramas-swiper .swiper-button-prev',
            },
            breakpoints: {
                640: { slidesPerView: 3, spaceBetween: 15 },
                768: { slidesPerView: 4, spaceBetween: 15 },
                1024: { slidesPerView: 6, spaceBetween: 15 },
                1200: { slidesPerView: 7, spaceBetween: 15 },
                1400: { slidesPerView: 8, spaceBetween: 15 },
            },
        });

        // Auto-hide alerts after 3 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.auto-hide-alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('hiding');
                    setTimeout(() => {
                        alert.remove();
                    }, 500);
                }, 3000);
            });
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