<?php

$retry = 0;
$cacheTime = 1800;
$apiKey = '';

function getGeoApiUrl ($place) {
  global $apiKey;
  $encoded = urlencode($place);
  return "https://api.openweathermap.org/geo/1.0/direct?q=${encoded}&APPID=${apiKey}";
}

function getGeoDataFromCache ($cache) {
  if (is_array($cache) && count($cache) > 0) {
    return [
      'lat' => $cache[0]['lat'],
      'lon' => $cache[0]['lon']
    ];
  }
}

function getGeoData ($place) {
  $cacheFilename = "./geo/${place}";

  if (file_exists($cacheFilename)) {
    $cache = json_decode(file_get_contents($cacheFilename), true);
    return getGeoDataFromCache($cache);
  }

  $url = getGeoApiUrl($place);
  $data = requestWithRetry($url);
  file_put_contents($cacheFilename, json_encode($data));
  return getGeoDataFromCache($data);
}

function getApiUrl ($type, $place) {
  global $apiKey;
  $geo = getGeoData($place);
  $encoded = urlencode($place);

  if (isset($geo['lat']) && isset($geo['lon']))
    return "https://api.openweathermap.org/data/2.5/${type}?lat=${geo['lat']}&lon=${geo['lon']}&APPID=${apiKey}";

  return "https://api.openweathermap.org/data/2.5/${type}?q=${encoded}&APPID=${apiKey}";
}

function getHTTPStatusByHeader ($header) {
  return substr($header, 9, 3);
}

function getHTTPStatus ($url) {
  $context = stream_context_create(['http' => ['method' => 'HEAD']]);
  $headers = get_headers($url, false, $context);
  return getHTTPStatusByHeader($headers[0]);
}

function requestApi ($type, $place) {
  $url = getApiUrl($type, $place);
  $data = requestWithRetry($url, true);

  if ($data === false) {
    saveCache($place, null, 3600 * 24 * 365);
  }

  return $data;
}

function requestWithRetry ($url, $reinit = false) {
  global $retry;
  $reinit && ($retry = 0);
  $httpStatus = getHTTPStatus($url);

  if ($httpStatus === '200') {
    $data = file_get_contents($url);
    $httpStatus = getHTTPStatusByHeader($http_response_header[0]);

    if ($httpStatus === '200') {
      return json_decode($data, true);
    } else {
      $retry++;
      if ($retry < 3) return requestWithRetry($url);
    }
  } elseif ($httpStatus === '404') {
    return false;
  } else {
    $retry++;
    if ($retry < 3) return requestWithRetry($url);
  }
}

function weather ($place) {
  getGeoData($place);
  $ret = loadCache($place);

  if ($ret !== false) return $ret;

  unset($ret);
  $data = requestApi('forecast', $place);

  if ($data) {
    $now = requestApi('weather', $place);
    $ret = [formatWeatherData($now)];
    $ret[0]['date'] = formatDate(time(), $data['city']['timezone']);
    $ret[0]['place'] = $data['city']['name'];
    $ret[0]['timezone'] = $data['city']['timezone'];

    for ($i = 0; $i < count($data['list']); ++$i) {
      $item = formatWeatherData($data['list'][$i]);
      $item['date'] = formatDate($data['list'][$i]['dt'], $data['city']['timezone']);
      $item['day'] = dayOfWeek($data['list'][$i]['dt'], $data['city']['timezone']);
      $ret[] = $item;
    }

    saveCache($place, $ret);
    return $ret;
  }
}

function getCacheFile ($place) {
  return "./cache/$place";
}

function loadCache ($place) {
  global $cacheTime;

  if (file_exists(getCacheFile($place))) {
    $data = json_decode(file_get_contents(getCacheFile($place)), true);

    if ($data['ts'] && $data['ts'] + $cacheTime > time()) {
      return $data['data'];
    }

    return false;
  }

  return false;
}

function saveCache ($place, $data, $ttl = 0) {
  file_put_contents(getCacheFile($place), json_encode(['ts' => time() + $ttl, 'data' => $data]));
}

function formatDate ($timestamp, $timezone) {
  return date('Y-m-d H:i', $timestamp + $timezone);
}

function dayOfWeek ($timestamp, $timezone) {
  return date('N', $timestamp + $timezone);
}

function formatTemp ($temp) {
  return intval($temp - 273);
}

function getWindDirection ($deg) {
  if ($deg > 337.5 || $deg <= 22.5) {
    return 'N';
  } else if ($deg > 22.5 && $deg <= 67.5) {
    return 'NE';
  } else if ($deg > 67.5 && $deg <= 112.5) {
    return 'E';
  } else if ($deg > 112.5 && $deg <= 157.5) {
    return 'SE';
  } else if ($deg > 157.5 && $deg <= 202.5) {
    return 'S';
  } else if ($deg > 202.5 && $deg <= 247.5) {
    return 'SW';
  } else if ($deg > 247.5 && $deg <= 292.5) {
    return 'W';
  } else {
    return 'NW';
  }
}

function formatWeatherData ($data) {
  $pressure = isset($data['main']['grnd_level']) ? $data['main']['grnd_level']
              : (isset($data['main']['sea_level']) ? $data['main']['sea_level']
              : (isset($data['main']['pressure']) ? $data['main']['pressure'] : 0));
  $ret = [
    'temp' => formatTemp($data['main']['temp']),
    'description' => $data['weather'][0]['description'],
    'feels_like' => formatTemp($data['main']['feels_like']),
    'clouds' => $data['clouds']['all'],
    'humidity' => $data['main']['humidity'],
    'wind_speed' => round($data['wind']['speed'] * 10) / 10,
    'wind_direction' => getWindDirection($data['wind']['deg']),
    'pressure' => intval($pressure * 0.75006)
  ];
  isset($data['rain']) && ($ret['rain'] = $data['rain']['3h'] ? $data['rain']['3h'] / 3 : $data['rain']['1h']);
  isset($data['snow']) && ($ret['snow'] = $data['snow']['3h'] ? $data['snow']['3h'] / 3 : $data['snow']['1h']);

  return $ret;
}

if (isset($_GET['place'])) {
  $place = preg_replace('/[^A-z ]/', '', $_GET['place']);
  $place = strtolower($place);
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: *');
  header('Content-Type: application/json');
  die(json_encode(weather($place)));
}
