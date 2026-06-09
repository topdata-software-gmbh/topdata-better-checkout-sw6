import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import Debouncer from 'src/helper/debouncer.helper';

const LOG_PREFIX = '[TopdataSW6 Autocomplete]';

export default class TopdataZipAutocomplete extends Plugin {
    static options = {
        autocompleteUrl: '/bettercheckoutsw6/swiss-post/autocomplete',
        autocompleteStreetUrl: '/bettercheckoutsw6/swiss-post/autocomplete-street',
        autocompleteHouseNumberUrl: '/bettercheckoutsw6/swiss-post/autocomplete-house-number',
        countryIdsUrl: null,
        countrySelectSelector: '.country-select',
        zipInputSelector: 'input[name$="[zipcode]"], input[name="zipcode"]',
        cityInputSelector: 'input[name$="[city]"], input[name="city"]',
        streetInputSelector: 'input[name$="[street]"], input[name="street"]',
    };

    init() {
        this._client = new HttpClient();
        this._supportedCountryIds = null;
        this._dropdownActive = null;

        console.log(LOG_PREFIX, 'Plugin initializing on element:', this.el);

        this._initElements();

        if (!this.zipInput && !this.streetInput) {
            console.warn(LOG_PREFIX, 'No ZIP or street input found — plugin will not activate');
            return;
        }

        if (!this.countrySelect) {
            console.warn(LOG_PREFIX, 'Country select not found — autocomplete will not filter by country');
        }

        this._fetchCountryIds();
        this._registerEvents();

        console.log(LOG_PREFIX, 'Plugin initialized. ZIP input:', !!this.zipInput,
            'City input:', !!this.cityInput,
            'Street input:', !!this.streetInput,
            'Country select:', !!this.countrySelect);
    }

    _initElements() {
        this.countrySelect = this.el.querySelector(this.options.countrySelectSelector);
        this.zipInput = this.el.querySelector(this.options.zipInputSelector);
        this.cityInput = this.el.querySelector(this.options.cityInputSelector);
        this.streetInput = this.el.querySelector(this.options.streetInputSelector);

        if (!this.zipInput) {
            console.debug(LOG_PREFIX, 'ZIP input not found with selector:', this.options.zipInputSelector);
        }
        if (!this.cityInput) {
            console.debug(LOG_PREFIX, 'City input not found with selector:', this.options.cityInputSelector);
        }
        if (!this.streetInput) {
            console.debug(LOG_PREFIX, 'Street input not found with selector:', this.options.streetInputSelector);
        }
        if (!this.countrySelect) {
            console.debug(LOG_PREFIX, 'Country select not found with selector:', this.options.countrySelectSelector);
        }
    }

    _fetchCountryIds() {
        const url = this.options.countryIdsUrl;
        if (!url) {
            console.warn(LOG_PREFIX, 'countryIdsUrl is empty — cannot fetch country IDs');
            return;
        }

        console.log(LOG_PREFIX, 'Fetching country IDs from:', url);

        this._client.get(url, (response) => {
            try {
                this._supportedCountryIds = JSON.parse(response);
                console.log(LOG_PREFIX, 'Supported country IDs loaded:', this._supportedCountryIds);
            } catch (e) {
                console.error(LOG_PREFIX, 'Failed to parse country IDs response:', e, response);
                this._supportedCountryIds = null;
            }
        });
    }

    _isCountrySupported() {
        if (!this.countrySelect) {
            console.debug(LOG_PREFIX, 'Country select missing — cannot determine country');
            return false;
        }
        if (!this._supportedCountryIds) {
            return true;
        }

        const selectedOption = this.countrySelect.options[this.countrySelect.selectedIndex];
        const isSupported = selectedOption && this._supportedCountryIds.includes(selectedOption.value);
        console.debug(LOG_PREFIX, 'Country check — selected:', selectedOption?.value, 'supported:', isSupported);
        return isSupported;
    }

    _registerEvents() {
        if (this.zipInput) {
            const debouncedZipAutocomplete = Debouncer.debounce(this._onZipAutocomplete.bind(this), 300);
            this.zipInput.addEventListener('input', (e) => {
                debouncedZipAutocomplete(e.target.value);
            });
            this.zipInput.addEventListener('keydown', this._onKeydown.bind(this));
            console.log(LOG_PREFIX, 'ZIP input event listeners registered');
        }

        if (this.streetInput) {
            const debouncedStreetAutocomplete = Debouncer.debounce(this._onStreetAutocomplete.bind(this), 300);
            this.streetInput.addEventListener('input', (e) => {
                debouncedStreetAutocomplete(e.target.value);
            });
            this.streetInput.addEventListener('keydown', this._onHouseNumberKeydown.bind(this));
            console.log(LOG_PREFIX, 'Street input event listeners registered');
        }

        document.addEventListener('click', (e) => {
            if (this._dropdownActive && !this._dropdownActive.contains(e.target)) {
                this._closeDropdown();
            }
        });
    }

    _onZipAutocomplete(query) {
        if (query.length < 2) {
            this._closeDropdown();
            return;
        }

        if (!this._isCountrySupported()) {
            console.debug(LOG_PREFIX, 'ZIP autocomplete skipped — country not supported');
            this._closeDropdown();
            return;
        }

        const url = `${this.options.autocompleteUrl}?query=${encodeURIComponent(query)}`;
        console.log(LOG_PREFIX, 'ZIP autocomplete request:', url);

        this._client.get(url, (response) => {
            try {
                const data = JSON.parse(response);
                console.log(LOG_PREFIX, 'ZIP autocomplete results:', data.length, 'items');
                this._renderZipDropdown(data);
            } catch (e) {
                console.error(LOG_PREFIX, 'ZIP autocomplete parse error:', e);
                this._closeDropdown();
            }
        });
    }

    _onStreetAutocomplete(query) {
        if (query.length < 2) {
            this._closeDropdown();
            return;
        }

        if (!this._isCountrySupported()) {
            this._closeDropdown();
            return;
        }

        const zip = this.zipInput ? this.zipInput.value.trim() : '';
        if (!zip) {
            console.debug(LOG_PREFIX, 'Street autocomplete skipped — no ZIP code entered yet');
            this._closeDropdown();
            return;
        }

        const url = `${this.options.autocompleteStreetUrl}?query=${encodeURIComponent(query)}&zip=${encodeURIComponent(zip)}`;
        console.log(LOG_PREFIX, 'Street autocomplete request:', url);

        this._client.get(url, (response) => {
            try {
                const data = JSON.parse(response);
                console.log(LOG_PREFIX, 'Street autocomplete results:', data.length, 'items');
                this._renderStreetDropdown(data);
            } catch (e) {
                console.error(LOG_PREFIX, 'Street autocomplete parse error:', e);
                this._closeDropdown();
            }
        });
    }

    _renderZipDropdown(items) {
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
                this._selectZipItem(item);
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
        this._dropdownActive = dropdown;
    }

    _renderStreetDropdown(items) {
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
            btn.innerHTML = `<strong>${item.street}</strong> ${item.zip} ${item.city}`;
            btn.addEventListener('click', () => {
                this._selectStreetItem(item);
            });
            btn.addEventListener('mouseenter', () => {
                const allItems = dropdown.querySelectorAll('.list-group-item');
                allItems.forEach(i => i.classList.remove('active'));
                btn.classList.add('active');
            });
            dropdown.appendChild(btn);
        });

        this.streetInput.parentNode.style.position = 'relative';
        this.streetInput.parentNode.appendChild(dropdown);
        this._dropdownActive = dropdown;
    }

    _selectZipItem(item) {
        console.log(LOG_PREFIX, 'ZIP selected:', item.zip, item.city);
        this.zipInput.value = item.zip;
        this.cityInput.value = item.city;
        this.zipInput.dispatchEvent(new Event('input', { bubbles: true }));
        this.cityInput.dispatchEvent(new Event('input', { bubbles: true }));
        this._closeDropdown();
    }

    _selectStreetItem(item) {
        console.log(LOG_PREFIX, 'Street selected:', item.street, item.zip, item.city);
        this.streetInput.value = item.street;
        if (this.zipInput && !this.zipInput.value) {
            this.zipInput.value = item.zip;
        }
        if (this.cityInput && !this.cityInput.value) {
            this.cityInput.value = item.city;
        }
        this.streetInput.dispatchEvent(new Event('input', { bubbles: true }));
        this._closeDropdown();
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

    _onHouseNumberKeydown(e) {
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

    _closeDropdown() {
        if (this._dropdownActive) {
            this._dropdownActive.remove();
            this._dropdownActive = null;
        }
    }
}
