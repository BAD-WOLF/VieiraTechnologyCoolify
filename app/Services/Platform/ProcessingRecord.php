<?php

namespace App\Services\Platform;

/**
 * Record of the processing operations (LGPD, art. 37), built from what the platform does and how
 * it is configured: what data, of whom, why, on which legal basis (art. 7º), with whom it is
 * shared, for how long it is kept and how it is protected.
 */
class ProcessingRecord
{
    /**
     * @return list<array{operation: string, data: string, subjects: string, purpose: string, basis: string, sharing: string, retention: string, security: string}>
     */
    public static function operations(): array
    {
        $hosting = (string) config('platform.privacy.hosting');
        $months = AccountDeletion::accessRecordMonths();
        $years = AccountDeletion::fiscalYears();
        $payments = collect(Privacy::processors())->pluck('name')->filter(fn (string $name) => str_contains($name, 'Efí') || str_contains($name, 'Stripe'))->implode(', ');

        return [
            [
                'operation' => __('Account and sign-in'),
                'data' => __('Name, e-mail, password (hashed), two-factor secret'),
                'subjects' => __('Customers, members of their teams and operators'),
                'purpose' => __('Create the account, sign in and protect it'),
                'basis' => __('Performance of the contract (art. 7º, V)'),
                'sharing' => $hosting,
                'retention' => __('While the account exists'),
                'security' => __('HTTPS, hashed passwords, two-factor authentication, access per team'),
            ],
            [
                'operation' => __('Access records and audit log'),
                'data' => __('IP address, browser, date and time of each access and action'),
                'subjects' => __('Customers, members of their teams and operators'),
                'purpose' => __('Keep the access records the law requires and investigate abuse and attacks'),
                'basis' => __('Legal obligation (art. 7º, II; Marco Civil da Internet, art. 15) and legitimate interest (art. 7º, IX)'),
                'sharing' => $hosting,
                'retention' => trans_choice('{1} :count month|[2,*] :count months', $months, ['count' => $months]),
                'security' => __('Only operators read it; deleted at the end of the period'),
            ],
            [
                'operation' => __('Billing'),
                'data' => __('Name, CPF or CNPJ, e-mail, phone, invoices and payments'),
                'subjects' => __('Customers'),
                'purpose' => __('Charge the plans and the usage'),
                'basis' => __('Performance of the contract (art. 7º, V) and legal obligation (art. 7º, II, tax law)'),
                'sharing' => trim($hosting.($payments !== '' ? ', '.$payments : '')),
                'retention' => trans_choice('{1} While the account exists; invoices :count year after it closes|[2,*] While the account exists; invoices :count years after it closes', $years, ['count' => $years]),
                'security' => __('Payer data encrypted in the database'),
            ],
            [
                'operation' => __('Hosting of the applications and databases of the customers'),
                'data' => __('Whatever the customers keep in their applications and databases (the customer is the controller; the platform is the operator, art. 39)'),
                'subjects' => __('Users of the applications of the customers'),
                'purpose' => __('Run the applications, databases and services the customer contracts'),
                'basis' => __('Performance of the contract (art. 7º, V), on the instructions of the customer'),
                'sharing' => $hosting,
                'retention' => __('Until the customer deletes it or closes the account'),
                'security' => __('Isolated containers and networks per customer, firewalls, encrypted disks and backups'),
            ],
            [
                'operation' => __('Usage metering and logs'),
                'data' => __('CPU, memory, disk and network usage; logs of the applications'),
                'subjects' => __('Customers'),
                'purpose' => __('Bill the usage, enforce the limits and help with problems'),
                'basis' => __('Performance of the contract (art. 7º, V)'),
                'sharing' => $hosting,
                'retention' => trans_choice('{1} Raw measurements :count day; then hourly totals|[2,*] Raw measurements :count days; then hourly totals', (int) config('platform.metering.raw_retention_days', 90), ['count' => (int) config('platform.metering.raw_retention_days', 90)]),
                'security' => __('Each customer sees only its own data'),
            ],
            [
                'operation' => __('Service messages'),
                'data' => __('Name and e-mail'),
                'subjects' => __('Customers, members of their teams and operators'),
                'purpose' => __('Invoices, limits, suspensions, security and maintenance notices'),
                'basis' => __('Performance of the contract (art. 7º, V)'),
                'sharing' => collect(Privacy::processors())->pluck('name')->implode(', '),
                'retention' => __('While the account exists'),
                'security' => __('Sent only to the members of the account'),
            ],
            [
                'operation' => __('Promotions and news'),
                'data' => __('Name and e-mail'),
                'subjects' => __('Users who consented'),
                'purpose' => __('Tell about offers and novelties of the platform'),
                'basis' => __('Consent (art. 7º, I), withdrawn at any time'),
                'sharing' => $hosting,
                'retention' => __('Until the consent is withdrawn or the account is deleted'),
                'security' => __('Only to whom consented'),
            ],
            [
                'operation' => __('Requests of the data subjects, consents and incidents'),
                'data' => __('Name, e-mail, the request and its answer; proof of each acceptance; the register of incidents'),
                'subjects' => __('Data subjects'),
                'purpose' => __('Answer the rights of the data subjects and account for the processing'),
                'basis' => __('Legal obligation (art. 7º, II; arts. 8º, § 2º, 18, 37 and 48)'),
                'sharing' => $hosting,
                'retention' => trans_choice('{1} :count year after it ends|[2,*] :count years after it ends', PrivacyRetention::PROOF_YEARS, ['count' => PrivacyRetention::PROOF_YEARS]),
                'security' => __('Only operators read it'),
            ],
        ];
    }

    public static function csv(): string
    {
        $columns = ['operation', 'data', 'subjects', 'purpose', 'basis', 'sharing', 'retention', 'security'];
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [__('Operation'), __('Data'), __('Data subjects'), __('Purpose'), __('Legal basis'), __('Shared with'), __('Retention'), __('Security')]);
        foreach (self::operations() as $operation) {
            fputcsv($handle, array_map(fn (string $column) => $operation[$column], $columns));
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
