<?php
/**
 * @TODO split this class
 */
class AsteriskEventsObserver implements ObserverInterface
{
    protected $_log;
    protected $_lang;
    protected $aster_log;
    protected $sugar_call;
    protected $sugar_user;
    protected $sugar_phones;
    protected $patterns;

    protected $local_len = 3;
    protected $external_len = 10;

    protected $timestamp;
    protected $timestamp_gmt;

    /**
     * @param LoggerInterface $log
     * @param AsteriskLanguage $lang
     * @param AsteriskCall $aster_log
     * @param SugarCall $sugar_call
     * @param SugarUserDb $sugar_user
     * @param SugarPhonesDb $sugar_phones
     * @param AsteriskDialPatterns $patterns
     */
    public function __construct(
        LoggerInterface $log,
        AsteriskLanguage $lang,
        AsteriskCall $aster_log,
        SugarCall $sugar_call,
        SugarUserDb $sugar_user,
        SugarPhonesDb $sugar_phones,
        AsteriskDialPatterns $patterns
    ) {
        $this->_log = $log;
        $this->_lang = $lang;
        $this->aster_log = $aster_log;
        $this->sugar_call = $sugar_call;
        $this->sugar_user = $sugar_user;
        $this->sugar_phones = $sugar_phones;
        $this->patterns = $patterns;
    }


    /**
     * New event coming.
     * If exists - call method with event name
     * @param ObservableInterface $source
     * @param $eventType
     * @param null $params
     */
    public function notify(ObservableInterface $source, $eventType, &$params = null)
    {
        if ($eventType == 'Event') {
            $this->timestamp = date('Y-m-d H:i:s');
            $this->timestamp_gmt = gmdate('Y-m-d H:i:s');

            $event_name = 'event'.$params['Event'];

            if (method_exists($this, $event_name)) {
                $this->$event_name($params);
            }
        }
    }

    protected function eventNewstate(&$arr) {
        if ($arr['Channelstatedesc'] == 'Ring' && $this->isExternalChannel($arr['Channel'])) {
            $this->incomingCallExternalChannel($arr);
            return;
        }

        if ($arr['Channelstatedesc'] == 'Ringing' && $this->isInternalChannel($arr['Channel'])) {
            $this->incomingCallInternalChannel($arr);
            return;
        }
    }

    protected function eventDial(&$arr) {
        if ($arr['Subevent'] == 'Begin' && $this->isInternalChannel($arr['Channel']) && $this->isExternalChannel($arr['Destination'])) {
            $this->outgoingCall($arr);
            return;
        }
    }

    protected function outgoingCall(&$arr) {
        //Event: Dial
        //Privilege: call,all
        //SubEvent: Begin
        //Channel: SIP/xxx-...
        //Destination: SIP/...
        //CallerIDNum: xxx
        //CallerIDName:
        //ConnectedLineNum: <unknown>
        //ConnectedLineName: <unknown>
        //UniqueID: xxxxxxxxxx.xxx
        //DestUniqueID: xxxxxxxxxx.xxx
        //Dialstring: .../xxx...

        $extension = $arr['Calleridnum'];
        if (!$extension)
            return; // We don't want to track unknown extension

        $phone = $this->cleanDialstring($arr['Dialstring']);

        if (!$this->isExternalNumber($phone) || !$this->isInternalNumber($extension)) {
            $this->log("Outgoing call, but Extension({$extension}) OR Destination({$phone}) number is not correct", 'DEBUG');
            return;
        }

        $this->log("Outbound call #{$arr['Uniqueid']} from {$extension} to {$phone}", 'DEBUG');

        $this->aster_log->create_new_record(
            array(
                'asterisk_id' => $arr['Uniqueid'],
                'asterisk_dest_id' => $arr['Destuniqueid'],
                'callstate' => 'Dial',
                'callerID' => $phone,
                'timestampCall' => $this->timestamp,
                'direction' => 'O',
                'channel' => $arr['Channel'],
                'remote_channel' => $arr['Destination'],
                'extension' => $extension
            )
        );
    }

    /**
     * I want to trace all incoming calls from city for detect - is call was answered or no. If call was answered, nothing to do with this call
     * because we create Call record in other place. But if this call never was in Bridge state - need to create Missed Call and assign to
     * found Account. Why here and not in eventNewstateRinging? Well, one incoming call can ringing to a lot of office extensions. And in some cases we
     * don't know which call is it (call from queue, for example, we have no uniqueid of incoming call, only ConnectedLineNum)
     * @param array $arr
     */
    protected function incomingCallExternalChannel(&$arr) {
        //Event: Newstate
        //Privilege: call,all
        //Channel: SIP/...
        //ChannelState: 4
        //ChannelStateDesc: Ring
        //CallerIDNum: xxxxxxxxxx
        //CallerIDName:
        //ConnectedLineNum:
        //ConnectedLineName:
        //Uniqueid: xxxxxxxxxx.xxx

        $phone = $this->cleanIncomingCallerID($arr['Calleridnum']);
        if (!$this->isExternalNumber($phone)) {
            $this->log("Incoming call, but CallerID {$arr['Calleridnum']} (after strip: {$phone}) is not correct", 'DEBUG');
            return;
        }

        $this->log("Inbound call #{$arr['Uniqueid']} from phone {$phone}", 'DEBUG');

        // Incoming call from city
        $this->aster_log->create_new_record(
            array(
                'asterisk_id' => $arr['Uniqueid'],
                'callstate' => 'Dial',
                'callerID' => $phone,
                'timestampCall' => $this->timestamp,
                'direction' => 'I',
                'channel' => $arr['Channel']
            )
        );
    }

    /**
     * Here is our extension ringing. We are not creating Call record here, because it can create too many trash records.
     * @param array $arr
     */
    protected function incomingCallInternalChannel(&$arr) {
        //Event: Newstate
        //Privilege: call,all
        //Channel: SIP/xxx-...
        //ChannelState: 5
        //ChannelStateDesc: Ringing
        //CallerIDNum: xxx
        //CallerIDName: ...
        //ConnectedLineNum: xxxxxxxxxx - <CallerID>
        //ConnectedLineName:
        //Uniqueid: xxxxxxxxxx.xxx

        $extension = $arr['Calleridnum'];
        $phone = $this->cleanIncomingCallerID($arr['Connectedlinenum']);

        if (!$this->isInternalNumber($extension)) {
            $this->log("Incoming call, but destination Extension number ({$extension}) is not correct", 'DEBUG');
            return;
        }

        // Here is only one way to detect ext to ext calls - check ConnectedLineNum phone number. This number is CallerId of call
        // even if it's Queue call. As simple way - we can check length of this number.
        if (!$this->patterns->isExternalNumber($phone))
            return; // extension to extension call

        $this->log("Inbound call #{$arr['Uniqueid']} from phone {$phone} to $extension", 'DEBUG');

        if (empty($extension)) // No need to track unknown destination
            return;

        // Office phone ringing with call from city
        $this->aster_log->create_new_record(
            array(
                'asterisk_id' => $arr['Uniqueid'],
                'callstate' => 'Ringing',
                'callerID' => $phone,
                'timestampCall' => $this->timestamp,
                'direction' => 'I',
                'channel' => $arr['Channel'],
                'extension' => $extension
            )
        );
    }

    /**
     * @param array $arr
     */
    protected function eventBridge(&$arr) {
        //Event: Bridge
        //Privilege: call,all
        //Bridgestate: Link
        //Bridgetype: core
        //Channel1: SIP/...
        //Channel2: SIP/...
        //Uniqueid1: xxxxxxxxxx.xxx
        //Uniqueid2: xxxxxxxxxx.xxx
        //CallerID1: xxxxxxxxxx
        //CallerID2: xxx

        // Lets check is any external channels in bridge, just for save database requests
        // And also lets find, which channel is external and which is internal
        $phone = null;
        $extension = null;
        $ext_id = null;
        $int_id = null;
        $remote_channel = null;
        $local_channel = null;

        if ($this->isExternalChannel($arr['Channel1'])) {
            $phone = $arr['Callerid1'];
            $extension = $arr['Callerid2'];
            $ext_id = $arr['Uniqueid1'];
            $int_id = $arr['Uniqueid2'];
            $remote_channel = $arr['Channel1'];
            $local_channel = $arr['Channel2'];
        }

        if ($this->isExternalChannel($arr['Channel2'])) {
            $phone = $arr['Callerid2'];
            $extension = $arr['Callerid1'];
            $ext_id = $arr['Uniqueid2'];
            $int_id = $arr['Uniqueid1'];
            $remote_channel = $arr['Channel2'];
            $local_channel = $arr['Channel1'];
        }

        if (!$phone)
            return;
        $phone = $this->cleanIncomingCallerID($phone);

        // Link created by external Ring now we can drop, because call not missed
        $this->aster_log->delete_data_by_uniqueid($ext_id);

        $this->log("Bridge calls #{$ext_id}({$phone}) and #{$int_id}({$extension})", 'DEBUG');

        $call_log = $this->aster_log->load_data_by_uniqueid($int_id, true);

        if (!empty($call_log)) {
            $this->aster_log->update_data_by_uniqueid($int_id, array(
                    'callstate' => 'Connected',
                    'timestampLink' => $this->timestamp,
                    'remote_channel' => $remote_channel,
                    'asterisk_dest_id' => $ext_id,
                    'extension' => $extension
                ));
        } else {
            $this->aster_log->create_new_record(array(
                    'asterisk_id' => $int_id,
                    'asterisk_dest_id' => $ext_id,
                    'callstate' => 'Connected',
                    'callerID' => $phone,
                    'timestampCall' => $this->timestamp,
                    'timestampLink' => $this->timestamp,
                    'direction' => 'I',
                    'channel' => $local_channel,
                    'remote_channel' => $remote_channel,
                    'extension' => $extension,
                ));
        }
    }

    /**
     * @param $arr
     */
    protected function eventHangup(&$arr) {
        //Event: Hangup
        //Privilege: call,all
        //Channel: SIP/...
        //Uniqueid: xxxxxxxxxx.xxx
        //CallerIDNum: xxx
        //CallerIDName:
        //ConnectedLineNum: xxxxxxxxxx
        //ConnectedLineName: <unknown>
        //Cause: 16
        //Cause-txt: Normal Clearing

        $call = $this->aster_log->load_data_by_uniqueid($arr['Uniqueid'], true);
        if ($call) {

            $this->log("Hangup call #{$call['asterisk_id']} phone:{$call['callerID']}/{$arr['Calleridnum']} cause:{$arr['Cause-txt']}", 'DEBUG');
            $call['Cause'] = $arr['Cause'];
            $call['Cause-txt'] = $arr['Cause-txt'];

            if ($call['callstate'] == 'Ringing') {
                // Call was ringing and not answered. Lest drop it. "Missed" call will be created on other Hangup event at other channel
                // It's save us from create lot of "missed" records if inbound call distributed to many extensions (each calling extension = one log record)
                $this->aster_log->delete_data_by_uniqueid($arr['Uniqueid']);
                return;
            }

            elseif ($call['callstate'] == 'Dial') {
                // It's external channel which not answered
                $this->fix_calls_records($call);
            }

            elseif ($call['callstate'] == 'Connected') {
                // Normal call finished
                $this->fix_calls_records($call);
            }

            else {
                $this->log("Unknown status {$call['callstate']} for call #{$call['asterisk_id']} (ID:{$call['id']})", 'DEBUG');
            }

            $this->aster_log->update_data_by_uniqueid($arr['Uniqueid'], array(
                    'callstate' => 'Hangup',
                    'hangup_cause' => $call['Cause'],
                    'hangup_cause_txt' => $call['Cause-txt'],
                    'timestampHangup' => $this->timestamp,
                    'call_record_id' => empty($call['call_record_id']) ? null : $call['call_record_id'],
                ));
        } else {
            //$this->log("Hangup unknown call #{$arr['Uniqueid']} phone:{$arr['Calleridnum']} cause:{$arr['Cause-txt']}", 'DEBUG');
        }
    }

    /**
     * After end of call need to update log and Calls record
     * If Call record wasn't created, lets find first customer and create this record
     * @param array $call
     */
    protected function fix_calls_records(&$call) {

        if ($call['timestampLink'])
            $duration_sec = strtotime($this->timestamp) - strtotime($call['timestampLink']);
        else
            $duration_sec = 0;

        $hours = floor($duration_sec/3600);
        $minutes = ceil(($duration_sec-$hours*3600)/60);

        $status = (($call['callstate'] == 'Connected') ? 'Held' : (($call['direction'] == 'I') ? 'Missed' : 'Not Held'));
        $direction = ($call['direction'] == 'I') ? 'Inbound' : 'Outbound';

        if (!$call['call_record_id']) {
            // Create Calls record with Answered state
            $relations = $this->sugar_phones->findRelationByPhone($this->cleanIncomingCallerID($call['callerID']));
            $user = $this->sugar_user->findUserByAsteriskExtension($call['extension']);

            $this->log("$status call #{$call['asterisk_id']} phone:{$call['callerID']}, rel:{$relations['relation_type']}/{$relations['relation_id']}, user:{$user}", 'DEBUG');

            if ($relations['relation_id'] || $user) {
                $call_id = $this->sugar_call->create_new_record(array(
                        'name' => str_replace('%phone', $call['callerID'],
                            $this->_lang->t($call['direction'] == 'I' ? 'ASTERISK_IN_CALL' : 'ASTERISK_OUT_CALL')),
                        'assigned_user_id' => $relations['assigned_to'],
                        'date_start' => date('Y-m-d H:i:s', (strtotime($this->timestamp_gmt)-$duration_sec)),
                        'date_end' => $this->timestamp_gmt,
                        'duration_hours' => $hours,
                        'duration_minutes' => $minutes,
                        'parent_type' => $relations['relation_type'],
                        'parent_id' => $relations['relation_id'],
                        'status' => $status,
                        'direction' => $direction,
                        'asterisk_caller_id_c' => $call['callerID']
                    ));
                $call['call_record_id'] = $call_id;

                if ($relations['contact']) {
                    // Now we need to make relation between this Call record and customer Contact
                    $this->sugar_call->related_to($call_id, 'Contacts', $relations['contact']['c_id']);
                }

                if ($user && $user != $relations['assigned_to']) {
                    // If it was outgoing call, also assign this call to user who did call
                    $this->sugar_call->update_data($call_id, array(
                            'assigned_user_id' => $user
                        ));
                }
            }
        } else {
            $this->log("$status call #{$call['asterisk_id']} call_record:{$call['call_record_id']}", 'DEBUG');

            // Update some info in Calls record
            $this->sugar_call->update_data($call['call_record_id'], array(
                    'duration_hours' => $hours,
                    'duration_minutes' => $minutes,
                    'status' => $status,
                    'date_end' => $this->timestamp_gmt,
                ));
        }
    }

    protected function cleanIncomingCallerID($phone) {
        return $this->patterns->cleanIncomingPhone($phone);
    }

    protected function cleanDialstring($phone) {
        return $this->patterns->cleanOutgoingPhone($phone);
    }

    protected function isInternalChannel($channel) {
        return $this->patterns->isInternalChannel($channel);
    }

    protected function isExternalChannel($channel) {
        return $this->patterns->isExternalChannel($channel);
    }

    protected function isExternalNumber($number) {
        return $this->patterns->isExternalNumber($number);
    }

    protected function isInternalNumber($number) {
        return $this->patterns->isExtensionNumber($number);
    }

    protected function log($message, $level='INFO') {
        $this->_log->log(get_class().': '.$message, $level);
    }

}
