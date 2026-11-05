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
          - **Lanjutkan ke tugas `[PENDING]` berikutnya satu per satu secara berurutan sampai SELURUH tugas di `## ⏳ Tugas Aktif` tuntas 100%.**
          - **Setelah tidak ada lagi tugas aktif di `TASK_QUEUE.md`, AI Agent langsung kembali ke mode standby (Smart Idle Mode) tanpa membuat cron loop atau timer di IDE.**

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
       4. Agent IDE yang menerima prompt tugas langsung mengeksekusi tugas aktif yang diberikan hingga tuntas 100%, lalu kembali ke mode standby tanpa membuat background loop.
   - Jika field `- **Agent:**` menunjuk ke Agent lain (misal `Claude Agent` sementara Anda adalah `Antigravity`):
     - Biarkan agent tersebut mengeksekusinya jika sesi kerjanya sedang aktif di terminal/workspace.
     - Jika pengguna secara eksplisit meminta Anda di chat ini untuk menangani antrean ("handle task queue", "proses antrian"), Anda dapat mengambil alih (fallback executor) dan menuntaskannya hingga 100% selesai.

---

## ⏰ 5. ARSITEKTUR ZERO-TOKEN POLLING & PERINGATAN CRON BERKALA
Dalam ekosistem Tele-vs-Code, pemantauan antrean tugas dirancang hemat kuota (Zero-Token Architecture):

1. **Larangan AI Agent Polling Loop (Pencegahan Pemborosan Kuota):**
   - AI Agent (LLM) **DILARANG KERAS** mendaftarkan background cron/timer berkala (misal loop setiap 1-5 menit) hanya untuk memeriksa apakah ada tugas baru di `TASK_QUEUE.md`.
   - Menjalankan cron loop pada LLM akan memproses ulang seluruh System Prompt, riwayat chat, dan tools definition di setiap siklus, yang dapat **menguras puluhan juta token per hari meskipun tidak ada tugas aktif**.
   - Model kerja AI Agent adalah murni **Event-Driven / On-Demand**: Agent hanya dieksekusi saat ada instruksi atau tugas nyata yang dipicu.

2. **Pendelegasian Polling ke Worker Lokal (Gratis 0 Token):**
   - Pemantauan berkala terhadap server remote dan `TASK_QUEUE.md` sepenuhnya dijalankan oleh **Ekstensi VS Code** atau **Node.js Worker (`tele-worker.js`)** menggunakan proses background lokal.
   - Polling lokal ini berjalan di CPU pengguna dan koneksi HTTP biasa dengan biaya **0 token / Rp 0 (100% Gratis)**.
   - Ketika worker lokal mendeteksi adanya tugas baru berstatus `[PENDING]`, barulah worker/ekstensi mendelegasikan tugas tersebut ke AI Agent secara terarah.

3. **Peringatan Wajib untuk Tugas Berkala (`/cron` / `/schedule`):**
   - Jika pengguna meminta penjadwalan berkala (`/cron` atau `/schedule`), sistem dan AI Agent WAJIB memberikan peringatan:
     > ⚠️ **PERINGATAN KUOTA TOKEN:** Menjalankan tugas AI Agent secara berkala (cron loop) akan menguras kuota kredit token secara masif karena setiap iterasi memproses ulang seluruh konteks chat. Disarankan untuk memperbesar interval (misal harian atau per jam) atau menggunakan task script lokal otomatis.

4. **Format Prompt Resmi Tanpa Looping:**
   - Prompt resmi dari ekstensi untuk AI Agent adalah:
     👉 `"Periksa file TASK_QUEUE.md dan selesaikan tugas <TASK-ID> sampai tuntas 100% sesuai panduan AGENTS.md."`
   - Prompt TIDAK LAGI menyertakan instruksi pembuatan cron/task berkala sehingga kuota pengguna terlindungi 100%.

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

---

## 🛡️ 9. KEAMANAN SISTEM & PENCEGAHAN PERINTAH BERBAHAYA (SECURITY GUARD)
Perintah sistem yang berisiko tinggi atau destruktif **DILARANG KERAS** dieksekusi secara sembarangan:

1. **Perintah Berbahaya yang Dicegah:**
   - Perintah sistem level rendah seperti `reboot`, `shutdown`, `poweroff`, `halt`, `init 0/6`, `Restart-Computer`, `systemctl reboot`.
   - Perintah shell/terminal yang tidak terotorisasi (`cmd:`, `shell:`, `run:`, `exec:`, `bash:`, `powershell:`).
   - Perintah destruktif file sistem (`rm -rf /`, `rmdir /s /q c:\`, `format`, `mkfs`, `dd if=`, fork bomb `:(){ :|:& };:`, `taskkill /f`).

2. **Hak Eksekusi Eksklusif (Hanya Akun `iam@usm.rip`):**
   - HANYA akun terverifikasi (`iam@usm.rip`) yang berhak mengeksekusi perintah sistem berbahaya atau shell.
   - Jika instruksi diajukan oleh akun/proyek selain `iam@usm.rip`, sistem (baik server, ekstensi, worker, maupun AI Agent) WAJIB menolak eksekusi secara langsung (*Access Denied*).

3. **Pencegahan Terenkripsi (Anti-Public Inspection):**
   - Pola dan aturan pencegahan perintah berbahaya dienkripsi secara kriptografis di sistem agar daftar kata kunci dan pola proteksi tidak dapat dibaca, dipelajari, atau dieksploitasi oleh publik di repository terbuka.

---

## ⚡ 10. ATURAN HEMAT KUOTA & PENCEGAHAN PERINTAH BERULANG (QUOTA GUARD)
Untuk menghemat pemakaian kuota token AI Agent, API, dan sumber daya sistem:

1. **Deduplikasi Perintah Otomatis (Server & Extension Level):**
   - Perintah/instruksi yang sama persis tidak akan dimasukkan ke antrean tugas ganda apabila tugas serupa masih berstatus `[PENDING]`, `[WAITING]`, atau `[IN_PROGRESS]`.
   - Diterapkan jeda pengaman (*cooldown window* 60 detik) untuk tugas yang baru selesai (`[DONE]`) guna mencegah spam klik ganda dari Telegram.
   - Pemicu agent di VS Code extension (`executeTaskWithAgent`) dilengkapi cache deduplikasi sehingga tugas atau instruksi yang sama tidak di-dispatch berulang kali ke terminal/Chat IDE dalam kurun waktu 60 detik.

2. **Smart Idle Mode pada Pemantauan Berkala (Zero-Token Consumption):**
   - Saat AI Agent dibangunkan oleh autonomous cron/scheduler berkala:
     - Jika bagian `## ⏳ Tugas Aktif` kosong (tidak ada tugas `[PENDING]` atau `[WAITING]`):
       👉 AI Agent WAJIB langsung kembali ke kondisi idle (standby) dengan output seringkas mungkin tanpa memanggil tool yang tidak perlu atau menghasilkan token panjang yang memboroskan kuota.
     - JANGAN PERNAH menjalankan serangkaian tool pencarian atau inspeksi file berulang jika tidak ada antrean tugas baru yang masuk.

3. **Penyelesaian Sekuensial Menjamin Efisiensi:**
   - Fokus 100% pada satu tugas aktif hingga selesai sebelum beralih ke tugas berikutnya.
   - Setiap tugas yang telah tuntas langsung dipindahkan ke riwayat `[DONE]` agar tidak pernah diproses kembali oleh worker maupun agent.

