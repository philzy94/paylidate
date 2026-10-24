<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductService;
use Illuminate\Http\Request;

/**
 * @group  Product management
 *
 * APIs for Product
 */
class ProductController extends Controller
{
    protected $products;

    public function __construct(ProductService $products)
    {
        $this->products = $products;
    }

    public function index()
    {
        return $this->success($this->products->listFor(auth('api')->user()));
    }

    // creating a product
    public function store(Request $request)
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

        return $this->success($this->products->create(auth('api')->user(), $data));
    }

    // getting single product
    public function show($slug)
    {
        return $this->success($this->products->findBySlug($slug, ['transaction', 'user:id,name']));
    }

    // public product page
    public function get_product($slug)
    {
        return $this->success($this->products->findBySlug($slug, ['transaction', 'user:id,name']));
    }

    // updating a product
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'type' => 'required|string',
            'description' => 'nullable|string',
            'referral_amount' => 'nullable|numeric|min:0',
        ]);

        return $this->success($this->products->update(auth('api')->user(), (int) $id, $data));
    }

    // deleting a product only if it doesn't have a transaction
    public function destroy($id)
    {
        $this->products->destroy(auth('api')->user(), (int) $id);

        return response()->json(['status' => 'success', 'message' => 'success']);
    }

    // Product available (enable or disable)
    public function status($id, Request $request)
    {
        $data = $request->validate(['status' => 'required|boolean']);

        return $this->success($this->products->setAvailability(auth('api')->user(), (int) $id, (bool) $data['status']));
    }

    public function delivered($id)
    {
        return $this->success($this->products->markDelivered(auth('api')->user(), (int) $id));
    }

    // Product Catalog
    public function get_catalog($id)
    {
        return $this->success($this->products->catalog((int) $id));
    }

    public function product_recommendation($id)
    {
        $this->products->recommend(auth('api')->user(), (int) $id);

        return response()->json(['status' => 'success', 'message' => 'product recommended']);
    }

    public function remove_product_recommendation($id)
    {
        $this->products->unrecommend(auth('api')->user(), (int) $id);

        return response()->json(['status' => 'success', 'message' => 'product unrecommended']);
    }
}
