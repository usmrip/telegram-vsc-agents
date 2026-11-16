import os, subprocess, sys

php_bin = 'C:\\apps\\php-8.3\\php.exe'
php_files = []

for root, dirs, files in os.walk('tele-vs-code'):
    for f in files:
        if f.endswith('.php'):
            php_files.append(os.path.join(root, f))

print(f"Linting {len(php_files)} PHP files in tele-vs-code...")
errors = []

for p in sorted(php_files):
    res = subprocess.run([php_bin, '-l', p], capture_output=True, text=True)
    if res.returncode != 0:
        errors.append((p, res.stderr or res.stdout))
        print(f"❌ {p}: {res.stderr or res.stdout}")
    else:
        print(f"✅ {p}: OK")

if not errors:
    print(f"\n🎉 ALL {len(php_files)} PHP FILES ARE 100% VALID!")
else:
    print(f"\n💥 {len(errors)} files failed linting!")
    sys.exit(1)
