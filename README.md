# paypal-ipn-forwarder

A fork/adaptation of ipn-forwarder-2014.zip by Aaron Edwards of 
Incsub/UglyRobot and originally found at 

https://premium.wpmudev.org/forums/topic/multiples-ipn-dynamically-setting-the-notification-url#post-608459

Adaptations 2015 by David King.

## Original header

Plugin Name: IPN Forwarder
Description: Authenticates and forwards IPN requests to whatever scripts need them.
Author: Aaron Edwards (Incsub)
Author URI: http://uglyrobot.com
Copyright 2007-2013 Incsub (http://incsub.com)
Version: 2014

## Configuration

You can add or modify IPN URL's here. You must include
a prefix in one of the custom fields to forward it on
correctly. This will check 'PROFILEREFERENCE' (rp_invoice_id), 'custom',
'INVNUM' (invoice) in the IPN response.

Monthly IPN logs are saved to the /logs/ directory and may be pulled via FTP. 
You should probably protect the /logs/ directory from direct downloads via an 
htaccess restriction for security.

