import os
import time
import tarfile
import paramiko
from datetime import datetime

timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
local_backup_dir = os.path.abspath("backups")
os.makedirs(local_backup_dir, exist_ok=True)

print(f"=== INITIATING FULL BACKUP [{timestamp}] ===")

# -------------------------------------------------------------
# 1. REMOTE SERVER BACKUP (Database SQLite & Web Files)
# -------------------------------------------------------------
print("\n[1/3] Connecting to Remote Server (10.1.1.23)...")
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('10.1.1.23', port=22, username='biru', password='kisah2Kasih')

remote_backup_dir = "/home/biru/domains/biru.co.id/backups/tele-vs-code"
remote_db_path = "/home/biru/domains/biru.co.id/data/tele-vs-code/tele-vs-code.db"
remote_web_dir = "/home/biru/domains/biru.co.id/public_html/tele-vs-code"
remote_archive_name = f"tele-vs-code_server_backup_{timestamp}.tar.gz"
remote_archive_path = f"{remote_backup_dir}/{remote_archive_name}"

remote_cmds = f"""
mkdir -p {remote_backup_dir}

# Safe SQLite snapshot using sqlite3 VACUUM INTO or sqlite3 .backup
if command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 {remote_db_path} ".backup '{remote_backup_dir}/tele-vs-code_db_snapshot.sqlite'"
else
    cp {remote_db_path} {remote_backup_dir}/tele-vs-code_db_snapshot.sqlite
fi

# Create compressed tar.gz of web files and db snapshot
tar -czf {remote_archive_path} \\
    -C /home/biru/domains/biru.co.id/public_html tele-vs-code \\
    -C {remote_backup_dir} tele-vs-code_db_snapshot.sqlite

rm -f {remote_backup_dir}/tele-vs-code_db_snapshot.sqlite

ls -lh {remote_archive_path}
"""

stdin, stdout, stderr = ssh.exec_command(remote_cmds)
remote_out = stdout.read().decode().strip()
remote_err = stderr.read().decode().strip()
print("Remote Output:")
print(remote_out)
if remote_err:
    print("Remote Stderr:", remote_err)

# -------------------------------------------------------------
# 2. DOWNLOAD DATABASE BACKUP TO LOCAL
# -------------------------------------------------------------
print("\n[2/3] Downloading DB & Server Backup Snapshot to Local...")
sftp = ssh.open_sftp()
local_db_backup = os.path.join(local_backup_dir, f"tele-vs-code_{timestamp}.db")
local_remote_archive = os.path.join(local_backup_dir, remote_archive_name)

# Download database directly
sftp.get(remote_db_path, local_db_backup)
print(f"-> SQLite DB downloaded: {local_db_backup} ({os.path.getsize(local_db_backup):,} bytes)")

# Download server archive
sftp.get(remote_archive_path, local_remote_archive)
print(f"-> Server archive downloaded: {local_remote_archive} ({os.path.getsize(local_remote_archive):,} bytes)")
sftp.close()
ssh.close()

# -------------------------------------------------------------
# 3. LOCAL WORKSPACE ARCHIVE
# -------------------------------------------------------------
print("\n[3/3] Archiving Local Workspace...")
local_ws_archive = os.path.join(local_backup_dir, f"workspace_local_backup_{timestamp}.tar.gz")

ignore_patterns = ['.git', 'node_modules', 'backups', '.gemini', 'scratch']

def filter_tar(tarinfo):
    for pat in ignore_patterns:
        if f"/{pat}/" in tarinfo.name or tarinfo.name.endswith(f"/{pat}") or tarinfo.name.startswith(f"{pat}/") or tarinfo.name == pat:
            return None
    return tarinfo

with tarfile.open(local_ws_archive, "w:gz") as tar:
    for item in os.listdir('.'):
        if item in ignore_patterns:
            continue
        tar.add(item, filter=filter_tar)

print(f"-> Local workspace archived: {local_ws_archive} ({os.path.getsize(local_ws_archive):,} bytes)")

print("\n=== BACKUP COMPLETED SUCCESSFULLY ===")
print(f"1. Remote Server Archive: {remote_archive_path}")
print(f"2. Local DB Snapshot:    {local_db_backup}")
print(f"3. Local Server Copy:    {local_remote_archive}")
print(f"4. Local Source Archive: {local_ws_archive}")
