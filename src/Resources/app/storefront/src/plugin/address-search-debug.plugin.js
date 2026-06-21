import Plugin from 'src/plugin-system/plugin.class';

export default class AddressSearchDebugPlugin extends Plugin {

    static options = {
        searchQuerySelector: '.address-manager-list-search',
        searchContentSelector: '.address-manager-list-wrapper',
        searchItemSelector: '.address-manager-select-address',
        searchNoItemFoundSelector: '.address-manager-no-address',
        searchEmptySateSelector: '.address-manager-empty-address',
    };

    init() {
        console.log('[AddressSearchDebug] Plugin initialized');
        console.log('[AddressSearchDebug] this.el:', this.el);
        console.log('[AddressSearchDebug] data-address-search element:', this.el);

        const searchInput = this.el.querySelector(this.options.searchQuerySelector);
        console.log('[AddressSearchDebug] Search input found:', searchInput);

        const searchContent = this.el.querySelector(this.options.searchContentSelector);
        console.log('[AddressSearchDebug] Search content wrapper:', searchContent);

        if (searchContent) {
            const children = searchContent.children;
            console.log('[AddressSearchDebug] Number of children:', children.length);
            Array.from(children).forEach((child, index) => {
                console.log(`[AddressSearchDebug] Child ${index}:`, child);
                const searchItem = child.querySelector(this.options.searchItemSelector);
                console.log(`[AddressSearchDebug]   Search item found:`, searchItem);
                if (searchItem) {
                    console.log(`[AddressSearchDebug]   Text content:`, searchItem.textContent);
                }
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', this._onSearch.bind(this));
            console.log('[AddressSearchDebug] Search event listener attached');
        }

        this._checkEmptyState();
    }

    _onSearch(event) {
        const searchQuery = event.target.value.toLowerCase();
        console.log('[AddressSearchDebug] Search query:', searchQuery);

        const searchContent = this.el.querySelector(this.options.searchContentSelector);
        const items = searchContent.children;

        Array.from(items).forEach((item, index) => {
            const searchItem = item.querySelector(this.options.searchItemSelector);
            if (searchItem) {
                const text = searchItem.textContent.toLowerCase();
                const matches = text.includes(searchQuery);
                console.log(`[AddressSearchDebug] Item ${index}: "${text.substring(0, 50)}..." matches: ${matches}`);

                if (matches) {
                    item.classList.remove('d-none');
                } else {
                    item.classList.add('d-none');
                }
            }
        });

        this._checkEmptyState(searchQuery);
    }

    _checkEmptyState(searchQuery = '') {
        const searchContent = this.el.querySelector(this.options.searchContentSelector);
        const items = searchContent.children;
        const visibleListItems = Array.from(items).filter(item => !item.classList.contains('d-none'));
        const notFound = this.el.querySelector(this.options.searchNoItemFoundSelector);
        const empty = this.el.querySelector(this.options.searchEmptySateSelector);

        console.log('[AddressSearchDebug] Visible items:', visibleListItems.length);
        console.log('[AddressSearchDebug] Not found element:', notFound);
        console.log('[AddressSearchDebug] Empty element:', empty);

        notFound.classList.add('d-none');
        empty.classList.add('d-none');

        if (visibleListItems.length !== 0) {
            return;
        }

        if (searchQuery.length === 0) {
            empty.classList.remove('d-none');
        } else {
            notFound.classList.remove('d-none');
        }
    }
}
