<?php

namespace App\Http\Requests;

use App\Enums\Plan;
use App\Rules\ReservedSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'   => ['required', 'string', 'max:100'],
            'slug'           => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/', new ReservedSlug, 'unique:companies,slug'],
            'plan'           => ['required', 'in:' . implode(',', Plan::values())],
            'asaas_cpf_cnpj' => ['required', 'string', 'min:11', 'max:18'],
            'branch_name'    => ['required', 'string', 'max:100'],
            'branch_phone'   => ['required', 'string', 'max:20'],
            'user_name'      => ['required', 'string', 'max:100'],
            'user_email'     => ['required', 'email', 'unique:users,email'],
            'user_password'  => ['required', Password::min(8)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique'              => 'Este endereço já está em uso.',
            'slug.regex'               => 'O endereço só pode conter letras minúsculas, números e hífens.',
            'slug.reserved_slug'       => 'Este endereço é reservado pelo sistema e não pode ser utilizado.',
            'user_email.unique'        => 'Este e-mail já está cadastrado.',
            'plan.in'                  => 'Selecione um plano válido.',
            'asaas_cpf_cnpj.required'  => 'CPF ou CNPJ é obrigatório para criação da conta de pagamento.',
            'user_password.min'        => 'A senha deve ter no mínimo 8 caracteres.',
        ];
    }
}
