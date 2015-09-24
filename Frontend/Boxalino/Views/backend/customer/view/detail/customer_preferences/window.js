//{block name="backend/customer/view/detail/window" append}
//{namespace name="backend/swag_customer_preferences/main"}
Ext.define('Shopware.apps.Customer.view.detail.customer_preferences.Window', {

    /**
     * Defines an override applied to a class.
     * @string
     */
    override: 'Shopware.apps.Customer.view.detail.Window',

    /**
     * Override the createFormTab method and append our own fieldSet
     * @return
     */
    createFormTab: function() {
        var me = this;

        // Call the original method and store its result
        var formTab = me.callParent(arguments);

        // Create our new fieldSet
        var fieldSet = Ext.create('Shopware.apps.Customer.view.detail.customer_preferences.Preferences');

        // After the (overridden) createFormTab method was called,
        // we are save to access the detailForm attribute
        me.detailForm.insert(1, fieldSet);

        // Return original method's result
        return formTab;
    }
});
//{/block}