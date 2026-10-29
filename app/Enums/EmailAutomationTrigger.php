<?php

namespace App\Enums;

enum EmailAutomationTrigger: string
{
    // Empresa criada no /cadastro e ainda em PENDING_PAYMENT (taxa de ativação não paga).
    case AbandonedSignup = 'abandoned_signup';
    // Empresa ativa sem nenhum pedido há N dias.
    case InactiveCompany = 'inactive_company';
    // Empresa ativa cadastrada há pouco (boas-vindas e primeiros passos).
    case NewCompany = 'new_company';

    public function label(): string
    {
        return match ($this) {
            self::AbandonedSignup => 'Cadastro abandonado',
            self::InactiveCompany => 'Empresa sem pedidos',
            self::NewCompany => 'Empresa nova',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AbandonedSignup => 'Empresa que se cadastrou e não pagou a taxa de ativação. Sai da sequência quando a empresa é ativada.',
            self::InactiveCompany => 'Empresa ativa sem pedidos há N dias. Sai da sequência quando volta a receber pedidos.',
            self::NewCompany => 'Empresa ativa cadastrada nos últimos 7 dias, N horas depois do cadastro (plano pago entra quando a ativação é paga). Sai se a empresa deixar de estar ativa.',
        };
    }

    /** Horas (cadastro abandonado, empresa nova) ou dias (sem pedidos) até a empresa entrar na sequência. */
    public function defaultThreshold(): int
    {
        return match ($this) {
            self::AbandonedSignup => 2,
            self::InactiveCompany => 30,
            self::NewCompany => 1,
        };
    }

    public function thresholdLabel(): string
    {
        return match ($this) {
            self::AbandonedSignup, self::NewCompany => 'Horas desde o cadastro',
            self::InactiveCompany => 'Dias sem pedidos',
        };
    }

    public function thresholdUnit(): string
    {
        return $this === self::InactiveCompany ? 'dias' : 'h';
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
