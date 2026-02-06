<?php
// subir_masivo.php
session_start();

$config_file = 'config/github_config.json';
$tmdb_config_file = 'config/tmdb_config.json';
$github_config = array();
$tmdb_config = array();

if (file_exists($config_file)) {
    $github_config = json_decode(file_get_contents($config_file), true);
}
if (file_exists($tmdb_config_file)) {
    $tmdb_config = json_decode(file_get_contents($tmdb_config_file), true);
}

$api_key = isset($tmdb_config['api_key']) ? $tmdb_config['api_key'] : '';

// Función para buscar en TMDB
function searchTMDB($id, $type, $season = null, $episode = null) {
    global $api_key;

    if (empty($api_key)) {
        return array('error' => 'API Key de TMDB no configurada');
    }

    $base_url = "https://api.themoviedb.org/3";

    if ($type === 'movie') {
        $url = "{$base_url}/movie/{$id}?api_key={$api_key}&language=es&append_to_response=credits";
    } else {
        if ($season && $episode) {
            $url = "{$base_url}/tv/{$id}/season/{$season}/episode/{$episode}?api_key={$api_key}&language=es";
        } elseif ($season) {
            $url = "{$base_url}/tv/{$id}/season/{$season}?api_key={$api_key}&language=es";
        } else {
            $url = "{$base_url}/tv/{$id}?api_key={$api_key}&language=es&append_to_response=credits";
        }
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        return json_decode($response, true);
    } else {
        return array('error' => "Error al buscar en TMDB: HTTP {$http_code}");
    }
}

// Función para obtener información de episodios de una temporada
function getSeasonEpisodes($series_id, $season_number) {
    global $api_key;

    if (empty($api_key)) {
        return array('error' => 'API Key de TMDB no configurada');
    }

    $url = "https://api.themoviedb.org/3/tv/{$series_id}/season/{$season_number}?api_key={$api_key}&language=es";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        return json_decode($response, true);
    } else {
        return array('error' => "Error al obtener temporada: HTTP {$http_code}");
    }
}

// Función para formatear fecha de TMDB (para created_at)
function formatTMDBDate($date_string) {
    if (empty($date_string)) {
        return date('Y-m-d');
    }

    try {
        $date = new DateTime($date_string);
        return $date->format('Y-m-d');
    } catch (Exception $e) {
        return date('Y-m-d');
    }
}

// Función para obtener timestamp actual (para updated_at)
function getCurrentTimestamp() {
    return date('Y-m-d\TH:i:s.v\Z');
}

// Función para formatear nombre de episodio con número
function formatEpisodeName($numero, $titulo) {
    $numero_formateado = str_pad($numero, 2, '0', STR_PAD_LEFT);
    return "{$numero_formateado}. {$titulo}";
}

// Función para validar URL
function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

// Función para validar tipo de enlace
function isValidType($type) {
    $valid_types = array('embed', 'mp4', 'm3u8', 'direct');
    return in_array(strtolower($type), $valid_types);
}

// Función para validar idioma
function isValidLanguage($language) {
    $valid_languages = array('latino', 'sub', 'castellano', 'español', 'ingles', 'dual', 'cast');
    return in_array(strtolower($language), $valid_languages);
}

// Función para validar tipo de contenido
function isValidContentType($type) {
    $valid_types = array('animes', 'series', 'dorama', 'movie');
    return in_array(strtolower($type), $valid_types);
}

// === FUNCIÓN MEJORADA: Actualizar home.json con formato objeto { "0": {}, "1": {} } y SIN DUPLICADOS por id_tmdb ===
function updateHomeJson($new_item, $github_config) {
    if (empty($github_config)) {
        return array('error' => 'Configuración de GitHub no disponible');
    }

    $api_url = "https://api.github.com/repos/{$github_config['repo_owner']}/{$github_config['repo_name']}/contents/{$github_config['data_file']}?ref={$github_config['branch']}";

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer {$github_config['token']}",
        "Accept: application/vnd.github.v3+json",
        "User-Agent: PHP-App"
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return array('error' => "Error al cargar home.json: HTTP {$http_code}");
    }

    $data = json_decode($response, true);
    $content = base64_decode($data['content']);
    $json_data = json_decode($content, true);

    // Convertir a formato objeto si aún es array
    if (isset($json_data['data']) && is_array($json_data['data']) && array_keys($json_data['data']) === range(0, count($json_data['data']) - 1)) {
        $json_data['data'] = array_values($json_data['data']); // asegurar array numérico
        $new_data = [];
        foreach ($json_data['data'] as $i => $item) {
            $new_data[(string)$i] = $item;
        }
        $json_data['data'] = $new_data;
    }

    $new_id = strval($new_item['id_tmdb']);

    // Verificar si ya existe el id_tmdb y actualizar si es así
    $exists = false;
    if (isset($json_data['data']) && is_array($json_data['data'])) {
        foreach ($json_data['data'] as $key => &$existing) {  // Nota: &$existing para modificar directamente
            if (isset($existing['id_tmdb']) && strval($existing['id_tmdb']) === $new_id) {
                // Actualizar los campos existentes con los nuevos valores
                foreach ($new_item as $field => $value) {
                    $existing[$field] = $value;
                }
                $exists = true;
                break;
            }
        }
    }

    // Si no existe, añadir nuevo con el siguiente índice numérico como string
    if (!$exists) {
        $next_index = 0;
        if (isset($json_data['data']) && is_array($json_data['data'])) {
            $indices = array_map('intval', array_keys($json_data['data']));
            if (!empty($indices)) {
                $next_index = max($indices) + 1;
            }
        }
        $json_data['data'][(string)$next_index] = $new_item;
    }

    // Guardar cambios en GitHub
    $newContent = base64_encode(json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer {$github_config['token']}",
        "Accept: application/vnd.github.v3+json",
        "User-Agent: PHP-App"
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
        'message' => 'Actualizar home.json - Agregar/Actualizar ID ' . $new_id,
        'content' => $newContent,
        'sha' => $data['sha'],
        'branch' => $github_config['branch']
    )));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($http_code === 200 || $http_code === 201) ? array('success' => true) : array('error' => "Error al guardar home.json: HTTP {$http_code}");
}

// === NUEVA FUNCIÓN: Actualizar details.json SIN BORRAR servidores existentes ===
function updateDetailsJson($new_details, $github_config) {
    if (empty($github_config)) {
        return array('error' => 'Configuración de GitHub no disponible');
    }

    $id_tmdb = strval($new_details['id_tmdb']);
    $detail_url = "https://api.github.com/repos/{$github_config['repo_owner']}/{$github_config['repo_name']}/contents/{$github_config['details_folder']}{$id_tmdb}.json";

    // Obtener archivo existente
    $ch = curl_init($detail_url . "?ref=" . $github_config['branch']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer {$github_config['token']}",
        "User-Agent: PHP-App",
        "Accept: application/vnd.github.v3+json"
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $existing_data = null;
    $sha = null;

    if ($http_code === 200) {
        $file = json_decode($response, true);
        $existing_content = base64_decode($file['content']);
        $existing_data = json_decode($existing_content, true);
        $sha = $file['sha'];
    }

    // Si no existe, usar los nuevos datos tal cual
    if (!$existing_data) {
        $final_data = $new_details;
    } else {
        // Fusionar: mantener todo lo viejo + añadir nuevos enlaces
        $final_data = $existing_data;

        // Actualizar metadatos básicos
        $final_data['titulo'] = $new_details['titulo'] ?? $existing_data['titulo'];
        $final_data['imagen'] = $new_details['imagen'] ?? $existing_data['imagen'];
        $final_data['etiquetas'] = $new_details['etiquetas'] ?? $existing_data['etiquetas'];
        $final_data['year'] = $new_details['year'] ?? $existing_data['year'];
        $final_data['synopsis'] = $new_details['synopsis'] ?? $existing_data['synopsis'];
        $final_data['updated_at'] = $new_details['updated_at'];
        $final_data['type'] = $new_details['type'] ?? $existing_data['type'];

        // Para películas: añadir nuevos enlaces
        if ($new_details['type'] === 'movie' && isset($new_details['enlaces'])) {
            if (!isset($final_data['enlaces'])) $final_data['enlaces'] = [];
            foreach ($new_details['enlaces'] as $nuevo) {
                $existe = false;
                foreach ($final_data['enlaces'] as $viejo) {
                    if ($viejo['url'] === $nuevo['url']) {
                        $existe = true;
                        break;
                    }
                }
                if (!$existe) {
                    $final_data['enlaces'][] = $nuevo;
                }
            }
        }

        // Para series/animes/doramas: añadir nuevos episodios o enlaces dentro de episodios
        if (in_array($new_details['type'], ['series', 'animes', 'dorama']) && isset($new_details['temporadas'])) {
            if (!isset($final_data['temporadas'])) $final_data['temporadas'] = [];

            foreach ($new_details['temporadas'] as $nueva_temp) {
                $temp_nombre = $nueva_temp['temporada'];
                $temp_encontrada = false;

                foreach ($final_data['temporadas'] as &$temp_existente) {
                    if ($temp_existente['temporada'] === $temp_nombre) {
                        $temp_encontrada = true;
                        foreach ($nueva_temp['capitulos'] as $nuevo_cap) {
                            $ep_id = $nuevo_cap['episode_id'];
                            $cap_encontrado = false;

                            foreach ($temp_existente['capitulos'] as &$cap_existente) {
                                if ($cap_existente['episode_id'] === $ep_id) {
                                    $cap_encontrado = true;
                                    if (!isset($cap_existente['enlaces'])) $cap_existente['enlaces'] = [];
                                    foreach ($nuevo_cap['enlaces'] as $nuevo_enlace) {
                                        $url_existe = false;
                                        foreach ($cap_existente['enlaces'] as $enlace_viejo) {
                                            if ($enlace_viejo['url'] === $nuevo_enlace['url']) {
                                                $url_existe = true;
                                                break;
                                            }
                                        }
                                        if (!$url_existe) {
                                            $cap_existente['enlaces'][] = $nuevo_enlace;
                                        }
                                    }
                                    break;
                                }
                            }

                            // Si el capítulo no existe, añadirlo completo
                            if (!$cap_encontrado) {
                                $temp_existente['capitulos'][] = $nuevo_cap;
                            }
                        }
                        break;
                    }
                }

                // Si la temporada no existe, añadirla completa
                if (!$temp_encontrada) {
                    $final_data['temporadas'][] = $nueva_temp;
                }
            }
        }
    }

    // Guardar
    $content = base64_encode(json_encode($final_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $ch = curl_init($detail_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer {$github_config['token']}",
        "User-Agent: PHP-App",
        "Accept: application/vnd.github.v3+json"
    ));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
        'message' => 'Actualizar detalles ID ' . $id_tmdb . ' (servidores añadidos)',
        'content' => $content,
        'sha' => $sha,
        'branch' => $github_config['branch']
    )));
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($http_code === 200 || $http_code === 201) ? array('success' => true) : array('error' => "Error HTTP {$http_code}");
}

// Procesar el contenido masivo
$resultado_procesamiento = array();
$contenido_procesado = array();
$errores = array();

$procesar_post = isset($_POST['procesar']) ? $_POST['procesar'] : false;
if ($procesar_post) {
    $contenido_masivo = isset($_POST['contenido_masivo']) ? $_POST['contenido_masivo'] : '';

    if (!empty($contenido_masivo)) {
        $lineas = explode("\n", $contenido_masivo);
        $contenido_actual = null;
        $temporada_actual = null;
        $episodio_actual = null;
        $id_actual = null;
        $item_actual = array();

        foreach ($lineas as $numero_linea => $linea) {
            $linea = trim($linea);

            if (empty($linea)) {
                continue; // Saltar líneas vacías
            }

            // Detectar tipo de contenido
            if (preg_match('/^\[Temporada\s+(\d+)\]$/i', $linea, $matches)) {
                // Si ya hay un item en proceso, guardarlo primero
                if (!empty($item_actual) && isset($item_actual['tipo']) && isset($item_actual['tmdb_id'])) {
                    $contenido_procesado[] = $item_actual;
                }

                $temporada_actual = (int)$matches[1];
                $contenido_actual = 'tv'; // Usamos 'tv' para series/animes/doramas
                $id_actual = null;
                $episodio_actual = null;
                $item_actual = array(
                    'tipo' => 'tv',
                    'content_type' => 'series', // Default
                    'temporada' => $temporada_actual,
                    'episodios' => array()
                );
                $resultado_procesamiento[] = "📺 Procesando Temporada {$temporada_actual}";
            } 
            elseif (preg_match('/^Movie$/i', $linea)) {
                // Si ya hay un item en proceso, guardarlo primero
                if (!empty($item_actual) && isset($item_actual['tipo']) && isset($item_actual['tmdb_id'])) {
                    $contenido_procesado[] = $item_actual;
                }

                $contenido_actual = 'movie';
                $temporada_actual = null;
                $episodio_actual = null;
                $id_actual = null;
                $item_actual = array(
                    'tipo' => 'movie',
                    'content_type' => 'movie'
                );
                $resultado_procesamiento[] = "🎬 Nueva Película detectada";
            }
            // Detectar ID
            elseif (preg_match('/^ID:\s*(\d+)/i', $linea, $matches)) {
                $id_actual = (int)$matches[1];
                $item_actual['tmdb_id'] = $id_actual;

                $tmdb_type = ($contenido_actual === 'movie') ? 'movie' : 'tv';
                $info = searchTMDB($id_actual, $tmdb_type);
                if (isset($info['error'])) {
                    $error_msg = "❌ Error ID {$id_actual}: {$info['error']}";
                    $resultado_procesamiento[] = $error_msg;
                    $errores[] = $error_msg;
                } else {
                    if ($contenido_actual === 'movie') {
                        $item_actual['titulo'] = isset($info['title']) ? $info['title'] : 'Sin título';
                        $item_actual['ano'] = isset($info['release_date']) ? substr($info['release_date'], 0, 4) : 'N/A';
                        $item_actual['poster'] = isset($info['poster_path']) ? "https://image.tmdb.org/t/p/w500{$info['poster_path']}" : '';
                        $item_actual['sinopsis'] = isset($info['overview']) ? $info['overview'] : '';
                        $item_actual['etiquetas'] = isset($info['genres']) ? implode(', ', array_map(function($g) { return isset($g['name']) ? $g['name'] : ''; }, $info['genres'])) : '';
                        $item_actual['fecha_original'] = isset($info['release_date']) ? $info['release_date'] : '';
                        $resultado_procesamiento[] = "✅ Película: {$item_actual['titulo']} ({$item_actual['ano']})";
                    } else {
                        // Para tv (series/animes/doramas)
                        $item_actual['titulo'] = isset($info['name']) ? $info['name'] : 'Sin título';
                        $item_actual['ano'] = isset($info['first_air_date']) ? substr($info['first_air_date'], 0, 4) : 'N/A';
                        $item_actual['poster'] = isset($info['poster_path']) ? "https://image.tmdb.org/t/p/w500{$info['poster_path']}" : '';
                        $item_actual['sinopsis'] = isset($info['overview']) ? $info['overview'] : '';
                        $item_actual['etiquetas'] = isset($info['genres']) ? implode(', ', array_map(function($g) { return isset($g['name']) ? $g['name'] : ''; }, $info['genres'])) : '';
                        $item_actual['fecha_original'] = isset($info['first_air_date']) ? $info['first_air_date'] : '';

                        // Obtener información de la temporada
                        if ($temporada_actual) {
                            $season_info = getSeasonEpisodes($id_actual, $temporada_actual);
                            if (!isset($season_info['error'])) {
                                $item_actual['temporada_info'] = $season_info;
                                $episodes_count = isset($season_info['episodes']) ? count($season_info['episodes']) : 0;
                                $resultado_procesamiento[] = "✅ Contenido: {$item_actual['titulo']} - Temporada {$temporada_actual} ({$episodes_count} episodios)";
                            } else {
                                $resultado_procesamiento[] = "⚠️ Contenido: {$item_actual['titulo']} - Temporada {$temporada_actual} (sin info de episodios)";
                            }
                        } else {
                            $resultado_procesamiento[] = "✅ Contenido: {$item_actual['titulo']}";
                        }
                    }
                }
            }
            // Detectar Type de contenido (animes, series, dorama) - solo para tv
            elseif (preg_match('/^Type:\s*(animes|series|dorama)/i', $linea, $matches)) {
                if ($contenido_actual === 'tv') {
                    $detected_type = strtolower(trim($matches[1]));
                    $item_actual['content_type'] = $detected_type;
                    $resultado_procesamiento[] = " 📋 Tipo de contenido: " . ucfirst($detected_type);
                }
            }
            // Detectar episodio
            elseif (preg_match('/^Episodio\s+(\d+)/i', $linea, $matches)) {
                $episodio_actual = (int)$matches[1];

                // Crear estructura del episodio si no existe
                if (!isset($item_actual['episodios'][$episodio_actual])) {
                    $item_actual['episodios'][$episodio_actual] = array(
                        'numero' => $episodio_actual,
                        'enlaces' => array()
                    );

                    // Si tenemos información de la temporada, obtener datos del episodio
                    if (isset($item_actual['temporada_info']['episodes'])) {
                        foreach ($item_actual['temporada_info']['episodes'] as $episode_info) {
                            if (isset($episode_info['episode_number']) && $episode_info['episode_number'] == $episodio_actual) {
                                $titulo_episodio = isset($episode_info['name']) ? $episode_info['name'] : "Episodio {$episodio_actual}";
                                // Formatear nombre del episodio con número
                                $item_actual['episodios'][$episodio_actual]['titulo'] = formatEpisodeName($episodio_actual, $titulo_episodio);
                                $item_actual['episodios'][$episodio_actual]['imagen'] = isset($episode_info['still_path']) ? "https://image.tmdb.org/t/p/w500{$episode_info['still_path']}" : '';
                                break;
                            }
                        }
                    } else {
                        // Si no hay información de TMDB, usar formato por defecto
                        $item_actual['episodios'][$episodio_actual]['titulo'] = formatEpisodeName($episodio_actual, "Episodio {$episodio_actual}");
                    }
                }

                $resultado_procesamiento[] = " 📺 Episodio {$episodio_actual}";
            }
            // Detectar URL
            elseif (preg_match('/^Url:\s*(.+)/i', $linea, $matches)) {
                $url = trim($matches[1]);
                if (!empty($url)) {
                    if (!isValidUrl($url)) {
                        $error_msg = " ❌ URL inválida en línea " . ($numero_linea + 1);
                        $resultado_procesamiento[] = $error_msg;
                        $errores[] = $error_msg;
                    } else {
                        if ($contenido_actual === 'movie') {
                            $item_actual['url'] = $url;
                            $resultado_procesamiento[] = " 🔗 URL: " . (strlen($url) > 60 ? substr($url, 0, 60) . '...' : $url);
                        } else if ($episodio_actual && isset($item_actual['episodios'][$episodio_actual])) {
                            // Crear nuevo enlace para el episodio
                            $item_actual['episodios'][$episodio_actual]['enlaces'][] = array(
                                'url' => $url,
                                'type' => '',
                                'idioma' => ''
                            );
                            $resultado_procesamiento[] = " 🔗 URL para episodio {$episodio_actual}: " . (strlen($url) > 50 ? substr($url, 0, 50) . '...' : $url);
                        }
                    }
                }
            }
            // Detectar Type de enlace
            elseif (preg_match('/^Type:\s*(.+)/i', $linea, $matches)) {
                $type = trim($matches[1]);
                if (!isValidType($type)) {
                    $error_msg = " ❌ Tipo de enlace inválido '{$type}' en línea " . ($numero_linea + 1);
                    $resultado_procesamiento[] = $error_msg;
                    $errores[] = $error_msg;
                } else {
                    if ($contenido_actual === 'movie') {
                        $item_actual['link_type'] = $type;
                        $resultado_procesamiento[] = " 📋 Tipo de enlace: {$type}";
                    } else if ($episodio_actual && isset($item_actual['episodios'][$episodio_actual])) {
                        // Buscar el último enlace que no tenga type asignado
                        $enlaces = &$item_actual['episodios'][$episodio_actual]['enlaces'];
                        if (!empty($enlaces)) {
                            // Buscar desde el final hacia el principio el primer enlace sin type
                            for ($i = count($enlaces) - 1; $i >= 0; $i--) {
                                if (empty($enlaces[$i]['type'])) {
                                    $enlaces[$i]['type'] = $type;
                                    $resultado_procesamiento[] = " 📋 Tipo de enlace para episodio {$episodio_actual}: {$type}";
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            // Detectar Idioma
            elseif (preg_match('/^Idioma:\s*(.+)/i', $linea, $matches)) {
                $idioma = trim($matches[1]);
                if (!isValidLanguage($idioma)) {
                    $error_msg = " ❌ Idioma inválido '{$idioma}' en línea " . ($numero_linea + 1);
                    $resultado_procesamiento[] = $error_msg;
                    $errores[] = $error_msg;
                } else {
                    if ($contenido_actual === 'movie') {
                        $item_actual['idioma'] = $idioma;
                        $resultado_procesamiento[] = " 🗣️ Idioma: {$idioma}";
                    } else if ($episodio_actual && isset($item_actual['episodios'][$episodio_actual])) {
                        // Buscar el último enlace que no tenga idioma asignado
                        $enlaces = &$item_actual['episodios'][$episodio_actual]['enlaces'];
                        if (!empty($enlaces)) {
                            // Buscar desde el final hacia el principio el primer enlace sin idioma
                            for ($i = count($enlaces) - 1; $i >= 0; $i--) {
                                if (empty($enlaces[$i]['idioma'])) {
                                    $enlaces[$i]['idioma'] = $idioma;
                                    $resultado_procesamiento[] = " 🗣️ Idioma para episodio {$episodio_actual}: {$idioma}";
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Agregar el último item si es válido
        if (!empty($item_actual) && isset($item_actual['tipo']) && isset($item_actual['tmdb_id'])) {
            $contenido_procesado[] = $item_actual;
        }

        // Guardar en sesión para posible guardado
        $_SESSION['contenido_procesado'] = $contenido_procesado;

        $resultado_procesamiento[] = "📊 Resumen: " . count($contenido_procesado) . " items procesados";
    }
}

// Guardar en GitHub
$guardar_post = isset($_POST['guardar']) ? $_POST['guardar'] : false;
if ($guardar_post) {
    $contenido_procesado = isset($_SESSION['contenido_procesado']) ? $_SESSION['contenido_procesado'] : array();

    if (!empty($contenido_procesado)) {
        $total_guardados = 0;
        $total_errores = 0;

        foreach ($contenido_procesado as $index => $item) {
            // Verificar que el item tenga todos los datos necesarios
            if (!isset($item['tipo']) || !isset($item['tmdb_id']) || !isset($item['titulo'])) {
                $resultado_procesamiento[] = "❌ Item {$index} incompleto, omitiendo...";
                $total_errores++;
                continue;
            }

            $resultado_procesamiento[] = "🚀 Procesando: {$item['titulo']}";

            // Fechas: created_at de TMDB, updated_at actual
            $fecha_creacion = isset($item['fecha_original']) ? formatTMDBDate($item['fecha_original']) : date('Y-m-d');
            $fecha_actualizacion = getCurrentTimestamp();

            $content_type = isset($item['content_type']) ? $item['content_type'] : ($item['tipo'] === 'movie' ? 'movie' : 'series');

            if ($item['tipo'] === 'movie') {
                // Preparar datos para home.json - asegurando id_tmdb como string
                $home_item = array(
                    'id_tmdb' => strval($item['tmdb_id']), // Convertir a string
                    'imagen' => isset($item['poster']) ? $item['poster'] : '',
                    'titulo' => $item['titulo'],
                    'etiquetas' => isset($item['etiquetas']) ? $item['etiquetas'] : '',
                    'type' => $content_type,
                    'created_at' => $fecha_creacion,
                    'updated_at' => $fecha_actualizacion,
                    'year' => isset($item['ano']) ? $item['ano'] : 'N/A',
                    'calidad' => 'HD'
                );

                // Preparar datos para details.json - asegurando id_tmdb como string
                $details_item = array(
                    'titulo' => $item['titulo'],
                    'imagen' => isset($item['poster']) ? $item['poster'] : '',
                    'etiquetas' => isset($item['etiquetas']) ? $item['etiquetas'] : '',
                    'year' => isset($item['ano']) ? $item['ano'] : 'N/A',
                    'created_at' => $fecha_creacion,
                    'updated_at' => $fecha_actualizacion,
                    'id_tmdb' => strval($item['tmdb_id']), // Convertir a string
                    'type' => $content_type,
                    'calidad' => 'HD',
                    'synopsis' => isset($item['sinopsis']) ? $item['sinopsis'] : '',
                    'enlaces' => array(array(
                        'nombre' => 'Enlace Principal',
                        'url' => isset($item['url']) ? $item['url'] : '',
                        'type' => isset($item['link_type']) ? $item['link_type'] : 'embed',
                        'language' => isset($item['idioma']) ? $item['idioma'] : 'Latino'
                    ))
                );
            } else {
                // Es tv (series/animes/doramas) - asegurando id_tmdb como string
                $home_item = array(
                    'id_tmdb' => strval($item['tmdb_id']), // Convertir a string
                    'imagen' => isset($item['poster']) ? $item['poster'] : '',
                    'titulo' => $item['titulo'],
                    'etiquetas' => isset($item['etiquetas']) ? $item['etiquetas'] : '',
                    'type' => $content_type,
                    'created_at' => $fecha_creacion,
                    'updated_at' => $fecha_actualizacion,
                    'year' => isset($item['ano']) ? $item['ano'] : 'N/A'
                );

                // Preparar temporadas para details.json
                $temporadas_details = array();
                $temporada_nombre = "Temporada " . (isset($item['temporada']) ? $item['temporada'] : 1);

                $capitulos_details = array();
                if (isset($item['episodios']) && is_array($item['episodios'])) {
                    foreach ($item['episodios'] as $episodio) {
                        $enlaces_episodio = array();
                        if (isset($episodio['enlaces']) && is_array($episodio['enlaces'])) {
                            $contador_enlace = 1;
                            foreach ($episodio['enlaces'] as $enlace) {
                                $enlaces_episodio[] = array(
                                    'nombre' => "Enlace " . $contador_enlace,
                                    'url' => isset($enlace['url']) ? $enlace['url'] : '',
                                    'type' => !empty($enlace['type']) ? $enlace['type'] : 'embed',
                                    'language' => !empty($enlace['idioma']) ? $enlace['idioma'] : 'Latino'
                                );
                                $contador_enlace++;
                            }
                        }

                        // Solo incluir nombre y portada, sin synopsis ni fecha_emision
                        $capitulos_details[] = array(
                            'episode_id' => str_pad(isset($episodio['numero']) ? $episodio['numero'] : 0, 2, '0', STR_PAD_LEFT),
                            'nombre' => isset($episodio['titulo']) ? $episodio['titulo'] : formatEpisodeName(isset($episodio['numero']) ? $episodio['numero'] : 0, "Episodio"),
                            'portada' => isset($episodio['imagen']) ? $episodio['imagen'] : '',
                            'enlaces' => $enlaces_episodio
                        );
                    }
                }

                $temporadas_details[] = array(
                    'temporada' => $temporada_nombre,
                    'capitulos' => $capitulos_details
                );

                $details_item = array(
                    'id_tmdb' => strval($item['tmdb_id']), // Convertir a string
                    'titulo' => $item['titulo'],
                    'imagen' => isset($item['poster']) ? $item['poster'] : '',
                    'etiquetas' => isset($item['etiquetas']) ? $item['etiquetas'] : '',
                    'year' => isset($item['ano']) ? $item['ano'] : 'N/A',
                    'type' => $content_type,
                    'synopsis' => isset($item['sinopsis']) ? $item['sinopsis'] : '',
                    'created_at' => $fecha_creacion,
                    'updated_at' => $fecha_actualizacion,
                    'temporadas' => $temporadas_details
                );
            }

            // Guardar en home.json
            $home_result = updateHomeJson($home_item, $github_config);
            if (isset($home_result['error'])) {
                $resultado_procesamiento[] = "❌ Error home.json: {$home_result['error']}";
                $total_errores++;
                continue;
            }

            // Guardar en details.json
            $details_result = updateDetailsJson($details_item, $github_config);
            if (isset($details_result['error'])) {
                $resultado_procesamiento[] = "❌ Error details.json: {$details_result['error']}";
                $total_errores++;
                continue;
            }

            $resultado_procesamiento[] = "✅ {$content_type} '{$item['titulo']}' guardada exitosamente";
            $total_guardados++;

            // Pequeña pausa para no saturar la API de GitHub
            sleep(1);
        }

        $resultado_procesamiento[] = "🎉 Proceso completado: {$total_guardados} guardados, {$total_errores} errores";

        // Limpiar sesión después de guardar
        unset($_SESSION['contenido_procesado']);
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subir Contenido Masivo - Panel de Administración</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Tus estilos CSS aquí (se mantienen igual) */
        :root {
            --primary-dark: #1a2639;
            --secondary-dark: #2e3b55;
            --accent-blue: #3b82f6;
            --accent-green: #10b981;
            --accent-red: #ef4444;
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
            color: white;
        }

        .navbar {
            background-color: var(--secondary-dark);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            padding: 1rem;
        }

        .navbar-brand {
            color: var(--text-light);
            font-weight: 600;
        }

        .content {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            margin-bottom: 20px;
            transition: transform 0.2s;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .card-header {
            background-color: var(--secondary-dark);
            border-bottom: 1px solid var(--border-light);
            padding: 15px 20px;
            border-radius: 12px 12px 0 0 !important;
        }

        .card-body {
            padding: 20px;
        }

        .form-control, .form-select {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-light);
            color: var(--text-light);
            border-radius: 8px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
            background-color: var(--secondary-dark);
            color: var(--text-light);
        }

        .btn-primary {
            background-color: var(--accent-blue);
            border-color: var(--accent-blue);
            padding: 10px 25px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            background-color: #2563eb;
            transform: translateY(-1px);
        }

        .btn-success {
            background-color: var(--accent-green);
            border-color: var(--accent-green);
            padding: 10px 25px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .btn-success:hover {
            background-color: #059669;
            transform: translateY(-1px);
        }

        .result-log {
            background-color: #1f2937;
            border: 1px solid #374151;
            border-radius: 8px;
            padding: 15px;
            max-height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .example-box {
            background-color: #1f2937;
            border: 1px dashed #4b5563;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
        }

        .example-code {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            color: #9ca3af;
            white-space: pre-wrap;
            line-height: 1.3;
        }

        .stat-card {
            text-align: center;
            padding: 15px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #9ca3af;
        }

        .badge-movie {
            background-color: var(--accent-blue);
        }

        .badge-series {
            background-color: var(--accent-green);
        }

        .enlace-item {
            background-color: #374151;
            border-radius: 6px;
            padding: 10px;
            margin: 5px 0;
            border-left: 4px solid var(--accent-blue);
        }

        .scroll-to-top {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--accent-blue);
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            transition: all 0.3s;
            z-index: 1000;
        }

        .scroll-to-top:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }

        .github-status {
            padding: 10px;
            border-radius: 6px;
            margin: 10px 0;
        }

        .github-success {
            background-color: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--accent-green);
            color: var(--accent-green);
        }

        .github-error {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--accent-red);
            color: var(--accent-red);
        }
    </style>
</head>
<body>
<!-- Navbar -->
<nav class="navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">
            <i class="fas fa-upload"></i> Subir Contenido Masivo
        </a>
        <div>
            <a href="subir_contenido.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
            <a href="#help" class="btn btn-outline-info btn-sm ms-2">
                <i class="fas fa-question-circle"></i> Ayuda
            </a>
        </div>
    </div>
</nav>

<!-- Content -->
<div class="content">
    <!-- Estadísticas rápidas -->
    <?php if (!empty($contenido_procesado)): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-number text-primary"><?php echo count($contenido_procesado); ?></div>
                    <div class="stat-label">Elementos Procesados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-number text-success">
                        <?php 
                        $peliculas = array_filter($contenido_procesado, function($item) { 
                            return isset($item['content_type']) && $item['content_type'] === 'movie'; 
                        });
                        echo count($peliculas);
                        ?>
                    </div>
                    <div class="stat-label">Películas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-number text-info">
                        <?php 
                        $series = array_filter($contenido_procesado, function($item) { 
                            return isset($item['content_type']) && in_array($item['content_type'], ['series', 'animes', 'dorama']); 
                        });
                        echo count($series);
                        ?>
                    </div>
                    <div class="stat-label">Series/Animes/Doramas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="stat-number <?php echo empty($errores) ? 'text-success' : 'text-warning'; ?>">
                        <?php echo count($errores); ?>
                    </div>
                    <div class="stat-label">Errores</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Formulario principal -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-bulk"></i> Carga Masiva de Contenido</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="masivoForm">
                        <div class="mb-3">
                            <label for="contenido_masivo" class="form-label">Contenido Masivo</label>
                            <textarea class="form-control" id="contenido_masivo" name="contenido_masivo" rows="18" 
                                      placeholder="Pega aquí el contenido en el formato especificado..." 
                                      style="font-family: 'Courier New', monospace; font-size: 0.9rem;"><?php echo htmlspecialchars(isset($_POST['contenido_masivo']) ? $_POST['contenido_masivo'] : ''); ?></textarea>
                            <div class="form-text">
                                <i class="fas fa-info-circle"></i> Ingresa el contenido en el formato especificado. El sistema detectará automáticamente series, películas, temporadas y episodios.
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" name="procesar" value="1" class="btn btn-primary">
                                <i class="fas fa-cogs"></i> Procesar Contenido
                            </button>

                            <?php if (!empty($contenido_procesado) && empty($errores)): ?>
                                <button type="submit" name="guardar" value="1" class="btn btn-success">
                                    <i class="fas fa-cloud-upload-alt"></i> Guardar en GitHub
                                </button>
                            <?php endif; ?>

                            <button type="button" class="btn btn-outline-info" onclick="limpiarFormulario()">
                                <i class="fas fa-broom"></i> Limpiar
                            </button>

                            <a href="subir_contenido.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancelar
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Resultados del procesamiento -->
            <?php if (!empty($resultado_procesamiento)): ?>
                <div class="card mt-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fas fa-list"></i> Resultado del Procesamiento</h6>
                        <span class="badge <?php echo empty($errores) ? 'bg-success' : 'bg-warning'; ?>">
                            <?php echo count($resultado_procesamiento); ?> líneas
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="result-log" id="resultLog">
                            <?php foreach ($resultado_procesamiento as $linea): ?>
                                <div class="<?php echo strpos($linea, '❌') !== false ? 'text-danger' : (strpos($linea, '✅') !== false ? 'text-success' : (strpos($linea, '🚀') !== false ? 'text-info' : (strpos($linea, '🎉') !== false ? 'text-success fw-bold' : ''))); ?>">
                                    <?php echo htmlspecialchars($linea); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($errores)): ?>
                            <div class="alert alert-danger mt-3">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Se encontraron <?php echo count($errores); ?> error(es).</strong> Corrígelos antes de guardar.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Panel lateral de información -->
        <div class="col-lg-4">
            <!-- Ejemplos de formato -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-info-circle"></i> Formatos Aceptados</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <h6>Para Series/Animes/Doramas:</h6>
                        <div class="example-box">
                            <div class="example-code">
[Temporada 1]
ID: 12345
Type: animes

Episodio 1
Url: https://ejemplo.com/serie/s1e1
Type: embed
Idioma: Latino

Episodio 2
Url: https://ejemplo.com/serie/s1e2
Type: mp4
Idioma: Sub
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <h6>Para Películas:</h6>
                        <div class="example-box">
                            <div class="example-code">
Movie
ID: 67890
Url: https://ejemplo.com/pelicula1
Type: mp4
Idioma: Latino

Movie
ID: 54321
Url: https://ejemplo.com/pelicula2
Type: embed
Idioma: Sub
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estado de GitHub -->
            <?php if (!empty($github_config)): ?>
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fab fa-github"></i> Estado de GitHub</h6>
                    </div>
                    <div class="card-body">
                        <div class="github-status github-success">
                            <i class="fas fa-check-circle"></i> Conectado a GitHub
                        </div>
                        <small class="text-muted">
                            Repositorio: <?php echo isset($github_config['repo_owner']) ? $github_config['repo_owner'] : 'N/A'; ?>/<?php echo isset($github_config['repo_name']) ? $github_config['repo_name'] : 'N/A'; ?><br>
                            Rama: <?php echo isset($github_config['branch']) ? $github_config['branch'] : 'N/A'; ?>
                        </small>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fab fa-github"></i> Estado de GitHub</h6>
                    </div>
                    <div class="card-body">
                        <div class="github-status github-error">
                            <i class="fas fa-exclamation-triangle"></i> GitHub no configurado
                        </div>
                        <small class="text-muted">
                            Configura GitHub primero para guardar el contenido.
                        </small>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Botón scroll to top -->
<button class="scroll-to-top" id="scrollToTop" onclick="scrollToTop()">
    <i class="fas fa-arrow-up"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Scroll to top
    function scrollToTop() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Mostrar/ocultar botón scroll to top
    window.addEventListener('scroll', function() {
        const scrollBtn = document.getElementById('scrollToTop');
        if (window.pageYOffset > 300) {
            scrollBtn.style.display = 'flex';
        } else {
            scrollBtn.style.display = 'none';
        }
    });

    // Auto-scroll al final del log de resultados
    document.addEventListener('DOMContentLoaded', function() {
        const resultLog = document.getElementById('resultLog');
        if (resultLog) {
            resultLog.scrollTop = resultLog.scrollHeight;
        }
    });

    // Limpiar formulario
    function limpiarFormulario() {
        if (confirm('¿Estás seguro de que quieres limpiar el formulario?')) {
            document.getElementById('contenido_masivo').value = '';
        }
    }

    // Confirmación antes de guardar en GitHub
    document.addEventListener('DOMContentLoaded', function() {
        const guardarBtn = document.querySelector('button[name="guardar"]');
        if (guardarBtn) {
            guardarBtn.addEventListener('click', function(e) {
                if (!confirm('¿Estás seguro de que quieres guardar todo el contenido procesado en GitHub? Esta acción modificará home.json y creará archivos details.')) {
                    e.preventDefault();
                }
            });
        }
    });

    // Auto-expand textarea
    document.getElementById('contenido_masivo').addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
</script>
</body>
</html>