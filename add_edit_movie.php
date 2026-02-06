<?php
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
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

$id = $_GET['id'] ?? '';
$item = null;
$details = null;

$extract = isset($_GET['extract']);

// DEBUG: Información inicial
error_log("=== DEBUG: Iniciando carga para ID: " . $id . " ===");

if ($id && !empty($github_config)) {
// Cargar home.json
$api_url = "https://api.github.com/repos/{$github_config['repo_owner']}/{$github_config['repo_name']}/contents/{$github_config['data_file']}?ref={$github_config['branch']}";
$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
"Authorization: Bearer {$github_config['token']}",
"Accept: application/vnd.github.v3+json",
"User-Agent: PHP-App"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
$data = json_decode($response, true);
$content = base64_decode($data['content']);
$json_data = json_decode($content, true);
$item = array_filter($json_data['data'], function($item) use ($id) {
return $item['id_tmdb'] === $id;
});
$item = array_shift($item);
}

// Cargar details/<id_tmdb>.json - CÓDIGO MEJORADO
$detail_url = "https://api.github.com/repos/{$github_config['repo_owner']}/{$github_config['repo_name']}/contents/{$github_config['details_folder']}{$id}.json?ref={$github_config['branch']}";

error_log("DEBUG: Cargando detalles desde: " . $detail_url);

$ch = curl_init($detail_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
"Authorization: Bearer {$github_config['token']}",
"Accept: application/vnd.github.v3+json",
"User-Agent: PHP-App"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$detail_response = curl_exec($ch);
$detail_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

error_log("DEBUG: Código HTTP de detalles: " . $detail_http_code);
error_log("DEBUG: Error cURL: " . $curl_error);

if ($detail_http_code === 200) {
$detail_data = json_decode($detail_response, true);

if (isset($detail_data['content']) && !empty($detail_data['content'])) {
$details_content = base64_decode($detail_data['content']);
$details = json_decode($details_content, true);

// DEBUG EXTENSIVO
error_log("DEBUG: Detalles decodificados correctamente");
error_log("DEBUG: Estructura completa de detalles: " . print_r($details, true));

if ($details && isset($details['enlaces']) && is_array($details['enlaces'])) {
error_log("DEBUG: Se encontraron " . count($details['enlaces']) . " enlaces");
foreach ($details['enlaces'] as $index => $enlace) {
error_log("DEBUG: Enlace {$index}:");
error_log("DEBUG: - Nombre: '" . ($enlace['nombre'] ?? 'NULL') . "'");
error_log("DEBUG: - URL: '" . ($enlace['url'] ?? 'NULL') . "'");
error_log("DEBUG: - Type: '" . ($enlace['type'] ?? 'NULL') . "'");
error_log("DEBUG: - Language: '" . ($enlace['language'] ?? 'NULL') . "'");
}
} else {
error_log("DEBUG: No se encontraron enlaces en los detalles");
if ($details) {
error_log("DEBUG: Claves disponibles en detalles: " . implode(', ', array_keys($details)));
}
}
} else {
error_log("DEBUG: No hay contenido en detail_data o está vacío");
}
} else {
error_log("DEBUG: Falló la carga del archivo de detalles. Código: " . $detail_http_code);
if ($detail_http_code === 404) {
error_log("DEBUG: El archivo de detalles no existe en GitHub");
}
}
}

// Si no se pudieron cargar los detalles de GitHub, intentar cargar desde backup local
if (!$details && $id) {
$local_details_file = 'local_data/' . $id . '_details_backup.json';
if (file_exists($local_details_file)) {
$details_content = file_get_contents($local_details_file);
$details = json_decode($details_content, true);
error_log("DEBUG: Cargando detalles desde backup local");
}
}

// Fetch from TMDB if no item in home.json or extract parameter is set
if ((!$item || $extract) && !empty($id) && !empty($api_key)) {
$api_url = "https://api.themoviedb.org/3/movie/{$id}?api_key={$api_key}&language=es";
$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
$tmdb_data = json_decode($response, true);

// Obtener el año de la fecha de lanzamiento
$year = '';
if (!empty($tmdb_data['release_date'])) {
$year = date('Y', strtotime($tmdb_data['release_date']));
}

$item = [
'id_tmdb' => $id,
'titulo' => $tmdb_data['title'] ?? '',
'imagen' => $tmdb_data['poster_path'] ? "https://image.tmdb.org/t/p/w342{$tmdb_data['poster_path']}" : '',
'etiquetas' => implode(', ', array_map(fn($g) => $g['name'], $tmdb_data['genres'] ?? [])),
'type' => 'movie',
'created_at' => $tmdb_data['release_date'] ?? date('Y-m-d'),
'updated_at' => date('Y-m-d\TH:i:s.v\Z'),
'year' => $year,
'calidad' => 'HD'
];

// Solo crear detalles si no existen
if (!$details) {
$details = [
'titulo' => $tmdb_data['title'] ?? '',
'imagen' => $tmdb_data['poster_path'] ? "https://image.tmdb.org/t/p/w342{$tmdb_data['poster_path']}" : '',
'etiquetas' => implode(', ', array_map(fn($g) => $g['name'], $tmdb_data['genres'] ?? [])),
'year' => $year,
'created_at' => $tmdb_data['release_date'] ?? date('Y-m-d'),
'updated_at' => date('Y-m-d\TH:i:s.v\Z'),
'id_tmdb' => $id,
'type' => 'movie',
'calidad' => 'HD',
'synopsis' => $tmdb_data['overview'] ?? '',
];
}
}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$new_item = [
'id_tmdb' => $_POST['id_tmdb'],
'imagen' => $_POST['imagen'],
'titulo' => $_POST['titulo'],
'etiquetas' => $_POST['etiquetas'],
'type' => 'movie',
'created_at' => $_POST['created_at'] ?: date('Y-m-d'),
'updated_at' => date('Y-m-d\TH:i:s.v\Z'),
'year' => $_POST['year'],
'calidad' => $_POST['calidad']
];

// Preparar y guardar detalles en details/<id_tmdb>.json
$new_details = [
'titulo' => $_POST['titulo'],
'imagen' => $_POST['imagen'],
'etiquetas' => $_POST['etiquetas'],
'year' => $_POST['year'],
'created_at' => $_POST['created_at'] ?: date('Y-m-d'),
'updated_at' => date('Y-m-d\TH:i:s.v\Z'),
'id_tmdb' => $_POST['id_tmdb'],
'type' => 'movie',
'calidad' => $_POST['calidad'],
'synopsis' => $_POST['synopsis']
];

// Handle links (enlaces) - CORREGIDO
$enlaces = [];
if (isset($_POST['enlace_nombre']) && is_array($_POST['enlace_nombre'])) {
for ($i = 0; $i < count($_POST['enlace_nombre']); $i++) {
if (!empty(trim($_POST['enlace_nombre'][$i]))) {
$enlaces[] = [
'nombre' => trim($_POST['enlace_nombre'][$i]),
'url' => trim($_POST['enlace_url'][$i] ?? ''),
'type' => trim($_POST['enlace_type'][$i] ?? 'embed'),
'language' => trim($_POST['enlace_language'][$i] ?? 'Latino')
];
}
}
}

if (!empty($enlaces)) {
$new_details['enlaces'] = $enlaces;
}

// Guardar localmente como backup temporal
$local_file = 'local_data/' . $new_item['id_tmdb'] . '_backup.json';
$local_details_file = 'local_data/' . $new_item['id_tmdb'] . '_details_backup.json';

if (!is_dir('local_data')) {
mkdir('local_data', 0755, true);
}

file_put_contents($local_file, json_encode($new_item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($local_details_file, json_encode($new_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$home_updated = false;
$details_updated = false;

// Actualizar home.json en GitHub
if (!empty($github_config)) {
$api_url = "https://api.github.com/repos/{$github_config['repo_owner']}/{$github_config['repo_name']}/contents/{$github_config['data_file']}?ref={$github_config['branch']}";
$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
"Authorization: Bearer {$github_config['token']}",
"Accept: application/vnd.github.v3+json",
"User-Agent: PHP-App"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
$data = json_decode($response, true);
$content = base64_decode($data['content']);
$json_data = json_decode($content, true);

// Filtrar y reindexar el array
$json_data['data'] = array_filter($json_data['data'], function($entry) use ($new_item) {
return $entry['id_tmdb'] !== $new_item['id_tmdb'];
});

// REINDEXAR EL ARRAY
$json_data['data'] = array_values($json_data['data']);
$json_data['data'][] = $new_item;

$newContent = base64_encode(json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
"Authorization: Bearer {$github_config['token']}",
"Accept: application/vnd.github.v3+json",
"User-Agent: PHP-App"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
'message' => 'Actualizar película con ID ' . $new_item['id_tmdb'],
'content' => $newContent,
'sha' => $data['sha'],
'branch' => $github_config['branch']
]));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$home_response = curl_exec($ch);
$home_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$home_updated = ($home_http_code === 200 || $home_http_code === 201);
error_log("DEBUG: Home actualizado en GitHub: " . ($home_updated ? 'SÍ' : 'NO'));
}
}

// Guardar detalles en GitHub
if (!empty($github_config)) {
$details_content = base64_encode(json_encode($new_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$detail_url = "https://api.github.com/repos/{$github_config['repo_owner']}/{$github_config['repo_name']}/contents/{$github_config['details_folder']}{$new_item['id_tmdb']}.json";

// Obtener SHA del archivo existente (si existe)
$ch = curl_init($detail_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
"Authorization: Bearer {$github_config['token']}",
"Accept: application/vnd.github.v3+json",
"User-Agent: PHP-App"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$detail_response = curl_exec($ch);
$detail_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$detail_data = $detail_http_code === 200 ? json_decode($detail_response, true) : ['sha' => null];

$ch = curl_init($detail_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
"Authorization: Bearer {$github_config['token']}",
"Accept: application/vnd.github.v3+json",
"User-Agent: PHP-App"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
'message' => 'Actualizar detalles de película con ID ' . $new_item['id_tmdb'],
'content' => $details_content,
'sha' => $detail_data['sha'],
'branch' => $github_config['branch']
]));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$details_response = curl_exec($ch);
$details_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$details_updated = ($details_http_code === 200 || $details_http_code === 201);
error_log("DEBUG: Detalles actualizados en GitHub: " . ($details_updated ? 'SÍ' : 'NO'));
}

// ELIMINAR ARCHIVOS LOCALES SI SE GUARDÓ EXITOSAMENTE EN GITHUB
if ($home_updated || $details_updated) {
// Eliminar archivos locales de backup
if (file_exists($local_file)) {
unlink($local_file);
error_log("DEBUG: Archivo local eliminado: " . $local_file);
}
if (file_exists($local_details_file)) {
unlink($local_details_file);
error_log("DEBUG: Archivo local de detalles eliminado: " . $local_details_file);
}
} else {
error_log("DEBUG: Los archivos se mantienen locales porque no se pudo subir a GitHub");
}

ob_start();
header('Location: panel.php?success=1');
ob_end_flush();
exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agregar/Editar Película - Panel de Administración</title>
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
padding: 0px;
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

.extract-btn {
margin-bottom: 15px;
}

.enlace-item {
background-color: rgba(255, 255, 255, 0.05);
border: 1px solid var(--border-light);
border-radius: 8px;
padding: 15px;
margin-bottom: 15px;
}

.enlace-header {
display: flex;
justify-content: space-between;
align-items: center;
margin-bottom: 10px;
}

.enlace-title {
font-weight: 600;
color: var(--accent-blue);
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

.enlace-item {
padding: 10px;
}
}
</style>
</head>
<body>
<!-- Overlay para mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg">
<div class="container-fluid"><!--
<button class="navbar-toggler" type="button" id="sidebarToggle">
<i class="fas fa-bars text-light" id="toggleIcon"></i>
</button>-->
<a class="navbar-brand ms-3" href="#">Panel de Administración</a>
</div>
</nav>

<!-- Sidebar --><!--
<div class="sidebar" id="sidebar">
<a href=""><i class="fas fa-home"></i> Inicio</a>
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

<!-- PANEL DE DEPURACIÓN MEJORADO -->
<div class="debug-panel">
<h6 class="mb-3">🔍 Información de Depuración - Carga desde GitHub</h6>
<div class="row">
<div class="col-md-6">
<strong>ID TMDB:</strong> <span class="debug-success"><?php echo $id; ?></span><br>
<strong>Detalles cargados:</strong> 
<?php if (isset($details)): ?>
<span class="debug-success">SÍ</span>
<?php else: ?>
<span class="debug-error">NO</span>
<?php endif; ?>
<br>
<strong>Enlaces encontrados:</strong> 
<?php if (isset($details['enlaces']) && is_array($details['enlaces'])): ?>
<span class="debug-success"><?php echo count($details['enlaces']); ?></span>
<?php else: ?>
<span class="debug-warning">0</span>
<?php endif; ?>
<br>
<strong>Origen de datos:</strong> 
<?php 
if (isset($details) && file_exists('local_data/' . $id . '_details_backup.json')) {
echo '<span class="debug-warning">Backup Local</span>';
} elseif (isset($details)) {
echo '<span class="debug-success">GitHub</span>';
} else {
echo '<span class="debug-error">No disponible</span>';
}
?>
</div>
<div class="col-md-6">
<?php if (isset($details['enlaces']) && is_array($details['enlaces'])): ?>
<?php foreach ($details['enlaces'] as $index => $enlace): ?>
<div class="mb-2 p-2 bg-dark rounded">
<strong>Enlace <?php echo $index + 1; ?>:</strong><br>
<span class="debug-success">Nombre:</span> "<?php echo htmlspecialchars($enlace['nombre'] ?? 'VACÍO'); ?>"<br>
<span class="debug-success">URL:</span> "<?php echo htmlspecialchars($enlace['url'] ?? 'VACÍO'); ?>"<br>
<span class="debug-success">Type:</span> "<?php echo htmlspecialchars($enlace['type'] ?? 'VACÍO'); ?>"<br>
<span class="debug-success">Language:</span> "<?php echo htmlspecialchars($enlace['language'] ?? 'VACÍO'); ?>"
</div>
<?php endforeach; ?>
<?php else: ?>
<span class="debug-warning">No se encontraron enlaces en el JSON de detalles</span>
<?php endif; ?>
</div>
</div>
</div>

<div class="card">
<div class="card-header">
<i class="fas fa-edit"></i> <?php echo $id ? 'Editar' : 'Agregar'; ?> Película
<?php if ($id && $api_key): ?>
<a href="?id=<?php echo $id; ?>&extract=1" class="btn btn-sm btn-primary extract-btn float-end">
<i class="fas fa-download"></i> Extraer de TMDB
</a>
<?php endif; ?>
</div>
<form method="POST" id="contentForm">
<div class="form-group">
<label for="id_tmdb" class="form-label">ID TMDB <span class="text-danger">*</span></label>
<input type="text" class="form-control" id="id_tmdb" name="id_tmdb" value="<?php echo htmlspecialchars($item['id_tmdb'] ?? ''); ?>" required placeholder="Ej: 10020">
<small class="info-text">Identificador único de TMDB.</small>
</div>
<div class="form-group">
<label for="imagen" class="form-label">URL de la Imagen <span class="text-danger">*</span></label>
<input type="url" class="form-control" id="imagen" name="imagen" value="<?php echo htmlspecialchars($item['imagen'] ?? ''); ?>" required placeholder="Ej: https://image.tmdb.org/t/p/w342/...">
<small class="info-text">URL de la imagen del contenido.</small>
</div>
<div class="form-group">
<label for="titulo" class="form-label">Título <span class="text-danger">*</span></label>
<input type="text" class="form-control" id="titulo" name="titulo" value="<?php echo htmlspecialchars($item['titulo'] ?? ''); ?>" required placeholder="Ej: La Bella y La Bestia">
<small class="info-text">Título del contenido.</small>
</div>
<div class="form-group">
<label for="etiquetas" class="form-label">Etiquetas <span class="text-danger">*</span></label>
<input type="text" class="form-control" id="etiquetas" name="etiquetas" value="<?php echo htmlspecialchars($item['etiquetas'] ?? ''); ?>" required placeholder="Ej: Romance, Animación">
<small class="info-text">Etiquetas separadas por comas.</small>
</div>
<div class="form-group">
<label for="calidad" class="form-label">Calidad <span class="text-danger">*</span></label>
<select class="form-control" id="calidad" name="calidad" required>
<option value="HD" <?php echo (isset($item['calidad']) && $item['calidad'] === 'HD') ? 'selected' : ''; ?>>HD</option>
<option value="FullHD" <?php echo (isset($item['calidad']) && $item['calidad'] === 'FullHD') ? 'selected' : ''; ?>>Full HD</option>
<option value="4K" <?php echo (isset($item['calidad']) && $item['calidad'] === '4K') ? 'selected' : ''; ?>>4K</option>
<option value="SD" <?php echo (isset($item['calidad']) && $item['calidad'] === 'SD') ? 'selected' : ''; ?>>SD</option>
</select>
<small class="info-text">Calidad del contenido.</small>
</div>
<div class="form-group">
<label for="created_at" class="form-label">Fecha de Creación</label>
<input type="date" class="form-control" id="created_at" name="created_at" value="<?php echo htmlspecialchars($item['created_at'] ?? ''); ?>">
<small class="info-text">Fecha de creación (dejar en blanco para usar la actual).</small>
</div>
<div class="form-group">
<label for="year" class="form-label">Año <span class="text-danger">*</span></label>
<input type="text" class="form-control" id="year" name="year" value="<?php echo htmlspecialchars($item['year'] ?? ''); ?>" required placeholder="Ej: 1991">
<small class="info-text">Año de lanzamiento.</small>
</div>
<div class="form-group">
<label for="synopsis" class="form-label">Sinopsis <span class="text-danger">*</span></label>
<textarea class="form-control" id="synopsis" name="synopsis" required placeholder="Ej: La joven Bella se encuentra prisionera..." rows="5"><?php echo htmlspecialchars($details['synopsis'] ?? ''); ?></textarea>
<small class="info-text">Descripción del contenido.</small>
</div>

<!-- SECCIÓN DE ENLACES MEJORADA -->
<div class="form-group">
<label class="form-label">Enlaces</label>
<div class="enlace-group" id="enlace-group">
<?php if (isset($details['enlaces']) && is_array($details['enlaces']) && !empty($details['enlaces'])): ?>
<?php foreach ($details['enlaces'] as $e_index => $enlace): ?>
<div class="enlace-item">
<div class="enlace-header">
<span class="enlace-title">Enlace <?php echo $e_index + 1; ?></span>
<button type="button" class="btn btn-danger btn-sm" onclick="removeEnlace(this)">
<i class="fas fa-trash"></i> Eliminar
</button>
</div>
<div class="mb-2">
<label class="form-label">Nombre del enlace *</label>
<input type="text" class="form-control" name="enlace_nombre[]" 
 value="<?php echo htmlspecialchars($enlace['nombre'] ?? ''); ?>" 
 placeholder="Ej: Ver Online, Descargar" required>
</div>
<div class="mb-2">
<label class="form-label">URL del enlace *</label>
<input type="url" class="form-control" name="enlace_url[]" 
 value="<?php echo htmlspecialchars($enlace['url'] ?? ''); ?>" 
 placeholder="https://ejemplo.com/video" required>
</div>
<div class="row">
<div class="col-md-6">
<label class="form-label">Tipo</label>
<select class="form-control" name="enlace_type[]">
<option value="embed" <?php echo (($enlace['type'] ?? 'embed') === 'embed') ? 'selected' : ''; ?>>Embed</option>
<option value="mp4" <?php echo (($enlace['type'] ?? '') === 'mp4') ? 'selected' : ''; ?>>MP4</option>

</select>
</div>
<div class="col-md-6">
<label class="form-label">Idioma</label>
<select class="form-control" name="enlace_language[]">
<option value="Latino" <?php echo (($enlace['language'] ?? 'Latino') === 'Latino') ? 'selected' : ''; ?>>Latino</option>
<option value="Cast" <?php echo (($enlace['language'] ?? '') === 'Cast') ? 'selected' : ''; ?>>Español/Castellano</option>
<option value="Sub" <?php echo (($enlace['language'] ?? '') === 'Sub') ? 'selected' : ''; ?>>Sub</option>
</select>
</div>
</div>
</div>
<?php endforeach; ?>
<?php else: ?>
<!-- Enlace por defecto cuando no hay enlaces -->
<div class="enlace-item">
<div class="enlace-header">
<span class="enlace-title">Enlace 1</span>
<button type="button" class="btn btn-danger btn-sm" onclick="removeEnlace(this)">
<i class="fas fa-trash"></i> Eliminar
</button>
</div>
<div class="mb-2">
<label class="form-label">Nombre del enlace *</label>
<input type="text" class="form-control" name="enlace_nombre[]" 
 placeholder="Ej: Ver Online, Descargar" required>
</div>
<div class="mb-2">
<label class="form-label">URL del enlace *</label>
<input type="url" class="form-control" name="enlace_url[]" 
 placeholder="https://ejemplo.com/video" required>
</div>
<div class="row">
<div class="col-md-6">
<label class="form-label">Tipo</label>
<select class="form-control" name="enlace_type[]">
<option value="embed">Embed</option>
<option value="mp4">MP4</option>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Idioma</label>
<select class="form-control" name="enlace_language[]">
<option value="Latino">Latino</option>
<option value="Cast">Español/Castellano</option>
<option value="Sub">Sub</option>
</select>
</div>
</div>
</div>
<?php endif; ?>
</div>
<button type="button" class="btn btn-secondary mt-2" onclick="addEnlace()">
<i class="fas fa-plus"></i> Agregar Enlace
</button>
<small class="info-text">Añade múltiples enlaces para la película.</small>
</div>

<div class="button-group">
<button type="button" class="btn btn-secondary" onclick="window.location.href='panel.php'">
<i class="fas fa-times"></i> Cancelar
</button>
<button type="submit" class="btn btn-primary">
<i class="fas fa-save"></i> Guardar
</button>
</div>
</form>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
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

// Dynamic link management
function addEnlace() {
const enlaceGroup = document.getElementById('enlace-group');
const enlaceCount = enlaceGroup.getElementsByClassName('enlace-item').length;
const newEnlace = `
<div class="enlace-item">
<div class="enlace-header">
<span class="enlace-title">Enlace ${enlaceCount + 1}</span>
<button type="button" class="btn btn-danger btn-sm" onclick="removeEnlace(this)">
<i class="fas fa-trash"></i> Eliminar
</button>
</div>
<div class="mb-2">
<label class="form-label">Nombre del enlace *</label>
<input type="text" class="form-control" name="enlace_nombre[]" 
 placeholder="Ej: Ver Online, Descargar" required>
</div>
<div class="mb-2">
<label class="form-label">URL del enlace *</label>
<input type="url" class="form-control" name="enlace_url[]" 
 placeholder="https://ejemplo.com/video" required>
</div>
<div class="row">
<div class="col-md-6">
<label class="form-label">Tipo</label>
<select class="form-control" name="enlace_type[]">
<option value="embed">Embed</option>
<option value="mp4">MP4</option>
</select>
</div>
<div class="col-md-6">
<label class="form-label">Idioma</label>
<select class="form-control" name="enlace_language[]">
<option value="Latino">Latino</option>
<option value="Cast">Español/Castellano</option>
<option value="Sub">Sub</option>
</select>
</div>
</div>
</div>
`;
enlaceGroup.insertAdjacentHTML('beforeend', newEnlace);
}

function removeEnlace(element) {
const group = element.closest('.enlace-item');
if (group) {
group.remove();
// Renumerar los enlaces restantes
const enlaceItems = document.querySelectorAll('.enlace-item');
enlaceItems.forEach((item, index) => {
const title = item.querySelector('.enlace-title');
if (title) {
title.textContent = `Enlace ${index + 1}`;
}
});
}
}

// Auto-fill year from created_at date
document.getElementById('created_at').addEventListener('change', function() {
const yearInput = document.getElementById('year');
if (this.value && !yearInput.value) {
const year = new Date(this.value).getFullYear();
yearInput.value = year;
}
});

// Validación del formulario
document.getElementById('contentForm').addEventListener('submit', function(e) {
const requiredFields = this.querySelectorAll('[required]');
let isValid = true;
requiredFields.forEach(field => {
if (!field.value.trim()) {
isValid = false;
field.style.borderColor = '#ef4444';
} else {
field.style.borderColor = '';
}
});
if (!isValid) {
e.preventDefault();
alert('Por favor, completa todos los campos requeridos.');
}
});

// Depuración en consola
console.log('Formulario cargado correctamente');
<?php if (isset($details['enlaces'])): ?>
console.log('Enlaces cargados desde PHP:', <?php echo json_encode($details['enlaces']); ?>);
<?php endif; ?>
</script>
</body>
</html>