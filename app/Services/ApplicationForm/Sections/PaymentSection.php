<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Services\FeeCalculator;
use App\Services\FeePaymentConfigService;
use App\Services\RequirementResolver;
use Illuminate\Validation\Rule;

/** Section 1 — Details of Demand Draft / NEFT. */
class PaymentSection extends Section
{
    public function __construct(RequirementResolver $requirements, private FeeCalculator $fees, private FeePaymentConfigService $configs)
    {
        parent::__construct($requirements);
    }

    public function key(): string
    {
        return 'payment';
    }

    public function title(): string
    {
        return 'Demand Draft / NEFT Details';
    }

    public function formReference(): string
    {
        return '1';
    }

    public function rules(Application $application): array
    {
        if ($this->fees->amountDue($application) <= 0) {
            return [];
        }

        $methods = array_values(array_diff($application->advertisement->payment_methods ?? [], ['exempt']));
        $due = $this->fees->amountDue($application);

        return [
            'method' => ['required', Rule::in($methods)],
            'utr_number' => ['required', 'string', 'regex:/^[A-Za-z0-9]{6,30}$/'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', "min:$due", 'max:1000000'],
            'bank_name' => ['required', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'utr_number.regex' => 'The UTR / transaction / DD number should contain 6 to 30 letters and digits only.',
            'amount.min' => 'The amount paid is less than the fee payable (Rs. :min).',
        ];
    }

    public function attributes(): array
    {
        return ['utr_number' => 'UTR / transaction / DD number', 'transaction_date' => 'date of payment'];
    }

    public function save(Application $application, array $data): void
    {
        $fee = $this->fees->breakdown($application);

        if ($fee['amount'] <= 0) {
            $application->payment()->updateOrCreate([], [
                'method' => 'exempt', 'amount' => 0, 'amount_due' => 0, 'utr_number' => null,
                'transaction_date' => null, 'bank_name' => null, 'exemption_basis' => $fee['basis'],
                'fee_payment_config_id' => null, 'fee_payment_config_version' => null, 'payment_config_snapshot' => null,
                'status' => 'exempt',
            ]);

            return;
        }

        // Freeze the bank details the candidate was shown, so later edits of the configuration
        // never change what this payment was made against.
        $config = $this->configs->forApplication($application);
        $application->payment()->updateOrCreate([], [
            'fee_payment_config_id' => $config?->id,
            'fee_payment_config_version' => $config?->version,
            'payment_config_snapshot' => $config ? $this->configs->snapshot($config) : null,
            'method' => $data['method'],
            'utr_number' => strtoupper($data['utr_number']),
            'transaction_date' => $data['transaction_date'],
            'amount' => $data['amount'],
            'amount_due' => $fee['amount'],
            'bank_name' => $data['bank_name'],
            'exemption_basis' => null,
            'status' => 'pending_verification',
        ]);
    }

    public function data(Application $application): array
    {
        $p = $application->payment;
        $fee = $this->fees->breakdown($application);

        return [
            'method' => $p?->method,
            'utr_number' => $p?->utr_number,
            'transaction_date' => $p?->transaction_date?->toDateString(),
            'amount' => $p?->amount,
            'bank_name' => $p?->bank_name,
            'status' => $p?->status,
            'amount_due' => $fee['amount'],
            'exemption_basis' => $fee['basis'],
        ];
    }

    public function issues(Application $application): array
    {
        $issues = [];
        $due = $this->fees->amountDue($application);
        $payment = $application->payment;

        if ($due > 0) {
            if (! $payment || $payment->method === 'exempt') {
                $issues[] = 'Enter the NEFT / Demand Draft details of the application fee.';
            } elseif ((float) $payment->amount < $due) {
                $issues[] = "The fee payable has changed to Rs. {$due}. Update the payment details.";
            }
        } elseif (! $payment) {
            $issues[] = 'Confirm the fee exemption on this step.';
        }

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
