<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MarketplaceRequest;
use App\Http\Resources\Api\V1\MarketplaceOrderResource;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Services\MarketplaceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MarketplaceController extends Controller
{
    public function __construct(private readonly MarketplaceService $market) {}

    public function catalogue(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'community_id' => ['nullable', 'integer', 'min:1'], 'seller_identity_id' => ['nullable', 'integer', 'min:1'], 'page' => ['integer', 'min:1'], 'per_page' => ['integer', 'min:1', 'max:100']]);
        $page = $this->market->catalogue($filters);

        return ApiResponse::success($this->productsPage($page));
    }

    public function product(MarketplaceProduct $product): JsonResponse
    {
        return ApiResponse::success($this->market->productData($this->market->publicProduct($product)));
    }

    public function own(Request $request): JsonResponse
    {
        $request->validate(['page' => ['integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);
        $identity = MemberIdentity::forUser($this->user($request));
        $query = MarketplaceProduct::query()->with(['seller.user', 'community', 'photo'])->where('seller_identity_id', $identity->id);
        $count = (clone $query)->where('is_active', true)->count();
        if ($request->filled('search')) {
            $query->whereRaw("lower(name || ' ' || description) like ?", ['%'.addcslashes(mb_strtolower(trim((string) $request->query('search'))), '\\%_').'%']);
        }

        return ApiResponse::success([...$this->productsPage($query->latest()->orderBy('id')->paginate(20)), 'active_count' => $count, 'active_limit' => 5, 'max_active' => 5]);
    }

    public function ownProduct(Request $request, MarketplaceProduct $product): JsonResponse
    {
        abort_unless($product->user_id === $this->user($request)->id && $product->seller_identity_id === MemberIdentity::forUser($this->user($request))->id, 403);
        Gate::forUser($this->user($request))->authorize('update', $product);

        return ApiResponse::success($this->market->productData($product->load(['seller.user', 'community', 'photo'])));
    }

    public function store(MarketplaceRequest $request): JsonResponse
    {
        return ApiResponse::success($this->market->productData($this->market->save($this->user($request), $this->productInput($request))), 'Produk disimpan.', 201);
    }

    public function update(MarketplaceRequest $request, MarketplaceProduct $product): JsonResponse
    {
        return ApiResponse::success($this->market->productData($this->market->save($this->user($request), $this->productInput($request), $product)), 'Produk diperbarui.');
    }

    public function quote(MarketplaceRequest $request): JsonResponse
    {
        return ApiResponse::success($this->market->quote($this->user($request), MarketplaceProduct::query()->findOrFail($request->input('product_id')), $request->integer('quantity')));
    }

    public function checkout(MarketplaceRequest $request): JsonResponse
    {
        $data = $request->validated();
        foreach (['quantity', 'expected_version', 'expected_unit_price'] as $field) {
            $data[$field] = $request->integer($field);
        }
        $data['acknowledged'] = $request->boolean('acknowledged');

        return ApiResponse::success(new MarketplaceOrderResource($this->market->checkout($this->user($request), $data)), 'Pembelian berhasil dan final tanpa refund.', 201);
    }

    public function orders(Request $request): JsonResponse
    {
        $request->validate(['direction' => ['nullable', Rule::in(['purchases', 'sales'])], 'page' => ['integer', 'min:1']]);
        $identity = MemberIdentity::forUser($this->user($request));
        $column = $request->query('direction', 'purchases') === 'sales' ? 'seller_identity_id' : 'buyer_identity_id';
        $page = MarketplaceOrder::query()->where($column, $identity->id)->latest('created_at')->orderByDesc('id')->paginate(20);

        return ApiResponse::success(['items' => MarketplaceOrderResource::collection($page->items())->resolve($request), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function byKey(Request $request, string $key): JsonResponse
    {
        $order = MarketplaceOrder::query()->where('user_id', $this->user($request)->id)->where('idempotency_key', $key)->firstOrFail();

        return ApiResponse::success(new MarketplaceOrderResource($order));
    }

    public function order(Request $request, MarketplaceOrder $order): JsonResponse
    {
        $identityId = MemberIdentity::forUser($this->user($request))->id;
        abort_if($identityId !== $order->buyer_identity_id && $identityId !== $order->seller_identity_id, 403);
        Gate::forUser($this->user($request))->authorize('view', $order);

        return ApiResponse::success(new MarketplaceOrderResource($order));
    }

    /** @param LengthAwarePaginator<int,MarketplaceProduct> $page @return array<string,mixed> */
    private function productsPage(LengthAwarePaginator $page): array
    {
        return ['items' => collect($page->items())->map(fn (MarketplaceProduct $product): array => $this->market->productData($product))->all(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    /** @return array<string,mixed> */
    private function productInput(MarketplaceRequest $request): array
    {
        $data = $request->validated();
        foreach (['community_id', 'price', 'stock', 'expected_version'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->integer($field);
            }
        }
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function user(Request $request): User
    {
        /** @var User $user */ $user = $request->user();

        return $user;
    }
}
