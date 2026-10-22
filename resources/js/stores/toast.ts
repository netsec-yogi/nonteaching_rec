import { defineStore } from 'pinia';

export interface Toast { id: number; message: string; variant: 'success' | 'danger' | 'warning' | 'info' }

let seq = 0;

export const useToast = defineStore('toast', {
    state: () => ({ items: [] as Toast[] }),
    actions: {
        show(message: string, variant: Toast['variant'] = 'success', ms = 4500) {
            const id = ++seq;
            this.items.push({ id, message, variant });
            setTimeout(() => this.dismiss(id), ms);
        },
        success(message: string) { this.show(message, 'success'); },
        error(message: string) { this.show(message, 'danger', 7000); },
        dismiss(id: number) { this.items = this.items.filter((t) => t.id !== id); },
    },
});
