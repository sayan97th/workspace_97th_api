<?php

namespace App\Http\Requests\Integration;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IntegrationAppCredentialsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * A blank `client_secret` keeps the saved one while the client id stays the same. `tenant_id`
     * is only read for Microsoft, empty means any work, school or personal account. A
     * `redirect_uri` override is only needed behind an HTTPS tunnel.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'tenant_id' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'redirect_uri' => ['sometimes', 'nullable', 'url', 'max:255'],
        ];
    }
}
