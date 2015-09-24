/**
 * Shopware 4.0
 * Copyright © 2012 shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 *
 * @category   Shopware
 * @package    Customer
 * @subpackage SwagCustomerPreferences
 * @copyright  Copyright (c) 2012, shopware AG (http://www.shopware.de)
 * @author shopware AG
 */

//{namespace name=backend/swag_customer_preferences/main}
Ext.define('Shopware.apps.Customer.view.detail.customer_preferences.Preferences', {
    /**
     * Define that the base field set is an extension of the Ext.form.FieldSet
     * @string
     */
    extend:'Ext.form.FieldSet',

    /**
     * Set css class for this component
     * @string
     */
    cls: Ext.baseCSSPrefix + 'preferences-field-set',

    /**
     * Layout type for the component.
     * @string
     */
    layout: 'column',

    /**
     * Component event method which is fired when the component
     * is initials. The component is initials when the user
     * want to create a new customer or edit an existing customer
     * @return void
     */
    initComponent:function () {
        var me = this;
        me.title = '{s name=preferences/title}Customers preferences{/s}';

        me.items = me.createForm();
        me.callParent(arguments);
    },


    /**
     * Creates both containers for the field set
     * to display the form fields in two columns.
     *
     * @return Array Contains the left and right container
     */
    createForm:function () {
        var leftContainer, rightContainer, me = this;

        leftContainer = Ext.create('Ext.container.Container', {
            columnWidth:0.5,
            border:false,
            cls: Ext.baseCSSPrefix + 'field-set-container',
            layout:'anchor',
            items:me.createFormLeft()
        });

        rightContainer = Ext.create('Ext.container.Container', {
            columnWidth:0.5,
            border:false,
            layout:'anchor',
            cls: Ext.baseCSSPrefix + 'field-set-container',
            items: me.createFormRight()
        });

        return [ leftContainer, rightContainer ];
    },

    /**
     * Creates the left container of the base field set.
     *
     * @return Array Contains the different form field of the left container
     */
    createFormLeft:function () {
        var me = this;

        me.customerShoeSize = Ext.create('Ext.form.field.Number', {
            name:'attribute[swagCustomerPreferencesSize]',
            fieldLabel:'{s name=preferences/shoe_size}Shoe size{/s}',
            minValue: 1,
            allowBlank: true,
            anchor:'95%',
            labelWidth:150,
            minWidth:250
        });


        return [
            me.customerShoeSize
        ];
    },

    /**
     * Creates the right container of the base field set.
     *
     * @return Array Contains the three form fields
     */
    createFormRight:function () {
        var me = this;

        me.customerColor = Ext.create('Ext.form.field.Text', {
            name:'attribute[swagCustomerPreferencesColor]',
            fieldLabel:'{s name=preferences/color}Color{/s}',
            anchor:'95%',
            labelWidth:100,
            minWidth:250
        });

        return [
            me.customerColor
        ];
    }
});