<?php

class SugarCall {
    protected $_soap;
    protected $_log;

    public function __construct(SugarSoapAdapter $soap, LoggerInterface $log) {
        $this->_soap = $soap;
        $this->_log = $log;
    }

    /**
     * @param int $id
     * @return array|bool
     */
    protected function load_data($id) {
        $arr = array(
            'module_name' => 'Calls',
            'id' => $id
        );

        $result = $this->_soap->soapCall('get_entry', $arr);
        if ($result === FALSE) {
            return false;
        }

        if (empty($result->entry_list)) {
            return false;
        }

        $fields = array();
        foreach($result->entry_list[0]->name_value_list as $val) {
            $fields[$val->name] = $val->value;
        }

        return $fields;
    }

    /**
     * @param array $attributes
     * @return bool|string ID of new record or FALSE
     */
    public function create_new_record($attributes) {
        return $this->update_data(null, $attributes);
    }

    /**
     * @param int|null $id - if NULL, new record will be created
     * @param array $attributes
     * @return bool
     */
    public function update_data($id, $attributes) {
        $result = $this->_set_call($id, $attributes);
        if ($result === FALSE || !$result->id) {
            if ($id)
                $this->log("Fail to update record {$id}", 'ERROR');
            else
                $this->log("Fail to create new record", 'ERROR');
            return false;
        }

        if ($id)
            $this->log("Update record {$id}/{$result->id}", 'DEBUG');
        else
            $this->log("Create new record {$result->id}", 'DEBUG');

        return $result->id;
    }

    protected function _set_call($id, $attributes) {
        $arr = array(
            'module_name' => 'Calls',
            'name_value_list' => array(),
        );

        if ($id) {
            $arr['name_value_list'][] = array(
                'name' => 'id',
                'value' => $id
            );
        }

        foreach($attributes as $key=>$value) {
            $arr['name_value_list'][] = array(
                'name' => $key,
                'value' => $value
            );
        }

        $result = $this->_soap->soapCall('set_entry', $arr);
        return $result;
    }

    public function related_to($from_id, $to_module, $to_id) {
        $arr = array(
            'set_relationship_value' => array(
                'module1' => 'Calls',
                'module1_id' => $from_id,
                'module2' => $to_module,
                'module2_id' => $to_id
            )
        );
        $result = $this->_soap->soapCall('set_relationship', $arr);
    }

    protected function log($message, $level='INFO') {
        $this->_log->log(get_class().': '.$message, $level);
    }
}