<?php

namespace Drupal\main\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\main\Model\Result;
use Drupal\main\Model\suitecrm\CasesConnection;
use Drupal\main\Model\suitecrm\PortalConfigSettings;
use Drupal\ssrs_connector\Model\ReportConnection;
use Drupal\main\Model\Utilities;

class CasesController extends ApplicationController {

    /**
     * Build a render array of details of a single case (an rto enquiry).
     *
     * @param null|string $case_id  A guid uniquely identifies the case.
     *
     * @return array  A render array, available for use and manipulation by the routed twig and javascript.
     */
    public function view($case_id = NULL) {
        $renderArray = [
            self::THEME => 'cases/view',
            self::OWN => false,
            self::ATTACHED => [self::LIBRARY => ['main/viewCaseLibrary'],],
            self::CRM_CASE => '',
            '#cache' => ['max-age' => 0]
        ];

        $casesConnection = CasesConnection::getInstance();
        $caseBasics = $casesConnection->getCaseBasicAttributes($case_id, ['account_id']);

        $renderArray[self::OWN] = ($this->account->id == $caseBasics['account_id']['value']);
        $renderArray[self::CRM_CASE] = $casesConnection->getCase($case_id, $renderArray[self::OWN]);

        if (
            $renderArray[self::OWN] &&
            $renderArray[self::CRM_CASE]->type == 'Enquiry' &&
            $renderArray[self::CRM_CASE]->is_visible
        ) {
            if ($renderArray[self::CRM_CASE]->state == 'Closed') {
                $customMessengerService = \Drupal::service('det.service');

                if ($renderArray[self::CRM_CASE]->portal_status == 'Resolved') {
                    $customMessengerService->set_message('This enquiry has been resolved. You are welcome to re-open it.', 'info');
                } else {
                    $customMessengerService->set_message('This enquiry is closed. Contact DET for further information.', 'info');
                }
            } else {
                // All good: enquiry is from this RTO, it is visible and open.
            }
        } else {
            $uid = \Drupal::currentUser()->id();
            \Drupal::logger('main')->error('user ' . $uid . ' from organisation ' . $this->account->name . ' (' . $this->account->to_id_c . '), tried to open an unavailable case ' . $case_id);
            $customMessengerService = \Drupal::service('det.service');
            $customMessengerService->set_message('No data is available.', self::ERROR_MESSAGE);
        }

        return $renderArray;
    }

    public function listCases() {
        return [
            self::THEME => 'cases/list',
            self::ATTACHED => [
                self::LIBRARY => [
                    'main/casesAssetLibrary'
                ],
            ]
        ];
    }

    /**
     * When the "Create enquiry" button is clicked this method is routed.
     *
     * @param string|null $error_state Defaults to null but any truthy value causes a generic error message to render.
     *
     * @return array A renderArray for use in the related twig template.
     */
    public function newCase($error_state = null)
    {
        \Drupal::logger('main')->debug('"Create enquiry" clicked. ' . ($error_state ? ' Error state detected, DZ upload must have failed' : ''));
        $categoriesList = $this->categoryList();
        //$subcategoriesList = $this->subcategoryList();

        if ($error_state) {
            drupal_set_message($this->msgGenericError, self::ERROR_MESSAGE);
        }

        return [
            self::THEME => 'cases/add',
            '#categories' => $categoriesList,
            // '#subcategories' => $subcategoriesList,
            self::ATTACHED => [self::LIBRARY => ['main/newCaseLibrary'],]
        ];
    }

    /**
     * Control the AJAX request and response around a submitted new Enquiry.
     * Includes much work to handle file uploads.
     *
     * @param Request $request Contains form entries and other metadata from the source portal twig
     *
     * @return Response json data to be handled by the calling javascript method.
     */
    public function addCase(Request $request)
    {
        \Drupal::logger('main')->debug('"Submit" clicked in New Enquiry form');
        $errors = [];
        $files = [];

        //assemble the parameters that define a new case record in the CRM
        # @param $subject
        $subject = $request->get('title');

        # @param $category
        $category = $request->get('enquiryCategory1');

        # @param $subcategory
        $subcategory = $request->get('enquirySubcategory1');

        # @param $description
        $description = $request->get('description');

        # @param $caseType
        $caseType = 'Enquiry'; // default case type is 'Enquiry'
        # @param $priority
        $priority = $request->get('priority');

        # @param $files
        $casesConnection = CasesConnection::getInstance();
        $fileAttributes = $casesConnection->screenFiles($request);

        if (!empty($fileAttributes['files'])) {
            $files = $fileAttributes['files'];
        }

        if (!empty($fileAttributes['errors'])) {
            $errors = $fileAttributes['errors'];
        }

        //send these parameters, containing the new case's data, to the CRM
        $newCaseId = $casesConnection->newCase($this->account->id, $subject, $category, $subcategory, $description, $caseType, $priority, $files);
        $acceptedOrNot = isset($newCaseId);

        //prepare a JSON response to the original request
        $json = [
            self::RESULT => $acceptedOrNot, // call it false to test error state
            self::CASE_ID => $newCaseId,
            self::ERROR_FIELD => $errors,
        ];

        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);
        $response->setContent(json_encode($json));
        \Drupal::logger('main')->debug('New enquiry → ' . (($acceptedOrNot) ? 'response object, case id "' . $newCaseId . '".' : 'empty response object.'));
        return $response;
    }

    /**
     * Control the AJAX request and response around re-populating the Case category drop-down list.
     *
     * @param Request $request
     *
     * @return Response json data to be handled by the calling javascript method.
     */
    public function fetchCategories(Request $request) {
        $caseType = $request->get('caseType');
        \Drupal::logger('main')->info(__METHOD__ . ' posting {caseType: "' . $caseType . '"} via ajax.');
        $categories = $this->getCategoryNames($caseType);
        $categoriesFound = (!empty($categories));

        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);

        $json = [
            self::RESULT => $categoriesFound,
            'categories' => $categories
        ];

        $response->setContent(json_encode($json));
        \Drupal::logger('main')->debug(__METHOD__ . ' → result is   ' . (($categoriesFound) ? ' a response object having an array of values.' : ' an empty response object.'));
        return $response;
    }

    /**
     * Control the AJAX request and response around re-populating the Case subcategory drop-down list.
     *
     * @param Request $request
     *
     * @return Response json data to be handled by the calling javascript method.
     */
    public function fetchSubcategories(Request $request) {
        $caseType = $request->get('caseType');
        \Drupal::logger('main')->info(__METHOD__ . ' posting {caseType: "' . $caseType . '"} via ajax.');
        $subcategories = $this->getSubcategoryNames($caseType);
        $subcategoriesFound = (!empty($subcategories));

        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);

        $json = [
            self::RESULT => $subcategoriesFound,
            'subcategories' => $subcategories
        ];

        $response->setContent(json_encode($json));
        \Drupal::logger('main')->debug(__METHOD__ . ' → result is   ' . (($subcategoriesFound) ? ' a response object having an array of values.' : ' an empty response object.'));
        return $response;
    }

    /**
     * Get a simple array of of category names, based on case type.
     *
     * @todo remove dummy data & refactor when the data model is updated
     *
     * @param string $caseType Default is 'Enquiry'.
     *
     * @return array A simple array of strings.
     */
    public function getCategoryNames($caseType = 'Enquiry') {

        $cid = 'case_categories:' . \Drupal::languageManager()->getCurrentLanguage()->getId();

        if ($cache = \Drupal::cache()->get($cid)) {
            $categoryNames = $cache->data;
        } else {
            // This is where you would add your code that will run only
            // when generating a new cache.

            $reportConnection = ReportConnection::getInstance();

            //assemble the parameters that define the SSRS Report of categories, based on case type
            # @param $ssrsReport
            $ssrsReport = $reportConnection->getSSRSReport();

            # @param $reportName
            $reportName = '/Portal Reports/Cases Category';
            
             # @param $reportParams
            $reportParams = [
                    ['name' => 'CaseType', 'value' => $caseType],
            ];


            //send a request for a copy of that report, based on these parameters
            $report = $reportConnection->fetchReportDataByParameters($ssrsReport, $reportName, $reportParams );

            if (!empty($report['data'])) {              
                $categoryNames = Utilities::parseSubCategoriesList($report['data']);
            } else {
                $categoryNames = null;
            }
            \Drupal::cache()->set($cid, $categoryNames);
        }


        return $categoryNames;
    }

    /**
     * Get a simple array of of subcategory names, based on case type.
     *
     * @todo refactor when the data model is updated: contents in query will also depend on selected enquiry category
     *
     * @param string $caseType Default is 'Enquiry'.
     *
     * @return array A simple array of strings.
     */
    public function getSubcategoryNames($caseType) {

        $cid = 'case_subcategories:' . $caseType . '-' . \Drupal::languageManager()->getCurrentLanguage()->getId();

        if ($cache = \Drupal::cache()->get($cid)) {
            $subCategoryNamesList = $cache->data;
        } else {

            $reportConnection = ReportConnection::getInstance();

            //assemble the parameters that define the SSRS Report of subcategories, based on case type
            # @param $ssrsReport
            $ssrsReport = $reportConnection->getSSRSReport();

            # @param $reportName  
            $reportName = '/Portal Reports/Cases SubCategory';
            
            $cases_connection = CasesConnection::getInstance();
            $cat_id = $cases_connection->getIdByName($caseType,'MT_cases_category');

            # @param $reportParams
            $reportParams = [
                    ['name' => 'CategoryID', 'value' => $cat_id],
            ];

            //send a request for a copy of that report, based on these parameters
            $report = $reportConnection->fetchReportDataByParameters($ssrsReport, $reportName, $reportParams);

            if (!empty($report['data'])) {
                $subCategoryNames = $report['data'];
                $subCategoryNamesList = Utilities::parseSubCategoriesList($subCategoryNames);
            } else {
                $subCategoryNamesList = array('n/a' => 'n/a');
            }

            \Drupal::cache()->set($cid, $subCategoryNamesList);
        }

        return $subCategoryNamesList;
    }

    public function addFiles(Request $request) {
        $case_id = $request->get(self::CASE_ID);
        $casesConnection = CasesConnection::getInstance();
        $fileAttributes = $casesConnection->screenFiles($request);

        if (!empty($fileAttributes['files'])) {
            $casesConnection->addCaseFiles($case_id, $fileAttributes['files']);
        }

        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);
        $uploadResult = ( is_array($fileAttributes) && (!array_key_exists('errors', $fileAttributes) || count($fileAttributes['errors']) == 0));

        $json = [
            self::RESULT => $uploadResult,
            self::CASE_ID => $case_id,
            self::ERROR_FIELD => $fileAttributes['errors'],
        ];

        $response->setContent(json_encode($json));
        \Drupal::logger('main')->debug(__METHOD__ . ' → response object indicating upload was ' . (($uploadResult) ? '' : 'not ' . 'OK'));
        return $response;
    }

    /*
     * Save the RTO-User-submitted slide-out feedback form (enquiry).
     *
     * Enquiry was submitted via the top-menu '?' icon  (it includes a screenshot for DET user to see context of Quesiton).
     *
     * @param Request object $request.
     *
     * @return Response object.
     */

    public function addFeedback(Request $request) {
        //assemble the parameters that define the new case record to be created in the CRM
        # @param subject
        $title = $request->get('enquiryTitle');

        # @param category
        $category = $request->get('enquiryCategory');

        # @param subcategory
        $subcategory = $request->get('enquirySubcategory');

        # @param description
        $description = $request->get('enquiryDescription');

        # @param caseType
        $caseType = 'Enquiry';

        # @param priority
        $priority = $request->get('enquiryPriority');
        $screenshot = explode('base64,', $request->get('screenshot'));

        //send these parameters, containing the new case's data, to the CRM
        $casesConnection = CasesConnection::getInstance();
        $caseId = $casesConnection->addFeedback($this->account->id, $title, $category, $subcategory, $description, $priority, $caseType, $screenshot[1]);
        $caseAttributes = $casesConnection->getCaseBasicAttributes($caseId);

        /* Use context-awareness for cases, e.g. EOI Application and Payments, to record this case's place in a hierarchy  */
        $parentType = $request->get('enquiryParentType');

        if (isset($parentType)) {
            $parentId = $request->get('enquiryParentId');
            $casesConnection->addCaseParent($caseId, $parentType, $parentId);
        }

        $message = '';
        $enq_ack_timeout = '0';

        //generate an HTTP response using the above outcomes
        $feedbackIsReceivedOrNot = isset($caseId);
        if ($feedbackIsReceivedOrNot) {
            $basePath = base_path();
            $baseMessage = PortalConfigSettings::getSettingValue('enq_received_msg');
            $midMessage = str_replace('{0}', $caseAttributes['case_number']["value"], $baseMessage);
            $message = str_replace('{1}', $basePath, $midMessage);
            $enq_ack_timeout = PortalConfigSettings::getSettingValue('enq_ack_timeout');
        }

        $json = [
            self::RESULT => $feedbackIsReceivedOrNot,
            self::CASE_ID => $caseId,
            'message' => $message,
            'timeout' => $enq_ack_timeout
        ];

        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);
        $response->setContent(json_encode($json));

        \Drupal::logger('main')->debug(__METHOD__ . ' → response object indicating feedback via "?" form was ' . (($feedbackIsReceivedOrNot) ? '' : 'not ' . 'received OK'));
        return $response;
    }

    public function addCaseFiles(Request $request) {
        $errors = [];
        $case_id = $request->get(self::CASE_ID);
        $file_count = $request->get('file_count');
        $files = [];

        for ($count = 0; $count < $file_count; $count++) {
            if (!array_key_exists('file', $_FILES)) {
                continue;
            }

            $fileError = $_FILES['file'][self::ERROR_FIELD][$count];

            if ($fileError > 0) {

                switch ($fileError) {
                    case 1:
                    case 2:
                        $errors['file'][$count] = 'File too large';
                        break;
                    case 3:
                        $errors['file'][$count] = 'Partial upload';
                        break;
                    default:
                        $errors['file'][$count] = 'Other';
                        break;
                }

                continue;
            }

            $files[$_FILES['file']['name'][$count]] = $_FILES['file']['tmp_name'][$count];
        }

        $casesConnection = CasesConnection::getInstance();
        $casesConnection->addCaseFiles($case_id, $files);
        echo json_encode([self::RESULT => count($errors) == 0, self::ERROR_FIELD => $errors,]);
        die();
    }

    public function fetchCases(Request $request) {
        $casesConnection = CasesConnection::getInstance();
        $cases = $casesConnection->getCases($this->account->id);
        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);
        $json = [];
        $json['data'] = $cases;
        $response->setContent(json_encode($json));
        return $response;
    }

    public function fetchCase(Request $request) {
        $id = $request->get('id');
        $casesConnection = CasesConnection::getInstance();
        $case = $casesConnection->getCase($id);
        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);
        $response->setContent(json_encode($case));
        return $response;
    }

    public function fetchCaseByID($caseid) {
        $id = $caseid;
        $casesConnection = CasesConnection::getInstance();
        $case = $casesConnection->getCase($id);
        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);
        $response->setContent(json_encode($case));
        return $response;
    }

    public function getDocument(Request $request) {
        $document_id = $request->get('doc_id');
        $casesConnection = CasesConnection::getInstance();
        $this->document = $casesConnection->getDocumentAttachment($document_id);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $file = base64_decode($this->document['file']);
        $mime = finfo_file($finfo, $this->document[self::FILENAME]);
        header('Content-type: ' . $mime);
        header('Content-Disposition: attachment;filename=' . $this->document[self::FILENAME]);
        ob_clean();
        flush();
        echo $file;
        exit();
    }

    /**
     * Method to Get Error Report using CRM REST API
     * 
     * @param $document_revision_id
     */
    public function getErrorReportDocument($document_revision_id) {
        $document_id = $document_revision_id;
        $casesConnection = CasesConnection::getInstance();
        $this->document = $casesConnection->getDocumentAttachment($document_id);

        if ($this->document['file']) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $file = base64_decode($this->document['file']);
            $mime = finfo_file($finfo, $this->document[self::FILENAME]);
            header('Content-type: ' . $mime);
            header('Content-Disposition: attachment;filename=' . $this->document[self::FILENAME]);
            ob_clean();
            flush();
            echo $file;
        } else {
            echo 'Unable to fetch file at this time.';
            \Drupal::logger('main')->error('Unable to fetch files at this time . $document_revision_id: ' . $document_revision_id);
        }

        exit();
    }

    public function addCaseUpdate(Request $request) {
        $case_id = $request->get(self::CASE_ID);
        $description = $request->get('update_text');
        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);

        if (!$case_id) {
            $response->setContent(json_encode('Case Id is required'));
            return $response;
        }

        if (!$description) {
            $response->setContent(json_encode('Update Text is required'));
            return $response;
        }

        $casesConnection = CasesConnection::getInstance();
        $case_update = $casesConnection->postUpdate($case_id, $description, $this->contact->id);
        $response->setContent(json_encode($case_update));
        return $response;
    }

    public function endCase(Request $request) {
        $result = new Result();
        $case_id = $request->get(self::CASE_ID);

        if (!empty($case_id)) {
            $casesConnection = CasesConnection::getInstance();
            $result = $casesConnection->enquiryCompletion($case_id, $this->contact->id);
        }

        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);
        $response->setContent(json_encode($result));
        return $response;
    }

    public function reopenCase(Request $request) {
        $result = new Result();
        $case_id = $request->get(self::CASE_ID);

        if (!empty($case_id)) {
            $casesConnection = CasesConnection::getInstance();
            $result = $casesConnection->enquiryReOpen($case_id, $this->contact->crm_id);
        }

        $response = new Response();
        $response->headers->set(self::CONTENT_TYPE, self::TYPE_JSON);
        $response->setContent(json_encode($result));
        return $response;
    }

    /**
     * Gnerate a simple array of  case bcategory names.
     *
     * Each array entry is a simple array (key is 'name', value is the name string).
     *
     * @return array
     */
    private function categoryList() {
        $result = [];
        $names = $this->getCategoryNames();

        if (is_array($names) && count($names)) {
            foreach ($names as $name) {
                $result[] = ['name' => $name];
            }
        }

        return $result;
    }

    /**
     * Gnerate a simple array of  case subcategory names.
     *
     * Each array entry is a simple array (key is 'name', value is the name string).
     *
     * @return array
     */
    private function subcategoryList() {
        $result = [];
        $names = $this->getSubcategoryNames();

        if (is_array($names) && count($names)) {
            foreach ($names as $name) {
                $result[] = ['name' => $name];
            }
        }

        return $result;
    }

}
