import json
from http.server import BaseHTTPRequestHandler, HTTPServer

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        if self.path == '/reference.bin':
            payload = bytes(range(256)) * 16
            ranged = self.headers.get('Range') == 'bytes=0-1023'
            self.send_response(206 if ranged else 200)
            if ranged:
                self.send_header('Content-Range', 'bytes 0-1023/4096')
                payload = payload[:1024]
            self.send_header('Content-Type', 'application/octet-stream')
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
        elif self.path == '/set-cookie':
            self.send_response(200)
            self.send_header('Set-Cookie', 'fixture_session=synthetic-value; Path=/only; HttpOnly')
            self.end_headers()
            self.wfile.write(b'ok')
        elif self.path == '/large':
            self.send_response(200)
            self.send_header('Content-Type', 'application/octet-stream')
            self.send_header('Content-Length', str(1024 * 1024))
            self.end_headers()
            try:
                self.wfile.write(b'x' * (1024 * 1024))
            except (BrokenPipeError, ConnectionResetError):
                pass
        else:
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps({'cookie': self.headers.get('Cookie', ''), 'host': self.headers.get('Host'), 'referer': self.headers.get('Referer'), 'range': self.headers.get('Range'), 'ua': self.headers.get('User-Agent')}).encode())

server = HTTPServer(('127.0.0.1', 0), Handler)
print(server.server_port, flush=True)
server.serve_forever()
