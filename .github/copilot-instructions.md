# 🤖 PANDUAN KERJA GITHUB COPILOT CHAT (Tele-vs-Code)

> **MANDATORY INSTRUCTION FOR GITHUB COPILOT:**
> Every time the user gives you a command or instruction in Copilot Chat:
> 1. **FIRST (BEFORE CODING):** You MUST record the command to the Tele-vs-Code Dashboard & Server by running:
>    `node .vscode/tele-vs-code/tele-task.js add "<User Command>" --sender "Copilot Chat" --status IN_PROGRESS`
> 2. Implement all necessary changes and verify them thoroughly.
> 3. **WHEN FINISHED (100% DONE):** You MUST mark it done by running:
>    `node .vscode/tele-vs-code/tele-task.js done "<TASK-ID>" "<100% completed summary>"`

---

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
     3. **Memulai Tugas Antrean Berikutnya & Filter Afinitas Agent (Strict Agent Affinity):**
        - Jika TIDAK ADA tugas yang sedang `[IN_PROGRESS]` dan terdapat tugas `[PENDING]`:
          - Periksa field `- **Agent:**` pada tugas `[PENDING]` pertama:
            👉 **HANYA ambil dan kerjakan jika tugas tersebut DITUJUKAN UNTUK COPILOT** (misal `- **Agent:** Copilot Agent`, `GitHub Copilot Agent`, `Agent IDE`, atau tidak ditentukan).
            👉 **JIKA TUGAS DITUJUKAN UNTUK AGENT LAIN (misal `- **Agent:** Antigravity AI Agent`, `Claude Agent`, `Gemini Agent`, `Cursor Agent`, dll.):**
               ⛔ **DILARANG KERAS MENGUBAH STATUSNYA MENJADI `[IN_PROGRESS]` ATAU MENGEKSEKUSINYA!**
               ⛔ **DILARANG MENGAMBIL ALIH TUGAS MILIK AGENT LAIN KECUALI USER EKSPLISIT MEMERINTAHKAN REBUT DI CHAT INI!**
               👉 Biarkan tugas tersebut tetap berstatus `[PENDING]` agar dieksekusi oleh Agent yang bersangkutan.
               👉 Anda WAJIB langsung berhenti (*STOP*) dan kembali ke mode standby (*Smart Idle*). Laporkan secara ringkas bahwa tugas aktif berikutnya di antrean diperuntukkan bagi Agent tersebut.
          - Jika tugas tersebut memang ditujukan untuk Anda:
            - Ubah statusnya menjadi `[IN_PROGRESS]` menggunakan:
              `node .vscode/tele-vs-code/tele-task.js start "<TASK-ID>"`
            - Terapkan perubahan kode yang diminta dan lakukan pengujian/verifikasi hingga tuntas 100%.
            - Tandai selesai menggunakan perintah:
              `node .vscode/tele-vs-code/tele-task.js done "<TASK-ID>" "<Ringkasan hasil 100% selesai>"`
              *(Perintah ini otomatis memperbarui status di server menjadi DONE, mencatat riwayat ke TASK_QUEUE.md, dan mengirim notifikasi SELESAI ke Telegram)*.
            - Lanjutkan ke tugas `[PENDING]` berikutnya HANYA jika tugas berikutnya juga ditujukan untuk Anda, sampai seluruh tugas milik Anda selesai atau antrean berikutnya milik agen lain.
            - Setelah tidak ada lagi tugas aktif milik Anda di `TASK_QUEUE.md`, AI Agent langsung kembali ke mode standby (Smart Idle Mode) tanpa membuat cron loop atau timer di IDE.

2. **LANGKAH 2 (PENCATATAN PERINTAH BARU DARI USER):**
   - Jika tugas antrean sudah selesai atau tidak ada antrean aktif, dan user memberikan instruksi baru di chat ini:
     1. Catat perintah tersebut ke Dashboard Status (`https://biru.co.id/tele-vs-code/`) & `TASK_QUEUE.md` dengan status `[IN_PROGRESS]` menggunakan perintah:
        `node .vscode/tele-vs-code/tele-task.js add "<Perintah User>" --sender "Copilot Chat" --status IN_PROGRESS`
     2. Kerjakan tugas sesuai instruksi hingga tuntas 100% dan terverifikasi.
     3. Setelah BENAR-BENAR SELESAI 100%, tandai tuntas:
        `node .vscode/tele-vs-code/tele-task.js done "<TASK-ID>" "<Ringkasan hasil 100% selesai>"`

3. **KATA KUNCI PEMICU INSTAN (TRIGGER KEYWORDS):**
   Jika user mengetik kata kunci singkat seperti:
   `proses antrian`, `kerjakan queue`, `next task`, `cek queue`, `/queue`, `/run`, atau `lanjutkan`:
   👉 Anda WAJIB langsung membaca `TASK_QUEUE.md`, mengambil tugas aktif berikutnya sesuai aturan sekuensial dan afinitas agen di atas, dan menyelesaikannya sampai tuntas.
