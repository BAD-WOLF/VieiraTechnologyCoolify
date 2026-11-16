<?php

namespace App\Services\Platform;

use App\Models\PlatformTenant;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Payer details of a tenant, stored encrypted in platform_tenants.billing_profile.
 *
 * Pix needs only the name and the CPF/CNPJ. The address is asked only from customers who pay with
 * boleto, because the bank registers a boleto only with the payer's address.
 */
class BillingProfile
{
    public const STATES = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public const FIELDS = ['name', 'document', 'email', 'phone', 'payment_method', 'zip', 'street', 'number', 'complement', 'district', 'city', 'state'];

    public const ADDRESS_FIELDS = ['zip', 'street', 'number', 'complement', 'district', 'city', 'state'];

    public const PIX = 'pix';

    public const BOLETO = 'boleto';

    /**
     * Payment methods the customer can choose: Pix needs the Pix key of the operator, the boleto
     * comes with the Inter charges API.
     *
     * @return array<int, string>
     */
    public static function methods(): array
    {
        if (! InterBilling::enabled() || InterBilling::isManual()) {
            return [];
        }

        return filled(Inter\InterSettings::pixKey()) ? [self::PIX, self::BOLETO] : [self::BOLETO];
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

    public static function needsAddress(?string $chosen): bool
    {
        return self::method($chosen) === self::BOLETO;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $prefix = '', ?string $method = null): array
    {
        $address = self::needsAddress($method);

        return [
            $prefix.'name' => ['required', 'string', 'max:100'],
            $prefix.'document' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::validDocument((string) $value)) {
                    $fail(__('Invalid CPF/CNPJ.'));
                }
            }],
            $prefix.'email' => ['required', 'email', 'max:100'],
            $prefix.'phone' => ['nullable', 'string', 'max:20'],
            $prefix.'payment_method' => ['nullable', Rule::in([self::PIX, self::BOLETO])],
            $prefix.'zip' => [$address ? 'required' : 'nullable', 'regex:/^\d{5}-?\d{3}$/'],
            $prefix.'street' => [$address ? 'required' : 'nullable', 'string', 'max:90'],
            $prefix.'number' => ['nullable', 'string', 'max:10'],
            $prefix.'complement' => ['nullable', 'string', 'max:30'],
            $prefix.'district' => ['nullable', 'string', 'max:60'],
            $prefix.'city' => [$address ? 'required' : 'nullable', 'string', 'max:60'],
            $prefix.'state' => [$address ? 'required' : 'nullable', Rule::in(self::STATES)],
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
        $profile['zip'] = self::digits($profile['zip']);
        $profile['state'] = strtoupper($profile['state']);
        $profile['payment_method'] = self::method($profile['payment_method']) ?? '';
        if (! self::needsAddress($profile['payment_method']) && blank($profile['street'])) {
            // An address typed halfway is useless: kept only for boleto payers.
            foreach (self::ADDRESS_FIELDS as $field) {
                $profile[$field] = '';
            }
        }
        $tenant->update(['billing_profile' => $profile]);
        audit_log('platform.billing_profile.updated', $tenant, ['fields' => array_keys(array_filter($profile))], $tenant->team_id);
    }

    /**
     * Payer ("pagador") of the Inter charge.
     *
     * @return array<string, string>
     */
    public static function payer(PlatformTenant $tenant): array
    {
        $profile = self::of($tenant);
        $document = self::digits($profile['document']);
        $phone = self::digits($profile['phone']);
        if (strlen($phone) > 11 && str_starts_with($phone, '55')) {
            $phone = substr($phone, 2);
        }
        $payer = [
            'cpfCnpj' => $document,
            'tipoPessoa' => strlen($document) === 14 ? 'JURIDICA' : 'FISICA',
            'nome' => Str::limit($profile['name'], 100, ''),
            'endereco' => Str::limit($profile['street'], 90, ''),
            'numero' => Str::limit($profile['number'], 10, ''),
            'complemento' => Str::limit($profile['complement'], 30, ''),
            'bairro' => Str::limit($profile['district'], 60, ''),
            'cidade' => Str::limit($profile['city'], 60, ''),
            'uf' => strtoupper($profile['state']),
            'cep' => self::digits($profile['zip']),
            'email' => Str::limit($profile['email'], 100, ''),
        ];
        if (strlen($phone) >= 10) {
            $payer['ddd'] = substr($phone, 0, 2);
            $payer['telefone'] = substr($phone, 2);
        }

        return array_filter($payer, fn (string $value) => $value !== '');
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
