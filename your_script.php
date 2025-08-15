<?php

$csvFile = $argv[1] ?? null;
if (!file_exists($csvFile)) {
    throw new Exception('File doesn\' exists.');
}

$rejectsLog = fopen('rejects.log', 'w');
if (!$rejectsLog) {
    throw new Exception('Unable to write rejects log');
}

$csvFileObject = new SplFileObject($csvFile, 'r');
$csvFileObject->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
$csvFileObject->setCsvControl(',');

// Skip Header
$csvFileObject->fgetcsv();
$lineCounter = 1;

$devices = [];

while (!$csvFileObject->eof()) {
    $row = $csvFileObject->fgetcsv();

    $lineCounter++;

    $deviceId = $row[0] ?? null;
    $lat = $row[1] ?? null;
    $long = $row[2] ?? null;
    $timestamp = $row[3] ?? null;

    // Discard rows with invalid coordinates
    if (!is_numeric($lat) || !is_numeric($long) || (float) $lat < -90 || (float) $lat > 90 || (float) $long < -180 || (float) $long > 180) {
        // fwrite($rejectsLog, "Line $lineCounter: invalid lat/lon: {$lat},{$long}\n");
        continue;
    }

    // Discard rows with invalid timestamps
    $dateTimeObject = null;

    if (is_string($timestamp)) {
        try {
            $dateTimeObject = (new DateTimeImmutable($timestamp)) ?: null;
        } catch (Exception $e) {
        }
    }

    if ($dateTimeObject === null) {
        // fwrite($rejectsLog, "Line $lineCounter: invalid timestamp: {$timestamp}\n");
        continue;
    }

    $devices[$deviceId][] = [
        'lat' => (float) $lat,
        'long' => (float) $long,
        'timestamp' => $dateTimeObject
    ];
}

fclose($rejectsLog);

function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    // Radius of Earth in km
    $R = 6371.0088;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlambda = deg2rad($lon2 - $lon1);

    $a = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlambda / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

function colorForIndex(int $i): string
{
    // Generate HSL hues spaced around the circle, convert to HEX
    $h = ($i * 47) % 360; // pseudo-random but spaced
    $s = 0.70;  // 70%
    $l = 0.50;  // 50%
    return hslToHex($h, $s, $l);
}

function hslToHex(float $h, float $s, float $l): string
{
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;

    $r = $g = $b = 0.0;
    if ($h < 60) {
        $r = $c;
        $g = $x;
        $b = 0;
    } elseif ($h < 120) {
        $r = $x;
        $g = $c;
        $b = 0;
    } elseif ($h < 180) {
        $r = 0;
        $g = $c;
        $b = $x;
    } elseif ($h < 240) {
        $r = 0;
        $g = $x;
        $b = $c;
    } elseif ($h < 300) {
        $r = $x;
        $g = 0;
        $b = $c;
    } else {
        $r = $c;
        $g = 0;
        $b = $x;
    }

    $R = (int) round(($r + $m) * 255);
    $G = (int) round(($g + $m) * 255);
    $B = (int) round(($b + $m) * 255);

    return sprintf("#%02X%02X%02X", $R, $G, $B);
}

$trips = [];
$tripCounter = 1;

foreach ($devices as $deviceId => $points) {
    // Order by points
    usort($points, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

    $currentTrip = [];
    $prevPoint = null;

    foreach ($points as $point) {
        if ($prevPoint === null) {
            $currentTrip[] = $point;
            $prevPoint = $point;
            continue;
        }

        $gapInMinutes = ($point['timestamp']->getTimestamp() - $prevPoint['timestamp']->getTimestamp()) / 60;
        $distanceInKm = haversineKm($prevPoint['lat'], $prevPoint['long'], $point['lat'], $point['long']);

        if ($gapInMinutes > 20 || $distanceInKm > 2) {
            $trips[] = [
                'trip_id' => "trip_{$tripCounter}",
                'device_id' => $deviceId,
                'points' => $currentTrip,
                'timestamp' => $point['timestamp']
            ];
            $tripCounter++;
            $currentTrip = [$point];
        } else {
            $currentTrip[] = $point;
        }

        $prevPoint = $point;
    }

    if (count($currentTrip) > 0) {
        $trips[] = [
            'trip_id' => "trip_{$tripCounter}",
            'device_id' => $deviceId,
            'points' => $currentTrip,
            'timestamp' => $point['timestamp']
        ];
    }
}

$features = [];

foreach ($trips as $key => $trip) {
    $points = $trip['points'];

    $totalDistanceKm = 0.0;
    $maxSpeedKmh = 0.0;

    foreach ($points as $innerKey => $point) {
        if ($innerKey === 0) {
            continue;
        }

        $prevPoint = $points[$innerKey - 1];

        $distanceKm = haversineKm($prevPoint['lat'], $prevPoint['long'], $point['lat'], $point['long']);

        $totalDistanceKm += $distanceKm;

        $pointDurationHours = max(0.0, ($prevPoint['timestamp']->getTimestamp() - $point['timestamp']->getTimestamp()) / 3600.0);

        if ($pointDurationHours > 0) {
            $speed = $distanceKm / $pointDurationHours;

            if ($speed > $maxSpeedKmh) {
                $maxSpeedKmh = $speed;
            }
        }
    }

    $firstPoint = $points[0];
    $lastPoint = end($points);

    $durationHr = max(0.0, ($lastPoint['timestamp']->getTimestamp() - $lastPoint['timestamp']->getTimestamp()) / 3600.0);
    $durationMin = $durationHr * 60;

    $avgSpeedKmh = ($durationHr > 0.0) ? ($totalDistanceKm / $durationHr) : 0.0;

    $features[] = [
        'type' => 'Feature',
        'properties' => [
            'trip_id' => $trip['trip_id'],
            'device_id' => $trip['device_id'],
            'point_count' => count($points),
            'total_distance_km' => round($totalDistanceKm, 3),
            'duration_min' => round($durationMin, 2),
            'avg_speed_kmh' => round($avgSpeedKmh, 3),
            'max_speed_kmh' => round($maxSpeedKmh, 3),
            'stroke' => colorForIndex($key),
            'stroke-width' => 4,
            'stroke-opacity' => 0.9,
            'started_at' => $firstPoint['timestamp']->format(DateTimeInterface::ATOM),
            'ended_at' => $lastPoint['timestamp']->format(DateTimeInterface::ATOM),
        ],
        'geometry' => [
            'type' => 'LineString',
            'coordinates' => array_map(fn($point): array => [$point['long'], $point['lat']], $points),
        ],
    ];
}

$geojson = [
    'type' => 'FeatureCollection',
    'features' => $features
];

file_put_contents(
    'test.geojson',
    json_encode($geojson)
);
