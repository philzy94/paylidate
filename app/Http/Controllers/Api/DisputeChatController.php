<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DisputeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DisputeChatController extends Controller
{
    protected DisputeService $disputes;

    public function __construct(DisputeService $disputes)
    {
        $this->disputes = $disputes;
    }

    public function index(string $dispute_id): JsonResponse
    {
        return $this->success($this->disputes->messages($this->authUser(), (int) $dispute_id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dispute_id' => 'required|integer',
            'message' => 'required|string',
        ]);

        return $this->success($this->disputes->addMessage($this->authUser(), (int) $data['dispute_id'], $data['message']));
    }
}
