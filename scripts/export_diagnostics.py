"""Print only allowlisted diagnostics, excluding raw URLs, file names and cookies.

Accepts logs produced by 1.0.5. Legacy lines are skipped, never copied verbatim.
"""
import re
import sys

STATES = {'api', 'html', 'yes', 'no', 'none', 'TLS', 'NETWORK', 'API', 'DOWNLOAD_READY',
          'TRANSPORT_ERROR', 'SERVICE_UNAVAILABLE', 'RATE_LIMITED', 'ACCESS_DENIED',
          'AUTHENTICATION_REQUIRED', 'INVALID_CREDENTIALS', 'QUOTA_EXCEEDED',
          'CAPTCHA_REQUIRED', 'PREMIUM_REQUIRED', 'FILE_NOT_FOUND', 'FILE_ID_MISMATCH',
          'INVALID_FILE_API_SCHEMA', 'PREMIUM_STATUS_UNKNOWN', 'LOGIN_NOT_CONFIRMED',
          'API_NOT_JSON_OBJECT', 'FINAL_RESPONSE_IS_NOT_A_FILE', 'PREMIUM_LINK_NOT_FOUND',
          'REDIRECT_LOOP', 'TOO_MANY_REDIRECTS', 'REDIRECT_WITHOUT_LOCATION',
          'CROSS_ORIGIN_POST_REDIRECT', 'UNTRUSTED_AUTH_REDIRECT', 'UNTRUSTED_LOGIN_ACTION',
          'INVALID_HTTP_URL', 'UNSUPPORTED_INPUT_URL', 'CREDENTIALS_MISSING',
          'COOKIE_FLUSH_FAILED', 'COOKIE_FILE_UNREADABLE', 'COOKIE_FILE_UNWRITABLE',
          'SESSION_DIRECTORY_UNWRITABLE', 'CURL_INIT_FAILED', 'CURL_CONFIGURATION_FAILED',
          'PHP_CURL_MISSING', 'PHP_DOM_MISSING_FOR_HTML'}
LABELS = {'MODULE VERSION', 'AUTH MODE', 'PREMIUM SESSION', 'PREMIUM LINK FOUND',
          'TRANSPORT CATEGORY', 'LOGIN PAGE FALLBACK', 'CDN ATTEMPT', 'ERROR', 'RESULT',
          'CURL ERROR', 'LOGIN CURL ERROR', 'INPUT URL', 'NORMALIZED URL', 'LOGIN URL',
          'ACCOUNT URL', 'FILE PAGE URL', 'FILE API URL', 'PREMIUM LINK URL', 'REDIRECT URL',
          'LOCATION HEADER', 'FINAL DOWNLOAD URL', 'FILENAME', 'COOKIES'}


def sanitize(lines):
    result = []
    for line in lines:
        match = re.fullmatch(r'(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00) \[([0-9a-f]{12})\] ([A-Z ]+): (.*)\n?', line)
        if not match:
            continue
        stamp, run, label, value = match.groups()
        if label not in LABELS and not re.fullmatch(r'(LOGIN|ACCOUNT|FILE PAGE|FILE API|REDIRECT|EMPTY FILE) HTTP CODE', label):
            continue
        if label.endswith('URL') or label in {'LOCATION HEADER', 'FILENAME', 'COOKIES'}:
            value = '[REDACTED]'
        elif label == 'MODULE VERSION':
            value = value if re.fullmatch(r'\d+\.\d+\.\d+', value) else '[UNKNOWN]'
        elif label.endswith('HTTP CODE'):
            value = value if re.fullmatch(r'\d{3}', value) else '[NOT REQUESTED OR UNKNOWN]'
        elif label == 'CDN ATTEMPT':
            value = value if value in {'1', '2', '3'} else '[UNKNOWN]'
        else:
            state = value[8:] if value.startswith('FAILED: ') else value
            value = value if state in STATES or re.fullmatch(r'(HTTP|CURL)_\d{1,3}', state) else '[UNKNOWN]'
        result.append(f'{stamp} [{run}] {label}: {value}')
    return result


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit('Usage: python3 scripts/export_diagnostics.py <log-file>')
    with open(sys.argv[1], encoding='utf-8', errors='replace') as stream:
        print('\n'.join(sanitize(stream)))
