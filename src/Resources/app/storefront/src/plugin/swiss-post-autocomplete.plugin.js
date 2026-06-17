import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import Debouncer from 'src/helper/debouncer.helper';

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
        houseNumberInputSelector: 'input[data-topdata-house-number]',
    };

    init() {
        this._client = new HttpClient();
        this._supportedCountryIds = null;
        this._dropdownActive = null;
        this._activeInput = null;
        this._suppressAutocomplete = false;
        this._streetAutocompleteZip = '';

        this._initElements();

        if (!this.zipInput && !this.cityInput) {
            return;
        }

        this._fetchCountryIds();
        this._registerEvents();
    }

    _initElements() {
        this.countrySelect = this.el.querySelector(this.options.countrySelectSelector);
        this.zipInput = this.el.querySelector(this.options.zipInputSelector);
        this.cityInput = this.el.querySelector(this.options.cityInputSelector);
        this.streetInput = this.el.querySelector(this.options.streetInputSelector);
        this.houseNumberInput = this.el.querySelector(this.options.houseNumberInputSelector);
    }

    _fetchCountryIds() {
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

    _isCountrySupported() {
        if (!this.countrySelect) return false;
        if (!this._supportedCountryIds) return true;

        const selectedOption = this.countrySelect.options[this.countrySelect.selectedIndex];
        if (!selectedOption) return false;
        if (!selectedOption.value) return true;

        return this._supportedCountryIds.includes(selectedOption.value);
    }

    _registerEvents() {
        if (this.zipInput) {
            const debounced = Debouncer.debounce(this._onAutocomplete.bind(this), 300);
            this.zipInput.addEventListener('input', (e) => {
                if (this._suppressAutocomplete) return;
                this._activeInput = 'zip';
                debounced(e.target.value);
            });
            this.zipInput.addEventListener('keydown', this._onKeydown.bind(this));
        }

        if (this.cityInput) {
            const debounced = Debouncer.debounce(this._onAutocomplete.bind(this), 300);
            this.cityInput.addEventListener('input', (e) => {
                if (this._suppressAutocomplete) return;
                this._activeInput = 'city';
                debounced(e.target.value);
            });
            this.cityInput.addEventListener('keydown', this._onKeydown.bind(this));
        }

        if (this.streetInput) {
            const debounced = Debouncer.debounce(this._onStreetAutocomplete.bind(this), 300);
            this.streetInput.addEventListener('input', (e) => {
                if (this._suppressAutocomplete) return;
                debounced(e.target.value);
            });
            this.streetInput.addEventListener('keydown', this._onKeydown.bind(this));
        }

        if (this.houseNumberInput) {
            const debounced = Debouncer.debounce(this._onHouseNumberAutocomplete.bind(this), 300);
            this.houseNumberInput.addEventListener('input', (e) => {
                if (this._suppressAutocomplete) return;
                debounced(e.target.value);
            });
            this.houseNumberInput.addEventListener('focus', () => {
                if (this._suppressAutocomplete) return;
                const value = this.houseNumberInput.value.trim();
                if (value) {
                    this._onHouseNumberAutocomplete(value);
                }
            });
            this.houseNumberInput.addEventListener('keydown', this._onKeydown.bind(this));
        }

        document.addEventListener('click', (e) => {
            if (this._dropdownActive && !this._dropdownActive.contains(e.target)) {
                this._closeDropdown();
            }
        });

        this._registerFormSubmitHandler();
    }

    _registerFormSubmitHandler() {
        const form = this.el.closest('form');
        if (!form || !this.streetInput || !this.houseNumberInput) return;

        form.addEventListener('submit', () => {
            const streetVal = this.streetInput.value.trim();
            const houseNumVal = this.houseNumberInput.value.trim();

            if (houseNumVal && streetVal && !streetVal.endsWith(houseNumVal)) {
                this.streetInput.value = streetVal + ' ' + houseNumVal;
            }
        });
    }

    _onAutocomplete(query) {
        if (query.length < 2) {
            this._closeDropdown();
            return;
        }

        if (!this._isCountrySupported()) {
            this._closeDropdown();
            return;
        }

        const url = `${this.options.autocompleteUrl}?query=${encodeURIComponent(query)}`;

        this._client.get(url, (response) => {
            try {
                const data = JSON.parse(response);
                this._renderDropdown(data);
            } catch (e) {
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
        this._streetAutocompleteZip = zip;
        let url = `${this.options.autocompleteStreetUrl}?query=${encodeURIComponent(query)}`;
        if (zip) {
            url += `&zip=${encodeURIComponent(zip)}`;
        }

        this._client.get(url, (response) => {
            try {
                const data = JSON.parse(response);
                this._renderStreetDropdown(data);
            } catch (e) {
                this._closeDropdown();
            }
        });
    }

    _onHouseNumberAutocomplete(query) {
        if (query.length < 1) {
            this._closeDropdown();
            return;
        }

        if (!this._isCountrySupported()) {
            this._closeDropdown();
            return;
        }

        const street = this.streetInput ? this.streetInput.value.trim() : '';
        const zip = this.zipInput ? this.zipInput.value.trim() : '';

        if (!street || !zip) {
            this._closeDropdown();
            return;
        }

        const url = `${this.options.autocompleteHouseNumberUrl}?query=${encodeURIComponent(query)}&street=${encodeURIComponent(street)}&zip=${encodeURIComponent(zip)}`;

        this._client.get(url, (response) => {
            try {
                const data = JSON.parse(response);
                this._renderHouseNumberDropdown(data);
            } catch (e) {
                this._closeDropdown();
            }
        });
    }

    _renderDropdown(items) {
        this._closeDropdown();
        if (!items || items.length === 0) return;

        const inputEl = this._activeInput === 'city' ? this.cityInput : this.zipInput;
        if (!inputEl) return;

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
                this._selectItem(item);
            });
            btn.addEventListener('mouseenter', () => {
                const allItems = dropdown.querySelectorAll('.list-group-item');
                allItems.forEach(i => i.classList.remove('active'));
                btn.classList.add('active');
            });
            dropdown.appendChild(btn);
        });

        inputEl.parentNode.style.position = 'relative';
        inputEl.parentNode.appendChild(dropdown);
        this._dropdownActive = dropdown;
    }

    _renderStreetDropdown(items) {
        this._closeDropdown();
        if (!items || items.length === 0) return;

        if (!this.streetInput) return;

        const zip = this.zipInput ? this.zipInput.value.trim() : '';
        const city = this.cityInput ? this.cityInput.value.trim() : '';
        const hasLocation = !!(zip || city);

        const dropdown = document.createElement('div');
        dropdown.className = 'swiss-post-autocomplete-dropdown list-group position-absolute w-100 shadow-sm';
        dropdown.style.zIndex = '1000';
        dropdown.style.maxHeight = '240px';
        dropdown.style.overflowY = 'auto';

        items.forEach((item) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-2 text-start';
            const label = hasLocation
                ? `${item.street} <span class="text-muted">(${zip} ${city})</span>`
                : item.street;
            btn.innerHTML = label;
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

    _renderHouseNumberDropdown(items) {
        this._closeDropdown();
        if (!items || items.length === 0) return;

        if (!this.houseNumberInput) return;

        const dropdown = document.createElement('div');
        dropdown.className = 'swiss-post-autocomplete-dropdown list-group position-absolute w-100 shadow-sm';
        dropdown.style.zIndex = '1000';
        dropdown.style.maxHeight = '240px';
        dropdown.style.overflowY = 'auto';

        items.forEach((item) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-2 text-start';
            btn.innerHTML = `<strong>${item.houseNumber}</strong>`;
            btn.addEventListener('click', () => {
                this._selectHouseNumberItem(item);
            });
            btn.addEventListener('mouseenter', () => {
                const allItems = dropdown.querySelectorAll('.list-group-item');
                allItems.forEach(i => i.classList.remove('active'));
                btn.classList.add('active');
            });
            dropdown.appendChild(btn);
        });

        this.houseNumberInput.parentNode.style.position = 'relative';
        this.houseNumberInput.parentNode.appendChild(dropdown);
        this._dropdownActive = dropdown;
    }

    _selectStreetItem(item) {
        if (this.streetInput) {
            this.streetInput.value = item.street;
        }
        if (item.zip && this.zipInput && !this.zipInput.value.trim()) {
            this.zipInput.value = item.zip;
        }
        this._suppressAutocomplete = true;
        if (this.streetInput) {
            this.streetInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (item.zip && this.zipInput && !this.zipInput.value.trim()) {
            this.zipInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        this._suppressAutocomplete = false;
        this._closeDropdown();
    }

    _selectHouseNumberItem(item) {
        if (this.houseNumberInput) {
            this.houseNumberInput.value = item.houseNumber;
        }
        if (item.street && this.streetInput) {
            const currentStreet = this.streetInput.value.trim();
            const fullStreet = item.street + ' ' + item.houseNumber;
            if (currentStreet !== fullStreet) {
                this.streetInput.value = fullStreet;
            }
        }
        if (item.zip && this.zipInput && !this.zipInput.value.trim()) {
            this.zipInput.value = item.zip;
        }
        if (item.city && this.cityInput && !this.cityInput.value.trim()) {
            this.cityInput.value = item.city;
        }

        this._suppressAutocomplete = true;
        if (this.streetInput) {
            this.streetInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (item.zip && this.zipInput && !this.zipInput.value.trim()) {
            this.zipInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (item.city && this.cityInput && !this.cityInput.value.trim()) {
            this.cityInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        this._suppressAutocomplete = false;
        this._closeDropdown();
    }

    _selectItem(item) {
        if (this.zipInput) {
            this.zipInput.value = item.zip;
        }
        if (this.cityInput) {
            this.cityInput.value = item.city;
        }
        if (this.countrySelect && item.countryId) {
            this.countrySelect.value = item.countryId;
            this.countrySelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
        this._suppressAutocomplete = true;
        if (this.zipInput) {
            this.zipInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (this.cityInput) {
            this.cityInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        this._suppressAutocomplete = false;
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
        this._activeInput = null;
        this._streetAutocompleteZip = '';
    }
}
