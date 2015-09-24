//{block name="backend/customer/view/list/list" append}
//{namespace name="backend/swag_customer_preferences/main"}
//Ext.define('Shopware.apps.Customer.view.list.List.customer_preferences.List', {
//
//    /**
//     * Defines an override applied to a class.
//     * @string
//     */
//    override: 'Shopware.apps.Customer.view.list.List',
//
//    /**
//     * Overrides the getColumns function of the overridden ExtJs object
//     * and inserts two new columns
//     * @return
//     */
//    getColumns: function() {
//        var me = this;
//
//        var columns = me.callParent(arguments);
//
//        var columnSize= {
//            header: '{s name=preferences/shoe_size}Shoe size{/s}',
//            dataIndex:'swagCustomerPreferencesSize',
//            flex: 1
//        };
//        var columnColor = {
//            header: '{s name=preferences/color}Color{/s}',
//            dataIndex:'swagCustomerPreferencesColor',
//            flex: 1
//        };
//
//        return Ext.Array.insert(columns, 8, [columnSize, columnColor]);
//    }
//});
//{/block}