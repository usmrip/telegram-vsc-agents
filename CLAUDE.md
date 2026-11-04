# 🤖 PANDUAN KERJA OTOMATIS AI AGENT (Tele-vs-Code)

## ⚡ 1. MANDATORY TRIGGER: EKSEKUSI OTOMATIS ANTREAN TUGAS (TASK_QUEUE.md)
Sebagai Agent AI otonom di workspace ini, Anda memiliki kewajiban utama untuk memantau dan menyelesaikan antrean tugas:

Setiap kali Anda menerima giliran bicara, prompt, atau instruksi apapun dari USER di IDE Chat ini:
1. **LANGKAH 1 (PRIORITAS UTAMA - PERIKSA TASK_QUEUE.md):**
   - Periksa file `TASK_QUEUE.md` di root proyek.
   - Periksa apakah ada tugas aktif di bagian `## ⏳ Tugas Aktif`.
   - **ATURAN MUTLAK STATUS & EKSEKUSI SEKUENSIAL:**
     1. **Hak Mengubah ke `[IN_PROGRESS]`:**
        - HANYA AI Agent yang mengeksekusi tugas (yang benar-benar membaca `TASK_QUEUE.md` dan mulai bekerja) atau instruksi yang disalin/dimasukkan langsung ke IDE Chat yang berhak mengubah status menjadi `[IN_PROGRESS]`.
        - Server, worker, dan webhook sync TIDAK BERHAK mengubah status tugas baru menjadi `[IN_PROGRESS]`—tugas baru harus tetap `[PENDING]` (atau `[WAITING]`).
     2. **Pengerjaan Satu per Satu (Sekuensial Murni):**
        - Tugas WAJIB dikerjakan **satu per satu secara berurutan**.
        - **Selama masih ada tugas yang berstatus `[IN_PROGRESS]`:**
          👉 Tugas lain yang berstatus `[PENDING]`, `[WAITING]`, atau `[QUEUE]` **TIDAK BOLEH BERUBAH STATUSNYA DAN TIDAK BOLEH DIKERJAKAN**.
          👉 Selesaikan tugas yang sedang `[IN_PROGRESS]` tersebut sampai tuntas 100% terlebih dahulu.
     3. **Memulai Tugas Antrean Berikutnya:**
        - Jika TIDAK ADA tugas yang sedang `[IN_PROGRESS]` dan terdapat tugas `[PENDING]`:
          - Ambil tugas `[PENDING]` yang pertama.
          - Ubah statusnya menjadi `[IN_PROGRESS]` menggunakan:
            `node .vscode/tele-vs-code/tele-task.js start "<TASK-ID>"`
          - Terapkan perubahan kode yang diminta dan lakukan pengujian/verifikasi hingga tuntas 100%.
          - Tandai selesai menggunakan perintah:
            `node .vscode/tele-vs-code/tele-task.js done "<TASK-ID>" "<Ringkasan hasil 100% selesai>"`
            *(Perintah ini otomatis memperbarui status di server menjadi DONE, mencatat riwayat ke TASK_QUEUE.md, dan mengirim notifikasi SELESAI ke Telegram)*.
          - Baru setelah itu, lanjutkan ke tugas `[PENDING]` berikutnya (satu per satu).

2. **LANGKAH 2 (PENCATATAN PERINTAH BARU DARI USER):**
   - Jika tugas antrean sudah selesai atau tidak ada antrean aktif, dan user memberikan instruksi baru di chat ini:
     1. Catat perintah tersebut ke Dashboard Status (`https://biru.co.id/tele-vs-code/`) & `TASK_QUEUE.md` dengan status `[IN_PROGRESS]` menggunakan perintah:
        `node .vscode/tele-vs-code/tele-task.js add "<Perintah User>" --sender "IDE Chat" --status IN_PROGRESS`
     2. Kerjakan tugas sesuai instruksi hingga tuntas 100% dan terverifikasi.
     3. Setelah BENAR-BENAR SELESAI 100%, tandai tuntas:
        `node .vscode/tele-vs-code/tele-task.js done "<TASK-ID>" "<Ringkasan hasil 100% selesai>"`

3. **KATA KUNCI PEMICU INSTAN (TRIGGER KEYWORDS):**
   Jika user mengetik kata kunci singkat seperti:
   `proses antrian`, `kerjakan queue`, `next task`, `cek queue`, `/queue`, `/run`, atau `lanjutkan`:
   👉 Anda WAJIB langsung membaca `TASK_QUEUE.md`, mengambil tugas aktif berikutnya sesuai aturan sekuensial di atas, dan menyelesaikannya sampai tuntas.

---

## 📢 2. NOTIFIKASI TELEGRAM
Setiap kali Anda menyelesaikan suatu tugas atau jika menemukan kendala error yang memblokir proses, jalankan perintah terminal:
- **Jika Sukses (HANYA jika tugas benar-benar 100% selesai dan terverifikasi):**
  `node .vscode/tele-vs-code/tele-notify.js "<Judul Tugas>" --status SELESAI --detail "<Rincian 1>" --duration "<Durasi Pengerjaan>"`
  *(Catatan: Perintah `node .vscode/tele-vs-code/tele-task.js done "<TASK-ID>" "<Ringkasan hasil>"` otomatis menghitung durasi pengerjaan dari waktu mulai hingga selesai dan mencantumkannya ke TASK_QUEUE.md serta notifikasi Telegram).*
- **Jika Sedang Proses Pengerjaan (opsional untuk update progres):**
  `node .vscode/tele-vs-code/tele-notify.js "<Judul Tugas>" --status PROSES --detail "<Status progres pekerjaan saat ini>"`
- **Jika Menemukan Kendala Error:**
  `node .vscode/tele-vs-code/tele-notify.js "<Judul Kendala>" --status KENDALA --detail "<Penyebab>"`

> ⚠️ **ATURAN MUTLAK:** JANGAN PERNAH mengirimkan status `SELESAI` saat tugas baru dimulai atau sedang dikerjakan. Status `SELESAI` HANYA untuk pekerjaan yang sudah 100% tuntas dan teruji. Selalu cantumkan durasi pengerjaan pada setiap laporan selesai.

---

## 💾 3. MODE EKSEKUSI & PENYIMPANAN LANGSUNG (DIRECT SAVE)
Karena seluruh riwayat pekerjaan dapat di-undo melalui Git dan IDE:
- Langsung simpan (save) dan terapkan semua perubahan file, penambahan kode, dan pembuatan fitur tanpa perlu meminta konfirmasi berulang.
- Langsung jalankan pengujian/verifikasi hingga tuntas 100%.
- Commit hasil pekerjaan yang sudah selesai ke Git (kecuali jika ada instruksi khusus untuk tidak push ke git).

---

## 🎯 4. MULTI-AGENTS AI ROUTING & DELEGASI TUGAS
Dalam lingkungan kerja dengan beberapa AI Agent sekaligus (misal: Antigravity, Claude Code, Cursor, Copilot, Gemini CLI, Roo, Cline):

1. **Penugasan Spesifik oleh Pengguna:**
   - Pengguna dapat menentukan agent eksekutor langsung dari pesan Telegram menggunakan tanda mention:
     - `@antigravity <instruksi>` -> Ditugaskan ke Antigravity AI Agent
     - `@claude <instruksi>` -> Ditugaskan ke Claude Agent
     - `@copilot <instruksi>` -> Ditugaskan ke Copilot Agent
     - `@cursor <instruksi>` -> Ditugaskan ke Cursor Agent
     - `@gemini <instruksi>` -> Ditugaskan ke Gemini Agent
     - `@roo <instruksi>` / `@cline <instruksi>` -> Ditugaskan ke Roo/Cline
     - Atau menggunakan opsi `--agent <nama_agent>`
   - Eksekutor yang dipilih akan tercatat pada field `- **Agent:**` di `TASK_QUEUE.md` dan tampil pada notifikasi Telegram & Dashboard.

2. **Aturan Eksekusi Multi-Agent & Solusi Tanpa CLI (Agent IDE Mode):**
   - Jika field `- **Agent:**` menunjuk ke Agent Anda secara spesifik (atau jika default `Antigravity AI Agent`, `Agent IDE`, atau tidak ditentukan): Anda WAJIB langsung mengeksekusinya.
   - **SOLUSI JIKA CLI AGENTS TIDAK TERPASANG:**
     - Jika CLI agents (`agy`, `claude`, `gemini`, dll.) belum terpasang di sistem pengguna:
       1. Sistem Tele-vs-Code menyediakan handler resmi `Agent IDE` (`telegramNotifier.agentHandler: "ide"`).
       2. Jika handler disetel ke `auto` dan sistem mendeteksi tidak ada CLI agent di PATH, Tele-vs-Code otomatis melakukan fallback ke `Agent IDE`.
       3. Tele-vs-Code secara otomatis menyalin prompt resmi tugas ke clipboard dan membuka jendela Chat IDE (`workbench.action.chat.open`). Pengguna cukup menempelkan prompt (`Ctrl+V`) ke Chat IDE.
       4. Agent IDE yang membaca prompt pemicu (`baca TASK_QUEUE.md secara berkala...`) wajib mendaftarkan task scheduler berkala (cron daemon) sehingga seluruh antrean berikutnya dikerjakan secara otonom tanpa memerlukan binary CLI eksternal.
   - Jika field `- **Agent:**` menunjuk ke Agent lain (misal `Claude Agent` sementara Anda adalah `Antigravity`):
     - Biarkan agent tersebut mengeksekusinya jika sesi kerjanya sedang aktif di terminal/workspace.
     - Jika pengguna secara eksplisit meminta Anda di chat ini untuk menangani antrean ("handle task queue", "proses antrian"), Anda dapat mengambil alih (fallback executor) dan menuntaskannya hingga 100% selesai.

---

## ⏰ 5. CRON & AUTO-SCHEDULE SYSTEM PADA AI AGENT
Ketika sebuah tugas ditujukan ke Agent (misal `Antigravity AI Agent` atau `@agent`):

1. **Pemicu Cron Otomatis pada Agent:**
   - Setiap kali Agent aktif di sesi workspace, Agent WAJIB mengaktifkan pemantauan berkala (*autonomous background schedule / cron daemon*) menggunakan fasilitas scheduler yang tersedia (misal: tool `schedule` dengan `CronExpression: "*/3 * * * *"` atau timer loop).
   - Melalui mekanisme cron ini, Agent secara otomatis terbangun secara berkala untuk membaca `TASK_QUEUE.md` dan langsung mengeksekusi antrean tugas yang masuk secara berurutan hingga tuntas 100%, **tanpa menunggu prompt manual dari pengguna di IDE Chat**.
   - Ekstensi VS Code Tele-vs-Code menyediakan konfigurasi interval waktu berkala yang dapat disesuaikan (default: 3 menit, rentang 1-60m) melalui setting `telegramNotifier.agentCronInterval` atau perintah `telegramNotifier.setAgentCronInterval`, serta tersimpan di `config.env` (`TELE_AGENT_CRON_INTERVAL`).
   - Hal ini menjamin pengguna yang berada di luar ruangan (hanya menggunakan Telegram di HP) tetap mendapatkan eksekusi coding yang cepat dan laporan penyelesaian tugas yang akurat.

2. **Dukungan Perintah Jadwal dari Telegram:**
   - Pengguna dapat membuat tugas berkala dari Telegram menggunakan format:
     - `/cron <instruksi>` atau `/schedule <instruksi>`
     - Contoh: `/cron @antigravity cek error log setiap 5 menit` atau `/schedule @antigravity backup database`
   - Agent yang menerima tugas tersebut wajib mendaftarkan task scheduler dan melaporkan statusnya ke Telegram.

3. **Format Prompt Pemicu Tugas Berkala:**
   - Prompt resmi dari ekstensi untuk mengaktifkan pemantauan berkala pada AI Agent adalah:
     👉 `"baca TASK_QUEUE.md secara berkala, buat task berkala untuk membaca dan kerjakan yang berstatus waiting satu persatu"`
   - Saat AI Agent menerima prompt ini (baik melalui terminal runner, clipboard paste, atau chat IDE), Agent WAJIB langsung:
     1. Mendaftarkan background schedule/timer (misal tool `schedule` dengan `CronExpression: "*/3 * * * *"` atau timer loop berulang).
     2. Membaca `TASK_QUEUE.md` secara berkala.
     3. Mengambil dan mengeksekusi tugas berstatus `[PENDING]` / `[WAITING]` satu per satu secara sekuensial hingga 100% selesai.

---

## 🔍 6. STRATEGI PENCARIAN DUA ARAH (SELAIN CARI DI LOKAL, CARI JUGA DI REMOTE)
Dalam ekosistem Tele-vs-Code, baik sistem ekstensi, runner CLI, maupun AI Agent wajib menerapkan prinsip pencarian dua arah (Dual-Search Strategy):

1. **Pencarian Antrean Tugas & Instruksi:**
   - Saat memeriksa antrean tugas berikutnya atau mencari instruksi spesifik dari suatu Task ID:
     👉 **Langkah 1:** Cari terlebih dahulu di berkas lokal `TASK_QUEUE.md`.
     👉 **Langkah 2 (Pencarian Remote):** Jika tugas atau instruksi belum ditemukan di lokal, sistem WAJIB mencari ke Server Remote Biru (`https://biru.co.id/tele-vs-code/?action=get_task&id=<TASK-ID>` atau `action=pull`).
     👉 **Langkah 3 (Sinkronisasi Otomatis):** Data tugas dari remote otomatis disinkronkan ke `TASK_QUEUE.md` lokal dengan status yang sesuai (`[PENDING]` atau `[IN_PROGRESS]`).

2. **Pencarian AI Agent & Binary Executor (findCli):**
   - Selain mencari di lingkungan instalasi lokal pengguna (`PATH`, `AppData`, `~/.local/bin`), sistem juga mencari di remote environments (Remote SSH, WSL, Dev Containers, snap, homebrew, nvm node binaries, dll.).
   - Jika binary CLI tidak ditemukan di lokal maupun remote, sistem secara cerdas melakukan fallback ke **Agent IDE** yang langsung mengeksekusi di jendela Chat IDE.

3. **Pencarian Lampiran & Media Dokumentasi:**
   - Selain mencari file lampiran di folder lokal (`attachments/<TASK-ID>/`), jika file fisik belum ada di disk lokal, unduh atau arahkan langsung ke tautan server remote Biru (`https://biru.co.id/tele-vs-code/uploads/...`).

---

## 🖥️ 7. PERTIMBANGAN INSTALASI VSIX DI SERVER (REMOTE SSH / LINUX / HEADLESS)
Saat ekstensi Tele-vs-Code (VSIX) diinstal dan dijalankan di lingkungan server (Remote SSH, WSL, Dev Containers, code-server, openvscode-server, atau Linux VPS headless):

1. **Dukungan Multi-Binary Server:**
   - Skrip instalasi (`install-vsix.js` / `install-vsix.sh`) secara cerdas mendeteksi varian binary editor di server:
     - `code`, `code-server`, `openvscode-server`, `cursor`, `codium`, `vscodium`
     - Path server Linux standar: `/usr/bin/code-server`, `/usr/local/bin/code-server`, `/snap/bin/code`, serta instalasi internal `~/.vscode-server/bin/*/bin/code-server`.
   - Hal ini memastikan paket VSIX dapat diinstal dengan mulus baik di desktop lokal maupun di server remote.

2. **Eksekusi Sisi Server (Workspace Extension Kind):**
   - Konfigurasi `extensionKind: ["workspace", "ui"]` memastikan bahwa saat pengguna terhubung ke Remote SSH, Tele-vs-Code berjalan langsung di sisi host server remote tempat workspace dan berkas `TASK_QUEUE.md` berada, bukan di laptop/klien lokal.

3. **Izin Eksekusi Skrip Runner di Linux Server (chmod +x):**
   - Saat inisialisasi berkas di server Linux/macOS, seluruh skrip tools di `.vscode/tele-vs-code/` (`tele-worker`, `tele-notify`, `tele-task`, `*.sh`) otomatis disetel dengan izin eksekusi `chmod 755` (`0o755`) agar dapat dieksekusi oleh AI Agent maupun terminal runner tanpa kendala *permission denied*.

4. **Resiliensi Headless & Non-Intrusive Notifications:**
   - Di server headless tanpa tampilan GUI:
     - Error koneksi, webhook timeout, atau server offline TIDAK PERNAH memunculkan pop-up modal yang memblokir, melainkan tampil sebagai indikator di status bar dan tercatat rapi di log output.
     - Auto-resolve proyek otomatis mengaitkan Project Key tanpa memaksa interaksi GUI wizard.

5. **Background Worker & Cron Scheduler Mandiri di Server:**
   - Pemantauan antrean di server remote dapat berjalan secara mandiri dan persisten menggunakan:
     - Fitur autonomous cron daemon internal AI Agent (misal tool `schedule` dengan interval 3 menit).
     - Atau background CLI worker: `nohup node .vscode/tele-vs-code/tele-worker.js > tele-worker.log 2>&1 &` atau via `tmux` / `systemd`.

---

## 🛑 8. ATURAN TUGAS BERKENDALA (ANTI RE-EXECUTION LOOP)
Tugas yang mengalami kendala teknis, error, atau gagal dieksekusi **DILARANG KERAS** dieksekusi berulang-ulang oleh automated background workers, polling routines, atau AI Agent:

1. **Penanganan Otomatis Saat Terjadi Kendala/Error:**
   - Ketika proses eksekusi kode, tool, atau runner menemukan error fatal atau exception yang memblokir:
     👉 Segera tandai tugas dengan status `[FAILED]` (atau `KENDALA`).
     👉 Pindahkan blok tugas dari `## ⏳ Tugas Aktif` ke bagian `## ✅ Riwayat Tugas Selesai` di `TASK_QUEUE.md` dengan penanda `[FAILED]`.
     👉 Kirimkan laporan status kendala ke Telegram hanya satu kali via `tele-notify.js "<Judul>" --status KENDALA --detail "<Penyebab>"`.
     👉 Sinkronkan status `FAILED` ke server remote agar antrean tidak lagi ditarik (`api_failed`).

2. **Larangan Eksekusi Berulang (Anti-Looping Guard):**
   - Runner berkala (`tele-worker.js`, background cron, dan interval sync ekstensi) WAJIB memfilter dan melompati setiap tugas yang berstatus `FAILED`, `KENDALA`, `ERROR`, atau `GAGAL`.
   - Perintah `tele-task start "<TASK-ID>"` secara ketat memblokir eksekusi tugas yang telah ditandai `[FAILED]` / `[KENDALA]`.
   - Eksekusi ulang tugas berkendala HANYA dimungkinkan jika:
     a) Pengguna memberikan instruksi perbaikan baru dari Telegram atau Chat IDE (membuat Task ID baru), ATAU
     b) Pengguna/Agent menyertakan flag paksa `--force` secara eksplisit (`node .vscode/tele-vs-code/tele-task.js start "<TASK-ID>" --force`).
