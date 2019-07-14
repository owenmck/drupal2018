<?php

namespace Drupal\main\Model;

use Drupal\main\Model\Utilities;
use Drupal\main\Model\suitecrm\SugarCase;

class CaseDTO {

    public $id               = '';
    public $title            = '';
    public $subcategory      = '';
    public $description      = '';
    public $number           = '';
    public $type             = '';
    public $created_date     = '';
    public $modified_date    = '';
    public $state            = '';
    public $status           = '';
    public $is_visible       = 0;
    public $portal_status    = '';
    public $aop_case_updates = '';
    public $account_id       = '';
    public $category         = '';
    public $related          = null;
    public $files            = null;

    public function __construct($sugarCase, $case_status_SugarObject) {
        $this->id               = $sugarCase->id;
        $this->title            = html_entity_decode($sugarCase->name);
        $this->category         = html_entity_decode($sugarCase->category_c);
        $this->subcategory      = html_entity_decode($sugarCase->subcategory_c);
        $this->description      = html_entity_decode($sugarCase->description);
        $this->number           = $sugarCase->case_number;
        $this->type             = $sugarCase->type;
        $this->created_date     = Utilities::formatDateTime($sugarCase->date_entered);
        $this->modified_date    = Utilities::formatDateTime($sugarCase->date_modified);
        $this->state            = $case_status_SugarObject->case_state;
        $this->status           = $case_status_SugarObject->name;
        $this->is_visible       = $case_status_SugarObject->is_visible_on_portal;
        $this->portal_status    = $case_status_SugarObject->placeholder_for_portal_status;

        if ( property_exists($sugarCase, 'aop_case_updates')) {
            $this->aop_case_updates = $sugarCase->aop_case_updates;
        }
    }
}
