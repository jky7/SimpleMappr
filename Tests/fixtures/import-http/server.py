import gzip, http.server, ssl, threading, time
class Fixture(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'
    def log_message(self, *args): pass
    def do_GET(self):
        data = '"Montréal, Québec",45.5,-73.5\r\nOrigin,0,0\r\n'.encode()
        if self.path == '/slow': time.sleep(20)
        if self.path == '/redirect':
            self.send_response(302); self.send_header('Location','/data'); self.send_header('Content-Length','0'); self.end_headers(); return
        if self.path == '/escape':
            self.send_response(302); self.send_header('Location','http://127.0.0.1/secret'); self.send_header('Content-Length','0'); self.end_headers(); return
        if self.path == '/downgrade':
            self.send_response(302); self.send_header('Location','http://fixture.test/data'); self.send_header('Content-Length','0'); self.end_headers(); return
        self.send_response(200)
        if self.path == '/headers':
            for i in range(100): self.send_header('X-Large-'+str(i),'x'*1000)
            self.send_header('Content-Length','0'); self.end_headers(); return
        if self.path == '/size':
            self.send_header('Content-Length', str(6*1024*1024)); self.end_headers(); return
        if self.path in ['/gzip','gzip-large'] or self.path == '/gzip-large':
            data = gzip.compress(b'x'*(6*1024*1024)) if self.path == '/gzip-large' else gzip.compress(data)
            self.send_header('Content-Encoding','gzip')
        if self.path == '/chunked':
            self.send_header('Transfer-Encoding','chunked'); self.end_headers()
            try:
                for _ in range(100): self.wfile.write(b'10000\r\n'+b'x'*65536+b'\r\n'); self.wfile.flush()
                self.wfile.write(b'0\r\n\r\n')
            except (BrokenPipeError,ConnectionResetError): pass
            return
        self.send_header('Content-Length',str(len(data))); self.end_headers()
        try: self.wfile.write(data)
        except (BrokenPipeError,ConnectionResetError): pass
plain = http.server.ThreadingHTTPServer(('0.0.0.0',80),Fixture)
tls = http.server.ThreadingHTTPServer(('0.0.0.0',443),Fixture)
context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain('/fixture/cert.pem','/fixture/key.pem');tls.socket=context.wrap_socket(tls.socket,server_side=True)
threading.Thread(target=plain.serve_forever,daemon=True).start();tls.serve_forever()
