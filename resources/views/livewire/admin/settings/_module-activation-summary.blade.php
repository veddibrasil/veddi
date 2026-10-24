{{-- Resumo da ativação de módulo. Valores da cotação feita no servidor ao abrir o modal (BillingSettings::openActivationConfirmation). --}}
O módulo custa <strong>R$ {{ number_format($addonAmount, 2, ',', '.') }}/mês</strong> e entra na mesma fatura do seu plano.
@if($activationChargeNow === null)
    O valor cobrado agora, proporcional até o próximo vencimento, é calculado na confirmação do pagamento.
@elseif($activationChargeNow > 0)
    Cobrado agora no cartão: <strong>R$ {{ number_format($activationChargeNow, 2, ',', '.') }}</strong>@if($activationProrated), proporcional aos dias até {{ $activationNextDueDate }}@endif.
@else
    Nada é cobrado agora: o módulo entra na fatura de {{ $activationNextDueDate }}.
@endif
@if($activationNextDueDate)
    A partir de {{ $activationNextDueDate }}, sua mensalidade passa a ser <strong>R$ {{ number_format($activationMonthlyTotal, 2, ',', '.') }}/mês</strong>.
@else
    Sua mensalidade passa a ser <strong>R$ {{ number_format($activationMonthlyTotal, 2, ',', '.') }}/mês</strong>.
@endif
