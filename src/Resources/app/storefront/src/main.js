import TopdataAddressValidator from './plugin/swiss-post-validator.plugin';
import TopdataZipAutocomplete from './plugin/swiss-post-autocomplete.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('TopdataAddressValidator', TopdataAddressValidator, '[data-topdata-address-validator]');
PluginManager.register('TopdataZipAutocomplete', TopdataZipAutocomplete, '[data-topdata-zip-autocomplete]');
