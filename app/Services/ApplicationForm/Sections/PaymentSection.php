<?php

namespace App\Services\ApplicationForm\Sections;

use App\Models\Application;
use App\Services\AuditLogger;
use App\Services\FeeCalculator;
use App\Services\FeePaymentConfigService;
use App\Services\RequirementResolver;
use Illuminate\Validation\Rule;

/** Section 1 — Details of Demand Draft / NEFT. */
class PaymentSection extends Section
{
    public function __construct(RequirementResolver $requirements, private FeeCalculator $fees, private FeePaymentConfigService $configs, private AuditLogger $audit)
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

    /** The fee step exists only when the advertisement's fee schedule gives this applicant a fee above zero. */
    public function isApplicable(Application $application): bool
    {
        return $this->fees->amountDue($application) > 0;
    }

    public function rejectsWhenNotApplicable(): bool
    {
        return true;
    }

    public function notApplicableMessage(): string
    {
        return 'No application fee is payable for this application, so payment details are not required.';
    }

    /**
     * Keep the stored fee record in line with the fee calculated now (after every saved step of a
     * draft, and at submission). A zero fee is recorded as exempt (with its basis) or not required,
     * never as unpaid; a payment staff have already verified is never touched. A stale entry is
     * replaced and the replacement audited (its verification history stays in payment_verifications).
     * An exemption marker is removed once a fee applies again, so the fee step asks for payment.
     */
    public function syncFeeRecord(Application $application): void
    {
        $fee = $this->fees->breakdown($application);
        $payment = $application->payment()->first();
        if ($payment?->status === 'verified') {
            return;
        }

        if ($fee['amount'] > 0) {
            if ($payment?->status === 'exempt') {
                $this->audit->record('payment.exemption_cleared', $payment, $payment->only(['status', 'exemption_basis']), [],
                    ['reason' => "A fee of Rs. {$fee['amount']} applies to the details now entered."], application: $application);
                $payment->delete();
            }

            return;
        }

        if (! $payment || $payment->status !== 'exempt' || $payment->exemption_basis !== $fee['basis']) {
            $old = $payment?->only(['method', 'utr_number', 'amount', 'amount_due', 'status', 'exemption_basis']);
            $payment = $application->payment()->updateOrCreate([], [
                'method' => 'exempt', 'amount' => 0, 'amount_due' => 0, 'utr_number' => null, 'transaction_date' => null, 'bank_name' => null,
                'exemption_basis' => $fee['basis'], 'status' => 'exempt',
                'fee_payment_config_id' => null, 'fee_payment_config_version' => null, 'payment_config_snapshot' => null,
            ]);
            if ($old && $old['status'] !== 'exempt') {
                $this->audit->record('payment.fee_not_payable', $payment, $old, $payment->only(['method', 'amount_due', 'status', 'exemption_basis']),
                    ['reason' => 'The fee calculated for the details now entered is zero.'], application: $application);
            }
        }
    }

    /** At submission: settle the fee record with the fee recalculated now and return the application's fee status. */
    public function settle(Application $application): string
    {
        $this->syncFeeRecord($application);
        $payment = $application->payment()->first();

        return match (true) {
            $payment?->status === 'verified' => 'verified',
            $payment?->status === 'exempt' => $payment->exemption_basis ? 'exempt' : 'not_required',
            default => 'pending_verification',
        };
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

        if ($fee['amount'] <= 0) { // unreachable through saveSection (the step does not apply); kept as a guard
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
            if (! $payment) {
                $issues[] = 'Enter the details of your application fee payment.';
            } elseif ($payment->method === 'exempt') {
                $issues[] = "With the personal details you have entered, an application fee of Rs. {$due} applies. Enter the payment details.";
            } elseif ((float) $payment->amount < $due) {
                $issues[] = "The fee payable has changed to Rs. {$due}. Update the payment details.";
            }
        }

        return array_merge($issues, $this->missingDocumentIssues($application));
    }
}
