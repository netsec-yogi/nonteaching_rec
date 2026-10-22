<?php

namespace App\Http\Requests;

use App\Models\Advertisement;
use App\Models\PostEligibilityCriterion;
use App\Models\Qualification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('advertisements.manage');
    }

    public function rules(): array
    {
        $ad = $this->route('advertisement');

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('posts', 'code')->where('advertisement_id', $ad->id)->whereNull('deleted_at')->ignore($this->route('post')?->id)],
            'name' => ['required', 'string', 'max:200'],
            'department' => ['nullable', 'string', 'max:200'],
            'group_classification' => ['nullable', 'string', 'max:30'],
            'pay_level' => ['nullable', 'string', 'max:50'],
            'pay_scale' => ['nullable', 'string', 'max:200'],
            'total_vacancies' => ['required', 'integer', 'min:0', 'max:10000'],
            'age_min' => ['nullable', 'integer', 'between:14,70'],
            'age_max' => ['nullable', 'integer', 'between:14,70', Rule::when($this->filled('age_min'), 'gte:age_min')],
            'age_cutoff_date' => ['nullable', 'date'],
            'age_relaxation' => ['nullable', 'string', 'max:5000'],
            'essential_qualifications' => ['nullable', 'string', 'max:20000'],
            'desirable_qualifications' => ['nullable', 'string', 'max:20000'],
            'experience_requirements' => ['nullable', 'string', 'max:20000'],
            'other_conditions' => ['nullable', 'string', 'max:20000'],
            'fee_schedule' => ['nullable', 'array'],
            'fee_schedule.default' => ['required_with:fee_schedule', 'numeric', 'min:0'],
            'fee_schedule.exemptions' => ['nullable', 'array'],
            'endorsement_mode' => ['nullable', Rule::in(Advertisement::ENDORSEMENT_MODES)],
            'status' => ['required', Rule::in(['open', 'closed'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'vacancies' => ['nullable', 'array'],
            'vacancies.*.category' => ['required', 'string', 'max:20', 'distinct'],
            'vacancies.*.count' => ['required', 'integer', 'min:0'],
            'vacancies.*.remarks' => ['nullable', 'string', 'max:255'],
            'criteria' => ['nullable', 'array'],
            'criteria.*.type' => ['required', Rule::in(PostEligibilityCriterion::TYPES)],
            'criteria.*.label' => ['required', 'string', 'max:300'],
            'criteria.*.value' => ['nullable', 'string', 'max:100'],
            'criteria.*.qualification_level' => ['nullable', Rule::in(array_keys(Qualification::LEVELS))],
            'criteria.*.is_essential' => ['boolean'],
            'criteria.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
