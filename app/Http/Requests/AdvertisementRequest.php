<?php

namespace App\Http\Requests;

use App\Models\Advertisement;
use App\Models\Payment;
use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvertisementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('advertisements.manage');
    }

    /** Omitted values fall back to the system defaults in Settings. */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('referee_deadline_days')) {
            $this->merge(['referee_deadline_days' => (int) app(SettingsService::class)->get('referee.default_deadline_days')]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('advertisement')?->id;

        return [
            'recruitment_cycle_id' => ['required', 'exists:recruitment_cycles,id'],
            'number' => ['required', 'string', 'max:60', Rule::unique('advertisements', 'number')->ignore($id)],
            'title' => ['required', 'string', 'max:300'],
            'publication_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'instructions' => ['nullable', 'string', 'max:100000'],
            'reservation_details' => ['nullable', 'string', 'max:50000'],
            'age_relaxation_rules' => ['nullable', 'string', 'max:50000'],
            'fee_rules_text' => ['nullable', 'string', 'max:20000'],
            'payment_instructions' => ['nullable', 'string', 'max:20000'],
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date', 'after:opens_at'],
            'reference_prefix' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\/-]+$/'],
            'fee_schedule' => ['nullable', 'array'],
            'fee_schedule.default' => ['required_with:fee_schedule', 'numeric', 'min:0'],
            'fee_schedule.exemptions' => ['nullable', 'array'],
            'fee_schedule.exemptions.*' => ['numeric', 'min:0'],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => [Rule::in(Payment::PAYABLE_METHODS)],
            'referees_required' => ['required', 'integer', 'between:0,5'],
            'referee_recommendations_mandatory' => ['boolean'],
            'referee_deadline_days' => ['required', 'integer', 'between:1,120'],
            'referee_reminder_days' => ['nullable', 'array', 'max:10'],
            'referee_reminder_days.*' => ['integer', 'between:1,120'],
            'referee_final_reminder_days_before' => ['nullable', 'integer', 'between:1,60'],
            'referee_max_reminders' => ['required', 'integer', 'between:0,10'],
            'referee_extra_verification' => ['boolean'],
            'referee_form_template_id' => ['nullable', 'exists:referee_form_templates,id'],
            'endorsement_mode' => ['required', Rule::in(Advertisement::ENDORSEMENT_MODES)],
            'identity_number_requirement' => ['sometimes', Rule::in(array_keys(Advertisement::IDENTITY_REQUIREMENTS))],
            'endorsement_instructions' => ['nullable', 'string', 'max:20000'],
            'committee_evaluation_mode' => ['required', Rule::in(['individual', 'collective'])],
            'evaluation_form_template_id' => ['nullable', 'exists:evaluation_form_templates,id'],
            'allow_withdrawal' => ['boolean'],
            'allow_clarification_uploads' => ['boolean'],
            'retention_years' => ['nullable', 'integer', 'between:1,50'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
