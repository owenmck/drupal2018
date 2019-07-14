<?php

namespace Drupal\main\Model\suitecrm;

use Drupal\main\Model\suitecrm\PortalConfigSettings;
use Drupal\main\Model\Utilities;

class sugarRestClient {

    /**
     * Rest object
     *
     * @var string
     */
    private $rest_url = '';

    /**
     * SugarCRM User
     *
     * @var string
     */
    private $rest_user = '';

    /**
     * SugarCRM Pass
     *
     * @var string
     */
    private $rest_pass = '';

    /**
     * SugarCRM Session ID
     *
     * @var string
     */
    protected $sid = NULL;

    /*
     * VTIS Web Services request end point
     */
    private $vtis_request_url = '';

    public function __construct() {
//        \Drupal::logger('main')->debug(__METHOD__ );
        $key = 'main_settings';

        $settings = [];

        if ($cache = \Drupal::cache()->get($key)) {
            $settings = $cache->data;
        } else {
            $settings = PortalConfigSettings::getSettings();
            \Drupal::cache()->set($key, $settings);
        }

        $this->base_url = $settings->crm_url;
        $this->rest_url = $this->base_url . '/service/v4_1/rest.php';
        $this->vtis_request_url = $settings->vtis_url;

        $this->rest_user = $settings->crm_user;
        $this->rest_pass = $settings->crm_pass;
//        \Drupal::logger('main')->debug(__METHOD__ . ' done.');
    }

    /**
     * convert to rest request and return decoded array
     *
     * @return array
     */
    private function rest_request($call_name, $call_arguments) {
        ob_start();
        $ch = curl_init();
        $post_data = 'method=' . $call_name . '&input_type=JSON&response_type=JSON';
        $jsonEncodedData = json_encode($call_arguments);
        $post_data = $post_data . '&rest_data=' . $jsonEncodedData;

        curl_setopt($ch, CURLOPT_URL, $this->rest_url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_PROXY, '');

        $output = curl_exec($ch);
        \Drupal::logger('main')->debug(__METHOD__ . '(' . $call_name . ', args), posting to CRM.');

        $logMessage = __METHOD__ . ' REST server result ';

        if ($output === FALSE) {
            $response_data = NULL;
            $curl_error = curl_error($ch);
            $curl_error_no = curl_errno($ch);
            $logMessage .= 'Error No == ' . $curl_error_no . ',  error message: ' . $curl_error;
            \Drupal::logger('main')->error($logMessage);

        } else {
            $response_data = json_decode($output, true);

            if (empty($response_data)) {
                $curl_error = curl_error($ch);
                $curl_error_no = curl_errno($ch);
                $logMessage .= ' → empty string. Error No == ' . $curl_error_no . ',  error message: ' . $curl_error . '. Check for spurious double-quotes in query (CRM logs) also check CRM values in Portal Custom Settings.';
                \Drupal::logger('main')->error($logMessage);

            } elseif (
                is_array($response_data) &&
                array_key_exists('number', $response_data) &&
                $response_data['number'] != 400
            ) {
                $logMessage .= ' → curl  error state: ' . print_r($response_data, true);
                \Drupal::logger('main')->error($logMessage);

            } else {
                $logMessage .= ' → seems OK.';
                \Drupal::logger('main')->debug($logMessage);
            }
        }

        curl_close($ch);
        ob_end_flush();
        return $response_data;
    }

    public function login() {
        $result = false;
        $call_arguments = [
            'user_auth' => ['user_name' => $this->rest_user, 'password' => $this->rest_pass,],
            'application_name' => '',
            'name_value_list' => [['name' => 'notifyonsave', 'value' => 'false']]
        ];

        $reply = $this->rest_request('login', $call_arguments);

        if (isset($reply['id'])) {
            $this->sid = $reply['id'];
            $result = $reply['id'];
        }

        \Drupal::logger('main')->debug(__METHOD__ . '() → ' . ($result ? $result : 'false'));
        return $result;
    }

    public function logout() {
        $this->rest_request('logout', ['session' => $this->sid,]);
        $this->sid = null;
        \Drupal::logger('main')->debug(__METHOD__ . '() done.');
    }

    /**
     * Retrieves a list of entries
     *
     * @param string $module
     * @param query $query
     * @param string $order_by
     * @param integer $offset
     * @param array $select_fields
     * @param integer $max_results
     * @param boolean $deleted
     * @return array
     */
    public function getEntryList($module, $query = '', $order_by = '', $offset = 0, $select_fields = [], $related_fields = [], $max_results = '0', $deleted = false) {
        $result = false;

        if ($this->sid) {

            $call_arguments = [
                'session' => $this->sid,
                'module_name' => $module,
                'query' => $query,
                'order_by' => $order_by,
                'offset' => $offset,
                'select_fields' => $select_fields,
                'link_name_to_fields_array' => $related_fields,
                'max_results' => 10000,
                'deleted' => $deleted,
            ];

            $reply = $this->rest_request('get_entry_list', $call_arguments);

            if ($reply['result_count'] > 0) {
                $result = $reply;
            }
        }

        \Drupal::logger('main')->debug(__METHOD__ . '(' . $module . ', query= ' . (strlen($query) ? $query : 'empty') . ', etc ) in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    public function get_include_contents($filename) {
        $result = false;

        if (is_file($filename)) {
            ob_start();
            include $filename;
            $result = ob_get_contents();
            ob_end_clean();
        }

        \Drupal::logger('main')->debug(__METHOD__ . '(' . $filename . ') → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    public function getEntry($module, $id, $select_fields = [], $related_fields = []) {
        $result = false;

        if ($this->sid) {
            $call_arguments = [
                'session' => $this->sid,
                'module_name' => $module,
                'id' => $id,
                'select_fields' => $select_fields,
                'link_name_to_fields_array' => $related_fields,
            ];

            $reply = $this->rest_request('get_entry', $call_arguments);

            if (!isset($reply['result_count']) || $reply['result_count'] > 0) {
                $result = $reply;
            }
        }

        \Drupal::logger('main')->debug(__METHOD__ . '(' . $module . ', select_fields, related_fields ) in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    /**
     * Add or change an entry in the given module using the given data, via a REST request.
     *
     * @param string $module
     * @param array $data  a name value list.
     *
     * @return array or false if there is no session id or false if the REST request failed.
     */
    public function setEntry($module, $data) {
        $result = false;

        if ($this->sid) {
            $call_arguments = [
                'session' => $this->sid,
                'module_name' => $module,
                'name_value_list' => $data,
            ];

            $reply = $this->rest_request('set_entry', $call_arguments);

            if (isset($reply['id'])) {
                $result = $reply;
            }
        }

        \Drupal::logger('main')->debug(__METHOD__ . '(' . $module . ', data), in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    /**
     * Add a new entry to the stated CRM relationship table, linking the given item Id from the primary Module, with an item of the named secondary module, whose id is also given.
     *
     * This is the same as setRelationship but it makes sense to me.
     *
     * @param $relationship_name
     * @param $primary_module_item_id
     * @param $related_module_name
     * @param $related_module_item_id
     * @return array|bool
     */
    public function insertRelationship($relationship_name, $primary_module_item_id, $related_module_name, $related_module_item_id) {
        $call_arguments = [
            'session' => $this->sid,
            'link_field_name' => $relationship_name,
            'related_ids' => [$primary_module_item_id],
            'module_name' => $related_module_name,
            'module_id' => $related_module_item_id,
        ];

        $result = ( $this->sid ? $this->rest_request('set_relationship', $call_arguments) : false);
        \Drupal::logger('main')->debug(__METHOD__ . '(' . $relationship_name . ', etc), in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    /**
     * Creates a new relationship-entry
     *
     * @param string $module1
     * @param string $module1_id
     * @param string $module2
     * @param string $module2_id
     * @return array
     */
    public function setRelationship($module1, $module1_id, $module2, $module2_id) {
        $result = false;

        if ($this->sid) {
            $call_arguments = [
                'session' => $this->sid,
                'module_name' => $module1,
                'module_id' => $module1_id,
                'link_field_name' => $module2,
                'related_ids' => [$module2_id],
            ];

            $result = $this->rest_request('set_relationship', $call_arguments);
        }

        \Drupal::logger('main')->debug(__METHOD__ . '(' . $module1 . ', id1, ' . $module2 . ', id2) in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    /**
     * Deletes a relationship-entry
     *
     * @param string $module1
     * @param string $module1_id
     * @param string $module2
     * @param string $module2_id
     * @return array
     */
    public function deleteRelationship($module1, $module1_id, $module2, $module2_id) {
        $result = false;

        if ($this->sid) {
            $call_arguments = [
                'session' => $this->sid,
                'module_name' => $module1,
                'module_id' => $module1_id,
                'link_field_name' => $module2,
                'related_ids' => [$module2_id],
                'name_value_list' => [],
                'delete' => 1,
            ];

            $result = $this->rest_request('set_relationship', $call_arguments);
        }

        \Drupal::logger('main')->debug(__METHOD__ . '(' . $module1 . ', id1, ' . $module2 . ', id2) in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    /**
     * Retrieves relationship data
     *
     * @param string $module_name
     * @param string $module_id
     * @param string $related_module
     * @return array
     */
    public function getRelationships($module_name, $module_id, $related_module, $related_module_query = '', $related_fields = [], $related_module_link_name_to_fields_array = [], $deleted = false, $order_by = '', $offset = 0, $limit = false) {
        $result = false;
        $call_arguments = [
            'session' => $this->sid,
            'module_name' => $module_name,
            'module_id' => $module_id,
            'link_field_name' => $related_module,
            'related_module_query' => $related_module_query,
            'related_fields' => $related_fields,
            'related_module_link_name_to_fields_array' => $related_module_link_name_to_fields_array,
            'deleted' => $deleted,
            'order_by' => $order_by,
            'offset' => $offset,
            'limit' => $limit,
        ];
        $reply = $this->rest_request('get_relationships', $call_arguments);

        if (!isset($reply['error']['number']) || $reply['error']['number'] == 0) {
            $result = $reply;
        }

        \Drupal::logger('main')->debug(__METHOD__ . '(' . $module_name . ', id1, ' . $related_module . ', etc) in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    /**
     * Retrieves a module field, via REST request.
     *
     * @param $module
     * @param $field
     *
     * @return mixed the field or false if there is no session id or false if the REST request fails.
     */
    public function getModuleFields($module, $field) {
        $result = false;

        if ($this->sid) {
            $call_arguments = [
                'session' => $this->sid,
                'module_name' => $module,
            ];

            $reply = $this->rest_request('get_module_fields', $call_arguments);

            if ($reply > 0) {
                $result = $reply['module_fields'][$field];
            }
        }

        \Drupal::logger('main')->debug(__METHOD__ . '(' . $module . ', ' . $field . ') in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    public function getAllModuleFields($module) {
        $result = false;
        \Drupal::logger('main')->debug(__METHOD__ . '(' . $module . ')');

        if ($this->sid) {
            $call_arguments = [
                'session' => $this->sid,
                'module_name' => $module,
            ];

            $reply = $this->rest_request('get_module_fields', $call_arguments);

            if ($reply > 0) {
                $result = $reply['module_fields'];
            }
        }

        \Drupal::logger('main')->debug(__METHOD__ . '(' . $module . ') → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    public function get_note_attachment($note_id) {
        $result = false;

        if ($this->sid) {
            $call_arguments = [
                'session' => $this->sid,
                'id' => $note_id
            ];

            $result = $this->rest_request('get_note_attachment', $call_arguments);
        }

        \Drupal::logger('main')->debug(__METHOD__ . '( note_id = ' . $note_id . ') in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    public function set_note_attachment($note_id, $file_name, $file_location) {
        include_once drupal_get_path('module', 'main') . '/nusoap/lib/nusoap.php';
        $server = $this->base_url . '/soap.php?wsdl';
        $soapclient = new \nusoap_client($server, true);
        $result_array = @$soapclient->call('login', ['user_auth' => ['user_name' => $this->rest_user, 'password' => $this->rest_pass,]]);
        $attachment = ['id' => $note_id, 'filename' => $file_name, 'file' => base64_encode(file_get_contents($file_location)),];
        $result = @$soapclient->call('set_note_attachment', ['session' => $result_array['id'], 'note' => $attachment]);
        \Drupal::logger('main')->debug(__METHOD__ . '( note_id=' . $note_id . ', file_name=' . $file_name . ', location=' . $file_location . ') → result ' . ($result ? Utilities::getObjectContentsAsString($result) : 'false'));
        return $result;
    }

    public function set_note_attachment_with_type($note_id, $file_name, $file_location, $template_type) {
        include_once drupal_get_path('module', 'main') . '/nusoap/lib/nusoap.php';
        $server = $this->base_url . '/soap.php?wsdl';
        $soapclient = new \nusoap_client($server, true);
        $result_array = @$soapclient->call('login', ['user_auth' => ['user_name' => $this->rest_user, 'password' => $this->rest_pass,]]);
        $attachment = ['id' => $note_id, 'filename' => $file_name, 'template_type' => $template_type, 'file' => base64_encode(file_get_contents($file_location)),];
        $result = @$soapclient->call('set_note_attachment', ['session' => $result_array['id'], 'note' => $attachment]);
        \Drupal::logger('main')->debug(__METHOD__ . '( note_id=' . $note_id . ', file_name=' . $file_name . ', location=' . $file_location . ') → result ' . ($result ? Utilities::getObjectContentsAsString($result) : 'false'));
        return $result;
    }

    public function set_entry_soap($module, $data) {
        include_once drupal_get_path('module', 'main') . '/nusoap/lib/nusoap.php';
        $server = $this->base_url . '/soap.php?wsdl';
        $soapclient = new \nusoap_client($server, true);
        $result_array = @$soapclient->call('login', ['user_auth' => ['user_name' => $this->rest_user, 'password' => $this->rest_pass,]]);
        $result = @$soapclient->call('set_entry', ['session' => $result_array['id'], 'module_name' => $module, 'name_value_list' => $data]);
        \Drupal::logger('main')->debug(__METHOD__ . '( ' . $module . ', data ) → result ' . ($result ? Utilities::getObjectContentsAsString($result) : 'false'));
        return $result;
    }

    public function new_case_soap($contact_id, $subject, $description, $type, $priority, $account_name) {
        include_once drupal_get_path('module', 'main') . '/nusoap/lib/nusoap.php';
        $server = $this->base_url . '/soap.php?wsdl';
        $soapclient = new \nusoap_client($server, true);
        $result_array = @$soapclient->call('login', ['user_auth' => ['user_name' => $this->rest_user, 'password' => $this->rest_pass,]]);

        $data = [
            'name' => $subject,
            'status' => 'New',
            'description' => $description,
            'type' => $type,
            'priority' => $priority,
            'account_name' => $account_name,
            'update_date_entered' => true
        ];

        $res = @$soapclient->call('set_entry', ['session' => $result_array['id'], 'module_name' => 'Cases', 'name_value_list' => $this->convertArrayToNVL(str_replace('&', '%26', $data))]);
        \Drupal::logger('main')->debug(__METHOD__ . '() on server ' . $server);
        \Drupal::logger('main')->debug('Case Data ' . Utilities::getObjectContentsAsString($data));
        \Drupal::logger('main')->debug('Case Web Service Result ' . Utilities::getObjectContentsAsString($res));
        return $res;
    }

    public function get_document_revision_soap($id) {
        include_once drupal_get_path('module', 'main') . '/nusoap/lib/nusoap.php';
        $server = $this->base_url . '/soap.php?wsdl';
        $soapclient = new \nusoap_client($server, true);
        $result_array = @$soapclient->call('login', ['user_auth' => ['user_name' => $this->rest_user, 'password' => $this->rest_pass,]]);
        $result = @$soapclient->call('get_document_revision', ['session' => $result_array['id'], 'i' => $id,]);
        \Drupal::logger('main')->debug(__METHOD__ . '( id=' . $id . ') → result ' . ($result ? 'OK' : 'false'));
        return $result['document_revision'];
    }

    public function get_document_revision($id) {
        $result = false;

        if ($this->sid) {
            $reply = $this->rest_request('get_document_revision', ['session' => $this->sid, 'id' => $id,]);
            $result = $reply['document_revision'];
        }

        \Drupal::logger('main')->debug(__METHOD__ . '( id = ' . $id . ') in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    public function set_document_revision($document_id, $file_name, $file_location, $revision_number = 1) {
        $result = false;

        if ($this->sid) {
            $content = file_get_contents($file_location);

            $call_arguments = [
                'session' => $this->sid,
                'document_revision' => [
                    'id' => $document_id,
                    'revision' => $revision_number,
                    'filename' => $file_name,
                    'file' => urlencode(base64_encode($content))
                ]
            ];

            $result = $this->rest_request('set_document_revision', $call_arguments);
        }

        \Drupal::logger('main')->debug(__METHOD__ . '( id = ' . $document_id . ', file_name=' . $file_name . ', location=' . $file_location . ', ' . $revision_number . ') in session ' . ( $this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    public function upload_document_given_content($document_id, $file_name, $content, $revision_number = 1) {
        $result = false;

        if ($this->sid) {
            $call_arguments = [
                'session' => $this->sid,
                'document_revision' => [
                    'id' => $document_id,
                    'revision' => $revision_number,
                    'filename' => $file_name,
                    'file' => urlencode($content)
                ],
            ];

            $result = $this->rest_request('set_document_revision', $call_arguments);
        }

        \Drupal::logger('main')->debug(__METHOD__ . '( id = ' . $document_id . ', file_name=' . $file_name . ', content , ' . $revision_number . ') in session ' . ($this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    public function upload_document_with_type($document_id, $file_name, $file_location, $template_type, $revision_number = 1) {
        $result = false;

        if ($this->sid) {
            $data = [
                'session' => $this->sid,
                'document_revision' => [
                    'id' => $document_id,
                    'revision' => $revision_number,
                    'filename' => $file_name,
                    'file' => base64_encode(file_get_contents($file_location))
                ]
            ];

            $result = $this->rest_request('set_document_revision', $data);
        }

        \Drupal::logger('main')->debug(__METHOD__ . '( id = ' . $document_id . ', file_name=' . $file_name . ', location=' . $file_location . ', ' . $revision_number . ') in session ' . ($this->sid ? $this->sid : 'null') . ' → result ' . ($result ? 'OK' : 'false'));
        return $result;
    }

    public function get_entry_list_soap($module, $query, $order_by, $select_fields) {
        include_once drupal_get_path('module', 'main') . '/nusoap/lib/nusoap.php';
        $server = $this->base_url . '/soap.php?wsdl';
        $soapclient = new \nusoap_client($server, true);
        $login_array = @$soapclient->call('login', ['user_auth' => ['user_name' => $this->rest_user, 'password' => $this->rest_pass,]]);
        $session = $login_array['id'];

        $data = [
            'session' => $session,
            'module_name' => $module,
            'query' => $query,
            'order_by' => $order_by,
            'select_fields' => $select_fields
        ];

        $result = @$soapclient->call('get_entry_list', $data);
        \Drupal::logger('main')->debug(__METHOD__ . '( ' . $module . ', query=' . $query . ', etc) → result ' . ($result ? Utilities::getObjectContentsAsString($result) : 'false'));
        return $result;
    }

    public function getNotificationsListSoap($query, $order_by) {
        $result = [];
        $server = $this->base_url . '/service/v4_1/soap.php?wsdl';

        $xml_post_string = '<soapenv:Envelope xmlns:sug="http://www.sugarcrm.com/sugarcrm"
                                    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                                    xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" soapenv:encodingstyle="http://schemas.xmlsoap.org/soap/encoding/">
                                    <soapenv:Body>
                                      <sug:get_entry_list soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                                         <session xsi:type="xsd:string">' . $this->getSessionToken() . '</session>
                                         <module_name xsi:type="xsd:string">Emails</module_name>
                                         <query xsi:type="xsd:string">' . $query . '</query>
                                         <order_by xsi:type="xsd:string">' . $order_by . '</order_by>
                                         <offset xsi:type="xsd:int">0</offset>
                                         <select_fields xsi:type="sug:select_fields" soapenc:arrayType="xsd:string[]">
                                            <Item xsi:type="xsd:string">id</Item>
                                            <Item xsi:type="xsd:string">name</Item>
                                            <Item xsi:type="xsd:string">description_html</Item>
                                            <Item xsi:type="xsd:string">date_entered</Item>
                                            <Item xsi:type="xsd:string">status</Item>
                                            <Item xsi:type="xsd:string">date_first_viewed_c</Item>
                                            <Item xsi:type="xsd:string">date_last_viewed_c</Item>
                                            <Item xsi:type="xsd:int">view_count_c</Item>
                                         </select_fields>
                                      </sug:get_entry_list>
                                    </soapenv:Body>
                                </soapenv:Envelope>';

        $headers = [
            'Content-type: text/xml;charset="utf-8"',
            'Accept: text/xml',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'SOAPAction: ' . $this->base_url . '/soap.php/get_entry_list',
            'Content-length: ' . strlen($xml_post_string),
        ];

        // PHP cURL  for https connection with auth
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_URL, $server);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string); // the SOAP request
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_PROXY, '');

        // converting
        $response = curl_exec($ch);
        curl_close($ch);
      
        if ($response) {
            // converting to XML
            $xml = simplexml_load_string($response, NULL, NULL, 'http://schemas.xmlsoap.org/soap/envelope/');   
            if ($xml) {
                $xml->registerXPathNamespace('SOAP-ENV', 'http://schemas.xmlsoap.org/soap/envelope/');

                foreach ($xml->xpath('//entry_list/item/name_value_list') as $name_value_list) {
                    $items = [];

                    foreach ($name_value_list->children() as $item) {
                        $items[(string) $item->name] = (string) $item->value;
                    }

                    $result[] = $items;
                }
            } else {
                \Drupal::logger('main')->error(__METHOD__ . '( call to simplexml_load_string using response=' . $response . ', etc → FALSE instead of a SimpleXMLElement');
            }
        } else {
            \Drupal::logger('main')->error(__METHOD__ . '( query=' . $query . ', etc) → FALSE response');
        }

        \Drupal::logger('main')->debug(__METHOD__ . '( query=' . $query . ', etc) → result ' . (count($result) ? 'OK' : 'empty or false'));
        return $result;
    }

    public function upload_document_with_type_soap($document_id, $file_name, $file_location, $revision_number = 1) {
        $server = $this->base_url . '/soap.php?wsdl';

        $xml_post_string = '<soapenv:Envelope xmlns:sug="http://www.sugarcrm.com/sugarcrm"
                                    xmlns:soap-enc="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                                    xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" soapenv:encodingstyle="http://schemas.xmlsoap.org/soap/encoding/">
                                    <soapenv:Body>
                                        <sug:set_document_revision xmlns:tns="http://www.sugarcrm.com/sugarcrm">
                                            <session xsi:type="xsd:string">' . $this->getSessionToken() . '</session>
                                            <note xsi:type="sug">
                                                <id xsi:type="xsd:string">' . $document_id . '</id>
                                                <revision xsi:type="xsd:string">' . $revision_number . '</revision>
                                                <filename xsi:type="xsd:string">' . $file_name . '</filename>
                                                <file xsi:type="xsd:string">' . base64_encode(file_get_contents($file_location)) . '</file>
                                            </note>
                                        </sug:set_document_revision>
                                    </soapenv:Body>
                                </soapenv:Envelope>';

        $headers = [
            'Content-type: text/xml;charset="utf-8"',
            'Accept: text/xml',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'SOAPAction: ' . $this->base_url . '/soap.php/set_document_revision',
            'Content-length: ' . strlen($xml_post_string),
        ];

        // PHP cURL  for https connection with auth
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_URL, $server);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string); // the SOAP request
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_PROXY, '');

        // converting
        $response = curl_exec($ch);
        curl_close($ch);
        $result = simplexml_load_string($response);
        \Drupal::logger('main')->debug(__METHOD__ . '( id = ' . $document_id . ', file_name=' . $file_name . ', location=' . $file_location . ', ' . $revision_number . ') → result ' . ($result ? 'OK' : 'empty or false'));
        return $result;
    }

    public function getSessionToken() {
        $result = '';
        $server = $this->base_url . '/service/v4_1/soap.php?wsdl';

        $xml_post_string = '<soapenv:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '.
                                   'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '.
                                    'xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '.
                                    'xmlns:sug="http://www.sugarcrm.com/sugarcrm">'.
                               '<soapenv:Header/>'.
                               '<soapenv:Body>'.
                                  '<sug:login soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'.
                                     '<user_auth xsi:type="sug:user_auth">'.
                                       ' <user_name xsi:type="xsd:string">' . $this->rest_user . '</user_name>'.
                                        '<password xsi:type="xsd:string">' . $this->rest_pass . '</password>'.
                                     '</user_auth>'.
                                  '</sug:login>'.
                               '</soapenv:Body>'
                            .'</soapenv:Envelope>';

        $headers = array(
            'Content-type: text/xml;charset=\"utf-8\"',
            'Accept: text/xml',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'SOAPAction: ' . $this->base_url . '/soap.php/login',
            'Content-length: ' . strlen($xml_post_string),
        );

        // PHP cURL  for https connection with auth
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_URL, $server);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string); // the SOAP request
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_PROXY, '');

        // converting
        $response = curl_exec($ch);
        $erros = curl_error($ch);
        curl_close($ch);

        // converting to XML
        $xml = simplexml_load_string($response, NULL, NULL, 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('SOAP-ENV', 'http://schemas.xmlsoap.org/soap/envelope/');

        foreach ($xml->xpath('//SOAP-ENV:Body') as $header) {
            $arr = $header->xpath('//id'); // Should output 'something'.
            $result = $arr[0][0];
            break;
        }

        \Drupal::logger('main')->debug(__METHOD__ . '() → result ' . ($result ? $result : 'empty or false'));
        return $result;
    }

    /**
     * Converts an Array to a SugarCRM-REST compatible name_value_list
     *
     * @param Array $data
     * @return Array
     */
    public function convertArrayToNVL($data) {
        $NVL = [];
        foreach ($data as $key => $value) {
            $NVL[] = ['name' => $key, 'value' => $value];
        }

        \Drupal::logger('main')->debug(__METHOD__ . '() → result ' . (count($NVL) ? 'OK' : 'empty or false'));
        return $NVL;
    }

    /**
     * Converts a SugarCRM-REST compatible name_value_list to an Array
     *
     * @param Array $data
     * @return Array
     */
    public function convertNVLToArray($data) {
        $array = [];
        foreach ($data as $row) {
            $array[$row['name']] = $row['value'];
        }

        \Drupal::logger('main')->debug(__METHOD__ . '() → result ' . (count($array) ? 'OK' : 'empty or false'));
        return $array;
    }

    public function getNamesAndValues($entry) {
        $result = [];
        $entryHasEntryList = ( is_array($entry) && array_key_exists('entry_list', $entry) && count($entry['entry_list']));
        $entryListHasNVL = ( is_array($entry['entry_list'][0]) && array_key_exists('name_value_list', $entry['entry_list'][0]) && count($entry['entry_list'][0]['name_value_list']));

        if ($entryHasEntryList && $entryListHasNVL) {
            $result = $this->convertNVLToArray($entry['entry_list'][0]['name_value_list']);
        }

        \Drupal::logger('main')->debug(__METHOD__ . '() → result ' . (count($result) ? 'OK' : 'empty or false'));
        return $result;
    }

    /*
     * Connect to VTIS Services using passed parameters
     *
     */

    public function vtisService_request($call_arguments) {


        $vtis_call_url = $this->vtis_request_url . $call_arguments;

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $vtis_call_url,
        ));

        $output = curl_exec($curl);
        $logMessage = __METHOD__ . '(call_args) ';

        if ($output === FALSE) {
            $response_data = NULL;
            $curl_error = curl_error($curl);
            $curl_error_no = curl_errno($curl);
            $logMessage .= 'Error No == ' . $curl_error_no . ',  error message: ' . $curl_error;
        } else {
            $response_data = json_decode($output, true);

            if (empty($response_data)) {
                $curl_error = curl_error($curl);
                $curl_error_no = curl_errno($curl);
                $logMessage .= ' → empty string. Error No == ' . $curl_error_no . ',  error message: ' . $curl_error . '. Check for spurious double-quotes in query (CRM logs) also check CRM values in Portal Custom Settings.';
            } else {
                $logMessage .= ' → seems OK.';
            }
        }

        \Drupal::logger('det')->debug($logMessage);
        curl_close($curl);

        return $response_data;
    }
    
    
    /*
     * Post to VTIS web Service 
     * 
     * @param $call_arguments - Runtime call argument list
     * @param $call_post_fields - JSON encoded post string 
     * @param $content_length - String size of $call_post_fields
     * 
     */
    
    public function vtisService_post($call_arguments, $call_post_fields ,$content_length) {


        $vtis_call_url = $this->vtis_request_url . $call_arguments;
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $vtis_call_url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $call_post_fields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-length:' . $content_length)); 
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $curl_error = curl_error($ch);
        $curl_error_no = curl_errno($ch);

        $output = json_decode($result, true);

        $logMessage = __METHOD__ . '(call_args, call_post_fields, content_length) ';

        if (strpos($output['Message'], 'error') !== false) {
            $response_data = $output['Message'].' '.$output['ExceptionMessage'].' '.$output['ExceptionType'];
            $curl_error = curl_error($ch);
            $curl_error_no = curl_errno($ch);
            $logMessage .= 'Error No == ' . $curl_error_no . ',  error message: ' . $curl_error;
        } else {
            $response_data = json_decode($output, true);

            if (empty($response_data)) {
                $curl_error = curl_error($ch);
                $curl_error_no = curl_errno($ch);
                $logMessage .= ' → empty string. Error No == ' . $curl_error_no . ',  error message: ' . $curl_error . '. Check for spurious double-quotes in query (CRM logs) also check CRM values in Portal Custom Settings.';
            } else {
                $logMessage .= ' → seems OK.';
            }
        }

        \Drupal::logger('det')->debug($logMessage);
        curl_close($ch);

        return $response_data.'  '.$content_length.'  '.$vtis_call_url."  ".$call_post_fields;
    }

}
