window.reportTooltip = () => ({
    open: false,
    pointerActivation: false,
    closeTimer: null,

    destroy() {
        clearTimeout(this.closeTimer);
    },

    show() {
        clearTimeout(this.closeTimer);
        if (!this.$refs.panel.matches(':popover-open')) this.$refs.panel.showPopover();
        this.position();
    },

    hide() {
        clearTimeout(this.closeTimer);
        if (this.$refs.panel.matches(':popover-open')) this.$refs.panel.hidePopover();
    },

    closeSoon() {
        clearTimeout(this.closeTimer);
        this.closeTimer = setTimeout(() => {
            if (!this.$el.matches(':hover') && !this.$refs.trigger.matches(':focus-visible')) this.hide();
        }, 150);
    },

    position() {
        const panel = this.$refs.panel;
        const rect = this.$refs.trigger.getBoundingClientRect();
        const margin = 8;
        const gap = 8;
        const width = document.documentElement.clientWidth;
        const height = window.innerHeight;
        panel.style.maxHeight = `${Math.max(0, height - margin * 2)}px`;
        const panelRect = panel.getBoundingClientRect();
        const above = rect.top - margin - gap;
        const below = height - rect.bottom - margin - gap;
        const placeAbove = above >= panelRect.height || above > below;
        const available = Math.max(0, placeAbove ? above : below);
        panel.style.maxHeight = `${available}px`;
        panel.style.left = `${Math.max(margin, Math.min(rect.left + (rect.width - panelRect.width) / 2, width - panelRect.width - margin))}px`;
        panel.style.top = `${placeAbove ? Math.max(margin, rect.top - Math.min(panelRect.height, available) - gap) : Math.max(margin, rect.bottom + gap)}px`;
        panel.style.visibility = 'visible';
    },
});
