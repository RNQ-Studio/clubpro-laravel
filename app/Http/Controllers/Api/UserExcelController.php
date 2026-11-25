<?php

namespace App\Http\Controllers\Api;

use App\Exports\UsersExport;
use App\Http\Controllers\Controller;
use App\Imports\UsersImport;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Exporter;
use Maatwebsite\Excel\Importer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class UserExcelController extends Controller
{
    /**
     * Mengunduh semua data users dalam format .xlsx.
     */
    public function export(Exporter $exporter): BinaryFileResponse|JsonResponse
    {
        try {
            $date = date('Ymd');
            $fileName = "users_export_{$date}.xlsx";

            // Menggunakan Dependency Injection Exporter untuk memicu download
            return $exporter->download(new UsersExport, $fileName);
        } catch (Throwable $e) {
            // Sesuai dengan spesifikasi format response gagal
            report($e);

            return ApiResponse::error('Gagal mengekspor data', 500);
        }
    }

    /**
     * Mengimpor data users dari file Excel (.xlsx).
     */
    public function import(Request $request, Importer $importer): JsonResponse
    {
        // Validasi input file: wajib ada dan valid
        if (! $request->hasFile('file') || ! $request->file('file')->isValid()) {
            return ApiResponse::error('File tidak valid. Harap upload file .xlsx', 400);
        }

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();

        // Validasi format file harus .xlsx
        if (strtolower($extension) !== 'xlsx') {
            return ApiResponse::error('File tidak valid. Harap upload file .xlsx', 400);
        }

        try {
            $import = new UsersImport;
            $importer->import($import, $file);

            // Return response ringkasan sesuai dengan format spesifikasi
            return ApiResponse::success($import->getSummary(), 'Import selesai');
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('Gagal memproses file import. Periksa format dan coba kembali.', 500);
        }
    }
}
