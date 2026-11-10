import json, socket, ssl, urllib.request, urllib.error
host = "remote.sandav.de"
print("dns_ipv4", sorted({x[4][0] for x in socket.getaddrinfo(host, None, socket.AF_INET)}))
for port in (31115, 31116, 31117):
    with socket.create_connection((host, port), timeout=8):
        print("tcp", port, "ok")
peer_id = b"own-external-connectivity-probe"
reg = b"\x0a" + bytes([len(peer_id)]) + peer_id
packet = b"\x32" + bytes([len(reg)]) + reg
with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
    s.settimeout(8)
    s.sendto(packet, (host, 31116))
    data, _ = s.recvfrom(2048)
    assert data.startswith(b"\x3a"), "unexpected registration response"
    print("udp", 31116, "protocol_response_ok")
ctx = ssl.create_default_context()
with socket.create_connection((host,443),timeout=8) as s:
    with ctx.wrap_socket(s,server_hostname=host) as tls:
        cert=tls.getpeercert()
        print("tls",tls.version(),"expires",cert["notAfter"])
for path, expected in [("/",200),("/api/login-options",200),("/api/ab",401),("/admin",404),("/scripts/user.php",404)]:
    try:
        with urllib.request.urlopen("https://"+host+path,timeout=10,context=ctx) as r:
            code=r.status
            if path=="/": assert json.loads(r.read())["name"]=="rust-book"
    except urllib.error.HTTPError as e: code=e.code
    assert code==expected, (path,code,expected)
    print("https",path,code,"ok")
print("PASS: external network, TLS, API authentication boundary and blocked admin")
