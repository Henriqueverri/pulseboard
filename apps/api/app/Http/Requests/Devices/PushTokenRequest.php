<?php

namespace App\Http\Requests\Devices;

use App\Models\PushDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PushTokenRequest extends FormRequest
{
    /**
     * The format returned by Notifications.getExpoPushTokenAsync() in the app.
     */
    public const TOKEN_PATTERN = '/^Expo(nent)?PushToken\[[A-Za-z0-9_-]{1,200}\]$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'token' => ['required', 'string', 'max:255', 'regex:'.self::TOKEN_PATTERN],
        ];

        if ($this->isMethod('POST')) {
            $rules['platform'] = ['required', 'string', Rule::in(PushDevice::PLATFORMS)];
        }

        return $rules;
    }
}
