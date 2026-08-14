import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

window.Alpine = Alpine;

Alpine.plugin(collapse);

document.addEventListener('alpine:init', () => {
    Alpine.store('toasts', {
        items: [],
        push(message, type = 'success') {
            const id = Math.random().toString(36).slice(2);

            this.items.push({ id, message, type, visible: true });
            this.$nextTick(() => {
                setTimeout(() => this.dismiss(id), 4200);
            });
        },
        dismiss(id) {
            this.items = this.items.filter((toast) => toast.id !== id);
        },
    });

    Alpine.data('currencyFromEntity', () => ({
        initCurrencySelect() {
            const sel = this.$el.querySelector('[name="customer_id"], [name="supplier_id"]');
            if (sel && sel.value) {
                this.syncCurrency(sel);
            }
        },
        syncCurrency(sel) {
            const cur = sel.selectedOptions[0]?.dataset?.currency || '';
            if (cur) {
                const curField = this.$el.querySelector('[name="currency"]');
                if (curField) {
                    curField.value = cur;
                }
            }
        },
    }));

    Alpine.data('shortCodeSuggest', (suggestUrl) => ({
        auto: false,
        suggestShortCode() {
            const nameField = this.$el.querySelector('[name="company_name"]');
            const codeField = this.$el.querySelector('[name="short_code"]');
            if (!nameField || !codeField) {
                return;
            }
            const name = (nameField.value || '').trim();
            if (!name) {
                return;
            }
            if (!codeField.value || this.auto) {
                fetch(`${suggestUrl}?name=${encodeURIComponent(name)}`)
                    .then((res) => res.json())
                    .then((data) => {
                        if (data.code) {
                            codeField.value = data.code;
                            this.auto = true;
                        }
                    })
                    .catch(() => {});
            }
        },
        markTouched() {
            this.auto = false;
        },
    }));
});

function appShell() {
    return {
        drawerOpen: false,
        collapsed: localStorage.getItem('cb-sidebar') === 'collapsed',
        isDark: window.__cbTheme === 'dark',
        isDesktop: window.innerWidth >= 1024,

        init() {
            this.$watch('collapsed', (value) => {
                localStorage.setItem('cb-sidebar', value ? 'collapsed' : 'expanded');
            });

            const onResize = () => {
                this.isDesktop = window.innerWidth >= 1024;
                if (this.isDesktop) {
                    this.drawerOpen = true;
                }
            };

            onResize();
            window.addEventListener('resize', onResize);
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
