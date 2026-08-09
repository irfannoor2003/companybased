import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

window.Alpine = Alpine;

Alpine.plugin(collapse);

document.addEventListener('alpine:init', () => {
    Alpine.store('toasts', {
        items: [],
        push(message, type = 'success') {
            const id = Math.random().toString(36).slice(2);

            this.items.push({ id, message, type });
            this.$nextTick(() => {
                setTimeout(() => this.dismiss(id), 4200);
            });
        },
        dismiss(id) {
            this.items = this.items.filter((toast) => toast.id !== id);
        },
    });
});

function appShell() {
    return {
        drawerOpen: false,
        collapsed: localStorage.getItem('cb-sidebar') === 'collapsed',
        hover: false,
        isDark: window.__cbTheme === 'dark',

        init() {
            this.$watch('collapsed', (value) => {
                localStorage.setItem('cb-sidebar', value ? 'collapsed' : 'expanded');
            });
        },

        toggleTheme() {
            this.isDark = !this.isDark;
            document.documentElement.classList.toggle('dark', this.isDark);
            localStorage.setItem('cb-theme', this.isDark ? 'dark' : 'light');
        },
    };
}

window.appShell = appShell;

function permissionMatrix() {
    return {
        toggleModule(module, checked) {
            document.querySelectorAll(`[data-perm-module="${module}"]`).forEach((el) => {
                el.checked = checked;
            });
        },
        checkAllPermissions(checked) {
            document.querySelectorAll('[name="permissions[]"]').forEach((el) => {
                el.checked = checked;
            });
        },
    };
}

window.permissionMatrix = permissionMatrix;

Alpine.start();
