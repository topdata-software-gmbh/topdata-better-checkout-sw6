import Plugin from 'src/plugin-system/plugin.class';
import HttpClient from 'src/service/http-client.service';
import Debouncer from 'src/helper/debouncer.helper';

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
        this._initElements();
        this._fetchCountryIds();
        this._registerEvents();
    }

    _initElements() {
        this.countrySelect = this.el.querySelector(this.options.countrySelectSelector);
        this.zipInput = this.el.querySelector(this.options.zipInputSelector);
        this.cityInput = this.el.querySelector(this.options.cityInputSelector);
        this.streetInput = this.el.querySelector(this.options.streetInputSelector);
        this.firstNameInput = this.el.querySelector(this.options.firstNameInputSelector);
        this.lastNameInput = this.el.querySelector(this.options.lastNameInputSelector);
        this.widget = this.el.querySelector('[data-swiss-post-validation]');
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

    _isCountrySupported() {
        if (!this.countrySelect) return false;
        if (!this._supportedCountryIds) return true;

        const selectedOption = this.countrySelect.options[this.countrySelect.selectedIndex];
        return selectedOption && this._supportedCountryIds.includes(selectedOption.value);
    }

    _registerEvents() {
        if (!this.countrySelect || !this.zipInput) return;

        const debouncedValidate = Debouncer.debounce(this._onValidate.bind(this), 400);
        this.el.addEventListener('input', (e) => {
            if (e.target.matches('input')) {
                debouncedValidate();
            }
        });

        this.countrySelect.addEventListener('change', this._onCountryChange.bind(this));
        this._onCountryChange();
    }

    _onCountryChange() {
        if (this._isCountrySupported()) {
            this.widget.classList.remove('d-none');
            this._onValidate();
        } else {
            this.widget.classList.add('d-none');
        }
    }

    _onValidate() {
        const address = this._getAddressPayload();

        if (!address.firstName || !address.lastName || !address.street || !address.zipcode || !address.city) {
            this._updateWidgetState('default');
            return;
        }

        this._client.post(this.options.validateUrl, JSON.stringify({ address }), (response) => {
            try {
                const data = JSON.parse(response);
                if (data.success) {
                    if (['CERTIFIED', 'DOMICILE_CERTIFIED'].includes(data.quality)) {
                        this._updateWidgetState('certified');
                    } else {
                        this._updateWidgetState('not-certified');
                    }
                } else {
                    this._updateWidgetState('error', data.error || 'Server validation error');
                }
            } catch (e) {
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
            countryCode: selectedOption ? selectedOption.getAttribute('data-country-iso') : 'CH',
        };
    }

    _updateWidgetState(state, errorMsg = '') {
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
