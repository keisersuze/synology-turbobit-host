<?php
/* TurboBitOrg 1.0.4. Original module: Mathieu Vedie, 2025.
 * Synology Download Station host module; PHP 5.6+ syntax, cURL + DOM required.
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

    public function __construct($Url, $Username, $Password, $HostInfo) {
        $parts = explode(';', (string)$Username);
        $this->username = array_shift($parts);
        $this->password = (string)$Password;
        foreach ($parts as $option) {
            if (trim($option) === 'local_log=1') $this->logging = true;
        }
        $p = parse_url(trim((string)$Url));
        if ($p && isset($p['host'], $p['path'], $p['scheme']) &&
            in_array(strtolower($p['scheme']), array('http', 'https'), true) &&
            in_array(strtolower($p['host']), array('turbobit.net', 'www.turbobit.net', 'trbt.cc', 'www.trbt.cc'), true) &&
            !isset($p['user']) && !isset($p['pass']) && !isset($p['port']) &&
            preg_match('~^/([a-zA-Z0-9]+)(?:/[^/]+)?\.html$~D', $p['path'], $m)) {
            $this->id = $m[1];
            $this->url = self::WEB . '/' . $this->id . '.html';
            if (strpos(strtolower($p['host']), 'trbt.cc') !== false) $this->shortDomain = 'trbt.cc';
        }
        $this->log('MODULE VERSION', '1.0.4');
        $this->log('INPUT URL', (string)$Url);
        $this->log('NORMALIZED URL', $this->url);
    }

    private function userAgent() {
        // Match the downloader when Synology exposes its User-Agent.
        return defined('DOWNLOAD_STATION_USER_AGENT') ? DOWNLOAD_STATION_USER_AGENT : 'Mozilla/5.0 (compatible; Synology Download Station; TurboBitOrg/1.0.4)';
    }

    private function initSession() {
        if ($this->curl !== null) return;
        if (!function_exists('curl_init') || !class_exists('DOMDocument')) $this->fail('PHP_CURL_OR_DOM_MISSING');
        $temp = tempnam('/tmp', 'turbobit_session_');
        if ($temp === false) $this->fail('SESSION_DIRECTORY_UNWRITABLE');
        unlink($temp);
        if (!mkdir($temp, 0700)) $this->fail('SESSION_DIRECTORY_UNWRITABLE');
        $this->sessionDir = $temp;
        $this->cookieFile = $temp . '/cookies.txt';
        file_put_contents($this->cookieFile, "# Netscape HTTP Cookie File\n");
        chmod($this->cookieFile, 0600);
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, '');
        curl_setopt($this->curl, CURLOPT_COOKIEJAR, $this->cookieFile);
    }

    private function closeSession() {
        if ($this->curl !== null) {
            // Explicitly flush: PHP 8 may retain the handle until garbage collection.
            curl_setopt($this->curl, CURLOPT_COOKIELIST, 'FLUSH');
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
            CURLOPT_REFERER => $referer, CURLOPT_ENCODING => '',
            CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => false,
            CURLOPT_RANGE => $probe ? '0-1023' : null,
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
        curl_setopt_array($this->curl, $opts);
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
        $this->log('CURL ERROR', $error === '' ? 'none' : $error);
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
        $relative = trim(html_entity_decode($relative, ENT_QUOTES, 'UTF-8'));
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
        $out = array();
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') array_pop($out);
            elseif ($segment !== '.') $out[] = $segment;
        }
        $url = $origin . implode('/', $out) . (count($r) > 1 ? '?' . $r[1] : '');
        $this->validateUrl($url);
        return $url;
    }

    private function trusted($url) {
        $p = parse_url($url);
        return $p && isset($p['scheme'], $p['host']) && $p['scheme'] === 'https' && !isset($p['port']) && in_array(strtolower($p['host']), array('turbobit.net', 'www.turbobit.net', 'app.turbobit.net'), true);
    }

    protected function follow($url, $phase, $method = 'GET', $data = null, $referer = '', $json = false, $probe = false, $trustedOnly = false) {
        $visited = array();
        for ($i = 0; $i <= 10; $i++) {
            if (isset($visited[$method . ' ' . $url])) $this->fail('REDIRECT_LOOP');
            $visited[$method . ' ' . $url] = true;
            if ($trustedOnly && !$this->trusted($url)) $this->fail('UNTRUSTED_AUTH_REDIRECT');
            $this->log($phase . ' URL', $url);
            $r = $this->request($url, $method, $data, $referer, $json, $probe);
            $this->log($phase . ' HTTP CODE', $r['code']);
            if ($phase === 'LOGIN') $this->log('LOGIN CURL ERROR', $r['error'] === '' ? 'none' : $r['error']);
            if ($r['errno']) $this->fail('TRANSPORT_ERROR');
            $location = isset($r['headers']['location']) ? $r['headers']['location'] : '';
            if ($phase === 'REDIRECT' || $location !== '') {
                $this->log('REDIRECT HTTP CODE', $r['code']);
                $this->log('LOCATION HEADER', $location);
            }
            if (!in_array($r['code'], array(301, 302, 303, 307, 308), true)) { $r['referer'] = $referer; return $r; }
            if ($location === '') $this->fail('REDIRECT_WITHOUT_LOCATION');
            $next = $this->absoluteUrl($url, $location);
            if ($method === 'POST' && in_array($r['code'], array(307, 308), true) && parse_url($next, PHP_URL_HOST) !== parse_url($url, PHP_URL_HOST)) $this->fail('CROSS_ORIGIN_POST_REDIRECT');
            if ($r['code'] === 303 || ($method === 'POST' && in_array($r['code'], array(301, 302), true))) { $method = 'GET'; $data = null; }
            // Never send an HTTPS Referer over an HTTP downgrade.
            $referer = parse_url($next, PHP_URL_SCHEME) === 'http' ? '' : $url;
            $url = $next;
        }
        $this->fail('TOO_MANY_REDIRECTS');
    }

    private function ok($r) {
        if ($r['code'] < 200 || $r['code'] >= 300) $this->fail('HTTP_' . $r['code']);
    }

    private function dom($html) {
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
        $body = json_decode($r['body'], true);
        if (is_array($body) && isset($body['error_name'])) {
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['error_name']);
            $this->log('API ERROR', $name);
            if (stripos($name, 'captcha') !== false || !empty($body['data']['needCaptcha'])) $this->fail('CAPTCHA_REQUIRED');
            if (stripos($name, 'file_not_found') !== false) $this->fail('FILE_NOT_FOUND', ERR_FILE_NO_EXIST);
            $this->fail('API_' . $name);
        }
        $this->ok($r);
        if (!is_array($body)) $this->fail('API_NOT_JSON');
        return $body;
    }

    private function authenticate() {
        if ($this->account !== null) return $this->account;
        if ($this->username === '' || $this->password === '') $this->fail('CREDENTIALS_MISSING');
        $r = $this->follow(self::WEB . '/login', 'LOGIN', 'GET', null, '', false, false, true);
        $this->ok($r);
        $form = $this->loginForm($r['body'], $r['url']);
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
            if ($this->trusted($url) && strpos(parse_url($url, PHP_URL_PATH), '/download/redirect/') === 0) return $url;
        }
        return null;
    }

    private function downloadLink() {
        $this->filePage = $this->url;
        $r = $this->follow($this->url, 'FILE PAGE', 'GET', null, self::WEB . '/');
        if ($r['code'] === 404 || $r['code'] === 410) $this->fail('FILE_NOT_FOUND', ERR_FILE_NO_EXIST);
        $this->ok($r);
        $this->filePage = $r['url'];
        $link = $this->premiumLink($r['body'], $r['url']);
        if ($link === null && $this->mode === 'api') {
            $info = $this->api('/download/info', 'FILE API', array('fileId' => $this->id, 'referrer' => '', 'site' => null, 'shortDomain' => $this->shortDomain));
            if (empty($info['premium'])) $this->fail('PREMIUM_REQUIRED', ERR_REQUIRED_PREMIUM);
            if (!empty($info['needCaptcha'])) $this->fail('CAPTCHA_REQUIRED');
            if (isset($info['file']['name'])) $this->filename = $this->safeFilename($info['file']['name']);
            if (isset($info['file']['size'])) $this->log('FILE SIZE', $info['file']['size']);
            if (!empty($info['downloadUrls'][0]) && is_string($info['downloadUrls'][0])) $link = $this->absoluteUrl(self::WEB . '/', $info['downloadUrls'][0]);
        }
        $this->log('PREMIUM LINK FOUND', $link !== null ? 'yes' : 'no');
        $this->log('PREMIUM LINK URL', $link === null ? '' : $link);
        if ($link === null) $this->fail('PREMIUM_LINK_NOT_FOUND');
        return $link;
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
        $type = isset($r['headers']['content-type']) ? strtolower($r['headers']['content-type']) : '';
        $cd = isset($r['headers']['content-disposition']) ? $r['headers']['content-disposition'] : '';
        if (stripos($cd, 'attachment') !== false || preg_match('/filename\*?\s*=/i', $cd)) return true;
        if (preg_match('/^\s*(?:<!doctype\s+html|<html|<\?xml)/i', $r['body'])) return false;
        return $type !== '' && !preg_match('~(?:html|json|xml|javascript|text/plain)~i', $type);
    }

    public function GetDownloadInfo() {
        try {
            if ($this->url === '') $this->fail('UNSUPPORTED_INPUT_URL', ERR_NOT_SUPPORT_TYPE);
            if ($this->authenticate() !== USER_IS_PREMIUM) $this->fail('PREMIUM_REQUIRED', ERR_REQUIRED_PREMIUM);
            $link = $this->downloadLink();
            // Bounded GET works even on servers that reject HEAD; never buffer a full file.
            $r = $this->follow($link, 'REDIRECT', 'GET', null, $this->filePage, false, true);
            $this->ok($r);
            if (!$this->isFileResponse($r)) $this->fail('FINAL_RESPONSE_IS_NOT_A_FILE');
            // The installed Synology interface exposes cookiepath, but no Referer.
            // Check the final URL under the same conditions as its downloader.
            if (!defined('DOWNLOAD_REFERER') && $r['referer'] !== '') {
                $plain = $this->request($r['url'], 'GET', null, '', false, true);
                $this->log('DOWNLOADER PROBE HTTP CODE', $plain['code']);
                if ($plain['errno'] || !$this->isFileResponse($plain)) $this->fail('FINAL_URL_REQUIRES_UNSUPPORTED_REFERER_OR_NEW_SESSION');
            }
            $name = $this->filenameFromResponse($r);
            $this->log('FINAL DOWNLOAD URL', $r['url']);
            $this->log('FILENAME', $name);
            $result = array(DOWNLOAD_URL => $r['url'], DOWNLOAD_ISPARALLELDOWNLOAD => false);
            if ($name !== null) { $result[DOWNLOAD_FILENAME] = $name; if (defined('INFO_NAME')) $result[INFO_NAME] = $name; }
            if ($this->curl !== null) curl_setopt($this->curl, CURLOPT_COOKIELIST, 'FLUSH');
            if ($this->cookieFile !== null) { $result[DOWNLOAD_COOKIE] = $this->cookieFile; $this->handedOff = true; }
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

    private function log($label, $value) {
        if (!$this->logging) return;
        if (is_link(self::LOG_DIR)) return;
        if (!is_dir(self::LOG_DIR) && !@mkdir(self::LOG_DIR, 0700, true) && !is_dir(self::LOG_DIR)) return;
        $file = self::LOG_DIR . '/' . ($this->id === '' ? 'default' : $this->id) . '.log';
        if (is_link($file)) return;
        $text = is_string($value) ? $value : json_encode($value);
        // Logs never contain credentials, cookie values or query token values.
        foreach (array($this->username, $this->password) as $secret) {
            if ($secret !== '') $text = str_replace(array($secret, rawurlencode($secret)), '[REDACTED]', $text);
        }
        $text = preg_replace('~([?&][^=\s&]+)=([^&\s]*)~', '$1=[REDACTED]', $text);
        $text = preg_replace('~(/download/redirect/)[^/\s]+~', '$1[REDACTED]', $text);
        $text = str_replace(array("\r", "\n"), array('\\r', '\\n'), $text);
        // Bound a per-file log to approximately 2 MiB; mode 0600 from creation.
        $mask = umask(0077);
        if (@filesize($file) > 2097152) @file_put_contents($file, '', LOCK_EX);
        @file_put_contents($file, gmdate('c') . ' ' . $label . ': ' . $text . "\n", FILE_APPEND | LOCK_EX);
        @chmod($file, 0600);
        umask($mask);
    }
}
