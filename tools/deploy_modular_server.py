import os
import time
import paramiko
import urllib.request
import ssl

HOST = "10.1.1.23"
PORT = 22
USER = "biru"
PASS = "kisah2Kasih"
REMOTE_DIR = "/home/biru/domains/biru.co.id/public_html/tele-vs-code"

print(f"Connecting to {HOST}:{PORT} via SSH/SFTP...")
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, port=PORT, username=USER, password=PASS, timeout=15)
sftp = ssh.open_sftp()
print("SFTP & SSH Connected.")

# 1. Pastikan seluruh folder remote siap
dirs_to_ensure = [
    f"{REMOTE_DIR}/includes",
    f"{REMOTE_DIR}/views",
    f"{REMOTE_DIR}/assets",
    f"{REMOTE_DIR}/assets/css",
    f"{REMOTE_DIR}/assets/js",
    f"{REMOTE_DIR}/assets/img",
    f"{REMOTE_DIR}/publish",
    f"{REMOTE_DIR}/publish/vsix",
    f"{REMOTE_DIR}/uploads"
]
for d in dirs_to_ensure:
    ssh.exec_command(f"mkdir -p {d}")

# 2. Backup remote index.php
ts = time.strftime("%Y%m%d_%H%M%S")
bak_cmd = f"cd {REMOTE_DIR} && cp index.php index.php.bak_{ts}"
stdin, stdout, stderr = ssh.exec_command(bak_cmd)
stdout.channel.recv_exit_status()
print(f"Created remote backup: index.php.bak_{ts}")

# 3. Kumpulkan file lokal yang wajib di-sync
files_to_upload = [
    ("tele-vs-code/index.php", f"{REMOTE_DIR}/index.php"),
    ("tele-vs-code/.htaccess", f"{REMOTE_DIR}/.htaccess"),
    ("tele-vs-code/.user.ini", f"{REMOTE_DIR}/.user.ini"),
]

# views/*.php
if os.path.exists("tele-vs-code/views"):
    for f in os.listdir("tele-vs-code/views"):
        if f.endswith(".php"):
            files_to_upload.append((f"tele-vs-code/views/{f}", f"{REMOTE_DIR}/views/{f}"))

# includes/*.php
if os.path.exists("tele-vs-code/includes"):
    for f in os.listdir("tele-vs-code/includes"):
        if f.endswith(".php"):
            files_to_upload.append((f"tele-vs-code/includes/{f}", f"{REMOTE_DIR}/includes/{f}"))

# assets/css/*
if os.path.exists("tele-vs-code/assets/css"):
    for f in os.listdir("tele-vs-code/assets/css"):
        files_to_upload.append((f"tele-vs-code/assets/css/{f}", f"{REMOTE_DIR}/assets/css/{f}"))

# assets/js/*
if os.path.exists("tele-vs-code/assets/js"):
    for f in os.listdir("tele-vs-code/assets/js"):
        files_to_upload.append((f"tele-vs-code/assets/js/{f}", f"{REMOTE_DIR}/assets/js/{f}"))

# assets/img/*
if os.path.exists("tele-vs-code/assets/img"):
    for f in os.listdir("tele-vs-code/assets/img"):
        files_to_upload.append((f"tele-vs-code/assets/img/{f}", f"{REMOTE_DIR}/assets/img/{f}"))

print(f"\nUploading {len(files_to_upload)} files to server...")
for local_p, remote_p in files_to_upload:
    try:
        sftp.put(local_p, remote_p)
        sz = os.path.getsize(local_p)
        print(f"  -> Uploaded {local_p} ({sz:,} bytes) to {remote_p}")
    except Exception as e:
        print(f"  ❌ Error uploading {local_p}: {e}")

sftp.close()

# 4. Set Permission
perm_cmd = f"cd {REMOTE_DIR} && chmod 755 . includes views assets assets/* publish publish/vsix uploads && chmod 644 *.php .htaccess includes/*.php views/*.php assets/*/* 2>/dev/null"
ssh.exec_command(perm_cmd)

# 5. Check PHP syntax on remote server
print("\nVerifying PHP syntax on remote server...")
check_cmd = f"cd {REMOTE_DIR} && php -l index.php && for f in includes/*.php views/*.php; do php -l \"$f\"; done"
stdin, stdout, stderr = ssh.exec_command(check_cmd)
out = stdout.read().decode('utf-8')
err = stderr.read().decode('utf-8')
exit_code = stdout.channel.recv_exit_status()

print("Remote Lint Output:\n" + out)
if err:
    print("Remote Lint Errors:\n" + err)

ssh.close()

if exit_code != 0:
    print("❌ Syntax error detected on remote server!")
    exit(1)

# 6. Test Production Endpoints
print("\nTesting live production endpoints...")
ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

# Endpoint 1: Landing / Dashboard
req1 = urllib.request.Request("https://biru.co.id/tele-vs-code/", headers={"User-Agent": "Mozilla/5.0"})
resp1 = urllib.request.urlopen(req1, context=ctx, timeout=10)
body1 = resp1.read().decode('utf-8', errors='ignore')
print(f"1. Landing Page: HTTP {resp1.status} - Contains Tele-vs-Code: {'Tele-vs-Code' in body1}")

# Endpoint 2: Tab Bantuan
req2 = urllib.request.Request("https://biru.co.id/tele-vs-code/?tab=bantuan", headers={"User-Agent": "Mozilla/5.0"})
resp2 = urllib.request.urlopen(req2, context=ctx, timeout=10)
body2 = resp2.read().decode('utf-8', errors='ignore')
print(f"2. Tab Bantuan: HTTP {resp2.status} - Guard Present: {'InvalidStateError' in body2}")

# Endpoint 3: Webhook
req3 = urllib.request.Request("https://biru.co.id/tele-vs-code/webhook?pkey=prj_61fa56314a", data=b"{}", headers={"Content-Type": "application/json", "User-Agent": "TelegramBot"})
resp3 = urllib.request.urlopen(req3, context=ctx, timeout=10)
body3 = resp3.read().decode('utf-8', errors='ignore')
print(f"3. Webhook: HTTP {resp3.status} - Body: {body3.strip()}")

# Endpoint 4: Download VSIX
req4 = urllib.request.Request("https://biru.co.id/tele-vs-code/?action=download_vsix", headers={"User-Agent": "Mozilla/5.0"})
resp4 = urllib.request.urlopen(req4, context=ctx, timeout=10)
disposition = resp4.headers.get("Content-Disposition", "")
print(f"4. Download VSIX: HTTP {resp4.status} - File: {disposition}")

if resp1.status == 200 and resp2.status == 200 and resp3.status == 200 and resp4.status == 200 and body3.strip() == "OK":
    print("\n🎉 PRODUCTION DEPLOYMENT & SINKRONISASI SERVER 100% SUKSES DAN TERVERIFIKASI!")
else:
    print("\n⚠️ Periksa kembali respon endpoint di atas.")
