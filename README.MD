## Migrate to FluentCart from other WordPress eCommerce plugins

This document provides a checklist to help you migrate your eCommerce store from other eCommerce Plugins to FluentCart
using this FluentCart Migrator plugin.

**Currently Supported Migrations:**

- [x] Easy Digital Downloads (EDD):
    - [x] Subscriptions (Including Live Recurring Payments)
    - [x] Coupons
    - [x] Customers
    - [x] Products
    - [x] Orders (including activities, notes, items)
    - [x] License Keys (Your old license keys and endpoints will continue to work as before)
    - [x] Order ID and receipt numbers are preserved

- [ ] WooCommerce (Upcoming)
- [ ] SureCart (Upcoming)

## Migration Process for EDD

1. **Setup FluentCart**: Ensure that FluentCart is properly set up with your currency, business details, payment
   gateways, etc.
2. **Enable Required Modules**: Make sure that the necessary FluentCart modules that you have on your EDD store (like
   Subscriptions, Coupons, Licensing etc.) are enabled to accommodate the data being migrated.
3. **Backup Your Store**: Before starting the migration, back up your entire Easy Digital Downloads store, including the
   database and files.
4. **Install FluentCart Migrator**: Install and activate the FluentCart Migrator plugin (this plugin) on your WordPress
   site.
5. **Run Migration Command**: Use WP-CLI to run the migration command. SSH into your server, navigate to your WordPress
   installation directory, and execute the following command:
   ```
   wp fluent_cart_migrator migrate_from_edd --all
   ```
6. **Wait for Completion**: Allow the migration process to complete. This may take some time depending on the size of
   your store.
7. **Verify Migration**: After the migration is complete, verify that all products, customers, orders, coupons,
   subscriptions, and license keys have been migrated correctly to FluentCart.
8. **Update FluentCart Settings**: Adjust any necessary settings in FluentCart to match your previous store setup.
9. **Update Checkout and Cart Pages**: Change your website's checkout and cart pages to use FluentCart shortcodes.
10. **Test Checkout Process**: Perform thorough testing of the checkout process to ensure everything is functioning
    correctly.
11. **Deactivate EDD**: If you no longer need Easy Digital Downloads, deactivate and uninstall the plugin.
12. **Clean Up Database**: Optionally, you may remove all the `edd_` prefixed tables from your database if you are sure
    everything is working fine.
13. **Monitor Store**: Keep an eye on your store for any issues and address them as needed.

### Additional Notes (If you are using Licensing Module)

- After migration, you may need to manually Licensing Configuration in FluentCart Products and set your License Limits,
  Product Version Numbers, Changelog etc. Only these data are not migrated automatically.
- Your existing software license keys and endpoints will continue to work as before without any changes.
- We recommend to migrate our [FluentCart Licensing SDK](https://github.com/fluent-cart/software-licensing-sdk) in your
  software for better experience.
- Keep the fluent-cart-migrator plugin activated if you have Licening module

### Additional Notes

- FluentCart does not migrate your draft/trashed/abandoned orders
- Please do test run on a staging site first before running on production
- Keep the fluent-cart-migrator plugin activated if you have old PayPal standard subscriptions.

### Support

- If you run a big store and need assistance with the migration, feel free to reach out to our support team for help.
  Our Founder Shahjahan Jewel will help you personally with the migration.
- For any issues or feature requests related to the migration process, please open an issue this repository on GitHub.

### Edge Cases

If you are using multi-currency in EDD and convert your store currency during migration, please use the code snippet:

**Snippet to convert from GBP to USD with currency conversion:**

```php 
add_filter('fluentcart_migrator_edd3_to_cents', function ($cents, $number, $currency) {
    if ($currency === 'GBP') {
        $cents = $cents * 1.32;
    }
    return $cents;
}, 10, 3);
```

### Contribution

- Contributions to enhance the migration process are welcome. Feel free to fork the repository and submit pull requests
  with improvements or additional migration features.

