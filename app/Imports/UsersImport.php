<?php

namespace App\Imports;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\RemembersRowNumber;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

/**
 * CONTOH FORMAT FILE EXCEL YANG BENAR UNTUK IMPORT:
 *
 * | name         | email                  |
 * |--------------|------------------------|
 * | Anggota Satu | satu@anaklangit.manahpro.web.id |
 * | Anggota Dua  | dua@anaklangit.manahpro.web.id  |
 *
 * Catatan:
 * - Baris pertama harus berupa header dengan nama kolom 'name' dan 'email'.
 * - Kolom 'name' wajib diisi, berupa string dengan maksimal 255 karakter.
 * - Kolom 'email' wajib diisi, berformat email valid, dengan maksimal 255 karakter.
 */
class UsersImport implements SkipsOnError, SkipsOnFailure, ToModel, WithHeadingRow, WithValidation
{
    use RemembersRowNumber;

    private int $totalRows = 0;

    private int $importedCount = 0;

    private int $skippedCount = 0;

    private array $skippedRows = [];

    /**
     * Memproses setiap baris data Excel yang valid.
     * Melakukan upsert berdasarkan email.
     *
     * @return Model|null
     */
    public function model(array $row)
    {
        $this->totalRows++;
        $rowNumber = $this->getRowNumber();

        try {
            $email = mb_strtolower(trim($row['email']));
            $matches = User::query()->whereRaw('lower(email) = ?', [$email])->limit(2)->get();
            if ($matches->count() > 1) {
                throw new \RuntimeException('Identitas email ambigu.');
            }
            $user = $matches->first();

            if ($user) {
                // Jika email sudah ada -> UPDATE name
                $user->update([
                    'name' => $row['name'],
                ]);
            } else {
                // Imported accounts use recovery/enrollment; never ship a shared credential.
                User::create([
                    'name' => $row['name'],
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                    'is_active' => true,
                ]);
            }

            $this->importedCount++;
        } catch (Throwable $e) {
            $this->skippedCount++;
            $this->skippedRows[] = [
                'row' => $rowNumber,
                'message' => 'Gagal memproses baris. Periksa data dan coba kembali.',
            ];
        }

        return null;
    }

    /**
     * Aturan validasi untuk setiap baris data Excel.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ];
    }

    /**
     * Pesan validasi kustom dalam bahasa Indonesia.
     */
    public function customValidationMessages(): array
    {
        return [
            'name.required' => 'Name wajib diisi',
            'name.string' => 'Name harus berupa teks',
            'name.max' => 'Name maksimal 255 karakter',
            'email.required' => 'Email wajib diisi',
            'email.email' => 'Email tidak valid',
            'email.max' => 'Email maksimal 255 karakter',
        ];
    }

    /**
     * Menangani kegagalan validasi baris.
     */
    public function onFailure(Failure ...$failures)
    {
        $failedRows = [];

        foreach ($failures as $failure) {
            $rowNum = $failure->row();
            if (! isset($failedRows[$rowNum])) {
                $failedRows[$rowNum] = [];
            }
            foreach ($failure->errors() as $error) {
                $failedRows[$rowNum][] = $error;
            }
        }

        foreach ($failedRows as $rowNum => $messages) {
            $this->totalRows++;
            $this->skippedCount++;
            $this->skippedRows[] = [
                'row' => $rowNum,
                'message' => implode(', ', $messages),
            ];
        }
    }

    /**
     * Menangani error tak terduga (misal database exception tingkat sistem).
     */
    public function onError(Throwable $e)
    {
        $this->totalRows++;
        $this->skippedCount++;
        $this->skippedRows[] = [
            'row' => $this->getRowNumber(),
            'message' => 'Baris tidak dapat diproses. Periksa data dan coba kembali.',
        ];
    }

    /**
     * Mendapatkan ringkasan hasil import.
     */
    public function getSummary(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'imported' => $this->importedCount,
            'skipped' => $this->skippedCount,
            'errors' => $this->skippedRows,
        ];
    }
}
