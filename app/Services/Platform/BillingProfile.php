<?php

namespace App\Services\Platform;

use App\Models\PlatformTenant;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Payer details of a tenant, stored encrypted in platform_tenants.billing_profile.
 *
 * Efí needs only the name and the CPF/CNPJ (and a phone number for boletos): the address of the
 * customer is never asked.
 */
class BillingProfile
{
    public const FIELDS = ['name', 'document', 'email', 'phone', 'payment_method'];

    public const PIX = 'pix';

    public const BOLETO = 'boleto';

    /**
     * Payment methods the customer can choose: Pix needs the Pix key and the certificate of the
     * Efí application, the boleto (with a Pix QR code) only its credentials.
     *
     * @return array<int, string>
     */
    public static function methods(): array
    {
        if (! PlatformBilling::enabled() || PlatformBilling::isManual()) {
            return [];
        }

        return self::configuredMethods();
    }

    /**
     * What the Efí credentials of the environment in use allow, whatever the payment provider is:
     * what the connection test of the console checks.
     *
     * @return array<int, string>
     */
    public static function configuredMethods(): array
    {
        if (! Efi\EfiSettings::isConfigured()) {
            return [];
        }

        return Efi\EfiSettings::pixReady() ? [self::PIX, self::BOLETO] : [self::BOLETO];
    }

    /**
     * How the tenant's invoices are charged: what the customer chose, when available.
     */
    public static function method(?string $chosen): ?string
    {
        $methods = self::methods();
        if ($methods === []) {
            return null;
        }

        return in_array($chosen, $methods, true) ? $chosen : $methods[0];
    }

    public static function methodOf(PlatformTenant $tenant): ?string
    {
        return self::method(data_get($tenant->billing_profile, 'payment_method'));
    }

    /**
     * Boletos are registered with a phone number of the payer.
     */
    public static function needsPhone(?string $chosen): bool
    {
        return self::method($chosen) === self::BOLETO;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $prefix = '', ?string $method = null): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:100'],
            $prefix.'document' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::validDocument((string) $value)) {
                    $fail(__('Invalid CPF/CNPJ.'));
                }
            }],
            $prefix.'email' => ['required', 'email', 'max:100'],
            $prefix.'phone' => [self::needsPhone($method) ? 'required' : 'nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && ! in_array(strlen(self::localPhone((string) $value)), [10, 11], true)) {
                    $fail(__('Type the phone number with the area code, e.g. (11) 98888-7777.'));
                }
            }],
            $prefix.'payment_method' => ['nullable', Rule::in([self::PIX, self::BOLETO])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function of(PlatformTenant $tenant): array
    {
        $profile = (array) $tenant->billing_profile;

        return collect(self::FIELDS)->mapWithKeys(fn (string $field) => [$field => (string) ($profile[$field] ?? '')])->all();
    }

    public static function isComplete(PlatformTenant $tenant): bool
    {
        $profile = self::of($tenant);

        return validator($profile, self::rules('', $profile['payment_method']))->passes();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(PlatformTenant $tenant, array $data): void
    {
        $profile = collect(self::FIELDS)->mapWithKeys(fn (string $field) => [$field => trim((string) ($data[$field] ?? ''))])->all();
        $profile['document'] = self::digits($profile['document']);
        $profile['payment_method'] = self::method($profile['payment_method']) ?? '';
        $tenant->update(['billing_profile' => $profile]);
        audit_log('platform.billing_profile.updated', $tenant, ['fields' => array_keys(array_filter($profile))], $tenant->team_id);
    }

    /**
     * Customer of an Efí boleto: name and CPF, or company name and CNPJ, with e-mail and phone.
     *
     * @return array<string, mixed>
     */
    public static function boletoCustomer(PlatformTenant $tenant): array
    {
        $profile = self::of($tenant);
        $document = self::digits($profile['document']);
        $name = Str::limit($profile['name'], 255, '');
        $customer = strlen($document) === 14
            ? ['juridical_person' => ['corporate_name' => $name, 'cnpj' => $document]]
            : ['name' => $name, 'cpf' => $document];
        if (filled($profile['email'])) {
            $customer['email'] = Str::limit($profile['email'], 255, '');
        }
        $phone = self::localPhone($profile['phone']);
        if (in_array(strlen($phone), [10, 11], true)) {
            $customer['phone_number'] = $phone;
        }

        return $customer;
    }

    /**
     * Phone with the area code, without the country code: "(11) 98888-7777" -> "11988887777".
     */
    public static function localPhone(?string $phone): string
    {
        $digits = self::digits($phone);

        return strlen($digits) > 11 && str_starts_with($digits, '55') ? substr($digits, 2) : $digits;
    }

    /**
     * Debtor ("devedor") of a Pix charge: only the document and the name.
     *
     * @return array<string, string>
     */
    public static function debtor(PlatformTenant $tenant): array
    {
        $profile = self::of($tenant);
        $document = self::digits($profile['document']);

        return [
            strlen($document) === 14 ? 'cnpj' : 'cpf' => $document,
            'nome' => Str::limit($profile['name'], 200, ''),
        ];
    }

    public static function digits(?string $value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }

    public static function validDocument(string $value): bool
    {
        $digits = self::digits($value);

        return match (strlen($digits)) {
            11 => self::validCpf($digits),
            14 => self::validCnpj($digits),
            default => false,
        };
    }

    private static function validCpf(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($position = 9; $position < 11; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $cpf[$index] * (($position + 1) - $index);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$position] !== $digit) {
                return false;
            }
        }

        return true;
    }

    private static function validCnpj(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        $weights = [[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]];
        foreach ([12, 13] as $round => $length) {
            $sum = 0;
            for ($index = 0; $index < $length; $index++) {
                $sum += (int) $cnpj[$index] * $weights[$round][$index];
            }
            $digit = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
            if ((int) $cnpj[$length] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
