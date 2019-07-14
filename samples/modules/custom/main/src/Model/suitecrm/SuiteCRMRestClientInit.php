<?php

namespace Drupal\main\Model\suitecrm;

use Drupal\main\Model\suitecrm\sugarRestClient;

/**
 * Description of sugarRestClientInit
 *
 * @author thushara.ranepura
 */
class SuiteCRMRestClientInit {

    const ACCOUNTS = 'accounts';
    const ACCOUNTS_NAT_NAT_SUBMISSION_1 = 'accounts_nat_nat_submission_1';
    const APPLICATION_LODGEMENT_STATUS_ID_C = 'eas_status_svts_lodgement_id_c';
    const APPLICATION_NOT_AVAILABLE = 'this Application is not available. ';
    const APPLY = 'apply';
    const BUTTON = 'button';
    const CASES_MODULE_PRIMARY = 'Cases';
    const CASES_MODULE_RELATED = 'cases';
    const CASE_STATUS_FIELD = 'mt_case_status_id_c';
    const CASE_STATUS_NEW = 'Awaiting RTO Response'; 
    const CASE_STATUS_MODULE = 'MT_Case_Status';
    const CASE_STATUS_USER_CLOSED = 'Closed';
    const CASE_TYPE_ENQUIRY = 'Enquiry';
    const CONTACT = 'contact';
    const CONTACT_DEPT = 'please contact DET for further information. ';
    const CONTRACT_TYPE_FIELD = 'contract_type';
    const DATE_CLOSED = 'date_closed_c';
    const DATE_ENTERED = 'date_entered';
    const DATE_FORMAT = 'Y-m-d H:i:s';
    const DATE_MODIFIED = 'date_modified';
    const DESCRIPTION = 'description';
    const DOCUMENT_NAME = 'document_name';
    const DOCUMENTS_MODULE = 'Documents';
    const EDIT_APPLICATION = 'edit application';
    const ELIGIBLE = 'eligible';
    const ENTRY_LIST = 'entry_list';
    const EOI_APPLICATION_MODULE = 'EOI_EOI_Application';
    const EOI_APPLICATION_DOCUMENTS_RELATED_MODULE = 'eoi_eoi_application_documents_1';
    const EOI_APPLICATION_ELIGIBILITY_TEMPLATE_RELATED_MODULE = 'MT_eligibility_criteria_template';
    const EOI_APPLICATION_LODGEMENT_STATUS_ID = 'eas_status_svts_lodgement_id_c';
    const EOI_APPLICATION_LODGEMENT_STATUS_MODULE = 'EAS_Status_SVTS_Lodgement';
    const EOI_APPLICATION_RESPONSE_MODULE = 'EOISF_EOI_Application_Response';
    const EOI_DOCUMENTS_RELATED_MODULE = 'eoi_eoi_documents_1';
    const EOI_APPLICATION_EOI_RELATED_MODULE = 'eoi_eoi_eoi_eoi_application_1';
    const EOI_ID = 'eoi_id';
    const EOI_MODULE = 'EOI_EOI';
    const EOI_REQUIREMENT_MODULE = 'EOISF_EOI_Application_Requirement';
    const ERROR_MESSAGE = 'error';
    const ERRORS = 'errors';
    const EVENTS_MODULE = 'FP_events';
    const EXTERNAL_CASE_STATUS_FIELD = 'mt_external_case_status_id_c';
    const EXTERNAL_CASE_STATUS_MODULE = 'MT_External_Case_Status';
    const EXTERNAL_CASE_STATUS_NEW = 'Submitted';
    const EXTERNAL_CASE_STATUS_USER_CLOSED = 'Accepted';
    const GUID_LENGTH = 36;
    const HEADERS = 'headers';
    const LINK_APPLICATION_INITIATIVE = 'eoi_eoi_application_mt_initiative_1';
    const LINK_DOCS_EOI_RESPONSE = 'documents_eoisf_eoi_application_response_1';
    const LINK_EOI_APPLICATION_RESPONSE = 'eoi_eoi_application_eoisf_eoi_application_response_1';
    const LINK_EOI_EOI_REQUIREMENT = 'EOISF_EOI_Application_Requirement';
    const LINK_EOI_REQUIREMENT_RESPONSE = 'eoisf_eoi_application_requirement_eoisf_eoi_application_response_1';
    const MT_EOI_APPLICATION_STATUS_ID_C = 'eas_status_external_eoi_application_id_c';
    const MT_EOI_APPLICATION_STATUS_MODULE = 'EAS_Status_External_EOI_Application';
    const NAME = 'name';
    const NAT_NAT_SUBMISSION = 'NAT_Nat_Submission';
    const NO = 'No';
    const NOT_ELIGIBLE = 'not eligible';
    const NOT_ELIGIBLE_FOR_EOI = 'your organisation is not eligible to apply for this Expression of Interest.';
    const OPTIONAL_INITIATIVES_KEY = 'optional_initiatives_';
    const OPTIONAL_INITIATIVE_REQUIREMENT_KEY = 'optional_initiative_requirement_';
    const PRIORITY = 'priority';
    const PROVIDER_LOCATION = 'provider_location_c';
    const RELATIONSHIP_LIST = 'relationship_list';
    const RTO_EOI_APPLICATION_ID = 'Eoi Application Id';
    const SECTION = 'section';
    const START_APPLICATION = 'Start';
    const STATE = 'state';
    const STATUS_CLOSED = 'Closed';
    const STATUS_DRAFT = 'Draft';
    const STATUS_INELIGIBLE = 'Ineligible';
    const STATUS_MESSAGE = 'status';
    const STATUS_OPEN = 'Open';
    const STR = 'string';
    const TIMEZONE = 'Australia/Melbourne';
    const TITLE = 'title';
    const VALUE = 'value';
    const VIEW_APPLICATION = 'view application';
    const VIEW_EOIS = 'View Expressions of Interest';
    const WARNING_MESSAGE = 'warning';
    const YES = 'Yes';

    public function __construct() {
        $this->restClient = new sugarRestClient();
        $this->restClient->login();
    }

    public function getVTISService($call_arguments) {
        return $this->restClient->vtisService_request($call_arguments);
    }
    
    public function getVTISPOSTService($call_arguments,$data,$content_length) {
        return $this->restClient->vtisService_post($call_arguments,$data,$content_length);
    }

}
