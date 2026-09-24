@props(['save' => ['save'], 'dirtyOn' => []])

{{--
    Avisa antes de descartar edições não salvas ao sair da tela (link do menu, recarregar, fechar a aba).
    Coloque como primeiro filho do componente Livewire.
    - save: ações que gravam ou trocam o formulário (limpam o aviso; se a validação falhar, ele volta).
    - dirtyOn: ações que mudam o formulário sem passar por um campo (ex.: adicionar uma linha).
      Campos sincronizados com o servidor (wire:model.live, $wire.set) já contam sozinhos.
    - data-unsaved-ignore: marque o que não faz parte do formulário (busca, filtros).
--}}
<div
    hidden
    x-data="{
        dirty: false,
        resets: @js(array_values((array) $save)),
        marks: @js(array_values((array) $dirtyOn)),
        root: null,
        handlers: null,
        offCommit: null,
        init() {
            const message = 'Você tem alterações não salvas. Se sair agora, elas serão descartadas.';

            this.root = this.$wire.$el;
            this.handlers = {
                input: (e) => {
                    if (! e.target.closest('[data-unsaved-ignore]')) this.dirty = true;
                },
                navigate: (e) => {
                    if (this.dirty && ! window.confirm(message)) e.preventDefault();
                },
                unload: (e) => {
                    if (! this.dirty) return;
                    e.preventDefault();
                    e.returnValue = '';
                },
            };

            this.root.addEventListener('input', this.handlers.input);
            this.root.addEventListener('change', this.handlers.input);
            document.addEventListener('livewire:navigate', this.handlers.navigate);
            window.addEventListener('beforeunload', this.handlers.unload);

            this.offCommit = Livewire.hook('commit', ({ component, commit, succeed, fail }) => {
                if (component.el !== this.root) return;

                const calls = commit.calls.map((call) => call.method);

                if (! calls.some((method) => this.resets.includes(method))) {
                    // Campo sincronizado (wire:model.live, $wire.set) ou ação que mexe no formulário.
                    if (calls.some((method) => this.marks.includes(method)) || Object.keys(commit.updates || {}).length) this.dirty = true;
                    return;
                }

                // Limpa já no envio: o redirect do save descarrega a página antes da resposta ser processada.
                this.dirty = false;
                succeed(({ snapshot }) => {
                    try {
                        const memo = (typeof snapshot === 'string' ? JSON.parse(snapshot) : snapshot).memo;
                        if (memo && memo.errors && Object.keys(memo.errors).length) this.dirty = true;
                    } catch (e) {
                        this.dirty = true;
                    }
                });
                fail(() => { this.dirty = true; });
            });
        },
        destroy() {
            if (! this.handlers) return;

            this.root.removeEventListener('input', this.handlers.input);
            this.root.removeEventListener('change', this.handlers.input);
            document.removeEventListener('livewire:navigate', this.handlers.navigate);
            window.removeEventListener('beforeunload', this.handlers.unload);
            if (typeof this.offCommit === 'function') this.offCommit();
        },
    }"
></div>
