<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MemberInvitation;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ClaimMemberInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $invitation = $this->route('invitation');
        $inApp = $invitation instanceof MemberInvitation && $invitation->delivery_method === 'in_app';

        return [
            'token' => [$inApp ? 'sometimes' : 'required', 'nullable', 'string', 'max:128'],
            'accept' => $inApp ? ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== true) {
                    $fail('Setujui undangan ini secara eksplisit sebelum menghubungkan anggota.');
                }
            }] : ['sometimes', 'boolean'],
        ];
    }
}
