{{-- Campos de cartão + titular com máscaras. Grava em cardNumber, cardExpiry, cardCvv, cardHolderName,
     cardCpfCnpj, cardPostalCode e cardAddressNumber do BillingSettings (limpos depois de cada tentativa). --}}
<div
    x-data="{
        maskCardNumber(e) {
            let v = e.target.value.replace(/\D/g, '').substring(0, 16);
            v = v.replace(/(\d{4})(?=\d)/g, '$1 ');
            e.target.value = v;
            $wire.set('cardNumber', v);
        },
        maskExpiry(e) {
            let v = e.target.value.replace(/\D/g, '').substring(0, 4);
            if (v.length >= 3) v = v.substring(0, 2) + '/' + v.substring(2);
            e.target.value = v;
            $wire.set('cardExpiry', v);
        },
        maskCvv(e) {
            let v = e.target.value.replace(/\D/g, '').substring(0, 4);
            e.target.value = v;
            $wire.set('cardCvv', v);
        },
        maskCpfCnpj(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length <= 11) {
                v = v.substring(0, 11)
                     .replace(/(\d{3})(\d)/, '$1.$2')
                     .replace(/(\d{3})(\d)/, '$1.$2')
                     .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            } else {
                v = v.substring(0, 14)
                     .replace(/(\d{2})(\d)/, '$1.$2')
                     .replace(/(\d{3})(\d)/, '$1.$2')
                     .replace(/(\d{3})(\d)/, '$1/$2')
                     .replace(/(\d{4})(\d{1,2})$/, '$1-$2');
            }
            e.target.value = v;
            $wire.set('cardCpfCnpj', v);
        },
        maskCep(e) {
            let v = e.target.value.replace(/\D/g, '').substring(0, 8);
            if (v.length > 5) v = v.substring(0, 5) + '-' + v.substring(5);
            e.target.value = v;
            $wire.set('cardPostalCode', v);
        },
        maskHolderName(e) {
            e.target.value = e.target.value.toUpperCase();
            $wire.set('cardHolderName', e.target.value);
        },
    }"
    class="space-y-5"
>
    <div>
        <flux:input
            wire:model="cardNumber"
            x-on:input="maskCardNumber($event)"
            label="Número do cartão"
            placeholder="0000 0000 0000 0000"
            maxlength="19"
            inputmode="numeric"
            autocomplete="cc-number"
        />
        @error('cardNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <flux:input
                wire:model="cardExpiry"
                x-on:input="maskExpiry($event)"
                label="Validade"
                placeholder="MM/AA"
                maxlength="5"
                inputmode="numeric"
                autocomplete="cc-exp"
            />
            @error('cardExpiry') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <flux:input
                wire:model="cardCvv"
                x-on:input="maskCvv($event)"
                label="CVV"
                placeholder="123"
                maxlength="4"
                inputmode="numeric"
                autocomplete="cc-csc"
                type="password"
                viewable
            />
            @error('cardCvv') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <flux:input
            wire:model="cardHolderName"
            x-on:input="maskHolderName($event)"
            label="Nome conforme no cartão"
            placeholder="NOME SOBRENOME"
            autocomplete="cc-name"
        />
        @error('cardHolderName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <hr class="border-zinc-100 dark:border-zinc-700">
    <p class="text-xs text-neutral-600 dark:text-neutral-400 -mt-2">Dados do titular para cobrança</p>

    <div>
        <flux:input
            wire:model="cardCpfCnpj"
            x-on:input="maskCpfCnpj($event)"
            label="CPF ou CNPJ do titular"
            placeholder="000.000.000-00"
            maxlength="18"
            inputmode="numeric"
        />
        @error('cardCpfCnpj') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <flux:input
                wire:model="cardPostalCode"
                x-on:input="maskCep($event)"
                label="CEP"
                placeholder="00000-000"
                maxlength="9"
                inputmode="numeric"
            />
            @error('cardPostalCode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <flux:input
                wire:model="cardAddressNumber"
                label="Número"
                placeholder="123"
            />
            @error('cardAddressNumber') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>
</div>
