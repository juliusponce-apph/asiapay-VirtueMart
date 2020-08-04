# PayDollar/SaimPay/PesoPay Payment plugin for VirtueMart
Use PayDollar/SaimPay/PesoPays plugin for VirtueMart to offer ALL payments option.

## Integration
The plugin integrates VirtueMart with PayDollar/SaimPay/PesoPay payment gateway with All payment method.

## Requirements
This plugin supports Joomla (3.X) and VirtueMart (3.X) and higher.

## Installation
1.	Go to Extension Manager then Upload and Install the plugin.
2.	After successful installation, search for the plug-in **'VMPAYMENT_ASIAPAY'** and enable it.
3.	Go to ***Components > VirtueMart***. Then go to Payment Methods. Then click **'New'**. Setup the Payment Method Accordingly. Important to set Published to **'Yes '** and set the Payment Method to **'VMPAYMENT_ASIAPAY'** Then click the **'Save'** button
4.	Once saved, the **'Configuration'** Tab is now available to be configured. Configure accordingly then click the **'Save & Close'** button

## Setup the Datafeed URL on PayDollar/PesoPay/SiamPay
 1. Login to your PayDollar/PesoPay/SiamPay account.
 2. After login, Go to left sidebar on Profile > Profile Settings > Payment Options.
 3. Click the “Enable” radio and set the datafeed URL on “Return Value Link” and click the “Update” button. The datafeed URL should be like this: http://www.yourstore.com/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component
 4. On the confirmation page, review your changes then click the “Confirm button”.

 ## Documentation
[VirtueMart documentation](https://github.com/asiapay-lib/asiapay-VirtueMart/blob/master/Joomla%20VirtueMart%203.X%20Plugin%20Setup%20Guide%2020150408.pdf)

## Support
If you have a feature request, or spotted a bug or a technical problem, create a GitHub issue. For other questions, contact our [Customer Service](https://www.paydollar.com/en/contactus.html).

## License
MIT license. For more information, see the LICENSE file.
