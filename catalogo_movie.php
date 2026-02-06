<?php
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// Cargar configuración de GitHub si existe
$config_file = 'config/github_config.json';
$github_config = [];
if (file_exists($config_file)) {
    $github_config = json_decode(file_get_contents($config_file), true);
}

// Función para obtener datos de GitHub
function getGitHubData($config, $type = 'peliculas') {
    $token = $config['token'] ?? '';
    $repo_owner = $config['repo_owner'] ?? '';
    $repo_name = $config['repo_name'] ?? '';
    $data_file = $config['data_file'] ?? 'home.json';
    $branch = $config['branch'] ?? 'main';

    if (empty($token) || empty($repo_owner) || empty($repo_name)) {
        return ['count' => 0, 'items' => [], 'lastUpdated' => ''];
    }

    // Obtener home.json desde GitHub
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
        return ['count' => 0, 'items' => [], 'lastUpdated' => ''];
    }

    $data = json_decode($response, true);
    $content = base64_decode($data['content']);
    $json_data = json_decode($content, true);

    $items = $json_data['data'] ?? [];
    $lastUpdated = $json_data['lastUpdated'] ?? '';

    // Filtrar solo películas
    $items = array_filter($items, function($item) {
        return $item['type'] === 'movie';
    });

    // Normalizar estructura
    $normalized_items = [];
    foreach (array_values($items) as $item) {
        $normalized = [
            'id_tmdb' => $item['id_tmdb'] ?? '',
            'imagen' => $item['imagen'] ?? '',
            'titulo' => $item['titulo'] ?? '',
            'year' => $item['year'] ?? '',
            'etiquetas' => $item['etiquetas'] ?? '',
            'type' => $item['type'] ?? '',
            'created_at' => $item['created_at'] ?? '',
            'updated_at' => $item['updated_at'] ?? '',
            'calidad' => $item['calidad'] ?? ''
        ];
        $normalized_items[] = $normalized;
    }

    return [
        'count' => count($normalized_items),
        'items' => $normalized_items,
        'lastUpdated' => $lastUpdated
    ];
}

// Obtener todas las películas
$all_movies_data = getGitHubData($github_config, 'peliculas');
$all_movies = $all_movies_data['items'];

// Procesar búsqueda y filtros
$search_term = $_GET['search'] ?? '';
$filter_etiquetas = $_GET['etiquetas'] ?? '';
$filter_sort = $_GET['sort'] ?? 'created_at_desc';

// Filtrar por término de búsqueda
if (!empty($search_term)) {
    $all_movies = array_filter($all_movies, function($movie) use ($search_term) {
        return stripos($movie['titulo'], $search_term) !== false;
    });
}

// Filtrar por etiquetas
if (!empty($filter_etiquetas)) {
    $all_movies = array_filter($all_movies, function($movie) use ($filter_etiquetas) {
        return stripos($movie['etiquetas'], $filter_etiquetas) !== false;
    });
}

// Ordenar
switch ($filter_sort) {
    case 'titulo_asc':
        usort($all_movies, function($a, $b) {
            return strcmp($a['titulo'], $b['titulo']);
        });
        break;
    case 'titulo_desc':
        usort($all_movies, function($a, $b) {
            return strcmp($b['titulo'], $a['titulo']);
        });
        break;
    case 'year_asc':
        usort($all_movies, function($a, $b) {
            return $a['year'] - $b['year'];
        });
        break;
    case 'year_desc':
        usort($all_movies, function($a, $b) {
            return $b['year'] - $a['year'];
        });
        break;
    case 'updated_at_desc':
        usort($all_movies, function($a, $b) {
            return strtotime($b['updated_at']) - strtotime($a['updated_at']);
        });
        break;
    case 'created_at_asc':
        usort($all_movies, function($a, $b) {
            return strtotime($a['created_at']) - strtotime($b['created_at']);
        });
        break;
    case 'created_at_desc':
    default:
        usort($all_movies, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        break;
}

// Obtener todas las etiquetas únicas para el filtro
$all_etiquetas = [];
foreach ($all_movies_data['items'] as $movie) {
    if (!empty($movie['etiquetas'])) {
        $etiquetas = explode(',', $movie['etiquetas']);
        foreach ($etiquetas as $etiqueta) {
            $etiqueta = trim($etiqueta);
            if (!empty($etiqueta) && !in_array($etiqueta, $all_etiquetas)) {
                $all_etiquetas[] = $etiqueta;
            }
        }
    }
}
sort($all_etiquetas);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Películas - Panel de Administración</title>
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
        }

        .content.sidebar-open {
            margin-left: 250px;
        }

        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .media-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 15px;
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
            height: 100%;
            position: relative;
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

        .media-card h5 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-light);
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .media-card .year {
            font-size: 0.9rem;
            color: #9ca3af;
            margin-bottom: 5px;
        }

        .media-card .etiquetas {
            font-size: 0.8rem;
            color: #6b7280;
            margin-bottom: 10px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }

        .edit-btn, .delete-btn {
            border: none;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            flex: 1;
            justify-content: center;
        }

        .edit-btn {
            background-color: var(--accent-blue);
            color: #ffffff;
        }

        .edit-btn:hover {
            background-color: #2563eb;
        }

        .delete-btn {
            background-color: #ef4444;
            color: #ffffff;
        }

        .delete-btn:hover {
            background-color: #dc2626;
        }

        .search-filters {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-light);
        }

        .form-control, .form-select {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-light);
            color: var(--text-light);
        }

        .form-control:focus, .form-select:focus {
            background-color: var(--secondary-dark);
            border-color: var(--accent-blue);
            color: var(--text-light);
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }

        .stats-card {
            background: linear-gradient(135deg, var(--accent-blue), #1e40af);
            color: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .media-card img {
                height: 200px;
            }
            
            .action-buttons {
                flex-direction: column;
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
            <i class="fas fa-bars text-light"></i>
        </button>
        <a class="navbar-brand ms-3" href="#">Catálogo de Películas</a>
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
    <div class="container-fluid">
        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <h4><i class="fas fa-film"></i> Total Películas</h4>
                    <h2><?php echo count($all_movies); ?></h2>
                </div>
            </div>
        </div>

        <!-- Search and Filters -->
        <div class="search-filters">
            <form method="GET" action="">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Buscar por título</label>
                        <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Buscar películas...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Filtrar por etiquetas</label>
                        <select class="form-select" name="etiquetas">
                            <option value="">Todas las etiquetas</option>
                            <?php foreach ($all_etiquetas as $etiqueta): ?>
                                <option value="<?php echo htmlspecialchars($etiqueta); ?>" <?php echo $filter_etiquetas === $etiqueta ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($etiqueta); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Ordenar por</label>
                        <select class="form-select" name="sort">
                            <option value="created_at_desc" <?php echo $filter_sort === 'created_at_desc' ? 'selected' : ''; ?>>Más recientes primero</option>
                            <option value="created_at_asc" <?php echo $filter_sort === 'created_at_asc' ? 'selected' : ''; ?>>Más antiguos primero</option>
                            <option value="updated_at_desc" <?php echo $filter_sort === 'updated_at_desc' ? 'selected' : ''; ?>>Últimos editados</option>
                            <option value="titulo_asc" <?php echo $filter_sort === 'titulo_asc' ? 'selected' : ''; ?>>Título A-Z</option>
                            <option value="titulo_desc" <?php echo $filter_sort === 'titulo_desc' ? 'selected' : ''; ?>>Título Z-A</option>
                            <option value="year_desc" <?php echo $filter_sort === 'year_desc' ? 'selected' : ''; ?>>Año más reciente</option>
                            <option value="year_asc" <?php echo $filter_sort === 'year_asc' ? 'selected' : ''; ?>>Año más antiguo</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Movies Grid -->
        <div class="row">
            <?php if (empty($all_movies)): ?>
                <div class="col-12">
                    <div class="card text-center p-5">
                        <i class="fas fa-film fa-3x mb-3 text-muted"></i>
                        <h4>No se encontraron películas</h4>
                        <p class="text-muted">Intenta con otros términos de búsqueda o filtros.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($all_movies as $movie): ?>
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-4">
                        <div class="media-card">
                            <img src="<?php echo htmlspecialchars($movie['imagen'] ?? ''); ?>" 
                                 alt="<?php echo htmlspecialchars($movie['titulo'] ?? ''); ?>" 
                                 onerror="this.src='https://via.placeholder.com/300x450?text=Sin+Imagen'">
                            <h5><?php echo htmlspecialchars($movie['titulo'] ?? ''); ?></h5>
                            <div class="year">
                                <i class="fas fa-calendar"></i> <?php echo htmlspecialchars($movie['year'] ?? ''); ?>
                            </div>
                            <?php if (!empty($movie['etiquetas'])): ?>
                                <div class="etiquetas">
                                    <i class="fas fa-tags"></i> <?php echo htmlspecialchars($movie['etiquetas']); ?>
                                </div>
                            <?php endif; ?>
                            <div class="action-buttons">
                                <button class="edit-btn" onclick="window.location.href='add_edit_movie.php?id=<?php echo htmlspecialchars($movie['id_tmdb']); ?>'">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button class="delete-btn" onclick="confirmDelete('<?php echo htmlspecialchars($movie['id_tmdb']); ?>', '<?php echo htmlspecialchars($movie['titulo']); ?>')">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Función para confirmar eliminación
function confirmDelete(id, title) {
    if (confirm(`¿Estás seguro de que quieres eliminar "${title}"? Esta acción no se puede deshacer.`)) {
        // Aquí iría la lógica para eliminar
        alert(`Eliminar película: ${title} (ID: ${id})`);
        // window.location.href = `delete_movie.php?id=${id}`;
    }
}

// Sidebar functionality
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