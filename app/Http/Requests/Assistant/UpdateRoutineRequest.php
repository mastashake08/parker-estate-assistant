<?php

namespace App\Http\Requests\Assistant;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class UpdateRoutineRequest extends StoreRoutineRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('routine'));
    }
}
