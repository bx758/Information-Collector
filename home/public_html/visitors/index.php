<?php

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header(
    "Content-Security-Policy: default-src 'self'; " .
    "style-src 'self' 'unsafe-inline'; " .
    "script-src 'self' 'unsafe-inline'; " .
    "img-src 'self' data: https://assets.ipstack.com; " .
    "connect-src 'self';"
);

$configFile = dirname(__DIR__, 2) . '/private/visitor-config.php';

if (!is_readable($configFile)) {
    http_response_code(500);
    exit('Server configuration is unavailable.');
}

$config = require $configFile;

$ipstackKey = (string) ($config['ipstack_key'] ?? '');
$logFile = (string) (
    $config['log_file'] ??
    '/var/log/apache2/visitor-dashboard.jsonl'
);

if ($ipstackKey === '') {
    http_response_code(500);
    exit('The ipstack access key is not configured.');
}

/**
 * Return the IP connected directly to Apache.
 *
 * Since the server is not behind a trusted reverse proxy,
 * do not trust X-Forwarded-For or similar headers.
 */
function getVisitorIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return 'unknown';
    }

    return $ip;
}

/**
 * Check whether the IP is public.
 */
function isPublicIp(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * Retrieve IP information from ipstack.
 *
 * Results are cached for one hour to reduce API requests.
 */
function getIpInformation(string $ip, string $accessKey): array
{
    if (!isPublicIp($ip)) {
        return [
            '_error' => 'Location lookup is unavailable for a private or reserved IP.',
        ];
    }

    $cacheDirectory = sys_get_temp_dir() . '/visitor-dashboard-cache';

    if (
        !is_dir($cacheDirectory) &&
        !mkdir($cacheDirectory, 0700, true) &&
        !is_dir($cacheDirectory)
    ) {
        return [
            '_error' => 'Unable to create the IP lookup cache.',
        ];
    }

    $cacheFile = $cacheDirectory . '/' . hash('sha256', $ip) . '.json';
    $cacheLifetime = 3600;

    if (
        is_file($cacheFile) &&
        filemtime($cacheFile) !== false &&
        filemtime($cacheFile) > time() - $cacheLifetime
    ) {
        $cachedContent = file_get_contents($cacheFile);

        if ($cachedContent !== false) {
            $cachedData = json_decode($cachedContent, true);

            if (is_array($cachedData)) {
                return $cachedData;
            }
        }
    }

    $url = sprintf(
        'http://api.ipstack.com/%s?access_key=%s',
        rawurlencode($ip),
        rawurlencode($accessKey)
    );

    $curl = curl_init($url);

    if ($curl === false) {
        return [
            '_error' => 'Unable to initialize the IP lookup.',
        ];
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'VisitorDashboard/1.0',
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($response === false) {
        return [
            '_error' => 'IP lookup request failed: ' . $curlError,
        ];
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return [
            '_error' => 'IP lookup returned HTTP status ' . $httpStatus . '.',
        ];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return [
            '_error' => 'The IP lookup returned invalid JSON.',
        ];
    }

    if (($data['success'] ?? true) === false) {
        $message = $data['error']['info'] ?? 'The IP lookup failed.';

        return [
            '_error' => (string) $message,
        ];
    }

    file_put_contents(
        $cacheFile,
        json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ),
        LOCK_EX
    );

    return $data;
}

/**
 * Limit data received from JavaScript before logging it.
 */
function cleanBrowserData(mixed $input): array
{
    if (!is_array($input)) {
        return [];
    }

    $allowedFields = [
        'browser',
        'browserVersion',
        'os',
        'deviceType',
        'platform',
        'language',
        'languages',
        'timezone',
        'screen',
        'viewport',
        'colorDepth',
        'cpuCores',
        'deviceMemory',
        'touchSupport',
        'cookiesEnabled',
        'doNotTrack',
        'online',
        'userAgent',
    ];

    $clean = [];

    foreach ($allowedFields as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }

        $value = $input[$field];

        if (is_bool($value) || is_int($value) || is_float($value)) {
            $clean[$field] = $value;
            continue;
        }

        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }

        if (is_scalar($value)) {
            $clean[$field] = substr((string) $value, 0, 1000);
        }
    }

    return $clean;
}

/**
 * Keep the most useful geolocation fields in the log.
 */
function createGeoLogData(array $geo): array
{
    if (isset($geo['_error'])) {
        return [
            'error' => $geo['_error'],
        ];
    }

    return [
        'continent_code' => $geo['continent_code'] ?? null,
        'continent_name' => $geo['continent_name'] ?? null,
        'country_code' => $geo['country_code'] ?? null,
        'country_name' => $geo['country_name'] ?? null,
        'region_code' => $geo['region_code'] ?? null,
        'region_name' => $geo['region_name'] ?? null,
        'city' => $geo['city'] ?? null,
        'zip' => $geo['zip'] ?? null,
        'latitude' => $geo['latitude'] ?? null,
        'longitude' => $geo['longitude'] ?? null,
        'timezone' => $geo['time_zone']['id'] ?? null,
        'currency' => $geo['currency']['code'] ?? null,
        'asn' => $geo['connection']['asn'] ?? null,
        'isp' => $geo['connection']['isp'] ?? null,
    ];
}

/**
 * Validate precise coordinates submitted by the browser.
 *
 * Browser geolocation is only available after the visitor grants permission.
 */
function cleanPreciseLocation(mixed $input): array
{
    if (!is_array($input)) {
        return [];
    }

    $clean = [];
    $numericFields = [
        'latitude',
        'longitude',
        'accuracy',
        'altitude',
        'altitudeAccuracy',
        'heading',
        'speed',
    ];

    foreach ($numericFields as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }

        $value = $input[$field];

        if ($value === null) {
            $clean[$field] = null;
            continue;
        }

        if (is_numeric($value)) {
            $clean[$field] = (float) $value;
        }
    }

    if (
        !isset($clean['latitude']) ||
        $clean['latitude'] < -90 ||
        $clean['latitude'] > 90
    ) {
        unset($clean['latitude']);
    }

    if (
        !isset($clean['longitude']) ||
        $clean['longitude'] < -180 ||
        $clean['longitude'] > 180
    ) {
        unset($clean['longitude']);
    }

    if (
        isset($clean['accuracy']) &&
        $clean['accuracy'] < 0
    ) {
        unset($clean['accuracy']);
    }

    if (
        isset($clean['heading']) &&
        ($clean['heading'] < 0 || $clean['heading'] > 360)
    ) {
        unset($clean['heading']);
    }

    if (
        isset($clean['speed']) &&
        $clean['speed'] < 0
    ) {
        unset($clean['speed']);
    }

    $timestamp = $input['timestamp'] ?? null;

    if (is_string($timestamp)) {
        $clean['timestamp'] = substr($timestamp, 0, 100);
    }

    return $clean;
}

$visitorIp = getVisitorIp();

/*
 * Handle browser-information and precise-location logging requests.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $rawBody = file_get_contents('php://input');
    $requestData = json_decode($rawBody ?: '', true);

    if (!is_array($requestData)) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON request.',
        ]);

        exit;
    }

    $action = $requestData['action'] ?? '';

    if ($action === 'log') {
        /*
         * Normal page visit: save browser details and IP-based location.
         */
        $geoInformation = getIpInformation($visitorIp, $ipstackKey);

        $logEntry = [
            'event' => 'page_visit',
            'timestamp' => gmdate('c'),
            'ip' => $visitorIp,
            'request_uri' => substr(
                (string) ($_SERVER['REQUEST_URI'] ?? ''),
                0,
                2000
            ),
            'referrer' => substr(
                (string) ($_SERVER['HTTP_REFERER'] ?? ''),
                0,
                2000
            ),
            'http_user_agent' => substr(
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0,
                2000
            ),
            'browser' => cleanBrowserData(
                $requestData['browser'] ?? []
            ),
            'geo' => createGeoLogData($geoInformation),
        ];
    } elseif ($action === 'log_location') {
        /*
         * Precise browser location: only sent after permission is granted.
         */
        $location = cleanPreciseLocation(
            $requestData['location'] ?? []
        );

        if (
            !isset($location['latitude']) ||
            !isset($location['longitude'])
        ) {
            http_response_code(400);

            echo json_encode([
                'success' => false,
                'error' => 'Invalid location coordinates.',
            ]);

            exit;
        }

        $logEntry = [
            'event' => 'precise_location',
            'timestamp' => gmdate('c'),
            'ip' => $visitorIp,
            'location' => $location,
            'http_user_agent' => substr(
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0,
                2000
            ),
        ];
    } else {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'error' => 'Unknown action.',
        ]);

        exit;
    }

    $encodedEntry = json_encode(
        $logEntry,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if (
        $encodedEntry === false ||
        file_put_contents(
            $logFile,
            $encodedEntry . PHP_EOL,
            FILE_APPEND | LOCK_EX
        ) === false
    ) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'error' => 'Unable to write the visitor log.',
        ]);

        exit;
    }

    echo json_encode([
        'success' => true,
    ]);

    exit;
}

$geoInformation = getIpInformation($visitorIp, $ipstackKey);

$serverData = [
    'ip' => $visitorIp,
    'geo' => $geoInformation,
];

$safeServerJson = json_encode(
    $serverData,
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Visitor Information</title>

    <style>
        :root {
            color-scheme: dark;
            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            color: #e8ecf5;
            background:
                radial-gradient(
                    circle at top left,
                    rgba(72, 89, 255, 0.22),
                    transparent 35%
                ),
                radial-gradient(
                    circle at bottom right,
                    rgba(0, 210, 190, 0.16),
                    transparent 30%
                ),
                #090c16;
        }

        .page {
            width: min(1200px, calc(100% - 32px));
            margin: 0 auto;
            padding: 48px 0;
        }

        .hero {
            margin-bottom: 28px;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            color: #b9c4ff;
            font-size: 13px;
            font-weight: 700;
            background: rgba(93, 111, 255, 0.12);
            border: 1px solid rgba(124, 138, 255, 0.25);
            border-radius: 999px;
        }

        .hero h1 {
            margin: 18px 0 8px;
            font-size: clamp(32px, 6vw, 60px);
            line-height: 1;
            letter-spacing: -0.04em;
        }

        .hero p {
            max-width: 760px;
            margin: 0;
            color: #a8b0c3;
            font-size: 16px;
            line-height: 1.7;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .card {
            overflow: hidden;
            background: rgba(18, 23, 40, 0.78);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
            backdrop-filter: blur(18px);
        }

        .card.full-width {
            grid-column: 1 / -1;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 20px 22px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
        }

        .card-title {
            margin: 0;
            font-size: 17px;
        }

        .flag {
            font-size: 28px;
        }

        .rows {
            padding: 8px 22px;
        }

        .row {
            display: grid;
            grid-template-columns: minmax(130px, 0.65fr) minmax(0, 1.35fr);
            gap: 18px;
            padding: 14px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .row:last-child {
            border-bottom: none;
        }

        .label {
            color: #8992a8;
            font-size: 14px;
        }

        .value {
            min-width: 0;
            color: #f3f5fa;
            font-size: 14px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #8fe6ca;
            font-size: 13px;
            font-weight: 700;
        }

        .status::before {
            width: 8px;
            height: 8px;
            content: "";
            background: #42d6a4;
            border-radius: 50%;
            box-shadow: 0 0 15px #42d6a4;
        }

        .error {
            margin: 18px 22px;
            padding: 14px 16px;
            color: #ffc6c6;
            background: rgba(255, 84, 84, 0.1);
            border: 1px solid rgba(255, 112, 112, 0.25);
            border-radius: 12px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 24px;
        }

        button {
            padding: 11px 17px;
            color: #ffffff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            background: #5367ff;
            border: 0;
            border-radius: 11px;
        }

        button:hover {
            background: #6879ff;
        }

        button:disabled {
            cursor: not-allowed;
            opacity: 0.65;
        }

        a {
            color: #9facff;
        }

        .card > .notice {
            margin: 18px 22px 0;
        }

        .notice {
            margin-top: 24px;
            padding: 16px 18px;
            color: #9fa8bc;
            font-size: 13px;
            line-height: 1.7;
            background: rgba(255, 255, 255, 0.035);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
        }

        @media (max-width: 760px) {
            .page {
                width: min(100% - 22px, 1200px);
                padding: 30px 0;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .card.full-width {
                grid-column: auto;
            }

            .row {
                grid-template-columns: 1fr;
                gap: 6px;
            }
        }
    </style>
</head>

<body>
    <main class="page">
        <section class="hero">
            <div class="hero-badge">Live visitor details</div>

            <h1>Your connection information</h1>

            <p>
                This page displays information supplied by your browser
                and approximate information associated with your public IP
                address.
            </p>
        </section>

        <section class="grid">
            <article class="card full-width">
                <div class="card-header">
                    <h2 class="card-title">Precise browser location</h2>

                    <button type="button" id="locationButton">
                        Share my location
                    </button>
                </div>

                <div id="locationMessage" class="notice">
                    Your browser will ask for permission. If approved, the
                    coordinates will be displayed here and saved in the visitor log.
                </div>

                <div class="rows" id="locationRows"></div>
            </article>

            <article class="card">
                <div class="card-header">
                    <h2 class="card-title">Network and location</h2>
                    <span class="flag" id="countryFlag"></span>
                </div>

                <div id="geoError" hidden></div>
                <div class="rows" id="networkRows"></div>
            </article>

            <article class="card">
                <div class="card-header">
                    <h2 class="card-title">Browser and device</h2>
                    <span class="status">Detected</span>
                </div>

                <div class="rows" id="browserRows"></div>
            </article>

            <article class="card full-width">
                <div class="card-header">
                    <h2 class="card-title">Raw user agent</h2>
                </div>

                <div class="rows" id="userAgentRows"></div>
            </article>
        </section>

        <div class="actions">
            <button type="button" id="copyButton">
                Copy information as JSON
            </button>
        </div>

        <div class="notice">
            IP-based location is approximate and may represent a VPN,
            mobile carrier gateway, hosting provider, or ISP exit point.
            It should not be treated as a visitor's exact physical address.
        </div>
    </main>

    <script>
        const serverData = <?= $safeServerJson ?: '{}' ?>;

        function detectBrowser(userAgent) {
            const patterns = [
                {
                    name: 'Microsoft Edge',
                    regex: /Edg\/([0-9.]+)/,
                },
                {
                    name: 'Opera',
                    regex: /OPR\/([0-9.]+)/,
                },
                {
                    name: 'Firefox',
                    regex: /Firefox\/([0-9.]+)/,
                },
                {
                    name: 'Chrome',
                    regex: /Chrome\/([0-9.]+)/,
                },
                {
                    name: 'Safari',
                    regex: /Version\/([0-9.]+).*Safari/,
                },
            ];

            for (const pattern of patterns) {
                const match = userAgent.match(pattern.regex);

                if (match) {
                    return {
                        name: pattern.name,
                        version: match[1] ?? 'Unknown',
                    };
                }
            }

            return {
                name: 'Unknown',
                version: 'Unknown',
            };
        }

        function detectOperatingSystem(userAgent) {
            if (/Windows NT 10\.0/.test(userAgent)) {
                return 'Windows 10 or Windows 11';
            }

            if (/Windows NT 6\.3/.test(userAgent)) {
                return 'Windows 8.1';
            }

            if (/Windows NT 6\.1/.test(userAgent)) {
                return 'Windows 7';
            }

            if (/Android/.test(userAgent)) {
                const match = userAgent.match(/Android\s([0-9.]+)/);

                return match ? `Android ${match[1]}` : 'Android';
            }

            if (/iPhone|iPad|iPod/.test(userAgent)) {
                const match = userAgent.match(/OS\s([0-9_]+)/);

                return match
                    ? `iOS ${match[1].replaceAll('_', '.')}`
                    : 'iOS';
            }

            if (/Mac OS X/.test(userAgent)) {
                const match = userAgent.match(/Mac OS X\s([0-9_]+)/);

                return match
                    ? `macOS ${match[1].replaceAll('_', '.')}`
                    : 'macOS';
            }

            if (/Linux/.test(userAgent)) {
                return 'Linux';
            }

            return 'Unknown';
        }

        function detectDeviceType(userAgent) {
            if (/Tablet|iPad/i.test(userAgent)) {
                return 'Tablet';
            }

            if (/Mobile|Android|iPhone|iPod/i.test(userAgent)) {
                return 'Mobile';
            }

            return 'Desktop or laptop';
        }

        function formatBoolean(value) {
            if (value === true) {
                return 'Yes';
            }

            if (value === false) {
                return 'No';
            }

            return 'Unavailable';
        }

        function displayValue(value) {
            if (
                value === null ||
                value === undefined ||
                value === ''
            ) {
                return 'Unavailable';
            }

            return String(value);
        }

        function addRow(container, label, value) {
            const row = document.createElement('div');
            row.className = 'row';

            const labelElement = document.createElement('div');
            labelElement.className = 'label';
            labelElement.textContent = label;

            const valueElement = document.createElement('div');
            valueElement.className = 'value';
            valueElement.textContent = displayValue(value);

            row.append(labelElement, valueElement);
            container.appendChild(row);
        }

        const userAgent = navigator.userAgent;
        const detectedBrowser = detectBrowser(userAgent);
        const geo = serverData.geo ?? {};

        const browserInformation = {
            browser: detectedBrowser.name,
            browserVersion: detectedBrowser.version,
            os: detectOperatingSystem(userAgent),
            deviceType: detectDeviceType(userAgent),
            platform:
                navigator.userAgentData?.platform ||
                navigator.platform ||
                'Unavailable',
            language: navigator.language || 'Unavailable',
            languages: navigator.languages || [],
            timezone:
                Intl.DateTimeFormat().resolvedOptions().timeZone ||
                'Unavailable',
            screen: `${screen.width} × ${screen.height}`,
            viewport: `${window.innerWidth} × ${window.innerHeight}`,
            colorDepth: `${screen.colorDepth}-bit`,
            cpuCores: navigator.hardwareConcurrency || 'Unavailable',
            deviceMemory: navigator.deviceMemory
                ? `${navigator.deviceMemory} GB`
                : 'Unavailable',
            touchSupport:
                navigator.maxTouchPoints > 0
                    ? `${navigator.maxTouchPoints} touch point(s)`
                    : 'No',
            cookiesEnabled: navigator.cookieEnabled,
            doNotTrack:
                navigator.doNotTrack === '1'
                    ? 'Enabled'
                    : navigator.doNotTrack === '0'
                        ? 'Disabled'
                        : 'Unspecified',
            online: navigator.onLine,
            userAgent,
        };

        const networkRows = document.getElementById('networkRows');
        const browserRows = document.getElementById('browserRows');
        const userAgentRows = document.getElementById('userAgentRows');
        const geoError = document.getElementById('geoError');
        const countryFlag = document.getElementById('countryFlag');

        if (geo._error) {
            geoError.hidden = false;
            geoError.className = 'error';
            geoError.textContent = geo._error;
        }

        countryFlag.textContent =
            geo.location?.country_flag_emoji || '';

        addRow(networkRows, 'IP address', serverData.ip);
        addRow(networkRows, 'IP type', geo.type);
        addRow(
            networkRows,
            'Continent',
            geo.continent_name && geo.continent_code
                ? `${geo.continent_name} (${geo.continent_code})`
                : geo.continent_name
        );
        addRow(
            networkRows,
            'Country',
            geo.country_name && geo.country_code
                ? `${geo.country_name} (${geo.country_code})`
                : geo.country_name
        );
        addRow(networkRows, 'Region', geo.region_name);
        addRow(networkRows, 'City', geo.city);
        addRow(networkRows, 'Postal code', geo.zip);

        addRow(
            networkRows,
            'Coordinates',
            geo.latitude !== undefined && geo.longitude !== undefined
                ? `${geo.latitude}, ${geo.longitude}`
                : null
        );

        addRow(networkRows, 'Timezone', geo.time_zone?.id);
        addRow(networkRows, 'Local IP time', geo.time_zone?.current_time);

        addRow(
            networkRows,
            'Currency',
            geo.currency?.code
                ? `${geo.currency.code} — ${geo.currency.name || ''}`
                : null
        );

        addRow(networkRows, 'ISP', geo.connection?.isp);
        addRow(networkRows, 'ASN', geo.connection?.asn);
        addRow(networkRows, 'Carrier', geo.connection?.carrier);

        addRow(
            browserRows,
            'Browser',
            `${browserInformation.browser} ${browserInformation.browserVersion}`
        );
        addRow(browserRows, 'Operating system', browserInformation.os);
        addRow(browserRows, 'Device type', browserInformation.deviceType);
        addRow(browserRows, 'Platform', browserInformation.platform);
        addRow(browserRows, 'Language', browserInformation.language);
        addRow(
            browserRows,
            'Languages',
            browserInformation.languages.join(', ')
        );
        addRow(browserRows, 'Browser timezone', browserInformation.timezone);
        addRow(browserRows, 'Screen size', browserInformation.screen);
        addRow(browserRows, 'Viewport size', browserInformation.viewport);
        addRow(browserRows, 'Color depth', browserInformation.colorDepth);
        addRow(browserRows, 'CPU cores', browserInformation.cpuCores);
        addRow(browserRows, 'Device memory', browserInformation.deviceMemory);
        addRow(browserRows, 'Touch support', browserInformation.touchSupport);
        addRow(
            browserRows,
            'Cookies enabled',
            formatBoolean(browserInformation.cookiesEnabled)
        );
        addRow(browserRows, 'Do Not Track', browserInformation.doNotTrack);
        addRow(
            browserRows,
            'Browser online',
            formatBoolean(browserInformation.online)
        );

        addRow(userAgentRows, 'User agent', browserInformation.userAgent);

        const completeInformation = {
            ip: serverData.ip,
            geo,
            browser: browserInformation,
        };

        document
            .getElementById('copyButton')
            .addEventListener('click', async () => {
                const button = document.getElementById('copyButton');

                try {
                    await navigator.clipboard.writeText(
                        JSON.stringify(completeInformation, null, 2)
                    );

                    button.textContent = 'Copied';

                    setTimeout(() => {
                        button.textContent = 'Copy information as JSON';
                    }, 1500);
                } catch {
                    button.textContent = 'Copy failed';
                }
            });

        /*
         * Send browser details back to the same PHP file for logging.
         */
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'log',
                browser: browserInformation,
            }),
            keepalive: true,
        }).catch(() => {
            // Logging failure should not break the page.
        });
        
        const locationButton = document.getElementById('locationButton');
        const locationRows = document.getElementById('locationRows');
        const locationMessage = document.getElementById('locationMessage');

        let preciseLocation = null;

        function clearLocationRows() {
            locationRows.replaceChildren();
        }

        function showLocationError(message) {
            locationMessage.textContent = message;
            locationMessage.hidden = false;
        }

        function requestPreciseLocation() {
            if (!window.isSecureContext) {
                showLocationError(
                    'Browser geolocation requires HTTPS.'
                );

                return;
            }

            if (!navigator.geolocation) {
                showLocationError(
                    'Geolocation is not supported by this browser.'
                );

                return;
            }

            locationButton.disabled = true;
            locationButton.textContent = 'Detecting location...';

            navigator.geolocation.getCurrentPosition(
                async (position) => {
                    const coordinates = position.coords;

                    preciseLocation = {
                        latitude: coordinates.latitude,
                        longitude: coordinates.longitude,
                        accuracy: coordinates.accuracy,
                        altitude: coordinates.altitude,
                        altitudeAccuracy: coordinates.altitudeAccuracy,
                        heading: coordinates.heading,
                        speed: coordinates.speed,
                        timestamp: new Date(
                            position.timestamp
                        ).toISOString(),
                    };

                    completeInformation.preciseLocation =
                        preciseLocation;

                    clearLocationRows();

                    addRow(
                        locationRows,
                        'Latitude',
                        preciseLocation.latitude
                    );

                    addRow(
                        locationRows,
                        'Longitude',
                        preciseLocation.longitude
                    );

                    addRow(
                        locationRows,
                        'Accuracy',
                        `${Math.round(preciseLocation.accuracy)} meters`
                    );

                    addRow(
                        locationRows,
                        'Altitude',
                        preciseLocation.altitude !== null
                            ? `${preciseLocation.altitude} meters`
                            : 'Unavailable'
                    );

                    addRow(
                        locationRows,
                        'Altitude accuracy',
                        preciseLocation.altitudeAccuracy !== null
                            ? `${preciseLocation.altitudeAccuracy} meters`
                            : 'Unavailable'
                    );

                    addRow(
                        locationRows,
                        'Heading',
                        preciseLocation.heading !== null
                            ? `${preciseLocation.heading}°`
                            : 'Unavailable'
                    );

                    addRow(
                        locationRows,
                        'Speed',
                        preciseLocation.speed !== null
                            ? `${preciseLocation.speed} m/s`
                            : 'Unavailable'
                    );

                    addRow(
                        locationRows,
                        'Detected at',
                        preciseLocation.timestamp
                    );

                    const mapRow = document.createElement('div');
                    mapRow.className = 'row';

                    const mapLabel = document.createElement('div');
                    mapLabel.className = 'label';
                    mapLabel.textContent = 'Map';

                    const mapValue = document.createElement('div');
                    mapValue.className = 'value';

                    const mapLink = document.createElement('a');

                    mapLink.href =
                        `https://www.openstreetmap.org/` +
                        `?mlat=${encodeURIComponent(
                            preciseLocation.latitude
                        )}` +
                        `&mlon=${encodeURIComponent(
                            preciseLocation.longitude
                        )}` +
                        `#map=16/${encodeURIComponent(
                            preciseLocation.latitude
                        )}` +
                        `/${encodeURIComponent(
                            preciseLocation.longitude
                        )}`;

                    mapLink.target = '_blank';
                    mapLink.rel = 'noopener noreferrer';
                    mapLink.textContent = 'Open location on map';

                    mapValue.appendChild(mapLink);
                    mapRow.append(mapLabel, mapValue);
                    locationRows.appendChild(mapRow);

                    const wasSaved = await logPreciseLocation();

                    locationMessage.textContent = wasSaved
                        ? 'Location permission granted and coordinates saved.'
                        : 'Location displayed, but the server could not save it.';

                    locationButton.textContent = 'Location detected';
                },
                (error) => {
                    const errorMessages = {
                        1: 'Location permission was denied.',
                        2: 'Your current location is unavailable.',
                        3: 'The location request timed out.',
                    };

                    showLocationError(
                        errorMessages[error.code] ||
                        'Unable to retrieve your location.'
                    );

                    locationButton.disabled = false;
                    locationButton.textContent = 'Try again';
                },
                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 60000,
                }
            );
        }

        async function logPreciseLocation() {
            if (!preciseLocation) {
                return false;
            }

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'log_location',
                        location: preciseLocation,
                    }),
                    keepalive: true,
                });

                if (!response.ok) {
                    return false;
                }

                const result = await response.json();

                return result.success === true;
            } catch {
                return false;
            }
        }

        locationButton.addEventListener(
            'click',
            requestPreciseLocation
        );
    </script>
</body>
</html>