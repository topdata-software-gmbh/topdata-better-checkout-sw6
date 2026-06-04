import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import Debouncer from 'src/helper/debouncer.helper';

export default class TopdataZipAutocomplete extends Plugin {
    static options = {
        autocompleteUrl: '/bettercheckoutsw6/swiss-post/autocomplete',
        countryIdsUrl: null,
        countrySelectSelector: '.country-select',
        zipInputSelector: 'input[name$="[zipcode]"], input[name="zipcode"]',
        cityInputSelector: 'input[name$="[city]"], input[name="city"]',
    };

    init() {
        this._client = new HttpClient();
        this._initElements();
        this._fetchCountryIds();
        this._registerEvents();
    }

    _initElements() {
        this.countrySelect = this.el.querySelector(this.options.countrySelectSelector);
        this.zipInput = this.el.querySelector(this.options.zipInputSelector);
        this.cityInput = this.el.querySelector(this.options.cityInputSelector);
    }

    _fetchCountryIds() {
        this._supportedCountryIds = null;
        const url = this.options.countryIdsUrl;
        if (!url) return;

        this._client.get(url, (response) => {
            try {
                this._supportedCountryIds = JSON.parse(response);
            } catch (e) {
                this._supportedCountryIds = null;
            }
        });
    }

    _registerEvents() {
        if (!this.zipInput) return;

        const debouncedAutocomplete = Debouncer.debounce(this._onAutocomplete.bind(this), 300);
        this.zipInput.addEventListener('input', (e) => {
            debouncedAutocomplete(e.target.value);
        });

        this.zipInput.addEventListener('keydown', this._onKeydown.bind(this));

        document.addEventListener('click', (e) => {
            if (!this.zipInput.contains(e.target)) {
                this._closeDropdown();
            }
        });
    }

    _onKeydown(e) {
        const dropdown = this.el.querySelector('.swiss-post-autocomplete-dropdown');
        if (!dropdown) return;

        const items = dropdown.querySelectorAll('.list-group-item');
        if (items.length === 0) return;

        const currentIndex = Array.from(items).findIndex(item => item.classList.contains('active'));

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this._highlightItem(items, Math.min(currentIndex + 1, items.length - 1));
                this._scrollToItem(items[Math.min(currentIndex + 1, items.length - 1)]);
                break;
            case 'ArrowUp':
                e.preventDefault();
                this._highlightItem(items, Math.max(currentIndex - 1, 0));
                this._scrollToItem(items[Math.max(currentIndex - 1, 0)]);
                break;
            case 'Enter':
                e.preventDefault();
                if (currentIndex >= 0) {
                    items[currentIndex].click();
                }
                break;
            case 'Escape':
                e.preventDefault();
                this._closeDropdown();
                break;
        }
    }

    _highlightItem(items, index) {
        items.forEach(item => item.classList.remove('active'));
        items[index].classList.add('active');
    }

    _scrollToItem(item) {
        const dropdown = item.closest('.swiss-post-autocomplete-dropdown');
        if (!dropdown) return;
        const itemRect = item.getBoundingClientRect();
        const containerRect = dropdown.getBoundingClientRect();
        if (itemRect.bottom > containerRect.bottom) {
            item.scrollIntoView({ block: 'nearest' });
        } else if (itemRect.top < containerRect.top) {
            item.scrollIntoView({ block: 'nearest' });
        }
    }

    _isCountrySupported() {
        if (!this.countrySelect) return false;
        if (!this._supportedCountryIds) return true;

        const selectedOption = this.countrySelect.options[this.countrySelect.selectedIndex];
        return selectedOption && this._supportedCountryIds.includes(selectedOption.value);
    }

    _onAutocomplete(query) {
        if (query.length < 2 || !this._isCountrySupported()) {
            this._closeDropdown();
            return;
        }

        this._client.get(`${this.options.autocompleteUrl}?query=${encodeURIComponent(query)}`, (response) => {
            try {
                const data = JSON.parse(response);
                this._renderDropdown(data);
            } catch (e) {
                this._closeDropdown();
            }
        });
    }

    _renderDropdown(items) {
        this._closeDropdown();
        if (items.length === 0) return;

        const dropdown = document.createElement('div');
        dropdown.className = 'swiss-post-autocomplete-dropdown list-group position-absolute w-100 shadow-sm';
        dropdown.style.zIndex = '1000';
        dropdown.style.maxHeight = '240px';
        dropdown.style.overflowY = 'auto';

        items.forEach((item) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-2 text-start';
            btn.innerHTML = `<strong>${item.zip}</strong> ${item.city}`;
            btn.addEventListener('click', () => {
                this._selectItem(item, btn);
            });
            btn.addEventListener('mouseenter', () => {
                const allItems = dropdown.querySelectorAll('.list-group-item');
                allItems.forEach(i => i.classList.remove('active'));
                btn.classList.add('active');
            });
            dropdown.appendChild(btn);
        });

        this.zipInput.parentNode.style.position = 'relative';
        this.zipInput.parentNode.appendChild(dropdown);
    }

    _selectItem(item, btn) {
        this.zipInput.value = item.zip;
        this.cityInput.value = item.city;
        this.zipInput.dispatchEvent(new Event('input', { bubbles: true }));
        this.cityInput.dispatchEvent(new Event('input', { bubbles: true }));
        this._closeDropdown();
    }

    _closeDropdown() {
        const active = this.el.querySelector('.swiss-post-autocomplete-dropdown');
        if (active) {
            active.remove();
        }
    }
}
