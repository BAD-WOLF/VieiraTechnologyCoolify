<?php

namespace App\Services\Platform;

use App\Models\PlatformTenant;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Payer details of a tenant (required by the bank to register a boleto), stored encrypted in
 * platform_tenants.billing_profile.
 */
class BillingProfile
{
    public const STATES = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public const FIELDS = ['name', 'document', 'email', 'phone', 'zip', 'street', 'number', 'complement', 'district', 'city', 'state'];

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:100'],
            $prefix.'document' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::validDocument((string) $value)) {
                    $fail(__('Invalid CPF/CNPJ.'));
                }
            }],
            $prefix.'email' => ['required', 'email', 'max:100'],
            $prefix.'phone' => ['nullable', 'string', 'max:20'],
            $prefix.'zip' => ['required', 'regex:/^\d{5}-?\d{3}$/'],
            $prefix.'street' => ['required', 'string', 'max:90'],
            $prefix.'number' => ['nullable', 'string', 'max:10'],
            $prefix.'complement' => ['nullable', 'string', 'max:30'],
            $prefix.'district' => ['nullable', 'string', 'max:60'],
            $prefix.'city' => ['required', 'string', 'max:60'],
            $prefix.'state' => ['required', Rule::in(self::STATES)],
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
        return validator(self::of($tenant), self::rules())->passes();
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
