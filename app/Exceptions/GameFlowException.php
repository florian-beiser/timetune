<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A request that does not fit the current game state, e.g. a placement after
 * the round was closed. The message is shown to the user as is.
 */
class GameFlowException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
