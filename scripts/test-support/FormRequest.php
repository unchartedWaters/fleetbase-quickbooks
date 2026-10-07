<?php

declare(strict_types=1);

namespace Illuminate\Foundation\Http;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;

class FormRequest extends Request
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function responseWithErrors(Validator $validator)
    {
        return $validator;
    }
}
