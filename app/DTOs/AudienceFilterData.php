<?php

namespace App\DTOs;

use App\Enums\Plan;
use App\Models\EmailContactList;
use Illuminate\Support\Carbon;

/**
 * Filtros de público do e-mail marketing (segmento salvo ou filtros da própria campanha).
 * Listas de contatos podem incluir também os donos cadastrados nas empresas. Os filtros de
 * empresa se aplicam somente aos donos. fromArray é tolerante: valor fora da lista fechada é descartado,
 * nunca vira SQL.
 */
readonly class AudienceFilterData
{
    public const COMPANY_STATUSES = [
        'ACTIVE' => 'Ativa',
        'PENDING_PAYMENT' => 'Cadastro não concluído (aguardando pagamento)',
        'OVERDUE' => 'Inadimplente',
        'BLOCKED' => 'Bloqueada',
    ];

    public const ROLES = [
        'company_admin' => 'Administrador (dono)',
        'branch_manager' => 'Gerente de filial',
    ];

    /**
     * @param  list<string>  $plans
     * @param  list<string>  $companyStatuses
     * @param  list<string>  $roles
     * @param  list<int>  $contactLists
     */
    public function __construct(
        public array $plans = [],
        public array $companyStatuses = [],
        public ?bool $companyActive = null,
        public array $roles = [],
        public ?string $createdFrom = null,
        public ?string $createdTo = null,
        public ?bool $pdvEnabled = null,
        public ?bool $fiscalEnabled = null,
        public ?int $inactiveDays = null,
        public array $contactLists = [],
        public bool $includeOwners = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            plans: self::onlyAllowed($data['plans'] ?? [], Plan::values()),
            companyStatuses: self::onlyAllowed($data['company_statuses'] ?? [], array_keys(self::COMPANY_STATUSES)),
            companyActive: self::toBool($data['company_active'] ?? null),
            roles: self::onlyAllowed($data['roles'] ?? [], array_keys(self::ROLES)),
            createdFrom: self::toDate($data['created_from'] ?? null),
            createdTo: self::toDate($data['created_to'] ?? null),
            pdvEnabled: self::toBool($data['pdv_enabled'] ?? null),
            fiscalEnabled: self::toBool($data['fiscal_enabled'] ?? null),
            inactiveDays: self::toPositiveInt($data['inactive_days'] ?? null),
            contactLists: self::toIds($data['contact_lists'] ?? []),
            includeOwners: self::toBool($data['include_owners'] ?? false) ?? false,
        );
    }

    /** Formato gravado em email_segments.filters / email_campaigns.filters. */
    public function toArray(): array
    {
        return [
            'plans' => $this->plans,
            'company_statuses' => $this->companyStatuses,
            'company_active' => $this->companyActive,
            'roles' => $this->roles,
            'created_from' => $this->createdFrom,
            'created_to' => $this->createdTo,
            'pdv_enabled' => $this->pdvEnabled,
            'fiscal_enabled' => $this->fiscalEnabled,
            'inactive_days' => $this->inactiveDays,
            'contact_lists' => $this->contactLists,
            ...($this->isContacts() ? ['include_owners' => $this->includeOwners] : []),
        ];
    }

    /** Público com contatos externos (listas importadas), opcionalmente junto dos donos. */
    public function isContacts(): bool
    {
        return $this->contactLists !== [];
    }

    /** Formato dos campos do formulário Livewire (selects tri-estado usam '', '1' e '0'). */
    public function toForm(): array
    {
        $tri = fn (?bool $value): string => $value === null ? '' : ($value ? '1' : '0');

        return [
            'plans' => $this->plans,
            'company_statuses' => $this->companyStatuses,
            'company_active' => $tri($this->companyActive),
            'roles' => $this->roles,
            'created_from' => $this->createdFrom ?? '',
            'created_to' => $this->createdTo ?? '',
            'pdv_enabled' => $tri($this->pdvEnabled),
            'fiscal_enabled' => $tri($this->fiscalEnabled),
            'inactive_days' => $this->inactiveDays === null ? '' : (string) $this->inactiveDays,
        ];
    }

    /** Regras de validação dos campos do formulário (prefixo = nome da propriedade Livewire). */
    public static function rules(string $prefix = 'filters'): array
    {
        return [
            "{$prefix}.plans" => ['array'],
            "{$prefix}.plans.*" => ['in:'.implode(',', Plan::values())],
            "{$prefix}.company_statuses" => ['array'],
            "{$prefix}.company_statuses.*" => ['in:'.implode(',', array_keys(self::COMPANY_STATUSES))],
            "{$prefix}.company_active" => ['nullable', 'in:,0,1'],
            "{$prefix}.roles" => ['array'],
            "{$prefix}.roles.*" => ['in:'.implode(',', array_keys(self::ROLES))],
            "{$prefix}.created_from" => ['nullable', 'date'],
            "{$prefix}.created_to" => ['nullable', 'date', "after_or_equal:{$prefix}.created_from"],
            "{$prefix}.pdv_enabled" => ['nullable', 'in:,0,1'],
            "{$prefix}.fiscal_enabled" => ['nullable', 'in:,0,1'],
            "{$prefix}.inactive_days" => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /** @return list<string> Papéis efetivos: vazio = todos os papéis do público (config). */
    public function effectiveRoles(): array
    {
        return $this->roles !== [] ? $this->roles : array_values(array_intersect(
            config('marketing.roles', []),
            array_keys(self::ROLES),
        ));
    }

    /** @return list<string> Resumo legível dos filtros, para listas e revisão. */
    public function describe(): array
    {
        if ($this->isContacts()) {
            $names = EmailContactList::whereIn('id', $this->contactLists)->orderBy('name')->pluck('name');

            $parts = ['Contatos externos: '.($names->isEmpty() ? 'lista removida' : $names->implode(', '))];
            if ($this->includeOwners) {
                $parts[] = 'Também os donos das empresas (administradores), sem e-mails duplicados';
            }

            return $parts;
        }

        $parts = [];

        if ($this->plans !== []) {
            $parts[] = 'Plano: '.implode(', ', array_map(fn ($plan) => Plan::from($plan)->label(), $this->plans));
        }
        if ($this->companyStatuses !== []) {
            $parts[] = 'Status: '.implode(', ', array_map(fn ($status) => self::COMPANY_STATUSES[$status], $this->companyStatuses));
        }
        if ($this->companyActive !== null) {
            $parts[] = $this->companyActive ? 'Empresa ativa' : 'Empresa inativa';
        }
        if ($this->roles !== []) {
            $parts[] = 'Papel: '.implode(', ', array_map(fn ($role) => self::ROLES[$role], $this->roles));
        }
        if ($this->createdFrom || $this->createdTo) {
            $parts[] = 'Cadastro: '.($this->createdFrom ? Carbon::parse($this->createdFrom)->format('d/m/Y') : '…')
                .' a '.($this->createdTo ? Carbon::parse($this->createdTo)->format('d/m/Y') : '…');
        }
        if ($this->pdvEnabled !== null) {
            $parts[] = $this->pdvEnabled ? 'Com PDV' : 'Sem PDV';
        }
        if ($this->fiscalEnabled !== null) {
            $parts[] = $this->fiscalEnabled ? 'Com nota fiscal' : 'Sem nota fiscal';
        }
        if ($this->inactiveDays !== null) {
            $parts[] = "Sem pedidos há {$this->inactiveDays} dias";
        }

        return $parts === [] ? ['Todos os usuários das empresas'] : $parts;
    }

    /** @return list<string> */
    private static function onlyAllowed(mixed $values, array $allowed): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('strval', array_filter($values, 'is_scalar')),
            fn (string $value) => in_array($value, $allowed, true),
        )));
    }

    /** @return list<int> */
    private static function toIds(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', array_filter($values, 'is_numeric')),
            fn (int $id) => $id > 0,
        )));
    }

    private static function toBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function toDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private static function toPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? min($int, 3650) : null;
    }
}
