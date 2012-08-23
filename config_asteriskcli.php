<?php
/* This file can be overrided by SugarRoot/config_override.php and SugarRoot/config_asteriskcli.php */

$sugar_config['asteriskcli_external_channel_pattern'] = '/^SIP\/uplink-/';
$sugar_config['asteriskcli_internal_channel_pattern'] = '/^SIP\/\d{3,4}-/';
$sugar_config['asteriskcli_incoming_number_ltrim'] = '';
$sugar_config['asteriskcli_outgoing_number_ltrim'] = '/^.+\//'; // or '/^.+\/12345/' where isd 12345 is predial string of uplink
$sugar_config['asteriskcli_callout_prefix'] = '9'; // digit added to phone from sugar, not used atm, will be used in click-to-call
$sugar_config['asteriskcli_extension_max_len'] = '4';
$sugar_config['asteriskcli_external_phone_min_len'] = '7';
