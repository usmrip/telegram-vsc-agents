# 🤖 Tele-vs-Code (Telegram VSC Agents)

> **Telegram sebagai pesuruh VS Code dari mana aja**  
> Delegasikan instruksi, kontrol otomatisasi coding AI Agent, dan pantau hasil pekerjaan di Visual Studio Code secara langsung melalui aplikasi Telegram Anda di HP/Laptop kapan saja dan di mana saja.

[![GitHub Repo](https://img.shields.io/badge/GitHub-usmrip%2Ftelegram--vsc--agents-blue?logo=github)](https://github.com/usmrip/telegram-vsc-agents)
[![Dashboard Server](https://img.shields.io/badge/Server-biru.co.id%2Ftele--vs--code-success)](https://biru.co.id/tele-vs-code/)

---

## ⚡ Mengapa Tele-vs-Code?

Bayangkan Anda sedang di luar rumah, di jalan, atau sedang bersantai, dan Anda ingin AI Agent di Visual Studio Code (seperti Claude Code, Antigravity, Roo Code, Cursor, dll.) menyelesaikan pekerjaan:

1. 💬 **Kirim Perintah via Telegram:** Cukup ketik instruksi di bot/grup Telegram Anda, misalnya:
   ```text
   buat fitur auth login dengan jwt
   ```
2. 🔄 **Diterima Otomatis oleh VS Code:** Ekstensi Tele-vs-Code langsung menarik tugas tersebut ke `TASK_QUEUE.md` di workspace proyek Anda.
3. 🦾 **Otomasi Mandiri (Autonomous Worker):** Agent membaca dan mengeksekusi tugas secara otomatis hingga tuntas 100%.
4. 🔔 **Laporan Balasan Realtime:** Begitu selesai, bot Telegram otomatis membalas Anda dengan status `✅ SELESAI` lengkap beserta rincian hasil pekerjaan dan commit git-nya!

---

## ⚙️ Cara Menghubungkan ke Server Biru (Setup 1-Menit)

1. Dapatkan **API Secret Key** gratis dan daftarkan Bot Telegram Anda di dashboard:  
   👉 **[https://biru.co.id/tele-vs-code/](https://biru.co.id/tele-vs-code/)**
2. Buat proyek baru di dashboard dan salin **Project Key** unik proyek Anda (`prj_xxxxxxxxxx`).
3. Di VS Code, klik status bar **`$(radio-tower) Tele-vs-Code (Pilih Proyek)`** di pojok kanan bawah, atau tekan `F1` lalu pilih **Tele-vs-Code: Hubungkan ke Server Biru (Setup Wizard)**.
4. Masukkan:
   - **AGENTS_TELE_SECRET:** Kunci API Secret Anda.
   - **Project Key:** Pilih dari daftar proyek yang otomatis diambil dari server, atau masukkan manual.
5. Selesai! Workspace Anda kini terhubung dan status bar akan menampilkan:
   ```
   ((·)) Tele-vs-Code [Nama Project]
   ```

---

## 🎮 Perintah Telegram Bot

Di grup atau private chat bot Telegram Anda:
- `/task <instruksi>` — Menambahkan instruksi tugas baru ke antrean workspace VS Code.
- `/status` — Menampilkan status antrean tugas saat ini.
- `/projects` — Menampilkan daftar proyek yang Anda miliki.
- `/use <nama_proyek>` — Beralih ke proyek tertentu.
- `/ping` — Uji koneksi bot & server.
- `/help` — Panduan lengkap daftar perintah.

---

## 🔒 Privasi & Keamanan Data
- Seluruh kredensial (`config.env`, bot token, chat ID, secret key) disimpan lokal dan berada di dalam `.gitignore`.
- Tidak ada data kode rahasia proyek yang dibagikan ke server publik selain judul instruksi tugas dan status laporan.

---

## 👨‍💻 Kontributor & Lisensi
Dibuat dengan ❤️ oleh **[usmrip](https://github.com/usmrip)** & **Biru Indonesia Creative**.  
Lisensi: [MIT](https://github.com/usmrip/telegram-vsc-agents/blob/main/LICENSE).
