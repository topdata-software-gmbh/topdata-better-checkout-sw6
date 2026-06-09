import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import Debouncer from 'src/helper/debouncer.helper';

const LOG_PREFIX = '[TopdataSW6 Validator]';

export default class TopdataAddressValidator extends Plugin {
    static options = {
        validateUrl: '/bettercheckoutsw6/swiss-post/validate',
        countryIdsUrl: null,
        countrySelectSelector: '.country-select',
        zipInputSelector: 'input[name$="[zipcode]"], input[name="zipcode"]',
        cityInputSelector: 'input[name$="[city]"], input[name="city"]',
        streetInputSelector: 'input[name$="[street]"], input[name="street"]',
        firstNameInputSelector: 'input[name$="[firstName]"], input[name="firstName"]',
        lastNameInputSelector: 'input[name$="[lastName]"], input[name="lastName"]',
    };

    init() {
        this._client = new HttpClient();
        this._supportedCountryIds = null;

        console.log(LOG_PREFIX, 'Plugin initializing on element:', this.el);

        this._initElements();

        if (!this.countrySelect) {
            console.warn(LOG_PREFIX, 'Country select not found with selector:', this.options.countrySelectSelector);
            console.warn(LOG_PREFIX, 'Validator cannot function without a country selector — plugin inactive');
            return;
        }

        if (!this.zipInput) {
            console.warn(LOG_PREFIX, 'ZIP input not found with selector:', this.options.zipInputSelector);
        }

        this._fetchCountryIds();
        this._registerEvents();

        console.log(LOG_PREFIX, 'Plugin initialized. Elements found —',
            'country:', !!this.countrySelect,
            'zip:', !!this.zipInput,
            'city:', !!this.cityInput,
            'street:', !!this.streetInput,
            'firstName:', !!this.firstNameInput,
            'lastName:', !!this.lastNameInput,
            'widget:', !!this.widget);
    }

    _findElement(selector) {
        return this.el.querySelector(selector) || this.el.closest('form')?.querySelector(selector);
    }

    _initElements() {
        this.countrySelect = this.el.querySelector(this.options.countrySelectSelector);
        this.zipInput = this.el.querySelector(this.options.zipInputSelector);
        this.cityInput = this.el.querySelector(this.options.cityInputSelector);
        this.streetInput = this.el.querySelector(this.options.streetInputSelector);
        this.firstNameInput = this._findElement(this.options.firstNameInputSelector);
        this.lastNameInput = this._findElement(this.options.lastNameInputSelector);
        this.widget = this.el.querySelector('[data-swiss-post-validation]');

        if (!this.widget) {
            console.warn(LOG_PREFIX, 'Validation widget not found — address validation UI will not appear. Look for [data-swiss-post-validation] in the DOM.');
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
        if (!this.countrySelect) return false;
        if (!this._supportedCountryIds) return true;

        const selectedOption = this.countrySelect.options[this.countrySelect.selectedIndex];
        return selectedOption && this._supportedCountryIds.includes(selectedOption.value);
    }

    _registerEvents() {
        if (!this.countrySelect || !this.zipInput) {
            console.warn(LOG_PREFIX, 'Cannot register events — countrySelect:', !!this.countrySelect, 'zipInput:', !!this.zipInput);
            return;
        }

        const debouncedValidate = Debouncer.debounce(this._onValidate.bind(this), 400);
        this.el.addEventListener('input', (e) => {
            if (e.target.matches('input')) {
                debouncedValidate();
            }
        });

        this.countrySelect.addEventListener('change', this._onCountryChange.bind(this));
        this._onCountryChange();

        console.log(LOG_PREFIX, 'Event listeners registered');
    }

    _onCountryChange() {
        if (this._isCountrySupported()) {
            if (this.widget) {
                this.widget.classList.remove('d-none');
            }
            console.log(LOG_PREFIX, 'Supported country selected — validation active');
            this._onValidate();
        } else {
            if (this.widget) {
                this.widget.classList.add('d-none');
            }
            console.log(LOG_PREFIX, 'Non-supported country selected — validation hidden');
        }
    }

    _onValidate() {
        const address = this._getAddressPayload();

        if (!address.firstName || !address.lastName || !address.street || !address.zipcode || !address.city) {
            console.debug(LOG_PREFIX, 'Validation skipped — not all fields filled');
            this._updateWidgetState('default');
            return;
        }

        console.log(LOG_PREFIX, 'Sending validation request for address:', {
            street: address.street,
            zipcode: address.zipcode,
            city: address.city,
            countryCode: address.countryCode,
        });

        this._client.post(this.options.validateUrl, JSON.stringify({ address }), (response) => {
            try {
                const data = JSON.parse(response);
                console.log(LOG_PREFIX, 'Validation response:', data);
                if (data.success) {
                    if (['CERTIFIED', 'DOMICILE_CERTIFIED'].includes(data.quality)) {
                        this._updateWidgetState('certified');
                    } else if (data.quality === 'USABLE') {
                        this._updateWidgetState('usable');
                    } else {
                        this._updateWidgetState('not-certified');
                    }
                } else {
                    this._updateWidgetState('error', this.widget?.dataset?.errorMessage || 'Address validation failed');
                }
            } catch (e) {
                console.error(LOG_PREFIX, 'Validation response parse error:', e);
                this._updateWidgetState('error', 'Malformed response');
            }
        });
    }

    _getAddressPayload() {
        const selectedOption = this.countrySelect.options[this.countrySelect.selectedIndex];
        return {
            firstName: this.firstNameInput ? this.firstNameInput.value.trim() : '',
            lastName: this.lastNameInput ? this.lastNameInput.value.trim() : '',
            street: this.streetInput ? this.streetInput.value.trim() : '',
            zipcode: this.zipInput ? this.zipInput.value.trim() : '',
            city: this.cityInput ? this.cityInput.value.trim() : '',
            countryId: selectedOption ? selectedOption.value : '',
        };
    }

    _updateWidgetState(state, errorMsg = '') {
        if (!this.widget) {
            console.warn(LOG_PREFIX, 'Cannot update widget state — widget element not found');
            return;
        }

        console.log(LOG_PREFIX, 'Widget state changed to:', state, errorMsg ? '(' + errorMsg + ')' : '');

        const msgs = this.widget.querySelectorAll('.status-msg');
        msgs.forEach(msg => msg.classList.add('d-none'));

        const activeMsg = this.widget.querySelector(`.status-${state}`);
        if (activeMsg) {
            activeMsg.classList.remove('d-none');
            if (state === 'error') {
                activeMsg.querySelector('.error-details').textContent = errorMsg;
            }
        }
    }
}
