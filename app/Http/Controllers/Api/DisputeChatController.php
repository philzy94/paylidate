<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DisputeService;
use Illuminate\Http\Request;

class DisputeChatController extends Controller
{
    protected $disputes;

    public function __construct(DisputeService $disputes)
    {
        $this->disputes = $disputes;
    }

    public function index($dispute_id)
    {
        return $this->success($this->disputes->messages(auth('api')->user(), (int) $dispute_id));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'dispute_id' => 'required|integer',
            'message' => 'required|string',
        ]);

        return $this->success($this->disputes->addMessage(auth('api')->user(), (int) $data['dispute_id'], $data['message']));
    }
}
