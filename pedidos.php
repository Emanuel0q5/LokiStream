<?php
// admin_pedidos.php
// Este script maneja el administrador de pedidos usando la API de GitHub.
// Asegúrate de llenar el archivo config/github_config.json con tus credenciales.

// Función para obtener el contenido del archivo de GitHub
function get_github_file($config, $path) {
    $url = "https://api.github.com/repos/{$config['repo_owner']}/{$config['repo_name']}/contents/$path?ref={$config['branch']}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: token {$config['token']}",
        "User-Agent: PHP"
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (isset($data['content'])) {
        return ['content' => base64_decode($data['content']), 'sha' => $data['sha']];
    }
    return false;
}

// Función para actualizar el archivo en GitHub
function put_github_file($config, $path, $content, $sha, $message) {
    $url = "https://api.github.com/repos/{$config['repo_owner']}/{$config['repo_name']}/contents/$path";
    $body = [
        'message' => $message,
        'content' => base64_encode($content),
        'sha' => $sha,
        'branch' => $config['branch']
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: token {$config['token']}",
        "User-Agent: PHP",
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Cargar configuración
$config_file = 'config/github_config.json';
if (!file_exists($config_file)) {
    die("Error: No se encontró el archivo de configuración $config_file");
}
$config = json_decode(file_get_contents($config_file), true);

// Definir el path del archivo de datos
$path = !empty($config['data_pedido']) ? $config['data_pedido'] : 'pedidos.json';

// Mensaje de estado
$message = '';

// Manejar actualización por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['index']) && isset($_POST['status'])) {
    $index = (int)$_POST['index'];
    $new_status = $_POST['status'];
    
    $file = get_github_file($config, $path);
    if ($file) {
        $pedidos = json_decode($file['content'], true);
        if (!is_array($pedidos)) $pedidos = [];
        
        if (isset($pedidos[$index])) {
            $pedidos[$index]['status'] = $new_status;
            $new_content = json_encode($pedidos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $result = put_github_file($config, $path, $new_content, $file['sha'], "Actualizado status del pedido #$index a $new_status");
            if (isset($result['content'])) {
                $message = "Status actualizado exitosamente para el pedido #$index.";
            } else {
                $message = "Error al actualizar: " . json_encode($result);
            }
        } else {
            $message = "Índice de pedido no encontrado.";
        }
    } else {
        $message = "Error al obtener el archivo de GitHub.";
    }
}

// Obtener los pedidos
$file = get_github_file($config, $path);
$pedidos = [];
$sha = '';
if ($file) {
    $pedidos = json_decode($file['content'], true);
    if (!is_array($pedidos)) $pedidos = [];
    $sha = $file['sha'];
}

// Verificar y agregar status faltantes o vacíos
$updated = false;
foreach ($pedidos as $key => &$pedido) {
    if (!isset($pedido['status']) || empty($pedido['status'])) {
        $pedido['status'] = 'En Proceso';
        $updated = true;
    }
}
unset($pedido);

if ($updated) {
    $new_content = json_encode($pedidos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $result = put_github_file($config, $path, $new_content, $sha, "Agregados status faltantes o vacíos en pedidos");
    if (isset($result['content'])) {
        $message .= " Status faltantes o vacíos agregados automáticamente.";
        // Refrescar datos después de la actualización
        $file = get_github_file($config, $path);
        $pedidos = json_decode($file['content'], true);
    } else {
        $message .= " Error al agregar status faltantes o vacíos.";
    }
}

// Ordenar los pedidos por 'count' descendente
usort($pedidos, function($a, $b) {
    return ($b['count'] ?? 0) - ($a['count'] ?? 0);
});

$statuses = ['Realizado', 'En Proceso', 'Próximamente', 'No Encontrado'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrador de Pedidos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    
       <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
            background-color: var(--primary-dark);
            color: var(--text-light);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        h1 {
            color: var(--text-light);
            text-align: center;
            margin-bottom: 30px;
            margin-top: 20px;
        }
        .card {
            background-color: var(--card-bg);
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            border-radius: 12px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.5);
        }
        .card img {
            width: 120px;
            height: auto;
            border-radius: 8px;
            object-fit: cover;
        }
        .title {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--text-light);
            margin-bottom: 0.25rem;
        }
        .type-badge {
            font-size: 0.8rem;
            background-color: var(--secondary-dark);
            color: var(--text-light);
        }
        .bottom-info {
            font-size: 0.9rem;
            color: #a0a0a0;
        }
        .update-btn {
            background-color: var(--accent-blue);
            border: none;
            font-size: 0.9rem;
        }
        .update-btn:hover {
            background-color: #2563eb;
        }
        .select-status {
            background-color: var(--secondary-dark);
            color: var(--text-light);
            border: 1px solid var(--border-light);
            font-size: 0.9rem;
        }
        .select-status option {
            background-color: var(--secondary-dark);
            color: var(--text-light);
        }
        .message {
            background-color: var(--card-bg);
            border: 1px solid var(--border-light);
            color: var(--text-light);
        }
        .error {
            background-color: #4d0000;
            border: 1px solid #990000;
            color: #ffcccc;
        }
        .no-pedidos {
            text-align: center;
            font-size: 1.2em;
            color: #cccccc;
        }
        @media (max-width: 576px) {
            .card img {
                margin-bottom: 1rem;
            }
            .title {
                font-size: 1.3rem;
            }
            .type-badge {
                font-size: 0.7rem;
            }
            .bottom-info {
                font-size: 0.8rem;
            }
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
        
                @media (max-width: 768px) {
            
                
                
            

           
                
            
/*
            .container {
                margin: 10px;
                padding: 10px;
            }*/

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
        <a href="pedidos.php"><i class="fa-regular fa-chart-bar"></i>Solicitudes </a>
    </div>
    
    
    
    
    <div class="container">
        <h1><i class="fas fa-box-open me-2"></i>Administrador de Pedidos</h1>
        <?php if ($message): ?>
            <div class="alert <?php echo strpos($message, 'Error') !== false ? 'alert-danger error' : 'alert-info message'; ?>">
                <?php echo htmlentities($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (empty($pedidos)): ?>
            <p class="no-pedidos">No hay pedidos disponibles.</p>
        <?php else: ?>
            <div class="row gy-3">
                <?php foreach ($pedidos as $index => $pedido): ?>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body row align-items-start g-3">
                                <?php if (isset($pedido['image'])): ?>
                                    <div class="col-auto">
                                        <img src="<?php echo htmlentities($pedido['image']); ?>" alt="<?php echo htmlentities($pedido['title'] ?? 'Imagen'); ?>">
                                    </div>
                                <?php endif; ?>
                                <div class="col">
                                    <div class="row g-1">
                                        <div class="col-12">
                                            <span class="badge rounded-pill type-badge"><?php echo htmlentities($pedido['type'] ?? 'N/A'); ?></span>
                                        </div>
                                        <div class="col-12 title"><?php echo htmlentities($pedido['title'] ?? 'N/A'); ?></div>
                                        <!-- No description in data, so omitted -->
                                        <div class="col-12">
                                            <div class="row g-3 align-items-center bottom-info">
                                                <div class="col-auto"><?php echo htmlentities($pedido['count'] ?? '0'); ?> solicitudes</div>
                                                <div class="col-auto">
                                                    <?php 
                                                    if (isset($pedido['timestamps']) && is_array($pedido['timestamps']) && !empty($pedido['timestamps'])) {
                                                        $first_ts = $pedido['timestamps'][0];
                                                        echo date('d/m/Y', strtotime($first_ts));
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                    ?>
                                                </div>
                                                <div class="col">
                                                    <form method="post" class="row g-2 justify-content-end">
                                                        <input type="hidden" name="index" value="<?php echo $index; ?>">
                                                        <div class="col-auto">
                                                            <select name="status" class="form-select select-status">
                                                                <?php foreach ($statuses as $s): ?>
                                                                    <option value="<?php echo $s; ?>" <?php if (isset($pedido['status']) && $pedido['status'] === $s) echo 'selected'; ?>><?php echo $s; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-auto">
                                                            <button type="submit" class="btn update-btn">Actualizar</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
   
    <script>
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('¿Estás seguro de actualizar el status de este pedido?')) {
                    e.preventDefault();
                }
            });
        });
    </script>
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