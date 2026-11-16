import urllib.request
import paramiko
import json

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('10.1.1.23', port=22, username='biru', password='kisah2Kasih')

cmd = """php -r '
session_save_path("/home/biru/domains/biru.co.id/tmp");
session_start();
$_SESSION["tvsc_uid"] = 1;
$_SESSION["tvsc_user_id"] = 1;
$_SESSION["tvsc_lang"] = "id";
session_write_close();
echo session_id();
'"""
stdin, stdout, stderr = ssh.exec_command(cmd)
sess_id = stdout.read().decode().strip()
ssh.close()

print(f"Created real PHP session ID: {sess_id}")

urls = [
    f"https://biru.co.id/tele-vs-code/?action=check_task_updates&hash=&filter_project=0&_ts=1",
    f"https://biru.co.id/tele-vs-code/?action=render_task_cards&filter_project=0&page=1&per_page=10&_ts=1"
]

for url in urls:
    print(f"\n--- Fetching: {url} ---")
    req = urllib.request.Request(url, headers={
        'User-Agent': 'Mozilla/5.0',
        'Cookie': f'PHPSESSID={sess_id}',
        'X-Requested-With': 'XMLHttpRequest'
    })
    try:
        with urllib.request.urlopen(req) as resp:
            body = resp.read().decode('utf-8')
            print("Status:", resp.status)
            print("Headers content-type:", resp.headers.get('content-type'))
            print("First 200 chars:")
            print(repr(body[:200]))
            try:
                parsed = json.loads(body)
                print("JSON parse SUCCESS! ok =", parsed.get('ok'), "changed =", parsed.get('changed'))
                if 'cards_html' in parsed:
                    print("cards_html length:", len(parsed['cards_html']))
            except Exception as je:
                print("JSON parse FAILED! Error:", je)
    except Exception as e:
        print("HTTP Error:", e)
