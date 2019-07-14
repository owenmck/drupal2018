<?php

namespace Drupal\main\Model\suitecrm;

use Drupal\main\Model\suitecrm\SuiteCRMRestClientInit;
use Drupal\main\Model\suitecrm\SugarCase;
use Drupal\main\Model\suitecrm\SugarUpdate;
use Drupal\main\Model\suitecrm\Document;
use Drupal\main\Model\CaseDTO;
use Drupal\main\Model\CaseRelatedDTO;
use Drupal\main\Model\Result;
use Drupal\main\Model\Utilities;

class CasesConnection extends SuiteCRMRestClientInit
{
    private $case_fields = [
        'id',
        self::NAME,
        self::DATE_ENTERED,
        self::DATE_MODIFIED,
        'category_c',
        'subcategory_c',
        self::DESCRIPTION,
        'case_number',
        'type',
        self::PRIORITY,
        'account_id',
        self::STATE,
        'parent_type',
        'parent_id',
        self::DATE_CLOSED,
        'update_text',
        'mt_case_status_id_c',
    ];
    private $case_status_fields = [
        'id',
        'name',
        'is_visible_on_portal',
        'case_state',
        'placeholder_for_portal_status',
    ];
    private $case_update_fields = [
        'id',
        self::NAME,
        self::DATE_ENTERED,
        self::DATE_MODIFIED,
        'category_c',
        'subcategory_c',
        self::DESCRIPTION,
        self::CONTACT,
        'contact_id',
        'internal',
        'assigned_user_id',
    ];
    private $contact_fields = [
        'id',
        'first_name',
        'last_name',
        self::DATE_ENTERED,
        self::DATE_MODIFIED,
        self::DESCRIPTION,
        'portal_user_type',
        'account_id',
        'joomla_account_id',
    ];
    private $user_fields = [
        'id',
        'first_name',
        'last_name',
        self::DATE_ENTERED,
        self::DATE_MODIFIED,
        self::DESCRIPTION,
    ];
    private $note_fields = [
        'id',
        self::NAME,
        self::DATE_ENTERED,
        self::DATE_MODIFIED,
        self::DESCRIPTION,
        'filename',
        'file_url'
    ];
    private $document_fields = [
        'id',
        'document_revision_id',
        self::NAME,
        'template_type',
        self::DOCUMENT_NAME,
        'active_date'
    ];
    private static $singleton;

    public static function getInstance()
    {
        if (!self::$singleton) {
            self::$singleton = new CasesConnection();
        }

        return self::$singleton;
    }

    private function getCaseFields()
    {
        $cid = 'case_fields_' . \Drupal::languageManager()->getCurrentLanguage()->getId();

        $data = NULL;
        if ($cache = \Drupal::cache()->get($cid)) {
            $data = $cache->data;
        } else {
            $data = $this->restClient->getAllModuleFields(self::CASES_MODULE_PRIMARY);
            \Drupal::cache()->set($cid, $data);
        }

        return $data;
    }

    public function getTypes()
    {
        $fields = $this->getCaseFields();
        return $fields['type'];
    }

    public function getPriorities()
    {
        $fields = $this->getCaseFields();
        return $fields[self::PRIORITY];
    }

    public function getStates()
    {
        $fields = $this->getCaseFields();
        return $fields[self::STATE];
    }

    /**
     * For every file, add an attachment and link the case.
     * Note that files attached via portal (having come fom an RTO user) are public: i.e. visible in original context.
     * That is, other RTO users from the same RTO can see data pertaining to that context, via the portal.
     *
     * @param $case_id
     * @param $files
     */
    public function addCaseFiles($case_id, $files)
    {
        if (is_array( $files) && count( $files)) {
            foreach ($files as $file_name => $file_location) {
                $document_data = [ self::DOCUMENT_NAME => $file_name, 'visibility_c' => 'public'];
                $new_document  = $this->restClient->setEntry(self::DOCUMENTS_MODULE, $document_data);
                $document_id   = $new_document['id'];

                $this->restClient->upload_document_with_type_soap($document_id, $file_name, $file_location);
                $this->restClient->setRelationship(self::DOCUMENTS_MODULE, $document_id, self::CASES_MODULE_RELATED, $case_id);
            }
        }
    }

    /**
     * Model a new Enquiry.
     * Any attached documents are given "public" visibility.
     *
     * @param string $account_id  Identifies the RTO.
     * @param string $subject As input by the logged-in user.
     * @param string $category As selected by the logged-in user.
     * @param string $subcategory As selected by the logged-in user.
     * @param string $description As input by the logged-in user.
     * @param string $type Defaults to 'Enquiry'
     * @param $priority
     * @param $files
     *
     * @return mixed
     */
    public function newCase($account_id, $subject, $category, $subcategory, $description, $caseType = self::CASE_TYPE_ENQUIRY, $priority = 'p1', $files = null)
    {
        $result = false;
        $response = $this->_addNewCase($account_id, $subject, $category, $subcategory, $description, $caseType, $priority);

        if ( is_array($response) && array_key_exists('id', $response) && $response['id']) {
            /* Use the uploaded files to create a new Document records in the CRM, each linked to this Case. */
            if (is_array($files) && count($files)) {
                foreach ($files as $file_name => $file_location) {
                    $document_data = [self::DOCUMENT_NAME => $file_name, 'visibility_c' => 'public'];
                    $new_document = $this->restClient->setEntry(self::DOCUMENTS_MODULE, $document_data);
                    $document_id = $new_document['id'];
                    $this->restClient->upload_document_with_type_soap($document_id, $file_name, $file_location);
                    $this->restClient->setRelationship(self::DOCUMENTS_MODULE, $document_id, self::CASES_MODULE_RELATED, $response['id']);
                }
            }
            $result = $response['id'];
        }
        return $result;
    }

    /**
     * Create a 'feedback' enquiry  (user clicked the ? icon).
     *
     * Assemble the new Case's attributes from the given parameters.
     * Create the new Case in the CRM.
     * Set the relationship between the Case and the RTO in the CRM.
     * Create a new Document in the CRM, using the given screenshot as the source material.
     * The uploaded screenshot by default should have public visibility in the CRM.
     * In use, its visibility in the portal depends on user context (must have same RTO as the original uploader).
     * Then set the relationship between the Document and the Case in the CRM.
     *
     * @param $account_id
     * @param $subject
     * @param $category
     * @param $subcategory
     * @param $description
     * @param $priority
     * @param $type
     * @param $screenshot
     *
     * @return mixed  A response object
     */
    public function addFeedback($account_id, $subject, $category, $subcategory, $description, $priority, $type, $screenshot)
    {
        $response = $this->_addNewCase($account_id, $subject, $category, $subcategory, $description, $type, $priority);
        /* Use the screenshot to create a new Document record in the CRM, linked to this Case*/
        $new_document   = $this->restClient->setEntry(self::DOCUMENTS_MODULE, [ self::DOCUMENT_NAME => 'Screenshot.png', 'visibility_c' => 'public' ]);
        $document_id    = $new_document['id'];
        $this->restClient->upload_document_given_content($document_id, 'Screenshot.png', $screenshot);
        $this->restClient->setRelationship(self::DOCUMENTS_MODULE, $document_id, self::CASES_MODULE_RELATED, $response['id']);
        \Drupal::logger('main')->debug('New in-context case added incl screenshot (' . $response['id']. ')');
        return $response['id'];
    }

    public function addCaseParent($caseId, $parentType, $parentId)
    {
        $data = [
            [ self::NAME => 'id',          self::VALUE => $caseId ],
            [ self::NAME => 'parent_type', self::VALUE => $parentType ],
            [ self::NAME => 'parent_id',   self::VALUE => $parentId ]
        ];

        return $this->restClient->setEntry(self::CASES_MODULE_PRIMARY, $data);
    }

    public function postUpdate($case_id, $update_text, $contact_id)
    {
        $data = [];
        $data[self::NAME] = $update_text;
        $data[self::DESCRIPTION] = $update_text;
        $data['contact_id'] = $contact_id;
        $data['case_id'] = $case_id;

        $response = $this->restClient->setEntry('AOP_Case_Updates', $data);
        return $this->getUpdate($response['id']);
    }

    public function getUpdate($update_id)
    {
        $data = [
            [self::NAME => self::CONTACT,        self::VALUE => $this->contact_fields ],
            [self::NAME => 'assigned_user_link', self::VALUE => $this->user_fields ]
        ];

        $updateData = $this->restClient->getEntry('AOP_Case_Updates', $update_id, $this->case_update_fields, $data);
        $result = new SugarUpdate($updateData[self::ENTRY_LIST][0], $updateData[self::RELATIONSHIP_LIST][0]);
        return $result;
    }

    public function getNoteAttachment($note_id)
    {
        $attachment = $this->restClient->get_note_attachment($note_id);
        return $attachment['note_attachment'];
    }

    /**
     * Get an array of data from, and metadata about, non-private documents linked to the given entity id
     * (e.g. an rto enquiry).
     *
     * Note that data from all related documents not specifically identified as having "private" visibility
     * will be retrieved.
     *
     * @param string $case_id  A Guid uniquely identifies the case.
     *
     * @return array containing one dimension for the data and another dimension for relationship data
     */
    public function getRelatedDocuments($case_id)
    {
        $related_module_query = " documents_cstm.visibility_c NOT IN ('private') " ;
        return $this->fromDocuments($this->restClient->getRelationships(self::CASES_MODULE_PRIMARY, $case_id, 'documents', $related_module_query, $this->document_fields ));
    }

    /**
     * Get an associative array of name/value pairs holding the stated
     *      attributes (field names) of the stated case.
     *
     * @param string $case_id The case.
     * @param array $fieldNameArray A simple array of field name strings.
     *      Defaults to $this->case_fields.
     *
     * @return mixed For a successful retrieval, return an associative array,
     *      each key is an attribute name, each value is an associative array
     *      holding name and value pairs.  Otherwise return false.
     */
    public function getCaseBasicAttributes($case_id, $fieldNameArray = ['use_default'])
    {
        if( ! array_diff($fieldNameArray, ['use_default'])) {
            $fieldNameArray = $this->case_fields;
        }

        \Drupal::logger('main')->debug(__METHOD__ . ' where case_id is ' . $case_id);
        $caseArray = $this->restClient->getEntry(self::CASES_MODULE_PRIMARY, $case_id, $fieldNameArray );
        return $caseArray[ self::ENTRY_LIST][0]['name_value_list'];
    }

    /**
     * Create an object containing details about a single case (i.e. an enquiry from rto).
     *
     * @param string|null $case_id A guid which uniquely identifies the case.
     * @param bool $ownCase Must be true otherwise an empty string is returned.
     *
     * @return CaseDTO|string A simple object containing data of this case or else an empty string.
     */
    public function getCase($case_id, $ownCase = false)
    {
        \Drupal::logger('main')->debug(__METHOD__ . ', case ' . $case_id);

        if ( $ownCase) {
            //API: getEntry($module, $id, $select_fields = [], $related_fields = [])
            $module         = self::CASES_MODULE_PRIMARY;
            $id             = $case_id;
            $select_fields  = $this->case_fields;
            $related_fields = [
                [self::NAME => 'aop_case_updates', self::VALUE => $this->case_update_fields],
                [self::NAME => 'notes',            self::VALUE => $this->note_fields       ],
            ];

            $case_record = $this->restClient->getEntry($module, $id, $select_fields, $related_fields);

            //API: SugarObject::__construct($case, $relations = [])
            $case       = $case_record[self::ENTRY_LIST][0];
            $relations  = $case_record[self::RELATIONSHIP_LIST][0];

            $case_SugarObject = new SugarCase($case,$relations);

            //identify and then append the case-updates data (i.e. the chat messsaging attributes)
            $case_SugarObject->aop_case_updates = [];


            $related_module_link_name_to_fields_array = [
                [self::NAME => self::CONTACT,       self::VALUE => $this->contact_fields],
                [self::NAME => 'assigned_user_link',self::VALUE => $this->user_fields   ],
                [self::NAME => 'notes',             self::VALUE => $this->note_fields   ],
            ];

            $allUpdates = $this->restClient->getRelationships(self::CASES_MODULE_PRIMARY, $case_id, 'aop_case_updates', '', $this->case_update_fields, $related_module_link_name_to_fields_array);

            foreach ($allUpdates[self::ENTRY_LIST] as $index => $sugarupdate) {
                $update = new SugarUpdate($sugarupdate, $allUpdates[self::RELATIONSHIP_LIST][$index]);

                if ($update->internal) {
                    continue;
                }

                $case_SugarObject->aop_case_updates[] = $update;
            }

            //This case's status and its directly related fields are handled by a link to a case status module entry
            $module             = self::CASE_STATUS_MODULE;
            $id                 = $case_SugarObject->mt_case_status_id_c;
            $select_fields      = $this->case_status_fields;
            $case_status_record = $this->restClient->getEntry($module, $id, $select_fields);

            //API: SugarObject::__construct($object, $relations = [])
            $object             = $case_status_record[self::ENTRY_LIST][0];
            $relations          = $case_status_record[self::RELATIONSHIP_LIST][0];
            $case_status_SugarObject = new SugarObject($object,$relations);
            $caseDTO            = new CaseDTO($case_SugarObject, $case_status_SugarObject);

            //append related material and files
            $caseDTO->related   = $this->getRelatedModule(   $case_SugarObject->parent_type, $case_SugarObject->parent_id);
            $caseDTO->files     = $this->getRelatedDocuments($case_SugarObject->id);

            /*
             * Set chat bubble order: Newest message on the bottom – same as on your phone
             */
            usort($caseDTO->aop_case_updates, function ($a, $b) { return strtotime($a->date_entered) - strtotime($b->date_entered); });

            $result = $caseDTO;
        } else {
            $result = '';
        }

        return $result;
    }

    public function getCases($accountId)
    {
        $cases = $this->fromSugarCases($this->restClient->getRelationships('Accounts', $accountId, self::CASES_MODULE_RELATED, '', $this->case_fields));

        if( is_array($cases) && !empty($cases)) {
            \Drupal::logger('main')->debug(__METHOD__ . ' → ' . count($cases) . ' cases.');
        } else {
            \Drupal::logger('main')->warning(__METHOD__ . ' → NO cases.');
        }

        return $cases;
    }

    private function getRelatedModule($parentType, $parentId)
    {
        $related = new CaseRelatedDTO();

        if ( $parentType == 'EOI_EOI_Application') {
            $related->id = $parentId;
            $related->type = 'EOI Application';
        } else {
            $related = null;
        }

        return $related;
    }

    private function fromDocuments($sugarDocuments)
    {
        $documents = [];
        $relations = [];

        foreach ($sugarDocuments[self::ENTRY_LIST] as $document) {
            $documents[] = new Document($document, $relations);
        }

        return $documents;
    }

    private function fromSugarCases($sugarcases)
    {
        $cases = [];

        foreach ($sugarcases[self::ENTRY_LIST] as $case) {
            $sugarcase = new SugarCase($case, null);
            $caseDTO = new CaseDTO($sugarcase);
            $cases[] = $caseDTO;
        }

        return $cases;
    }

    public function getDocumentAttachment($document_id)
    {
        return $this->restClient->get_document_revision($document_id);
    }

    /**
     * Screen the uploaded files in the current request, checking for error states.
     *
     * @param $request
     *
     * @return array The resulting array holds 'files' data and 'errors' data.
     */
    public function screenFiles( $request)
    {
        $result = [
            'files'         =>[],
            self::ERRORS    =>[],
        ];
        $file_count = $request->get('file_count');

        if ( $file_count > 0) {

            for ($count = 0; $count < $file_count; $count++) {
                if (!array_key_exists('file', $_FILES)) {
                    continue;
                }

                $fileError = $_FILES['file']['error'][$count];

                if ($fileError > 0) {
                    switch ($fileError) {
                        case 1:
                        case 2:
                            $result[self::ERRORS]['file'][$count] = 'File too large';
                            break;
                        case 3:
                            $result[self::ERRORS]['file'][$count] = 'Partial upload';
                            break;
                        default:
                            $result[self::ERRORS]['file'][$count] = 'Other';
                            break;
                    }

                    continue;
                }

                $result['files'][$_FILES['file'][self::NAME][$count]] = $_FILES['file']['tmp_name'][$count];
            }

        } else {
            $result[self::ERRORS]['file'][0] = 'No file detected';
        }

        return $result;
    }

    public function enquiryCompletion($case_id, $contact)
    {
        \Drupal::logger('main')->debug(__METHOD__);
        $result = new Result();

        $data = [
            'id'                             => $case_id,
            self::CASE_STATUS_FIELD          => $this->getIdByName(self::CASE_STATUS_USER_CLOSED,          self::CASE_STATUS_MODULE),
            self::DATE_CLOSED                => date('Y-m-d H:i:s'),
            'modified_user_id'               => $contact->crm_id

        ];

        $result->success = $this->restClient->setEntry(self::CASES_MODULE_PRIMARY, $data);

        if ($result->success) {
            $this->postUpdate($case_id, 'Enquiry closed (resolved)', $contact->id);
        } else {
            $result->message = 'Enquiry could not be closed at this time.';
            $customMessengerService = \Drupal::service('det.service');
            $customMessengerService->set_message($result->message, self::ERROR_MESSAGE);
            
        }

        return $result;
    }

    public function enquiryReOpen($case_id, $contact)
    {
        \Drupal::logger('main')->debug(__METHOD__);
        $result = new Result();

        $data = [
            'id'                             => $case_id,
            self::CASE_STATUS_FIELD          => $this->getIdByName('In Progress',  self::CASE_STATUS_MODULE),
            self::DATE_CLOSED                => '',
            self::DATE_MODIFIED              => date('Y-m-d H:i:s'),
            'modified_user_id'               => $contact->crm_id
        ];

        $result->success = $this->restClient->setEntry(self::CASES_MODULE_PRIMARY, $data);

        if ($result->success) {
            $this->postUpdate($case_id, 'Enquiry re-opened', $contact->id);
        } else {
            $result->message = 'Enquiry could not be re-opened at this time.';
            $customMessengerService = \Drupal::service('det.service');
            $customMessengerService->set_message($result->message, self::ERROR_MESSAGE);

        }

        return $result;
    }


    /**
     * Add a new case into the CRM.
     *
     * @param $account_id
     * @param $subject
     * @param $category
     * @param $subcategory
     * @param $description
     * @param string $caseType
     * @param string $priority
     *
     * @return array  JSON-formatted.
     */
    private function _addNewCase($account_id, $subject, $category, $subcategory, $description, $caseType, $priority)
    {
        \Drupal::logger('main')->debug(__METHOD__);
        $external_status =  self::EXTERNAL_CASE_STATUS_NEW;

        if ( $caseType != self::CASE_TYPE_ENQUIRY) {
            $external_status = self::EXTERNAL_CASE_STATUS_USER_CLOSED;
        }

        $data = [
            self::STATE                      => 'Open',
            'type'                           => $caseType,
            self::PRIORITY                   => $priority,
            self::NAME                       => $subject,
            'mt_cases_category_id_c'         => $this->getIdByName($category,             'MT_cases_category'),
            'mt_cases_subcategory_id_c'      => $this->getIdByName($subcategory,          'MT_cases_subcategory'),
            self::DESCRIPTION                => $description,
            'update_date_entered'            => true,
            self::CASE_STATUS_FIELD          => $this->getIdByName(self::CASE_STATUS_NEW, self::CASE_STATUS_MODULE),
            self::EXTERNAL_CASE_STATUS_FIELD => $this->getIdByName($external_status,      self::EXTERNAL_CASE_STATUS_MODULE),
        ];

        \Drupal::logger('main')->debug('       category id: ' . $data['mt_cases_subcategory_id_c']);
        \Drupal::logger('main')->debug('    subcategory id: ' . $data['mt_cases_subcategory_id_c']);
        \Drupal::logger('main')->debug('         status id: ' . $data[self::CASE_STATUS_FIELD]);
        \Drupal::logger('main')->debug('external status id: ' . $data[self::EXTERNAL_CASE_STATUS_FIELD]);

        if ($caseType != self::CASE_TYPE_ENQUIRY) {
            $data[self::DATE_CLOSED] = date('Y-m-d H:i:s');
        }

        $response = $this->restClient->setEntry(self::CASES_MODULE_PRIMARY, $data);

        //In the CRM, set the relationship between the Case and the RTO
        $this->restClient->setRelationship(self::CASES_MODULE_PRIMARY, $response['id'], 'accounts', $account_id);
        \Drupal::logger('main')->debug(__METHOD__ . ' has set up CRM contents for a new case; returned a JSON formatted array.');
        return $response;
    }

    /**
     * Get the ID which corresponds with the given status name string in the given 'relate' table of the CRM.
     *
     * Often a SuiteCRM module contains an attribute of type 'relate'; a kind of foreign key link to a row from a separate table.
     * The CRM user sees the value of that linked row's name field when they look at the related attribute in an instance of the module.
     * Behind the scenes, of course, it is the foreign key id that gets stored.
     * This method retrieves the id that links the module with row of the relate table, based on the given name.
     *
     * @assume each name value in the relate table, $relatedModuleName, is unique.
     *
     * @param string $name  e.g. 'Commenced'.
     * @param string $relatedModuleName e.g. 'EAS_Status_SVTS_Lodgement'
     * @return mixed Either the id or else false.
     */
    public function getIdByName($name, $relatedModuleName)
    {
        \Drupal::logger('main')->debug(__METHOD__ . '( ' . $name . ', relatedModule= ' . $relatedModuleName . ') returns either a GUID or false.');
        $statusArray = $this->restClient->getEntryList($relatedModuleName,$relatedModuleName.'.'. 'name =\'' . $name . '\'');

        \Drupal::logger('main')->debug('(intermediary step) statusArray : ' . print_r($statusArray, true));
        $sugarObject = new SugarObject($statusArray[self::ENTRY_LIST][0], []);

        $result = isset($sugarObject->id) ? $sugarObject->id : isset($sugarObject->id);
        \Drupal::logger('main')->debug(__METHOD__ . ' → ' . ($result ? $result: 'false'));

        return $result;
    }

    /**
     *  Get the attributes of the case status to which the given case points
     *
     * @param string $case_id A GUID.
     *
     * @return array of key-value pairs
     */
    public function getCaseStatusAttributes($case_id)
    {

    }

}
