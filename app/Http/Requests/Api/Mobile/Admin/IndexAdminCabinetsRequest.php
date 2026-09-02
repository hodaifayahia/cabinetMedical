<?php

namespace App\Http\Requests\Api\Mobile\Admin;

use App\Enums\CabinetStatus;
use App\Support\Wilayas;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Platform back office: filters for the cross-tenant clinic list.
 */
class IndexAdminCabinetsRequest extends AdminFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function adminRules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(CabinetStatus::values())],
            'wilaya_code' => ['sometimes', 'integer', 'between:'.Wilayas::MIN.','.Wilayas::MAX],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }
}
