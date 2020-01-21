import Plugin from 'src/plugin-system/plugin.class';

export default class HeidelpayInvoiceGuaranteedPlugin extends Plugin {
    static options = {
        isB2BCustomer: false,
        customerInfo: null,
    };

    /**
     * @type {Object}
     *
     * @public
     */
    static invoiceGuaranteed;

    /**
     * @type {HeidelpayBasePlugin}
     *
     * @private
     */
    static heidelpayPlugin = null;

    /**
     * @type {object}
     */
    static b2bCustomerProvider = null;

    init() {
        console.log(this.options.customerInfo);

        this.heidelpayPlugin = window.PluginManager.getPluginInstances('HeidelpayBase')[0];
        this.invoiceGuaranteed = this.heidelpayPlugin.heidelpayInstance.InvoiceGuaranteed();

        if (this.options.isB2BCustomer) {
            this._createB2bForm();
        }

        this._registerEvents();
    }

    _createB2bForm() {
        this.b2bCustomerProvider = this.heidelpayPlugin.heidelpayInstance.B2BCustomer();

        this.b2bCustomerProvider.b2bCustomerEventHandler = (event) => this._onValidateB2bForm(event);
        this.b2bCustomerProvider.initFormFields(this._getB2bCustomerObject());

        this.b2bCustomerProvider.create({
            containerId: 'heidelpay-b2b-form',
        });
    }

    _onValidateB2bForm(event) {
        this.heidelpayPlugin.setSubmitButtonActive(event.success);
    }

    _registerEvents() {
        this.heidelpayPlugin.$emitter.subscribe('heidelpayBase_createResource', () => this._onCreateResource(), {
            scope: this,
        });
    }

    _onCreateResource() {
        this.heidelpayPlugin.setSubmitButtonActive(false);

        if (this.options.isB2BCustomer) {
            this.b2bCustomerProvider.createCustomer().then((data) => {
                this._onB2bCustomerCreated(data.id);
            }).catch((error) => {
                this._handleError(error);
            });
        } else {
            this.invoiceGuaranteed.createResource()
                .then((resource) => this._submitPayment(resource))
                .catch((error) => this._handleError(error));
        }
    }

    /**
     * @param {string} b2bCustomerId
     * @private
     */
    _onB2bCustomerCreated(b2bCustomerId) {
        const resourceIdElement = document.getElementById('heidelpayCustomerId');
        resourceIdElement.value = b2bCustomerId;

        this.invoiceGuaranteed.createResource()
            .then((resource) => {
                this._submitPayment(resource);
            })
            .catch((error) => {
                this._handleError(error);
            });
    }

    /**
     * @param {Object} resource
     * @private
     */
    _submitPayment(resource) {
        this.heidelpayPlugin.submitResource(resource);
    }

    /**
     * @param {Object} error
     *
     * @private
     */
    _handleError(error) {
        this.heidelpayPlugin.showError(error);
    }

    _getB2bCustomerObject() {
        return {
            firstname: this.options.customerInfo.firstName,
            lastname: this.options.customerInfo.lastName,
            company: this.options.customerInfo.activeBillingAddress.company,
            salutation: this.options.customerInfo.salutation.salutationKey,
            birthDate: this.options.customerInfo.lastName.birthday,
            email: this.options.customerInfo.email,
            billingAddress: {
                street: this.options.customerInfo.activeBillingAddress.street,
                zip: this.options.customerInfo.activeBillingAddress.zipcode,
                city: this.options.customerInfo.activeBillingAddress.city,
                country: this.options.customerInfo.activeBillingAddress.country.name,
            },
            shippingAddress: {
                street: this.options.customerInfo.activeShippingAddress.street,
                zip: this.options.customerInfo.activeShippingAddress.zipcode,
                city: this.options.customerInfo.activeShippingAddress.city,
                country: this.options.customerInfo.activeShippingAddress.country.name,
            },
        }
    }
}
