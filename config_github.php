<?php
$config_dir = 'config/';
$config_file = $config_dir . 'github_config.json';

if (!is_dir($config_dir)) {
    mkdir($config_dir, 0755, true);
}

// Cargar configuración existente
$github_config = [];
if (file_exists($config_file)) {
    $github_config = json_decode(file_get_contents($config_file), true);
}

// Manejar POST para guardar configuración
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['github_token'] ?? '');
    $repo_owner = trim($_POST['repo_owner'] ?? '');
    $repo_name = trim($_POST['repo_name'] ?? '');
    $branch = trim($_POST['branch'] ?? '');
    $data_file = trim($_POST['data_file'] ?? '');
    $details_folder = trim($_POST['details_folder'] ?? '');

    // Mantener valores existentes si los campos sensibles están vacíos
    if (empty($token)) {
        $token = $github_config['token'] ?? '';
    }
    if (empty($repo_owner)) {
        $repo_owner = $github_config['repo_owner'] ?? '';
    }
    if (empty($repo_name)) {
        $repo_name = $github_config['repo_name'] ?? '';
    }
    if (empty($branch)) {
        $branch = $github_config['branch'] ?? 'main';
    }
    if (empty($data_file)) {
        $data_file = $github_config['data_file'] ?? 'home.json';
    }
    if (empty($details_folder)) {
        $details_folder = $github_config['details_folder'] ?? 'details/';
    }

    // Asegurar que details_folder termine con /
    if (!empty($details_folder) && substr($details_folder, -1) !== '/') {
        $details_folder .= '/';
    }

    if (!empty($token) && !empty($repo_owner) && !empty($repo_name)) {
        $config = [
            'token' => $token,
            'repo_owner' => $repo_owner,
            'repo_name' => $repo_name,
            'branch' => $branch,
            'data_file' => $data_file,
            'details_folder' => $details_folder
        ];

        file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
        $message = 'Configuración guardada exitosamente.';
    } else {
        $error = 'Por favor, complete todos los campos requeridos.';
    }

    // Recargar configuración después de guardar
    $github_config = $config ?? $github_config;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración GitHub - Panel de Administración</title>
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
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--border-light);
            color: var(--text-light);
            font-weight: 600;
        }

        .form-label {
            color: var(--text-light);
            font-weight: 500;
        }

        .form-control {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-light);
            color: var(--text-light);
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
        }

        .btn-primary:hover {
            background-color: #2563eb;
            border-color: #2563eb;
        }

        .alert {
            border-radius: 8px;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border: 1px solid #10b981;
            color: #10b981;
        }

        .alert-danger {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid #ef4444;
            color: #ef4444;
        }

        .info-text {
            color: #9ca3af;
            font-size: 0.9rem;
            margin-top: 5px;
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
            <div class="card">
                <div class="card-header">
                    <i class="fab fa-github"></i> Configuración de GitHub API
                </div>
                <div class="card-body">
                    <?php if (isset($message)): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                    <?php endif; ?>
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label for="github_token" class="form-label">Token de Acceso Personal (PAT)</label>
                            <input type="password" class="form-control" id="github_token" name="github_token" placeholder="" required>
                            <div class="info-text">Cree un PAT en <a href="https://github.com/settings/tokens" target="_blank" style="color: var(--accent-blue);">GitHub Settings</a> con permisos de repo.</div>
                        </div>
                        <div class="mb-3">
                            <label for="repo_owner" class="form-label">Dueño del Repositorio (Owner)</label>
                            <input type="text" class="form-control" id="repo_owner" name="repo_owner" placeholder=" (ej: tu-usuario)" required>
                        </div>
                        <div class="mb-3">
                            <label for="repo_name" class="form-label">Nombre del Repositorio</label>
                            <input type="text" class="form-control" id="repo_name" name="repo_name" placeholder=" (ej: mi-repo-peliculas)" required>
                        </div>
                        <div class="mb-3">
                            <label for="branch" class="form-label">Branch</label>
                            <input type="text" class="form-control" id="branch" name="branch" value="<?php echo htmlspecialchars($github_config['branch'] ?? 'main'); ?>" placeholder="ej: main">
                        </div>
                        <div class="mb-3">
                            <label for="data_file" class="form-label">Archivo de Datos (Índice)</label>
                            <input type="text" class="form-control" id="data_file" name="data_file" value="<?php echo htmlspecialchars($github_config['data_file'] ?? 'home.json'); ?>" placeholder="ej: home.json">
                            <div class="info-text">El archivo JSON que contiene el índice de películas y series.</div>
                        </div>
                        <div class="mb-3">
                            <label for="details_folder" class="form-label">Carpeta de Detalles</label>
                            <input type="text" class="form-control" id="details_folder" name="details_folder" value="<?php echo htmlspecialchars($github_config['details_folder'] ?? 'details/'); ?>" placeholder="ej: details/">
                            <div class="info-text">Carpeta en el repositorio donde se almacenan los detalles por ID de TMDB (ej: details/123.json).</div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Configuración</button>
                    </form>

                    <?php if (!empty($github_config)): ?>
                        <hr style="border-color: var(--border-light);">
                        <h6>Configuración Actual:</h6>
                        <ul class="list-unstyled small">
                            <li><strong>Repositorio:</strong> [Oculto por seguridad]</li>
                            <li><strong>Branch:</strong> <?php echo htmlspecialchars($github_config['branch']); ?></li>
                            <li><strong>Archivo de Índice:</strong> <?php echo htmlspecialchars($github_config['data_file']); ?></li>
                            <li><strong>Carpeta de Detalles:</strong> <?php echo htmlspecialchars($github_config['details_folder']); ?></li>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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