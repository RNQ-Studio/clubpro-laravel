<?php

namespace App\Support;

use App\Support\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponse
{
    /**
     * @param  AnonymousResourceCollection|AbstractPaginator|JsonResource|array|mixed  $data
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        if ($data instanceof AnonymousResourceCollection && $data->resource instanceof AbstractPaginator) {
            $paginator = $data->resource;
            $payload['data'] = $data->resolve();
            $meta = ['pagination' => self::paginationMeta($paginator)] + $meta;
        } elseif ($data instanceof AbstractPaginator) {
            $payload['data'] = $data->items();
            $meta = ['pagination' => self::paginationMeta($data)] + $meta;
        } else {
            $payload['data'] = $data;
        }

        if (! empty($meta)) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function error(
        string $message,
        int $status = 400,
        array $errors = [],
        ApiErrorCode|string|null $code = null,
        array $meta = []
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($code !== null) {
            $payload['code'] = $code instanceof ApiErrorCode ? $code->value : $code;
        }

        if (! empty($errors)) {
            $payload['errors'] = $errors;
        }

        if (! empty($meta)) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    private static function paginationMeta(AbstractPaginator $paginator): array
    {
        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];

        if ($paginator instanceof LengthAwarePaginator) {
            $meta['total'] = $paginator->total();
            $meta['last_page'] = $paginator->lastPage();
        }

        return $meta;
    }
}
