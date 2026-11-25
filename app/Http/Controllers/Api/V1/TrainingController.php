<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TrainingRequest;
use App\Models\Community;
use App\Models\TrainingAttendance;
use App\Models\TrainingLocation;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TrainingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingController extends Controller
{
    public function __construct(private readonly TrainingService $training) {}

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }

    public function overview(Request $request, Community $community): JsonResponse
    {
        return ApiResponse::success($this->training->overview($this->actor($request), $community));
    }

    public function catalog(TrainingRequest $request, Community $community): JsonResponse
    {
        return ApiResponse::success($this->training->saveCatalog($this->actor($request), $community, $request->validated()), 'Harga paket disimpan. Pembelian lama tetap memakai harga semula.');
    }

    public function location(TrainingRequest $request, Community $community, ?TrainingLocation $trainingLocation = null): JsonResponse
    {
        return ApiResponse::success($this->training->saveLocation($this->actor($request), $community, $request->validated(), $trainingLocation), 'Lokasi dan pembagian nominal disimpan.');
    }

    public function session(TrainingRequest $request, Community $community): JsonResponse
    {
        return ApiResponse::success($this->training->createSession($this->actor($request), $community, $request->validated()), 'Sesi latihan dibuat.', 201);
    }

    public function purchase(TrainingRequest $request, Community $community): JsonResponse
    {
        return ApiResponse::success($this->training->purchaseData($this->training->purchase($this->actor($request), $community, $request->validated())), 'Paket dibeli. Dana dicadangkan sekali; absensi tidak memotong saldo tersedia lagi.', 201);
    }

    public function checkIn(TrainingRequest $request, Community $community, TrainingSession $trainingSession): JsonResponse
    {
        return ApiResponse::success($this->training->attendanceData($this->training->checkIn($this->actor($request), $community, $trainingSession, $request->validated())), 'Check-in menunggu konfirmasi petugas; jatah dan dana belum digunakan.');
    }

    public function confirm(TrainingRequest $request, Community $community, TrainingSession $trainingSession): JsonResponse
    {
        return ApiResponse::success($this->training->attendanceData($this->training->confirm($this->actor($request), $community, $trainingSession, $request->validated())), 'Absensi dikonfirmasi. Satu jatah dan dana paket dialokasikan.');
    }

    public function correct(TrainingRequest $request, Community $community, TrainingAttendance $trainingAttendance): JsonResponse
    {
        return ApiResponse::success($this->training->attendanceData($this->training->correct($this->actor($request), $community, $trainingAttendance, $request->validated())), 'Koreksi teknis tercatat. Jatah dan dana kembali ke paket terikat, bukan refund saldo.');
    }

    public function report(Request $request, Community $community): JsonResponse
    {
        $filters = $request->validate(['location_id' => ['sometimes', 'integer', 'min:1'], 'recipient_member_id' => ['sometimes', 'integer', 'min:1'], 'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return ApiResponse::success($this->training->report($this->actor($request), $community, $filters));
    }
}
