<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @group  Product management
 *
 * APIs for Product
 */
class ProductController extends Controller
{
    protected ProductService $products;

    public function __construct(ProductService $products)
    {
        $this->products = $products;
    }

    public function index(): JsonResponse
    {
        return $this->success($this->products->listFor($this->authUser()));
    }

    // creating a product
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:1',
            'type' => 'required|string',
            'transaction_type' => 'required|in:buy,sell',
            'description' => 'nullable|string',
            'referral_amount' => 'nullable|numeric|min:0',
            'seller_email' => 'required_if:transaction_type,buy|nullable|string|email',
        ]);

        return $this->success($this->products->create($this->authUser(), $data));
    }

    // getting single product
    public function show(string $slug): JsonResponse
    {
        return $this->success($this->products->findBySlug($slug, ['transaction', 'user:id,name']));
    }

    // public product page
    public function get_product(string $slug): JsonResponse
    {
        return $this->success($this->products->findBySlug($slug, ['transaction', 'user:id,name']));
    }

    // updating a product
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'type' => 'required|string',
            'description' => 'nullable|string',
            'referral_amount' => 'nullable|numeric|min:0',
        ]);

        return $this->success($this->products->update($this->authUser(), (int) $id, $data));
    }

    // deleting a product only if it doesn't have a transaction
    public function destroy(string $id): JsonResponse
    {
        $this->products->destroy($this->authUser(), (int) $id);

        return response()->json(['status' => 'success', 'message' => 'success']);
    }

    // Product available (enable or disable)
    public function status(string $id, Request $request): JsonResponse
    {
        $data = $request->validate(['status' => 'required|boolean']);

        return $this->success($this->products->setAvailability($this->authUser(), (int) $id, (bool) $data['status']));
    }

    public function delivered(string $id): JsonResponse
    {
        return $this->success($this->products->markDelivered($this->authUser(), (int) $id));
    }

    // Product Catalog
    public function get_catalog(string $id): JsonResponse
    {
        return $this->success($this->products->catalog((int) $id));
    }

    public function product_recommendation(string $id): JsonResponse
    {
        $this->products->recommend($this->authUser(), (int) $id);

        return response()->json(['status' => 'success', 'message' => 'product recommended']);
    }

    public function remove_product_recommendation(string $id): JsonResponse
    {
        $this->products->unrecommend($this->authUser(), (int) $id);

        return response()->json(['status' => 'success', 'message' => 'product unrecommended']);
    }
}
