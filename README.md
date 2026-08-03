# Visitor Information Dashboard

A lightweight PHP visitor-information dashboard that displays and logs information available from a visitor's browser and public IP address.

The application combines:

- Server-side visitor IP detection
- Approximate IP geolocation through the ipstack API
- Browser, operating system, screen, language, and device information
- Optional browser geolocation after explicit user permission
- JSON Lines (`.jsonl`) visit logging
- One-hour IP lookup caching
- A responsive dark-mode interface

> [!IMPORTANT]
> IP geolocation is approximate. Precise browser geolocation is requested only after the visitor clicks the location button and grants permission.

## Features

### Network and IP information

The server reads the visitor IP from PHP's `REMOTE_ADDR` and retrieves available ipstack data, including:

- IP version
- Continent and country
- Region and city
- Postal code
- Approximate latitude and longitude
- Time zone
- Currency
- ISP
- ASN
- Carrier, when available

The project intentionally does not trust `X-Forwarded-For` because it is designed for a server that is not behind a trusted reverse proxy.

### Browser and device information

The page displays browser-supplied information such as:

- Browser name and version
- Operating system
- Device category
- Platform
- Preferred language and language list
- Browser time zone
- Screen and viewport dimensions
- Color depth
- Logical CPU count
- Approximate device memory, when supported
- Touch-point support
- Cookie status
- Do Not Track status
- Online status
- Raw user-agent string

Some fields are unavailable in certain browsers because browsers intentionally restrict fingerprinting-related information.

### Optional precise geolocation

A visitor may click **Share my location** to request browser geolocation.

When permission is granted, the dashboard can display and log:

- Latitude
- Longitude
- Accuracy radius
- Altitude
- Altitude accuracy
- Heading
- Speed
- Detection timestamp
- An OpenStreetMap link

Browser geolocation normally requires HTTPS. It cannot be collected legitimately without the visitor's browser permission.

### Logging

The application writes one JSON object per line to a `.jsonl` file. It supports two event types:

- `page_visit`
- `precise_location`

Example page-visit entry:

```json
{
  "event": "page_visit",
  "timestamp": "2026-08-04T00:00:00+00:00",
  "ip": "203.0.113.10",
  "request_uri": "/visitor/",
  "referrer": "",
  "http_user_agent": "Mozilla/5.0 ...",
  "browser": {
    "browser": "Firefox",
    "browserVersion": "140.0",
    "os": "Linux",
    "deviceType": "Desktop or laptop"
  },
  "geo": {
    "country_name": "Armenia",
    "city": "Yerevan",
    "timezone": "Asia/Yerevan",
    "isp": "Example ISP"
  }
}
```

Example precise-location entry:

```json
{
  "event": "precise_location",
  "timestamp": "2026-08-04T00:01:00+00:00",
  "ip": "203.0.113.10",
  "location": {
    "latitude": 40.1772,
    "longitude": 44.5035,
    "accuracy": 25,
    "altitude": null,
    "altitudeAccuracy": null,
    "heading": null,
    "speed": null,
    "timestamp": "2026-08-04T00:00:59.000Z"
  },
  "http_user_agent": "Mozilla/5.0 ..."
}
```

## Requirements

- PHP 8.0 or newer
- PHP cURL extension
- A PHP-capable web server such as Apache, Nginx with PHP-FPM, or PHP's built-in development server
- An ipstack access key
- HTTPS for browser geolocation
- Write permission for the configured log file

Check your PHP version:

```bash
php -v
```

Check whether cURL is enabled:

```bash
php -m | grep -i curl
```

On Debian or Ubuntu with Apache:

```bash
sudo apt update
sudo apt install php-curl
sudo systemctl restart apache2
```

## Recommended directory structure

The current `index.php` expects its private configuration two directory levels above the page directory:

```text
/home/username/
├── private/
│   ├── visitor-config.php
│   └── visitor-dashboard.jsonl
└── public_html/
    └── visitor/
        └── index.php
```

The application loads:

```php
$configFile = dirname(__DIR__, 2) . '/private/visitor-config.php';
```

For an `index.php` located at:

```text
/home/username/public_html/visitor/index.php
```

that resolves to:

```text
/home/username/private/visitor-config.php
```

## Installation

### 1. Clone or upload the project

```bash
git clone https://github.com/bx758/Information-Collector.git
```

Copy or place `index.php` in your public web directory, for example:

```text
public_html/visitor/index.php
```

### 2. Create the private configuration

Create:

```text
/home/username/private/visitor-config.php
```

Add:

```php
<?php

declare(strict_types=1);

return [
    'ipstack_key' => 'YOUR_IPSTACK_ACCESS_KEY',
    'log_file' => __DIR__ . '/visitor-dashboard.jsonl',
];
```

Do not commit this file to GitHub.

### 3. Create the log file

```bash
mkdir -p ~/private
touch ~/private/visitor-dashboard.jsonl
chmod 700 ~/private
chmod 600 ~/private/visitor-config.php
chmod 600 ~/private/visitor-dashboard.jsonl
```

On some shared-hosting platforms, PHP may require group-write permission:

```bash
chmod 660 ~/private/visitor-dashboard.jsonl
```

Avoid `chmod 777`.

### 4. Open the page

```text
https://your-domain.example/visitor/
```

### 5. Test PHP syntax

```bash
php -l public_html/visitor/index.php
```

Expected output:

```text
No syntax errors detected in public_html/visitor/index.php
```

## Local development

PHP must execute the file. Opening `index.php` directly in a browser or serving it with a static-file server will expose the PHP source instead of running it.

Do **not** use any of these for this project:

```bash
python3 -m http.server
```

- VS Code Live Server
- A file URL such as `file:///.../index.php`
- Any other server that only serves static files

### Option A: PHP built-in server

Install PHP and the cURL extension on Debian or Ubuntu:

```bash
sudo apt update
sudo apt install php php-curl
```

From the project directory, start the development server:

```bash
php -S 127.0.0.1:8000
```

Then open:

```text
http://127.0.0.1:8000/index.php
```

You can also use:

```text
http://localhost:8000/index.php
```

### Option B: Local Apache

Install Apache with PHP support:

```bash
sudo apt update
sudo apt install apache2 php libapache2-mod-php php-curl
sudo systemctl restart apache2
```

Copy the project into Apache's document root:

```bash
sudo mkdir -p /var/www/html/visitor
sudo cp index.php /var/www/html/visitor/index.php
```

Then open:

```text
http://localhost/visitor/
```

Confirm PHP execution with a minimal test:

```bash
echo '<?php echo "PHP works"; ?>' | sudo tee /var/www/html/php-test.php
```

Opening `http://localhost/php-test.php` should display only:

```text
PHP works
```

Delete the test afterward:

```bash
sudo rm /var/www/html/php-test.php
```

### Local configuration layout

The production code expects this configuration path:

```php
$configFile = dirname(__DIR__, 2) . '/private/visitor-config.php';
```

For a simple local test, you may temporarily change it to:

```php
$configFile = __DIR__ . '/visitor-config.php';
```

Then use this local structure:

```text
visitor-information-dashboard/
├── index.php
├── visitor-config.php
└── visitor-dashboard.jsonl
```

Create `visitor-config.php`:

```php
<?php

declare(strict_types=1);

return [
    'ipstack_key' => 'YOUR_IPSTACK_ACCESS_KEY',
    'log_file' => __DIR__ . '/visitor-dashboard.jsonl',
];
```

Create the local log file:

```bash
touch visitor-dashboard.jsonl
chmod 664 visitor-dashboard.jsonl
```

Do not commit the local config or log file. Do not use this public-directory config layout unchanged in production.

### Expected localhost IP behavior

On localhost, PHP normally detects one of these addresses:

```text
127.0.0.1
::1
192.168.x.x
10.x.x.x
```

These addresses are private or reserved, so ipstack cannot geolocate them. The following message is expected during local development:

```text
Location lookup is unavailable for a private or reserved IP.
```

This does not indicate a PHP error. The browser-information panel and permission-based browser geolocation can still work.

Browsers generally treat `http://localhost` and `http://127.0.0.1` as secure local-development contexts, so the location permission request may work locally even without HTTPS.

To test only the ipstack display with a public address, you may temporarily add this immediately after `$visitorIp = getVisitorIp();`:

```php
if ($visitorIp === '127.0.0.1' || $visitorIp === '::1') {
    $visitorIp = '94.154.43.220';
}
```

Remove this override before deployment. Otherwise, every local request will be logged as the test IP.

## Configuration alternatives

If your host does not permit files outside `public_html`, use a protected private directory:

```text
public_html/
├── private/
│   ├── .htaccess
│   ├── visitor-config.php
│   └── visitor-dashboard.jsonl
└── visitor/
    └── index.php
```

Protect `public_html/private/.htaccess` with:

```apache
Require all denied

<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
```

Then update the configuration path in `index.php`:

```php
$configFile = dirname(__DIR__) . '/private/visitor-config.php';
```

Confirm that direct access is blocked:

```bash
curl -I https://your-domain.example/private/visitor-config.php
```

The server should return `403 Forbidden`.

## Viewing logs

Watch new entries:

```bash
tail -f ~/private/visitor-dashboard.jsonl
```

Pretty-print the latest entry with `jq`:

```bash
tail -n 1 ~/private/visitor-dashboard.jsonl | jq
```

Show only page visits:

```bash
jq -c 'select(.event == "page_visit")' \
  ~/private/visitor-dashboard.jsonl
```

Show only precise-location events:

```bash
jq -c 'select(.event == "precise_location")' \
  ~/private/visitor-dashboard.jsonl
```

Count records by IP:

```bash
jq -r '.ip' ~/private/visitor-dashboard.jsonl \
  | sort \
  | uniq -c \
  | sort -nr
```

## IP lookup cache

ipstack results are cached for one hour in the PHP temporary directory:

```text
<system-temporary-directory>/visitor-dashboard-cache/
```

Each cache filename is a SHA-256 hash of the IP address. Caching reduces API requests when the same IP loads the page repeatedly.

## Security notes

- Keep the ipstack access key outside the public web directory whenever possible.
- Never commit `visitor-config.php`, logs, cache files, or real visitor data.
- Rotate any API key that has been published or shared publicly.
- Do not trust `X-Forwarded-For` unless the server is behind a known and trusted proxy.
- The application validates latitude and longitude before writing precise-location events.
- Input strings are length-limited before logging.
- Logs may contain personal data and must not be publicly downloadable.
- Add authentication before building any web-based log viewer.
- Use log rotation or scheduled cleanup to prevent unlimited disk usage.

The page sends these response headers:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`
- A restrictive Content Security Policy

## Privacy and legal responsibilities

Visitor IP addresses, browser details, and precise coordinates can be personal data. Before deploying this project publicly:

- Clearly disclose what information is collected.
- Explain why it is collected and how long it is retained.
- Request explicit permission before precise geolocation.
- Provide an appropriate privacy notice.
- Restrict access to logs.
- Delete information that is no longer needed.
- Follow the privacy and data-protection rules applicable to your visitors and hosting location.

This project does not bypass browser permissions and should not be modified to collect precise location covertly.

## SMS limitation

A normal website cannot read a phone's SMS inbox. Browser JavaScript does not have general access to personal SMS messages.

Some browsers support limited OTP-assisted APIs, such as WebOTP, for receiving a one-time verification code after user interaction and consent. That is not equivalent to reading SMS history, and it is not implemented in this project.

## Troubleshooting

### The browser displays PHP source code

If the browser shows text beginning with PHP statements such as `function`, `$configFile`, or `curl_init()`, PHP is not being executed. The file is being served as plain text.

Check the following:

1. The file is named `index.php`, not `index.html` or `index.php.txt`.
2. You are not using VS Code Live Server, `python3 -m http.server`, or a `file://` URL.
3. PHP is installed:

   ```bash
   php -v
   ```

4. Use PHP's development server:

   ```bash
   php -S 127.0.0.1:8000
   ```

5. When using Apache, install and enable PHP support, then restart Apache.

Serving raw PHP source is a security risk because it can expose application logic, file paths, and secrets. Stop the static server immediately and correct the PHP configuration.

### `Location lookup is unavailable for a private or reserved IP.`

This is expected on localhost and private networks. ipstack requires a public IP address and cannot geolocate `127.0.0.1`, `::1`, or private LAN addresses.

Deploy the page publicly to test the real visitor IP, or use the temporary local test-IP override described in the **Local development** section.

### `Server configuration is unavailable.`

PHP cannot read the configuration file.

Check:

- The path in `$configFile`
- File ownership
- Directory permissions
- PHP `open_basedir` restrictions on shared hosting

### `The ipstack access key is not configured.`

Make sure the config file returns a non-empty `ipstack_key` value.

### `Unable to write the visitor log.`

PHP cannot write to the configured log file.

Check the file path and permissions:

```bash
ls -la ~/private/visitor-dashboard.jsonl
```

### Location button says HTTPS is required

Open the site with an HTTPS URL. Browser geolocation is restricted to secure contexts, except for local development on `localhost`.

### Location permission was denied

The visitor denied the browser permission prompt. The site cannot override that decision. The visitor must change the site's permission in their browser settings before trying again.

### IP location is wrong

IP-based coordinates may point to:

- An ISP gateway
- A mobile carrier exit node
- A VPN endpoint
- A hosting provider
- A nearby city rather than the visitor's exact location

This is expected behavior for IP geolocation.

### ipstack request fails on HTTPS-only plans

The current project calls:

```text
http://api.ipstack.com/
```

Some ipstack plans or deployment requirements may support or require HTTPS. Update the API URL only when your plan supports it:

```php
'https://api.ipstack.com/%s?access_key=%s'
```

## Suggested `.gitignore`

```gitignore
# Private configuration and secrets
private/
visitor-config.php
.env
.env.*

# Visitor logs
*.log
*.jsonl

# Cache and temporary files
cache/
tmp/
*.tmp

# Editor and operating-system files
.vscode/
.idea/
.DS_Store
Thumbs.db
```

## Project structure for GitHub

```text
visitor-information-dashboard/
├── index.php
├── README.md
├── .gitignore
└── examples/
    └── visitor-config.example.php
```

Example `examples/visitor-config.example.php`:

```php
<?php

declare(strict_types=1);

return [
    'ipstack_key' => 'YOUR_IPSTACK_ACCESS_KEY',
    'log_file' => __DIR__ . '/../storage/visitor-dashboard.jsonl',
];
```

Do not place a real API key in the example file.

## Current limitations

- Browser and device detection is based partly on the user-agent string and may be inaccurate.
- Browser-provided values can be modified by the visitor and should not be treated as verified facts.
- IP information depends on ipstack availability and account limits.
- The JSONL file is not intended as a high-volume analytics database.
- The application has no administrator dashboard or log authentication.
- It does not deduplicate repeat visits.
- It does not read SMS messages, contacts, files, or other private phone data.

## Contributing

Issues and pull requests are welcome. Do not include real visitor logs, API keys, or personal information in bug reports.

Before submitting changes:

```bash
php -l index.php
```

Test both workflows:

1. Normal page visit logging
2. Permission-based precise-location logging

## Disclaimer

This project is provided for educational and legitimate analytics purposes. You are responsible for deploying it transparently, securing collected data, obtaining required consent, and complying with applicable laws and service terms.
