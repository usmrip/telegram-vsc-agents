## NOTIFIKASI TELEGRAM
Setiap kali Anda menyelesaikan suatu tugas atau jika menemukan kendala error yang memblokir proses, jalankan perintah terminal:
- Jika sukses (HANYA jika tugas benar-benar 100% selesai dan terverifikasi):
  tele-notify "<Judul Tugas>" --status SELESAI --detail "<Rincian 1>" --detail "<Rincian 2>"
- Jika sedang dalam proses pengerjaan (opsional untuk update progres):
  tele-notify "<Judul Tugas>" --status PROSES --detail "<Status progres pekerjaan saat ini>"
- Jika kendala:
  tele-notify "<Judul Kendala>" --status KENDALA --detail "<Penyebab>"

> ⚠️ **ATURAN MUTLAK:** JANGAN PERNAH mengirimkan status `SELESAI` saat tugas baru dimulai atau sedang dikerjakan. Status `SELESAI` HANYA untuk pekerjaan yang sudah 100% tuntas dan teruji.

## PENCATATAN PERINTAH IDE CHAT KE DASHBOARD
Setiap kali Anda menerima perintah baru dari USER di IDE Chat ini:
1. Catat perintah tersebut ke Dashboard Status (`https://biru.co.id/tele-vs-code/`) & `TASK_QUEUE.md` dengan status `[IN_PROGRESS]` menggunakan perintah:
   `tele-task add "<Perintah User>" --sender "IDE Chat" --status IN_PROGRESS`
2. Kerjakan tugas sesuai instruksi hingga tuntas 100% dan terverifikasi.
3. Setelah BENAR-BENAR SELESAI 100%:
   - Tandai selesai menggunakan perintah:
     `tele-task done "<TASK-ID>" "<Ringkasan hasil 100% selesai>"`
     *(Perintah ini otomatis mengupdate status di dashboard server ke DONE, mengupdate TASK_QUEUE.md, dan mengirim notifikasi SELESAI ke Telegram)*.

## ANTREAN TUGAS TELEGRAM (TASK_QUEUE.md)
Jika terdapat file TASK_QUEUE.md di root proyek, periksa tugas berstatus [PENDING] atau [IN_PROGRESS]:
1. Ubah status menjadi [IN_PROGRESS] saat mulai mengerjakan.
2. Kerjakan tugas sesuai instruksi yang tercantum sampai selesai dan teruji.
3. Setelah BENAR-BENAR SELESAI 100%:
   - Ubah status menjadi [DONE] (atau [FAILED] jika gagal).
   - Catat ringkasan perubahannya di TASK_QUEUE.md.
   - Perbarui status di server webhook (jika terhubung).
   - Kirimkan notifikasi ke Telegram dengan status SELESAI menggunakan `tele-notify`.

## MODE EKSEKUSI & PENYIMPANAN LANGSUNG (DIRECT SAVE)
Karena seluruh riwayat pekerjaan dapat di-undo melalui Git dan IDE:
- Langsung simpan (save) dan terapkan semua perubahan file, penambahan kode, dan pembuatan fitur tanpa perlu meminta konfirmasi berulang.
- Langsung jalankan pengujian/verifikasi hingga tuntas 100%.
- Commit hasil pekerjaan yang sudah selesai ke Git.
