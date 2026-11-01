# 🤖 PANDUAN KERJA OTOMATIS AI AGENT (Tele-vs-Code)

## ⚡ 1. MANDATORY TRIGGER: EKSEKUSI OTOMATIS ANTREAN TUGAS (TASK_QUEUE.md)
Sebagai Agent AI otonom di workspace ini, Anda memiliki kewajiban utama untuk memantau dan menyelesaikan antrean tugas:

Setiap kali Anda menerima giliran bicara, prompt, atau instruksi apapun dari USER di IDE Chat ini:
1. **LANGKAH 1 (PRIORITAS UTAMA - PERIKSA TASK_QUEUE.md):**
   - Periksa file `TASK_QUEUE.md` di root proyek.
   - Jika terdapat tugas berstatus `[PENDING]` atau `[IN_PROGRESS]` (terutama tugas masuk dari Telegram):
     👉 **ANDA WAJIB OTOMATIS MENGEKSEKUSI TUGAS TERSEBUT SAMPAI SELESAI 100%!**
     - Jangan menunggu diminta secara khusus.
     - Ubah status menjadi `[IN_PROGRESS]` saat mulai dikerjakan.
     - Terapkan perubahan kode yang diminta dan lakukan pengujian/verifikasi.
     - Setelah tuntas 100%, tandai selesai menggunakan perintah:
       `node .vscode/tele-vs-code/tele-task.js done "<TASK-ID>" "<Ringkasan hasil 100% selesai>"`
       *(Perintah ini otomatis memperbarui status di dashboard server menjadi DONE, mencatat riwayat ke TASK_QUEUE.md, dan mengirim notifikasi SELESAI ke Telegram)*.
     - Jika terdapat beberapa antrean, proses satu per satu secara berurutan.

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
   👉 Anda WAJIB langsung membaca `TASK_QUEUE.md`, mengambil tugas aktif berikutnya, dan menyelesaikannya sampai tuntas.

---

## 📢 2. NOTIFIKASI TELEGRAM
Setiap kali Anda menyelesaikan suatu tugas atau jika menemukan kendala error yang memblokir proses, jalankan perintah terminal:
- **Jika Sukses (HANYA jika tugas benar-benar 100% selesai dan terverifikasi):**
  `node .vscode/tele-vs-code/tele-notify.js "<Judul Tugas>" --status SELESAI --detail "<Rincian 1>" --detail "<Rincian 2>"`
- **Jika Sedang Proses Pengerjaan (opsional untuk update progres):**
  `node .vscode/tele-vs-code/tele-notify.js "<Judul Tugas>" --status PROSES --detail "<Status progres pekerjaan saat ini>"`
- **Jika Menemukan Kendala Error:**
  `node .vscode/tele-vs-code/tele-notify.js "<Judul Kendala>" --status KENDALA --detail "<Penyebab>"`

> ⚠️ **ATURAN MUTLAK:** JANGAN PERNAH mengirimkan status `SELESAI` saat tugas baru dimulai atau sedang dikerjakan. Status `SELESAI` HANYA untuk pekerjaan yang sudah 100% tuntas dan teruji.

---

## 💾 3. MODE EKSEKUSI & PENYIMPANAN LANGSUNG (DIRECT SAVE)
Karena seluruh riwayat pekerjaan dapat di-undo melalui Git dan IDE:
- Langsung simpan (save) dan terapkan semua perubahan file, penambahan kode, dan pembuatan fitur tanpa perlu meminta konfirmasi berulang.
- Langsung jalankan pengujian/verifikasi hingga tuntas 100%.
- Commit hasil pekerjaan yang sudah selesai ke Git (kecuali jika ada instruksi khusus untuk tidak push ke git).
