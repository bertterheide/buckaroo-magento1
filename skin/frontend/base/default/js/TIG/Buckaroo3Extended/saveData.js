jQuery('.buckaroo3extended_input').find('input,select').live('change', function() {
        sendData(jQuery(this));
    }
);

jQuery('#buckaroo3extended_directdebit_account_owner, #buckaroo3extended_directdebit_account_number').live('change', function() {
        sendData(jQuery(this));
    }
);
function sendData(element)
{
     jQuery.post(
         '<?php echo $this->getUrl('buckaroo3extended/checkout/saveData');?>',
         {
             name: element.attr('name'),
             value: element.val()
         }
     );
}