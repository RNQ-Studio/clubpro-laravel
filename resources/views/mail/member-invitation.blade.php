Undangan {{ $invitation->kind === 'guardian' ? 'wali' : 'pengaitan akun' }} untuk {{ $invitation->identity->name }}.
Masuk dengan email penerima yang telah diverifikasi. Buka menu Undangan di aplikasi ClubPro dan masukkan kode berikut:
{{ $claimToken }}
Undangan berlaku 24 jam dan hanya dapat digunakan satu kali. Abaikan bila Anda tidak mengenali anggota ini.
