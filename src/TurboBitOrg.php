<?php
/* TurboBitOrg 1.0.6. Original module: Mathieu Vedie, 2025.
 * Synology Download Station host module; PHP 5.6+ syntax, cURL required; DOM used by the legacy HTML fallback.
 * No credentials or session cookies are embedded in this file.
 */
class TurboBitOrgException extends Exception {}

class SynoFileHostingTurboBit {
    const LOG_DIR = '/tmp/turbobit_dot_org';
    const WEB = 'https://turbobit.net';
    const API = 'https://app.turbobit.net/api';
    private $url = '';
    private $id = '';
    private $username;
    private $password;
    private $logging = false;
    private $curl = null;
    private $sessionDir = null;
    private $cookieFile = null;
    private $handedOff = false;
    private $account = null;
    private $mode = null;
    private $filename = null;
    private $filePage = '';
    private $shortDomain = '';
    protected $fileSize = null;
    private $runId;
    private $logsMaintained = false;

    public function __construct($Url, $Username, $Password, $HostInfo) {
        $parts = explode(';', (string)$Username);
        $this->username = array_shift($parts);
        $this->password = (string)$Password;
        foreach ($parts as $option) {
            if (trim($option) === 'local_log=1') $this->logging = true;
        }
        $this->runId = substr(hash('sha256', uniqid('', true)), 0, 12);
        $input = trim((string)$Url);
        $p = parse_url($input);
        if ($p && isset($p['host'], $p['path'], $p['scheme']) &&
            in_array(strtolower($p['scheme']), array('http', 'https'), true) &&
            $this->inputHost($p['host']) && !isset($p['user']) && !isset($p['pass']) &&
            (!isset($p['port']) || (strtolower($p['scheme']) === 'https' && $p['port'] === 443) || (strtolower($p['scheme']) === 'http' && $p['port'] === 80)) &&
            !preg_match('/[\x00-\x20\x7f]/', $input)) {
            // Signed redirect inputs with a known file ID are renewed through
            // canonical file resolution. Never rewrite or reuse their token.
            if (preg_match('~^/([a-zA-Z0-9]+)(?:/[^/]+)?\.html$~D', $p['path'], $m) ||
                preg_match('~^/download/redirect/[^/]+/([a-zA-Z0-9]+)/?$~D', $p['path'], $m)) {
                $this->id = $m[1];
                $this->url = self::WEB . '/' . $this->id . '.html';
                if (strtolower($p['host']) === 'trbt.cc' || strtolower($p['host']) === 'www.trbt.cc') $this->shortDomain = 'trbt.cc';
            }
        }
        $this->log('MODULE VERSION', '1.0.6');
        $this->log('INPUT URL', (string)$Url);
        $this->log('NORMALIZED URL', $this->url);
    }

    private function userAgent() {
        // Match the downloader when Synology exposes its User-Agent.
        return defined('DOWNLOAD_STATION_USER_AGENT') ? DOWNLOAD_STATION_USER_AGENT : 'Mozilla/5.0 (compatible; Synology Download Station; TurboBitOrg/1.0.6)';
    }

    private function inputHost($host) {
        return in_array(strtolower($host), array('turbobit.net', 'www.turbobit.net', 'trbt.cc', 'www.trbt.cc', 'torbobit.net', 'www.torbobit.net'), true);
    }

    private function initSession() {
        if ($this->curl !== null) return;
        if (!function_exists('curl_init')) $this->fail('PHP_CURL_MISSING');
        $mask = umask(0077);
        $temp = @tempnam('/tmp', 'turbobit_session_');
        if ($temp === false) { umask($mask); $this->fail('SESSION_DIRECTORY_UNWRITABLE'); }
        $created = @unlink($temp) && @mkdir($temp, 0700);
        umask($mask);
        if (!$created) $this->fail('SESSION_DIRECTORY_UNWRITABLE');
        $this->sessionDir = $temp;
        $this->cookieFile = $temp . '/cookies.txt';
        $mask = umask(0077);
        $written = @file_put_contents($this->cookieFile, "# Netscape HTTP Cookie File\n");
        umask($mask);
        if ($written === false || !@chmod($this->cookieFile, 0600)) $this->fail('COOKIE_FILE_UNWRITABLE');
        $this->curl = curl_init();
        if ($this->curl === false) { $this->curl = null; $this->fail('CURL_INIT_FAILED'); }
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, '');
        curl_setopt($this->curl, CURLOPT_COOKIEJAR, $this->cookieFile);
    }

    private function closeSession() {
        if ($this->curl !== null) {
            // Explicitly flush: PHP 8 may retain the handle until garbage collection.
            curl_setopt($this->curl, CURLOPT_COOKIELIST, 'FLUSH');
            if (is_resource($this->curl)) curl_close($this->curl);
            $this->curl = null;
        }
        if (!$this->handedOff && $this->cookieFile !== null) {
            @unlink($this->cookieFile);
            @rmdir($this->sessionDir);
            $this->cookieFile = null;
            $this->sessionDir = null;
        }
    }

    public function __destruct() { $this->closeSession(); }

    protected function request($url, $method = 'GET', $data = null, $referer = '', $json = false, $probe = false) {
        $this->validateUrl($url);
        $this->initSession();
        $headers = array(); $body = ''; $stopped = false;
        $limit = $probe ? 1024 : 2097152;
        $requestHeaders = array('Accept: ' . ($json ? 'application/json' : '*/*'), 'Accept-Language: en-US,en;q=0.9');
        if ($method === 'POST') {
            $requestHeaders[] = 'Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded');
            $requestHeaders[] = 'Origin: ' . self::WEB;
        }
        $opts = array(
            CURLOPT_URL => $url, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $this->userAgent(), CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_REFERER => $referer, CURLOPT_ENCODING => $probe ? 'identity' : '',
            CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => false,
            CURLOPT_RANGE => $probe === true ? '0-1023' : null,
            CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$headers) {
                if (preg_match('~^HTTP/\S+\s+\d+~i', $line)) $headers = array();
                elseif (strpos($line, ':') !== false) {
                    list($name, $value) = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$body, &$stopped, $limit) {
                $remaining = $limit - strlen($body);
                $body .= substr($chunk, 0, max(0, $remaining));
                if (strlen($chunk) > $remaining) { $stopped = true; return 0; }
                return strlen($chunk);
            }
        );
        if (!curl_setopt_array($this->curl, $opts)) $this->fail('CURL_CONFIGURATION_FAILED');
        // Reset previous POST state without clearing the cookie engine.
        curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        if ($method === 'POST') {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $json ? json_encode($data) : http_build_query($data, '', '&'));
        }
        curl_exec($this->curl);
        $errno = curl_errno($this->curl);
        $error = curl_error($this->curl);
        $code = (int)curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        // Deliberate bounded download, not a network failure.
        if ($probe && $stopped && $errno === 23) { $errno = 0; $error = ''; }
        if (!$probe && $stopped) { $errno = 23; $error = 'RESPONSE_EXCEEDS_2_MIB'; }
        $this->log('CURL ERROR', $errno === 0 ? 'none' : 'CURL_' . $errno);
        if ($errno && defined('CURLOPT_SSL_VERIFYPEER')) $this->log('TRANSPORT CATEGORY', in_array($errno, array(35, 51, 58, 60, 77), true) ? 'TLS' : 'NETWORK');
        $cookies = curl_getinfo($this->curl, CURLINFO_COOKIELIST);
        $safeCookies = array();
        if (is_array($cookies)) foreach ($cookies as $cookie) {
            $fields = explode("\t", $cookie);
            if (count($fields) >= 7) $safeCookies[] = array('domain' => $fields[0], 'path' => $fields[2], 'secure' => $fields[3], 'name' => $fields[5], 'value' => '[REDACTED]');
        }
        $this->log('COOKIES', $safeCookies);
        return array('url' => $url, 'code' => $code, 'headers' => $headers, 'body' => $body, 'error' => $error, 'errno' => $errno);
    }

    private function validateUrl($url) {
        $p = parse_url($url);
        if (!$p || !isset($p['host'], $p['scheme']) || !in_array(strtolower($p['scheme']), array('http', 'https'), true) || isset($p['user']) || isset($p['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) $this->fail('INVALID_HTTP_URL');
    }

    protected function absoluteUrl($base, $relative) {
        $relative = trim($relative); // DOM already decodes attributes; HTTP/API URLs are raw.
        $relative = preg_replace('/#.*$/s', '', $relative);
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $relative)) { $this->validateUrl($relative); return $relative; }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (substr($relative, 0, 2) === '//') return $this->absoluteUrl($base, $b['scheme'] . ':' . $relative);
        $path = isset($b['path']) ? $b['path'] : '/';
        if ($relative === '') return preg_replace('/#.*$/s', '', $base);
        if ($relative[0] === '?') return $origin . $path . $relative;
        $r = explode('?', $relative, 2);
        $path = $relative[0] === '/' ? $r[0] : substr($path, 0, strrpos($path, '/') + 1) . $r[0];
        // Keep the root and duplicate slashes, and do not decode signed paths.
        $out = array('');
        $segments = explode('/', $path);
        array_shift($segments);
        foreach ($segments as $index => $segment) {
            if ($segment === '..') {
                if (count($out) > 1) array_pop($out);
            } elseif ($segment !== '.') $out[] = $segment;
            if ($index === count($segments) - 1 && ($segment === '.' || $segment === '..')) $out[] = '';
        }
        $url = $origin . (count($out) === 1 ? '/' : implode('/', $out)) . (count($r) > 1 ? '?' . $r[1] : '');
        $this->validateUrl($url);
        return $url;
    }

    private function trusted($url) {
        $p = parse_url($url);
        return $p && isset($p['scheme'], $p['host']) && strtolower($p['scheme']) === 'https' && !isset($p['user']) && !isset($p['pass']) && (!isset($p['port']) || $p['port'] === 443) && in_array(strtolower($p['host']), array('turbobit.net', 'www.turbobit.net', 'app.turbobit.net'), true);
    }

    protected function follow($url, $phase, $method = 'GET', $data = null, $referer = '', $json = false, $probe = false, $trustedOnly = false) {
        if ($probe && !defined('DOWNLOAD_REFERER')) $referer = '';
        $visited = array();
        for ($i = 0; $i <= 10; $i++) {
            if (isset($visited[$method . ' ' . $url])) $this->fail('REDIRECT_LOOP');
            $visited[$method . ' ' . $url] = true;
            if ($trustedOnly && !$this->trusted($url)) $this->fail('UNTRUSTED_AUTH_REDIRECT');
            $this->log($phase . ' URL', $url);
            $r = $this->request($url, $method, $data, $referer, $json, $probe);
            $this->log($phase . ' HTTP CODE', $r['code']);
            if ($phase === 'LOGIN') $this->log('LOGIN CURL ERROR', $r['errno'] ? 'CURL_' . $r['errno'] : 'none');
            if ($r['errno']) $this->fail('TRANSPORT_ERROR');
            $location = isset($r['headers']['location']) ? $r['headers']['location'] : '';
            if ($phase === 'REDIRECT' || $location !== '') {
                $this->log('REDIRECT HTTP CODE', $r['code']);
                $this->log('LOCATION HEADER', $location);
            }
            if (!in_array($r['code'], array(301, 302, 303, 307, 308), true)) { $r['referer'] = $referer; return $r; }
            if ($location === '') $this->fail('REDIRECT_WITHOUT_LOCATION');
            $next = $this->absoluteUrl($url, $location);
            if ($method === 'POST' && in_array($r['code'], array(307, 308), true) && $this->origin($next) !== $this->origin($url)) $this->fail('CROSS_ORIGIN_POST_REDIRECT');
            if ($r['code'] === 303 || ($method === 'POST' && in_array($r['code'], array(301, 302), true))) { $method = 'GET'; $data = null; }
            // Never send an HTTPS Referer over an HTTP downgrade.
            $referer = ($probe && !defined('DOWNLOAD_REFERER')) || strtolower(parse_url($next, PHP_URL_SCHEME)) === 'http' ? '' : $url;
            $url = $next;
        }
        $this->fail('TOO_MANY_REDIRECTS');
    }

    private function origin($url) {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme']);
        return $scheme . '://' . strtolower($p['host']) . ':' . (isset($p['port']) ? $p['port'] : ($scheme === 'https' ? 443 : 80));
    }

    private function ok($r) {
        if ($r['code'] === 429) $this->fail('RATE_LIMITED');
        if ($r['code'] === 401) $this->fail('AUTHENTICATION_REQUIRED');
        if ($r['code'] === 403) $this->fail('ACCESS_DENIED');
        if ($r['code'] >= 500) $this->fail('SERVICE_UNAVAILABLE');
        if ($r['code'] < 200 || $r['code'] >= 300) $this->fail('HTTP_' . $r['code']);
    }

    private function dom($html) {
        if (!class_exists('DOMDocument')) $this->fail('PHP_DOM_MISSING_FOR_HTML');
        $dom = new DOMDocument();
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        return new DOMXPath($dom);
    }

    protected function loginForm($html, $url) {
        $xp = $this->dom($html);
        foreach ($xp->query('//form[.//input[@type="password"]]') as $form) {
            $fields = array(); $userField = null; $passField = null;
            foreach ($xp->query('.//input[@name]', $form) as $input) {
                if ($input->hasAttribute('disabled')) continue;
                $name = $input->getAttribute('name'); $type = strtolower($input->getAttribute('type'));
                if ($type === 'password') $passField = $name;
                elseif (in_array($name, array('user[login]', 'email', 'login', 'username'), true)) $userField = $name;
                if ($type === 'hidden' || $type === 'submit' || (($type === 'checkbox' || $type === 'radio') && $input->hasAttribute('checked'))) $fields[$name] = $input->getAttribute('value');
            }
            if ($userField !== null && $passField !== null) {
                $action = $this->absoluteUrl($url, $form->getAttribute('action'));
                if (!$this->trusted($action)) $this->fail('UNTRUSTED_LOGIN_ACTION');
                $fields[$userField] = $this->username; $fields[$passField] = $this->password;
                return array($action, $fields);
            }
        }
        return null;
    }

    private function api($path, $phase, $data = null) {
        $r = $this->follow(self::API . $path, $phase, $data === null ? 'GET' : 'POST', $data, self::WEB . '/login', true, false, true);
        $body = json_decode($r['body'], true, 512, JSON_BIGINT_AS_STRING);
        if (is_array($body) && isset($body['error_name']) && is_string($body['error_name'])) {
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['error_name']);
            $this->log('API ERROR', $name);
            if (stripos($name, 'password') !== false || stripos($name, 'credentials') !== false) $this->fail('INVALID_CREDENTIALS');
            if (stripos($name, 'quota') !== false || stripos($name, 'traffic') !== false) $this->fail('QUOTA_EXCEEDED');
            if (stripos($name, 'captcha') !== false || !empty($body['data']['needCaptcha'])) $this->fail('CAPTCHA_REQUIRED');
            if (stripos($name, 'file_not_found') !== false) $this->fail('FILE_NOT_FOUND', ERR_FILE_NO_EXIST);
            $this->fail('API_' . $name);
        }
        $this->ok($r);
        // Login is an acknowledgement, not an account document: an empty JSON
        // array was accepted by 1.0.4. Only /user/info proves the account state.
        $emptyLoginAck = $path === '/auth/login' && $body === array();
        if (!is_array($body) || (!$emptyLoginAck && substr(ltrim($r['body']), 0, 1) !== '{')) $this->fail('API_NOT_JSON_OBJECT');
        if (!empty($body['needCaptcha']) || (isset($body['data']) && is_array($body['data']) && !empty($body['data']['needCaptcha']))) $this->fail('CAPTCHA_REQUIRED');
        return $body;
    }

    private function authenticate() {
        if ($this->account !== null) return $this->account;
        if ($this->username === '' || $this->password === '') $this->fail('CREDENTIALS_MISSING');
        $form = null;
        try {
            $r = $this->follow(self::WEB . '/login', 'LOGIN', 'GET', null, '', false, false, true);
            $this->ok($r);
            if (class_exists('DOMDocument')) $form = $this->loginForm($r['body'], $r['url']);
        } catch (TurboBitOrgException $e) {
            // A public page outage may coexist with a healthy API. No login POST
            // has been made yet; never retry a rejected authentication POST.
            if (!in_array($e->getMessage(), array('TRANSPORT_ERROR', 'ACCESS_DENIED', 'SERVICE_UNAVAILABLE', 'HTTP_404'), true)) throw $e;
            $this->log('LOGIN PAGE FALLBACK', 'API');
        }
        if ($form !== null) {
            $this->mode = 'html';
            $r = $this->follow($form[0], 'LOGIN', 'POST', $form[1], $r['url'], false, false, true);
            $this->ok($r);
            $r = $this->follow(self::WEB . '/', 'ACCOUNT', 'GET', null, self::WEB . '/login', false, false, true);
            $this->ok($r);
            $xp = $this->dom($r['body']);
            $premium = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " user-menu ")]//*[contains(concat(" ", normalize-space(@class), " "), " yesturbo ")]')->length > 0;
            $loggedIn = $xp->query('//a[contains(@href,"/logout")]')->length > 0;
            if (!$premium && !$loggedIn) $this->fail('LOGIN_NOT_CONFIRMED');
            $this->account = $premium ? USER_IS_PREMIUM : USER_IS_FREE;
        } else {
            // API contract observed in Turbobit's own web app, 2026-09-26.
            $this->mode = 'api';
            $this->api('/auth/login', 'LOGIN', array('email' => $this->username, 'password' => $this->password, 'captcha' => true, 'captchaResponse' => '', 'captchaIndex' => 0));
            $info = $this->api('/user/info', 'ACCOUNT');
            // The web app supplies login from its own local state, not /user/info.
            // An authenticated JSON response containing the documented premium
            // status is the evidence here; never require a client-only field.
            if (!isset($info['premium']['status']) || !in_array($info['premium']['status'], array('active', 'inactive'), true)) $this->fail('PREMIUM_STATUS_UNKNOWN');
            $this->account = $info['premium']['status'] === 'active' ? USER_IS_PREMIUM : USER_IS_FREE;
        }
        $this->log('AUTH MODE', $this->mode);
        $this->log('PREMIUM SESSION', $this->account === USER_IS_PREMIUM ? 'yes' : 'no');
        return $this->account;
    }

    public function Verify($ClearCookie) {
        try { $result = $this->authenticate(); }
        catch (TurboBitOrgException $e) { $result = LOGIN_FAIL; }
        if ($ClearCookie) { $this->closeSession(); $this->account = null; $this->mode = null; }
        return $result;
    }

    protected function premiumLink($html, $base) {
        $xp = $this->dom($html);
        foreach ($xp->query('//a[contains(@href,"/download/redirect/")]') as $a) {
            $url = $this->absoluteUrl($base, $a->getAttribute('href'));
            $p = parse_url($url);
            if (strtolower($p['scheme']) === 'https' && ($this->trusted($url) || $this->inputHost($p['host'])) && strpos($p['path'], '/download/redirect/') === 0) return $url;
        }
        return null;
    }

    private function downloadLinks() {
        $this->filePage = $this->url;
        $this->log('FILE PAGE URL', $this->url);
        if ($this->mode === 'api') {
            // Do not require the JavaScript file page after API authentication.
            $this->log('FILE PAGE HTTP CODE', 'not requested (API mode)');
            $info = $this->api('/download/info', 'FILE API', array('fileId' => $this->id, 'referrer' => '', 'site' => null, 'shortDomain' => $this->shortDomain));
            if (!isset($info['premium']) || !is_bool($info['premium'])) $this->fail('INVALID_FILE_API_SCHEMA');
            if (!$info['premium']) $this->fail('PREMIUM_REQUIRED', ERR_REQUIRED_PREMIUM);
            if (!isset($info['file']) || !is_array($info['file']) || !isset($info['downloadUrls']) || !is_array($info['downloadUrls'])) $this->fail('INVALID_FILE_API_SCHEMA');
            if (isset($info['file']['id']) && $info['file']['id'] !== $this->id) $this->fail('FILE_ID_MISMATCH');
            if (isset($info['file']['name']) && is_string($info['file']['name'])) $this->filename = $this->safeFilename($info['file']['name']);
            if (isset($info['file']['size'])) $this->fileSize = $this->decimal($info['file']['size']);
            $this->log('FILE SIZE', $this->fileSize === null ? 'unknown' : $this->fileSize);
            $links = array();
            // At most three supplied candidates, sequentially, for this file only.
            foreach ($info['downloadUrls'] as $candidate) {
                if (!is_string($candidate) || trim($candidate) === '') continue;
                $link = $this->absoluteUrl(self::WEB . '/', $candidate);
                if (!in_array($link, $links, true)) $links[] = $link;
                if (count($links) >= 3) break;
            }
        } else {
            $r = $this->follow($this->url, 'FILE PAGE', 'GET', null, self::WEB . '/');
            if ($r['code'] === 404 || $r['code'] === 410) $this->fail('FILE_NOT_FOUND', ERR_FILE_NO_EXIST);
            $this->ok($r);
            $this->filePage = $r['url'];
            $link = $this->premiumLink($r['body'], $r['url']);
            $links = $link === null ? array() : array($link);
        }
        $this->log('PREMIUM LINK FOUND', count($links) ? 'yes' : 'no');
        if (!$links) $this->fail('PREMIUM_LINK_NOT_FOUND');
        return $links;
    }

    private function decimal($value) {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string)$value)) return null;
        $s = ltrim((string)$value, '0');
        return $s === '' ? '0' : $s;
    }

    private function safeFilename($name) {
        $name = basename(str_replace('\\', '/', preg_replace('/[\x00-\x1f\x7f]/', '', (string)$name)));
        return $name === '' || $name === '.' || $name === '..' ? null : $name;
    }

    protected function filenameFromResponse($r) {
        $cd = isset($r['headers']['content-disposition']) ? $r['headers']['content-disposition'] : '';
        if (preg_match("~filename\\*\\s*=\\s*UTF-8'[^']*'([^;\\r\\n]+)~i", $cd, $m)) return $this->safeFilename(rawurldecode(trim($m[1], " \"")));
        if (preg_match('~filename\s*=\s*(?:"([^"]+)"|([^;\r\n]+))~i', $cd, $m)) return $this->safeFilename(isset($m[2]) && $m[2] !== '' ? trim($m[2]) : $m[1]);
        if ($this->filename !== null) return $this->filename;
        return $this->safeFilename(rawurldecode(basename(parse_url($r['url'], PHP_URL_PATH))));
    }

    protected function isFileResponse($r) {
        if (!in_array($r['code'], array(200, 206), true)) return false;
        $headers = $r['headers'];
        $size = null;
        if ($r['code'] === 206) {
            if (!isset($headers['content-range']) || !preg_match('~^bytes 0-([0-9]+)/([0-9]+)$~iD', $headers['content-range'], $m)) return false;
            // Our request is always bytes 0-1023. Decimal strings avoid 32-bit overflow.
            $end = $this->decimal($m[1]); $size = $this->decimal($m[2]);
            if (strlen($end) > 4 || (int)$end > 1023 || $size === '0' || (strlen($size) <= 4 && (int)$size <= (int)$end)) return false;
            if (strlen($r['body']) !== (int)$end + 1) return false;
            if (isset($headers['content-length']) && $this->decimal($headers['content-length']) !== (string)((int)$end + 1)) return false;
        } elseif (isset($headers['content-length'])) {
            $size = $this->decimal($headers['content-length']);
            if ($size === null) return false;
            if (strlen($size) <= 4 && (int)$size <= 1024 && strlen($r['body']) !== (int)$size) return false;
        }
        $matches = $this->fileSize !== null && $size !== null && $this->fileSize === $size;
        if ($this->fileSize !== null && $size !== null && !$matches) return false;
        $type = isset($headers['content-type']) ? strtolower($headers['content-type']) : '';
        $structured = preg_match('~(?:html|json|xml|javascript|text/plain)~i', $type) || preg_match('/^\s*(?:<!doctype\s+html|<html|<\?xml|[\[{])/i', $r['body']);
        // Legitimate text/HTML/JSON and empty files need matching API metadata.
        // An attachment header alone never overrides a suspicious response.
        if ($structured || $r['body'] === '' || $type === '') return $matches;
        return true;
    }

    private function resolveFile($links) {
        $last = null;
        foreach ($links as $index => $link) {
            $this->log('PREMIUM LINK URL', $link);
            $this->log('CDN ATTEMPT', $index + 1);
            try {
                // Match the final downloader from the first probe; no redundant
                // second GET. A truly single-use URL is still not supported.
                $r = $this->follow($link, 'REDIRECT', 'GET', null, $this->filePage, false, true);
                if ($r['code'] === 416 && $this->fileSize === '0') {
                    $r = $this->follow($link, 'EMPTY FILE', 'GET', null, $this->filePage, false, 'no-range');
                }
                $this->ok($r);
                if (!$this->isFileResponse($r)) $this->fail('FINAL_RESPONSE_IS_NOT_A_FILE');
                return $r;
            } catch (TurboBitOrgException $e) {
                if (!in_array($e->getMessage(), array('TRANSPORT_ERROR', 'SERVICE_UNAVAILABLE', 'HTTP_404', 'HTTP_410', 'ACCESS_DENIED'), true)) throw $e;
                $last = $e;
            }
        }
        throw $last;
    }

    public function GetDownloadInfo() {
        try {
            if ($this->url === '') $this->fail('UNSUPPORTED_INPUT_URL', ERR_NOT_SUPPORT_TYPE);
            if ($this->authenticate() !== USER_IS_PREMIUM) $this->fail('PREMIUM_REQUIRED', ERR_REQUIRED_PREMIUM);
            $r = $this->resolveFile($this->downloadLinks());
            $name = $this->filenameFromResponse($r);
            $this->log('FINAL DOWNLOAD URL', $r['url']);
            $this->log('FILENAME', $name);
            $result = array(DOWNLOAD_URL => $r['url'], DOWNLOAD_ISPARALLELDOWNLOAD => false);
            if ($name !== null) { $result[DOWNLOAD_FILENAME] = $name; if (defined('INFO_NAME')) $result[INFO_NAME] = $name; }
            if ($this->curl !== null && !curl_setopt($this->curl, CURLOPT_COOKIELIST, 'FLUSH')) $this->fail('COOKIE_FLUSH_FAILED');
            if ($this->cookieFile !== null) {
                clearstatcache(true, $this->cookieFile);
                if (!is_file($this->cookieFile) || !is_readable($this->cookieFile) || !@chmod($this->cookieFile, 0600)) $this->fail('COOKIE_FILE_UNREADABLE');
                $result[DOWNLOAD_COOKIE] = $this->cookieFile; $this->handedOff = true;
            }
            if (defined('DOWNLOAD_REFERER')) $result[constant('DOWNLOAD_REFERER')] = $r['referer'];
            if (defined('DOWNLOAD_USER_AGENT')) $result[constant('DOWNLOAD_USER_AGENT')] = $this->userAgent();
            $this->log('RESULT', 'DOWNLOAD_READY');
            return $result;
        } catch (TurboBitOrgException $e) {
            $this->log('RESULT', 'FAILED: ' . $e->getMessage());
            return array(DOWNLOAD_ERROR => $e->getCode() ? $e->getCode() : ERR_UNKNOWN);
        }
    }

    private function fail($reason, $code = 0) {
        $this->log('ERROR', $reason);
        throw new TurboBitOrgException($reason, $code);
    }

    protected function log($label, $value) {
        if (!$this->logging) return;
        if (is_link(static::LOG_DIR)) return;
        if (!is_dir(static::LOG_DIR) && !@mkdir(static::LOG_DIR, 0700, true) && !is_dir(static::LOG_DIR)) return;
        if (!$this->logsMaintained) { $this->logsMaintained = true; $this->maintainLogs(); }
        $file = static::LOG_DIR . '/' . ($this->id === '' ? 'default' : $this->id) . '.log';
        if (is_link($file)) return;
        $text = $this->redact($label, $value);
        $mask = umask(0077);
        $fh = @fopen($file, 'c');
        umask($mask);
        if ($fh === false) return;
        if (@flock($fh, LOCK_EX)) {
            @chmod($file, 0600);
            $stat = fstat($fh);
            if ($stat['size'] > 2097152) ftruncate($fh, 0);
            fseek($fh, 0, SEEK_END);
            fwrite($fh, gmdate('c') . ' [' . $this->runId . '] ' . $label . ': ' . $text . "\n");
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    protected function redact($label, $value) {
        if ($label === 'FILENAME') return '[REDACTED]';
        // Path, query and fragment may all contain download credentials. Keep
        // only a syntactically safe hostname, never a signed CDN path.
        if (preg_match('/(?:^| )URL$/D', $label) || $label === 'LOCATION HEADER') {
            $p = is_string($value) ? parse_url($value) : false;
            return $p && isset($p['scheme'], $p['host']) && preg_match('/^[a-z0-9.-]+$/iD', $p['host']) ? strtolower($p['scheme']) . '://' . $p['host'] . '/[REDACTED]' : '[REDACTED]';
        }
        $text = is_string($value) ? $value : json_encode($value);
        foreach (array($this->username, $this->password) as $secret) {
            if ($secret !== '') $text = str_replace(array($secret, rawurlencode($secret)), '[REDACTED]', $text);
        }
        $text = preg_replace('~https?://[^\s]+~i', '[REDACTED URL]', $text);
        return str_replace(array("\r", "\n"), array('\\r', '\\n'), substr($text, 0, 4096));
    }

    private function maintainLogs() {
        $files = glob(static::LOG_DIR . '/*.log');
        if (!is_array($files)) return;
        $times = array();
        foreach ($files as $file) if (!is_link($file) && is_file($file)) $times[$file] = @filemtime($file);
        asort($times);
        $count = count($times);
        foreach ($times as $file => $mtime) {
            if ($mtime >= time() - 7 * 86400 && $count < 100) continue;
            // Never remove a currently locked log; session cookies are not logs
            // and are deliberately excluded from automatic retention.
            $fh = @fopen($file, 'r+');
            if ($fh === false) continue;
            if (@flock($fh, LOCK_EX | LOCK_NB)) { if (@unlink($file)) $count--; flock($fh, LOCK_UN); }
            fclose($fh);
        }
    }
}
