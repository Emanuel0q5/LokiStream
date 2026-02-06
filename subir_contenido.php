<?php
$config_file = 'config/github_config.json';
$tmdb_config_file = 'config/tmdb_config.json';
$github_config = [];
$tmdb_config = [];

if (file_exists($config_file)) {
    $github_config = json_decode(file_get_contents($config_file), true);
}
if (file_exists($tmdb_config_file)) {
    $tmdb_config = json_decode(file_get_contents($tmdb_config_file), true);
}

$api_key = $tmdb_config['api_key'] ?? '';
$search_query = $_GET['query'] ?? '';
$content_type = $_GET['type'] ?? 'movie';
$page = max(1, intval($_GET['page'] ?? 1));
$results = [];
$total_pages = 1;
$is_single_result = false;

if (!empty($search_query) && !empty($api_key)) {
    $endpoint = $content_type === 'movie' ? 'movie' : 'tv';
    
    // Detectar si es un ID de TMDB (numérico) o IMDB (empieza con 'tt')
    if (is_numeric($search_query)) {
        // Búsqueda por ID TMDB
        $api_url = "https://api.themoviedb.org/3/{$endpoint}/{$search_query}?api_key={$api_key}&language=es";
        $is_single_result = true;
    } elseif (stripos($search_query, 'tt') === 0) {
        // Búsqueda por ID IMDB
        $api_url = "https://api.themoviedb.org/3/find/{$search_query}?api_key={$api_key}&language=es&external_source=imdb_id";
        $is_single_result = true;
    } else {
        // Búsqueda normal por texto
        $api_url = "https://api.themoviedb.org/3/search/{$endpoint}?api_key={$api_key}&query=" . urlencode($search_query) . "&language=es&include_adult=false&page={$page}";
    }
    
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $data = json_decode($response, true);
        
        if ($is_single_result) {
            if (isset($data['movie_results']) || isset($data['tv_results'])) {
                // Para búsqueda por IMDB
                $key = $content_type === 'movie' ? 'movie_results' : 'tv_results';
                $results = $data[$key] ?? [];
            } else {
                // Para búsqueda por TMDB ID
                $results = $data ? [$data] : [];
            }
        } else {
            $results = $data['results'] ?? [];
            $total_pages = $data['total_pages'] ?? 1;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subir Contenido - Panel de Administración</title>
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
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
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
          margin-top: 60px;
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

        .search-section {
            background-color: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .search-form {
            display: flex;
            gap: 15px;
            align-items: end;
        }

        .search-form .form-control {
            flex: 1;
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-light);
            color: var(--text-light);
            border-radius: 6px;
        }

        .search-form .form-control:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }

        .btn-search {
            background-color: var(--accent-blue);
            border-color: var(--accent-blue);
            padding: 10px 20px;
            font-weight: 500;
        }

        .btn-search:hover {
            background-color: #2563eb;
        }

        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .result-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .result-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .result-card img {
            width: 100%;
            height: 400px;
            object-fit: cover;
        }

        .card-body {
            padding: 15px;
        }

        .card-title {
            color: var(--text-light);
            font-weight: 600;
            margin-bottom: 10px;
        }

        .card-text {
            color: #9ca3af;
            margin-bottom: 15px;
        }

        .btn-add {
            background-color: var(--accent-blue);
            border: none;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            width: 100%;
        }

        .btn-add:hover {
            background-color: #2563eb;
        }

        .no-results {
            text-align: center;
            color: #9ca3af;
            padding: 50px;
        }

        .pagination {
            justify-content: center;
            margin-top: 30px;
        }

        .pagination .page-item.active .page-link {
            background-color: var(--accent-blue);
            border-color: var(--accent-blue);
        }

        .pagination .page-link {
            color: var(--text-light);
            background-color: var(--card-bg);
            border: 1px solid var(--border-light);
        }

        .pagination .page-link:hover {
            background-color: var(--secondary-dark);
        }

        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
                gap: 10px;
            }

            .results-grid {
                grid-template-columns: 1fr;
            }

            .container {
                margin: 10px;
                padding: 10px;
            }

            .navbar {
                padding: 10px 15px;
            }

            .navbar-brand {
                font-size: 1.2rem;
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
        <a href="subir_telegram.php"><i class="fab fa-telegram-plane"></i> Configurar Telegram</a>
    </div>

    <!-- Content -->
    <div class="content" id="content">
        <div class="container">
            <div class="search-section">
                <h4><i class="fas fa-search"></i> Buscar Contenido en TMDB</h4>
                <form method="GET" class="search-form">
                    <input type="text" class="form-control" name="query" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Ej: La Bella y La Bestia o ID TMDB/IMDB" required>
                    <select name="type" class="form-control">
                        <option value="movie" <?php echo $content_type === 'movie' ? 'selected' : ''; ?>>Películas</option>
                        <option value="tv" <?php echo $content_type === 'tv' ? 'selected' : ''; ?>>Series/Animes/Doramas</option>
                    </select>
                    <button type="submit" class="btn btn-search"><i class="fas fa-search"></i> Buscar</button>
                </form>
                <small class="info-text">Busca en TMDB por nombre, ID TMDB (numérico) o ID IMDB (ttXXXXXXX). Selecciona el contenido para agregar/editar.</small>
                
            </div>

            <?php if (!empty($results)): ?>
                <div class="results-grid">
                    <?php foreach ($results as $result): ?>
                        <?php
                        $tmdb_id = $result['id'];
                        $title = $result['title'] ?? $result['name'] ?? 'Sin título';
                        $overview = $result['overview'] ?? 'Sin descripción';
                        $poster_path = $result['poster_path'] ? "https://image.tmdb.org/t/p/w500{$result['poster_path']}" : 'https://via.placeholder.com/500x750?text=No+Imagen';
                        $release_date = $result['release_date'] ?? $result['first_air_date'] ?? 'Sin fecha';
                        $type_param = $content_type === 'tv' ? 'series' : 'movie';
                        ?>
                        <div class="result-card">
                            <img src="<?php echo $poster_path; ?>" alt="<?php echo htmlspecialchars($title); ?>" class="card-img-top">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo htmlspecialchars($title); ?></h5>
                                <p class="card-text"><?php echo htmlspecialchars(substr($overview, 0, 150)); ?>...</p>
                                <p class="card-text small text-muted"><?php echo $release_date; ?></p>
                                <a href="<?php echo $content_type === 'movie' ? 'add_edit_eivom.php' : 'add_edit_eires.php'; ?>?id=<?php echo $tmdb_id; ?>&type=<?php echo $type_param; ?>" class="btn btn-add">
                                    <i class="fas fa-plus"></i> Agregar
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (!$is_single_result && $total_pages > 1): ?>
                    <nav aria-label="Pagination" class="pagination">
                        <ul class="pagination">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?query=<?php echo urlencode($search_query); ?>&type=<?php echo $content_type; ?>&page=<?php echo $page - 1; ?>">Anterior</a>
                                </li>
                            <?php endif; ?>
                            
                            <?php 
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?query=<?php echo urlencode($search_query); ?>&type=<?php echo $content_type; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?query=<?php echo urlencode($search_query); ?>&type=<?php echo $content_type; ?>&page=<?php echo $page + 1; ?>">Siguiente</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php elseif (!empty($search_query)): ?>
                <div class="no-results">
                    <i class="fas fa-search" style="font-size: 3rem; color: #9ca3af; margin-bottom: 15px;"></i>
                    <h5>No se encontraron resultados</h5>
                    <p>Intenta con otra búsqueda.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Agregar esto en subir_contenido.php después de la sección de búsqueda TMDB -->
<!--<div class="card mt-4">
    <div class="card-body text-center">
        <div class="row align-items-center">
            <div class="col-md-8 text-start">
                <i class="fas fa-bulk fa-2x text-primary mb-3"></i>
                <h4>Carga Masiva</h4>
                <p class="text-muted">Sube múltiples películas y episodios de series automáticamente usando el formato especificado. Ahorra tiempo procesando lotes de contenido.</p>
                <ul class="text-start text-muted">
                    <li>Procesamiento automático de TMDB</li>
                    <li>Validación de URLs y formatos</li>
                    <li>Soporte para múltiples temporadas</li>
                    <li>Detección inteligente de contenido</li>
                </ul>
            </div>
            <div class="col-md-4">
                <a href="masivo.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-upload"></i> Ir a Carga Masiva
                </a>
            </div>
        </div>
    </div>
</div>-->
    </div>






    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const sidebar = document.getElementById('sidebar');
        const content = document.getElementById('content');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const toggleIcon = document.getElementById('toggleIcon');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        let isOpen = false;

        sidebarToggle.addEventListener('click', () => {
            isOpen = !isOpen;
            
            if (isOpen) {
                sidebar.classList.add('active');
                content.classList.add('sidebar-open');
                sidebarOverlay.classList.add('active');
                toggleIcon.classList.remove('fa-bars');
                toggleIcon.classList.add('fa-times');
            } else {
                sidebar.classList.remove('active');
                content.classList.remove('sidebar-open');
                sidebarOverlay.classList.remove('active');
                toggleIcon.classList.remove('fa-times');
                toggleIcon.classList.add('fa-bars');
            }
        });

        sidebarOverlay.addEventListener('click', () => {
            if (isOpen) {
                isOpen = false;
                sidebar.classList.remove('active');
                content.classList.remove('sidebar-open');
                sidebarOverlay.classList.remove('active');
                toggleIcon.classList.remove('fa-times');
                toggleIcon.classList.add('fa-bars');
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth >= 992 && isOpen) {
                isOpen = false;
                sidebar.classList.remove('active');
                content.classList.remove('sidebar-open');
                sidebarOverlay.classList.remove('active');
                toggleIcon.classList.remove('fa-times');
                toggleIcon.classList.add('fa-bars');
            }
        });
    </script>
</body>
</html>