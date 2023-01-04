<?php

// This COmanage Registry enrollment plugin is intended to be used
// with a self-signup enrollment flow for LSST that
// automatically adds users to a CO Group. It forces the
// CO group object to be provisioned when the enrollment flow
// completes.
//
// The following enrollment steps are implemented:
//
// petitionerAttributes:
//   - promotes the Name of type preferred to be the
//     primary name
// provision:
//   - invokes manualProvision() on the CO Group(s)

App::uses('CoPetitionsController', 'Controller');
App::uses('HtmlHelper', 'View/Helper');
 
class Lsst01EnrollerCoPetitionsController extends CoPetitionsController {
  // Class name, used by Cake
  public $name = "Lsst01EnrollerCoPetitions";
  public $uses = array("CoPetition");

  /**
   * Plugin functionality following petitionerAttributes step
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */

  protected function execute_plugin_petitionerAttributes($id, $onFinish) {
    // Pull the petition and its related models.
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['EnrolleeCoPerson'] = 'Name';

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Petitioner Attributes: Petition is " . print_r($petition, true));

    // Loop over the Name objects to find the Name of type preferred
    // and set it to be the primary name. Note that the beforeSave()
    // method on the Name model will take care of updating the other
    // Name objects as necessary.
    if(!empty($petition['EnrolleeCoPerson']['Name'])) {
      if(is_array($petition['EnrolleeCoPerson']['Name'])) {
        foreach($petition['EnrolleeCoPerson']['Name'] as $name) {
          if($name['type'] == NameEnum::Preferred) {
            $id = $name['id'];
            $data = array();
            $data['Name'] = $name;
            $data['Name']['primary_name'] = true;

            if($this->CoPetition->EnrolleeCoPerson->Name->save($data)) {
              $this->log("Set primary_name true for Name with ID $id");
            } else {
              $this->log("ERROR: could not set primary_name true for Name with ID $id");
            }
            break;
          }
        }
      }
    }

    $this->redirect($onFinish);
  }

  /**
   * Plugin functionality following provision step
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */
   
  protected function execute_plugin_provision($id, $onFinish) {
    // Pull the petition and its related enrollment flow configuration.
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['CoEnrollmentFlow']['CoEnrollmentAttribute'] = 'CoEnrollmentAttributeDefault';

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Provision: Petition is " . print_r($petition, true));

    // Examine the enrollment flow configuration to determine which, if any,
    // CO Groups should be re-provisioned.
    $groupIds = array();

    if(!empty($petition['CoEnrollmentFlow']['CoEnrollmentAttribute'])) {
      foreach($petition['CoEnrollmentFlow']['CoEnrollmentAttribute'] as $attribute) {
        if($attribute['attribute'] == 'g:co_group_member') {
          if(!empty($attribute['CoEnrollmentAttributeDefault'][0]['value'])) {
            $groupId = $attribute['CoEnrollmentAttributeDefault'][0]['value'];
            $groupIds[] = $groupId;
          }
        }
      }
    }

    $this->log("Provision: will force provisioning of CO Groups with IDs " . print_r($groupIds, true));

    // Invoke a manual re-provision on the CO Group.
    foreach($groupIds as $groupId) {
      $this->CoPetition->Co->CoGroup->manualProvision(null, null, $groupId);
      $this->log("Provision: reprovsioned CO Group with ID $groupId");
    }

    $this->redirect($onFinish);
  }
}
